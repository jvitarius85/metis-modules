<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! class_exists( 'Metis' ) ) {
    require_once dirname( __DIR__ ) . '/CoreBootstrap.php';
    metis_core_bootstrap( 'service_registry' );
}

if ( ! class_exists( 'Metis_Logger' ) ) {
    metis_core_bootstrap( 'log' );
}

/**
 * Metis Module Registry & Loader
 *
 * Backwards-compatible function API backed by the centralized service registry.
 */

metis_register_core_services();

function metis_register_module( string $slug, string $dir, array $config ): void {
    $service = Metis::service( 'modules' );
    $service->register( $slug, $dir, $config );

    $module = $service->get( $slug );
    if ( $module === null ) {
        return;
    }

    if ( function_exists( 'metis_security_register_module_policies' ) ) {
        metis_security_register_module_policies( $slug, $config );
    }

    if ( class_exists( 'Metis_Logger' ) ) {
        Metis_Logger::module_registered( $slug );
    }
}

function metis_get_modules(): array {
    return Metis::service( 'modules' )->all();
}

function metis_get_module( string $slug ): ?array {
    return Metis::service( 'modules' )->get( $slug );
}

function metis_boot_modules(): void {
    Metis::service( 'modules' )->boot();
}

metis_boot_modules();

function metis_resolve_view( string $domain, string $view ): array {
    return Metis::service( 'modules' )->resolve_view( $domain, $view );
}

metis_on( 'init', function () {
    foreach ( metis_get_modules() as $module ) {
        $cfg  = $module['config'];
        $dir  = $module['dir'];
        $slug = $module['slug'];

        if ( empty( $cfg['assets']['ajax'] ) ) continue;

        $ajax_file = metis_trailingslashit( $dir ) . 'assets/' . $cfg['assets']['ajax'];

        if ( file_exists( $ajax_file ) ) {
            if ( function_exists( 'metis_security_trusted_include' ) ) {
                metis_security_trusted_include( $ajax_file );
            } else {
                require_once $ajax_file;
            }
            if ( class_exists( 'Metis_Logger' ) ) {
                Metis_Logger::info( "AJAX controller definitions loaded: {$slug}" );
            }
        } else {
            if ( class_exists( 'Metis_Logger' ) ) {
                Metis_Logger::warn( "Missing AJAX controller definitions for module: {$slug}", [ 'expected' => $ajax_file ] );
            }
        }
    }
} );
