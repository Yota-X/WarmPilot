<?php
namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

// Activation intentionally inspects and creates plugin-owned custom tables.
// Table identifiers are generated exclusively from $wpdb->prefix and fixed suffixes.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
final class Activator {
    public static function schema_is_current(): bool {
        global $wpdb;

        $required = [
            $wpdb->prefix . 'warmpilot_jobs' => [
                'id', 'status', 'profile_id', 'trigger_source', 'settings', 'started_at',
                'finished_at', 'stop_requested', 'total', 'successful', 'failed',
                'skipped', 'total_verify_time',
            ],
            $wpdb->prefix . 'warmpilot_items' => [
                'id', 'job_id', 'parent_id', 'depth', 'item_type', 'url', 'url_hash',
                'status', 'discovered_at', 'processed_at', 'warm_time', 'verify_time',
                'response_code', 'content_type', 'cf_cache_status', 'cache_headers',
                'error_text', 'attempts',
            ],
            $wpdb->prefix . 'warmpilot_schedules' => [
                'id', 'name', 'enabled', 'interval_key', 'cron_expression', 'settings',
                'next_run', 'last_run', 'last_job_id', 'created_at', 'updated_at',
            ],
        ];

        foreach ($required as $table => $columns) {
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($table_exists !== $table) {
                return false;
            }

            $actual = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
            if (array_diff($columns, $actual)) {
                return false;
            }
        }

        return true;
    }
    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $jobs = $wpdb->prefix . 'warmpilot_jobs';
        $items = $wpdb->prefix . 'warmpilot_items';
        $schedules = $wpdb->prefix . 'warmpilot_schedules';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$jobs} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            status varchar(20) NOT NULL DEFAULT 'idle',
            profile_id bigint unsigned NULL,
            trigger_source varchar(20) NOT NULL DEFAULT 'manual',
            settings longtext NOT NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            stop_requested tinyint(1) NOT NULL DEFAULT 0,
            total bigint unsigned NOT NULL DEFAULT 0,
            successful bigint unsigned NOT NULL DEFAULT 0,
            failed bigint unsigned NOT NULL DEFAULT 0,
            skipped bigint unsigned NOT NULL DEFAULT 0,
            total_verify_time double NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY status (status),
            KEY profile_status (profile_id, status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$items} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint unsigned NOT NULL,
            parent_id bigint unsigned NULL,
            depth int unsigned NOT NULL DEFAULT 0,
            item_type varchar(20) NOT NULL DEFAULT 'page',
            url text NOT NULL,
            url_hash char(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            discovered_at datetime NOT NULL,
            processed_at datetime NULL,
            warm_time double NULL,
            verify_time double NULL,
            response_code int NULL,
            content_type varchar(191) NULL,
            cf_cache_status varchar(50) NULL,
            cache_headers text NULL,
            error_text text NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY job_url (job_id, url_hash),
            KEY job_status (job_id, status),
            KEY job_processed (job_id, processed_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$schedules} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            interval_key varchar(30) NOT NULL DEFAULT 'hourly',
            cron_expression varchar(100) NULL,
            settings longtext NOT NULL,
            next_run datetime NULL,
            last_run datetime NULL,
            last_job_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY enabled_next (enabled, next_run)
        ) {$charset};");

        add_option(Database::OPTION, Settings::default_settings());
        add_option(Database::LOG_OPTION, Settings::default_log_settings());
        update_option('warmpilot_db_version', Database::DB_VERSION);
    }
}
