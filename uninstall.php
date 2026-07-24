<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/class-uninstaller.php';
YotaX\WarmPilot\Uninstaller::run();
