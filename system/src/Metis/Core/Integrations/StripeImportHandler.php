<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

require_once __DIR__ . '/StripeRuntimeBootstrap.php';

if ( ! function_exists( 'metis_stripe_import_error_response' ) ) {
    function metis_stripe_import_error_response( string $message, int $status, string $error_code ): never {
        $request_id = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';
        $endpoint = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/api/ajax' ), PHP_URL_PATH ) ?? '/api/ajax' );
        $action = metis_key_clean( (string) ( $_REQUEST['action'] ?? 'metis_import_stripe_transactions' ) );
        $code_key = metis_key_clean( $error_code );

        metis_audit_log_security( 'ajax_action_failed', [
            'module'   => 'donations',
            'severity' => $status >= 500 ? 'error' : 'warning',
            'outcome'  => 'failed',
            'resource' => [
                'type'  => 'ajax_action',
                'id'    => $action,
                'label' => $code_key,
            ],
            'context'  => [
                'route'         => 'ajax.metis.api',
                'endpoint'      => $endpoint,
                'status_code'   => $status,
                'error_code'    => $code_key,
                'error_message' => $message,
                'request_id'    => $request_id,
            ],
        ] );

        metis_runtime_send_json_error( [ 'message' => $message, 'code' => $code_key ], $status );
    }
}

if ( ! function_exists( 'metis_ajax_import_stripe_transactions' ) ) {
    function metis_ajax_import_stripe_transactions(): void {
        metis_check_ajax_referer( 'metis_donations', 'metis_action_nonce' );

        if ( ! metis_current_user_can( 'manage_options' ) ) {
            metis_stripe_import_error_response( 'Unauthorized.', 403, 'permission_denied' );
        }

        if ( ! class_exists( '\Stripe\Stripe' ) ) {
            metis_stripe_import_error_response( 'Stripe SDK not loaded.', 500, 'stripe_sdk_missing' );
        }

        if ( \Stripe\Stripe::getApiKey() === null ) {
            metis_stripe_init();
        }

        if ( \Stripe\Stripe::getApiKey() === null ) {
            metis_stripe_import_error_response( 'Stripe secret key not configured.', 500, 'stripe_secret_missing' );
        }

        metis_runtime_send_json_success( [
            'message' => 'Stripe import endpoint is available.',
        ] );
    }
}

if ( function_exists( 'metis_ajax_register_handler' ) ) {
    if ( function_exists( 'metis_ajax_register_controller' ) ) {
        metis_ajax_register_controller( 'metis_import_stripe_transactions', [
            'module' => 'donations',
            'permission' => 'edit',
            'nonce_action' => metis_ajax_nonce_action( 'metis_import_stripe_transactions' ),
        ] );
    }
    metis_ajax_register_handler( 'metis_import_stripe_transactions', 'metis_ajax_import_stripe_transactions' );
}
