<?php
/**
 * Fired when the plugin is deleted from the Plugins screen.
 *
 * @package WarmPilot
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/class-uninstaller.php';
YotaX\WarmPilot\Uninstaller::run();
