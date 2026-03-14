<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stripe API Bootstrap
 *
 * Loads the Stripe PHP SDK and initializes the API key.
 * Also loads the webhook handler.
 */

require_once __DIR__ . '/stripe-php-19.2.0/init.php';

// Initialize Stripe with the secret key from settings
function metis_stripe_init(): void {
    static $initialized = false;
    if ( $initialized ) return;

    $secret = Core_Settings_Service::get( 'stripe_secret' );

    // Fall back to legacy option key if not yet migrated
    if ( ! $secret ) {
        $secret = get_option( 'mwtools_stripe_secret' );
    }

    if ( $secret ) {
        \Stripe\Stripe::setApiKey( $secret );
        $initialized = true;
        Metis_Logger::info( 'Stripe SDK initialized' );
    } else {
        Metis_Logger::warn( 'Stripe SDK: no secret key configured' );
    }
}

metis_stripe_init();

// Load webhook handler
require_once __DIR__ . '/webhook.php';
