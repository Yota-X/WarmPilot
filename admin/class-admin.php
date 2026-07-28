<?php
/**
 * Admin menu registration, asset enqueueing, and the settings page views.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

/**
 * Renders the WarmPilot admin page (Tools > WarmPilot) and its assets.
 */
class Admin extends Log_Rotation {
    /**
     * Registers the WarmPilot admin page under Tools.
     */
    public function admin_menu(): void {
        add_management_page(
            __('WarmPilot', 'warmpilot'),
            __('WarmPilot', 'warmpilot'),
            'manage_options',
            'warmpilot',
            [$this, 'render_admin']
        );
    }
    /**
     * Enqueues the admin CSS/JS only on WarmPilot's own admin page.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets(string $hook): void {
        if ($hook !== 'tools_page_warmpilot') {
            return;
        }
        wp_enqueue_style('warmpilot-admin', WARMPILOT_URL . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('warmpilot-admin', WARMPILOT_URL . 'assets/admin.js', ['jquery'], self::VERSION, true);
        wp_localize_script('warmpilot-admin', 'WarmPilotAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'strings' => [
                'starting' => __('Starting…', 'warmpilot'),
                'running' => __('Running', 'warmpilot'),
                'stopped' => __('Stopped', 'warmpilot'),
                'finished' => __('Finished', 'warmpilot'),
                'error' => __('Request failed.', 'warmpilot'),
                'idle' => __('Idle', 'warmpilot'),
                'stop' => __('Stop', 'warmpilot'),
                'stopping' => __('Stopping…', 'warmpilot'),
                'stoppingState' => __('Stopping', 'warmpilot'),
                /* translators: %d: job ID. */
                'jobHash' => __('Job #%d', 'warmpilot'),
                'viewLog' => __('View log', 'warmpilot'),
                'success' => __('Success', 'warmpilot'),
                'errorsLabel' => __('Errors', 'warmpilot'),
                'csv' => __('CSV', 'warmpilot'),
                'delete' => __('Delete', 'warmpilot'),
                'manual' => __('Manual', 'warmpilot'),
                'cron' => __('Cron', 'warmpilot'),
                /* translators: %1$s: current page number, %2$s: total number of pages. */
                'pageOfPages' => __('Page %1$s of %2$s', 'warmpilot'),
                /* translators: %s: total number of rows. */
                'rowsCount' => __('%s rows', 'warmpilot'),
            ],
        ]);
    }
    /**
     * Renders the WarmPilot admin page (all tabs), gated on manage_options.
     */
    public function render_admin(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = $this->normalize_settings(get_option(self::OPTION, []));
        $log_settings = wp_parse_args(get_option(self::LOG_OPTION, []), self::default_log_settings());
        require WARMPILOT_PATH . 'admin/views/page.php';
    }
    /**
     * Renders a labeled checkbox input bound to a settings key.
     *
     * @param string               $name      Settings key / input name.
     * @param string               $label     Visible label text (already translated by the caller).
     * @param array<string, mixed> $settings  Settings array to read the current value from.
     * @param string               $id_prefix Optional prefix for the input's id attribute (to avoid duplicate ids across tabs).
     */
    protected function checkbox(string $name, string $label, array $settings, string $id_prefix = ''): void {
        ?>
        <label class="warmpilot-check">
            <input type="checkbox" id="<?php echo esc_attr($id_prefix . $name); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked(!empty($settings[$name])); ?>>
            <?php echo esc_html($label); ?>
        </label>
        <?php
    }
    /**
     * Renders the "Cron environment" diagnostics card (detected mode, heartbeat, recommended check interval).
     */
    protected function render_cron_environment(): void {
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $heartbeat = (int)get_option('warmpilot_cron_heartbeat', 0);
        $age = $heartbeat ? max(0, time() - $heartbeat) : null;
        $recent = $age !== null && $age <= 180;
        $shortest = $this->shortest_enabled_interval();
        $recommended_minutes = $shortest > 0 ? max(1, min(15, (int)floor($shortest / 60))) : 5;
        $mode = $disabled ? 'System cron mode' : 'WordPress traffic cron mode';
        $state = $disabled ? ($recent ? 'Active' : 'Needs verification') : 'Active';
        $state_class = $disabled && !$recent ? 'warning' : 'ok';
        $command = '* * * * * cd /path/to/wordpress && /usr/bin/php wp-cron.php >/dev/null 2>&1';
        ?>
        <section class="warmpilot-card warmpilot-cron-environment">
            <div class="warmpilot-cron-environment-head">
                <div>
                    <h2><?php esc_html_e('Cron environment', 'warmpilot'); ?></h2>
                    <p class="description"><?php esc_html_e('This shows how due WordPress cron events are currently triggered.', 'warmpilot'); ?></p>
                </div>
                <div class="warmpilot-cron-mode-summary">
                    <span class="warmpilot-cron-mode-badge"><?php echo esc_html($mode); ?></span>
                    <span class="warmpilot-cron-health warmpilot-cron-health-<?php echo esc_attr($state_class); ?>"><?php echo esc_html($state); ?></span>
                </div>
            </div>
            <div class="warmpilot-cron-mode-grid">
                <div class="warmpilot-cron-mode-option <?php echo !$disabled ? 'is-current' : ''; ?>">
                    <h3><?php esc_html_e('Mode 1: WordPress traffic cron', 'warmpilot'); ?></h3>
                    <p><?php esc_html_e('No server setup is required. WordPress checks due tasks when the site receives requests. It is easy to use, but jobs may start late when traffic is low.', 'warmpilot'); ?></p>
                    <code>DISABLE_WP_CRON = false</code>
                </div>
                <div class="warmpilot-cron-mode-option <?php echo $disabled ? 'is-current' : ''; ?>">
                    <h3><?php esc_html_e('Mode 2: System cron', 'warmpilot'); ?></h3>
                    <p><?php esc_html_e('Recommended for reliable schedules. The server calls wp-cron.php regularly, independently of visitors. Calling it every minute supports every-minute tasks and aligned 5/15-minute schedules.', 'warmpilot'); ?></p>
                    <code>define('DISABLE_WP_CRON', true);</code>
                </div>
            </div>
            <div class="warmpilot-cron-diagnostics">
                <div><strong><?php esc_html_e('Detected mode:', 'warmpilot'); ?></strong> <?php echo esc_html($mode); ?></div>
                <div><strong><?php esc_html_e('Last WP-Cron heartbeat:', 'warmpilot'); ?></strong>
                    <?php echo $heartbeat ? esc_html(human_time_diff($heartbeat, time()) . ' ago') : esc_html__('Not detected yet', 'warmpilot'); ?>
                </div>
                <div><strong><?php esc_html_e('Shortest enabled task:', 'warmpilot'); ?></strong>
                    <?php echo $shortest ? esc_html(($shortest / 60) . ' minute(s)') : esc_html__('No enabled tasks', 'warmpilot'); ?>
                </div>
                <div><strong><?php esc_html_e('Recommended wp-cron check:', 'warmpilot'); ?></strong>
                    <?php echo esc_html($recommended_minutes === 1 ? 'Every minute' : 'At least every ' . $recommended_minutes . ' minutes'); ?>
                </div>
            </div>
            <?php if ($disabled && !$recent) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('DISABLE_WP_CRON is enabled, but no recent wp-cron.php heartbeat was detected. Make sure the server cron is saved and running.', 'warmpilot'); ?></p></div>
            <?php elseif (!$disabled) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e('Traffic mode is enabled. The plugin will work, but exact start times depend on incoming site requests. Switch to system cron for predictable execution.', 'warmpilot'); ?></p></div>
            <?php endif; ?>
            <details class="warmpilot-cron-setup">
                <summary><?php esc_html_e('System cron setup instructions', 'warmpilot'); ?></summary>
                <p><?php esc_html_e('Add this line to wp-config.php:', 'warmpilot'); ?></p>
                <pre><code>define('DISABLE_WP_CRON', true);</code></pre>
                <p><?php esc_html_e('Generic system cron example:', 'warmpilot'); ?></p>
                <pre><code><?php echo esc_html($command); ?></code></pre>
                <p class="hint"><?php esc_html_e('The server may call wp-cron.php every minute; only tasks whose scheduled time is due will start.', 'warmpilot'); ?></p>
            </details>
        </section>
        <?php
    }
}
