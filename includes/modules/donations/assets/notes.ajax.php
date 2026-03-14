<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Donations Notes AJAX Handlers
 *
 * Endpoints:
 *   wp_ajax_metis_add_batch_note
 *   wp_ajax_metis_update_note
 *   wp_ajax_metis_delete_note
 */

Metis_Logger::info( 'Donations Notes AJAX loaded' );

// -------------------------------------------------------------------------
// Add batch note
// -------------------------------------------------------------------------

metis_add_action( 'wp_ajax_metis_add_batch_note', function () {

    if ( ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }

    $batch = sanitize_text_field( $_POST['batch_code'] ?? '' );
    $text  = sanitize_text_field( $_POST['text']       ?? '' );

    if ( ! $batch || ! $text ) {
        metis_send_json_error( [ 'message' => 'Missing batch_code or text' ], 400 );
    }

    metis_add_batch_note( $batch, $text );
    metis_add_batch_audit( $batch, 'note_added', $text );

    metis_send_json_success();
} );

// -------------------------------------------------------------------------
// Update batch note
// -------------------------------------------------------------------------

metis_add_action( 'wp_ajax_metis_update_note', function () {

    if ( ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }

    global $wpdb;

    $id    = intval( $_POST['id']    ?? 0 );
    $text  = sanitize_textarea_field( $_POST['text']  ?? '' );
    $batch = sanitize_text_field( $_POST['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $wpdb->update(
        Metis_Tables::get( 'batch_notes' ),
        [ 'note_text' => $text ],
        [ 'id'        => $id   ],
        [ '%s' ],
        [ '%d' ]
    );

    metis_add_batch_audit( $batch, 'note_updated', metis_json_encode( [ 'note_id' => $id ] ) );

    metis_send_json_success();
} );

// -------------------------------------------------------------------------
// Delete batch note
// -------------------------------------------------------------------------

metis_add_action( 'wp_ajax_metis_delete_note', function () {

    if ( ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }

    global $wpdb;

    $id    = intval( $_POST['id']    ?? 0 );
    $batch = sanitize_text_field( $_POST['batch'] ?? '' );

    if ( ! $id || ! $batch ) {
        metis_send_json_error( [ 'message' => 'Missing id or batch' ], 400 );
    }

    $wpdb->delete(
        Metis_Tables::get( 'batch_notes' ),
        [ 'id' => $id ],
        [ '%d' ]
    );

    metis_add_batch_audit( $batch, 'note_deleted', metis_json_encode( [ 'note_id' => $id ] ) );

    metis_send_json_success();
} );
