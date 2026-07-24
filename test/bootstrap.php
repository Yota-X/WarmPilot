<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);

final class WP_Error {
    public function __construct(public string $code = '', public string $message = '') {}
}

function home_url(string $path = ''): string {
    return 'https://example.com' . ($path === '' ? '' : '/' . ltrim($path, '/'));
}
function untrailingslashit(string $value): string {
    return rtrim($value, '/\\');
}
function wp_parse_url(string $url, int $component = -1): mixed {
    return parse_url($url, $component);
}
function esc_url_raw(string $url): string {
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}
function wp_parse_args(array $args, array $defaults = []): array {
    return array_merge($defaults, $args);
}
function absint(mixed $value): int {
    return abs((int) $value);
}
function wp_unslash(mixed $value): mixed {
    return is_string($value) ? stripslashes($value) : $value;
}
function sanitize_textarea_field(string $value): string {
    return trim(strip_tags(str_replace("\r", '', $value)));
}
function sanitize_text_field(string $value): string {
    return trim(strip_tags(preg_replace('/[\r\n\t ]+/', ' ', $value) ?? $value));
}
function wp_json_encode(mixed $value): string|false {
    return json_encode($value);
}
function current_time(string $type, bool $gmt = false): string {
    return $type === 'mysql' ? '2026-07-24 12:00:00' : '';
}
function wp_timezone(): DateTimeZone {
    return new DateTimeZone('UTC');
}
function get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['wp_test_options'][$name] ?? $default;
}
function update_option(string $name, mixed $value, bool $autoload = true): bool {
    $GLOBALS['wp_test_options'][$name] = $value;
    return true;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', bool|null $autoload = null): bool {
    if (array_key_exists($name, $GLOBALS['wp_test_options'] ?? [])) return false;
    $GLOBALS['wp_test_options'][$name] = $value;
    return true;
}
function get_transient(string $name): mixed {
    return $GLOBALS['wp_test_transients'][$name] ?? false;
}
function set_transient(string $name, mixed $value, int $expiration): bool {
    $GLOBALS['wp_test_transients'][$name] = $value;
    return true;
}
function delete_transient(string $name): bool {
    unset($GLOBALS['wp_test_transients'][$name]);
    return true;
}
function delete_option(string $name): bool {
    $GLOBALS['wp_test_deleted_options'][] = $name;
    unset($GLOBALS['wp_test_options'][$name]);
    return true;
}
function wp_clear_scheduled_hook(string $hook): int|false {
    $GLOBALS['wp_test_cleared_hooks'][] = $hook;
    return 1;
}
function is_wp_error(mixed $value): bool {
    return $value instanceof WP_Error;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once __DIR__ . '/Support/WordPressRequestsStubs.php';
require_once __DIR__ . '/Support/ExposesProtectedMethods.php';
foreach ([
    'class-database.php',
    'class-settings.php',
    'class-url-normalizer.php',
    'class-http-client.php',
    'class-crawler.php',
    'class-job-repository.php',
    'class-job-runner.php',
    'class-cron-manager.php',
    'class-log-repository.php',
    'class-log-rotation.php',
    'class-uninstaller.php',
] as $file) {
    require_once $root . '/includes/' . $file;
}
require_once $root . '/admin/class-admin.php';
require_once $root . '/admin/class-ajax-controller.php';
