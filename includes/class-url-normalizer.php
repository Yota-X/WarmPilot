<?php
namespace YotaX\WarmPilot;

use Pdp\Domain;
use Pdp\Rules;
use Throwable;

defined('ABSPATH') || exit;

class Url_Normalizer extends Settings {
    protected function wildcard_match(string $pattern, string $url): bool {
        $pattern = trim($pattern);
        if ($pattern === '') return false;
        $regex = preg_quote($pattern, '~');
        $regex = str_replace('\*', '.*', $regex);
        return (bool) preg_match('~^' . $regex . '$~i', $url);
    }

    /**
     * Patterns written for any allowed host apply to its registrable domain and
     * subdomains, as resolved through the bundled Public Suffix List. The path,
     * query string, scheme and wildcard syntax are still respected.
     */
    protected function allowed_pattern_match(string $pattern, string $url, array $allowed_hosts = []): bool {
        if ($this->wildcard_match($pattern, $url)) {
            return true;
        }

        $pattern = trim($pattern);
        $pattern_parts = wp_parse_url($pattern);
        $url_parts = wp_parse_url($url);
        if (!$pattern_parts || !$url_parts || empty($pattern_parts['host']) || empty($url_parts['host'])) {
            return false;
        }

        $pattern_host = strtolower(rtrim((string) $pattern_parts['host'], '.'));
        $url_host = strtolower(rtrim((string) $url_parts['host'], '.'));
        if (
            !$this->host_matches_allowed_roots($pattern_host, $allowed_hosts)
            || !$this->host_matches_allowed_roots($url_host, $allowed_hosts)
        ) {
            return false;
        }

        $pattern_scheme = strtolower((string) ($pattern_parts['scheme'] ?? 'https'));
        $url_path = (string) ($url_parts['path'] ?? '/');
        $url_query = isset($url_parts['query']) && $url_parts['query'] !== '' ? '?' . $url_parts['query'] : '';
        $host_normalized_url = $pattern_scheme . '://' . $pattern_host . $url_path . $url_query;

        return $this->wildcard_match($pattern, $host_normalized_url);
    }
    protected function allowed_hosts(array $settings): array {
        $hosts = [];

        foreach (array_merge(
            $this->lines((string) ($settings['start_urls'] ?? '')),
            $this->lines((string) ($settings['sitemap_urls'] ?? ''))
        ) as $candidate_url) {
            $host = strtolower(rtrim((string) wp_parse_url($candidate_url, PHP_URL_HOST), '.'));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        $home_host = strtolower(rtrim((string) wp_parse_url(home_url(), PHP_URL_HOST), '.'));
        if ($home_host !== '') {
            $hosts[] = $home_host;
        }

        return array_values(array_unique($hosts));
    }
    protected function domain_root(string $host): string {
        $host = strtolower(trim(rtrim($host, '.')));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        try {
            static $rules;
            $rules ??= Rules::fromPath(dirname(__DIR__) . '/resources/public_suffix_list.dat');
            $resolved = $rules->resolve(Domain::fromIDNA2008($host));
            $registrable = $resolved->registrableDomain()->toString();

            return $resolved->suffix()->isKnown() && $registrable !== '' ? strtolower($registrable) : $host;
        } catch (Throwable) {
            // Fail closed: an unavailable or invalid PSL must never widen the crawl scope.
            return $host;
        }
    }
    protected function host_matches_allowed_roots(string $host, array $allowed_hosts): bool {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '') {
            return false;
        }

        foreach ($allowed_hosts as $allowed_host) {
            $root = $this->domain_root((string) $allowed_host);

            if ($root && ($host === $root || str_ends_with($host, '.' . $root))) {
                return true;
            }
        }

        return false;
    }
    protected function is_site_domain_or_subdomain(string $host): bool {
        return $this->host_matches_allowed_roots($host, $this->allowed_hosts([
            'start_urls' => home_url('/'),
            'sitemap_urls' => '',
        ]));
    }
    protected function lines(string $text): array {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));
    }
    protected function canonical_url_key(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $port_text = ($port && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) ? ':' . $port : '';

        $path = $parts['path'] ?? '/';
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') array_pop($segments); else $segments[] = $segment;
        }
        $normalized_path = '/' . implode('/', $segments);
        if ($normalized_path !== '/' && str_ends_with($path, '/')) {
            $normalized_path .= '/';
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query_args);
            $this->recursive_ksort($query_args);
            $query_string = http_build_query($query_args, '', '&', PHP_QUERY_RFC3986);
            if ($query_string !== '') $query = '?' . $query_string;
        }

        return $scheme . '://' . $host . $port_text . $normalized_path . $query;
    }
    protected function recursive_ksort(array &$value): void {
        ksort($value);
        foreach ($value as &$child) {
            if (is_array($child)) $this->recursive_ksort($child);
        }
    }
    protected function normalize_url(string $url): ?string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '') return null;
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        if ($path === '') $path = '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return esc_url_raw($scheme . '://' . $host . $port . $path . $query);
    }
    protected function absolute_url(string $base, string $relative): ?string {
        $relative = trim(html_entity_decode($relative, ENT_QUOTES | ENT_HTML5));
        if ($relative === '' || str_starts_with($relative, '#') || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $relative)) {
            return null;
        }
        if (preg_match('~^https?://~i', $relative)) return $this->normalize_url($relative);

        $base_parts = wp_parse_url($base);
        if (!$base_parts || empty($base_parts['scheme']) || empty($base_parts['host'])) return null;

        if (str_starts_with($relative, '//')) {
            return $this->normalize_url($base_parts['scheme'] . ':' . $relative);
        }

        $origin = $base_parts['scheme'] . '://' . $base_parts['host'] . (isset($base_parts['port']) ? ':' . $base_parts['port'] : '');
        if (str_starts_with($relative, '/')) return $this->normalize_url($origin . $relative);

        $path = $base_parts['path'] ?? '/';
        $dir = preg_replace('~/[^/]*$~', '/', $path);
        $combined = $dir . $relative;

        $segments = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        return $this->normalize_url($origin . '/' . implode('/', $segments));
    }
}
