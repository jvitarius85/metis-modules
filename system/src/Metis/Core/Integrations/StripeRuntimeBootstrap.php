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

if ( ! function_exists( 'metis_stripe_diagnostics_store' ) ) {
    function metis_stripe_diagnostics_store( array $values ): void {
        if ( ! class_exists( 'Core_Settings_Service' ) ) {
            return;
        }

        foreach ( $values as $key => $value ) {
            $setting = 'stripe_diag_' . metis_key_clean( (string) $key );
            if ( $setting === 'stripe_diag_' ) {
                continue;
            }
            \Core_Settings_Service::set( $setting, is_scalar( $value ) || $value === null ? (string) $value : metis_json_encode( $value ), false );
        }
    }
}

if ( ! function_exists( 'metis_stripe_record_api_success' ) ) {
    function metis_stripe_record_api_success( string $method, string $path ): void {
        metis_stripe_diagnostics_store( [
            'last_api_success_at' => metis_current_time( 'mysql' ),
            'last_api_method' => strtoupper( trim( $method ) ),
            'last_api_path' => trim( $path ),
        ] );
    }
}

if ( ! function_exists( 'metis_stripe_record_api_error' ) ) {
    function metis_stripe_record_api_error( \Throwable $error, string $method = '', string $path = '' ): void {
        $values = [
            'last_api_error_at' => metis_current_time( 'mysql' ),
            'last_api_error_message' => $error->getMessage(),
            'last_api_method' => strtoupper( trim( $method ) ),
            'last_api_path' => trim( $path ),
            'last_api_error_class' => get_class( $error ),
        ];

        if ( $error instanceof \Metis\Core\Integrations\StripeApiException ) {
            $values['last_api_error_type'] = (string) ( $error->stripeType() ?? '' );
            $values['last_api_error_code'] = (string) ( $error->stripeCode() ?? '' );
            $values['last_api_request_id'] = (string) ( $error->requestId() ?? '' );
            $values['last_api_http_status'] = (string) $error->httpStatus();
        }

        metis_stripe_diagnostics_store( $values );
    }
}

if ( ! function_exists( 'metis_stripe_record_webhook_received' ) ) {
    function metis_stripe_record_webhook_received( array $event ): void {
        metis_stripe_diagnostics_store( [
            'last_webhook_received_at' => metis_current_time( 'mysql' ),
            'last_webhook_event_id' => (string) ( $event['event_id'] ?? $event['id'] ?? '' ),
            'last_webhook_event_type' => (string) ( $event['event_type'] ?? $event['type'] ?? '' ),
        ] );
    }
}

if ( ! function_exists( 'metis_stripe_record_webhook_processed' ) ) {
    function metis_stripe_record_webhook_processed( array $event ): void {
        metis_stripe_diagnostics_store( [
            'last_webhook_processed_at' => metis_current_time( 'mysql' ),
            'last_webhook_event_id' => (string) ( $event['event_id'] ?? $event['id'] ?? '' ),
            'last_webhook_event_type' => (string) ( $event['event_type'] ?? $event['type'] ?? '' ),
        ] );
    }
}

if ( ! function_exists( 'metis_stripe_record_webhook_failure' ) ) {
    function metis_stripe_record_webhook_failure( string $code, string $message ): void {
        metis_stripe_diagnostics_store( [
            'last_webhook_failure_at' => metis_current_time( 'mysql' ),
            'last_webhook_failure_code' => $code,
            'last_webhook_failure_message' => $message,
        ] );
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
