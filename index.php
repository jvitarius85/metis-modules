<?php
declare(strict_types=1);

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_VERSION', '1.5.1' );
define( 'METIS_PATH', __DIR__ . '/' );

require_once __DIR__ . '/includes/core/standalone_bootstrap.php';

if ( ! defined( 'METIS_URL' ) ) {
    $base_path = rtrim( dirname( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ), '/' );
    define( 'METIS_URL', trailingslashit( metis_runtime_base_url( $base_path === '/' ? '' : $base_path ) ) );
}

try {
    metis_standalone_boot();
    metis_standalone_dispatch();
} catch ( Throwable $e ) {
    if ( class_exists( 'Metis_Logger' ) ) {
        Metis_Logger::error( 'Standalone fatal', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ] );
    } elseif ( function_exists( 'metis_standalone_boot_log' ) ) {
        metis_standalone_boot_log( 'fatal', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ] );
    }

    http_response_code( 500 );
    header( 'Content-Type: text/html; charset=UTF-8' );
    echo '<h1>Metis failed to boot.</h1>';
    echo '<p>Check the Metis log for the captured exception.</p>';
}
