<?php
if ( ! defined( 'ABSPATH' ) ) exit;

Metis_Logger::info( 'Stripe webhook provider loaded' );

metis_webhook_register_provider( 'stripe', [
    'verify'  => 'metis_stripe_webhook_verify_request',
    'process' => 'metis_stripe_webhook_process_event',
] );

function metis_stripe_webhook_verify_request( Metis_Http_Request $request ): array {
    $payload = $request->body();
    $signature = $request->header( 'stripe-signature' );
    $secret = (string) Core_Settings_Service::get( 'stripe_webhook_secret', '' );

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

    $resource_id = sanitize_text_field( (string) ( $event_array['data']['object']['id'] ?? '' ) );

    return [
        'event_id'    => (string) ( $event_array['id'] ?? '' ),
        'event_type'  => (string) ( $event_array['type'] ?? '' ),
        'resource_id' => $resource_id,
        'payload'     => $event_array,
    ];
}

function metis_stripe_webhook_process_event( array $event, ?Metis_Http_Request $request = null ): array {
    $type = (string) $event['event_type'];
    $payload = (array) $event['payload'];
    $object = isset( $payload['data']['object'] ) && is_array( $payload['data']['object'] )
        ? (object) $payload['data']['object']
        : (object) [];

    switch ( $type ) {
        case 'payout.paid':
            metis_stripe_handle_payout_paid( $object );
            break;

        case 'payout.updated':
            metis_stripe_handle_payout_updated( $object );
            break;

        case 'payout.failed':
            metis_stripe_handle_payout_status( $object, 'failed' );
            break;

        case 'payout.canceled':
            metis_stripe_handle_payout_status( $object, 'canceled' );
            break;

        default:
            Metis_Logger::info( 'Stripe webhook: unhandled event type', [ 'type' => $type ] );
            break;
    }

    return [
        'provider'   => 'stripe',
        'event_type' => $type,
    ];
}

function metis_stripe_find_deposit_by_payout_id( string $stripe_payout_id ): ?array {
    global $wpdb;

    if ( $stripe_payout_id === '' ) {
        return null;
    }

    $deposits_table = Metis_Tables::get( 'deposits' );
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, provider_ref, status
             FROM {$deposits_table}
             WHERE provider = 'stripe' AND meta LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like( '"payout_id":"' . $stripe_payout_id . '"' ) . '%'
        ),
        ARRAY_A
    );

    return is_array( $row ) ? $row : null;
}

function metis_stripe_handle_payout_paid( object $payout ): void {

    global $wpdb;

    $stripe_payout_id = (string) ( $payout->id ?? '' );
    if ( ! $stripe_payout_id ) {
        throw new Metis_Webhook_Exception( 'Stripe payout is missing an ID.', 400, 'stripe_payout_missing_id' );
    }

    $deposits_table     = Metis_Tables::get( 'deposits' );
    $transactions_table = Metis_Tables::get( 'transactions' );

    $arrival_date = ! empty( $payout->arrival_date )
        ? gmdate( 'Y-m-d', (int) $payout->arrival_date )
        : current_time( 'Y-m-d' );

    $net_amount = (float) ( $payout->amount ?? 0 ) / 100.0;
    $currency   = strtolower( (string) ( $payout->currency ?? 'usd' ) );
    $existing   = metis_stripe_find_deposit_by_payout_id( $stripe_payout_id );

    $totals = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            SUM( amount + IFNULL(fee, 0) )  AS gross,
            SUM( IFNULL(fee, 0) )           AS fees,
            SUM( amount )                   AS net,
            COUNT(*)                        AS txn_count
         FROM {$transactions_table}
         WHERE stripe_payout_id = %s",
        $stripe_payout_id
    ) );

    $gross     = (float) ( $totals->gross     ?? $net_amount );
    $fees      = (float) ( $totals->fees      ?? 0 );
    $net       = (float) ( $totals->net       ?? $net_amount );
    $txn_count = (int)   ( $totals->txn_count ?? 0 );

    $meta = metis_json_encode( [
        'payout_id'    => $stripe_payout_id,
        'arrival_date' => $arrival_date,
        'source'       => 'webhook',
    ] );

    if ( $existing ) {
        $wpdb->update(
            $deposits_table,
            [
                'status'            => 'deposited',
                'deposit_date'      => $arrival_date,
                'total_amount'      => $net_amount,
                'gross_total'       => $gross,
                'fee_total'         => $fees,
                'net_total'         => $net,
                'transaction_count' => $txn_count,
                'meta'              => $meta,
                'updated_at'        => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $existing['id'] ]
        );

        $deposit_code = (string) $existing['provider_ref'];
        Metis_Logger::info( 'Stripe webhook: deposit updated', [ 'code' => $deposit_code, 'payout' => $stripe_payout_id ] );
    } else {
        $deposit_code = metis_generate_code( 'DP', $deposits_table, 'provider_ref' );

        $wpdb->insert(
            $deposits_table,
            [
                'provider'          => 'stripe',
                'provider_ref'      => $deposit_code,
                'deposit_type'      => 'stripe',
                'source'            => 'webhook',
                'status'            => 'deposited',
                'deposit_date'      => $arrival_date,
                'expected_date'     => $arrival_date,
                'total_amount'      => $net_amount,
                'gross_total'       => $gross,
                'fee_total'         => $fees,
                'net_total'         => $net,
                'currency'          => $currency,
                'batch_count'       => 1,
                'transaction_count' => $txn_count,
                'meta'              => $meta,
                'created_at'        => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' ),
            ]
        );

        Metis_Logger::info( 'Stripe webhook: deposit created', [ 'code' => $deposit_code, 'payout' => $stripe_payout_id ] );
    }

    if ( function_exists( 'finance_event_create' ) ) {
        finance_event_create( [
            'event_type'   => 'stripe_payout',
            'provider'     => 'stripe',
            'reference_id' => $stripe_payout_id,
            'amount'       => $net_amount,
            'currency'     => $currency,
            'notes'        => 'Stripe payout settled into bank account',
            'occurred_at'  => $arrival_date . ' 00:00:00',
            'metadata_json'=> [
                'deposit_code'       => $deposit_code,
                'deposit_status'     => (string) ( $payout->status ?? 'paid' ),
                'gross_total'        => $gross,
                'fee_total'          => $fees,
                'net_total'          => $net,
                'transaction_count'  => $txn_count,
                'source'             => 'stripe_webhook',
            ],
        ] );
    }

    $linked = metis_stripe_link_transactions( $stripe_payout_id, $deposit_code );
    Metis_Logger::info( 'Stripe webhook: transactions linked', [ 'count' => $linked, 'deposit' => $deposit_code ] );
}

function metis_stripe_handle_payout_updated( object $payout ): void {

    global $wpdb;

    $stripe_payout_id = (string) ( $payout->id ?? '' );
    if ( ! $stripe_payout_id ) {
        throw new Metis_Webhook_Exception( 'Stripe payout is missing an ID.', 400, 'stripe_payout_missing_id' );
    }

    $existing = metis_stripe_find_deposit_by_payout_id( $stripe_payout_id );

    if ( ! $existing ) {
        Metis_Logger::info( 'Stripe webhook: payout.updated with no matching deposit', [ 'payout' => $stripe_payout_id ] );
        return;
    }

    $wpdb->update(
        Metis_Tables::get( 'deposits' ),
        [ 'status' => (string) ( $payout->status ?? 'pending' ), 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => (int) $existing['id'] ]
    );

    Metis_Logger::info( 'Stripe webhook: deposit status updated', [ 'payout' => $stripe_payout_id, 'status' => $payout->status ?? 'pending' ] );
}

function metis_stripe_handle_payout_status( object $payout, string $status ): void {

    global $wpdb;

    $stripe_payout_id = (string) ( $payout->id ?? '' );
    if ( ! $stripe_payout_id ) {
        throw new Metis_Webhook_Exception( 'Stripe payout is missing an ID.', 400, 'stripe_payout_missing_id' );
    }

    $existing = metis_stripe_find_deposit_by_payout_id( $stripe_payout_id );
    if ( ! $existing ) {
        Metis_Logger::info( "Stripe webhook: {$status} with no matching deposit", [ 'payout' => $stripe_payout_id ] );
        return;
    }

    $wpdb->update(
        Metis_Tables::get( 'deposits' ),
        [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => (int) $existing['id'] ]
    );

    Metis_Logger::info( "Stripe webhook: deposit marked {$status}", [ 'payout' => $stripe_payout_id ] );
}
