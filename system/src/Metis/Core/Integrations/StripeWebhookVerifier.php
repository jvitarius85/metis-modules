<?php
declare(strict_types=1);

namespace Metis\Core\Integrations;

final class StripeWebhookVerifier {
    public function verify(string $payload, string $signatureHeader, string $secret, int $tolerance = 300): array {
        if ($payload === '') {
            throw new \RuntimeException('Stripe payload is empty.');
        }
        if ($signatureHeader === '' || $secret === '') {
            throw new \RuntimeException('Stripe signature verification is not configured.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
                continue;
            }
            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new \RuntimeException('Stripe signature header is malformed.');
        }

        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            throw new \RuntimeException('Stripe signature timestamp is outside the allowed tolerance.');
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            throw new \RuntimeException('Stripe signature verification failed.');
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Stripe payload could not be decoded.');
        }

        return $decoded;
    }
}
