<?php
/**
 * Plugin bootstrap: singleton entry point that wires up all admin_menu,
 * admin_enqueue_scripts, wp_ajax_*, and cron hooks.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

/**
 * Top of the plugin's class chain: the single instance constructed from
 * warmpilot.php that registers every WordPress hook the plugin uses.
 */
final class Plugin extends Ajax_Controller {
    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Returns the shared Plugin instance, constructing it on first call.
     */
    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Runs activation checks and registers all of the plugin's WordPress hooks.
     */
    private function __construct() {
        parent::__construct();

        if (defined('DOING_CRON') && DOING_CRON) {
            update_option('warmpilot_cron_heartbeat', time(), false);
        }

        if (get_option('warmpilot_db_version') !== self::DB_VERSION || !Activator::schema_is_current()) {
            Activator::activate();
            $this->realign_existing_schedules();
        }
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_warmpilot_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_warmpilot_save_log_settings', [$this, 'ajax_save_log_settings']);
        add_action('wp_ajax_warmpilot_save_uninstall_settings', [$this, 'ajax_save_uninstall_settings']);
        add_action('wp_ajax_warmpilot_start', [$this, 'ajax_start']);
        add_action('wp_ajax_warmpilot_process', [$this, 'ajax_process']);
        add_action('wp_ajax_warmpilot_status', [$this, 'ajax_status']);
        add_action('wp_ajax_warmpilot_stop', [$this, 'ajax_stop']);
        add_action('wp_ajax_warmpilot_reset', [$this, 'ajax_reset']);
        add_action('wp_ajax_warmpilot_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_warmpilot_save_cron_profile', [$this, 'ajax_save_cron_profile']);
        add_action('wp_ajax_warmpilot_delete_cron_profile', [$this, 'ajax_delete_cron_profile']);
        add_action('wp_ajax_warmpilot_toggle_cron_profile', [$this, 'ajax_toggle_cron_profile']);
        add_action('wp_ajax_warmpilot_run_cron_profile', [$this, 'ajax_run_cron_profile']);
        add_action('wp_ajax_warmpilot_stop_cron_profile', [$this, 'ajax_stop_cron_profile']);
        add_action('wp_ajax_warmpilot_get_cron_profile', [$this, 'ajax_get_cron_profile']);
        add_action('wp_ajax_warmpilot_cron_profiles_status', [$this, 'ajax_cron_profiles_status']);
        add_action('wp_ajax_warmpilot_job_logs', [$this, 'ajax_job_logs']);
        add_action('wp_ajax_warmpilot_delete_job_log', [$this, 'ajax_delete_job_log']);
        add_action('wp_ajax_warmpilot_delete_profile_logs', [$this, 'ajax_delete_profile_logs']);
        add_action('wp_ajax_warmpilot_delete_manual_logs', [$this, 'ajax_delete_manual_logs']);

        // The intervals added by cron_schedules() are fixed literals (60/300/900/604800
        // seconds) that never change between releases, so already-scheduled events never drift.
        // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action(self::CRON_HOOK, [$this, 'cron_tick']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'warmpilot_minute', self::CRON_HOOK);
        }
    }

}
