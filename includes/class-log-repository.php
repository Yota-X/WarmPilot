<?php
/**
 * Read queries for the job log tables used by the Job Logs tab.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

// Reports read live data from plugin-owned tables; caching would return stale job state.
// Table identifiers come exclusively from $wpdb->prefix in Database::__construct().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
/**
 * Fetches job log rows (with their cron profile name, if any) for display.
 */
class Log_Repository extends Cron_Manager {
    /**
     * Fetches the most recent job logs of any trigger source.
     *
     * @param int $limit Maximum rows to return (clamped to 1-2000).
     * @return object[] Job rows with an added profile_name column.
     */
    protected function get_all_job_logs(int $limit = 500): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT j.*, s.name AS profile_name
             FROM {$this->jobs_table} j
             LEFT JOIN {$this->schedules_table} s ON s.id=j.profile_id
             ORDER BY j.id DESC LIMIT %d",
            max(1, min(2000, $limit))
        ));
    }
    /**
     * Fetches the most recent cron-triggered job logs.
     *
     * @param int $limit Maximum rows to return (clamped to 1-1000).
     * @return object[] Job rows with an added profile_name column.
     */
    protected function get_cron_job_logs(int $limit = 200): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT j.*, s.name AS profile_name FROM {$this->jobs_table} j LEFT JOIN {$this->schedules_table} s ON s.id=j.profile_id WHERE j.trigger_source IN ('cron','cron_manual') ORDER BY j.id DESC LIMIT %d",
            max(1, min(1000, $limit))
        ));
    }
}
