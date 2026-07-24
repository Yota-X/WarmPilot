<?php
namespace YotaX\WarmPilot;

use WP_Error;

defined('ABSPATH') || exit;

// WarmPilot uses plugin-owned queue tables whose live state must not be cached.
// Table identifiers come exclusively from $wpdb->prefix in Database::__construct().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
class Job_Runner extends Job_Repository {
    protected string $last_job_error = '';

    protected function get_last_job_error(): string {
        return $this->last_job_error;
    }
    protected function acquire_job_lock(int $job_id): bool {
        $key = 'warmpilot_job_lock_' . $job_id;
        $created = add_option($key, time(), '', false);
        if ($created) return true;

        $started_at = (int) get_option($key, 0);
        if ($started_at > 0 && $started_at < time() - 300) {
            delete_option($key);
            return add_option($key, time(), '', false);
        }
        return false;
    }
    protected function release_job_lock(int $job_id): void {
        delete_option('warmpilot_job_lock_' . $job_id);
    }
    protected function create_job(array $settings, ?int $profile_id = null, string $trigger_source = 'manual'): int {
        global $wpdb;
        $this->last_job_error = '';
        $wpdb->insert($this->jobs_table, [
            'status' => 'running',
            'profile_id' => $profile_id,
            'trigger_source' => $trigger_source,
            'settings' => wp_json_encode($settings),
            'started_at' => current_time('mysql', true),
            'stop_requested' => 0,
        ]);
        $job_id = (int) $wpdb->insert_id;
        if (!$job_id) {
            $this->last_job_error = $wpdb->last_error ?: 'The job row could not be inserted into the database.';
            return 0;
        }

        $allowed_hosts = $this->allowed_hosts($settings);
        $seed_count = 0;
        $seed_results = [];
        foreach ($this->lines($settings['start_urls']) as $url) {
            $url = $this->normalize_url($url);
            if ($url) {
                $result = $this->queue_url($job_id, $url, 0, 'page', null, $settings, $allowed_hosts, true);
                $seed_results[] = $url . ': ' . $result;
                if ($result === 'queued') $seed_count++;
            }
        }
        foreach ($this->lines($settings['sitemap_urls']) as $url) {
            $url = $this->normalize_url($url);
            if ($url) {
                $result = $this->queue_url($job_id, $url, 0, 'sitemap', null, $settings, $allowed_hosts, true);
                $seed_results[] = $url . ': ' . $result;
                if ($result === 'queued') $seed_count++;
            }
        }
        if ($seed_count === 0) {
            $db_error = trim((string) $wpdb->last_error);
            $details = $seed_results ? implode('; ', $seed_results) : 'No valid Entry URL or Sitemap URL was found.';
            $this->last_job_error = $db_error !== '' ? $db_error : $details;
            $wpdb->update($this->jobs_table, ['status'=>'finished','finished_at'=>current_time('mysql', true)], ['id'=>$job_id]);
            return 0;
        }
        $this->refresh_job_totals($job_id);
        return $job_id;
    }
    protected function process_job_batch(int $job_id): array|WP_Error {
        if (!$this->acquire_job_lock($job_id)) {
            return new WP_Error('job_locked', 'This job batch is already being processed.');
        }
        try {
            return $this->process_locked_job_batch($job_id);
        } finally {
            $this->release_job_lock($job_id);
        }
    }
    protected function process_locked_job_batch(int $job_id): array|WP_Error {
        global $wpdb;

        $job = $this->get_job($job_id);
        if (!$job) return new WP_Error('not_found', 'Job not found.');

        if ((int) $job->stop_requested === 1) {
            $wpdb->update($this->jobs_table, [
                'status' => 'stopped',
                'finished_at' => current_time('mysql', true),
            ], ['id' => $job_id]);
            return $this->status_payload($job_id);
        }

        $settings = $this->normalize_settings(json_decode($job->settings, true) ?: []);
        $workers = (int) $settings['workers'];

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->items_table}
             WHERE job_id = %d AND status = 'queued'
             ORDER BY id ASC LIMIT %d",
            $job_id,
            $workers
        ));

        if (!$items) {
            $wpdb->update($this->jobs_table, [
                'status' => 'finished',
                'finished_at' => current_time('mysql', true),
            ], ['id' => $job_id]);
            $this->refresh_job_totals($job_id);
            $this->apply_log_rotation_for_job($job_id);
            return $this->status_payload($job_id);
        }

        $ids = array_map(fn($i) => (int) $i->id, $items);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->items_table} SET status='processing' WHERE id IN ({$placeholders})",
            ...$ids
        ));

        $headers = $this->parse_headers($settings['headers']);
        $warm = $this->multi_request_with_retries($items, $headers, $settings, true);

        $allowed_hosts = $this->allowed_hosts($settings);
        foreach ($items as $item) {
            $result = $warm[$item->id] ?? [
                'code' => 0, 'time' => 0, 'headers' => [], 'body' => '', 'error' => 'No HTTP result.'
            ];

            if (!$result['error'] && $result['code'] >= 200 && $result['code'] < 400) {
                if ($item->item_type === 'sitemap') {
                    $this->discover_sitemap_urls($job_id, $item, $result['body'], $settings, $allowed_hosts);
                } elseif ($item->item_type === 'page' && (int) $settings['max_depth'] !== -1) {
                    $this->discover_html_urls($job_id, $item, $result['body'], $settings, $allowed_hosts);
                }
            }
        }

        $verify = [];
        if (!empty($settings['verify_after_warm'])) {
            $verifiable = array_values(array_filter($items, function ($item) use ($warm) {
                $result = $warm[$item->id] ?? null;
                return $result && !$result['error'] && $result['code'] >= 200 && $result['code'] < 500;
            }));
            if ($verifiable) {
                $verify = $this->multi_request_with_retries($verifiable, $headers, $settings, false);
            }
        }

        foreach ($items as $item) {
            $w = $warm[$item->id] ?? null;
            $v = $verify[$item->id] ?? $w;

            $success = $w && !$w['error'] && $w['code'] >= 200 && $w['code'] < 400;
            $status = $success ? 'successful' : 'failed';
            $error = $w ? $w['error'] : 'Unknown request failure';
            if (!$error && !$success) {
                $error = 'HTTP ' . (int) $w['code'];
            }

            $wpdb->update($this->items_table, [
                'status' => $status,
                'processed_at' => current_time('mysql', true),
                'warm_time' => $w ? (float) $w['time'] : null,
                'verify_time' => $v ? (float) $v['time'] : null,
                'response_code' => $v ? (int) $v['code'] : 0,
                'content_type' => $v ? sanitize_text_field($this->header_value($v['headers'], 'content-type')) : '',
                'cf_cache_status' => $v ? sanitize_text_field($this->header_value($v['headers'], 'cf-cache-status')) : '',
                'cache_headers' => $v ? sanitize_textarea_field($this->cache_headers_text($v['headers'])) : '',
                'error_text' => $error,
                'attempts' => $w ? (int)($w['attempts'] ?? 1) : 0,
            ], ['id' => (int) $item->id]);
        }

        $this->refresh_job_totals($job_id);

        if (!empty($settings['delay_seconds'])) {
            usleep((int) round((float) $settings['delay_seconds'] * 1000000));
        }

        return $this->status_payload($job_id);
    }
    protected function status_payload(int $job_id, int $report_page = 1, int $per_page = 100, bool $errors_only = false, bool $success_only = false): array {
        global $wpdb;
        $this->refresh_job_totals($job_id);
        $job = $this->get_job($job_id);
        if (!$job) return ['status' => 'idle', 'items' => []];

        $processed = (int) $job->successful + (int) $job->failed + (int) $job->skipped;
        $avg = (int) $job->successful > 0 ? (float) $job->total_verify_time / (int) $job->successful : 0;

        $start = $job->started_at ? strtotime($job->started_at . ' UTC') : time();
        $end = $job->finished_at ? strtotime($job->finished_at . ' UTC') : time();
        $seconds = max(0, $end - $start);
        $speed = $seconds > 0 ? ($processed / $seconds) * 60 : 0;

        $report_status_sql = $errors_only
            ? "status IN ('failed','skipped')"
            : ($success_only
                ? "status = 'successful'"
                : "status IN ('successful','failed','skipped')");

        $report_total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->items_table} WHERE job_id=%d AND {$report_status_sql}",
            $job_id
        ));
        $report_pages = max(1, (int) ceil($report_total / $per_page));
        $report_page = min(max(1, $report_page), $report_pages);
        $offset = ($report_page - 1) * $per_page;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id,processed_at,depth,item_type,url,verify_time,response_code,content_type,cf_cache_status,cache_headers,error_text,status
             FROM {$this->items_table}
             WHERE job_id=%d AND {$report_status_sql}
             ORDER BY id DESC LIMIT %d OFFSET %d",
            $job_id,
            $per_page,
            $offset
        ), ARRAY_A);

        $queued = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->items_table} WHERE job_id=%d AND status IN ('queued','processing')",
            $job_id
        ));
        $known_total = $processed + $queued;
        $known_progress = $known_total > 0 ? round(($processed / $known_total) * 100, 2) : 0;

        return [
            'job_id' => $job_id,
            'status' => $job->status,
            'queued' => $queued,
            'processed' => $processed,
            'total' => (int) $job->total,
            'successful' => (int) $job->successful,
            'failed' => (int) $job->failed,
            'skipped' => (int) $job->skipped,
            'avg' => round($avg, 4),
            'duration' => gmdate('H:i:s', $seconds),
            'speed' => round($speed, 2),
            'progress' => $known_progress,
            'known_total' => $known_total,
            'remaining' => $queued,
            'report_page' => $report_page,
            'report_pages' => $report_pages,
            'report_total' => $report_total,
            'per_page' => $per_page,
            'errors_only' => $errors_only,
            'success_only' => $success_only,
            'items' => $items,
        ];
    }
}
