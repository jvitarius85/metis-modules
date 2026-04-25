<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

$composer_autoload = defined( 'METIS_VENDOR_PATH' )
    ? METIS_VENDOR_PATH . 'autoload.php'
    : dirname( __DIR__, 4 ) . '/vendor/autoload.php';
if ( is_file( $composer_autoload ) ) {
    require_once $composer_autoload;
}

if ( ! function_exists( 'metis_stripe_init' ) ) {
    function metis_stripe_init(): void {
        static $initialized = false;
        if ( $initialized ) {
            return;
        }

        $secret = class_exists( 'Core_Settings_Service' )
            ? \Metis\Core\Services\CredentialService::getBySetting( 'stripe_secret' )
            : null;
        if ( $secret && class_exists( '\Stripe\Stripe' ) ) {
            \Stripe\Stripe::setApiKey( $secret );
            $initialized = true;
            Metis_Logger::info( 'Stripe SDK initialized' );
            return;
        }

        Metis_Logger::warn( 'Stripe SDK: no secret key configured' );
    }
}

if ( class_exists( '\Stripe\Stripe' ) ) {
    metis_stripe_init();
}

require_once __DIR__ . '/StripeWebhookRuntime.php';
