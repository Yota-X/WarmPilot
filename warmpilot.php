<?php
/**
 * Plugin Name: WarmPilot
 * Plugin URI: https://github.com/Yota-X/WarmPilot
 * Description: Multi-worker cache warming with live reporting, URL crawling, cron profiles, retries, and asset preloading.
 * Version: 1.0.1
 * Author: Yota-X
 * Author URI: https://github.com/Yota-X
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Text Domain: warmpilot
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WarmPilot
 */

defined('ABSPATH') || exit;

define('WARMPILOT_VERSION', '1.0.1');
define('WARMPILOT_FILE', __FILE__);
define('WARMPILOT_PATH', plugin_dir_path(__FILE__));
define('WARMPILOT_URL', plugin_dir_url(__FILE__));

require_once WARMPILOT_PATH . 'vendor/autoload.php';

$warmpilot_files = [
    'includes/class-database.php',
    'includes/class-settings.php',
    'includes/class-url-normalizer.php',
    'includes/class-http-client.php',
    'includes/class-crawler.php',
    'includes/class-job-repository.php',
    'includes/class-job-runner.php',
    'includes/class-cron-manager.php',
    'includes/class-log-repository.php',
    'includes/class-log-rotation.php',
    'admin/class-admin.php',
    'admin/class-ajax-controller.php',
    'includes/class-activator.php',
    'includes/class-plugin.php',
];
foreach ($warmpilot_files as $warmpilot_file) {
    require_once WARMPILOT_PATH . $warmpilot_file;
}

register_activation_hook(__FILE__, [YotaX\WarmPilot\Activator::class, 'activate']);
YotaX\WarmPilot\Plugin::instance();
