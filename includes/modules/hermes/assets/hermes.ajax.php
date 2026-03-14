<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function metis_hermes_ajax_verify( bool $manage = false ): void {
    if ( ! metis_hermes_can_view() ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    if ( $manage && ! metis_hermes_can_manage() ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    metis_hermes_ensure_schema();
}

function metis_hermes_register_ajax_controllers(): void {
    static $registered = false;

    if ( $registered || ! function_exists( 'metis_ajax_register_controller' ) ) {
        return;
    }

    $registered = true;

    metis_ajax_register_controller( 'metis_hermes_bootstrap', [
        'module' => 'hermes',
        'permission' => 'view',
        'schema' => [],
    ] );
    metis_ajax_register_controller( 'metis_hermes_query', [
        'module' => 'hermes',
        'permission' => 'view',
        'schema' => [
            'query' => [ 'type' => 'string', 'required' => true ],
            'session_code' => [ 'type' => 'string', 'required' => false ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_diagnostics', [
        'module' => 'hermes',
        'permission' => 'view',
        'schema' => [
            'query' => [ 'type' => 'string', 'required' => false ],
            'session_code' => [ 'type' => 'string', 'required' => false ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_preview_action', [
        'module' => 'hermes',
        'permission' => 'view',
        'schema' => [
            'action_code' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_approve_action', [
        'module' => 'hermes',
        'permission' => 'edit',
        'schema' => [
            'action_code' => [ 'type' => 'string', 'required' => true ],
            'note' => [ 'type' => 'string', 'required' => false ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_execute_action', [
        'module' => 'hermes',
        'permission' => 'edit',
        'schema' => [
            'action_code' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
}

metis_add_action( 'wp_ajax_metis_hermes_bootstrap', function () {
    metis_hermes_ajax_verify( false );
    metis_send_json_success( metis_hermes_dashboard_payload() );
} );

metis_add_action( 'wp_ajax_metis_hermes_query', function () {
    metis_hermes_ajax_verify( false );
    $query = sanitize_text_field( metis_unslash( $_POST['query'] ?? '' ) );
    $session_code = sanitize_text_field( metis_unslash( $_POST['session_code'] ?? '' ) );
    metis_send_json_success( metis_hermes_gateway()->converse( $query, $session_code ) );
} );

metis_add_action( 'wp_ajax_metis_hermes_diagnostics', function () {
    metis_hermes_ajax_verify( false );
    $query = sanitize_text_field( metis_unslash( $_POST['query'] ?? '' ) );
    $session_code = sanitize_text_field( metis_unslash( $_POST['session_code'] ?? '' ) );
    metis_send_json_success( metis_hermes_gateway()->diagnostics( $query, $session_code ) );
} );

metis_add_action( 'wp_ajax_metis_hermes_preview_action', function () {
    metis_hermes_ajax_verify( false );
    $action_code = sanitize_text_field( metis_unslash( $_POST['action_code'] ?? '' ) );
    metis_send_json_success( metis_hermes_gateway()->previewAction( $action_code ) );
} );

metis_add_action( 'wp_ajax_metis_hermes_approve_action', function () {
    metis_hermes_ajax_verify( true );
    $action_code = sanitize_text_field( metis_unslash( $_POST['action_code'] ?? '' ) );
    $note = sanitize_text_field( metis_unslash( $_POST['note'] ?? '' ) );
    metis_send_json_success( [ 'action' => metis_hermes_gateway()->approveAction( $action_code, $note ) ] );
} );

metis_add_action( 'wp_ajax_metis_hermes_execute_action', function () {
    metis_hermes_ajax_verify( true );
    $action_code = sanitize_text_field( metis_unslash( $_POST['action_code'] ?? '' ) );
    metis_send_json_success( metis_hermes_gateway()->executeAction( $action_code ) );
} );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_hermes_register_ajax_controllers();
}
