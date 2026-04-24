<?php
if (!defined('METIS_ROOT')) exit;

/**
 * MWTOOLS Assets (single, predictable pipeline)
 * - Loads core assets from /assets/core.css and /assets/core.js
 * - Then module assets via the routed /assets/modules/{module}/{file} endpoint
 */

metis_on('metis_assets_enqueue', function () {
    $asset_version = defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : '1.0.0';
    $asset_base_url = defined( 'METIS_URL' ) ? (string) METIS_URL : metis_trailingslashit( metis_home_url( '/' ) );
    $domain = metis_key_clean( (string) metis_get_query_var( 'metis_domain' ) );
    $view   = metis_key_clean( (string) metis_get_query_var( 'metis_view' ) );
    $is_dashboard_view = $view === 'dashboard' || str_ends_with( $view, '_dashboard' ) || str_ends_with( $view, '-dashboard' );
    $help_enabled = true;
    $walkthrough_enabled = true;

    $is_portal_request = function_exists('metis_is_portal_request') && metis_is_portal_request();

    if ( defined( 'METIS_STANDALONE' ) && METIS_STANDALONE ) {
        $is_portal_request = metis_get_query_var( 'metis_domain' ) !== '' || metis_get_query_var( 'metis_view' ) !== '';
    }

    if ( ! $is_portal_request ) {
        return;
    }

    // Core CSS/JS (NOTE: no /css/ subfolder)
    metis_runtime_register_style_alias(
        'metis-runtime-theme',
        metis_runtime_asset_url(
            'theme.css',
            metis_key_clean( (string) metis_get_query_var( 'metis_domain' ) ),
            metis_key_clean( (string) metis_get_query_var( 'metis_view' ) )
        ),
        [],
        $asset_version
    );

    metis_runtime_register_script_alias(
        'metis-runtime-bootstrap',
        metis_runtime_asset_url(
            'bootstrap.js',
            metis_key_clean( (string) metis_get_query_var( 'metis_domain' ) ),
            metis_key_clean( (string) metis_get_query_var( 'metis_view' ) )
        ),
        ['jquery'],
        $asset_version,
        false
    );

    metis_runtime_register_style_alias(
        'metis-core',
        $asset_base_url . 'assets/core.css',
        [],
        $asset_version
    );

    metis_runtime_register_script_alias(
        'metis-core',
        $asset_base_url . 'assets/core.js',
        ['jquery', 'metis-runtime-bootstrap'],
        $asset_version,
        true
    );

    metis_runtime_register_style_alias(
        'metis-kpi-cards',
        $asset_base_url . 'assets/components/kpi-cards.css',
        [ 'metis-core' ],
        $asset_version
    );

    metis_runtime_register_script_alias(
        'metis-kpi-cards',
        $asset_base_url . 'assets/components/kpi-cards.js',
        [ 'metis-core' ],
        $asset_version,
        true
    );

    metis_runtime_register_script_alias(
        'metis-help-panel',
        $asset_base_url . 'assets/js/help/help-panel.js',
        [ 'metis-core' ],
        $asset_version,
        true
    );

    metis_runtime_register_script_alias(
        'metis-help-search',
        $asset_base_url . 'assets/js/help/help-search.js',
        [ 'metis-core', 'metis-help-panel' ],
        $asset_version,
        true
    );

    metis_runtime_register_script_alias(
        'metis-walkthrough-ui',
        $asset_base_url . 'assets/js/help/walkthrough.js',
        [ 'metis-core' ],
        $asset_version,
        true
    );

    metis_runtime_register_script_alias(
        'metis-help-ui',
        $asset_base_url . 'assets/js/help/help.js',
        [ 'metis-core', 'metis-help-panel', 'metis-help-search', 'metis-walkthrough-ui' ],
        $asset_version,
        true
    );

    metis_runtime_enqueue_script( 'jquery' );
    metis_runtime_enqueue_style( 'metis-runtime-theme' );
    metis_runtime_enqueue_style('metis-core');
    metis_runtime_enqueue_script( 'metis-runtime-bootstrap' );
    metis_runtime_enqueue_script('metis-core');
    if ( $is_dashboard_view ) {
        metis_runtime_enqueue_style( 'metis-kpi-cards' );
        metis_runtime_enqueue_script( 'metis-kpi-cards' );
    }

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
            $value = is_array( $saved_theme ) ? metis_hex_color_clean( (string) ( $saved_theme[ $key ] ?? '' ) ) : '';
            if ( ! $value || strtolower( $value ) === strtolower( (string) $field['default'] ) ) {
                continue;
            }

            $theme_lines[] = sprintf( '%s: %s;', (string) $field['css_var'], $value );
        }

        if ( ! empty( $theme_lines ) ) {
            metis_runtime_add_inline_style( 'metis-core', ":root {
" . implode( "
", $theme_lines ) . "
}" );
        }
    }

    // Core AJAX vars — available to all modules including the global code search.
    // Modules may overwrite metisAjax with their own nonce via localized inline config;
    // the code-search handler uses the 'metis_core' nonce and falls back gracefully.
    $session_data = [
        'authenticated' => function_exists( 'metis_user_logged_in' ) ? (bool) metis_user_logged_in() : false,
        'idle_ttl' => function_exists( 'metis_auth_session_idle_ttl_seconds' ) ? (int) metis_auth_session_idle_ttl_seconds() : 1800,
        'absolute_ttl' => function_exists( 'metis_auth_session_absolute_ttl_seconds' ) ? (int) metis_auth_session_absolute_ttl_seconds() : 43200,
        'issued_at' => (int) ( $_SESSION['metis_auth_issued_at'] ?? 0 ),
        'last_activity_at' => (int) ( $_SESSION['metis_auth_last_activity_at'] ?? 0 ),
        'keepalive_url' => function_exists( 'metis_auth_session_keepalive_url' ) ? (string) metis_auth_session_keepalive_url() : metis_home_url( '/api/auth/session/keepalive' ),
        'login_url' => function_exists( 'metis_auth_login_url' ) ? (string) metis_auth_login_url() : metis_home_url( '/login' ),
    ];

    metis_runtime_localize_script( 'metis-core', 'metisAjax', [
        'ajax_url'      => metis_ajax_endpoint_url(),
        'nonce'         => metis_runtime_create_nonce( 'metis_core' ),
        'action_nonces' => metis_ajax_action_nonces(),
        'site_url'      => function_exists( 'metis_home_url' ) ? rtrim( (string) metis_home_url( '/' ), '/' ) : rtrim( (string) metis_home_url(), '/' ),
        'module_boot_failures' => function_exists( 'metis_module_boot_failures' ) ? metis_module_boot_failures() : [],
        'session' => $session_data,
    ] );

    if ( function_exists( 'metis_quick_actions_service' ) ) {
        metis_runtime_localize_script( 'metis-core', 'metisQuickActions', [
            'actions' => metis_quick_actions_service()->available(),
        ] );
    }

        $help_payload = [
        'enabled' => true,
        'walkthrough_enabled' => true,
        'current_topic' => '',
        'current_domain' => '',
        'current_view' => '',
        'docs_base_url' => rtrim( $asset_base_url, '/' ),
        'ajax_url' => metis_ajax_endpoint_url(),
        'nonce' => metis_runtime_create_nonce( 'metis_core' ),
        'action_nonces' => [
            'metis_help_index' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_help_index' ) ),
            'metis_help_topic' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_help_topic' ) ),
            'metis_help_search' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_help_search' ) ),
            'metis_walkthrough_get' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_walkthrough_get' ) ),
            'metis_walkthrough_progress' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_walkthrough_progress' ) ),
        ],
    ];

    if ( function_exists( 'metis_help_service' ) ) {
        $help_service = metis_help_service();
        if ( $help_service instanceof Metis_Help_Service ) {
            $help_enabled = $help_service->enabled();
            $walkthrough_enabled = $help_service->walkthrough_enabled();
            $help_payload = array_merge(
                $help_payload,
                $help_service->bootstrap_payload(
                    metis_key_clean( (string) metis_get_query_var( 'metis_domain' ) ),
                    metis_key_clean( (string) metis_get_query_var( 'metis_view' ) )
                )
            );
        }
    }

    if ( $help_enabled ) {
        metis_runtime_enqueue_script( 'metis-help-panel' );
        metis_runtime_enqueue_script( 'metis-help-search' );
        if ( $walkthrough_enabled ) {
            metis_runtime_enqueue_script( 'metis-walkthrough-ui' );
        }
        metis_runtime_enqueue_script( 'metis-help-ui' );
        metis_runtime_localize_script( 'metis-help-ui', 'metisHelp', $help_payload );
    }

    if ( function_exists( 'metis_get_module' ) && is_array( metis_get_module( 'hermes' ) ) && function_exists( 'metis_hermes_can_view' ) && metis_hermes_can_view() ) {
        $hermes_style_handle  = 'metis-hermes-global';
        $hermes_script_handle = 'metis-hermes-global';
        $user_name = 'Metis User';
        $user_avatar_url = metis_avatar_url( $user_name, '', 96 );

        if ( function_exists( 'metis_profile_current_person' ) ) {
            $person = metis_profile_current_person();
            if ( is_array( $person ) ) {
                $person_avatar_key = (string) ( $person['pid'] ?? '' );
                $user_name = trim( (string) ( $person['display_name'] ?? '' ) );
                if ( $user_name === '' ) {
                    $user_name = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
                }
                $user_avatar_url = metis_avatar_url( $user_name !== '' ? $user_name : 'Metis User', (string) ( $person['avatar_url'] ?? '' ), 96, $person_avatar_key );
            }
        }

        metis_runtime_enqueue_style(
            $hermes_style_handle,
            metis_module_asset_url( 'hermes', 'hermes.css' ),
            [ 'metis-core' ],
            $asset_version
        );

        metis_runtime_enqueue_script(
            $hermes_script_handle,
            metis_module_asset_url( 'hermes', 'hermes.js' ),
            [ 'metis-core' ],
            $asset_version,
            true
        );

        metis_runtime_localize_script( $hermes_script_handle, 'metisHermesAjax', [
            'ajax_url' => metis_ajax_endpoint_url(),
            'nonce' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_hermes_query' ) ),
            'action_nonces' => metis_ajax_action_nonces(),
            'dashboard_url' => function_exists( 'metis_portal_url' ) ? metis_portal_url( 'hermes', 'dashboard' ) : '',
            'avatar_url' => metis_module_asset_url( 'hermes', 'hermes.png' ),
            'user_avatar_url' => $user_avatar_url,
        ] );
    }

    if ( function_exists( 'metis_accessibility_settings' ) ) {
        $accessibility = metis_accessibility_settings();
        $profile_payload = [];

        foreach ( (array) ( $accessibility['profiles'] ?? [] ) as $slug => $profile ) {
            $profile_payload[ $slug ] = [
                'label' => (string) ( $profile['label'] ?? ucfirst( str_replace( '-', ' ', (string) $slug ) ) ),
                'preferences' => (array) ( $profile['preferences'] ?? [] ),
            ];
        }

        metis_runtime_localize_script( 'metis-core', 'metisAccessibility', [
            'toolbarEnabled' => ! empty( $accessibility['toolbar_enabled'] ),
            'allowOverrides' => ! empty( $accessibility['allow_overrides'] ),
            'defaultProfile' => (string) ( $accessibility['default_profile'] ?? 'none' ),
            'storageKey' => (string) ( $accessibility['storage_key'] ?? 'metis-accessibility-preferences' ),
            'profiles' => $profile_payload,
        ] );
    }

    if (!$domain || !$view) {
        // template_redirect sets these; if missing, still allow core assets
        return;
    }

    if ( $view === 'editor' ) {
        $simple_style_handle = 'metis-editor-simple';
        $simple_script_handle = 'metis-editor-simple';

        metis_runtime_enqueue_style(
            $simple_style_handle,
            $asset_base_url . 'assets/js/editor/simple-editor.css',
            [ 'metis-core' ],
            (string) @filemtime( METIS_ROOT . '/assets/js/editor/simple-editor.css' ) ?: $asset_version
        );

        metis_runtime_enqueue_script(
            $simple_script_handle,
            $asset_base_url . 'assets/js/editor/simple-editor.js',
            [ 'metis-core' ],
            (string) @filemtime( METIS_ROOT . '/assets/js/editor/simple-editor.js' ) ?: $asset_version,
            true
        );

        metis_runtime_localize_script( $simple_script_handle, 'metisWebsiteAjax', [
            'ajax_url' => metis_ajax_endpoint_url(),
            'nonce' => metis_runtime_create_nonce( 'metis_website' ),
            'action_nonces' => metis_ajax_action_nonces(),
            'media_library_url' => function_exists( 'metis_portal_url' ) ? metis_portal_url( 'media', 'library' ) : '',
        ] );

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
            $handle = 'metis-' . $slug . '-' . metis_slug_clean($css);
            $css_file = metis_trailingslashit( (string) ( $module['dir'] ?? '' ) ) . 'assets/' . ltrim( (string) $css, '/' );
            $css_ver = is_file( $css_file ) ? (string) filemtime( $css_file ) : $asset_version;

            metis_runtime_enqueue_style(
                $handle,
                metis_module_asset_url( $slug, (string) $css ),
                ['metis-core'],
                $css_ver
            );
        }
    }

    // JS
    if (!empty($cfg['assets']['js']) && is_array($cfg['assets']['js'])) {
        foreach ($cfg['assets']['js'] as $js) {
            $handle = 'metis-' . $slug . '-' . metis_slug_clean($js);
            $js_file = metis_trailingslashit( (string) ( $module['dir'] ?? '' ) ) . 'assets/' . ltrim( (string) $js, '/' );
            $js_ver = is_file( $js_file ) ? (string) filemtime( $js_file ) : $asset_version;

            metis_runtime_enqueue_script(
                $handle,
                metis_module_asset_url( $slug, (string) $js ),
                ['metis-core'],
                $js_ver,
                true
            );

            // Localize module AJAX vars — use nonce_action from JSON config if defined,
            // otherwise fall back to the core nonce already set by the core enqueue above.
            $nonce_action = (string) ( $cfg['assets']['nonce_action'] ?? '' );
            $ajax_object  = (string) ( $cfg["assets"]["ajax_object"] ?? $cfg["ajax_object"] ?? "metisAjax" );
            if ( $nonce_action !== '' ) {
                $payload = [
                    'ajax_url' => metis_ajax_endpoint_url(),
                    'nonce'    => metis_runtime_create_nonce( $nonce_action ),
                    'action_nonces' => metis_ajax_action_nonces(),
                ];

                if ( $slug === 'website' && function_exists( 'metis_portal_url' ) ) {
                    $payload['media_library_url'] = metis_portal_url( 'media', 'library' );
                }

                metis_runtime_localize_script( $handle, $ajax_object, $payload );
            }
        }
    }

});
