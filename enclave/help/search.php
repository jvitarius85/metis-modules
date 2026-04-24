<?php
declare(strict_types=1);

if ( ! defined( 'METIS_STANDALONE' ) ) {
    define( 'METIS_STANDALONE', true );
}

if ( ! defined( 'METIS_PATH' ) ) {
    define( 'METIS_PATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/src/Metis/Core/CoreBootstrap.php';
metis_core_bootstrap( 'standalone_bootstrap' );
metis_standalone_boot();

header( 'Content-Type: application/json; charset=utf-8' );

$encode = static function ( array $payload ): string {
    if ( function_exists( 'wp_json_encode' ) ) {
        return (string) wp_json_encode( $payload );
    }

    return (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
};

if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' ) {
    http_response_code( 405 );
    echo $encode( [
        'success' => false,
        'message' => 'Method not allowed.',
    ] );
    exit;
}

$enclave = metis_security_enclave();
if ( ! $enclave->has_policy( 'help.search' ) ) {
    $enclave->register_policy(
        new Metis_Security_Policy(
            'help.search',
            null,
            'view',
            true,
            true,
            true,
            'metis_help_search_route',
            180,
            60
        )
    );
}

try {
    $payload = $enclave->execute(
        'help.search',
        metis_security_runtime_request_context( $_POST ),
        static function (): array {
            $service = metis_help_service();
            if ( ! $service instanceof Metis_Help_Service ) {
                throw new RuntimeException( 'Help service is unavailable.' );
            }

            $query = (string) ( $_POST['query'] ?? '' );
            $category = (string) ( $_POST['category'] ?? '' );
            $limit = (int) ( $_POST['limit'] ?? 10 );
            $page = (int) ( $_POST['page'] ?? 1 );

            return $service->searchIndex( $query, $category, $limit, $page );
        }
    );

    echo $encode( array_merge( [ 'success' => true ], $payload ) );
} catch ( Throwable $throwable ) {
    http_response_code( $throwable instanceof Metis_Security_Enclave_Exception ? (int) $throwable->status() : 500 );
    echo $encode( [
        'success' => false,
        'message' => $throwable instanceof Metis_Security_Enclave_Exception
            ? $throwable->getMessage()
            : 'Help search failed.',
    ] );
}
exit;
