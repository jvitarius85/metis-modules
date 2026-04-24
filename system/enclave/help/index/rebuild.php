<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/_bootstrap.php';

header( 'Content-Type: application/json; charset=utf-8' );

if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' ) {
    metis_help_enclave_fail( 405, 'Method not allowed.' );
}

metis_help_enclave_register_policy( 'help.index.rebuild', 'manage', 'metis_help_index_rebuild' );

try {
    $payload = metis_security_enclave()->execute(
        'help.index.rebuild',
        metis_security_runtime_request_context( $_POST ),
        static function (): array {
            $store = new \Metis\Core\HelpSearchStore();
            $count = $store->rebuildSearchIndex();
            return [ 'rebuilt' => $count ];
        }
    );

    echo metis_help_enclave_json( array_merge( [ 'success' => true ], $payload ) );
} catch ( Throwable $e ) {
    if ( $e instanceof Metis_Security_Enclave_Exception ) {
        metis_help_enclave_fail( (int) $e->status(), $e->getMessage() );
    }
    metis_help_enclave_fail( 500, 'Unable to rebuild the help search index.' );
}
exit;
