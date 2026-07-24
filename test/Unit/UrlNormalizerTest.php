<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WarmPilot\Tests\Support\ExposesProtectedMethods;
use YotaX\WarmPilot\Url_Normalizer;

final class UrlHarness extends Url_Normalizer {
    use ExposesProtectedMethods;
    public function __construct() {}
}

final class UrlNormalizerTest extends TestCase {
    private UrlHarness $urls;

    protected function setUp(): void {
        $this->urls = new UrlHarness();
    }

    public function testWildcardMatchingIsAnchoredAndCaseInsensitive(): void {
        self::assertTrue($this->urls->call('wildcard_match', 'https://example.com/products/*', 'https://EXAMPLE.com/products/one'));
        self::assertFalse($this->urls->call('wildcard_match', '*/products/*', 'https://example.com/blog/one'));
    }

    public function testLinesTrimAndRemoveEmptyEntries(): void {
        self::assertSame(['one', 'two'], $this->urls->call('lines', " one \r\n\n two "));
    }

    public function testNormalizeUrlAcceptsOnlyAbsoluteHttpUrls(): void {
        self::assertSame('https://example.com/a?b=1', $this->urls->call('normalize_url', ' HTTPS://Example.COM/a?b=1#fragment '));
        self::assertNull($this->urls->call('normalize_url', '/relative'));
        self::assertNull($this->urls->call('normalize_url', 'javascript:alert(1)'));
    }

    /**
     * @dataProvider absoluteUrlProvider
     */
    public function testAbsoluteUrlResolution(string $base, string $relative, ?string $expected): void {
        self::assertSame($expected, $this->urls->call('absolute_url', $base, $relative));
    }

    public function absoluteUrlProvider(): array {
        return [
            'root path' => ['https://example.com/a/page', '/shop', 'https://example.com/shop'],
            'parent segment' => ['https://example.com/a/b/page', '../shop', 'https://example.com/a/shop'],
            'protocol relative' => ['https://example.com/a', '//cdn.example.com/x', 'https://cdn.example.com/x'],
            'fragment' => ['https://example.com/a', '#section', null],
            'email' => ['https://example.com/a', 'mailto:test@example.com', null],
        ];
    }

    public function testCanonicalKeyDeduplicatesDefaultPortsDotSegmentsAndQueryOrder(): void {
        $one = $this->urls->call('canonical_url_key', 'https://example.com:443/a/../shop/?b=2&a=1');
        $two = $this->urls->call('canonical_url_key', 'https://example.com/shop/?a=1&b=2');
        self::assertSame($two, $one);
    }

    public function testCanonicalKeyKeepsDifferentSubdomainsDistinct(): void {
        $apex = $this->urls->call('canonical_url_key', 'https://example.com/shop/');
        $www = $this->urls->call('canonical_url_key', 'https://www.example.com/shop/');
        self::assertNotSame($apex, $www);
    }

    public function testAllowedHostsIncludeConfiguredSeedsAndHomeDomain(): void {
        $hosts = $this->urls->call('allowed_hosts', [
            'start_urls' => "https://blog.example.com/\nhttps://other.test/",
            'sitemap_urls' => 'https://maps.test/sitemap.xml',
        ]);
        self::assertSame(['blog.example.com', 'other.test', 'maps.test', 'example.com'], $hosts);
    }

    public function testAllowedRootDerivedFromAnyHomeSubdomainMatchesWholeDomainFamily(): void {
        self::assertTrue($this->urls->call('host_matches_allowed_roots', 'example.com', ['shop.example.com']));
        self::assertTrue($this->urls->call('host_matches_allowed_roots', 'www.example.com', ['shop.example.com']));
        self::assertTrue($this->urls->call('host_matches_allowed_roots', 'cdn.example.com', ['shop.example.com']));
        self::assertTrue($this->urls->call('host_matches_allowed_roots', 'example.com', ['example.com']));
        self::assertFalse($this->urls->call('host_matches_allowed_roots', 'example.com.evil.test', ['example.com']));
        self::assertFalse($this->urls->call('host_matches_allowed_roots', 'notexample.com', ['example.com']));
    }

    public function testDomainRootUsesPublicSuffixListForCountryCodeSuffix(): void {
        self::assertSame('example.co.uk', $this->urls->call('domain_root', 'shop.example.co.uk'));
        self::assertTrue($this->urls->call('host_matches_allowed_roots', 'cdn.example.co.uk', ['shop.example.co.uk']));
        self::assertFalse($this->urls->call('host_matches_allowed_roots', 'example.co.uk.evil.test', ['shop.example.co.uk']));
    }

    public function testDomainRootRespectsPrivatePublicSuffixes(): void {
        self::assertSame('tenant.github.io', $this->urls->call('domain_root', 'shop.tenant.github.io'));
        self::assertTrue($this->urls->call('host_matches_allowed_roots', 'cdn.tenant.github.io', ['shop.tenant.github.io']));
        self::assertFalse($this->urls->call('host_matches_allowed_roots', 'other.github.io', ['shop.tenant.github.io']));
    }

    public function testAllowedPatternAppliesApexRuleToSubdomain(): void {
        self::assertTrue($this->urls->call(
            'allowed_pattern_match',
            'https://example.com/products/*',
            'https://shop.example.com/products/one',
            ['example.com']
        ));
        self::assertFalse($this->urls->call(
            'allowed_pattern_match',
            'https://example.com/products/*',
            'https://shop.example.com/blog/one',
            ['example.com']
        ));
    }
}
