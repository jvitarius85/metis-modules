<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_require_cli_tool' ) ) {
    function metis_require_cli_tool(): void {
        if ( PHP_SAPI === 'cli' ) {
            return;
        }

        if ( ! headers_sent() ) {
            http_response_code( 404 );
            header( 'Content-Type: text/plain; charset=UTF-8' );
            header( 'X-Content-Type-Options: nosniff' );
        }

        echo "Not found.\n";
        exit;
    }
}
