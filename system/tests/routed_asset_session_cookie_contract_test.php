<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$router_runtime = file_get_contents( dirname( __DIR__ ) . '/src/Metis/Core/Routing/RouterRuntime.php' );
if ( ! is_string( $router_runtime ) || $router_runtime === '' ) {
    fwrite( STDERR, "Unable to read RouterRuntime.php\n" );
    exit( 1 );
}

$expected = [
    'function metis_router_suppress_session_cookie_headers(): void',
    "header_remove( 'Set-Cookie' );",
    "function metis_router_handle_core_asset_request( Metis_Http_Request \$request ): Metis_Http_Response {\n    metis_router_suppress_session_cookie_headers();",
    "function metis_router_handle_module_asset_request( Metis_Http_Request \$request ): Metis_Http_Response {\n    metis_router_suppress_session_cookie_headers();",
    "function metis_router_handle_runtime_asset_request( Metis_Http_Request \$request ): Metis_Http_Response {\n    metis_register_core_services();\n    metis_router_suppress_session_cookie_headers();",
    "function metis_router_handle_svg_icon_request( Metis_Http_Request \$request ): Metis_Http_Response {\n    metis_router_suppress_session_cookie_headers();",
];

$missing = [];
foreach ( $expected as $needle ) {
    if ( ! str_contains( $router_runtime, $needle ) ) {
        $missing[] = $needle;
    }
}

if ( $missing !== [] ) {
    fwrite( STDERR, "Missing routed asset cookie suppression contract:\n" . implode( "\n", $missing ) . "\n" );
    exit( 1 );
}

fwrite( STDOUT, "Routed asset session cookie suppression contract checks passed.\n" );
