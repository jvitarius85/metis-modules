<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

require_once dirname( __DIR__, 3 ) . '/src/Metis/Core/Integrations/StripeRuntimeBootstrap.php';

use Metis\Modules\Donations\TransactionMutationService;
use Metis\Modules\Donations\TransactionRecordService;

/**
 * Donations Notes AJAX Handlers
 *
 * Endpoints:
 *   metis_ajax_metis_add_batch_note
 *   metis_ajax_metis_update_note
 *   metis_ajax_metis_delete_note
 */

Metis_Logger::info( 'Donations Notes AJAX loaded' );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_add_batch_note', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_add_batch_note' ),
    ] );
    metis_ajax_register_controller( 'metis_update_note', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_update_note' ),
    ] );
    metis_ajax_register_controller( 'metis_delete_note', [
        'module' => 'donations',
        'permission' => 'delete',
        'nonce_action' => metis_ajax_nonce_action( 'metis_delete_note' ),
    ] );
    metis_ajax_register_controller( 'metis_add_transaction_note', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_add_transaction_note' ),
    ] );
    metis_ajax_register_controller( 'metis_record_transaction_refund', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_record_transaction_refund' ),
    ] );
    metis_ajax_register_controller( 'metis_update_transaction_campaign', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_update_transaction_campaign' ),
    ] );
}

function metis_donations_notes_ajax_verify( string $action, string $permission = 'edit' ): void {
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
        ? metis_ajax_nonce_action( $action )
        : $action;

    if ( $nonce === '' || ! function_exists( 'metis_runtime_verify_nonce' ) || ! metis_runtime_verify_nonce( $nonce, $nonce_action ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $allowed = match ( $permission ) {
        'delete' => function_exists( 'metis_donations_can_delete' ) && metis_donations_can_delete(),
        'view' => function_exists( 'metis_donations_can' ) && metis_donations_can( 'view' ),
        default => function_exists( 'metis_donations_can_manage' ) && metis_donations_can_manage(),
    };

    if ( ! $allowed ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

function metis_donations_notes_ensure_tables(): void {
    TransactionMutationService::ensureSupportingTables();
}

function metis_donations_current_user_id(): ?int {
    $id = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
    return $id > 0 ? $id : null;
}

function metis_donations_transaction_exists( string $tid ): ?array {
    return TransactionRecordService::findByTransactionId( $tid );
}

// -------------------------------------------------------------------------
// Add batch note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_add_batch_note', function () {
    metis_donations_notes_ajax_verify( 'metis_add_batch_note', 'edit' );

    $batch = metis_text_clean( metis_request_post()['batch_code'] ?? '' );
    $text  = metis_text_clean( metis_request_post()['text']       ?? '' );

    if ( ! $batch || ! $text ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing batch_code or text' ], 400 );
    }

    metis_add_batch_note( $batch, $text );
    metis_add_batch_audit( $batch, 'note_added', $text );

    metis_runtime_send_json_success();
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

// -------------------------------------------------------------------------
// Update batch note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_update_note', function () {
    metis_donations_notes_ajax_verify( 'metis_update_note', 'edit' );

    $id    = intval( metis_request_post()['id']    ?? 0 );
    $text  = metis_textarea_clean( metis_request_post()['text']  ?? '' );
    $batch = metis_text_clean( metis_request_post()['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $updated = metis_update_batch_note( $id, $batch, $text );
    if ( $updated === false ) {
        metis_runtime_send_json_error( [ 'message' => 'Failed to update batch note.' ], 500 );
    }

    metis_add_batch_audit( $batch, 'note_updated', metis_json_encode( [ 'note_id' => $id ] ) );

    metis_runtime_send_json_success();
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

// -------------------------------------------------------------------------
// Delete batch note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_delete_note', function () {
    metis_donations_notes_ajax_verify( 'metis_delete_note', 'delete' );

    $id    = intval( metis_request_post()['id']    ?? 0 );
    $batch = metis_text_clean( metis_request_post()['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $deleted = metis_delete_batch_note( $id, $batch );
    if ( $deleted === false ) {
        metis_runtime_send_json_error( [ 'message' => 'Failed to delete batch note.' ], 500 );
    }

    metis_add_batch_audit( $batch, 'note_deleted', metis_json_encode( [ 'note_id' => $id ] ) );

    metis_runtime_send_json_success();
}, [
    'module' => 'donations',
    'permission' => 'delete',
] );

// -------------------------------------------------------------------------
// Add transaction note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_add_transaction_note', function () {
    metis_donations_notes_ajax_verify( 'metis_add_transaction_note', 'edit' );

    metis_donations_notes_ensure_tables();

    $post = metis_request_post();
    $tid  = metis_text_clean( $post['tid'] ?? '' );
    $note = metis_textarea_clean( $post['note'] ?? '' );

    if ( $tid === '' || $note === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing transaction ID or note.' ], 400 );
    }

    if ( ! metis_donations_transaction_exists( $tid ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Transaction not found.' ], 404 );
    }

    $result = TransactionMutationService::addTransactionNote( $tid, $note, metis_donations_current_user_id() );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Failed to save note.' ) ], (int) ( $result['status'] ?? 500 ) );
    }

    metis_runtime_send_json_success( [
        'note' => $result['note'] ?? [],
    ] );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

// -------------------------------------------------------------------------
// Record transaction refund
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_record_transaction_refund', function () {
    metis_donations_notes_ajax_verify( 'metis_record_transaction_refund', 'edit' );

    metis_donations_notes_ensure_tables();

    $post = metis_request_post();
    $tid = metis_text_clean( $post['tid'] ?? '' );
    $amount = isset( $post['amount'] ) ? round( (float) $post['amount'], 2 ) : 0.0;
    $reason = metis_text_clean( $post['reason'] ?? '' );
    $notes = metis_textarea_clean( $post['notes'] ?? '' );
    $source = metis_key_clean( (string) ( $post['source'] ?? 'stripe' ) );
    if ( ! in_array( $source, [ 'stripe', 'manual' ], true ) ) {
        $source = 'stripe';
    }

    if ( $tid === '' || $amount <= 0 ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing transaction ID or refund amount.' ], 400 );
    }

    $transaction = metis_donations_transaction_exists( $tid );
    if ( ! $transaction ) {
        metis_runtime_send_json_error( [ 'message' => 'Transaction not found.' ], 404 );
    }

    $result = TransactionMutationService::recordTransactionRefund(
        $transaction,
        $tid,
        $amount,
        $reason,
        $notes,
        $source,
        metis_donations_current_user_id()
    );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Failed to record refund.' ) ], (int) ( $result['status'] ?? 500 ) );
    }

    metis_runtime_send_json_success( [
        'refund' => $result['refund'] ?? [],
        'total_refunded' => (float) ( $result['total_refunded'] ?? 0 ),
        'net_after_refunds' => (float) ( $result['net_after_refunds'] ?? 0 ),
    ] );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_update_transaction_campaign', function () {
    metis_donations_notes_ajax_verify( 'metis_update_transaction_campaign', 'edit' );

    $tid = metis_text_clean( metis_request_post()['tid'] ?? '' );
    $campaign_reference = metis_text_clean( metis_request_post()['campaign_code'] ?? '' );

    if ( $tid === '' || $campaign_reference === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing transaction ID or campaign.' ], 400 );
    }

    $transaction = metis_donations_transaction_exists( $tid );
    if ( ! $transaction ) {
        metis_runtime_send_json_error( [ 'message' => 'Transaction not found.' ], 404 );
    }

    $resolved_campaign = \Metis\Modules\Donations\CampaignService::resolveCampaignReference( $campaign_reference, false );
    if ( $resolved_campaign === null ) {
        metis_runtime_send_json_error( [ 'message' => 'Campaign not found.' ], 404 );
    }

    $result = TransactionMutationService::updateTransactionCampaign( $tid, $resolved_campaign );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Unable to update transaction campaign.' ) ], (int) ( $result['status'] ?? 500 ) );
    }

    metis_runtime_send_json_success( [
        'campaign_code' => (string) ( $result['campaign_code'] ?? $resolved_campaign ),
        'campaign_name' => (string) ( $result['campaign_name'] ?? $resolved_campaign ),
    ] );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );
