<?php
namespace YotaX\WarmPilot;

use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;
use Throwable;

defined('ABSPATH') || exit;

class Http_Client extends Url_Normalizer {
    protected function multi_request_with_retries(array $items, array $headers, array $settings, bool $capture_body = true): array {
        $remaining = array_values($items);
        $final = [];
        $max_attempts = 1 + (int)($settings['retry_count'] ?? 0);
        for ($attempt = 1; $attempt <= $max_attempts && $remaining; $attempt++) {
            $batch = $this->multi_request($remaining, $headers, $settings, $capture_body);
            $retry = [];
            foreach ($remaining as $item) {
                $result = $batch[$item->id] ?? ['code'=>0,'time'=>0,'headers'=>[],'body'=>'','error'=>'No HTTP result.'];
                $result['attempts'] = $attempt;
                $final[$item->id] = $result;
                if ($attempt < $max_attempts && $this->should_retry_result($result)) $retry[] = $item;
            }
            $remaining = $retry;
            if ($remaining && !empty($settings['retry_delay_seconds'])) {
                usleep((int) round((float) $settings['retry_delay_seconds'] * 1000000));
            }
        }
        return $final;
    }
    protected function should_retry_result(array $result): bool {
        $code = (int)($result['code'] ?? 0);
        return !empty($result['error']) || $code === 0 || $code === 408 || $code === 429 || $code >= 500;
    }
    protected function multi_request(array $items, array $headers, array $settings, bool $capture_body = true): array {
        $requests = [];
        foreach ($items as $item) {
            $requests[(int) $item->id] = [
                'url' => $item->url,
                'headers' => $headers,
                'type' => Requests::GET,
            ];
        }

        $started = microtime(true);
        $completion_times = [];
        try {
            $responses = Requests::request_multiple($requests, [
                'timeout' => (int) $settings['timeout'],
                'connect_timeout' => min(15, (int) $settings['timeout']),
                'verify' => !empty($settings['ssl_verify']),
                'redirects' => 5,
                'follow_redirects' => true,
                'complete' => static function ($response, $id) use (&$completion_times, $started): void {
                    $completion_times[(int) $id] = round(microtime(true) - $started, 4);
                },
            ]);
        } catch (Throwable $exception) {
            $responses = [];
            foreach ($items as $item) {
                $responses[(int) $item->id] = $exception;
            }
        }

        $results = [];
        foreach ($items as $item) {
            $id = (int) $item->id;
            $response = $responses[$id] ?? null;
            if ($response instanceof Response) {
                $results[$id] = [
                    'code' => (int) $response->status_code,
                    'time' => $completion_times[$id] ?? round(microtime(true) - $started, 4),
                    'headers' => $this->normalize_response_headers($response->headers->getAll()),
                    'body' => $capture_body ? (string) $response->body : '',
                    'error' => '',
                ];
                continue;
            }

            $results[$id] = [
                'code' => 0,
                'time' => $completion_times[$id] ?? round(microtime(true) - $started, 4),
                'headers' => [],
                'body' => '',
                'error' => $response instanceof Throwable ? $response->getMessage() : 'No HTTP response.',
            ];
        }

        return $results;
    }
    protected function normalize_response_headers(array $headers): array {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower((string) $name)] = array_map('strval', (array) $values);
        }
        return $normalized;
    }
    protected function parse_headers(string $text): array {
        $headers = [];
        foreach ($this->lines($text) as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            if ($name !== '') $headers[$name] = $value;
        }
        return $headers;
    }
    protected function header_value(array $headers, string $name): string {
        $value = $headers[strtolower($name)] ?? [];
        if (is_array($value)) {
            return (string) end($value);
        }
        return (string) $value;
    }
    protected function cache_headers_text(array $headers): string {
        $lines = [];
        foreach ($headers as $name => $values) {
            if (stripos((string) $name, 'cache') === false) {
                continue;
            }
            foreach ((array) $values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }
        return implode("\n", $lines);
    }
}
