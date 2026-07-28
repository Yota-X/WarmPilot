<?php
/**
 * Discovery of further URLs to warm from sitemap XML and crawled HTML.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

use DOMDocument;
use DOMXPath;

defined('ABSPATH') || exit;

/**
 * Parses sitemap XML and HTML responses to queue further discovered URLs
 * (pages, sub-sitemaps, and optionally scripts/styles/fonts/images).
 */
class Crawler extends Http_Client {
    /**
     * Queues every <loc> URL found in a sitemap XML document.
     *
     * @param int                  $job_id        Job the discovered URLs belong to.
     * @param object               $item          The sitemap item that was just fetched.
     * @param string               $xml           Response body of the sitemap.
     * @param array<string, mixed> $settings      Job settings.
     * @param string[]             $allowed_hosts Hosts considered in-scope for this job.
     */
    protected function discover_sitemap_urls(int $job_id, object $item, string $xml, array $settings, array $allowed_hosts): void {
        if ($xml === '') return;

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) {
            libxml_clear_errors();
            return;
        }

        foreach ($doc->xpath('//*[local-name()="loc"]') ?: [] as $loc) {
            $url = $this->normalize_url((string) $loc);
            if (!$url) continue;
            $type = preg_match('~\.xml(?:\.gz)?(?:\?.*)?$~i', $url) ? 'sitemap' : 'page';
            $this->queue_url($job_id, $url, (int) $item->depth + 1, $type, (int) $item->id, $settings, $allowed_hosts, $type === 'sitemap');
        }
        libxml_clear_errors();
    }
    /**
     * Queues links found in an HTML page, plus optional script/style/font/image assets per settings.
     *
     * @param int                  $job_id        Job the discovered URLs belong to.
     * @param object               $item          The page item that was just fetched.
     * @param string               $html          Response body of the page.
     * @param array<string, mixed> $settings      Job settings (visit_scripts, visit_styles, visit_fonts, visit_images, ...).
     * @param string[]             $allowed_hosts Hosts considered in-scope for this job.
     */
    protected function discover_html_urls(int $job_id, object $item, string $html, array $settings, array $allowed_hosts): void {
        if ($html === '') return;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Crawled pages are frequently not well-formed HTML; libxml_use_internal_errors()
        // routes libxml warnings away from output, and @ additionally silences the
        // non-libxml PHP notices loadHTML() can still emit for malformed markup.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $url = $this->absolute_url($item->url, $node->getAttribute('href'));
            if ($url) {
                $this->queue_url($job_id, $url, (int) $item->depth + 1, 'page', (int) $item->id, $settings, $allowed_hosts);
            }
        }

        $asset_queries = [];
        if (!empty($settings['visit_scripts'])) $asset_queries['script'] = '//script[@src]/@src';
        if (!empty($settings['visit_styles'])) $asset_queries['style'] = '//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")]/@href';
        if (!empty($settings['visit_images'])) {
            $asset_queries['image'] = '//img[@src]/@src';
        }
        if (!empty($settings['visit_fonts'])) {
            $asset_queries['font'] = '//link[@href and (contains(@rel,"preload") or contains(@as,"font"))]/@href';
        }

        foreach ($asset_queries as $type => $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
                $url = $this->absolute_url($item->url, $node->nodeValue);
                if ($url) {
                    $this->queue_url($job_id, $url, (int) $item->depth + 1, $type, (int) $item->id, $settings, $allowed_hosts, true);
                }
            }
        }

        if (!empty($settings['visit_images'])) {
            foreach ($xpath->query('//img[@srcset]/@srcset') ?: [] as $node) {
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
                foreach (explode(',', $node->nodeValue) as $candidate) {
                    $candidate = trim(explode(' ', trim($candidate))[0]);
                    $url = $this->absolute_url($item->url, $candidate);
                    if ($url) {
                        $this->queue_url($job_id, $url, (int) $item->depth + 1, 'image', (int) $item->id, $settings, $allowed_hosts, true);
                    }
                }
            }
        }
        libxml_clear_errors();
    }
}
