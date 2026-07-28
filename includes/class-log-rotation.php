<?php
/**
 * Log-retention rotation for warming job history.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

// Log retention operates on plugin-owned tables and must observe current job state.
// Table identifiers come exclusively from $wpdb->prefix in Database::__construct().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
/**
 * Applies the configured retention (count and age) to finished job logs.
 */
class Log_Rotation extends Log_Repository {
    /**
     * Runs global log rotation once a specific job has finished.
     *
     * @param int $job_id Job ID that just finished processing.
     */
    protected function apply_log_rotation_for_job(int $job_id): void {
        $job = $this->get_job($job_id);
        if (!$job || $job->status === 'running') return;
        $this->apply_global_log_rotation();
    }
    /**
     * Deletes finished jobs (and their items) beyond the configured retention count/age.
     */
    protected function apply_global_log_rotation(): void {
        global $wpdb;
        $settings = wp_parse_args(get_option(self::LOG_OPTION, []), self::default_log_settings());
        $count = max(0, (int) $settings['log_retention_count']);
        $days = max(0, (int) $settings['log_retention_days']);
        $delete_ids = [];
        if ($days > 0) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
            $delete_ids = array_merge($delete_ids, $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$this->jobs_table} WHERE status<>'running' AND started_at < %s",
                $cutoff
            )));
        }
        if ($count > 0) {
            $keep = $wpdb->get_col("SELECT id FROM {$this->jobs_table} WHERE status<>'running' ORDER BY id DESC LIMIT " . (int) $count);
            $all = $wpdb->get_col("SELECT id FROM {$this->jobs_table} WHERE status<>'running'");
            $delete_ids = array_merge($delete_ids, array_diff($all, $keep));
        }
        foreach (array_unique(array_map('intval', $delete_ids)) as $id) {
            $this->delete_job_and_items($id);
        }
    }
}
