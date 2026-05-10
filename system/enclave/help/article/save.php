<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/_bootstrap.php';

header( 'Content-Type: application/json; charset=utf-8' );

if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' ) {
    metis_help_enclave_fail( 405, 'Method not allowed.' );
}

metis_help_enclave_register_policy( 'help.article.save', 'manage', 'metis_help_article_save' );

$enclave = metis_security_enclave();

try {
    $payload = $enclave->execute(
        'help.article.save',
        metis_security_runtime_request_context( metis_request_post() ),
        static function (): array {
            $store = new \Metis\Core\HelpSearchStore();
            $id = isset( metis_request_post()['id'] ) ? (int) metis_request_post()['id'] : 0;
            $articleId = $store->saveArticle(
                [
                    'title' => (string) ( metis_request_post()['title'] ?? '' ),
                    'slug' => (string) ( metis_request_post()['slug'] ?? '' ),
                    'summary' => (string) ( metis_request_post()['summary'] ?? '' ),
                    'content' => (string) ( metis_request_post()['content'] ?? '' ),
                    'category_id' => (int) ( metis_request_post()['category_id'] ?? 0 ),
                    'tags' => (string) ( metis_request_post()['tags'] ?? '' ),
                    'search_terms' => (string) ( metis_request_post()['search_terms'] ?? '' ),
                    'status' => (string) ( metis_request_post()['status'] ?? 'draft' ),
                ],
                $id > 0 ? $id : null
            );

            $article = $store->articleById( $articleId, true );
            return [
                'article_id' => $articleId,
                'article' => $article,
            ];
        }
    );

    echo metis_help_enclave_json( array_merge( [ 'success' => true ], $payload ) );
} catch ( InvalidArgumentException $e ) {
    metis_help_enclave_fail( 422, $e->getMessage() );
} catch ( Throwable $e ) {
    if ( $e instanceof Metis_Security_Enclave_Exception ) {
        metis_help_enclave_fail( (int) $e->status(), $e->getMessage() );
    }
    metis_help_enclave_fail( 500, 'Unable to save the help article.' );
}
exit;
