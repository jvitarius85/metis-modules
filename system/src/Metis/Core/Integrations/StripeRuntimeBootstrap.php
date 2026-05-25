<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

use Metis\Core\Application;
use Metis\Core\Integrations\StripeApiClient;
use Metis\Core\Services\HttpClient;

if ( ! function_exists( 'metis_stripe_secret_key' ) ) {
    function metis_stripe_secret_key(): string {
        return (string) \Metis\Core\Services\CredentialService::getBySetting( 'stripe_secret' );
    }
}

if ( ! function_exists( 'metis_stripe_publishable_key' ) ) {
    function metis_stripe_publishable_key(): string {
        foreach ( [ 'stripe_publishable_key', 'stripe_public_key', 'stripe_pk', 'stripe_publishable' ] as $key ) {
            $value = trim( (string) \Core_Settings_Service::get( $key, '' ) );
            if ( str_starts_with( $value, 'pk_' ) ) {
                return $value;
            }
        }
        return '';
    }
}

if ( ! function_exists( 'metis_stripe_webhook_secret' ) ) {
    function metis_stripe_webhook_secret(): string {
        return (string) \Metis\Core\Services\CredentialService::getBySetting( 'stripe_webhook_secret' );
    }
}

if ( ! function_exists( 'metis_stripe_api_version' ) ) {
    function metis_stripe_api_version(): ?string {
        $value = trim( (string) \Core_Settings_Service::get( 'stripe_api_version', '' ) );
        return $value !== '' ? $value : null;
    }
}

if ( ! function_exists( 'metis_stripe_client' ) ) {
    function metis_stripe_client(): ?StripeApiClient {
        static $client = null;
        static $cacheKey = null;

        $secret = metis_stripe_secret_key();
        if ( $secret === '' ) {
            return null;
        }

        $version = metis_stripe_api_version();
        $key = $secret . '|' . ( $version ?? '' );
        if ( $client instanceof StripeApiClient && $cacheKey === $key ) {
            return $client;
        }

        if ( class_exists( Application::class ) && Application::has_service( 'stripe_api' ) ) {
            $service = Application::service( 'stripe_api' );
            if ( $service instanceof StripeApiClient ) {
                $client = $service;
                $cacheKey = $key;
                return $client;
            }
        }

        $http = class_exists( Application::class ) && Application::has_service( 'http' )
            ? Application::service( 'http' )
            : new HttpClient();
        if ( ! $http instanceof HttpClient ) {
            $http = new HttpClient();
        }

        $client = new StripeApiClient( $secret, $http, $version );
        $cacheKey = $key;
        return $client;
    }
}

if ( ! function_exists( 'metis_stripe_is_configured' ) ) {
    function metis_stripe_is_configured(): bool {
        $client = metis_stripe_client();
        return $client instanceof StripeApiClient && $client->isConfigured();
    }
}

require_once __DIR__ . '/StripeWebhookRuntime.php';
