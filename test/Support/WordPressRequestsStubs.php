<?php
declare(strict_types=1);

namespace WpOrg\Requests;

final class TestHeaders {
    public function __construct(private array $headers = []) {}
    public function getAll(): array {
        return $this->headers;
    }
}

class Response {
    public int|false $status_code = false;
    public string $body = '';
    public TestHeaders $headers;

    public function __construct(int $status = 200, string $body = '', array $headers = []) {
        $this->status_code = $status;
        $this->body = $body;
        $this->headers = new TestHeaders($headers);
    }
}

final class Requests {
    public const GET = 'GET';
    public static array $responses = [];
    public static array $lastRequests = [];
    public static array $lastOptions = [];

    public static function request_multiple(array $requests, array $options = []): array {
        self::$lastRequests = $requests;
        self::$lastOptions = $options;
        foreach (self::$responses as $id => $response) {
            if (isset($options['complete'])) {
                $options['complete']($response, $id);
            }
        }
        return self::$responses;
    }
}
