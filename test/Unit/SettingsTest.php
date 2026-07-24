<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WarmPilot\Tests\Support\ExposesProtectedMethods;
use YotaX\WarmPilot\Settings;

require_once dirname(__DIR__) . '/Support/ExposesProtectedMethods.php';

final class SettingsHarness extends Settings {
    use ExposesProtectedMethods;
    public function __construct() {}
}

final class SettingsTest extends TestCase {
    private SettingsHarness $settings;

    protected function setUp(): void {
        $this->settings = new SettingsHarness();
    }

    public function testDefaultsDescribeSafeInitialConfiguration(): void {
        $defaults = Settings::default_settings();
        self::assertSame(5, $defaults['workers']);
        self::assertSame(20, $defaults['timeout']);
        self::assertSame(2, $defaults['retry_count']);
        self::assertSame(5000, $defaults['max_urls']);
        self::assertSame(0, $defaults['max_depth']);
        self::assertSame(1, $defaults['ssl_verify']);
    }

    public function testSanitizationClampsNumericLimits(): void {
        $result = $this->settings->call('sanitize_settings', [
            'workers' => 999,
            'timeout' => 0,
            'retry_count' => 999,
            'max_urls' => -5,
            'max_depth' => -99,
            'delay_seconds' => 999999,
            'retry_delay_seconds' => -1,
        ]);
        self::assertSame(30, $result['workers']);
        self::assertSame(1, $result['timeout']);
        self::assertSame(10, $result['retry_count']);
        self::assertSame(0, $result['max_urls']);
        self::assertSame(-1, $result['max_depth']);
        self::assertSame(86400, $result['delay_seconds']);
        self::assertSame(0, $result['retry_delay_seconds']);
    }

    public function testMissingCheckboxesBecomeDisabled(): void {
        $result = $this->settings->call('sanitize_settings', []);
        foreach (['verify_after_warm', 'same_host_only', 'visit_scripts', 'visit_styles', 'visit_fonts', 'visit_images', 'ssl_verify'] as $key) {
            self::assertSame(0, $result[$key]);
        }
    }

    public function testLegacyMillisecondValuesAreNormalized(): void {
        $result = $this->settings->call('normalize_settings', [
            'delay_ms' => 1500,
            'retry_delay_ms' => 250,
        ]);
        self::assertSame(1.5, $result['delay_seconds']);
        self::assertSame(0.25, $result['retry_delay_seconds']);
        self::assertArrayNotHasKey('delay_ms', $result);
        self::assertArrayNotHasKey('retry_delay_ms', $result);
    }

    public function testLogDefaultsAreStable(): void {
        self::assertSame([
            'log_retention_count' => 50,
            'log_retention_days' => 30,
            'delete_data_on_uninstall' => 0,
        ], Settings::default_log_settings());
    }
}
