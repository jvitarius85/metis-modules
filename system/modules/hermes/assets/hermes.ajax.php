<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_hermes_ajax_verify( bool $manage = false ): void {
    if ( ! metis_hermes_can_view() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    if ( $manage && ! metis_hermes_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    metis_hermes_ensure_schema();
}

function metis_hermes_ajax_handle( callable $callback ): void {
    try {
        metis_runtime_send_json_success( $callback() );
    } catch ( Throwable $throwable ) {
        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::error( 'hermes.ajax.failed', [
                'exception' => get_class( $throwable ),
                'message' => $throwable->getMessage(),
            ] );
        }
        metis_runtime_send_json_error( [
            'message' => 'Hermes request failed.',
        ], 500 );
    }
}

function metis_hermes_release_progress_token( string $token ): string {
    $token = preg_replace( '/[^a-z0-9_-]/i', '', strtolower( trim( $token ) ) ) ?? '';
    return substr( $token, 0, 64 );
}

function metis_hermes_release_progress_store_file( string $token ): string {
    return 'hermes/release-progress/' . metis_hermes_release_progress_token( $token ) . '.json';
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
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_bootstrap' ),
        'schema' => [],
    ] );
    metis_ajax_register_controller( 'metis_hermes_query', [
        'module' => 'hermes',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_query' ),
        'schema' => [
            'query' => [ 'type' => 'string', 'required' => true ],
            'session_code' => [ 'type' => 'string', 'required' => false ],
            'current_route' => [ 'type' => 'string', 'required' => false ],
            'current_module' => [ 'type' => 'string', 'required' => false ],
            'current_topic' => [ 'type' => 'string', 'required' => false ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_diagnostics', [
        'module' => 'hermes',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_diagnostics' ),
        'schema' => [
            'query' => [ 'type' => 'string', 'required' => false ],
            'session_code' => [ 'type' => 'string', 'required' => false ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_preview_action', [
        'module' => 'hermes',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_preview_action' ),
        'schema' => [
            'action_code' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_approve_action', [
        'module' => 'hermes',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_approve_action' ),
        'schema' => [
            'action_code' => [ 'type' => 'string', 'required' => true ],
            'note' => [ 'type' => 'string', 'required' => false ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_execute_action', [
        'module' => 'hermes',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_execute_action' ),
        'schema' => [
            'action_code' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_execute_release_action', [
        'module' => 'hermes',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_execute_action' ),
        'schema' => [
            'action_code' => [ 'type' => 'string', 'required' => true ],
            'progress_token' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_release_progress', [
        'module' => 'hermes',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_execute_action' ),
        'schema' => [
            'progress_token' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
    metis_ajax_register_controller( 'metis_hermes_reveal_secret', [
        'module' => 'hermes',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_hermes_reveal_secret' ),
        'schema' => [
            'reveal_token' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
}

metis_ajax_register_handler( 'metis_hermes_bootstrap', function () {
    metis_hermes_ajax_verify( false );
    metis_hermes_ajax_handle( static fn (): array => metis_hermes_dashboard_payload() );
} );

metis_ajax_register_handler( 'metis_hermes_query', function () {
    metis_hermes_ajax_verify( false );
    $query = metis_text_clean( metis_runtime_unslash( metis_request_post()['query'] ?? '' ) );
    $session_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['session_code'] ?? '' ) );
    $runtime_context = [
        'current_route' => metis_text_clean( metis_runtime_unslash( metis_request_post()['current_route'] ?? '' ) ),
        'current_module' => metis_text_clean( metis_runtime_unslash( metis_request_post()['current_module'] ?? '' ) ),
        'current_topic' => metis_text_clean( metis_runtime_unslash( metis_request_post()['current_topic'] ?? '' ) ),
    ];
    metis_hermes_ajax_handle( static fn (): array => metis_hermes_gateway()->converse( $query, $session_code, $runtime_context ) );
} );

metis_ajax_register_handler( 'metis_hermes_diagnostics', function () {
    metis_hermes_ajax_verify( false );
    $query = metis_text_clean( metis_runtime_unslash( metis_request_post()['query'] ?? '' ) );
    $session_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['session_code'] ?? '' ) );
    metis_hermes_ajax_handle( static fn (): array => metis_hermes_gateway()->diagnostics( $query, $session_code ) );
} );

metis_ajax_register_handler( 'metis_hermes_preview_action', function () {
    metis_hermes_ajax_verify( false );
    $action_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['action_code'] ?? '' ) );
    metis_hermes_ajax_handle( static fn (): array => metis_hermes_gateway()->previewAction( $action_code ) );
} );

metis_ajax_register_handler( 'metis_hermes_approve_action', function () {
    metis_hermes_ajax_verify( true );
    $action_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['action_code'] ?? '' ) );
    $note = metis_textarea_clean( metis_runtime_unslash( metis_request_post()['note'] ?? '' ) );
    metis_hermes_ajax_handle( static fn (): array => [ 'action' => metis_hermes_gateway()->approveAction( $action_code, $note ) ] );
} );

metis_ajax_register_handler( 'metis_hermes_execute_action', function () {
    metis_hermes_ajax_verify( true );
    $action_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['action_code'] ?? '' ) );
    metis_hermes_ajax_handle( static fn (): array => metis_hermes_gateway()->executeAction( $action_code ) );
} );

metis_ajax_register_handler( 'metis_hermes_execute_release_action', function () {
    metis_hermes_ajax_verify( true );
    $action_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['action_code'] ?? '' ) );
    $progress_token = metis_text_clean( metis_runtime_unslash( metis_request_post()['progress_token'] ?? '' ) );
    metis_hermes_ajax_handle( static fn (): array => metis_hermes_gateway()->executeReleaseAction( $action_code, $progress_token ) );
} );

metis_ajax_register_handler( 'metis_hermes_release_progress', function () {
    metis_hermes_ajax_verify( true );
    $token = metis_hermes_release_progress_token( metis_text_clean( metis_runtime_unslash( metis_request_post()['progress_token'] ?? '' ) ) );
    if ( $token === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A progress token is required.' ], 400 );
    }

    $progress = function_exists( 'metis_runtime_json_store_read' )
        ? metis_runtime_json_store_read( metis_hermes_release_progress_store_file( $token ) )
        : [];

    metis_runtime_send_json_success( [
        'message' => 'Release progress loaded.',
        'progress' => is_array( $progress ) ? $progress : [],
    ] );
} );

metis_ajax_register_handler( 'metis_hermes_reveal_secret', function () {
    metis_hermes_ajax_verify( true );
    $reveal_token = metis_text_clean( metis_runtime_unslash( metis_request_post()['reveal_token'] ?? '' ) );
    metis_hermes_ajax_handle( static fn (): array => metis_hermes_gateway()->revealSecret( $reveal_token ) );
} );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_hermes_register_ajax_controllers();
}
