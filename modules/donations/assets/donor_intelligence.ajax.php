<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Modules\Donations\DonorIntelligenceService;

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
    return DonorIntelligenceService::buildData( $args );
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
