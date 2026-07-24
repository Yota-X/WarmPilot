<?php
namespace YotaX\WarmPilot;

use DOMDocument;
use DOMXPath;

defined('ABSPATH') || exit;

class Crawler extends Http_Client {
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
    protected function discover_html_urls(int $job_id, object $item, string $html, array $settings, array $allowed_hosts): void {
        if ($html === '') return;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
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
                $url = $this->absolute_url($item->url, $node->nodeValue);
                if ($url) {
                    $this->queue_url($job_id, $url, (int) $item->depth + 1, $type, (int) $item->id, $settings, $allowed_hosts, true);
                }
            }
        }

        if (!empty($settings['visit_images'])) {
            foreach ($xpath->query('//img[@srcset]/@srcset') ?: [] as $node) {
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
