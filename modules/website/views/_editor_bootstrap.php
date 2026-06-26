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
            'metis_website_editor_revision_compare',
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
    function metis_website_editor_icon_picker_library_json(): string {
        static $json = null;
        if ( is_string( $json ) ) {
            return $json;
        }

        $category_for_icon = static function ( string $slug ): string {
            $slug = strtolower( trim( $slug ) );
            if ( $slug === '' ) {
                return 'general';
            }

            $explicit = [
                'accounting' => 'finance',
                'accessibility' => 'accessibility',
                'add' => 'ui',
                'android' => 'brands',
                'animals' => 'animals',
                'apple' => 'brands',
                'apple-ios' => 'brands',
                'campfire' => 'activities',
                'bat' => 'animals',
                'braille-blind' => 'accessibility',
                'briefcase' => 'people',
                'cards' => 'activities',
                'calendar' => 'actions',
                'dice' => 'activities',
                'chart-scatter' => 'analytics',
                'checkbox' => 'ui',
                'checkbox-checked' => 'ui',
                'checkbox-checked-filled' => 'ui',
                'checkbox-indeterminate' => 'ui',
                'checkbox-indeterminate-filled' => 'ui',
                'checkmark-filled' => 'ui',
                'checkmark-outline' => 'ui',
                'close-filled' => 'ui',
                'close-outline' => 'ui',
                'refresh' => 'ui',
                'coffee' => 'food-drinks',
                'contacts' => 'people',
                'credit-card' => 'finance',
                'croissant' => 'food-drinks',
                'database' => 'infrastructure',
                'diagram-alt' => 'analytics',
                'diagram' => 'analytics',
                'disability-ramp' => 'accessibility',
                'divider' => 'ui',
                'donut' => 'food-drinks',
                'drive' => 'files',
                'drink' => 'food-drinks',
                'emoji' => 'ui',
                'facebook' => 'brands',
                'fries' => 'food-drinks',
                'generate-pdf' => 'files',
                'globe' => 'communication',
                'google' => 'brands',
                'grid' => 'ui',
                'h1' => 'editor',
                'heat-map' => 'analytics',
                'hiking' => 'activities',
                'invite' => 'communication',
                'italic' => 'editor',
                'instagram' => 'brands',
                'list' => 'editor',
                'list-boxes' => 'editor',
                'list-bulleted' => 'editor',
                'list-checked' => 'editor',
                'list-dropdown' => 'editor',
                'loading-circle' => 'status',
                'meeting' => 'people',
                'module' => 'ui',
                'microsoft' => 'brands',
                'movie' => 'activities',
                'muffin' => 'food-drinks',
                'need' => 'donations',
                'notification' => 'communication',
                'pie' => 'food-drinks',
                'pie-slice' => 'food-drinks',
                'pizza' => 'food-drinks',
                'puzzle' => 'activities',
                'radio-button' => 'ui',
                'radio-button-checked' => 'ui',
                'redo' => 'ui',
                'reward' => 'donations',
                'burger' => 'food-drinks',
                'burrito' => 'food-drinks',
                'cake' => 'food-drinks',
                'cat' => 'animals',
                'jenga' => 'activities',
                'cupcake' => 'food-drinks',
                'dog' => 'animals',
                'paw-print' => 'animals',
                'pig' => 'animals',
                'sankey-diagram' => 'analytics',
                'sankey-diagram-alt' => 'analytics',
                'shield-cross' => 'health',
                'sign-language' => 'accessibility',
                'stripe' => 'brands',
                'sushi' => 'food-drinks',
                'taco' => 'food-drinks',
                'undo' => 'ui',
                'vote' => 'activities',
                'website' => 'layout',
                'youtube' => 'brands',
            ];
            if ( isset( $explicit[ $slug ] ) ) {
                return $explicit[ $slug ];
            }

            $contains_any = static function ( string $value, array $needles ): bool {
                foreach ( $needles as $needle ) {
                    if ( $needle !== '' && str_contains( $value, (string) $needle ) ) {
                        return true;
                    }
                }

                return false;
            };

            if ( $contains_any( $slug, [ 'accessibility', 'braille', 'blind', 'sign-language', 'hearing' ] ) ) return 'accessibility';
            if ( str_contains( $slug, 'checkmark' ) || str_contains( $slug, 'checkbox' ) || str_contains( $slug, 'radio-' ) || str_contains( $slug, 'add-' ) || str_contains( $slug, 'close-' ) || str_contains( $slug, 'collapse' ) || str_contains( $slug, 'expand' ) || in_array( $slug, [ 'add', 'grid', 'emoji', 'divider', 'undo', 'redo' ], true ) ) return 'ui';
            if ( str_starts_with( $slug, 'arrow-' ) || str_starts_with( $slug, 'align-' ) || str_starts_with( $slug, 'distribute-' ) || str_starts_with( $slug, 'next-' ) || str_starts_with( $slug, 'repeat' ) || str_contains( $slug, 'tabs' ) ) return 'layout';
            if ( str_starts_with( $slug, 'text-' ) || str_contains( $slug, 'indent' ) || in_array( $slug, [ 'h1', 'italic' ], true ) || str_contains( $slug, 'list-' ) || str_contains( $slug, 'code' ) || str_contains( $slug, 'data-format' ) || str_contains( $slug, 'data-table' ) ) return 'editor';
            if ( $contains_any( $slug, [ 'accounting', 'finance', 'currency', 'money', 'wallet', 'piggy-bank', 'purchase', 'pricing', 'coin', 'cashing-check', 'credit-card' ] ) ) return 'finance';
            if ( $contains_any( $slug, [ 'donat', 'donor', 'campaign', 'hand-donation', 'handshake', 'reward', 'need' ] ) ) return 'donations';
            if ( $contains_any( $slug, [ 'activity', 'activities', 'camp', 'campfire', 'cards', 'dice', 'game', 'hike', 'hiking', 'jenga', 'movie', 'puzzle', 'vote' ] ) ) return 'activities';
            if ( $contains_any( $slug, [ 'food', 'drink', 'burger', 'burrito', 'cake', 'coffee', 'croissant', 'cupcake', 'donut', 'fries', 'muffin', 'pie', 'pizza', 'sushi', 'taco' ] ) ) return 'food-drinks';
            if ( $contains_any( $slug, [ 'shield', 'padlock', 'lock', 'security', 'fingerprint', 'passkey', 'scan', 'auth', 'license' ] ) ) return 'security';
            if ( $contains_any( $slug, [ 'health', 'medical', 'stethoscope', 'reminder-medical' ] ) ) return 'health';
            if ( $contains_any( $slug, [ 'chart', 'graph', 'heat-map', 'dashboard', 'report', 'progress', 'finance', 'phrase-sentiment' ] ) ) return 'analytics';
            if ( str_starts_with( $slug, 'document' ) || str_starts_with( $slug, 'doc' ) || in_array( $slug, [ 'pdf', 'csv', 'json', 'png', 'ppt', 'raw', 'svg', 'txt', 'xls', 'zip', 'gif', 'mp3', 'mp4', 'mov', 'wmv', 'tif', 'vmdk-disk', 'bat' ], true ) ) return 'files';
            if ( $contains_any( $slug, [ 'image', 'screen', 'mobile', 'tablet' ] ) ) return 'files';
            if ( $contains_any( $slug, [ 'email', 'chat', 'notification', 'forum', 'share', 'link', 'phone', 'newsletter', 'service-desk', 'wikis' ] ) ) return 'communication';
            if ( in_array( $slug, [ 'animal', 'animals', 'bat', 'cat', 'dog', 'paw', 'paw-print', 'pig', 'pet', 'pets' ], true ) ) return 'animals';
            if ( str_starts_with( $slug, 'user' ) || str_starts_with( $slug, 'group' ) || str_starts_with( $slug, 'home' ) || str_starts_with( $slug, 'building' ) || str_starts_with( $slug, 'workspace' ) || str_starts_with( $slug, 'apps' ) ) return 'people';
            if ( $contains_any( $slug, [ 'server', 'data-base', 'database', 'ibm-watsonx' ] ) ) return 'infrastructure';
            if ( str_starts_with( $slug, 'settings' ) || str_contains( $slug, 'task' ) || str_contains( $slug, 'calendar' ) || str_contains( $slug, 'time' ) || str_contains( $slug, 'event' ) || str_contains( $slug, 'save' ) || str_contains( $slug, 'edit' ) || str_contains( $slug, 'cut' ) || str_contains( $slug, 'pen' ) || str_contains( $slug, 'printer' ) || str_contains( $slug, 'folder' ) || str_contains( $slug, 'box' ) || str_contains( $slug, 'template' ) || str_contains( $slug, 'add-' ) || str_contains( $slug, 'close-' ) || str_contains( $slug, 'download' ) || str_contains( $slug, 'upload' ) || str_contains( $slug, 'export' ) || str_contains( $slug, 'collapse' ) || str_contains( $slug, 'expand' ) || str_contains( $slug, 'trash' ) || str_contains( $slug, 'logout' ) || str_contains( $slug, 'paper-clip' ) || str_contains( $slug, 'arrows-horizontal' ) ) return 'actions';
            if ( str_starts_with( $slug, 'help' ) || str_contains( $slug, 'information' ) || str_contains( $slug, 'accessibility' ) || str_contains( $slug, 'in-progress' ) || str_contains( $slug, 'need' ) || str_contains( $slug, 'result' ) || str_contains( $slug, 'loading' ) || str_contains( $slug, 'favorite' ) || str_contains( $slug, 'reminder' ) ) return 'status';
            if ( str_starts_with( $slug, 'logo-' ) ) return 'brands';

            return 'general';
        };

        $items = [];
        $svg_icon_keys = function_exists( 'metis_navigation_svg_icon_keys' ) ? metis_navigation_svg_icon_keys() : [];
        foreach ( $svg_icon_keys as $icon_key ) {
            $icon_key = metis_key_clean( str_replace( '_', '-', (string) $icon_key ) );
            if ( $icon_key === '' ) {
                continue;
            }
            $svg_markup = function_exists( 'metis_navigation_svg_icon_markup' ) ? (string) metis_navigation_svg_icon_markup( $icon_key ) : '';
            $items[] = [
                'key' => $icon_key,
                'label' => ucwords( str_replace( '-', ' ', $icon_key ) ),
                'svg' => $svg_markup,
                'url' => $svg_markup === '' && function_exists( 'metis_navigation_svg_icon_url' ) ? (string) metis_navigation_svg_icon_url( $icon_key ) : '',
                'category' => $category_for_icon( $icon_key ),
            ];
        }

        $json = function_exists( 'metis_json_encode' ) ? (string) metis_json_encode( $items ) : (string) json_encode( $items );
        if ( $json === '' ) {
            $json = '[]';
        }

        return $json;
    }

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
            'can_manage_media' => function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'website.manage_media' ) : false,
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
        <script type="application/json" id="metis-editor-icon-library-json"><?php echo metis_website_editor_icon_picker_library_json(); ?></script>
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
            data-editor-can-manage-media="<?php echo ! empty( $payload['can_manage_media'] ) ? '1' : '0'; ?>"
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
