<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Donations Donor Intelligence AJAX Handler
 *
 * Endpoint: metis_ajax_metis_donations_donor_intelligence
 *
 * Uses Core_Reports_Service for the SQL, then adds:
 *   - contact display names + emails (JOIN)
 *   - per-row segmentation (recurring / returning / one-time / lapsed)
 *   - segment summary counts
 */

Metis_Logger::info( 'Donations Donor Intelligence AJAX loaded' );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_donations_donor_intelligence', [
        'module' => 'donations',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_donor_intelligence' ),
    ] );
}

function metis_donations_donor_intelligence_ajax_verify(): void {
    $nonce = '';
    foreach ( [ 'metis_action_nonce', 'nonce' ] as $field ) {
        $value = metis_request_post()[ $field ] ?? '';
        if ( is_scalar( $value ) ) {
            $nonce = trim( (string) metis_runtime_unslash( $value ) );
            if ( $nonce !== '' ) {
                break;
            }
        }
    }

    $nonce_action = function_exists( 'metis_ajax_nonce_action' )
        ? metis_ajax_nonce_action( 'metis_donations_donor_intelligence' )
        : 'metis_donations_donor_intelligence';

    if ( $nonce === '' || ! function_exists( 'metis_runtime_verify_nonce' ) || ! metis_runtime_verify_nonce( $nonce, $nonce_action ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! function_exists( 'metis_donations_can' ) || ! metis_donations_can( 'view' ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

metis_ajax_register_handler( 'metis_donations_donor_intelligence', 'metis_ajax_donor_intelligence' );

// -------------------------------------------------------------------------
// Shared DI data builder — used by AJAX handler AND PDF export
// -------------------------------------------------------------------------

function metis_build_donor_intelligence_data( array $args ): array {
    $db = metis_db();

    $lifetime = ! empty( $args['lifetime'] );
    $start    = $args['start']    ?? '';
    $end      = $args['end']      ?? '';
    $platform = $args['platform'] ?? null;
    $status   = $args['status']   ?? null;

    $service = new Core_Reports_Service( Metis_Tables::get( 'transactions' ) );

    $result = $service->run_donor_intelligence( [
        'start'    => $lifetime ? null : ( $start ?: null ),
        'end'      => $lifetime ? null : ( $end   ?: null ),
        'platform' => $platform,
        'status'   => $status,
        'limit'    => 200,
        'lifetime' => $lifetime,
    ] );

    $rows = $result['rows'] ?? [];
    $kpis = $result['kpis'] ?? [];

    $contacts_table = Metis_Tables::get( 'contacts' );
    $now            = new DateTime();
    $segments       = [ 'recurring' => 0, 'returning' => 0, 'one-time' => 0, 'lapsed' => 0 ];

    $dids        = array_filter( array_column( $rows, 'did' ) );
    $contact_map = [];

    if ( ! empty( $dids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $dids ), '%s' ) );
        $contact_rows = $db->fetchAll(
            "SELECT did, first_name, last_name, email FROM {$contacts_table} WHERE did IN ({$placeholders})",
            $dids
        );
        foreach ( $contact_rows as $c ) {
            $contact_map[ $c['did'] ] = $c;
        }
    }

    foreach ( $rows as &$row ) {
        $did = $row['did'] ?? '';
        $c   = $contact_map[ $did ] ?? null;

        $full = $c ? trim( ( $c['first_name'] ?? '' ) . ' ' . ( $c['last_name'] ?? '' ) ) : '';
        $row['display_name'] = $full !== '' ? $full : $did;
        $row['email']        = $c['email'] ?? '';

        $row['gross']          = (float) ( $row['gross']          ?? 0 );
        $row['fee']            = (float) ( $row['fee']            ?? 0 );
        $row['net']            = (float) ( $row['net']            ?? 0 );
        $row['donation_count'] = (int)   ( $row['donation_count'] ?? 0 );
        $row['avg_gift']       = $row['donation_count'] > 0
            ? $row['gross'] / $row['donation_count'] : 0;

        $months_since = 999;
        if ( ! empty( $row['last_gift'] ) ) {
            try {
                $last_dt      = new DateTime( $row['last_gift'] );
                $months_since = (int) $now->diff( $last_dt )->days / 30;
            } catch ( Exception $e ) {}
        }

        if ( $months_since > 12 ) {
            $seg = 'lapsed';
        } elseif ( $row['donation_count'] >= 5 ) {
            $seg = 'recurring';
        } elseif ( $row['donation_count'] >= 2 ) {
            $seg = 'returning';
        } else {
            $seg = 'one-time';
        }

        $row['segment'] = $seg;
        if ( isset( $segments[ $seg ] ) ) $segments[ $seg ]++;
    }
    unset( $row );

    return [
        'rows'     => $rows,
        'kpis'     => $kpis,
        'segments' => $segments,
    ];
}

function metis_ajax_donor_intelligence(): void {
    metis_donations_donor_intelligence_ajax_verify();

    // --- Parse filters ---
    $filters_raw = metis_request_post()['filters'] ?? null;
    $filters     = [];
    if ( is_string( $filters_raw ) && $filters_raw !== '' ) {
        $decoded = json_decode( stripslashes( $filters_raw ), true );
        if ( is_array( $decoded ) ) $filters = $decoded;
    } elseif ( is_array( $filters_raw ) ) {
        $filters = $filters_raw;
    }

    $lifetime = ! empty( metis_request_post()['lifetime'] );
    $start    = metis_text_clean( metis_request_post()['start'] ?? '' );
    $end      = metis_text_clean( metis_request_post()['end']   ?? '' );

    $platform = ( ! empty( $filters['platform'] ) && $filters['platform'] !== 'ALL' )
        ? strtoupper( metis_key_clean( $filters['platform'] ) )
        : null;

    $status_input = null;
    if ( ! empty( $filters['status'] ) && strtoupper( $filters['status'] ) !== 'ALL' ) {
        $status_input = strtolower( metis_key_clean( $filters['status'] ) );
    }

    try {
        if ( ! class_exists( 'Core_Reports_Service' ) ) {
            metis_runtime_send_json_error( [ 'message' => 'Reports service not loaded' ], 500 );
        }

        $result = metis_build_donor_intelligence_data( [
            'lifetime' => $lifetime,
            'start'    => $start,
            'end'      => $end,
            'platform' => $platform,
            'status'   => $status_input,
        ] );

        metis_runtime_send_json_success( array_merge( $result, [
            'filters' => [ 'lifetime' => $lifetime, 'start' => $start, 'end' => $end ],
        ] ) );

    } catch ( Throwable $e ) {
        Metis_Logger::error( 'Donor intelligence failed', [ 'error' => $e->getMessage() ] );
        metis_runtime_send_json_error( [ 'message' => 'Donor intelligence failed.' ], 500 );
    }
}
