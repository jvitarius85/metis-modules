<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Metis\Modules\Forms\Repository;

function metis_forms_ajax_guard( bool $manage_required = false ): void {
    check_ajax_referer( 'metis_forms', 'nonce' );
    if ( ! metis_forms_can_view() || ( $manage_required && ! metis_forms_can_manage() ) ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }
    metis_forms_ensure_schema();
}

metis_add_action( 'wp_ajax_metis_forms_list', function (): void {
    metis_forms_ajax_guard();
    metis_send_json_success( [ 'forms' => Repository::listForms() ] );
} );

metis_add_action( 'wp_ajax_metis_forms_get', function (): void {
    metis_forms_ajax_guard();
    $form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
    $form = Repository::getFormById( $form_id );
    if ( ! $form ) {
        metis_send_json_error( 'Form not found.', 404 );
    }
    metis_send_json_success( [ 'form' => $form ] );
} );

metis_add_action( 'wp_ajax_metis_forms_save', function (): void {
    metis_forms_ajax_guard( true );
    $raw = isset( $_POST['payload'] ) ? (string) $_POST['payload'] : '';
    $payload = json_decode( $raw, true );
    if ( ! is_array( $payload ) ) {
        metis_send_json_error( 'Invalid form payload.', 422 );
    }
    $result = Repository::saveForm( $payload, (int) get_current_user_id() );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( (string) ( $result['error'] ?? 'Save failed.' ), (int) ( $result['status'] ?? 500 ) );
    }
    metis_send_json_success( [ 'form' => $result['form'] ] );
} );

metis_add_action( 'wp_ajax_metis_forms_duplicate', function (): void {
    metis_forms_ajax_guard( true );
    $result = Repository::duplicateForm( (int) ( $_POST['form_id'] ?? 0 ), (int) get_current_user_id() );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( (string) ( $result['error'] ?? 'Duplicate failed.' ), (int) ( $result['status'] ?? 500 ) );
    }
    metis_send_json_success( [ 'form' => $result['form'] ] );
} );

metis_add_action( 'wp_ajax_metis_forms_publish', function (): void {
    metis_forms_ajax_guard( true );
    $result = Repository::publishForm( (int) ( $_POST['form_id'] ?? 0 ) );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( (string) ( $result['error'] ?? 'Publish failed.' ), (int) ( $result['status'] ?? 500 ) );
    }
    metis_send_json_success( [ 'form' => $result['form'] ] );
} );

metis_add_action( 'wp_ajax_metis_forms_delete', function (): void {
    metis_forms_ajax_guard( true );
    if ( ! metis_forms_can_delete() ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }
    $result = Repository::deleteForm( (int) ( $_POST['form_id'] ?? 0 ) );
    metis_send_json_success( $result );
} );

metis_add_action( 'wp_ajax_metis_forms_entries', function (): void {
    metis_forms_ajax_guard();
    $form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
    metis_send_json_success( [ 'entries' => Repository::listSubmissions( $form_id ) ] );
} );

metis_add_action( 'wp_ajax_metis_forms_export', function (): void {
    metis_forms_ajax_guard();
    $form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
    metis_send_json_success( [ 'csv' => Repository::exportSubmissionsCsv( $form_id ) ] );
} );

metis_add_action( 'wp_ajax_metis_forms_dynamic_options', function (): void {
    metis_forms_ajax_guard();
    $raw = isset( $_POST['source'] ) ? (string) $_POST['source'] : '';
    $source = json_decode( $raw, true );
    if ( ! is_array( $source ) ) {
        metis_send_json_error( 'Invalid source.', 422 );
    }
    metis_send_json_success( [ 'options' => Repository::resolveDynamicOptions( $source ) ] );
} );
