<?php
/**
 * Admin page shell: tab navigation plus each tab's view.
 *
 * @package WarmPilot
 */

defined('ABSPATH') || exit;
?>
        <div class="wrap warmpilot-wrap">
            <h1><?php esc_html_e('WarmPilot – Cache Warmer & Cron Crawler', 'warmpilot'); ?></h1>
            <p class="description">
                <?php esc_html_e('Warms pages concurrently, crawls pagination and internal links, optionally visits assets, then verifies response time and cache headers.', 'warmpilot'); ?>
            </p>
            <nav class="nav-tab-wrapper warmpilot-tabs">
                <a href="#warmpilot-manual-tab" class="nav-tab nav-tab-active" data-tab="manual"><?php esc_html_e('Manual warming', 'warmpilot'); ?></a>
                <a href="#warmpilot-cron-tab" class="nav-tab" data-tab="cron"><?php esc_html_e('Cron tasks', 'warmpilot'); ?></a>
                <a href="#warmpilot-log-tab" class="nav-tab warmpilot-log-nav" data-tab="log"><?php esc_html_e('Job Logs', 'warmpilot'); ?></a>
                <a href="#warmpilot-log-settings-tab" class="nav-tab" data-tab="log-settings"><?php esc_html_e('Log settings', 'warmpilot'); ?></a>
                <a href="#warmpilot-uninstall-tab" class="nav-tab" data-tab="uninstall"><?php esc_html_e('Data & Uninstall', 'warmpilot'); ?></a>
            </nav>

<?php require WARMPILOT_PATH . 'admin/views/tab-manual.php'; ?>
<?php require WARMPILOT_PATH . 'admin/views/tab-cron.php'; ?>
<?php require WARMPILOT_PATH . 'admin/views/tab-job-logs.php'; ?>
<?php require WARMPILOT_PATH . 'admin/views/tab-log-settings.php'; ?>
<?php require WARMPILOT_PATH . 'admin/views/tab-uninstall.php'; ?>
        </div>
