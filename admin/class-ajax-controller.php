<?php
/**
 * AJAX endpoints backing the admin UI: settings, manual/cron job control,
 * job logs, and CSV export.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

use Throwable;

defined('ABSPATH') || exit;

// WarmPilot uses plugin-owned queue tables whose live state must not be cached.
// Table identifiers come exclusively from $wpdb->prefix in Database::__construct().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
/**
 * Handles every wp_ajax_warmpilot_* action registered by Plugin, each gated
 * by authorize() (capability + nonce check).
 */
class Ajax_Controller extends Admin {
    // All AJAX handlers call authorize() before processing request data.
    // phpcs:disable WordPress.Security.NonceVerification.Missing

    /**
     * Requires manage_options and a valid nonce; halts the request with a JSON error otherwise.
     */
    protected function authorize(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }
    /**
     * Saves the manual warming settings from $_POST.
     */
    public function ajax_save_settings(): void {
        $this->authorize();
        $settings = $this->sanitize_settings($_POST);
        update_option(self::OPTION, $settings, false);
        wp_send_json_success(['settings' => $settings]);
    }
    /**
     * Saves the log-retention settings from $_POST and applies rotation immediately.
     */
    public function ajax_save_log_settings(): void {
        $this->authorize();
        $settings = wp_parse_args(get_option(self::LOG_OPTION, []), self::default_log_settings());
        $settings['log_retention_count'] = max(0, absint(wp_unslash($_POST['log_retention_count'] ?? 50)));
        $settings['log_retention_days'] = max(0, absint(wp_unslash($_POST['log_retention_days'] ?? 30)));
        update_option(self::LOG_OPTION, $settings, false);
        $this->apply_global_log_rotation();
        wp_send_json_success(['settings' => $settings]);
    }
    /**
     * Saves the "delete data on uninstall" setting from $_POST.
     */
    public function ajax_save_uninstall_settings(): void {
        $this->authorize();
        $settings = wp_parse_args(get_option(self::LOG_OPTION, []), self::default_log_settings());
        $settings['delete_data_on_uninstall'] = !empty($_POST['delete_data_on_uninstall']) ? 1 : 0;
        update_option(self::LOG_OPTION, $settings, false);
        wp_send_json_success([
            'delete_data_on_uninstall' => $settings['delete_data_on_uninstall'],
        ]);
    }
    /**
     * Returns the full job log list for the "Job Logs" tab.
     */
    public function ajax_job_logs(): void {
        $this->authorize();
        $status_labels = [
            'running' => __('Running', 'warmpilot'),
            'finished' => __('Finished', 'warmpilot'),
            'stopped' => __('Stopped', 'warmpilot'),
        ];
        $logs = array_map(static function ($log) use ($status_labels): array {
            $is_cron = in_array($log->trigger_source, ['cron', 'cron_manual'], true);
            return [
                'id' => (int) $log->id,
                'type_key' => $is_cron ? 'cron' : 'manual',
                'type' => $is_cron ? __('Cron', 'warmpilot') : __('Manual', 'warmpilot'),
                /* translators: %d: cron profile ID. */
                'task' => $is_cron ? ($log->profile_name ?: sprintf(__('Deleted task #%d', 'warmpilot'), (int) $log->profile_id)) : '—',
                'started_at' => $log->started_at ?: '—',
                'finished_at' => $log->finished_at ?: '—',
                'status' => (string) $log->status,
                'status_label' => $status_labels[$log->status] ?? (string) $log->status,
                'total' => (int) $log->total,
                'successful' => (int) $log->successful,
                'failed' => (int) $log->failed,
            ];
        }, $this->get_all_job_logs());
        wp_send_json_success(['logs' => $logs]);
    }
    /**
     * Starts a new manual warming job from $_POST settings.
     */
    public function ajax_start(): void {
        $this->authorize();
        $settings = $this->sanitize_settings($_POST);
        update_option(self::OPTION, $settings, false);
        $job_id = $this->create_job($settings, null, 'manual');
        if (!$job_id) {
            wp_send_json_error(['message' => 'No valid entry or sitemap URLs were queued.']);
        }
        wp_send_json_success(['job_id' => $job_id]);
    }
    /**
     * Processes one batch of a job's queued items (polled repeatedly by the admin UI until finished).
     */
    public function ajax_process(): void {
        $this->authorize();
        $job_id = absint($_POST['job_id'] ?? 0);
        $payload = $this->process_job_batch($job_id);
        if (is_wp_error($payload)) wp_send_json_error(['message'=>$payload->get_error_message()]);
        wp_send_json_success($payload);
    }
    /**
     * Creates or updates a cron profile from $_POST (including optional custom cron expression fields).
     */
    public function ajax_save_cron_profile(): void {
        $this->authorize();
        global $wpdb;
        $settings = $this->sanitize_settings($_POST);
        $name = sanitize_text_field(wp_unslash($_POST['profile_name'] ?? ''));
        if ($name === '') wp_send_json_error(['message'=>'Task name is required.']);
        $interval = sanitize_key(wp_unslash($_POST['interval_key'] ?? 'hourly'));
        $allowed_intervals = ['warmpilot_minute','five_minutes','fifteen_minutes','hourly','twicedaily','daily','weekly','custom_cron'];
        if (!in_array($interval, $allowed_intervals, true)) $interval='hourly';
        $cron_expression = null;
        if ($interval === 'custom_cron') {
            if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
                wp_send_json_error(['message'=>'Custom cron syntax is available only in system cron mode with DISABLE_WP_CRON set to true.']);
            }
            $cron_fields = [
                sanitize_text_field(wp_unslash($_POST['cron_minute'] ?? '*')),
                sanitize_text_field(wp_unslash($_POST['cron_hour'] ?? '*')),
                sanitize_text_field(wp_unslash($_POST['cron_day'] ?? '*')),
                sanitize_text_field(wp_unslash($_POST['cron_month'] ?? '*')),
                sanitize_text_field(wp_unslash($_POST['cron_weekday'] ?? '*')),
            ];
            $cron_expression = implode(' ', $cron_fields);
            if (!$this->validate_cron_expression($cron_expression)) {
                wp_send_json_error(['message'=>'Invalid cron expression. Use five standard fields: minute, hour, day, month, weekday.']);
            }
        }
        $id = absint($_POST['profile_id'] ?? 0);
        $now = current_time('mysql', true);
        $data = [
            'name'=>$name,
            'interval_key'=>$interval, 'cron_expression'=>$cron_expression, 'settings'=>wp_json_encode($settings),
            'next_run'=>$this->aligned_next_run_mysql($interval, null, $cron_expression), 'updated_at'=>$now,
        ];
        if ($id) {
            $wpdb->update($this->schedules_table, $data, ['id'=>$id]);
        } else {
            $data['enabled']=1;
            $data['created_at']=$now;
            $wpdb->insert($this->schedules_table, $data);
            $id=(int)$wpdb->insert_id;
        }
        wp_send_json_success(['profile_id'=>$id,'reload'=>true]);
    }
    /**
     * Returns a single cron profile's settings for the edit form.
     */
    public function ajax_get_cron_profile(): void {
        $this->authorize();
        global $wpdb;
        $id=absint($_POST['profile_id'] ?? 0);
        $profile=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedules_table} WHERE id=%d", $id));
        if (!$profile) wp_send_json_error(['message'=>'Task not found.']);
        wp_send_json_success([
            'id'=>(int)$profile->id, 'name'=>$profile->name, 'enabled'=>(int)$profile->enabled,
            'interval_key'=>$profile->interval_key, 'cron_expression'=>$profile->cron_expression, 'settings'=>$this->normalize_settings(json_decode($profile->settings, true) ?: [])
        ]);
    }
    /**
     * Deletes a cron profile (its jobs/logs are left intact).
     */
    public function ajax_delete_cron_profile(): void {
        $this->authorize();
        global $wpdb;
        $wpdb->delete($this->schedules_table, ['id'=>absint($_POST['profile_id'] ?? 0)]);
        wp_send_json_success();
    }
    /**
     * Toggles a cron profile's enabled state, recalculating next_run when re-enabled.
     */
    public function ajax_toggle_cron_profile(): void {
        $this->authorize();
        global $wpdb;
        $id = absint($_POST['profile_id'] ?? 0);
        $profile = $wpdb->get_row($wpdb->prepare("SELECT id, enabled, interval_key, cron_expression FROM {$this->schedules_table} WHERE id=%d", $id));
        if (!$profile) wp_send_json_error(['message'=>'Task not found.']);
        $enabled = (int) $profile->enabled === 1 ? 0 : 1;
        $data = ['enabled'=>$enabled, 'updated_at'=>current_time('mysql', true)];
        if ($enabled) {
            $data['next_run'] = $this->aligned_next_run_mysql($profile->interval_key, null, $profile->cron_expression);
        }
        $wpdb->update($this->schedules_table, $data, ['id'=>$id]);
        wp_send_json_success(['profile_id'=>$id, 'enabled'=>$enabled]);
    }
    /**
     * Starts a cron profile's job immediately (rejected if it already has a running job).
     */
    public function ajax_run_cron_profile(): void {
        $this->authorize();
        global $wpdb;
        $id = absint($_POST['profile_id'] ?? 0);
        $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedules_table} WHERE id=%d", $id));
        if (!$profile) wp_send_json_error(['message'=>'Task not found.']);
        $active_job_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->jobs_table} WHERE profile_id=%d AND status='running' ORDER BY id DESC LIMIT 1",
            $id
        ));
        if ($active_job_id) {
            wp_send_json_error(['message'=>'This cron task is already running.', 'job_id'=>$active_job_id]);
        }
        $settings = $this->normalize_settings(json_decode($profile->settings, true) ?: []);
        $job_id = $this->create_job($settings, $id, 'cron_manual');
        if (!$job_id) wp_send_json_error(['message'=>'Could not create job: ' . $this->get_last_job_error()]);
        $wpdb->update($this->schedules_table, ['last_run'=>current_time('mysql', true),'last_job_id'=>$job_id], ['id'=>$id]);
        wp_send_json_success(['job_id'=>$job_id]);
    }
    /**
     * Requests a stop for all currently-running jobs of a cron profile.
     */
    public function ajax_stop_cron_profile(): void {
        $this->authorize();
        global $wpdb;
        $profile_id = absint($_POST['profile_id'] ?? 0);
        if (!$profile_id) wp_send_json_error(['message'=>'Invalid task.']);

        $job_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->jobs_table} WHERE profile_id=%d AND status='running'",
            $profile_id
        ));
        if (!$job_ids) wp_send_json_error(['message'=>'This cron task is not running.']);

        $placeholders = implode(',', array_fill(0, count($job_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->jobs_table} SET stop_requested=1 WHERE id IN ({$placeholders})",
            ...array_map('intval', $job_ids)
        ));
        wp_send_json_success(['profile_id'=>$profile_id, 'job_ids'=>array_map('intval', $job_ids)]);
    }
    /**
     * Returns the live status (Idle/Running/Stopping/Disabled) of every cron profile,
     * polled by the "Cron tasks" tab.
     */
    public function ajax_cron_profiles_status(): void {
        $this->authorize();
        global $wpdb;

        $profiles = $wpdb->get_results(
            "SELECT id, enabled, next_run, last_run, last_job_id FROM {$this->schedules_table} ORDER BY id ASC"
        );
        $result = [];

        foreach ($profiles as $profile) {
            $active = $wpdb->get_row($wpdb->prepare(
                "SELECT id, stop_requested FROM {$this->jobs_table} WHERE profile_id=%d AND status='running' ORDER BY id DESC LIMIT 1",
                (int) $profile->id
            ));

            $status = !(int) $profile->enabled
                ? 'Disabled'
                : ($active ? ((int) $active->stop_requested ? 'Stopping' : 'Running') : 'Idle');

            $result[] = [
                'profile_id' => (int) $profile->id,
                'status' => $status,
                'active_job_id' => $active ? (int) $active->id : 0,
                'next_run' => $this->display_utc_mysql($profile->next_run),
                'last_run' => $this->display_utc_mysql($profile->last_run),
                'last_job_id' => (int) ($profile->last_job_id ?: 0),
            ];
        }

        wp_send_json_success(['profiles' => $result]);
    }

    /**
     * Deletes a single job log (and its items); refused while the job is still running.
     */
    public function ajax_delete_job_log(): void {
        $this->authorize();
        $job_id = absint($_POST['job_id'] ?? 0);
        $job = $this->get_job($job_id);
        if (!$job) wp_send_json_error(['message'=>'Log not found.']);
        if ($job->status === 'running') wp_send_json_error(['message'=>'A running job cannot be deleted.']);
        $this->delete_job_and_items($job_id);
        wp_send_json_success();
    }
    /**
     * Deletes every non-running job log belonging to a cron profile.
     */
    public function ajax_delete_profile_logs(): void {
        $this->authorize();
        global $wpdb;
        $profile_id = absint($_POST['profile_id'] ?? 0);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->jobs_table} WHERE profile_id=%d AND status<>'running'", $profile_id));
        foreach ($ids as $id) $this->delete_job_and_items((int)$id);
        wp_send_json_success(['deleted'=>count($ids)]);
    }
    /**
     * Deletes every non-running manual (non-cron) job log.
     */
    public function ajax_delete_manual_logs(): void {
        $this->authorize();
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM {$this->jobs_table} WHERE profile_id IS NULL AND trigger_source='manual' AND status<>'running'");
        foreach ($ids as $id) $this->delete_job_and_items((int)$id);
        wp_send_json_success(['deleted'=>count($ids)]);
    }
    /**
     * Returns the live report payload for a job (polled by both the manual tab and the log viewer).
     */
    public function ajax_status(): void {
        $this->authorize();
        $job_id = absint($_POST['job_id'] ?? 0);
        $report_page = max(1, absint($_POST['report_page'] ?? 1));
        $per_page = min(500, max(25, absint($_POST['per_page'] ?? 100)));
        $errors_only = !empty($_POST['errors_only']);
        $success_only = !$errors_only && !empty($_POST['success_only']);
        wp_send_json_success($this->status_payload($job_id, $report_page, $per_page, $errors_only, $success_only));
    }
    /**
     * Requests a stop for a single running job.
     */
    public function ajax_stop(): void {
        $this->authorize();
        global $wpdb;
        $job_id = absint($_POST['job_id'] ?? 0);
        $wpdb->update($this->jobs_table, ['stop_requested' => 1], ['id' => $job_id]);
        wp_send_json_success(['job_id' => $job_id]);
    }
    /**
     * Clears a job's report by deleting its items and the job row itself.
     */
    public function ajax_reset(): void {
        $this->authorize();
        global $wpdb;
        $job_id = absint($_POST['job_id'] ?? 0);
        if ($job_id) {
            $wpdb->delete($this->items_table, ['job_id' => $job_id]);
            $wpdb->delete($this->jobs_table, ['id' => $job_id]);
        }
        wp_send_json_success();
    }
    /**
     * Streams a job's report as a CSV download. Uses its own capability/nonce
     * check (rather than authorize()) because this is a GET download, not a
     * wp_send_json_* AJAX action.
     */
    public function ajax_export_csv(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer(self::NONCE_ACTION, 'nonce');

        global $wpdb;
        $job_id = absint($_GET['job_id'] ?? 0);
        $errors_only = !empty($_GET['errors_only']);
        $success_only = !$errors_only && !empty($_GET['success_only']);
        $status_sql = $errors_only
            ? " AND status IN ('failed','skipped')"
            : ($success_only ? " AND status='successful'" : '');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT processed_at,depth,item_type,url,verify_time,response_code,error_text,content_type,cf_cache_status,cache_headers,status
             FROM {$this->items_table} WHERE job_id=%d{$status_sql} ORDER BY id ASC",
            $job_id
        ), ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="warmpilot-job-' . $job_id . ($errors_only ? '-errors' : ($success_only ? '-success' : '')) . '.csv"');
        $csv = "\xEF\xBB\xBF";
        $csv .= $this->csv_line(['Time', 'Depth', 'Type', 'URL', 'Loading time afterwards (seconds)', 'Response code', 'Error text', 'Content-Type', 'CF-Cache-Status', 'Cache headers', 'Status']);
        foreach ($rows as $row) {
            $csv .= $this->csv_line($row);
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate CSV download after authorization.
        echo $csv;
        exit;
    }
    /**
     * Renders one CSV row (double-quoted, with embedded quotes escaped).
     *
     * @param array<int|string, mixed> $fields Row values in column order.
     */
    protected function csv_line(array $fields): string {
        return implode(',', array_map(
            static fn($field): string => '"' . str_replace('"', '""', (string) $field) . '"',
            array_values($fields)
        )) . "\r\n";
    }
}
