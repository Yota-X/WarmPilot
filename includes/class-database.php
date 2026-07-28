<?php
/**
 * Shared constants and per-request table names for the plugin's custom tables.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

/**
 * Base of the plugin's class chain: option/table names and shared constants
 * inherited by every other WarmPilot class (Settings, Admin, Ajax_Controller, ...).
 */
class Database {
    // Tied to WARMPILOT_VERSION so enqueued asset cache-busting always matches
    // the plugin's actual release version instead of a separately tracked number.
    public const VERSION = WARMPILOT_VERSION;
    public const OPTION = 'warmpilot_settings';
    public const LOG_OPTION = 'warmpilot_log_settings';
    public const SCHEDULES_OPTION = 'warmpilot_cron_profiles';
    public const CRON_HOOK = 'warmpilot_cron_tick';
    public const NONCE_ACTION = 'warmpilot_admin';
    public const DB_VERSION = '1.4.1';

    /**
     * Prefixed name of the jobs table.
     *
     * @var string
     */
    protected string $jobs_table;
    /**
     * Prefixed name of the job items (queued URLs) table.
     *
     * @var string
     */
    protected string $items_table;
    /**
     * Prefixed name of the cron profiles (schedules) table.
     *
     * @var string
     */
    protected string $schedules_table;

    /**
     * Resolves the plugin's table names from the current $wpdb prefix.
     */
    protected function __construct() {
        global $wpdb;
        $this->jobs_table = $wpdb->prefix . 'warmpilot_jobs';
        $this->items_table = $wpdb->prefix . 'warmpilot_items';
        $this->schedules_table = $wpdb->prefix . 'warmpilot_schedules';
    }
}
