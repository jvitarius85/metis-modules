<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_website_editor_required_nonce_actions' ) ) {
    function metis_website_editor_required_nonce_actions( bool $include_preview = false ): array {
        $actions = [
            'metis_website_page_get',
            'metis_website_page_create',
            'metis_website_page_save',
            'metis_website_post_get',
            'metis_website_post_create',
            'metis_website_post_save',
            'metis_website_editor_properties_options',
            'metis_website_editor_media_upload',
            'metis_website_editor_lock',
            'metis_website_editor_revisions_list',
            'metis_website_editor_revision_restore',
            'metis_website_reusable_blocks_list',
            'metis_website_reusable_block_get',
            'metis_website_reusable_block_save',
        ];

        if ( $include_preview ) {
            $actions[] = 'metis_editor_render_preview';
        }

        return $actions;
    }
}

if ( ! function_exists( 'metis_website_build_editor_bootstrap' ) ) {
    function metis_website_build_editor_bootstrap( array $args = [] ): array {
        $editor_new = trim( (string) ( $args['editor_new'] ?? '' ) );
        $editor_key = trim( (string) ( $args['editor_key'] ?? '' ) );
        $editor_nonce = '';

        if ( function_exists( 'metis_runtime_create_nonce' ) ) {
            $editor_nonce = (string) metis_runtime_create_nonce( 'metis_website' );
            if ( $editor_nonce === '' ) {
                $editor_nonce = (string) metis_runtime_create_nonce( 'metis_core' );
            }
        }

        $required_actions = metis_website_editor_required_nonce_actions( ! empty( $args['include_preview'] ) );
        $editor_action_nonces = [];
        if ( function_exists( 'metis_ajax_action_nonces' ) ) {
            $runtime_action_nonces = metis_ajax_action_nonces();
            foreach ( $required_actions as $nonce_action ) {
                $editor_action_nonces[ $nonce_action ] = isset( $runtime_action_nonces[ $nonce_action ] ) ? (string) $runtime_action_nonces[ $nonce_action ] : '';
            }
        } elseif ( function_exists( 'metis_runtime_create_nonce' ) ) {
            foreach ( $required_actions as $nonce_action ) {
                $editor_action_nonces[ $nonce_action ] = (string) metis_runtime_create_nonce( 'metis_ajax:' . $nonce_action );
            }
        }

        if ( $editor_nonce === '' ) {
            foreach ( $editor_action_nonces as $nonce_value ) {
                if ( is_string( $nonce_value ) && trim( $nonce_value ) !== '' ) {
                    $editor_nonce = trim( $nonce_value );
                    break;
                }
            }
        }

        $theme_defaults = [
            'metis_primary' => '#485bc7',
            'metis_primary_dark' => '#3246a7',
            'metis_accent' => '#ff7542',
            'metis_bg' => '#f5f6fa',
            'metis_surface' => '#ffffff',
            'metis_border' => '#e0e2ea',
            'metis_text' => '#1f2330',
            'metis_text_muted' => '#6d7485',
            'metis_header_bg' => '#eceeff',
            'metis_row_odd_bg' => '#ffffff',
            'metis_row_even_bg' => '#f8f9fd',
            'metis_row_hover_bg' => '#eef2ff',
            'metis_sidebar_bg' => '#16192b',
            'metis_sidebar_icon_color' => '#7a82a6',
            'metis_sidebar_active_color' => '#a8b4ff',
        ];
        $theme_saved = class_exists( 'Core_Settings_Service' ) ? \Core_Settings_Service::get( 'theme_colors', [] ) : [];
        $theme_css_lines = [];
        foreach ( $theme_defaults as $token_key => $token_default ) {
            $raw = is_array( $theme_saved ) ? (string) ( $theme_saved[ $token_key ] ?? $token_default ) : $token_default;
            $hex = metis_hex_color_clean( $raw ) ?: $token_default;
            $css_var = '--' . str_replace( '_', '-', $token_key );
            $theme_css_lines[] = $css_var . ': ' . $hex . ';';
        }

        $simple_editor_css = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/assets/js/editor/simple-editor.css' ) : '/assets/js/editor/simple-editor.css';
        $simple_editor_js  = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/assets/js/editor/simple-editor.js' ) : '/assets/js/editor/simple-editor.js';
        $simple_editor_css_version = (string) @filemtime( METIS_ASSETS_PATH . 'js/editor/simple-editor.css' );
        $simple_editor_js_version = (string) @filemtime( METIS_ASSETS_PATH . 'js/editor/simple-editor.js' );

        if ( $simple_editor_css_version !== '' ) {
            $simple_editor_css .= ( strpos( $simple_editor_css, '?' ) === false ? '?' : '&' ) . 'v=' . rawurlencode( $simple_editor_css_version );
        }
        if ( $simple_editor_js_version !== '' ) {
            $simple_editor_js .= ( strpos( $simple_editor_js, '?' ) === false ? '?' : '&' ) . 'v=' . rawurlencode( $simple_editor_js_version );
        }

        $editor_action_nonces_json = function_exists( 'metis_json_encode' )
            ? (string) metis_json_encode( $editor_action_nonces )
            : (string) json_encode( $editor_action_nonces, JSON_UNESCAPED_SLASHES );

        return [
            'editor_boot_key' => $editor_new !== '' ? '' : $editor_key,
            'editor_nonce' => $editor_nonce,
            'editor_action_nonces_json' => $editor_action_nonces_json,
            'editor_theme_css' => '#metis-simple-editor-root{' . implode( '', $theme_css_lines ) . '}',
            'simple_editor_css' => $simple_editor_css,
            'simple_editor_js' => $simple_editor_js,
            'can_edit' => function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'website.edit' ) : false,
            'can_publish' => function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'website.publish' ) : false,
            'can_create' => function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'website.create' ) : false,
        ];
    }
}

if ( ! function_exists( 'metis_website_render_editor_bootstrap' ) ) {
    function metis_website_render_editor_bootstrap( array $args = [] ): void {
        $payload = metis_website_build_editor_bootstrap( $args );
        ?>
        <link rel="stylesheet" href="<?php echo metis_escape_attr( $payload['simple_editor_css'] ); ?>">
        <style id="metis-simple-editor-theme-vars"><?php echo metis_escape_html( $payload['editor_theme_css'] ); ?></style>
        <div id="metis-editor-inline-root"></div>
        <div
            id="metis-editor-bootstrap"
            data-editor-key="<?php echo metis_escape_attr( (string) $payload['editor_boot_key'] ); ?>"
            data-editor-new="<?php echo metis_escape_attr( (string) ( $args['editor_new'] ?? '' ) ); ?>"
            data-editor-id="<?php echo metis_escape_attr( (string) ( $args['editor_id'] ?? 0 ) ); ?>"
            data-editor-nonce="<?php echo metis_escape_attr( (string) $payload['editor_nonce'] ); ?>"
            data-editor-action-nonces="<?php echo metis_escape_attr( (string) $payload['editor_action_nonces_json'] ); ?>"
            data-editor-context="<?php echo metis_escape_attr( (string) ( $args['editor_context'] ?? 'website' ) ); ?>"
            data-editor-kind="<?php echo metis_escape_attr( (string) ( $args['editor_kind'] ?? '' ) ); ?>"
            data-editor-page-id="<?php echo metis_escape_attr( (string) ( $args['editor_page_id'] ?? 0 ) ); ?>"
            data-editor-post-id="<?php echo metis_escape_attr( (string) ( $args['editor_post_id'] ?? 0 ) ); ?>"
            data-editor-can-edit="<?php echo ! empty( $payload['can_edit'] ) ? '1' : '0'; ?>"
            data-editor-can-publish="<?php echo ! empty( $payload['can_publish'] ) ? '1' : '0'; ?>"
            data-editor-can-create="<?php echo ! empty( $payload['can_create'] ) ? '1' : '0'; ?>"
        ></div>
        <div id="metis-editor-boot-status" class="metis-editor-boot-status">
            <div class="metis-editor-boot-card">
                <div class="metis-editor-boot-title">Loading Editor</div>
                <div class="metis-editor-boot-copy">Preparing structured editor...</div>
            </div>
        </div>
        <script src="<?php echo metis_escape_attr( $payload['simple_editor_js'] ); ?>"></script>
        <?php
    }
}
