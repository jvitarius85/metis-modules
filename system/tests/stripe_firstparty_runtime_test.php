<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/Metis/Core/Services/HttpClient.php';
require_once dirname( __DIR__ ) . '/src/Metis/Core/Integrations/StripeApiException.php';
require_once dirname( __DIR__ ) . '/src/Metis/Core/Integrations/StripeApiClient.php';
require_once dirname( __DIR__ ) . '/src/Metis/Core/Integrations/StripeWebhookVerifier.php';

function assert_true( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function assert_same( mixed $expected, mixed $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . ' Expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) );
    }
}

$captured = [];
$http = new \Metis\Core\Services\HttpClient(
    static function ( string $method, string $url, array $headers, string $body ) use ( &$captured ): array {
        $captured = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return [
            'status' => 200,
            'body' => '{"id":"pi_test","object":"payment_intent","charges":{"data":[]}}',
            'json' => [
                'id' => 'pi_test',
                'object' => 'payment_intent',
                'charges' => [ 'data' => [] ],
            ],
            'headers' => [],
        ];
    }
);

$client = new \Metis\Core\Integrations\StripeApiClient( 'sk_test_demo', $http );
$intent = $client->createPaymentIntent(
    [
        'amount' => 5000,
        'currency' => 'usd',
        'payment_method_types' => [ 'card' ],
        'metadata' => [ 'donor' => 'D123' ],
        'expand' => [ 'latest_charge.balance_transaction' ],
    ],
    [
        'idempotency_key' => 'idem_123',
    ]
);

assert_same( 'pi_test', $intent->id ?? null, 'Stripe API client should normalize response objects.' );
assert_same( 'POST', $captured['method'] ?? null, 'Stripe API client should use POST for payment intent creation.' );
assert_same( 'https://api.stripe.com/v1/payment_intents', $captured['url'] ?? null, 'Stripe API client should target the payment intents endpoint.' );
assert_same( 'Bearer sk_test_demo', $captured['headers']['Authorization'] ?? null, 'Stripe API client should send the active secret key.' );
assert_same( 'idem_123', $captured['headers']['Idempotency-Key'] ?? null, 'Stripe API client should propagate idempotency headers.' );
assert_same( 'application/x-www-form-urlencoded', $captured['headers']['Content-Type'] ?? null, 'Stripe API client should use Stripe-compatible form encoding.' );
assert_same(
    'amount=5000&currency=usd&payment_method_types%5B%5D=card&metadata%5Bdonor%5D=D123&expand%5B%5D=latest_charge.balance_transaction',
    $captured['body'] ?? null,
    'Stripe API client should flatten nested parameters using Stripe form conventions.'
);

$payload = '{"id":"evt_test","type":"charge.succeeded"}';
$secret = 'whsec_test';
$timestamp = time();
$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
$verifier = new \Metis\Core\Integrations\StripeWebhookVerifier();
$event = $verifier->verify( $payload, 't=' . $timestamp . ',v1=' . $signature, $secret );

assert_same( 'evt_test', $event['id'] ?? null, 'Stripe webhook verifier should return decoded payload arrays.' );

$threw = false;
try {
    $verifier->verify( $payload, 't=' . $timestamp . ',v1=invalid', $secret );
} catch ( RuntimeException $e ) {
    $threw = true;
    assert_true( str_contains( strtolower( $e->getMessage() ), 'signature' ), 'Invalid Stripe signatures should report signature failures.' );
}

assert_true( $threw, 'Stripe webhook verifier should reject invalid signatures.' );

fwrite( STDOUT, "First-party Stripe runtime checks passed.\n" );
