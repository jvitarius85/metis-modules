<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/_bootstrap.php';

header( 'Content-Type: application/json; charset=utf-8' );

if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' ) {
    metis_help_enclave_fail( 405, 'Method not allowed.' );
}

metis_help_enclave_register_policy( 'help.article.publish', 'manage', 'metis_help_article_publish' );

try {
    $payload = metis_security_enclave()->execute(
        'help.article.publish',
        metis_security_runtime_request_context( $_POST ),
        static function (): array {
            $id = (int) ( $_POST['id'] ?? 0 );
            if ( $id < 1 ) {
                throw new InvalidArgumentException( 'Help article ID is required.' );
            }

            $store = new \Metis\Core\HelpSearchStore();
            $store->setStatus( $id, 'published' );
            return [ 'article_id' => $id, 'status' => 'published' ];
        }
    );

    echo metis_help_enclave_json( array_merge( [ 'success' => true ], $payload ) );
} catch ( InvalidArgumentException $e ) {
    metis_help_enclave_fail( 422, $e->getMessage() );
} catch ( Throwable $e ) {
    if ( $e instanceof Metis_Security_Enclave_Exception ) {
        metis_help_enclave_fail( (int) $e->status(), $e->getMessage() );
    }
    metis_help_enclave_fail( 500, 'Unable to publish the help article.' );
}
exit;
