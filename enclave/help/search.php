<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header( 'Content-Type: application/json; charset=utf-8' );

if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' ) {
    http_response_code( 405 );
    echo $encode( [
        'success' => false,
        'message' => 'Method not allowed.',
    ] );
    exit;
}

$enclave = metis_security_enclave();
metis_help_enclave_register_policy( 'help.search', 'view', 'metis_help_search_route' );

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

    echo metis_help_enclave_json( array_merge( [ 'success' => true ], $payload ) );
} catch ( Throwable $throwable ) {
    http_response_code( $throwable instanceof Metis_Security_Enclave_Exception ? (int) $throwable->status() : 500 );
    echo metis_help_enclave_json( [
        'success' => false,
        'message' => $throwable instanceof Metis_Security_Enclave_Exception
            ? $throwable->getMessage()
            : 'Help search failed.',
    ] );
}
exit;
