<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

metis_webhook_register_provider( 'stripe', [
    'verify'  => 'metis_stripe_webhook_verify_request',
    'process' => 'metis_stripe_webhook_process_event',
] );

if ( ! function_exists( 'metis_stripe_webhook_verify_request' ) ) {
    function metis_stripe_webhook_verify_request( Metis_Http_Request $request ): array {
        $payload   = $request->body();
        $signature = $request->header( 'stripe-signature' );
        $secret    = (string) Core_Settings_Service::get( 'stripe_webhook_secret', '' );

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
            $event = \Stripe\Webhook::constructEvent( $payload, $signature, $secret );
        } catch ( \Stripe\Exception\SignatureVerificationException $e ) {
            throw new Metis_Webhook_Exception( 'Stripe signature verification failed.', 401, 'webhook_signature_invalid', [
                'provider_error' => $e->getMessage(),
            ] );
        } catch ( Throwable $e ) {
            throw new Metis_Webhook_Exception( 'Stripe payload is invalid.', 400, 'webhook_payload_invalid', [
                'provider_error' => $e->getMessage(),
            ] );
        }

        $event_array = json_decode( metis_json_encode( $event ), true );
        if ( ! is_array( $event_array ) ) {
            throw new Metis_Webhook_Exception( 'Stripe payload could not be normalized.', 400, 'webhook_payload_invalid' );
        }

        return [
            'event_id'    => (string) ( $event_array['id'] ?? '' ),
            'event_type'  => (string) ( $event_array['type'] ?? '' ),
            'resource_id' => sanitize_text_field( (string) ( $event_array['data']['object']['id'] ?? '' ) ),
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
