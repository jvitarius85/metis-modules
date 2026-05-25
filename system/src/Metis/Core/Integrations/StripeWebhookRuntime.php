<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

if ( ! function_exists( 'metis_stripe_webhook_env_bool' ) ) {
    function metis_stripe_webhook_env_bool( string $name, bool $default ): bool {
        $raw = getenv( $name );
        if ( $raw === false ) {
            return $default;
        }

        $parsed = filter_var( trim( (string) $raw ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        return $parsed === null ? $default : $parsed;
    }
}

if ( ! function_exists( 'metis_stripe_webhook_env_list' ) ) {
    function metis_stripe_webhook_env_list( string $name ): array {
        $raw = getenv( $name );
        if ( $raw === false ) {
            return [];
        }

        $items = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
        return array_values( array_unique( array_map( 'strval', $items ) ) );
    }
}

if ( ! function_exists( 'metis_stripe_webhook_required_header_values' ) ) {
    function metis_stripe_webhook_required_header_values( string $name ): array {
        $raw = getenv( $name );
        if ( $raw === false ) {
            return [];
        }

        $decoded = json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $headers = [];
        foreach ( $decoded as $header => $value ) {
            $header = metis_key_clean( (string) $header );
            $value = is_scalar( $value ) ? trim( (string) $value ) : '';
            if ( $header === '' || $value === '' ) {
                continue;
            }
            $headers[ $header ] = $value;
        }

        return $headers;
    }
}

if ( ! function_exists( 'metis_stripe_webhook_provider_policy' ) ) {
    function metis_stripe_webhook_provider_policy(): array {
        if ( ! metis_stripe_webhook_env_bool( 'METIS_STRIPE_WEBHOOK_STRICT_POLICY', false ) ) {
            return [];
        }

        $required_headers = [];
        if ( metis_stripe_webhook_env_bool( 'METIS_STRIPE_WEBHOOK_REQUIRE_SIGNATURE_HEADER', true ) ) {
            $required_headers[] = 'stripe-signature';
        }

        foreach ( metis_stripe_webhook_env_list( 'METIS_STRIPE_WEBHOOK_REQUIRED_HEADERS' ) as $header ) {
            $key = metis_key_clean( (string) $header );
            if ( $key !== '' && ! in_array( $key, $required_headers, true ) ) {
                $required_headers[] = $key;
            }
        }

        $required_header_values = metis_stripe_webhook_required_header_values( 'METIS_STRIPE_WEBHOOK_REQUIRED_HEADER_VALUES_JSON' );
        foreach ( $required_header_values as $header => $value ) {
            $required_headers[ $header ] = $value;
        }

        return [
            'allow_ips'        => metis_stripe_webhook_env_list( 'METIS_STRIPE_WEBHOOK_ALLOW_IPS' ),
            'allow_cidrs'      => metis_stripe_webhook_env_list( 'METIS_STRIPE_WEBHOOK_ALLOW_CIDRS' ),
            'required_headers' => $required_headers,
        ];
    }
}

metis_webhook_register_provider( 'stripe', array_merge(
    [
        'verify'  => 'metis_stripe_webhook_verify_request',
        'process' => 'metis_stripe_webhook_process_event',
    ],
    metis_stripe_webhook_provider_policy()
) );

if ( ! function_exists( 'metis_stripe_webhook_verify_request' ) ) {
    function metis_stripe_webhook_verify_request( Metis_Http_Request $request ): array {
        $payload   = $request->body();
        $signature = $request->header( 'stripe-signature' );
        $secret    = \function_exists( 'metis_stripe_webhook_secret' ) ? metis_stripe_webhook_secret() : (string) \Metis\Core\Services\CredentialService::getBySetting( 'stripe_webhook_secret' );

        if ( $request->method() !== 'POST' ) {
            throw new Metis_Webhook_Exception( 'Webhook method not allowed.', 405, 'webhook_method_invalid' );
        }

        if ( $payload === '' ) {
            throw new Metis_Webhook_Exception( 'Webhook body is empty.', 400, 'webhook_payload_invalid' );
        }

        if ( $secret === '' ) {
            throw new Metis_Webhook_Exception( 'Stripe webhook secret is not configured.', 503, 'webhook_secret_missing' );
        }

        if ( $signature === '' ) {
            throw new Metis_Webhook_Exception( 'Stripe signature header is missing.', 401, 'webhook_signature_missing' );
        }

        try {
            $verifier = new \Metis\Core\Integrations\StripeWebhookVerifier();
            $event_array = $verifier->verify( $payload, $signature, $secret );
        } catch ( Throwable $e ) {
            $status = str_contains( strtolower( $e->getMessage() ), 'signature' ) ? 401 : 400;
            $code = $status === 401 ? 'webhook_signature_invalid' : 'webhook_payload_invalid';
            throw new Metis_Webhook_Exception( $status === 401 ? 'Stripe signature verification failed.' : 'Stripe payload is invalid.', $status, $code, [
                'provider_error_class' => get_class( $e ),
            ] );
        }
        if ( ! is_array( $event_array ) ) {
            throw new Metis_Webhook_Exception( 'Stripe payload could not be normalized.', 400, 'webhook_payload_invalid' );
        }

        return [
            'event_id'    => (string) ( $event_array['id'] ?? '' ),
            'event_type'  => (string) ( $event_array['type'] ?? '' ),
            'resource_id' => metis_text_clean( (string) ( $event_array['data']['object']['id'] ?? '' ) ),
            'payload'     => $event_array,
        ];
    }
}

if ( ! function_exists( 'metis_stripe_webhook_process_event' ) ) {
    function metis_stripe_webhook_process_event( array $event, ?Metis_Http_Request $request = null ): array {
        return [
            'provider'   => 'stripe',
            'event_type' => (string) ( $event['event_type'] ?? '' ),
        ];
    }
}
