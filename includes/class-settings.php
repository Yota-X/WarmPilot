<?php
/**
 * Default settings and input sanitization for warming jobs.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

/**
 * Provides the default warming/log settings and sanitizes user-submitted settings.
 */
class Settings extends Database {
    /**
     * Default warming settings used for new installs and as a fallback for missing keys.
     *
     * @return array<string, mixed> Default warming settings.
     */
    public static function default_settings(): array {
        return [
            'workers' => 5,
            'timeout' => 20,
            'retry_count' => 2,
            'retry_delay_seconds' => 1,
            'max_urls' => 5000,
            'max_depth' => 0,
            'start_urls' => home_url('/'),
            'sitemap_urls' => home_url('/wp-sitemap.xml'),
            'include_patterns' => untrailingslashit(home_url()) . '/*',
            'exclude_patterns' => implode("\n", [
                '*/wp-admin/*',
                '*/wp-login.php*',
                '*/wp-json/*',
                '*/cart/*',
                '*/checkout/*',
                '*/my-account/*',
                '*/cdn-cgi/*',
                '*?add-to-cart=*',
                '*preview=true*',
            ]),
            'headers' => 'User-Agent: WarmPilot/' . WARMPILOT_VERSION . "\nAccept: text/html,application/xhtml+xml",
            'verify_after_warm' => 1,
            'same_host_only' => 1,
            'visit_scripts' => 0,
            'visit_styles' => 0,
            'visit_fonts' => 0,
            'visit_images' => 0,
            'delay_seconds' => 0,
            'ssl_verify' => 1,
        ];
    }
    /**
     * Default log-retention settings used for new installs and as a fallback for missing keys.
     *
     * @return array<string, mixed> Default log-retention settings.
     */
    public static function default_log_settings(): array {
        return [
            'log_retention_count' => 50,
            'log_retention_days' => 30,
            'delete_data_on_uninstall' => 0,
        ];
    }
    /**
     * Migrates legacy millisecond-based delay keys and fills in any missing defaults.
     *
     * @param array<string, mixed> $settings Raw stored or submitted settings.
     * @return array<string, mixed> Settings with legacy keys migrated and defaults applied.
     */
    protected function normalize_settings(array $settings): array {
        if (!array_key_exists('delay_seconds', $settings) && array_key_exists('delay_ms', $settings)) {
            $settings['delay_seconds'] = max(0, (float) $settings['delay_ms'] / 1000);
        }
        if (!array_key_exists('retry_delay_seconds', $settings) && array_key_exists('retry_delay_ms', $settings)) {
            $settings['retry_delay_seconds'] = max(0, (float) $settings['retry_delay_ms'] / 1000);
        }
        unset($settings['delay_ms'], $settings['retry_delay_ms']);
        return wp_parse_args($settings, self::default_settings());
    }
    /**
     * Sanitizes and clamps raw $_POST-style settings input into safe, bounded values.
     *
     * @param array<string, mixed> $input Raw, unsanitized settings input (already unslashed by callers where needed).
     * @return array<string, mixed> Sanitized settings ready for storage.
     */
    protected function sanitize_settings(array $input): array {
        $defaults = self::default_settings();
        $bools = ['verify_after_warm', 'same_host_only', 'visit_scripts', 'visit_styles', 'visit_fonts', 'visit_images', 'ssl_verify'];
        $out = [
            'workers' => min(30, max(1, absint($input['workers'] ?? $defaults['workers']))),
            'timeout' => min(300, max(1, absint($input['timeout'] ?? $defaults['timeout']))),
            'retry_count' => min(10, max(0, absint($input['retry_count'] ?? $defaults['retry_count']))),
            'retry_delay_seconds' => max(0, min(86400, (float) ($input['retry_delay_seconds'] ?? (isset($input['retry_delay_ms']) ? ((float) $input['retry_delay_ms'] / 1000) : $defaults['retry_delay_seconds'])))),
            'max_urls' => max(0, (int) ($input['max_urls'] ?? $defaults['max_urls'])),
            'max_depth' => max(-1, (int) ($input['max_depth'] ?? $defaults['max_depth'])),
            'delay_seconds' => max(0, min(86400, (float) ($input['delay_seconds'] ?? (isset($input['delay_ms']) ? ((float)$input['delay_ms'] / 1000) : 0)))),
        ];
        foreach (['start_urls', 'sitemap_urls', 'include_patterns', 'exclude_patterns', 'headers'] as $key) {
            $out[$key] = sanitize_textarea_field(wp_unslash($input[$key] ?? $defaults[$key]));
        }
        foreach ($bools as $key) {
            $out[$key] = !empty($input[$key]) ? 1 : 0;
        }
        return $out;
    }
}
