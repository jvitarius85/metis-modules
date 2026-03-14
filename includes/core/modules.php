<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Metis' ) ) {
    require_once __DIR__ . '/bootstrap.php';
    metis_core_bootstrap( 'service_registry' );
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

    Metis_Logger::module_registered( $slug );
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

metis_add_action( 'wp_enqueue_scripts', function () {

    $domain = get_query_var( 'metis_domain' );
    $view   = get_query_var( 'metis_view' );

    if ( ! $domain || ! $view ) return;

    $modules = metis_get_modules();
    if ( empty( $modules[ $domain ] ) ) return;

    $module = $modules[ $domain ];
    $cfg    = $module['config'];
    $slug   = $module['slug'];

    if ( ! empty( $cfg['assets']['css'] ) && is_array( $cfg['assets']['css'] ) ) {
        foreach ( $cfg['assets']['css'] as $css ) {
            $handle   = "metis-{$slug}-" . sanitize_title( $css );
            $css_file = trailingslashit( $module['dir'] ) . 'assets/' . ltrim( (string) $css, '/' );
            $css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : METIS_VERSION;
            metis_enqueue_style(
                $handle,
                metis_module_asset_url( $slug, (string) $css ),
                [ 'metis-core' ],
                $css_ver
            );
        }
    }

    if ( ! empty( $cfg['assets']['js'] ) && is_array( $cfg['assets']['js'] ) ) {
        foreach ( $cfg['assets']['js'] as $js ) {
            $handle  = "metis-{$slug}-" . sanitize_title( $js );
            $js_file = trailingslashit( $module['dir'] ) . 'assets/' . ltrim( (string) $js, '/' );
            $js_ver  = file_exists( $js_file ) ? (string) filemtime( $js_file ) : METIS_VERSION;
            metis_enqueue_script(
                $handle,
                metis_module_asset_url( $slug, (string) $js ),
                [ 'metis-core' ],
                $js_ver,
                true
            );

            $ajax_object = $cfg['ajax_object'] ?? ( $slug === 'donations' ? 'metisAjax' : null );

            if ( $ajax_object ) {
                metis_localize_script( $handle, $ajax_object, [
                    'ajax_url'       => metis_ajax_endpoint_url(),
                    'nonce'          => metis_create_nonce( "metis_{$slug}" ),
                    'action_nonces'  => metis_ajax_action_nonces(),
                ] );
            }
        }
    }

}, 20 );

metis_add_action( 'init', function () {
    foreach ( metis_get_modules() as $module ) {
        $cfg  = $module['config'];
        $dir  = $module['dir'];
        $slug = $module['slug'];

        if ( empty( $cfg['assets']['ajax'] ) ) continue;

        $ajax_file = trailingslashit( $dir ) . 'assets/' . $cfg['assets']['ajax'];

        if ( file_exists( $ajax_file ) ) {
            if ( function_exists( 'metis_security_trusted_include' ) ) {
                metis_security_trusted_include( $ajax_file );
            } else {
                require_once $ajax_file;
            }
            Metis_Logger::info( "AJAX controller definitions loaded: {$slug}" );
        } else {
            Metis_Logger::warn( "Missing AJAX controller definitions for module: {$slug}", [ 'expected' => $ajax_file ] );
        }
    }
} );
