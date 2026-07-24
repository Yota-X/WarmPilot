<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WarmPilot\Tests\Support\ExposesProtectedMethods;
use YotaX\WarmPilot\Crawler;

final class CrawlerHarness extends Crawler {
    use ExposesProtectedMethods;
    public array $queued = [];
    public function __construct() {}
    protected function queue_url(int $job_id, string $url, int $depth, string $type, ?int $parent_id, array $settings, array $allowed_hosts, bool $bypass_include = false): string {
        $this->queued[] = compact('job_id', 'url', 'depth', 'type', 'parent_id', 'bypass_include');
        return 'queued';
    }
}

final class CrawlerTest extends TestCase {
    private CrawlerHarness $crawler;

    protected function setUp(): void {
        $this->crawler = new CrawlerHarness();
    }

    public function testSitemapDiscoversPagesAndNestedSitemaps(): void {
        $xml = '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://example.com/nested.xml</loc></sitemap><url><loc>https://example.com/page</loc></url></sitemapindex>';
        $this->crawler->call('discover_sitemap_urls', 7, (object) ['id' => 3, 'depth' => 1], $xml, [], ['example.com']);
        self::assertCount(2, $this->crawler->queued);
        self::assertSame('sitemap', $this->crawler->queued[0]['type']);
        self::assertSame('page', $this->crawler->queued[1]['type']);
        self::assertTrue($this->crawler->queued[0]['bypass_include']);
        self::assertSame(2, $this->crawler->queued[0]['depth']);
    }

    public function testInvalidSitemapIsIgnored(): void {
        $this->crawler->call('discover_sitemap_urls', 1, (object) ['id' => 1, 'depth' => 0], '<broken', [], []);
        self::assertSame([], $this->crawler->queued);
    }

    public function testHtmlDiscoversLinksAndEnabledAssets(): void {
        $html = '<html><head><link rel="stylesheet" href="/a.css"><link rel="preload" as="font" href="/font.woff2"></head><body>'
            . '<a href="/page">Page</a><script src="/app.js"></script><img src="/one.jpg" srcset="/two.jpg 2x, /three.jpg 3x"></body></html>';
        $settings = [
            'visit_scripts' => 1,
            'visit_styles' => 1,
            'visit_fonts' => 1,
            'visit_images' => 1,
        ];
        $this->crawler->call('discover_html_urls', 1, (object) [
            'id' => 10,
            'depth' => 0,
            'url' => 'https://example.com/base/index.html',
        ], $html, $settings, ['example.com']);
        self::assertSame(
            ['page', 'script', 'style', 'image', 'font', 'image', 'image'],
            array_column($this->crawler->queued, 'type')
        );
        self::assertSame('https://example.com/page', $this->crawler->queued[0]['url']);
    }

    public function testDisabledAssetsAreNotDiscovered(): void {
        $this->crawler->call('discover_html_urls', 1, (object) [
            'id' => 1,
            'depth' => 0,
            'url' => 'https://example.com/',
        ], '<a href="/page">x</a><img src="/image.jpg">', [
            'visit_scripts' => 0,
            'visit_styles' => 0,
            'visit_fonts' => 0,
            'visit_images' => 0,
        ], ['example.com']);
        self::assertCount(1, $this->crawler->queued);
        self::assertSame('page', $this->crawler->queued[0]['type']);
    }
}
