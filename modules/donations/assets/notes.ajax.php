<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Donations Notes AJAX Handlers
 *
 * Endpoints:
 *   legacy_ajax_metis_add_batch_note
 *   legacy_ajax_metis_update_note
 *   legacy_ajax_metis_delete_note
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
}

// -------------------------------------------------------------------------
// Add batch note
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_add_batch_note', function () {
    $batch = metis_text_clean( $_POST['batch_code'] ?? '' );
    $text  = metis_text_clean( $_POST['text']       ?? '' );

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
    $db = metis_db();

    $id    = intval( $_POST['id']    ?? 0 );
    $text  = metis_textarea_clean( $_POST['text']  ?? '' );
    $batch = metis_text_clean( $_POST['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $db->update(
        Metis_Tables::get( 'batch_notes' ),
        [ 'note_text' => $text ],
        [ 'id'        => $id   ]
    );

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
    $db = metis_db();

    $id    = intval( $_POST['id']    ?? 0 );
    $batch = metis_text_clean( $_POST['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $db->delete(
        Metis_Tables::get( 'batch_notes' ),
        [ 'id' => $id ]
    );

    metis_add_batch_audit( $batch, 'note_deleted', metis_json_encode( [ 'note_id' => $id ] ) );

    metis_runtime_send_json_success();
}, [
    'module' => 'donations',
    'permission' => 'delete',
] );
