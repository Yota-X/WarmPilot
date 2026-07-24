<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WarmPilot\Tests\Support\ExposesProtectedMethods;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;
use YotaX\WarmPilot\Http_Client;

class HttpHarness extends Http_Client {
    use ExposesProtectedMethods;
    public array $batches = [];
    public int $calls = 0;
    public function __construct() {}
    protected function multi_request(array $items, array $headers, array $settings, bool $capture_body = true): array {
        return $this->batches[$this->calls++] ?? [];
    }
}

final class RealHttpHarness extends Http_Client {
    use ExposesProtectedMethods;
    public function __construct() {}
}

final class HttpClientTest extends TestCase {
    private HttpHarness $http;

    protected function setUp(): void {
        $this->http = new HttpHarness();
        Requests::$responses = [];
        Requests::$lastRequests = [];
        Requests::$lastOptions = [];
    }

    public function testHeaderParsingIgnoresMalformedLinesAndUsesLastDuplicate(): void {
        $headers = $this->http->call('parse_headers', "Accept: text/html\nMalformed\nX-Test: one\nX-Test: two");
        self::assertSame(['Accept' => 'text/html', 'X-Test' => 'two'], $headers);
    }

    public function testResponseHeadersAreNormalizedForReporting(): void {
        self::assertSame([
            'cache-control' => ['public', 'max-age=60'],
            'content-type' => ['text/html'],
        ], $this->http->call('normalize_response_headers', [
            'Cache-Control' => ['public', 'max-age=60'],
            'Content-Type' => 'text/html',
        ]));
    }

    public function testHeaderValueUsesLastResponseValueCaseInsensitively(): void {
        self::assertSame('HIT', $this->http->call('header_value', ['cf-cache-status' => ['MISS', 'HIT']], 'CF-Cache-Status'));
        self::assertSame('', $this->http->call('header_value', [], 'missing'));
    }

    public function testOnlyHeadersContainingCacheAreReported(): void {
        $result = $this->http->call('cache_headers_text', [
            'content-type' => ['text/html'],
            'cache-control' => ['public'],
            'cf-cache-status' => ['HIT'],
        ]);
        self::assertSame("cache-control: public\ncf-cache-status: HIT", $result);
    }

    /**
     * @dataProvider retryProvider
     */
    public function testRetryPolicy(array $result, bool $expected): void {
        self::assertSame($expected, $this->http->call('should_retry_result', $result));
    }

    public function retryProvider(): array {
        return [
            'network error' => [['code' => 200, 'error' => 'timeout'], true],
            'no response' => [['code' => 0, 'error' => ''], true],
            'timeout' => [['code' => 408, 'error' => ''], true],
            'rate limit' => [['code' => 429, 'error' => ''], true],
            'server error' => [['code' => 503, 'error' => ''], true],
            'client error' => [['code' => 404, 'error' => ''], false],
            'success' => [['code' => 200, 'error' => ''], false],
        ];
    }

    public function testRetryOnlyRepeatsFailedItemsAndTracksAttempts(): void {
        $a = (object) ['id' => 1];
        $b = (object) ['id' => 2];
        $this->http->batches = [
            [
                1 => ['code' => 503, 'error' => '', 'time' => 1, 'headers' => [], 'body' => ''],
                2 => ['code' => 200, 'error' => '', 'time' => 1, 'headers' => [], 'body' => ''],
            ],
            [
                1 => ['code' => 200, 'error' => '', 'time' => 1, 'headers' => [], 'body' => ''],
            ],
        ];
        $result = $this->http->call('multi_request_with_retries', [$a, $b], [], [
            'retry_count' => 2,
            'retry_delay_seconds' => 0,
        ]);
        self::assertSame(2, $this->http->calls);
        self::assertSame(2, $result[1]['attempts']);
        self::assertSame(1, $result[2]['attempts']);
        self::assertSame(200, $result[1]['code']);
    }

    public function testParallelRequestsUseWordPressBundledRequestsApi(): void {
        $http = new RealHttpHarness();
        Requests::$responses = [
            7 => new Response(200, '<html>ok</html>', [
                'Cache-Control' => ['public'],
                'Content-Type' => ['text/html'],
            ]),
        ];

        $result = $http->call('multi_request', [
            (object) ['id' => 7, 'url' => 'https://example.com/page'],
        ], ['User-Agent' => 'WarmPilot/Test'], [
            'timeout' => 20,
            'ssl_verify' => 1,
        ]);

        self::assertSame('https://example.com/page', Requests::$lastRequests[7]['url']);
        self::assertSame(Requests::GET, Requests::$lastRequests[7]['type']);
        self::assertSame(20, Requests::$lastOptions['timeout']);
        self::assertTrue(Requests::$lastOptions['verify']);
        self::assertSame(200, $result[7]['code']);
        self::assertSame('<html>ok</html>', $result[7]['body']);
        self::assertSame(['public'], $result[7]['headers']['cache-control']);
        self::assertSame('', $result[7]['error']);
    }

    public function testParallelRequestExceptionsBecomeRetryableResults(): void {
        $http = new RealHttpHarness();
        Requests::$responses = [9 => new \RuntimeException('Timed out')];

        $result = $http->call('multi_request', [
            (object) ['id' => 9, 'url' => 'https://example.com/slow'],
        ], [], [
            'timeout' => 1,
            'ssl_verify' => 1,
        ]);

        self::assertSame(0, $result[9]['code']);
        self::assertSame('Timed out', $result[9]['error']);
        self::assertTrue($http->call('should_retry_result', $result[9]));
    }
}
