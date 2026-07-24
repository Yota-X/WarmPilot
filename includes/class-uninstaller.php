<?php
namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

// Uninstall removes only fixed, plugin-owned table names based on $wpdb->prefix.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
final class Uninstaller {
    private const LOG_OPTION = 'warmpilot_log_settings';
    private const CRON_HOOK = 'warmpilot_cron_tick';

    public static function run(): void {
        $settings = get_option(self::LOG_OPTION, []);
        if (empty($settings['delete_data_on_uninstall'])) {
            return;
        }

        global $wpdb;
        foreach (['warmpilot_items', 'warmpilot_jobs', 'warmpilot_schedules'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            // Removing plugin-owned tables is explicitly requested by the uninstall setting.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);

        foreach ([
            'warmpilot_settings',
            self::LOG_OPTION,
            'warmpilot_cron_profiles',
            'warmpilot_db_version',
            'warmpilot_cron_heartbeat',
            'warmpilot_cron_lock',
            'warmpilot_brand_version',
        ] as $option) {
            delete_option($option);
        }

        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'warmpilot_job_lock_%'"
        );
    }
}
