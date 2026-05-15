<?php
declare(strict_types=1);

if ( ! defined( 'METIS_STANDALONE' ) ) {
    define( 'METIS_STANDALONE', true );
}

if ( ! defined( 'METIS_PATH' ) ) {
    define( 'METIS_PATH', dirname( __DIR__, 3 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/src/Metis/Core/CoreBootstrap.php';
\metis_core_bootstrap( 'standalone_bootstrap' );
\metis_standalone_boot();

if ( ! function_exists( 'metis_help_enclave_json' ) ) {
    function metis_help_enclave_json( array $payload ): string {
        if ( function_exists( 'metis_json_encode' ) ) {
            return (string) metis_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        }

        return (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }
}

if ( ! function_exists( 'metis_help_enclave_register_policy' ) ) {
    function metis_help_enclave_register_policy( string $operation, string $permission, string $nonceAction ): void {
        $enclave = \metis_security_enclave();
        if ( $enclave->has_policy( $operation ) ) {
            return;
        }

        $enclave->register_policy(
            new \Metis_Security_Policy(
                $operation,
                'help',
                $permission,
                true,
                true,
                true,
                $nonceAction,
                180,
                60
            )
        );
    }
}

if ( ! function_exists( 'metis_help_enclave_fail' ) ) {
    function metis_help_enclave_fail( int $status, string $message ): never {
        http_response_code( $status );
        echo metis_help_enclave_json(
            [
                'success' => false,
                'message' => $message,
            ]
        );
        exit;
    }
}
