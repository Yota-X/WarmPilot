<?php
namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

class Database {
    public const VERSION = '1.0.20.7';
    public const OPTION = 'warmpilot_settings';
    public const LOG_OPTION = 'warmpilot_log_settings';
    public const SCHEDULES_OPTION = 'warmpilot_cron_profiles';
    public const CRON_HOOK = 'warmpilot_cron_tick';
    public const NONCE_ACTION = 'warmpilot_admin';
    public const DB_VERSION = '1.4.1';

    protected string $jobs_table;
    protected string $items_table;
    protected string $schedules_table;

    protected function __construct() {
        global $wpdb;
        $this->jobs_table = $wpdb->prefix . 'warmpilot_jobs';
        $this->items_table = $wpdb->prefix . 'warmpilot_items';
        $this->schedules_table = $wpdb->prefix . 'warmpilot_schedules';
    }
}
