<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YotaX\WarmPilot\Uninstaller;

final class UninstallDatabase {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public array $queries = [];

    public function query(string $query): bool {
        $this->queries[] = $query;
        return true;
    }
}

final class UninstallerTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wpdb'] = new UninstallDatabase();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_deleted_options'] = [];
        $GLOBALS['wp_test_cleared_hooks'] = [];
        $GLOBALS['wp_test_transients'] = ['warmpilot_cron_lock' => 1];
    }

    public function testPreservesEverythingByDefault(): void {
        Uninstaller::run();

        self::assertSame([], $GLOBALS['wpdb']->queries);
        self::assertSame([], $GLOBALS['wp_test_deleted_options']);
        self::assertSame([], $GLOBALS['wp_test_cleared_hooks']);
        self::assertArrayHasKey('warmpilot_cron_lock', $GLOBALS['wp_test_transients']);
    }

    public function testDeletesAllPluginDataWhenExplicitlyEnabled(): void {
        $GLOBALS['wp_test_options']['warmpilot_log_settings'] = [
            'delete_data_on_uninstall' => 1,
        ];

        Uninstaller::run();

        self::assertSame([
            'DROP TABLE IF EXISTS `wp_warmpilot_items`',
            'DROP TABLE IF EXISTS `wp_warmpilot_jobs`',
            'DROP TABLE IF EXISTS `wp_warmpilot_schedules`',
            "DELETE FROM wp_options WHERE option_name LIKE 'warmpilot_job_lock_%'",
        ], $GLOBALS['wpdb']->queries);
        self::assertSame(['warmpilot_cron_tick'], $GLOBALS['wp_test_cleared_hooks']);
        self::assertContains('warmpilot_settings', $GLOBALS['wp_test_deleted_options']);
        self::assertContains('warmpilot_log_settings', $GLOBALS['wp_test_deleted_options']);
        self::assertContains('warmpilot_db_version', $GLOBALS['wp_test_deleted_options']);
        self::assertContains('warmpilot_cron_lock', $GLOBALS['wp_test_deleted_options']);
    }
}
