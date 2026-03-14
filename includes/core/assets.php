<?php
if (!defined('ABSPATH')) exit;

/**
 * MWTOOLS Assets (single, predictable pipeline)
 * - Loads core assets from /assets/core.css and /assets/core.js
 * - Then module assets via the routed /assets/modules/{module}/{file} endpoint
 */

metis_add_action('wp_enqueue_scripts', function () {

    $is_portal_request = function_exists('metis_is_portal_request') && metis_is_portal_request();

    if ( defined( 'METIS_STANDALONE' ) && METIS_STANDALONE ) {
        $is_portal_request = get_query_var( 'metis_domain' ) !== '' || get_query_var( 'metis_view' ) !== '';
    }

    if ( ! $is_portal_request ) {
        return;
    }

    // Core CSS/JS (NOTE: no /css/ subfolder)
    metis_register_style(
        'metis-core',
        METIS_URL . 'assets/core.css',
        [],
        METIS_VERSION
    );

    metis_register_script(
        'metis-core',
        METIS_URL . 'assets/core.js',
        ['jquery'],
        METIS_VERSION,
        true
    );

    metis_enqueue_script( 'jquery' );
    metis_enqueue_style('metis-core');
    metis_enqueue_script('metis-core');

    if ( class_exists( 'Core_Settings_Service' ) ) {
        $theme_defaults = [
            'mw_primary' => [ 'css_var' => '--mw-primary', 'default' => '#485bc7' ],
            'mw_primary_dark' => [ 'css_var' => '--mw-primary-dark', 'default' => '#3246a7' ],
            'mw_accent' => [ 'css_var' => '--mw-accent', 'default' => '#ff7542' ],
            'mw_bg' => [ 'css_var' => '--mw-bg', 'default' => '#f5f6fa' ],
            'mw_surface' => [ 'css_var' => '--mw-surface', 'default' => '#ffffff' ],
            'mw_border' => [ 'css_var' => '--mw-border', 'default' => '#e0e2ea' ],
            'mw_text' => [ 'css_var' => '--mw-text', 'default' => '#1f2330' ],
            'mw_text_muted' => [ 'css_var' => '--mw-text-muted', 'default' => '#6d7485' ],
            'mw_header_bg' => [ 'css_var' => '--mw-header-bg', 'default' => '#eceeff' ],
            'mw_row_odd_bg' => [ 'css_var' => '--mw-row-odd-bg', 'default' => '#ffffff' ],
            'mw_row_even_bg' => [ 'css_var' => '--mw-row-even-bg', 'default' => '#f8f9fd' ],
            'mw_row_hover_bg' => [ 'css_var' => '--mw-row-hover-bg', 'default' => '#eef2ff' ],
            'mw_sidebar_bg' => [ 'css_var' => '--mw-sidebar-bg', 'default' => '#16192b' ],
            'mw_sidebar_icon_color' => [ 'css_var' => '--mw-sidebar-icon-color', 'default' => '#7a82a6' ],
            'mw_sidebar_active_color' => [ 'css_var' => '--mw-sidebar-active-color', 'default' => '#a8b4ff' ],
        ];

        $saved_theme = Core_Settings_Service::get( 'theme_colors', [] );
        $theme_lines = [];

        foreach ( $theme_defaults as $key => $field ) {
            $value = is_array( $saved_theme ) ? sanitize_hex_color( (string) ( $saved_theme[ $key ] ?? '' ) ) : '';
            if ( ! $value || strtolower( $value ) === strtolower( (string) $field['default'] ) ) {
                continue;
            }

            $theme_lines[] = sprintf( '%s: %s;', (string) $field['css_var'], $value );
        }

        if ( ! empty( $theme_lines ) ) {
            metis_add_inline_style( 'metis-core', ":root {
" . implode( "
", $theme_lines ) . "
}" );
        }
    }

    // Core AJAX vars — available to all modules including the global code search.
    // Modules may overwrite metisAjax with their own nonce via wp_localize_script;
    // the code-search handler uses the 'metis_core' nonce and falls back gracefully.
    metis_localize_script( 'metis-core', 'metisAjax', [
        'ajax_url' => metis_ajax_endpoint_url(),
        'nonce'    => metis_create_nonce( 'metis_core' ),
        'action_nonces' => metis_ajax_action_nonces(),
    ] );

    // Module assets
$domain = sanitize_key(get_query_var('metis_domain'));
$view   = sanitize_key(get_query_var('metis_view'));

    if (!$domain || !$view) {
        // template_redirect sets these; if missing, still allow core assets
        return;
    }

    if (!function_exists('metis_get_modules')) return;

    $modules = metis_get_modules();
    if (empty($modules[$domain])) return;

    $module = $modules[$domain];
    $cfg    = $module['config'] ?? [];
    $slug   = $module['slug'] ?? $domain;

    // CSS
    if (!empty($cfg['assets']['css']) && is_array($cfg['assets']['css'])) {
        foreach ($cfg['assets']['css'] as $css) {
            $handle = 'metis-' . $slug . '-' . sanitize_title($css);

            metis_enqueue_style(
                $handle,
                metis_module_asset_url( $slug, (string) $css ),
                ['metis-core'],
                METIS_VERSION
            );
        }
    }

    // JS
    if (!empty($cfg['assets']['js']) && is_array($cfg['assets']['js'])) {
        foreach ($cfg['assets']['js'] as $js) {
            $handle = 'metis-' . $slug . '-' . sanitize_title($js);

            metis_enqueue_script(
                $handle,
                metis_module_asset_url( $slug, (string) $js ),
                ['metis-core'],
                METIS_VERSION,
                true
            );

            // Localize module AJAX vars — use nonce_action from JSON config if defined,
            // otherwise fall back to the core nonce already set by the core enqueue above.
            $nonce_action = (string) ( $cfg['assets']['nonce_action'] ?? '' );
            $ajax_object  = (string) ( $cfg['ajax_object'] ?? 'metisAjax' );
            if ( $nonce_action !== '' ) {
                metis_localize_script( $handle, $ajax_object, [
                    'ajax_url' => metis_ajax_endpoint_url(),
                    'nonce'    => metis_create_nonce( $nonce_action ),
                    'action_nonces' => metis_ajax_action_nonces(),
                ] );
            }
        }
    }

});
