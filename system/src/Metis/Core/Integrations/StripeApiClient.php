<?php
declare(strict_types=1);

namespace Metis\Core\Integrations;

use Metis\Core\Services\HttpClient;

final class StripeApiClient {
    private const BASE_URL = 'https://api.stripe.com/v1';
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly string $secretKey,
        private readonly HttpClient $http,
        private readonly ?string $apiVersion = null
    ) {}

    public function isConfigured(): bool {
        return $this->secretKey !== '' && str_starts_with($this->secretKey, 'sk_');
    }

    public function createCustomer(array $payload, array $options = []): object {
        return $this->post('/customers', $payload, $options);
    }

    public function retrieveCustomer(string $customerId, array $params = [], array $options = []): object {
        return $this->get('/customers/' . rawurlencode($customerId), $params, $options);
    }

    public function createPaymentIntent(array $payload, array $options = []): object {
        return $this->post('/payment_intents', $payload, $options);
    }

    public function retrievePaymentIntent(string $paymentIntentId, array $params = [], array $options = []): object {
        return $this->get('/payment_intents/' . rawurlencode($paymentIntentId), $params, $options);
    }

    public function listPaymentIntents(array $params = [], array $options = []): object {
        return $this->get('/payment_intents', $params, $options);
    }

    public function createRefund(array $payload, array $options = []): object {
        return $this->post('/refunds', $payload, $options);
    }

    public function retrieveCharge(string $chargeId, array $params = [], array $options = []): object {
        return $this->get('/charges/' . rawurlencode($chargeId), $params, $options);
    }

    public function retrieveBalanceTransaction(string $balanceTransactionId, array $params = [], array $options = []): object {
        return $this->get('/balance_transactions/' . rawurlencode($balanceTransactionId), $params, $options);
    }

    public function listBalanceTransactions(array $params = [], array $options = []): object {
        return $this->get('/balance_transactions', $params, $options);
    }

    public function listPayouts(array $params = [], array $options = []): object {
        return $this->get('/payouts', $params, $options);
    }

    public function retrievePayout(string $payoutId, array $params = [], array $options = []): object {
        return $this->get('/payouts/' . rawurlencode($payoutId), $params, $options);
    }

    public function listSubscriptions(array $params = [], array $options = []): object {
        return $this->get('/subscriptions', $params, $options);
    }

    public function retrieveSubscription(string $subscriptionId, array $params = [], array $options = []): object {
        return $this->get('/subscriptions/' . rawurlencode($subscriptionId), $params, $options);
    }

    public function cancelSubscription(string $subscriptionId, array $params = [], array $options = []): object {
        return $this->delete('/subscriptions/' . rawurlencode($subscriptionId), $params, $options);
    }

    public function retrieveProduct(string $productId, array $params = [], array $options = []): object {
        return $this->get('/products/' . rawurlencode($productId), $params, $options);
    }

    public function get(string $path, array $params = [], array $options = []): object {
        return $this->request('GET', $path, $params, $options);
    }

    public function post(string $path, array $params = [], array $options = []): object {
        return $this->request('POST', $path, $params, $options);
    }

    public function delete(string $path, array $params = [], array $options = []): object {
        return $this->request('DELETE', $path, $params, $options);
    }

    private function request(string $method, string $path, array $params = [], array $options = []): object {
        if (!$this->isConfigured()) {
            throw new StripeApiException('Stripe secret key is not configured.');
        }

        $method = strtoupper($method);
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
        ];
        if ($this->apiVersion !== null && $this->apiVersion !== '') {
            $headers['Stripe-Version'] = $this->apiVersion;
        }
        if (!empty($options['idempotency_key'])) {
            $headers['Idempotency-Key'] = (string) $options['idempotency_key'];
        }

        $url = self::BASE_URL . $path;
        $body = '';
        if ($params !== []) {
            $encoded = $this->encodeParameters($params);
            if ($method === 'GET' || $method === 'DELETE') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $encoded;
            } else {
                $body = $encoded;
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        }

        $attempt = 0;
        $lastException = null;
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            try {
                $response = $this->http->request($method, $url, $headers, $body, [
                    'timeout' => (int) ($options['timeout'] ?? 30),
                    'connect_timeout' => (int) ($options['connect_timeout'] ?? 10),
                    'capture_headers' => true,
                ]);
            } catch (\Throwable $e) {
                if (\function_exists('metis_stripe_record_api_error')) {
                    \metis_stripe_record_api_error($e, $method, $path);
                }
                $lastException = new StripeApiException(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Stripe request failed.',
                    0,
                    'connection_error',
                    null,
                    null,
                    [],
                    true,
                    $e
                );
                if ($attempt < self::MAX_RETRIES) {
                    $this->backoff($attempt);
                    continue;
                }
                throw $lastException;
            }

            $status = (int) ($response['status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                if (\function_exists('metis_stripe_record_api_success')) {
                    \metis_stripe_record_api_success($method, $path);
                }
                $json = is_array($response['json'] ?? null) ? $response['json'] : [];
                return $this->normalizeResource($json);
            }

            $exception = $this->exceptionFromResponse($status, is_array($response['json'] ?? null) ? $response['json'] : [], (array) ($response['headers'] ?? []));
            if (\function_exists('metis_stripe_record_api_error')) {
                \metis_stripe_record_api_error($exception, $method, $path);
            }
            if ($exception->isRetryable() && $attempt < self::MAX_RETRIES) {
                $this->backoff($attempt);
                $lastException = $exception;
                continue;
            }
            throw $exception;
        }

        throw $lastException instanceof StripeApiException ? $lastException : new StripeApiException('Stripe request failed.');
    }

    private function exceptionFromResponse(int $status, array $json, array $headers): StripeApiException {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $message = trim((string) ($error['message'] ?? 'Stripe request failed.'));
        $type = ($error['type'] ?? null);
        $type = is_string($type) && $type !== '' ? $type : null;
        $code = ($error['code'] ?? null);
        $code = is_string($code) && $code !== '' ? $code : null;
        $requestId = $this->headerValue($headers, 'request-id');
        $retryable = $status === 409 || $status === 429 || $status >= 500 || $type === 'api_connection_error' || $type === 'api_error';

        return new StripeApiException(
            $message,
            $status,
            $type,
            $code,
            $requestId,
            $json,
            $retryable
        );
    }

    private function headerValue(array $headers, string $name): ?string {
        $value = $headers[strtolower($name)] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : null;
    }

    private function encodeParameters(array $params): string {
        $pairs = [];
        foreach ($params as $key => $value) {
            $this->flattenParameters($pairs, (string) $key, $value);
        }
        return implode('&', array_map(
            static fn (array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
            $pairs
        ));
    }

    private function flattenParameters(array &$pairs, string $prefix, mixed $value): void {
        if ($value === null || $prefix === '') {
            return;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $key => $nested) {
                $next = $isList ? $prefix . '[]' : $prefix . '[' . (string) $key . ']';
                $this->flattenParameters($pairs, $next, $nested);
            }
            return;
        }
        if (is_bool($value)) {
            $pairs[] = [$prefix, $value ? 'true' : 'false'];
            return;
        }
        $pairs[] = [$prefix, (string) $value];
    }

    private function normalizeResource(mixed $value): object {
        if (is_object($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return (object) ['value' => $value];
        }
        return json_decode((string) json_encode($this->normalizeNested($value), JSON_UNESCAPED_SLASHES), false, 512, JSON_THROW_ON_ERROR);
    }

    private function normalizeNested(mixed $value): mixed {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeNested($item), $value);
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalizeNested($item);
        }
        return $normalized;
    }

    private function backoff(int $attempt): void {
        $delayMs = match ($attempt) {
            1 => 150,
            2 => 350,
            default => 750,
        };
        usleep($delayMs * 1000);
    }
}
