<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Application;

final class HttpClient {
    /** @var null|\Closure(string,string,array<string,string>,string):array<string,mixed> */
    private ?\Closure $transport;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport instanceof \Closure ? $transport : ($transport !== null ? \Closure::fromCallable($transport) : null);
    }

    public function get(string $url, array $headers = [], array $options = []): array {
        return $this->request('GET', $url, $headers, '', $options);
    }

    public function postJson(string $url, array $payload, array $headers = [], array $options = []): array {
        $headers['Content-Type'] = 'application/json';
        return $this->request('POST', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}', $options);
    }

    public function request(string $method, string $url, array $headers = [], string $body = '', array $options = []): array {
        if ($this->transport instanceof \Closure) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $this->assertSupportedUrl($url);
        $service = $this->dependencyName($url);
        $circuit = Application::has_service('circuit_breaker') ? Application::service('circuit_breaker') : null;
        if ($circuit instanceof \Metis\Core\Error\CircuitBreaker && !$circuit->isCallPermitted($service)) {
            throw new \RuntimeException(sprintf('Circuit open for dependency [%s].', $service));
        }

        $normalizedHeaders = $headers + [
            'Accept' => 'application/json',
            'User-Agent' => 'Metis-Core-HttpClient/1.0',
        ];
        $timeout = max(1, (int) ($options['timeout'] ?? 30));
        $connectTimeout = max(1, (int) ($options['connect_timeout'] ?? min(10, $timeout)));
        $captureHeaders = (bool) ($options['capture_headers'] ?? false);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException(sprintf('Unable to initialize HTTP client for [%s].', $url));
            }

            $formatted = [];
            foreach ($normalizedHeaders as $key => $value) {
                $formatted[] = $key . ': ' . $value;
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_HTTPHEADER => $formatted,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTP') && \defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            if (\defined('CURLOPT_REDIR_PROTOCOLS') && \defined('CURLPROTO_HTTP') && \defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            if ($captureHeaders) {
                curl_setopt($ch, CURLOPT_HEADER, true);
            }

            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = $captureHeaders ? (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE) : 0;
            $error = curl_error($ch);
            if (\PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }

            if ($response === false) {
                if ($circuit instanceof \Metis\Core\Error\CircuitBreaker) {
                    $circuit->recordFailure($service);
                }
                throw new \RuntimeException($error !== '' ? $error : sprintf('HTTP request failed for [%s].', $url));
            }

            if ($circuit instanceof \Metis\Core\Error\CircuitBreaker) {
                $circuit->recordSuccess($service);
            }

            $responseBody = (string) $response;
            $responseHeaders = [];
            if ($captureHeaders && $headerSize > 0) {
                $responseHeaders = $this->parseHeaders(substr($responseBody, 0, $headerSize));
                $responseBody = (string) substr($responseBody, $headerSize);
            }

            return [
                'status' => $status,
                'body' => $responseBody,
                'json' => $this->decodeJson($responseBody),
                'headers' => $responseHeaders,
            ];
        }

        $contextHeaders = '';
        foreach ($normalizedHeaders as $key => $value) {
            $contextHeaders .= $key . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => $contextHeaders,
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => $timeout,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $meta = $http_response_header ?? [];
        $status = 0;
        if (isset($meta[0]) && preg_match('/\s(\d{3})\s/', (string) $meta[0], $matches)) {
            $status = (int) $matches[1];
        }

        if (!is_string($responseBody)) {
            if ($circuit instanceof \Metis\Core\Error\CircuitBreaker) {
                $circuit->recordFailure($service);
            }
            throw new \RuntimeException(sprintf('HTTP request failed for [%s].', $url));
        }

        if ($circuit instanceof \Metis\Core\Error\CircuitBreaker) {
            $circuit->recordSuccess($service);
        }

        return [
            'status' => $status,
            'body' => $responseBody,
            'json' => $this->decodeJson($responseBody),
            'headers' => $captureHeaders ? $this->parseHeaders(implode("\r\n", $meta)) : [],
        ];
    }

    private function dependencyName(string $url): string {
        $host = (string) parse_url($url, PHP_URL_HOST);
        return $host !== '' ? strtolower($host) : 'http';
    }

    private function assertSupportedUrl(string $url): void {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('HTTP request URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if ($scheme === '' || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('HTTP request URL must be a valid HTTP(S) URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('HTTP request URL must not include credentials.');
        }
    }

    private function decodeJson(string $body): array {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function parseHeaders(string $rawHeaders): array {
        $headers = [];
        foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $normalized = strtolower(trim($name));
            $trimmed = trim($value);
            if ($normalized === '') {
                continue;
            }
            if (isset($headers[$normalized])) {
                if (!is_array($headers[$normalized])) {
                    $headers[$normalized] = [$headers[$normalized]];
                }
                $headers[$normalized][] = $trimmed;
            } else {
                $headers[$normalized] = $trimmed;
            }
        }
        return $headers;
    }
}
