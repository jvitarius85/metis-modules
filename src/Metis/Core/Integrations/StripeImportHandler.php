<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

require_once __DIR__ . '/StripeRuntimeBootstrap.php';

if ( ! function_exists( 'metis_ajax_import_stripe_transactions' ) ) {
    function metis_ajax_import_stripe_transactions(): void {
        metis_check_ajax_referer( 'metis_donations', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        if ( ! class_exists( '\Stripe\Stripe' ) ) {
            metis_runtime_send_json_error( [ 'message' => 'Stripe SDK not loaded.' ], 500 );
        }

        if ( \Stripe\Stripe::getApiKey() === null ) {
            metis_stripe_init();
        }

        if ( \Stripe\Stripe::getApiKey() === null ) {
            metis_runtime_send_json_error( [ 'message' => 'Stripe secret key not configured.' ], 500 );
        }

        metis_runtime_send_json_success( [
            'message' => 'Stripe import endpoint is available.',
        ] );
    }
}

if ( function_exists( 'metis_ajax_register_handler' ) ) {
    metis_ajax_register_handler( 'metis_import_stripe_transactions', 'metis_ajax_import_stripe_transactions' );
}
