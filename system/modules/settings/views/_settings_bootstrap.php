<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! function_exists( 'metis_settings_is_system_admin' ) ) {
    function metis_settings_is_system_admin(): bool {
        return metis_current_user_can( 'manage_options' );
    }
}

if ( ! function_exists( 'metis_settings_is_developer' ) ) {
    function metis_settings_is_developer(): bool {
        if ( metis_settings_is_system_admin() ) {
            return true;
        }

        $user = function_exists( 'metis_runtime_current_user' ) ? metis_runtime_current_user() : null;
        $roles = $user instanceof MetisUser ? (array) $user->roles : [];
        foreach ( $roles as $role ) {
            if ( metis_key_clean( (string) $role ) === 'developer' ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'metis_settings_route_parts' ) ) {
    function metis_settings_route_parts(): array {
        $path = function_exists( 'metis_request_path_relative_to_site' ) ? (string) metis_request_path_relative_to_site() : '/';
        $portal_slug = trim( (string) ( function_exists( 'metis_portal_slug' ) ? metis_portal_slug() : '' ), '/' );
        if ( $portal_slug !== '' ) {
            $path = preg_replace( '#^/' . preg_quote( $portal_slug, '#' ) . '#', '', $path );
        }
        $path = trim( (string) $path, '/' );
        $parts = $path !== '' ? explode( '/', $path ) : [];

        return [
            'domain' => metis_key_clean( (string) ( $parts[0] ?? '' ) ),
            'view' => metis_key_clean( (string) ( $parts[1] ?? '' ) ),
            'tail' => metis_key_clean( (string) ( $parts[2] ?? '' ) ),
        ];
    }
}

if ( ! function_exists( 'metis_settings_section_access_matrix' ) ) {
    function metis_settings_section_access_matrix(): array {
        return [
            'identity' => [ 'admin' ],
            'organization' => [ 'admin' ],
            'developers' => [ 'admin', 'developer' ],
            'system' => [ 'admin' ],
            'security' => [ 'admin' ],
            'data' => [ 'admin' ],
            'platform' => [ 'admin' ],
            'help' => [ 'admin' ],
        ];
    }
}

if ( ! function_exists( 'metis_settings_can_access_section' ) ) {
    function metis_settings_can_access_section( string $section ): bool {
        $section = metis_key_clean( $section );
        $matrix = metis_settings_section_access_matrix();
        $allowed = (array) ( $matrix[ $section ] ?? [] );
        if ( $allowed === [] ) {
            return false;
        }

        foreach ( $allowed as $rule ) {
            if ( $rule === 'admin' && metis_settings_is_system_admin() ) {
                return true;
            }
            if ( $rule === 'developer' && metis_settings_is_developer() ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'metis_settings_ia' ) ) {
    function metis_settings_ia(): array {
        return [
            'identity' => [
                'label' => 'IDENTITY',
                'pages' => [
                    'general' => [ 'label' => 'General', 'section' => 'general' ],
                    'user-experience' => [ 'label' => 'User Experience', 'section' => 'user_experience' ],
                    'branding' => [ 'label' => 'Branding', 'section' => 'branding' ],
                    'navigation' => [ 'label' => 'Navigation', 'section' => 'navigation' ],
                ],
            ],
            'organization' => [
                'label' => 'ORGANIZATION',
                'pages' => [
                    'email' => [ 'label' => 'Email', 'section' => 'email' ],
                    'payments' => [ 'label' => 'Payments', 'section' => 'payments' ],
                    'google-workspace' => [ 'label' => 'Google Workspace', 'section' => 'google_workspace' ],
                    'calendar' => [ 'label' => 'Calendar', 'section' => 'calendar' ],
                    'drive' => [ 'label' => 'Drive', 'section' => 'drive' ],
                ],
            ],
            'developers' => [
                'label' => 'DEVELOPERS',
                'pages' => [
                    'api' => [ 'label' => 'API & Endpoints', 'section' => 'developers_api' ],
                ],
            ],
            'system' => [
                'label' => 'SYSTEM',
                'pages' => [
                    'runtime' => [ 'label' => 'Runtime', 'section' => 'runtime' ],
                    'jobs-tasks' => [ 'label' => 'Jobs & Tasks', 'section' => 'jobs_tasks' ],
                ],
            ],
            'security' => [
                'label' => 'SECURITY & HEALTH',
                'pages' => [
                    'system-health' => [ 'label' => 'System Health', 'section' => 'system_health' ],
                ],
            ],
            'data' => [
                'label' => 'DATA PROTECTION',
                'pages' => [
                    'backup' => [ 'label' => 'Backup', 'section' => 'backup' ],
                ],
            ],
            'platform' => [
                'label' => 'PLATFORM',
                'pages' => [
                    'about' => [ 'label' => 'About', 'section' => 'about' ],
                ],
            ],
            'help' => [
                'label' => 'HELP',
                'pages' => [
                    'help' => [ 'label' => 'Help', 'section' => 'help' ],
                ],
            ],
        ];
    }
}

if ( ! function_exists( 'metis_settings_sections' ) ) {
    function metis_settings_sections( ?bool $is_system_admin = null ): array {
        $sections = [
            'general' => 'General',
            'user_experience' => 'User Experience',
            'branding' => 'Branding',
            'navigation' => 'Navigation',
            'email' => 'Email',
            'payments' => 'Payments',
            'google_workspace' => 'Google Workspace',
            'calendar' => 'Calendar',
            'drive' => 'Drive',
            'developers_api' => 'API & Endpoints',
            'runtime' => 'Runtime',
            'jobs_tasks' => 'Jobs & Tasks',
            'system_health' => 'System Health',
            'backup' => 'Backup',
            'about' => 'About',
            'help' => 'Help',
        ];

        if ( ! metis_settings_is_system_admin() && ! metis_settings_is_developer() ) {
            return [];
        }

        if ( ! metis_settings_is_developer() ) {
            unset( $sections['developers_api'] );
        }

        if ( ! metis_settings_is_system_admin() ) {
            unset(
                $sections['general'],
                $sections['user_experience'],
                $sections['branding'],
                $sections['navigation'],
                $sections['email'],
                $sections['google_workspace'],
                $sections['calendar'],
                $sections['drive'],
                $sections['runtime'],
                $sections['jobs_tasks'],
                $sections['system_health'],
                $sections['backup'],
                $sections['about'],
                $sections['help']
            );
        }

        return $sections;
    }
}

if ( ! function_exists( 'metis_settings_section_groups' ) ) {
    function metis_settings_section_groups( ?bool $is_system_admin = null ): array {
        $grouped = [];
        foreach ( metis_settings_ia() as $section_key => $definition ) {
            if ( ! metis_settings_can_access_section( $section_key ) ) {
                continue;
            }

            $label = (string) ( $definition['label'] ?? strtoupper( $section_key ) );
            $grouped[ $label ] = [];
            foreach ( (array) ( $definition['pages'] ?? [] ) as $page_key => $page_definition ) {
                $grouped[ $label ][ $section_key . '/' . $page_key ] = (string) ( $page_definition['label'] ?? $page_key );
            }
        }

        return $grouped;
    }
}

if ( ! function_exists( 'metis_settings_render_section_nav' ) ) {
    function metis_settings_render_section_nav( string $current_section ): void {
        $route = metis_settings_route_parts();
        $active = (string) ( $route['view'] ?? '' );
        $active_page = (string) ( $route['tail'] ?? '' );
        if ( $active_page === '' ) {
            $ia = metis_settings_ia();
            if ( isset( $ia[ $active ]['pages'] ) && is_array( $ia[ $active ]['pages'] ) ) {
                $first_page = array_key_first( $ia[ $active ]['pages'] );
                if ( is_string( $first_page ) ) {
                    $active_page = $first_page;
                }
            }
        }
        $active_key = $active . '/' . $active_page;
        echo '<div class="metis-sidebar-layout metis-settings-layout">';
        echo '<aside class="metis-sidebar-layout-sidebar metis-settings-layout-sidebar">';
        echo '<div class="metis-sidebar-layout-sidebar-inner metis-settings-layout-sidebar-inner">';
        echo '<div class="metis-list-sidebar-actions">';
        echo '<div class="metis-list-sidebar-label">Settings</div>';
        echo '<nav class="metis-list-sidebar-nav" aria-label="Settings sections">';
        foreach ( metis_settings_section_groups() as $group_label => $group_sections ) {
            echo '<div class="metis-list-sidebar-label" style="margin-top:10px;">' . metis_escape_html( (string) $group_label ) . '</div>';
            foreach ( $group_sections as $slug => $label ) {
                $parts = explode( '/', (string) $slug, 2 );
                $section = (string) ( $parts[0] ?? '' );
                $page = (string) ( $parts[1] ?? '' );
                $key = $section . '/' . $page;
                $class = $key === $active_key ? ' is-active' : '';
                echo '<a class="metis-list-sidebar-nav-item' . metis_escape_attr( $class ) . '" href="' . metis_escape_url( metis_settings_section_url( $section, $page ) ) . '">' . metis_escape_html( (string) $label ) . '</a>';
            }
        }
        echo '</nav>';
        echo '</div>';
        echo '</div>';
        echo '</aside>';
        echo '<div class="metis-sidebar-layout-content metis-settings-layout-content">';
    }
}

if ( ! function_exists( 'metis_settings_render_section_end' ) ) {
    function metis_settings_render_section_end(): void {
        echo '</div></div>';
    }
}

if ( ! function_exists( 'metis_settings_section_matches' ) ) {
    function metis_settings_section_matches( string $section, array $candidates ): bool {
        return in_array( metis_key_clean( $section ), array_map( 'metis_key_clean', $candidates ), true );
    }
}

if ( ! function_exists( 'metis_settings_should_load_logging_state' ) ) {
    function metis_settings_should_load_logging_state( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'runtime', 'logging' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_navigation_state' ) ) {
    function metis_settings_should_load_navigation_state( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'navigation', 'menu' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_credential_lists' ) ) {
    function metis_settings_should_load_credential_lists( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'developers_api', 'google_workspace', 'email' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_email_usage' ) ) {
    function metis_settings_should_load_email_usage( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'email', 'newsletter' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_scheduler_snapshot' ) ) {
    function metis_settings_should_load_scheduler_snapshot( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'jobs_tasks', 'scheduler', 'operations' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_release_state' ) ) {
    function metis_settings_should_load_release_state( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'about' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_homepage_pages' ) ) {
    function metis_settings_should_load_homepage_pages( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'general' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_drive_options' ) ) {
    function metis_settings_should_load_drive_options( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'drive', 'backup' ] );
    }
}

if ( ! function_exists( 'metis_settings_should_load_calendar_options' ) ) {
    function metis_settings_should_load_calendar_options( string $section ): bool {
        return metis_settings_section_matches( $section, [ 'calendar' ] );
    }
}

if ( ! function_exists( 'metis_settings_render_messages' ) ) {
    function metis_settings_render_messages( bool $saved, array $errors ): void {
        // Settings surfaces use toast notifications for save responses.
        // Intentionally no inline banners here.
    }
}

if ( ! function_exists( 'metis_settings_section_url' ) ) {
    function metis_settings_section_url( string $section, string $page = '' ): string {
        $section = metis_key_clean( $section );
        $page = metis_key_clean( $page );
        if ( $section === '' ) {
            $section = 'identity';
        }
        if ( $page === '' ) {
            $page = 'general';
        }
        return rtrim( metis_portal_url( 'settings', $section ), '/' ) . '/' . $page . '/';
    }
}

if ( ! function_exists( 'metis_settings_date_presets' ) ) {
    function metis_settings_date_presets(): array {
        return [
            'mm/dd/yy'   => 'm/d/y',
            'mm/dd/yyyy' => 'm/d/Y',
            'yyyy-mm-dd' => 'Y-m-d',
            'dd/mm/yyyy' => 'd/m/Y',
            'mmm d, yyyy'=> 'M j, Y',
        ];
    }
}

if ( ! function_exists( 'metis_settings_time_presets' ) ) {
    function metis_settings_time_presets(): array {
        return [
            'h:mm:ss a/m' => 'g:i:s a',
            'hh:mm:ss A/P'=> 'h:i:s A',
            'HH:mm:ss'    => 'H:i:s',
            'h:mm a/m'    => 'g:i a',
            'HH:mm'       => 'H:i',
        ];
    }
}

if ( ! function_exists( 'metis_settings_default_klipy_search_url' ) ) {
    function metis_settings_default_klipy_search_url(): string {
        return 'https://api.klipy.com/v1/gifs/search';
    }
}

if ( ! function_exists( 'metis_settings_scalar_string' ) ) {
    function metis_settings_scalar_string( mixed $value, string $default = '' ): string {
        if ( ! is_scalar( $value ) && $value !== null ) {
            return $default;
        }

        $normalized = metis_text_clean( (string) $value );

        return $normalized !== '' ? $normalized : $default;
    }
}

if ( ! function_exists( 'metis_settings_bool_flag' ) ) {
    function metis_settings_bool_flag( mixed $value, bool $default = false ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }

        if ( is_string( $value ) ) {
            $normalized = strtolower( trim( $value ) );
            if ( in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true ) ) {
                return true;
            }
            if ( in_array( $normalized, [ '0', 'false', 'no', 'off', '' ], true ) ) {
                return false;
            }
        }

        return $default;
    }
}

if ( ! function_exists( 'metis_settings_int_range' ) ) {
    function metis_settings_int_range( mixed $value, int $default, int $min, int $max ): int {
        if ( ! is_scalar( $value ) || $value === '' ) {
            return $default;
        }

        $normalized = (int) $value;
        if ( $normalized < $min ) {
            return $min;
        }
        if ( $normalized > $max ) {
            return $max;
        }

        return $normalized;
    }
}

if ( ! function_exists( 'metis_settings_normalize_klipy_search_url' ) ) {
    function metis_settings_normalize_klipy_search_url( string $raw_url ): string {
        $default = metis_settings_default_klipy_search_url();
        $raw_url = trim( $raw_url );
        if ( $raw_url === '' ) {
            return $default;
        }

        $candidate = metis_url_clean( $raw_url );
        if ( $candidate === '' ) {
            return '';
        }

        $parts = parse_url( $candidate );
        if ( ! is_array( $parts ) ) {
            return '';
        }

        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return '';
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( (string) ( $parts['host'] ?? '' ) );
        $port   = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
        $path   = '/' . trim( (string) ( $parts['path'] ?? '' ), '/' );

        if ( $scheme !== 'https' || $host !== 'api.klipy.com' || $port !== 443 || $path !== '/v1/gifs/search' ) {
            return '';
        }

        return $default;
    }
}

if ( ! function_exists( 'metis_settings_parse_date_pattern' ) ) {
    function metis_settings_parse_date_pattern( string $input ): string {
        $working = trim( strtolower( $input ) );
        if ( $working === '' ) {
            return '';
        }
        $count = 0;
        $result = preg_replace_callback(
            '/yyyy|yy|mmmm|mmm|mm|m|dd|d/i',
            static function ( array $match ) use ( &$count ): string {
                $count++;
                return match ( strtolower( (string) ( $match[0] ?? '' ) ) ) {
                    'yyyy' => 'Y',
                    'yy'   => 'y',
                    'mmmm' => 'F',
                    'mmm'  => 'M',
                    'mm'   => 'm',
                    'm'    => 'n',
                    'dd'   => 'd',
                    'd'    => 'j',
                    default => '',
                };
            },
            $working
        );

        if ( ! is_string( $result ) || $count < 1 ) {
            return '';
        }

        return $result;
    }
}

if ( ! function_exists( 'metis_settings_parse_time_pattern' ) ) {
    function metis_settings_parse_time_pattern( string $input ): string {
        $working = trim( $input );
        if ( $working === '' ) {
            return '';
        }
        $count = 0;
        $result = preg_replace_callback(
            '/am\/pm|a\/m|A\/P|HH|H|hh|h|mm|m|ss|s|A|a/',
            static function ( array $match ) use ( &$count ): string {
                $count++;
                return match ( (string) ( $match[0] ?? '' ) ) {
                    'am/pm', 'a/m' => 'a',
                    'A/P' => 'A',
                    'HH' => 'H',
                    'H' => 'G',
                    'hh' => 'h',
                    'h' => 'g',
                    'mm', 'm' => 'i',
                    'ss', 's' => 's',
                    'A' => 'A',
                    'a' => 'a',
                    default => '',
                };
            },
            $working
        );

        if ( ! is_string( $result ) || $count < 1 ) {
            return '';
        }

        return $result;
    }
}

if ( ! function_exists( 'metis_settings_save_image_upload' ) ) {
    function metis_settings_save_image_upload( array $file, string $label, int $max_bytes, array $allowed_mime_types ): array {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return [ 'ok' => false, 'error' => $label . ' upload did not complete correctly.' ];
        }

        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        if ( $size < 1 ) {
            return [ 'ok' => false, 'error' => $label . ' file is empty.' ];
        }
        if ( $size > $max_bytes ) {
            return [ 'ok' => false, 'error' => sprintf( '%s must be %s or smaller.', $label, size_format( $max_bytes ) ) ];
        }

        $finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
        $mime_type = $finfo ? (string) finfo_file( $finfo, $file['tmp_name'] ) : '';
        if ( $finfo ) {
            finfo_close( $finfo );
        }

        if ( $mime_type === '' || ! in_array( $mime_type, $allowed_mime_types, true ) ) {
            return [ 'ok' => false, 'error' => $label . ' has an unsupported file type.' ];
        }

        $contents = file_get_contents( $file['tmp_name'] );
        if ( $contents === false || $contents === '' ) {
            return [ 'ok' => false, 'error' => 'Unable to read the uploaded ' . strtolower( $label ) . '.' ];
        }

        return [
            'ok'    => true,
            'asset' => [
                'filename' => metis_filename_clean( (string) ( $file['name'] ?? strtolower( $label ) ) ),
                'mime_type' => $mime_type,
                'size' => $size,
                'data_base64' => base64_encode( $contents ),
                'updated_at' => metis_current_time( 'mysql' ),
            ],
        ];
    }
}

if ( ! function_exists( 'metis_settings_save_logo_upload' ) ) {
    function metis_settings_save_logo_upload( array $file ): array {
        $result = metis_settings_save_image_upload(
            $file,
            'Logo',
            2 * 1024 * 1024,
            [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ]
        );

        if ( empty( $result['ok'] ) ) {
            return $result;
        }

        return [ 'ok' => true, 'logo' => $result['asset'] ];
    }
}

if ( ! function_exists( 'metis_settings_save_favicon_upload' ) ) {
    function metis_settings_save_favicon_upload( array $file ): array {
        $result = metis_settings_save_image_upload(
            $file,
            'Favicon',
            512 * 1024,
            [ 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml' ]
        );

        if ( empty( $result['ok'] ) ) {
            return $result;
        }

        return [ 'ok' => true, 'favicon' => $result['asset'] ];
    }
}

if ( ! function_exists( 'metis_settings_asset_src' ) ) {
    function metis_settings_asset_src( $asset ): string {
        if ( ! is_array( $asset ) ) {
            return '';
        }

        $url = metis_url_clean( (string) ( $asset['url'] ?? '' ) );
        if ( $url !== '' ) {
            return $url;
        }

        $mime_type = (string) ( $asset['mime_type'] ?? '' );
        $data      = (string) ( $asset['data_base64'] ?? '' );
        if ( $mime_type === '' || $data === '' ) {
            return '';
        }

        return 'data:' . $mime_type . ';base64,' . $data;
    }
}

if ( ! function_exists( 'metis_settings_media_asset_from_request' ) ) {
    function metis_settings_media_asset_from_request( string $field_prefix, array $allowed_mime_types ): array {
        $token = strtolower( trim( metis_text_clean( (string) metis_runtime_unslash( metis_request_post()[ $field_prefix . '_media_token' ] ?? '' ) ) ) );
        $url   = metis_url_clean( (string) metis_runtime_unslash( metis_request_post()[ $field_prefix . '_media_url' ] ?? '' ) );
        $name  = metis_filename_clean( (string) metis_runtime_unslash( metis_request_post()[ $field_prefix . '_media_name' ] ?? '' ) );
        $mime  = strtolower( trim( metis_text_clean( (string) metis_runtime_unslash( metis_request_post()[ $field_prefix . '_media_mime' ] ?? '' ) ) ) );

        if ( $token === '' && $url === '' ) {
            return [ 'ok' => false, 'empty' => true ];
        }

        if ( $token !== '' && ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
            return [ 'ok' => false, 'error' => 'Invalid media token provided.' ];
        }

        if ( $url === '' || strpos( $url, '/media/' ) === false ) {
            return [ 'ok' => false, 'error' => 'Selected media URL is invalid.' ];
        }

        if ( $mime !== '' && ! in_array( $mime, $allowed_mime_types, true ) ) {
            return [ 'ok' => false, 'error' => 'Selected media type is not supported.' ];
        }

        return [
            'ok' => true,
            'asset' => [
                'filename' => $name !== '' ? $name : 'media',
                'mime_type' => $mime,
                'url' => $url,
                'public_token' => $token,
                'updated_at' => metis_current_time( 'mysql' ),
            ],
        ];
    }
}

if ( ! function_exists( 'metis_settings_theme_color_fields' ) ) {
    function metis_settings_theme_color_fields(): array {
        return [
            'metis_primary' => [ 'css_var' => '--metis-primary', 'label' => 'Primary', 'default' => '#485bc7' ],
            'metis_primary_dark' => [ 'css_var' => '--metis-primary-dark', 'label' => 'Primary Dark', 'default' => '#3246a7' ],
            'metis_accent' => [ 'css_var' => '--metis-accent', 'label' => 'Accent', 'default' => '#ff7542' ],
            'metis_bg' => [ 'css_var' => '--metis-bg', 'label' => 'Background', 'default' => '#f5f6fa' ],
            'metis_surface' => [ 'css_var' => '--metis-surface', 'label' => 'Surface', 'default' => '#ffffff' ],
            'metis_border' => [ 'css_var' => '--metis-border', 'label' => 'Border', 'default' => '#e0e2ea' ],
            'metis_text' => [ 'css_var' => '--metis-text', 'label' => 'Text', 'default' => '#1f2330' ],
            'metis_text_muted' => [ 'css_var' => '--metis-text-muted', 'label' => 'Muted Text', 'default' => '#6d7485' ],
            'metis_header_bg' => [ 'css_var' => '--metis-header-bg', 'label' => 'Header Background', 'default' => '#eceeff' ],
            'metis_row_odd_bg' => [ 'css_var' => '--metis-row-odd-bg', 'label' => 'Row Odd Background', 'default' => '#ffffff' ],
            'metis_row_even_bg' => [ 'css_var' => '--metis-row-even-bg', 'label' => 'Row Even Background', 'default' => '#f8f9fd' ],
            'metis_row_hover_bg' => [ 'css_var' => '--metis-row-hover-bg', 'label' => 'Row Hover Background', 'default' => '#eef2ff' ],
            'metis_sidebar_bg' => [ 'css_var' => '--metis-sidebar-bg', 'label' => 'Sidebar Background', 'default' => '#16192b' ],
            'metis_sidebar_icon_color' => [ 'css_var' => '--metis-sidebar-icon-color', 'label' => 'Sidebar Icon', 'default' => '#7a82a6' ],
            'metis_sidebar_active_color' => [ 'css_var' => '--metis-sidebar-active-color', 'label' => 'Sidebar Active', 'default' => '#a8b4ff' ],
        ];
    }
}

if ( ! function_exists( 'metis_settings_get_saved_theme_colors' ) ) {
    function metis_settings_get_saved_theme_colors(): array {
        $fields = metis_settings_theme_color_fields();
        $saved  = Core_Settings_Service::get( 'theme_colors', [] );
        $result = [];

        foreach ( $fields as $key => $field ) {
            $default = (string) ( $field['default'] ?? '' );
            $value   = is_array( $saved ) ? (string) ( $saved[ $key ] ?? $default ) : $default;
            $result[ $key ] = metis_hex_color_clean( $value ) ?: $default;
        }

        return $result;
    }
}

if ( ! function_exists( 'metis_settings_menu_modules' ) ) {
    function metis_settings_menu_modules(): array {
        $modules = function_exists( 'metis_get_modules' ) ? metis_get_modules() : [];
        if ( empty( $modules ) ) {
            return [];
        }

        $result = [];
        foreach ( $modules as $slug => $module ) {
            if ( $slug === 'profile' ) {
                continue;
            }
            $config = is_array( $module['config'] ?? null ) ? $module['config'] : [];
            $navigation = is_array( $config['navigation'] ?? null ) ? $config['navigation'] : [];
            $enabled = array_key_exists( 'enabled', $navigation ) ? (bool) $navigation['enabled'] : true;
            if ( ! $enabled ) {
                continue;
            }
            $result[] = [
                'slug' => $slug,
                'label' => (string) ( $navigation['label'] ?? $config['name'] ?? $config['label'] ?? ucfirst( $slug ) ),
                'required' => ! empty( $config['required'] ),
            ];
        }

        return $result;
    }
}

if ( ! function_exists( 'metis_settings_navigation_editor_state' ) ) {
    function metis_settings_navigation_editor_state(): array {
        if ( ! function_exists( 'metis_navigation_service' ) ) {
            return [ 'items' => [], 'unassigned' => [] ];
        }

        $modules = function_exists( 'metis_get_modules' ) ? metis_get_modules() : [];
        return metis_navigation_service()->editorState( $modules );
    }
}

if ( ! function_exists( 'metis_settings_normalize_drive_rows' ) ) {
    function metis_settings_normalize_drive_rows( $rows ): array {
        $normalized = [];
        if ( ! is_array( $rows ) ) {
            return $normalized;
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $drive_id = metis_text_clean( $row['drive_id'] ?? '' );
            if ( $drive_id === '' ) {
                continue;
            }
            $normalized[] = [
                'label' => metis_text_clean( $row['label'] ?? '' ),
                'drive_id' => $drive_id,
                'drive_name' => metis_text_clean( $row['drive_name'] ?? '' ),
                'is_default' => ! empty( $row['is_default'] ) ? 1 : 0,
                'is_users_home' => ! empty( $row['is_users_home'] ) ? 1 : 0,
            ];
        }
        if ( count( $normalized ) === 1 ) {
            $normalized[0]['is_default'] = 1;
        }
        $has_default = false;
        foreach ( $normalized as $index => $row ) {
            if ( ! empty( $row['is_default'] ) && ! $has_default ) {
                $normalized[ $index ]['is_default'] = 1;
                $has_default = true;
            } else {
                $normalized[ $index ]['is_default'] = 0;
            }
        }
        if ( ! $has_default && ! empty( $normalized ) ) {
            $normalized[0]['is_default'] = 1;
        }
        return $normalized;
    }
}

if ( ! function_exists( 'metis_settings_normalize_calendar_rows' ) ) {
    function metis_settings_normalize_calendar_rows( $rows ): array {
        $normalized = [];
        if ( ! is_array( $rows ) ) {
            return $normalized;
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $calendar_id = metis_text_clean( $row['calendar_id'] ?? '' );
            if ( $calendar_id === '' ) {
                continue;
            }
            $normalized[] = [
                'label' => metis_text_clean( $row['label'] ?? '' ),
                'calendar_id' => $calendar_id,
                'calendar_name' => metis_text_clean( $row['calendar_name'] ?? '' ),
                'is_default' => ! empty( $row['is_default'] ) ? 1 : 0,
            ];
        }
        if ( count( $normalized ) === 1 ) {
            $normalized[0]['is_default'] = 1;
        }
        $has_default = false;
        foreach ( $normalized as $index => $row ) {
            if ( ! empty( $row['is_default'] ) && ! $has_default ) {
                $normalized[ $index ]['is_default'] = 1;
                $has_default = true;
            } else {
                $normalized[ $index ]['is_default'] = 0;
            }
        }
        if ( ! $has_default && ! empty( $normalized ) ) {
            $normalized[0]['is_default'] = 1;
        }
        return $normalized;
    }
}

if ( ! function_exists( 'metis_settings_normalize_inbound_mailbox_rows' ) ) {
    function metis_settings_normalize_inbound_mailbox_rows( $rows ): array {
        $normalized = [];
        if ( is_string( $rows ) ) {
            $decoded = json_decode( $rows, true );
            $rows = is_array( $decoded ) ? $decoded : [];
        }
        if ( ! is_array( $rows ) ) {
            return $normalized;
        }

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $mailbox_email = strtolower( trim( metis_email_clean( (string) ( $row['mailbox_email'] ?? $row['email'] ?? '' ) ) ) );
            if ( $mailbox_email === '' || ! metis_email_is_valid( $mailbox_email ) ) {
                continue;
            }

            $delegated_user = strtolower( trim( metis_email_clean( (string) ( $row['delegated_user'] ?? $row['subject'] ?? $mailbox_email ) ) ) );
            if ( $delegated_user === '' || ! metis_email_is_valid( $delegated_user ) ) {
                $delegated_user = $mailbox_email;
            }

            $label_ids = $row['label_ids'] ?? [];
            if ( is_string( $label_ids ) ) {
                $label_ids = preg_split( '/[\s,]+/', $label_ids ) ?: [];
            }
            if ( ! is_array( $label_ids ) ) {
                $label_ids = [];
            }

            $normalized_label_ids = [];
            foreach ( $label_ids as $label_id ) {
                $label_id = trim( metis_text_clean( (string) $label_id ) );
                if ( $label_id !== '' && ! in_array( $label_id, $normalized_label_ids, true ) ) {
                    $normalized_label_ids[] = $label_id;
                }
            }

            $label_filter_behavior = metis_key_clean( (string) ( $row['label_filter_behavior'] ?? '' ) );
            if ( ! in_array( $label_filter_behavior, [ 'include', 'exclude' ], true ) ) {
                $label_filter_behavior = '';
            }

            $mailbox_key = metis_key_clean( (string) ( $row['mailbox_key'] ?? '' ) );
            if ( $mailbox_key === '' ) {
                $mailbox_key = metis_key_clean( str_replace( [ '@', '.' ], '_', $mailbox_email ) );
            }

            $normalized[] = [
                'mailbox_key'           => $mailbox_key,
                'mailbox_email'         => $mailbox_email,
                'display_name'          => metis_text_clean( (string) ( $row['display_name'] ?? '' ) ),
                'delegated_user'        => $delegated_user,
                'enabled'               => ! array_key_exists( 'enabled', $row ) || ! empty( $row['enabled'] ) ? 1 : 0,
                'label_ids'             => $normalized_label_ids,
                'label_filter_behavior' => $label_filter_behavior,
            ];
        }

        return $normalized;
    }
}

if ( ! function_exists( 'metis_mask_key' ) ) {
    function metis_mask_key( string $key ): string {
        if ( strlen( $key ) < 8 ) return str_repeat( '•', 20 );
        return str_repeat( '•', 16 ) . substr( $key, -4 );
    }
}

if ( ! function_exists( 'metis_settings_format_interval' ) ) {
    function metis_settings_format_interval( int $seconds ): string {
        $seconds = max( 60, $seconds );

        if ( $seconds % DAY_IN_SECONDS === 0 ) {
            $days = (int) ( $seconds / DAY_IN_SECONDS );
            return $days . ' day' . ( $days === 1 ? '' : 's' );
        }

        if ( $seconds % HOUR_IN_SECONDS === 0 ) {
            $hours = (int) ( $seconds / HOUR_IN_SECONDS );
            return $hours . ' hour' . ( $hours === 1 ? '' : 's' );
        }

        if ( $seconds % MINUTE_IN_SECONDS === 0 ) {
            $minutes = (int) ( $seconds / MINUTE_IN_SECONDS );
            return $minutes . ' minute' . ( $minutes === 1 ? '' : 's' );
        }

        return $seconds . ' seconds';
    }
}

if ( ! function_exists( 'metis_settings_normalize_timezone' ) ) {
    function metis_settings_normalize_timezone( string $timezone ): string {
        if ( function_exists( 'metis_runtime_timezone_name' ) ) {
            return metis_runtime_timezone_name( $timezone );
        }

        $timezone = trim( $timezone );
        return $timezone !== '' && in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : 'UTC';
    }
}

if ( ! function_exists( 'metis_settings_format_datetime_display' ) ) {
    function metis_settings_format_datetime_display( string $value, string $date_format, string $time_format, string $timezone ): string {
        $format = trim( $date_format . ' ' . $time_format );
        return function_exists( 'metis_runtime_format_datetime' )
            ? metis_runtime_format_datetime( $value, $format !== '' ? $format : null, $timezone, null, trim( $value ) )
            : trim( $value );
    }
}

if ( ! function_exists( 'metis_settings_recent_cutoff' ) ) {
    function metis_settings_recent_cutoff( int $days ): string {
        $seconds = max( 1, $days ) * DAY_IN_SECONDS;
        return function_exists( 'metis_runtime_date' )
            ? metis_runtime_date( 'Y-m-d H:i:s', (int) metis_current_time( 'timestamp' ) - $seconds )
            : date( 'Y-m-d H:i:s', time() - $seconds );
    }
}

if ( ! function_exists( 'metis_settings_scheduler_task_association' ) ) {
    function metis_settings_scheduler_task_association( string $task_slug, array $task_config ): string {
        $declared = trim( (string) ( $task_config['association'] ?? '' ) );
        if ( $declared !== '' ) {
            return $declared;
        }

        $fallback = [
            'integrity_scan' => 'Run integrity scan and heal pass',
            'cache_cleanup' => 'Clean expired cache and transients',
            'data_retention_cleanup' => 'Purge expired operational history',
            'release_update_check' => 'Check trusted release updates',
            'release_auto_update' => 'Apply trusted release updates within policy',
            'drive_listing_sync' => 'Queue operation: drive.sync',
            'background_job_processing' => 'Drain async job queue',
        ];

        return (string) ( $fallback[ $task_slug ] ?? 'Scheduled cron callback' );
    }
}

if ( ! function_exists( 'metis_settings_humanize_slug' ) ) {
    function metis_settings_humanize_slug( string $slug ): string {
        $slug = metis_key_clean( $slug );
        if ( $slug === '' ) {
            return '';
        }

        return ucwords( str_replace( '_', ' ', $slug ) );
    }
}

if ( ! function_exists( 'metis_settings_root_path' ) ) {
    function metis_settings_root_path(): string {
        $root = defined( 'METIS_ROOT' )
            ? (string) METIS_ROOT
            : ( defined( 'METIS_PATH' ) ? (string) METIS_PATH : dirname( __DIR__, 3 ) );

        return rtrim( $root, '/\\' );
    }
}

if ( ! function_exists( 'metis_settings_storage_root_path' ) ) {
    function metis_settings_storage_root_path(): string {
        $root = defined( 'METIS_STORAGE_PATH' )
            ? (string) METIS_STORAGE_PATH
            : metis_settings_root_path() . '/storage';

        return rtrim( $root, '/\\' );
    }
}

if ( ! function_exists( 'metis_settings_storage_child_path' ) ) {
    function metis_settings_storage_child_path( string $child = '' ): string {
        $root = metis_settings_storage_root_path();
        $child = trim( $child, '/\\' );

        return $child === '' ? $root : $root . '/' . $child;
    }
}

if ( ! function_exists( 'metis_settings_health_filesystem_targets' ) ) {
    function metis_settings_health_filesystem_targets(): array {
        $root = metis_settings_root_path();
        $media_roots = function_exists( 'metis_media_storage_roots' )
            ? (array) metis_media_storage_roots( true )
            : [];

        return [
            [ 'path' => defined( 'METIS_CONFIG_PATH' ) ? (string) METIS_CONFIG_PATH : $root . '/system/config', 'label' => 'system/config', 'type' => 'sensitive', 'required' => true, 'mode' => 0755 ],
            [ 'path' => defined( 'METIS_MODULES_PATH' ) ? (string) METIS_MODULES_PATH : $root . '/system/modules', 'label' => 'system/modules', 'type' => 'sensitive', 'required' => true, 'mode' => 0755 ],
            [ 'path' => defined( 'METIS_SRC_PATH' ) ? (string) METIS_SRC_PATH : $root . '/system/src', 'label' => 'system/src', 'type' => 'sensitive', 'required' => true, 'mode' => 0755 ],
            [ 'path' => metis_settings_storage_child_path(), 'label' => 'storage', 'type' => 'runtime', 'required' => true, 'mode' => 0775 ],
            [ 'path' => metis_settings_storage_child_path( 'runtime' ), 'label' => 'storage/runtime', 'type' => 'runtime', 'required' => true, 'mode' => 0775 ],
            [ 'path' => (string) ( $media_roots['public'] ?? metis_settings_storage_child_path( 'public-media' ) ), 'label' => 'storage/public-media', 'type' => 'runtime', 'required' => true, 'mode' => 0775 ],
            [ 'path' => (string) ( $media_roots['protected'] ?? metis_settings_storage_child_path( 'protected-media' ) ), 'label' => 'storage/protected-media', 'type' => 'runtime', 'required' => true, 'mode' => 0775 ],
            [ 'path' => (string) ( $media_roots['private'] ?? metis_settings_storage_child_path( 'private-records' ) ), 'label' => 'storage/private-records', 'type' => 'runtime', 'required' => true, 'mode' => 0775 ],
            [ 'path' => (string) ( $media_roots['legacy_uploads'] ?? metis_settings_storage_child_path( 'uploads' ) ), 'label' => 'storage/uploads (legacy)', 'type' => 'legacy_runtime', 'required' => false, 'mode' => 0775 ],
        ];
    }
}

if ( ! function_exists( 'metis_settings_health_filesystem_check_id' ) ) {
    function metis_settings_health_filesystem_check_id( string $label ): string {
        return 'fs_perm_' . metis_key_clean( str_replace( '/', '_', $label ) );
    }
}

if ( ! function_exists( 'metis_settings_health_security_offense_clause' ) ) {
    function metis_settings_health_security_offense_clause(): string {
        return "
            (
                LOWER(action_type) = 'login_failed'
                OR LOWER(action_type) = 'invalid_cron_secret'
                OR LOWER(action_type) = 'cron_secret_missing'
                OR LOWER(action_type) LIKE '%brute%'
                OR LOWER(action_type) LIKE '%credential%'
                OR LOWER(action_type) LIKE '%lockout%'
                OR LOWER(action_type) LIKE '%threat%'
                OR LOWER(action_type) LIKE '%tamper%'
                OR LOWER(action_type) LIKE '%intrusion%'
            )
        ";
    }
}

if ( ! function_exists( 'metis_settings_health_security_offense_exclusion_clause' ) ) {
    function metis_settings_health_security_offense_exclusion_clause(): string {
        return "
            NOT (
                (LOWER(action_type) = 'route_action_failed' AND LOWER(resource_label) IN ('invalid_nonce', 'operation_not_registered'))
                OR (LOWER(action_type) = 'ajax_action_failed' AND LOWER(resource_label) IN ('invalid_nonce', 'operation_not_registered'))
                OR (LOWER(action_type) = 'system_cron_task_failed' AND LOWER(module_slug) = 'grandys_stash')
            )
        ";
    }
}

if ( ! function_exists( 'metis_settings_health_status_counts' ) ) {
    function metis_settings_health_status_counts( array $checks ): array {
        $status_counts = [ 'pass' => 0, 'warn' => 0, 'fail' => 0 ];
        foreach ( $checks as $check ) {
            $status = (string) ( is_array( $check ) ? ( $check['status'] ?? 'warn' ) : 'warn' );
            if ( isset( $status_counts[ $status ] ) ) {
                $status_counts[ $status ]++;
            }
        }

        return $status_counts;
    }
}

if ( ! function_exists( 'metis_settings_latest_backup_artifact' ) ) {
    function metis_settings_latest_backup_artifact(): array {
        $root = metis_settings_storage_child_path( 'backups' );
        if ( ! is_dir( $root ) ) {
            return [ 'timestamp' => 0, 'raw' => '', 'source' => '', 'count' => 0 ];
        }

        $latest_ts = 0;
        $latest_raw = '';
        $latest_source = '';
        $count = 0;
        $entries = glob( $root . '/*' );
        if ( ! is_array( $entries ) ) {
            $entries = [];
        }

        foreach ( $entries as $entry ) {
            $entry = (string) $entry;
            $candidate_ts = 0;
            $candidate_raw = '';
            $candidate_source = '';

            if ( is_dir( $entry ) ) {
                foreach ( [ $entry . '/payload/metadata.json', $entry . '/metadata.json' ] as $metadata_path ) {
                    if ( ! is_file( $metadata_path ) ) {
                        continue;
                    }

                    $json = json_decode( (string) @file_get_contents( $metadata_path ), true );
                    if ( ! is_array( $json ) ) {
                        continue;
                    }

                    $raw = (string) ( $json['created_at_local'] ?? $json['completed_at'] ?? $json['created_at_utc'] ?? '' );
                    $ts = $raw !== '' ? strtotime( $raw ) : false;
                    if ( $ts === false || $ts < 1 ) {
                        $mtime = @filemtime( $metadata_path );
                        $ts = $mtime !== false ? (int) $mtime : 0;
                    }

                    if ( $ts > $candidate_ts ) {
                        $candidate_ts = (int) $ts;
                        $candidate_raw = $raw !== '' ? $raw : date( 'Y-m-d H:i:s', (int) $ts );
                        $candidate_source = basename( $entry );
                    }
                }

                foreach ( [ $entry . '/payload/*', $entry . '/*' ] as $pattern ) {
                    $files = glob( $pattern );
                    if ( ! is_array( $files ) ) {
                        continue;
                    }
                    foreach ( $files as $file ) {
                        if ( ! is_file( (string) $file ) || preg_match( '/\.(zip|tar|tgz|gz|sql|json)$/i', (string) $file ) !== 1 ) {
                            continue;
                        }
                        $mtime = @filemtime( (string) $file );
                        $ts = $mtime !== false ? (int) $mtime : 0;
                        if ( $ts > $candidate_ts ) {
                            $candidate_ts = $ts;
                            $candidate_raw = date( 'Y-m-d H:i:s', $ts );
                            $candidate_source = basename( $entry );
                        }
                    }
                }
            } elseif ( is_file( $entry ) && preg_match( '/\.(zip|tar|tgz|gz|sql|json)$/i', $entry ) === 1 ) {
                $mtime = @filemtime( $entry );
                $candidate_ts = $mtime !== false ? (int) $mtime : 0;
                $candidate_raw = $candidate_ts > 0 ? date( 'Y-m-d H:i:s', $candidate_ts ) : '';
                $candidate_source = basename( $entry );
            }

            if ( $candidate_ts > 0 ) {
                $count++;
            }
            if ( $candidate_ts > $latest_ts ) {
                $latest_ts = $candidate_ts;
                $latest_raw = $candidate_raw;
                $latest_source = $candidate_source;
            }
        }

        return [
            'timestamp' => $latest_ts,
            'raw' => $latest_raw,
            'source' => $latest_source,
            'count' => $count,
        ];
    }
}

if ( ! function_exists( 'metis_settings_health_service_targets' ) ) {
    function metis_settings_health_service_targets(): array {
        return [
            'cache' => 'Cache Service',
            'files' => 'File Service',
            'db' => 'Database Service',
            'settings' => 'Settings Service',
            'modules' => 'Module Loader',
            'router' => 'Router Service',
            'jobs' => 'Job Queue',
            'job_workers' => 'Job Worker Registry',
            'operations' => 'Operations Service',
            'permissions' => 'Permissions Service',
            'security_kernel' => 'Security Kernel',
            'secure_enclave' => 'Secure Enclave',
            'navigation' => 'Navigation Service',
            'quick_actions' => 'Quick Actions Service',
            'help' => 'Help Service',
            'walkthroughs' => 'Walkthrough Service',
            'hermes_library' => 'Hermes Definition Library',
            'backup' => 'Backup Service',
            'release' => 'Release Service',
            'scheduler' => 'Scheduler Service',
        ];
    }
}

if ( ! function_exists( 'metis_settings_health_service_hydration_status' ) ) {
    function metis_settings_health_service_hydration_status(): array {
        if ( function_exists( 'metis_register_core_services' ) ) {
            metis_register_core_services();
        }

        $hydrated = [];
        $missing = [];
        $failed = [];

        foreach ( metis_settings_health_service_targets() as $service_key => $label ) {
            $service_key = (string) $service_key;
            $label = (string) $label;

            if ( ! class_exists( '\Metis\Core\Application' ) || ! \Metis\Core\Application::has_service( $service_key ) ) {
                $missing[] = $label;
                continue;
            }

            try {
                $service = \Metis\Core\Application::service( $service_key );
                if ( ! is_object( $service ) ) {
                    $failed[] = sprintf( '%s returned %s', $label, get_debug_type( $service ) );
                    continue;
                }
                $hydrated[] = $label;
            } catch ( Throwable $exception ) {
                $failed[] = sprintf( '%s: %s', $label, $exception->getMessage() );
            }
        }

        $status = empty( $missing ) && empty( $failed ) ? 'pass' : 'fail';
        $message = $status === 'pass'
            ? sprintf( '%d core services hydrated successfully.', count( $hydrated ) )
            : trim( implode( ' ', array_filter( [
                empty( $missing ) ? '' : 'Missing services: ' . implode( ', ', $missing ) . '.',
                empty( $failed ) ? '' : 'Failed services: ' . implode( '; ', $failed ) . '.',
            ] ) ) );

        return [
            'status' => $status,
            'message' => $message,
            'recommendation' => $status === 'pass' ? '' : 'Run auto-remediate to reload core services and inspect service boot failures.',
            'hydrated' => $hydrated,
            'missing' => $missing,
            'failed' => $failed,
        ];
    }
}

if ( ! function_exists( 'metis_settings_health_help_service_status' ) ) {
    function metis_settings_health_help_service_status(): array {
        if ( function_exists( 'metis_register_core_services' ) ) {
            metis_register_core_services();
        }

        $manifest_base = defined( 'METIS_MODULES_PATH' ) ? (string) METIS_MODULES_PATH : metis_settings_root_path() . '/system/modules';
        $seed_base = defined( 'METIS_SRC_PATH' ) ? (string) METIS_SRC_PATH : metis_settings_root_path() . '/system/src';
        $manifest_path = rtrim( $manifest_base, '/\\' ) . '/help/module.json';
        $seed_path = rtrim( $seed_base, '/\\' ) . '/Metis/Core/Help/Seeds/HelpDocumentsSeed.php';
        $problems = [];
        $route_count = 0;

        if ( ! is_file( $manifest_path ) ) {
            $problems[] = 'Help module manifest is missing.';
        } else {
            $manifest = json_decode( (string) @file_get_contents( $manifest_path ), true );
            if ( ! is_array( $manifest ) ) {
                $problems[] = 'Help module manifest is invalid JSON.';
            } else {
                $routes = (array) ( $manifest['routes'] ?? [] );
                $route_count = count( $routes );
                $route_names = [];
                foreach ( $routes as $route ) {
                    if ( is_array( $route ) && isset( $route['name'] ) ) {
                        $route_names[] = (string) $route['name'];
                    }
                }
                foreach ( [ 'help.index', 'help.search', 'help.article', 'help.category' ] as $required_route ) {
                    if ( ! in_array( $required_route, $route_names, true ) ) {
                        $problems[] = sprintf( 'Required help route "%s" is not registered.', $required_route );
                    }
                }
            }
        }

        if ( ! is_file( $seed_path ) ) {
            $problems[] = 'Help document seed file is missing.';
        }

        if ( ! class_exists( '\Metis\Core\HelpSearchStore' ) ) {
            $problems[] = 'HelpSearchStore is not loadable.';
        }

        if ( class_exists( '\Metis\Core\Application' ) && ! \Metis\Core\Application::has_service( 'help' ) ) {
            $problems[] = 'Help service is not registered.';
        }

        $article_found = false;
        $category_count = 0;
        if ( class_exists( '\Metis\Core\HelpSearchStore' ) ) {
            try {
                $store = new \Metis\Core\HelpSearchStore();
                $article_found = is_array( $store->articleBySlug( 'editing-a-user', false ) );
                $tree = $store->navigationTree();
                $category_count = is_array( $tree ) ? count( $tree ) : 0;
                if ( ! $article_found ) {
                    $problems[] = 'Seeded article "editing-a-user" is not available.';
                }
            } catch ( Throwable $exception ) {
                $problems[] = 'Help search store failed: ' . $exception->getMessage();
            }
        }

        $status = empty( $problems ) ? 'pass' : 'fail';

        return [
            'status' => $status,
            'message' => $status === 'pass'
                ? sprintf( 'Help service is hydrated with %d routes and %d populated categories.', $route_count, $category_count )
                : implode( ' ', $problems ),
            'recommendation' => $status === 'pass' ? '' : 'Run auto-remediate to seed help documents, rebuild the search index, and reload route caches.',
            'article_found' => $article_found,
            'category_count' => $category_count,
            'route_count' => $route_count,
            'problems' => $problems,
        ];
    }
}

if ( ! function_exists( 'metis_settings_health_hermes_library_status' ) ) {
    function metis_settings_health_hermes_library_status(): array {
        if ( function_exists( 'metis_register_core_services' ) ) {
            metis_register_core_services();
        }

        if ( ! class_exists( '\Metis\Core\Application' ) || ! \Metis\Core\Application::has_service( 'hermes_library' ) ) {
            return [
                'status' => 'fail',
                'message' => 'Hermes definition library service is not registered.',
                'recommendation' => 'Run auto-remediate to reload core services and rebuild Hermes definition caches.',
            ];
        }

        try {
            $library_service = \Metis\Core\Application::service( 'hermes_library' );
            if ( ! is_object( $library_service ) || ! method_exists( $library_service, 'library' ) ) {
                return [
                    'status' => 'fail',
                    'message' => 'Hermes definition library service does not expose the library loader.',
                    'recommendation' => 'Verify HermesDefinitionLibrary deployment and service registration.',
                ];
            }

            $library = (array) $library_service->library();
            $manifest = (array) ( $library['manifest'] ?? [] );
            $context_packs = (array) ( $library['context_packs'] ?? [] );
            $playbooks = (array) ( $library['playbooks'] ?? [] );
            $missions = (array) ( $library['missions'] ?? [] );
            $dynamic_layer = (array) ( $library['dynamic_layer'] ?? [] );

            $expected_context = count( (array) ( $manifest['context_packs'] ?? [] ) );
            $expected_playbooks = count( (array) ( $manifest['playbooks'] ?? [] ) );
            $expected_missions = count( (array) ( $manifest['missions'] ?? [] ) );
            $problems = [];

            if ( $expected_context < 1 || count( $context_packs ) < $expected_context ) {
                $problems[] = sprintf( 'Context packs loaded %d/%d.', count( $context_packs ), $expected_context );
            }
            if ( $expected_playbooks < 1 || count( $playbooks ) < $expected_playbooks ) {
                $problems[] = sprintf( 'Playbooks loaded %d/%d.', count( $playbooks ), $expected_playbooks );
            }
            if ( $expected_missions < 1 || count( $missions ) < $expected_missions ) {
                $problems[] = sprintf( 'Missions loaded %d/%d.', count( $missions ), $expected_missions );
            }

            foreach ( [ 'system', 'backup', 'permissions' ] as $required_context_pack ) {
                if ( ! isset( $context_packs[ $required_context_pack ] ) ) {
                    $problems[] = sprintf( 'Required context pack "%s" is missing.', $required_context_pack );
                }
            }
            foreach ( [ 'system_health_diagnostics', 'permission_diagnostics' ] as $required_playbook ) {
                if ( ! isset( $playbooks[ $required_playbook ] ) ) {
                    $problems[] = sprintf( 'Required playbook "%s" is missing.', $required_playbook );
                }
            }
            if ( empty( $dynamic_layer['schema'] ) || ! is_array( $dynamic_layer['schema'] ) ) {
                $problems[] = 'Hermes dynamic context schema did not hydrate.';
            }

            $status = empty( $problems ) ? 'pass' : 'fail';

            return [
                'status' => $status,
                'message' => $status === 'pass'
                    ? sprintf( 'Hermes library hydrated %d context packs, %d playbooks, and %d missions.', count( $context_packs ), count( $playbooks ), count( $missions ) )
                    : implode( ' ', $problems ),
                'recommendation' => $status === 'pass' ? '' : 'Run auto-remediate to clear Hermes caches and reload deployed definitions.',
                'context_packs' => count( $context_packs ),
                'playbooks' => count( $playbooks ),
                'missions' => count( $missions ),
                'problems' => $problems,
            ];
        } catch ( Throwable $exception ) {
            return [
                'status' => 'fail',
                'message' => 'Hermes definition library failed to load: ' . $exception->getMessage(),
                'recommendation' => 'Verify system/config/hermes manifests and run auto-remediate to rebuild Hermes caches.',
            ];
        }
    }
}

if ( ! function_exists( 'metis_settings_build_scheduler_snapshot' ) ) {
    function metis_settings_build_scheduler_snapshot( string $timezone, string $date_format, string $time_format ): array {
        $timezone = metis_settings_normalize_timezone( $timezone );
        $date_format = trim( $date_format ) !== '' ? $date_format : 'm/d/y';
        $time_format = trim( $time_format ) !== '' ? $time_format : 'g:i:s a';
        $system_cron_tasks = Metis_Cron_Manager::registered_tasks();
        $queue_summary = \Metis\Core\Application::has_service( 'operations' )
            ? metis_operations()->queueSummary()
            : [ 'cron' => [], 'operations' => [] ];
        $recent_async_jobs = \Metis\Core\Application::has_service( 'operations' )
            ? metis_operations()->recentJobs( 20 )
            : [];

        $task_association_map = [];
        $system_cron_task_rows = [];
        foreach ( $system_cron_tasks as $task_slug => $task_config ) {
            $task_state = metis_get_option( 'metis_cron_task_state_' . $task_slug, [] );
            $task_state = is_array( $task_state ) ? $task_state : [];
            $last_finished_at = (string) ( $task_state['last_finished_at'] ?? '' );

            $system_cron_task_rows[] = [
                'slug' => $task_slug,
                'label' => (string) ( $task_config['label'] ?? $task_slug ),
                'module' => (string) ( $task_config['module'] ?? 'core' ),
                'association' => metis_settings_scheduler_task_association( $task_slug, $task_config ),
                'enabled' => ! empty( $task_config['enabled'] ),
                'interval_minutes' => max( 1, (int) ceil( ( (int) ( $task_config['interval'] ?? 300 ) ) / MINUTE_IN_SECONDS ) ),
                'default_interval_minutes' => max( 1, (int) ceil( ( (int) ( $task_config['default_interval'] ?? $task_config['interval'] ?? 300 ) ) / MINUTE_IN_SECONDS ) ),
                'interval_label' => metis_settings_format_interval( (int) ( $task_config['interval'] ?? 300 ) ),
                'last_status' => (string) ( $task_state['last_status'] ?? 'never' ),
                'last_finished_at' => $last_finished_at,
                'last_finished_at_display' => $last_finished_at !== ''
                    ? metis_settings_format_datetime_display( $last_finished_at, $date_format, $time_format, $timezone )
                    : 'Never',
                'last_error' => (string) ( $task_state['last_error'] ?? '' ),
            ];
            $task_association_map[ $task_slug ] = metis_settings_scheduler_task_association( $task_slug, $task_config );
        }

        $recent_async_jobs = array_map( static function ( array $job_row ) use ( $date_format, $time_format, $timezone, $task_association_map, $system_cron_tasks ): array {
            $started_raw = (string) ( $job_row['started_at'] ?: $job_row['available_at'] ?: '' );
            $finished_raw = (string) ( $job_row['completed_at'] ?: $job_row['failed_at'] ?: '' );
            $reserved_until_raw = (string) ( $job_row['reserved_until'] ?? '' );
            $task_slug = metis_key_clean( (string) ( $job_row['task'] ?? '' ) );
            $task_label = '';
            if ( (string) ( $job_row['job_type'] ?? '' ) === 'system.cron.task' && $task_slug !== '' && isset( $system_cron_tasks[ $task_slug ] ) ) {
                $task_label = (string) ( $system_cron_tasks[ $task_slug ]['label'] ?? '' );
            }
            if ( $task_label === '' && $task_slug !== '' ) {
                $task_label = metis_settings_humanize_slug( $task_slug );
            }
            if ( $task_label === '' && (string) ( $job_row['job_type'] ?? '' ) === 'system.cron.task' ) {
                $task_label = (string) ( $job_row['label'] ?? 'Cron Task' );
            }
            $job_label = (string) (
                $job_row['label']
                ?: $task_label
                ?: $job_row['operation']
                ?: $job_row['task']
                ?: $job_row['job_type']
                ?: 'System job'
            );
            $job_row['started_at_display'] = $started_raw !== ''
                ? metis_settings_format_datetime_display( $started_raw, $date_format, $time_format, $timezone )
                : 'Pending';
            $job_row['finished_at_display'] = $finished_raw !== ''
                ? metis_settings_format_datetime_display( $finished_raw, $date_format, $time_format, $timezone )
                : '-';
            $job_row['reserved_until_display'] = $reserved_until_raw !== ''
                ? metis_settings_format_datetime_display( $reserved_until_raw, $date_format, $time_format, $timezone )
                : '';
            $job_row['task_label'] = $task_label;
            $job_row['association'] = (string) ( $task_association_map[ $task_slug ] ?? 'Scheduled cron callback' );
            $job_row['job_label'] = $job_label;
            return $job_row;
        }, $recent_async_jobs );

        $operations_recent_jobs = array_values( array_filter( $recent_async_jobs, static function ( array $row ): bool {
            return (string) ( $row['job_type'] ?? '' ) === 'system.operation';
        } ) );
        $system_cron_recent_jobs = array_values( array_filter( $recent_async_jobs, static function ( array $row ): bool {
            return (string) ( $row['job_type'] ?? '' ) === 'system.cron.task';
        } ) );

        return [
            'system_cron_tasks' => $system_cron_tasks,
            'queue_summary' => $queue_summary,
            'recent_async_jobs' => $recent_async_jobs,
            'operations_recent_jobs' => $operations_recent_jobs,
            'system_cron_recent_jobs' => $system_cron_recent_jobs,
            'system_cron_task_rows' => $system_cron_task_rows,
        ];
    }
}

if ( ! function_exists( 'metis_settings_build_performance_security_report' ) ) {
    function metis_settings_build_performance_security_report(): array {
        $checks = [];
        $kpis = [];
        $severity_weight = [ 'pass' => 0, 'warn' => 10, 'fail' => 25 ];

        $add_check = static function ( string $id, string $title, string $category, string $status, string $message, string $recommendation = '' ) use ( &$checks, $severity_weight ): void {
            $status = in_array( $status, [ 'pass', 'warn', 'fail' ], true ) ? $status : 'warn';
            $checks[] = [
                'id' => $id,
                'title' => $title,
                'category' => $category,
                'status' => $status,
                'message' => $message,
                'recommendation' => $recommendation,
            ];
        };

        $add_kpi = static function ( string $id, string $label, string $value, string $hint = '', string $tone = 'neutral' ) use ( &$kpis ): void {
            $kpis[] = [
                'id' => $id,
                'label' => $label,
                'value' => $value,
                'hint' => $hint,
                'tone' => in_array( $tone, [ 'neutral', 'good', 'warn', 'bad' ], true ) ? $tone : 'neutral',
            ];
        };

        $environment = function_exists( 'metis_environment_type' ) ? metis_key_clean( (string) metis_environment_type() ) : 'production';
        if ( $environment === '' ) {
            $environment = 'production';
        }
        $is_production_like = in_array( $environment, [ 'production', 'prod', 'live' ], true );
        $timezone = metis_settings_normalize_timezone( (string) Core_Settings_Service::get( 'timezone', Core_Settings_Service::get( 'site_timezone', 'UTC' ) ) );
        $date_format = (string) Core_Settings_Service::get( 'date_format', 'm/d/y' );
        $time_format = (string) Core_Settings_Service::get( 'time_format', 'g:i:s a' );
        $display_datetime = static function ( string $raw ) use ( $date_format, $time_format, $timezone ): string {
            return metis_settings_format_datetime_display( $raw, $date_format, $time_format, $timezone );
        };
        $human_bytes = static function ( $bytes ): string {
            $value = max( 0.0, (float) $bytes );
            $units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
            $unit_index = 0;
            $unit_max = count( $units ) - 1;
            while ( $value >= 1024.0 && $unit_index < $unit_max ) {
                $value /= 1024.0;
                $unit_index++;
            }

            $precision = $unit_index === 0 ? 0 : ( $value >= 100 ? 0 : ( $value >= 10 ? 1 : 2 ) );
            return number_format( $value, $precision ) . ' ' . $units[ $unit_index ];
        };

        $parse_ini_bytes = static function ( string $raw ): int {
            $raw = trim( strtolower( $raw ) );
            if ( $raw === '' || $raw === '-1' ) {
                return -1;
            }
            $unit = substr( $raw, -1 );
            $value = (float) $raw;
            return match ( $unit ) {
                'g' => (int) round( $value * 1024 * 1024 * 1024 ),
                'm' => (int) round( $value * 1024 * 1024 ),
                'k' => (int) round( $value * 1024 ),
                default => (int) round( $value ),
            };
        };

        $parse_datetime = static function ( string $raw ) use ( $timezone ): int {
            $raw = trim( $raw );
            if ( $raw === '' ) {
                return 0;
            }

            try {
                $has_timezone = (bool) preg_match( '/([zZ]|[+\-]\d{2}:?\d{2})$/', $raw );
                $date = $has_timezone
                    ? new DateTimeImmutable( $raw )
                    : new DateTimeImmutable( $raw, new DateTimeZone( metis_settings_normalize_timezone( $timezone ) ) );
                return (int) $date->getTimestamp();
            } catch ( Throwable ) {
                $ts = strtotime( $raw );
                return $ts !== false ? (int) $ts : 0;
            }
        };

        $permission_octal = static function ( string $path ): string {
            $perms = @fileperms( $path );
            if ( $perms === false ) {
                return 'unknown';
            }

            return substr( sprintf( '%o', $perms ), -4 );
        };

        $system_cron_configured = Metis_Cron_Manager::configured_secret_masked() !== '';
        $add_check(
            'cron_secret',
            'Scheduler Shared Secret',
            'security',
            $system_cron_configured ? 'pass' : 'fail',
            $system_cron_configured
                ? 'Scheduler endpoint secret is configured.'
                : 'Scheduler endpoint secret is missing.',
            $system_cron_configured ? '' : 'Set a shared secret in Scheduler settings to protect the cron endpoint.'
        );

        $display_errors_raw = strtolower( trim( (string) ini_get( 'display_errors' ) ) );
        $display_errors_on = in_array( $display_errors_raw, [ '1', 'on', 'yes', 'true' ], true );
        $add_check(
            'php_display_errors',
            'PHP Error Display',
            'security',
            $display_errors_on ? 'fail' : 'pass',
            $display_errors_on
                ? 'display_errors is enabled and may expose sensitive runtime details.'
                : 'display_errors is disabled.',
            $display_errors_on ? 'Disable display_errors in production and rely on structured logs.' : ''
        );

        $force_ssl_admin = defined( 'FORCE_SSL_ADMIN' ) ? (bool) FORCE_SSL_ADMIN : ( function_exists( 'force_ssl_admin' ) ? (bool) force_ssl_admin() : false );
        $add_check(
            'force_ssl_admin',
            'Admin TLS Enforcement',
            'security',
            $force_ssl_admin ? 'pass' : 'warn',
            $force_ssl_admin
                ? 'Admin requests enforce SSL/TLS.'
                : 'Admin SSL enforcement is not explicitly enabled.',
            $force_ssl_admin ? '' : 'Enable FORCE_SSL_ADMIN to harden admin/session traffic.'
        );

        $log_force_token = trim( (string) Core_Settings_Service::get( 'logging_force_url_token', '' ) );
        $add_check(
            'logging_force_token',
            'Logging Force Token',
            'security',
            'pass',
            $log_force_token !== ''
                ? 'Force-log URL token is configured.'
                : 'Force-log URL token is not configured (optional unless URL-triggered logging is used).',
            ''
        );

        $queue_summary = \Metis\Core\Application::has_service( 'operations' )
            ? metis_operations()->queueSummary()
            : [ 'cron' => [], 'operations' => [] ];
        $queue_backlog = (int) ( $queue_summary['cron']['queued'] ?? 0 )
            + (int) ( $queue_summary['cron']['processing'] ?? 0 )
            + (int) ( $queue_summary['operations']['queued'] ?? 0 )
            + (int) ( $queue_summary['operations']['processing'] ?? 0 );
        $queue_failed_total = (int) ( $queue_summary['cron']['failed'] ?? 0 )
            + (int) ( $queue_summary['operations']['failed'] ?? 0 );
        $queue_failed_recent = 0;
        $recent_window_seconds = 24 * HOUR_IN_SECONDS;
        $current_ts = function_exists( 'metis_current_time' ) ? (int) metis_current_time( 'timestamp' ) : time();
        $recent_jobs_for_health = \Metis\Core\Application::has_service( 'operations' )
            ? metis_operations()->recentJobs( 250 )
            : [];
        if ( ! empty( $recent_jobs_for_health ) ) {
            $recent_failed = [];
            $recent_completed_ts = [];
            foreach ( $recent_jobs_for_health as $recent_job ) {
                if ( ! is_array( $recent_job ) ) {
                    continue;
                }
                $status = strtolower( (string) ( $recent_job['status'] ?? '' ) );
                $job_type = (string) ( $recent_job['job_type'] ?? '' );
                $op = metis_key_clean( (string) ( $recent_job['operation'] ?? '' ) );
                $task = metis_key_clean( (string) ( $recent_job['task'] ?? '' ) );
                $fingerprint = $job_type === 'system.operation'
                    ? 'op:' . ( $op !== '' ? $op : (string) ( $recent_job['job_code'] ?? '' ) )
                    : 'cron:' . ( $task !== '' ? $task : (string) ( $recent_job['job_code'] ?? '' ) );
                if ( $fingerprint === 'op:' || $fingerprint === 'cron:' ) {
                    continue;
                }

                if ( $status === 'completed' ) {
                    $completed_ts = $parse_datetime( (string) ( $recent_job['completed_at'] ?? '' ) );
                    if ( $completed_ts > 0 ) {
                        $recent_completed_ts[ $fingerprint ] = max( $completed_ts, (int) ( $recent_completed_ts[ $fingerprint ] ?? 0 ) );
                    }
                    continue;
                }

                if ( $status !== 'failed' ) {
                    continue;
                }

                $failed_ts = $parse_datetime( (string) ( $recent_job['failed_at'] ?? '' ) );
                if ( $failed_ts < 1 || ( $current_ts - $failed_ts ) > $recent_window_seconds ) {
                    continue;
                }
                $recent_failed[] = [ 'fingerprint' => $fingerprint, 'failed_ts' => $failed_ts ];
            }

            foreach ( $recent_failed as $failed_item ) {
                $fingerprint = (string) ( $failed_item['fingerprint'] ?? '' );
                $failed_ts = (int) ( $failed_item['failed_ts'] ?? 0 );
                $completed_ts = (int) ( $recent_completed_ts[ $fingerprint ] ?? 0 );
                if ( $completed_ts <= $failed_ts ) {
                    $queue_failed_recent++;
                }
            }
        }
        $queue_backlog_warn = $is_production_like ? 10 : 20;
        $queue_backlog_fail = $is_production_like ? 40 : 100;
        $queue_failed_warn = $is_production_like ? 1 : 1;
        $queue_failed_fail = $is_production_like ? 5 : 10;
        $backlog_status = $queue_backlog > $queue_backlog_fail ? 'fail' : ( $queue_backlog > $queue_backlog_warn ? 'warn' : 'pass' );
        $failed_status = $queue_failed_recent > $queue_failed_fail ? 'fail' : ( $queue_failed_recent >= $queue_failed_warn ? 'warn' : 'pass' );

        $add_check(
            'queue_backlog',
            'Async Queue Backlog',
            'performance',
            $backlog_status,
            sprintf( 'Current backlog is %d jobs.', $queue_backlog ),
            $backlog_status === 'pass' ? '' : 'Increase queue processing cadence and review long-running operations.'
        );

        $add_check(
            'queue_failures',
            'Async Queue Failures',
            'performance',
            $failed_status,
            sprintf( 'Failed jobs in last 24h: %d (total retained failed jobs: %d).', $queue_failed_recent, $queue_failed_total ),
            $failed_status === 'pass' ? '' : 'Review failed operation and cron jobs, then replay or fix root causes.'
        );

        if ( function_exists( 'metis_operations' ) ) {
            metis_operations();
        }
        $registered_workers = function_exists( 'metis_job_queue' ) ? metis_job_queue()->registeredWorkers() : [];
        $required_workers = [ 'system.cron.task', 'system.operation' ];
        $missing_workers = array_values( array_filter( $required_workers, static function ( string $worker ) use ( $registered_workers ): bool {
            return ! in_array( $worker, $registered_workers, true );
        } ) );
        $add_check(
            'queue_worker_registration',
            'Queue Worker Registration',
            'performance',
            empty( $missing_workers ) ? 'pass' : 'fail',
            empty( $missing_workers )
                ? 'Core cron and operation workers are registered for queue processing.'
                : 'Missing queue workers: ' . implode( ', ', $missing_workers ) . '.',
            empty( $missing_workers ) ? '' : 'Load core services before draining the queue so scheduled work has executable workers.'
        );

        $service_hydration = metis_settings_health_service_hydration_status();
        $add_check(
            'core_service_hydration',
            'Core Service Hydration',
            'resilience',
            (string) ( $service_hydration['status'] ?? 'fail' ),
            (string) ( $service_hydration['message'] ?? 'Core service hydration could not be verified.' ),
            (string) ( $service_hydration['recommendation'] ?? 'Run auto-remediate and inspect service boot failures.' )
        );

        $help_service = metis_settings_health_help_service_status();
        $add_check(
            'help_service_hydration',
            'Help Service Hydration',
            'resilience',
            (string) ( $help_service['status'] ?? 'fail' ),
            (string) ( $help_service['message'] ?? 'Help service hydration could not be verified.' ),
            (string) ( $help_service['recommendation'] ?? 'Run auto-remediate to rebuild help service data.' )
        );

        $hermes_library = metis_settings_health_hermes_library_status();
        $add_check(
            'hermes_definition_library',
            'Hermes Definition Library',
            'security',
            (string) ( $hermes_library['status'] ?? 'fail' ),
            (string) ( $hermes_library['message'] ?? 'Hermes definition library hydration could not be verified.' ),
            (string) ( $hermes_library['recommendation'] ?? 'Run auto-remediate to rebuild Hermes definition caches.' )
        );

        $system_cron_tasks = Metis_Cron_Manager::registered_tasks();
        $critical_tasks = [ 'background_job_processing', 'cache_cleanup', 'data_retention_cleanup', 'integrity_scan' ];
        $disabled_critical = [];
        foreach ( $critical_tasks as $task_slug ) {
            if ( isset( $system_cron_tasks[ $task_slug ] ) && empty( $system_cron_tasks[ $task_slug ]['enabled'] ) ) {
                $disabled_critical[] = $task_slug;
            }
        }
        $critical_status = empty( $disabled_critical ) ? 'pass' : ( in_array( 'background_job_processing', $disabled_critical, true ) ? 'fail' : 'warn' );
        $add_check(
            'critical_cron_tasks',
            'Critical Cron Tasks',
            'performance',
            $critical_status,
            empty( $disabled_critical )
                ? 'Critical maintenance tasks are enabled.'
                : 'Disabled critical tasks: ' . implode( ', ', $disabled_critical ) . '.',
            empty( $disabled_critical ) ? '' : 'Re-enable critical tasks in Scheduler settings.'
        );

        $retention_snapshot = [];
        $retention_total_rows = 0;
        $retention_expired_rows = 0;
        $retention_largest_label = '';
        $retention_largest_rows = 0;
        $retention_status = 'warn';
        $retention_message = 'Data retention service could not be inspected.';
        $retention_recommendation = 'Verify the data retention service registration and run auto-remediate.';
        if ( function_exists( 'metis_data_retention' ) ) {
            try {
                $retention_snapshot = (array) metis_data_retention()->snapshot();
                $retention_total_rows = (int) ( $retention_snapshot['total_rows'] ?? 0 );
                $retention_expired_rows = (int) ( $retention_snapshot['total_expired_rows'] ?? 0 );
                $largest_policy = is_array( $retention_snapshot['largest_policy'] ?? null ) ? (array) $retention_snapshot['largest_policy'] : [];
                $retention_largest_label = (string) ( $largest_policy['label'] ?? '' );
                $retention_largest_rows = (int) ( $largest_policy['rows'] ?? 0 );
                $retention_enabled = ! empty( $retention_snapshot['enabled'] );
                if ( ! $retention_enabled ) {
                    $retention_status = 'warn';
                    $retention_message = 'Data retention cleanup is disabled.';
                    $retention_recommendation = 'Enable data retention cleanup to keep operational tables bounded.';
                } else {
                    $retention_status = $retention_expired_rows > 50000 ? 'fail' : ( $retention_expired_rows > 10000 ? 'warn' : 'pass' );
                    $retention_message = sprintf(
                        'Retention tracks %s governed rows; %s rows are beyond policy windows.',
                        number_format( $retention_total_rows ),
                        number_format( $retention_expired_rows )
                    );
                    if ( $retention_largest_label !== '' ) {
                        $retention_message .= sprintf( ' Largest tracked set: %s (%s rows).', $retention_largest_label, number_format( $retention_largest_rows ) );
                    }
                    $retention_recommendation = $retention_status === 'pass'
                        ? ''
                        : 'Run the Data Retention Cleanup scheduler task and review policies if expired rows continue to grow.';
                }
            } catch ( Throwable $exception ) {
                $retention_status = 'fail';
                $retention_message = 'Data retention inspection failed: ' . $exception->getMessage();
                $retention_recommendation = 'Review database connectivity and retention policy table mappings.';
            }
        }
        $add_check(
            'data_retention_cleanup',
            'Data Retention Cleanup',
            'performance',
            $retention_status,
            $retention_message,
            $retention_recommendation
        );

        $compiled_config_cached = class_exists( '\Metis\Core\Cache\CacheService' )
            && \Metis\Core\Cache\CacheService::get( 'configuration.compiled' ) !== null;
        if ( ! $compiled_config_cached && class_exists( '\Metis\Core\Cache\CacheService' ) && function_exists( 'metis_standalone_compiled_config' ) ) {
            try {
                \Metis\Core\Cache\CacheService::set( 'configuration.compiled', metis_standalone_compiled_config( true ), 3600 );
                $compiled_config_cached = \Metis\Core\Cache\CacheService::get( 'configuration.compiled' ) !== null;
            } catch ( Throwable ) {
                $compiled_config_cached = false;
            }
        }
        $add_check(
            'compiled_config_cache',
            'Compiled Config Cache',
            'performance',
            $compiled_config_cached ? 'pass' : 'warn',
            $compiled_config_cached
                ? 'Compiled configuration cache is available.'
                : 'Compiled configuration cache is cold or missing.',
            $compiled_config_cached ? '' : 'Rebuild caches to warm configuration.compiled and reduce repeated config work.'
        );

        $log_size_bytes = 0;
        if ( class_exists( 'Metis_Logger' ) ) {
            $log_path = (string) Metis_Logger::viewer_log_path( '' );
            if ( $log_path !== '' && is_file( $log_path ) ) {
                $size = filesize( $log_path );
                $log_size_bytes = $size !== false ? (int) $size : 0;
            }
        }
        $log_size_status = $log_size_bytes > 100 * 1024 * 1024 ? 'fail' : ( $log_size_bytes > 20 * 1024 * 1024 ? 'warn' : 'pass' );
        $add_check(
            'log_file_size',
            'Log File Size',
            'performance',
            $log_size_status,
            sprintf( 'Primary log size is %s.', $human_bytes( $log_size_bytes ) ),
            $log_size_status === 'pass' ? '' : 'Rotate or clear oversized logs to keep IO and log viewer performance stable.'
        );

        $memory_limit_raw = (string) ini_get( 'memory_limit' );
        $memory_limit_bytes = $parse_ini_bytes( $memory_limit_raw );
        $memory_limit_status = 'pass';
        if ( $memory_limit_bytes > 0 && $memory_limit_bytes < 128 * 1024 * 1024 ) {
            $memory_limit_status = 'fail';
        } elseif ( $memory_limit_bytes > 0 && $memory_limit_bytes < 256 * 1024 * 1024 ) {
            $memory_limit_status = 'warn';
        }
        $add_check(
            'php_memory_limit',
            'PHP Memory Limit',
            'performance',
            $memory_limit_status,
            sprintf( 'memory_limit is %s.', $memory_limit_raw !== '' ? $memory_limit_raw : 'unset' ),
            $memory_limit_status === 'pass' ? '' : 'Increase memory_limit for heavy admin tasks, exports, and release operations.'
        );

        $max_execution_time = (int) ini_get( 'max_execution_time' );
        $execution_status = ( $max_execution_time > 0 && $max_execution_time < 30 ) ? 'warn' : 'pass';
        $add_check(
            'php_max_execution_time',
            'PHP Max Execution Time',
            'performance',
            $execution_status,
            sprintf( 'max_execution_time is %d seconds.', $max_execution_time ),
            $execution_status === 'pass' ? '' : 'Increase execution time budget for admin operations or ensure heavy jobs run asynchronously.'
        );

        foreach ( [ 'curl', 'zip' ] as $required_extension ) {
            $extension_loaded = extension_loaded( $required_extension );
            $add_check(
                'php_extension_' . $required_extension,
                'PHP Extension: ' . strtoupper( $required_extension ),
                'security',
                $extension_loaded ? 'pass' : 'fail',
                $extension_loaded ? 'Available.' : 'Required PHP extension is not loaded.',
                $extension_loaded ? '' : 'Enable this PHP extension before running release, backup, and remote metadata operations.'
            );
        }

        foreach ( [ 'proc_open', 'proc_close', 'proc_get_status', 'proc_terminate' ] as $required_function ) {
            $function_available = function_exists( $required_function );
            $add_check(
                'php_function_' . $required_function,
                'PHP Function: ' . $required_function,
                'security',
                $function_available ? 'pass' : 'fail',
                $function_available ? 'Available.' : 'Required PHP process function is disabled.',
                $function_available ? '' : 'Enable required PHP process functions for full release, recovery, integrity, and diagnostics support.'
            );
        }

        $disk_target = defined( 'METIS_ROOT' ) ? (string) METIS_ROOT : ( defined( 'METIS_PATH' ) ? (string) METIS_PATH : dirname( __DIR__, 3 ) );
        $disk_free = @disk_free_space( $disk_target );
        $disk_total = @disk_total_space( $disk_target );
        $disk_free = $disk_free !== false ? (int) $disk_free : 0;
        $disk_total = $disk_total !== false ? (int) $disk_total : 0;
        $disk_percent_free = $disk_total > 0 ? (int) floor( ( $disk_free / $disk_total ) * 100 ) : 0;
        $disk_status = $disk_free < 1024 * 1024 * 1024
            ? 'fail'
            : ( $disk_free < 5 * 1024 * 1024 * 1024 ? 'warn' : 'pass' );
        $add_check(
            'disk_capacity',
            'Disk Capacity',
            'performance',
            $disk_status,
            sprintf(
                'Free disk is %s (%d%% free).',
                $human_bytes( $disk_free ),
                $disk_percent_free
            ),
            $disk_status === 'pass' ? '' : 'Increase free disk capacity to protect backups, logs, and queue durability.'
        );

        $cpu_load_status = 'pass';
        $cpu_load_message = 'Load average unavailable on this runtime.';
        if ( function_exists( 'sys_getloadavg' ) ) {
            $load = sys_getloadavg();
            if ( is_array( $load ) && isset( $load[0] ) ) {
                $cpu_count = 1;
                if ( is_readable( '/proc/cpuinfo' ) ) {
                    $cpuinfo = (string) @file_get_contents( '/proc/cpuinfo' );
                    if ( $cpuinfo !== '' ) {
                        $count = preg_match_all( '/^processor\\s*:/m', $cpuinfo );
                        if ( is_int( $count ) && $count > 0 ) {
                            $cpu_count = $count;
                        }
                    }
                } elseif ( getenv( 'NUMBER_OF_PROCESSORS' ) !== false ) {
                    $cpu_count = max( 1, (int) getenv( 'NUMBER_OF_PROCESSORS' ) );
                }

                $load_one = (float) $load[0];
                $normalized_load = $cpu_count > 0 ? $load_one / $cpu_count : $load_one;
                $warn_threshold = $is_production_like ? 0.90 : 1.00;
                $fail_threshold = $is_production_like ? 1.20 : 1.50;
                $cpu_load_status = $normalized_load >= $fail_threshold ? 'fail' : ( $normalized_load >= $warn_threshold ? 'warn' : 'pass' );
                $cpu_load_message = sprintf(
                    'Load average is %.2f (normalized %.2f across %d CPU core%s).',
                    $load_one,
                    $normalized_load,
                    $cpu_count,
                    $cpu_count === 1 ? '' : 's'
                );
            }
        }
        $add_check(
            'cpu_load_capacity',
            'CPU Load Capacity',
            'performance',
            $cpu_load_status,
            $cpu_load_message,
            $cpu_load_status === 'pass' ? '' : 'Reduce synchronous workload and scale worker/host capacity.'
        );

        $filesystem_targets = metis_settings_health_filesystem_targets();

        foreach ( $filesystem_targets as $entry ) {
            $path = (string) ( $entry['path'] ?? '' );
            $label = (string) ( $entry['label'] ?? $path );
            $type = (string) ( $entry['type'] ?? 'runtime' );
            $required = ! array_key_exists( 'required', $entry ) || ! empty( $entry['required'] );
            $exists = is_dir( $path ) || is_file( $path );
            if ( ! $exists ) {
                $add_check(
                    metis_settings_health_filesystem_check_id( $label ),
                    'Filesystem Permissions: ' . $label,
                    'security',
                    $required ? 'warn' : 'pass',
                    $required
                        ? sprintf( '%s path is missing.', $label )
                        : sprintf( '%s compatibility path is absent; no legacy root is currently active.', $label ),
                    $required ? 'Ensure this path exists with expected permissions.' : 'Create this path only when importing or reading legacy upload data.'
                );
                continue;
            }

            $octal = $permission_octal( $path );
            $is_world_writable = ( @fileperms( $path ) & 0x0002 ) !== 0;
            $is_writable = is_writable( $path );

            if ( $type === 'sensitive' ) {
                $status = $is_world_writable ? ( $is_production_like ? 'fail' : 'warn' ) : 'pass';
                $add_check(
                    metis_settings_health_filesystem_check_id( $label ),
                    'Filesystem Permissions: ' . $label,
                    'security',
                    $status,
                    sprintf( '%s permissions are %s.', $label, $octal ),
                    $status === 'pass' ? '' : 'Remove world-writable permissions from source/config paths.'
                );
                continue;
            }

            if ( ! $is_writable ) {
                $add_check(
                    metis_settings_health_filesystem_check_id( $label ),
                    'Filesystem Permissions: ' . $label,
                    'security',
                    'fail',
                    sprintf( '%s is not writable (mode %s).', $label, $octal ),
                    'Grant runtime write access for storage paths used by cache/logs/uploads.'
                );
                continue;
            }

            $runtime_status = $is_world_writable ? ( $is_production_like ? 'warn' : 'warn' ) : 'pass';
            $add_check(
                metis_settings_health_filesystem_check_id( $label ),
                'Filesystem Permissions: ' . $label,
                'security',
                $runtime_status,
                sprintf( '%s permissions are %s.', $label, $octal ),
                $runtime_status === 'pass' ? '' : 'Prefer group-scoped write permissions over world-writable mode.'
            );
        }

        $enclave_ready = function_exists( 'metis_security_enclave' )
            && function_exists( 'metis_security_enforce_ajax_request' )
            && function_exists( 'metis_security_register_ajax_policies' );
        $add_check(
            'secure_enclave_runtime',
            'Secure Enclave Runtime',
            'security',
            $enclave_ready ? 'pass' : 'fail',
            $enclave_ready
                ? 'Secure Enclave runtime bridge and AJAX enforcement hooks are available.'
                : 'Secure Enclave runtime bridge is not fully available.',
            $enclave_ready ? '' : 'Ensure SecurityRuntimeBridge loads and metis_security_enforce_ajax_request is registered.'
        );

        if ( $enclave_ready ) {
            metis_security_register_ajax_policies();
            $enclave = metis_security_enclave();
            $critical_enclave_ops = [
                'route.system_cron',
                'module.view.settings',
                'ajax.settings.metis_settings_save_section',
                'ajax.settings.metis_settings_checker_snapshot',
            ];
            $missing_ops = array_values( array_filter( $critical_enclave_ops, static function ( string $operation ) use ( $enclave ): bool {
                return ! $enclave->has_policy( $operation );
            } ) );

            $critical_status = empty( $missing_ops ) ? 'pass' : 'fail';
            $add_check(
                'secure_enclave_policy_coverage',
                'Secure Enclave Policy Coverage',
                'security',
                $critical_status,
                empty( $missing_ops )
                    ? 'Critical route and AJAX operations are registered in enclave policies.'
                    : 'Missing policies: ' . implode( ', ', $missing_ops ) . '.',
                empty( $missing_ops ) ? '' : 'Register missing policies before allowing sensitive actions.'
            );
        }

        if ( class_exists( '\Metis\Hermes\HermesUniversalActionRegistry' ) && function_exists( 'metis_security_enclave' ) ) {
            $registry = new \Metis\Hermes\HermesUniversalActionRegistry();
            $actions = $registry->all();
            $enclave = metis_security_enclave();

            $write_without_enclave = [];
            $missing_hermes_enclave_policies = [];
            foreach ( $actions as $action_key => $action_def ) {
                $read_only = ! empty( $action_def['read_only'] );
                $enclave_op = trim( (string) ( $action_def['enclave_op'] ?? '' ) );
                if ( ! $read_only && $enclave_op === '' ) {
                    $write_without_enclave[] = (string) $action_key;
                    continue;
                }
                if ( $enclave_op !== '' && ! $enclave->has_policy( $enclave_op ) ) {
                    $missing_hermes_enclave_policies[] = $enclave_op;
                }
            }

            $missing_hermes_enclave_policies = array_values( array_unique( $missing_hermes_enclave_policies ) );
            $hermes_enclave_status = ( empty( $write_without_enclave ) && empty( $missing_hermes_enclave_policies ) ) ? 'pass' : 'fail';
            $hermes_message = 'Hermes write/system actions are mapped to enclave operations and registered policies.';
            if ( ! empty( $write_without_enclave ) || ! empty( $missing_hermes_enclave_policies ) ) {
                $parts = [];
                if ( ! empty( $write_without_enclave ) ) {
                    $parts[] = 'write actions missing enclave_op: ' . implode( ', ', $write_without_enclave );
                }
                if ( ! empty( $missing_hermes_enclave_policies ) ) {
                    $parts[] = 'unregistered enclave policies: ' . implode( ', ', $missing_hermes_enclave_policies );
                }
                $hermes_message = implode( '; ', $parts ) . '.';
            }

            $add_check(
                'hermes_enclave_strictness',
                'Hermes Enclave Strictness',
                'security',
                $hermes_enclave_status,
                $hermes_message,
                $hermes_enclave_status === 'pass' ? '' : 'Ensure every non-read-only Hermes action requires a registered enclave operation.'
            );
        }

        $security_offense_total = 0;
        $security_offense_top = '';
        $security_offense_breakdown = [];
        $security_cutoff = metis_settings_recent_cutoff( 7 );
        if ( class_exists( 'Metis_Tables' ) ) {
            $security_table = Metis_Tables::get( 'audit_security' );
            $security_offense_clause = metis_settings_health_security_offense_clause();
            $security_offense_exclusion_clause = metis_settings_health_security_offense_exclusion_clause();
            $security_offense_total = (int) metis_db()->scalar(
                "SELECT COUNT(*)
                 FROM {$security_table}
                 WHERE occurred_at >= %s
                   AND {$security_offense_clause}
                   AND {$security_offense_exclusion_clause}",
                [ $security_cutoff ]
            );
            $security_top_rows = metis_db()->fetchAll(
                "SELECT action_type, COUNT(*) AS total
                 FROM {$security_table}
                 WHERE occurred_at >= %s
                   AND {$security_offense_clause}
                   AND {$security_offense_exclusion_clause}
                 GROUP BY action_type
                 ORDER BY total DESC
                 LIMIT 1",
                [ $security_cutoff ]
            );
            if ( is_array( $security_top_rows ) && ! empty( $security_top_rows[0]['action_type'] ) ) {
                $security_offense_top = (string) $security_top_rows[0]['action_type'];
            }

            $security_offense_rows = metis_db()->fetchAll(
                "SELECT module_slug, action_type, resource_label, COUNT(*) AS total
                 FROM {$security_table}
                 WHERE occurred_at >= %s
                   AND {$security_offense_clause}
                   AND {$security_offense_exclusion_clause}
                 GROUP BY module_slug, action_type, resource_label
                 ORDER BY total DESC
                 LIMIT 3",
                [ $security_cutoff ]
            ) ?: [];

            foreach ( $security_offense_rows as $offense_row ) {
                $module = trim( (string) ( $offense_row['module_slug'] ?? '' ) );
                $action = trim( (string) ( $offense_row['action_type'] ?? '' ) );
                $resource = trim( (string) ( $offense_row['resource_label'] ?? '' ) );
                $count = (int) ( $offense_row['total'] ?? 0 );
                if ( $count < 1 ) {
                    continue;
                }

                $descriptor = ( $module !== '' ? $module : 'unknown-module' ) . '/' . ( $action !== '' ? $action : 'unknown-action' );
                if ( $resource !== '' ) {
                    $descriptor .= ' [' . $resource . ']';
                }
                $security_offense_breakdown[] = $descriptor . ': ' . $count;
            }
        }
        $security_offense_status = $security_offense_total > ( $is_production_like ? 100 : 200 )
            ? 'fail'
            : ( $security_offense_total > ( $is_production_like ? 20 : 50 ) ? 'warn' : 'pass' );
        $add_check(
            'security_offenses_7d',
            'Repeated Security Offenses (7d)',
            'security',
            $security_offense_status,
            $security_offense_total < 1
                ? 'No high-signal security offense events were recorded in audit data for the last 7 days.'
                : sprintf(
                    '%d high-signal security offense events in audit data for 7 days. Top repeated indicators: %s.',
                    $security_offense_total,
                    ! empty( $security_offense_breakdown )
                        ? implode( '; ', $security_offense_breakdown )
                        : ( $security_offense_top !== '' ? $security_offense_top : 'none identified' )
                ),
            $security_offense_status === 'pass' ? '' : 'Investigate repeated login, credential, lockout, cron-secret, or threat indicators.'
        );

        $brute_force_total = 0;
        $rate_limit_total = 0;
        $brute_force_top = [];
        $rate_limit_top = [];
        if ( class_exists( 'Metis_Tables' ) ) {
            $security_table = Metis_Tables::get( 'audit_security' );
            $security_totals = metis_db()->fetchAll(
                "SELECT
                    SUM(
                        CASE
                            WHEN LOWER(action_type) = 'login_failed'
                                OR LOWER(action_type) LIKE '%brute%'
                                OR LOWER(action_type) LIKE '%credential%'
                            THEN 1 ELSE 0
                        END
                    ) AS brute_total,
                    SUM(
                        CASE
                            WHEN LOWER(action_type) = 'security_rate_limit_triggered'
                                OR LOWER(action_type) = 'enclave.denied_rate_limit'
                                OR LOWER(action_type) = 'rate_limited'
                                OR LOWER(action_type) LIKE '%rate_limit%'
                                OR LOWER(action_type) LIKE '%rate-lim%'
                                OR LOWER(action_type) LIKE '%429%'
                            THEN 1 ELSE 0
                        END
                     ) AS rate_total
                 FROM {$security_table}
                 WHERE occurred_at >= %s",
                [ $security_cutoff ]
            );
            $security_totals_row = is_array( $security_totals ) && isset( $security_totals[0] ) && is_array( $security_totals[0] )
                ? $security_totals[0]
                : [];
            $brute_force_total = (int) ( $security_totals_row['brute_total'] ?? 0 );
            $rate_limit_total = (int) ( $security_totals_row['rate_total'] ?? 0 );

            $brute_force_top_rows = metis_db()->fetchAll(
                "SELECT module_slug, action_type, resource_label, COUNT(*) AS total
                 FROM {$security_table}
                 WHERE occurred_at >= %s
                   AND (
                        LOWER(action_type) = 'login_failed'
                        OR LOWER(action_type) LIKE '%brute%'
                        OR LOWER(action_type) LIKE '%credential%'
                   )
                 GROUP BY module_slug, action_type, resource_label
                 ORDER BY total DESC
                 LIMIT 3",
                [ $security_cutoff ]
            ) ?: [];
            foreach ( $brute_force_top_rows as $offense_row ) {
                $module = trim( (string) ( $offense_row['module_slug'] ?? '' ) );
                $action = trim( (string) ( $offense_row['action_type'] ?? '' ) );
                $resource = trim( (string) ( $offense_row['resource_label'] ?? '' ) );
                $count = (int) ( $offense_row['total'] ?? 0 );
                if ( $count < 1 ) {
                    continue;
                }
                $descriptor = ( $module !== '' ? $module : 'unknown-module' ) . '/' . ( $action !== '' ? $action : 'unknown-action' );
                if ( $resource !== '' ) {
                    $descriptor .= ' [' . $resource . ']';
                }
                $brute_force_top[] = $descriptor . ': ' . $count;
            }

            $rate_limit_top_rows = metis_db()->fetchAll(
                "SELECT module_slug, action_type, resource_label, COUNT(*) AS total
                 FROM {$security_table}
                 WHERE occurred_at >= %s
                   AND (
                        LOWER(action_type) = 'security_rate_limit_triggered'
                        OR LOWER(action_type) = 'enclave.denied_rate_limit'
                        OR LOWER(action_type) = 'rate_limited'
                        OR LOWER(action_type) LIKE '%rate_limit%'
                        OR LOWER(action_type) LIKE '%rate-lim%'
                        OR LOWER(action_type) LIKE '%429%'
                   )
                 GROUP BY module_slug, action_type, resource_label
                 ORDER BY total DESC
                 LIMIT 3",
                [ $security_cutoff ]
            ) ?: [];
            foreach ( $rate_limit_top_rows as $offense_row ) {
                $module = trim( (string) ( $offense_row['module_slug'] ?? '' ) );
                $action = trim( (string) ( $offense_row['action_type'] ?? '' ) );
                $resource = trim( (string) ( $offense_row['resource_label'] ?? '' ) );
                $count = (int) ( $offense_row['total'] ?? 0 );
                if ( $count < 1 ) {
                    continue;
                }
                $descriptor = ( $module !== '' ? $module : 'unknown-module' ) . '/' . ( $action !== '' ? $action : 'unknown-action' );
                if ( $resource !== '' ) {
                    $descriptor .= ' [' . $resource . ']';
                }
                $rate_limit_top[] = $descriptor . ': ' . $count;
            }
        }

        $brute_force_status = $brute_force_total >= ( $is_production_like ? 30 : 80 )
            ? 'fail'
            : ( $brute_force_total >= ( $is_production_like ? 8 : 25 ) ? 'warn' : 'pass' );
        $add_check(
            'brute_force_events_7d',
            'Brute Force Indicators (7d)',
            'security',
            $brute_force_status,
            $brute_force_total < 1
                ? 'No brute-force indicators were logged in the last 7 days.'
                : sprintf(
                    '%d brute-force indicator events in 7 days. Top sources: %s.',
                    $brute_force_total,
                    ! empty( $brute_force_top ) ? implode( '; ', $brute_force_top ) : 'none identified'
                ),
            $brute_force_status === 'pass' ? '' : 'Investigate repeated login failures and enforce stricter lockout/MFA controls.'
        );

        $rate_limit_status = $rate_limit_total >= ( $is_production_like ? 120 : 300 )
            ? 'fail'
            : ( $rate_limit_total >= ( $is_production_like ? 25 : 80 ) ? 'warn' : 'pass' );
        $add_check(
            'rate_limit_events_7d',
            'Rate Limiting Events (7d)',
            'security',
            $rate_limit_status,
            $rate_limit_total < 1
                ? 'No rate limiting events were logged in the last 7 days.'
                : sprintf(
                    '%d rate limiting events in 7 days. Top sources: %s.',
                    $rate_limit_total,
                    ! empty( $rate_limit_top ) ? implode( '; ', $rate_limit_top ) : 'none identified'
                ),
            $rate_limit_status === 'pass' ? '' : 'Review repeated bursts by module/action and tune limits or upstream traffic controls.'
        );

        $last_backup_completed_at = '';
        $last_backup_ts = 0;
        $backup_history_error = '';
        try {
            $backup_runs = function_exists( 'metis_backup_list_runs' ) ? (array) metis_backup_list_runs( 20 ) : [];
        } catch ( Throwable $exception ) {
            $backup_runs = [];
            $backup_history_error = 'Backup run history could not be inspected.';
        }
        foreach ( $backup_runs as $run ) {
            if ( ! is_array( $run ) ) {
                continue;
            }
            if ( strtolower( (string) ( $run['status'] ?? '' ) ) !== 'success' ) {
                continue;
            }
            $candidate = (string) ( $run['completed_at'] ?? $run['updated_at'] ?? $run['started_at'] ?? '' );
            $candidate_ts = $parse_datetime( $candidate );
            if ( $candidate_ts > $last_backup_ts ) {
                $last_backup_ts = $candidate_ts;
                $last_backup_completed_at = $candidate;
            }
        }

        $backup_source = 'run_history';
        if ( $last_backup_ts < 1 ) {
            $artifact = metis_settings_latest_backup_artifact();
            $artifact_ts = (int) ( $artifact['timestamp'] ?? 0 );
            if ( $artifact_ts > 0 ) {
                $last_backup_ts = $artifact_ts;
                $last_backup_completed_at = (string) ( $artifact['raw'] ?? date( 'Y-m-d H:i:s', $artifact_ts ) );
                $backup_source = 'local_artifact';
            }
        }

        $backup_age_hours = $last_backup_ts > 0 ? (int) floor( ( time() - $last_backup_ts ) / HOUR_IN_SECONDS ) : PHP_INT_MAX;
        $backup_warn_hours = $is_production_like ? 36 : ( 7 * 24 );
        $backup_fail_hours = $is_production_like ? 72 : ( 14 * 24 );
        $backup_status = $backup_age_hours >= $backup_fail_hours ? 'fail' : ( $backup_age_hours >= $backup_warn_hours ? 'warn' : 'pass' );
        if ( $last_backup_ts < 1 ) {
            $backup_status = $backup_history_error !== '' ? 'warn' : 'fail';
        }
        $add_check(
            'backup_recency',
            'Last Successful Backup',
            'resilience',
            $backup_status,
            $last_backup_ts < 1
                ? ( $backup_history_error !== '' ? $backup_history_error : 'No successful backup run found.' )
                : (
                    $backup_source === 'local_artifact'
                        ? sprintf( 'Last backup artifact found at %s (%d hours ago).', $display_datetime( $last_backup_completed_at ), $backup_age_hours )
                        : sprintf( 'Last successful backup completed at %s (%d hours ago).', $display_datetime( $last_backup_completed_at ), $backup_age_hours )
                ),
            $backup_status === 'pass'
                ? ( $backup_source === 'local_artifact' ? 'Backup history was empty; verify scheduled runs continue recording history.' : '' )
                : 'Run a backup now and verify the scheduled backup cadence.'
        );

        $stale_cron_tasks = [];
        $pending_initial_cron_tasks = [];
        $failed_cron_tasks = [];
        foreach ( Metis_Cron_Manager::registered_tasks() as $task_slug => $task_config ) {
            if ( empty( $task_config['enabled'] ) ) {
                continue;
            }
            $state = metis_get_option( 'metis_cron_task_state_' . $task_slug, [] );
            $state = is_array( $state ) ? $state : [];
            $last_finished = (string) ( $state['last_finished_at'] ?? '' );
            $last_started = (string) ( $state['last_started_at'] ?? '' );
            $last_status = metis_key_clean( (string) ( $state['last_status'] ?? '' ) );
            $last_ts = $parse_datetime( $last_finished );
            $last_started_ts = $parse_datetime( $last_started );
            $interval = max( 60, (int) ( $task_config['interval'] ?? 300 ) );
            $lock_ttl = max( 60, (int) ( $task_config['lock_ttl'] ?? 900 ) );
            $stale_threshold = max( 2 * HOUR_IN_SECONDS, $interval * 6 );
            $running_threshold = max( 30 * MINUTE_IN_SECONDS, $lock_ttl * 2 );

            if ( $last_status === 'failed' ) {
                $failed_cron_tasks[] = $task_slug;
                continue;
            }

            if ( ! empty( $state['running'] ) && $last_started_ts > 0 && ( $current_ts - $last_started_ts ) > $running_threshold ) {
                $stale_cron_tasks[] = $task_slug;
                continue;
            }

            if ( $last_ts < 1 ) {
                $pending_initial_cron_tasks[] = $task_slug;
                continue;
            }

            if ( ( $current_ts - $last_ts ) > $stale_threshold ) {
                $stale_cron_tasks[] = $task_slug;
            }
        }
        $cron_problem_count = count( $stale_cron_tasks ) + count( $failed_cron_tasks );
        $cron_health_status = $cron_problem_count < 1 ? 'pass' : ( $cron_problem_count > 2 ? 'fail' : 'warn' );
        $cron_health_message = 'Enabled cron tasks with execution history are within expected windows.';
        if ( ! empty( $pending_initial_cron_tasks ) ) {
            $cron_health_message .= ' Pending first completion: ' . implode( ', ', $pending_initial_cron_tasks ) . '.';
        }
        if ( ! empty( $failed_cron_tasks ) ) {
            $cron_health_message = 'Cron tasks with last failed status: ' . implode( ', ', $failed_cron_tasks ) . '.';
            if ( ! empty( $stale_cron_tasks ) ) {
                $cron_health_message .= ' Stale tasks: ' . implode( ', ', $stale_cron_tasks ) . '.';
            }
        } elseif ( ! empty( $stale_cron_tasks ) ) {
            $cron_health_message = 'Stale cron tasks: ' . implode( ', ', $stale_cron_tasks ) . '.';
        }
        $add_check(
            'cron_execution_health',
            'Cron Execution Health',
            'performance',
            $cron_health_status,
            $cron_health_message,
            $cron_health_status === 'pass' ? '' : 'Verify scheduler trigger frequency and rerun failed or stale tasks manually.'
        );

        $webhook_processed_7d = 0;
        $webhook_failed_7d = 0;
        $webhook_last_processed_at = '';
        if ( function_exists( 'metis_webhook_ensure_schema' ) && class_exists( 'Metis_Tables' ) ) {
            metis_webhook_ensure_schema();
            $webhook_table = Metis_Tables::get( 'webhook_events' );
            $webhook_cutoff = metis_settings_recent_cutoff( 7 );
            $webhook_summary_rows = metis_db()->fetchAll(
                "SELECT status, COUNT(*) AS total
                 FROM {$webhook_table}
                 WHERE created_at >= %s
                 GROUP BY status",
                [ $webhook_cutoff ]
            );
            foreach ( $webhook_summary_rows as $summary_row ) {
                $status = strtolower( (string) ( $summary_row['status'] ?? '' ) );
                $total = (int) ( $summary_row['total'] ?? 0 );
                if ( $status === 'processed' ) {
                    $webhook_processed_7d += $total;
                } elseif ( $status === 'failed' ) {
                    $webhook_failed_7d += $total;
                }
            }

            $webhook_last_processed_at = (string) metis_db()->scalar(
                "SELECT MAX(processed_at) FROM {$webhook_table} WHERE status = 'processed'"
            );
        }

        $webhook_secret_configured = str_starts_with( metis_settings_get_credential_value( 'stripe_webhook_secret' ), 'whsec_' );
        $webhook_status = 'pass';
        if ( $webhook_secret_configured && $webhook_failed_7d > 0 ) {
            $webhook_status = $webhook_failed_7d >= max( 3, (int) floor( $webhook_processed_7d / 2 ) ) ? 'fail' : 'warn';
        }
        $add_check(
            'webhook_health_7d',
            'Webhook Health (7d)',
            'integrations',
            $webhook_status,
            $webhook_secret_configured
                ? sprintf(
                    'Processed: %d, Failed: %d in last 7 days. Last processed: %s.',
                    $webhook_processed_7d,
                    $webhook_failed_7d,
                    $webhook_last_processed_at !== '' ? $display_datetime( $webhook_last_processed_at ) : 'never'
                )
                : 'Webhook signing secret is not configured.',
            $webhook_status === 'pass' ? '' : 'Verify webhook provider delivery logs and signature configuration.'
        );

        $release_status = function_exists( 'metis_release_status_snapshot' )
            ? (array) metis_release_status_snapshot()
            : ( function_exists( 'metis_release_status' ) ? (array) metis_release_status( false ) : [] );
        $release_last_checked = (string) ( $release_status['last_checked_at'] ?? '' );
        $release_last_checked_ts = $parse_datetime( $release_last_checked );
        $release_age_hours = $release_last_checked_ts > 0 ? (int) floor( ( time() - $release_last_checked_ts ) / HOUR_IN_SECONDS ) : PHP_INT_MAX;
        $release_remote_status = (string) ( $release_status['remote_status'] ?? 'unknown' );
        $release_warn_hours = $is_production_like ? 24 : ( 7 * 24 );
        $release_fail_hours = $is_production_like ? 72 : ( 14 * 24 );
        $release_check_status = $release_last_checked_ts < 1
            ? 'fail'
            : ( $release_age_hours >= $release_fail_hours ? 'fail' : ( $release_age_hours >= $release_warn_hours ? 'warn' : 'pass' ) );
        if ( in_array( $release_remote_status, [ 'unavailable', 'error' ], true ) ) {
            $release_check_status = $release_check_status === 'fail' ? 'fail' : 'warn';
        }
        $add_check(
            'release_update_checker',
            'Update Checker Health',
            'release',
            $release_check_status,
            $release_last_checked_ts < 1
                ? 'Release checker has never completed a status refresh.'
                : sprintf( 'Last checked at %s (%d hours ago), remote status: %s.', $display_datetime( $release_last_checked ), $release_age_hours, $release_remote_status ),
            $release_check_status === 'pass' ? '' : 'Run release check and verify GitHub/update connectivity.'
        );

        $release_auto_update_enabled = function_exists( 'metis_release_auto_update_enabled' )
            ? metis_release_auto_update_enabled()
            : (bool) Core_Settings_Service::get( 'release_auto_update_enabled', true );
        $release_auto_update_max_level = function_exists( 'metis_release_auto_update_max_level' )
            ? metis_release_auto_update_max_level()
            : 'patch';
        $release_auto_update_task_enabled = isset( $system_cron_tasks['release_auto_update'] )
            && ! empty( $system_cron_tasks['release_auto_update']['enabled'] );
        $release_auto_update_status = ! $release_auto_update_enabled
            ? 'warn'
            : ( $release_auto_update_task_enabled ? 'pass' : 'fail' );
        $add_check(
            'release_auto_update_policy',
            'Release Auto Update Policy',
            'release',
            $release_auto_update_status,
            $release_auto_update_enabled
                ? sprintf( 'Trusted release auto-update is enabled for %s releases.', $release_auto_update_max_level )
                : 'Trusted release auto-update is disabled.',
            $release_auto_update_status === 'pass' ? '' : 'Enable release auto-update and its scheduler task if production should self-apply trusted patch releases.'
        );

        $add_kpi( 'kpi_environment', 'Environment', strtoupper( $environment ), 'Threshold profile', $is_production_like ? 'good' : 'neutral' );
        $add_kpi( 'kpi_queue_backlog', 'Queue Backlog', (string) $queue_backlog, 'Queued + processing', $backlog_status === 'pass' ? 'good' : ( $backlog_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi( 'kpi_security_offenses', 'Security Offenses (7d)', (string) $security_offense_total, $security_offense_top !== '' ? 'Top: ' . $security_offense_top : '', $security_offense_status === 'pass' ? 'good' : ( $security_offense_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi( 'kpi_brute_force', 'Brute Force (7d)', (string) $brute_force_total, ! empty( $brute_force_top ) ? 'Top: ' . (string) $brute_force_top[0] : 'Login failure indicators', $brute_force_status === 'pass' ? 'good' : ( $brute_force_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi( 'kpi_rate_limit', 'Rate Limited (7d)', (string) $rate_limit_total, ! empty( $rate_limit_top ) ? 'Top: ' . (string) $rate_limit_top[0] : 'Burst/abuse control triggers', $rate_limit_status === 'pass' ? 'good' : ( $rate_limit_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi( 'kpi_backup_age', 'Backup Age', $last_backup_ts < 1 ? 'Never' : (string) $backup_age_hours . 'h', 'Last successful backup', $backup_status === 'pass' ? 'good' : ( $backup_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi( 'kpi_cron_stale', 'Cron Issues', (string) $cron_problem_count, 'Stale or failed enabled tasks', $cron_health_status === 'pass' ? 'good' : ( $cron_health_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi( 'kpi_retention_expired', 'Expired Rows', number_format( $retention_expired_rows ), 'Retention-governed history', $retention_status === 'pass' ? 'good' : ( $retention_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi( 'kpi_webhook', 'Webhook Failures (7d)', (string) $webhook_failed_7d, 'Processed ' . $webhook_processed_7d, $webhook_status === 'pass' ? 'good' : ( $webhook_status === 'fail' ? 'bad' : 'warn' ) );
        $add_kpi(
            'kpi_disk_free',
            'Disk Free',
            $human_bytes( $disk_free ),
            (string) $disk_percent_free . '% free',
            $disk_status === 'pass' ? 'good' : ( $disk_status === 'fail' ? 'bad' : 'warn' )
        );

        $check_count = count( $checks );
        $status_counts = metis_settings_health_status_counts( $checks );

        $score = 100;
        if ( $check_count > 0 ) {
            $computed_penalty = ( (int) $status_counts['warn'] * (int) ( $severity_weight['warn'] ?? 10 ) )
                + ( (int) $status_counts['fail'] * (int) ( $severity_weight['fail'] ?? 25 ) );
            $max_penalty = $check_count * (int) ( $severity_weight['fail'] ?? 25 );
            $score = (int) round( 100 * max( 0, $max_penalty - $computed_penalty ) / max( 1, $max_penalty ) );
        }
        $score = max( 0, min( 100, (int) $score ) );

        return [
            'generated_at' => metis_current_time( 'mysql' ),
            'generated_at_display' => $display_datetime( metis_current_time( 'mysql' ) ),
            'score' => $score,
            'status_counts' => $status_counts,
            'kpis' => $kpis,
            'checks' => $checks,
        ];
    }
}

if ( ! function_exists( 'metis_settings_read_uploaded_json_file' ) ) {
    function metis_settings_read_uploaded_json_file( array $file, string $label = 'JSON file' ): array {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
            return [ 'ok' => false, 'error' => $label . ' upload did not complete correctly.' ];
        }

        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        if ( $size < 1 ) {
            return [ 'ok' => false, 'error' => $label . ' is empty.' ];
        }
        if ( $size > 1024 * 1024 ) {
            return [ 'ok' => false, 'error' => $label . ' must be 1 MB or smaller.' ];
        }

        $contents = file_get_contents( (string) $file['tmp_name'] );
        if ( ! is_string( $contents ) || trim( $contents ) === '' ) {
            return [ 'ok' => false, 'error' => 'Unable to read the uploaded ' . strtolower( $label ) . '.' ];
        }

        $decoded = json_decode( $contents, true );
        if ( ! is_array( $decoded ) ) {
            return [ 'ok' => false, 'error' => $label . ' is not valid JSON.' ];
        }

        return [
            'ok' => true,
            'json' => $contents,
            'decoded' => $decoded,
        ];
    }
}

if ( ! function_exists( 'metis_settings_save_general_section' ) ) {
    function metis_settings_save_general_section( array &$errors, bool &$saved ): void {
        if ( ! empty( metis_request_post()['remove_portal_logo'] ) ) {
            Core_Settings_Service::delete( 'portal_logo' );
            $saved = true;
        } elseif ( isset( metis_request_files()['portal_logo_file'] ) && ! empty( metis_request_files()['portal_logo_file']['name'] ) ) {
            $logo_upload = metis_settings_save_logo_upload( metis_request_files()['portal_logo_file'] );
            if ( empty( $logo_upload['ok'] ) ) {
                $errors[] = 'Unable to save the uploaded logo.';
            } else {
                Core_Settings_Service::set( 'portal_logo', $logo_upload['logo'], false );
                $saved = true;
            }
        } else {
            $logo_media = metis_settings_media_asset_from_request( 'portal_logo', [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ] );
            if ( ! empty( $logo_media['ok'] ) ) {
                Core_Settings_Service::set( 'portal_logo', $logo_media['asset'], false );
                $saved = true;
            } elseif ( empty( $logo_media['empty'] ) && ! empty( $logo_media['error'] ) ) {
                $errors[] = (string) $logo_media['error'];
            }
        }

        if ( ! empty( metis_request_post()['remove_portal_favicon'] ) ) {
            Core_Settings_Service::delete( 'portal_favicon' );
            $saved = true;
        } elseif ( isset( metis_request_files()['portal_favicon_file'] ) && ! empty( metis_request_files()['portal_favicon_file']['name'] ) ) {
            $favicon_upload = metis_settings_save_favicon_upload( metis_request_files()['portal_favicon_file'] );
            if ( empty( $favicon_upload['ok'] ) ) {
                $errors[] = 'Unable to save the uploaded favicon.';
            } else {
                Core_Settings_Service::set( 'portal_favicon', $favicon_upload['favicon'], false );
                $saved = true;
            }
        } else {
            $favicon_media = metis_settings_media_asset_from_request( 'portal_favicon', [ 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml', 'image/webp' ] );
            if ( ! empty( $favicon_media['ok'] ) ) {
                Core_Settings_Service::set( 'portal_favicon', $favicon_media['asset'], false );
                $saved = true;
            } elseif ( empty( $favicon_media['empty'] ) && ! empty( $favicon_media['error'] ) ) {
                $errors[] = (string) $favicon_media['error'];
            }
        }

        $portal_name = metis_text_clean( metis_request_post()['portal_name'] ?? '' );
        if ( $portal_name !== '' ) {
            Core_Settings_Service::set( 'portal_name', $portal_name );
            $saved = true;
        }

        $org_name = metis_text_clean( metis_request_post()['org_name'] ?? '' );
        if ( $org_name !== '' ) {
            Core_Settings_Service::set( 'org_name', $org_name );
            $saved = true;
        }
        $org_tagline = metis_text_clean( (string) ( metis_request_post()['org_tagline'] ?? '' ) );
        Core_Settings_Service::set( 'org_tagline', $org_tagline );
        $saved = true;

        $timezone = metis_text_clean( (string) ( metis_request_post()['timezone'] ?? '' ) );
        if ( $timezone === '' || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            $timezone = 'UTC';
        }
        Core_Settings_Service::set( 'timezone', $timezone, true );
        Core_Settings_Service::set( 'site_timezone', $timezone, true );
        $saved = true;

        $date_presets = metis_settings_date_presets();
        $date_choice = metis_text_clean( (string) ( metis_request_post()['date_format_choice'] ?? 'mm/dd/yy' ) );
        $date_custom = trim( metis_text_clean( (string) ( metis_request_post()['date_format_custom'] ?? '' ) ) );
        if ( $date_choice === 'custom' ) {
            $parsed_date = metis_settings_parse_date_pattern( $date_custom );
            if ( $parsed_date === '' ) {
                $parsed_date = 'm/d/y';
                $errors[] = 'Custom date format is invalid. Using default.';
            }
            Core_Settings_Service::set( 'date_format_mode', 'custom', true );
            Core_Settings_Service::set( 'date_format_custom', $date_custom, true );
            Core_Settings_Service::set( 'date_format', $parsed_date, true );
        } else {
            if ( ! array_key_exists( $date_choice, $date_presets ) ) {
                $date_choice = 'mm/dd/yy';
            }
            Core_Settings_Service::set( 'date_format_mode', 'preset', true );
            Core_Settings_Service::set( 'date_format_custom', '', true );
            Core_Settings_Service::set( 'date_format', (string) $date_presets[ $date_choice ], true );
        }
        $saved = true;

        $time_presets = metis_settings_time_presets();
        $time_choice = metis_text_clean( (string) ( metis_request_post()['time_format_choice'] ?? 'h:mm:ss a/m' ) );
        $time_custom = trim( metis_text_clean( (string) ( metis_request_post()['time_format_custom'] ?? '' ) ) );
        if ( $time_choice === 'custom' ) {
            $parsed_time = metis_settings_parse_time_pattern( $time_custom );
            if ( $parsed_time === '' ) {
                $parsed_time = 'g:i:s a';
                $errors[] = 'Custom time format is invalid. Using default.';
            }
            Core_Settings_Service::set( 'time_format_mode', 'custom', true );
            Core_Settings_Service::set( 'time_format_custom', $time_custom, true );
            Core_Settings_Service::set( 'time_format', $parsed_time, true );
        } else {
            if ( ! array_key_exists( $time_choice, $time_presets ) ) {
                $time_choice = 'h:mm:ss a/m';
            }
            Core_Settings_Service::set( 'time_format_mode', 'preset', true );
            Core_Settings_Service::set( 'time_format_custom', '', true );
            Core_Settings_Service::set( 'time_format', (string) $time_presets[ $time_choice ], true );
        }
        $saved = true;

        if ( array_key_exists( 'site_homepage_page_id', metis_request_post() ) ) {
            $homepage_id = isset( metis_request_post()['site_homepage_page_id'] ) ? (int) metis_request_post()['site_homepage_page_id'] : 0;
            if ( $homepage_id > 0 ) {
                if (
                    class_exists( '\Metis\Modules\Website\Services\HomepageService' )
                    && class_exists( '\Metis\Modules\Website\Services\PageService' )
                ) {
                    $page = \Metis\Modules\Website\Services\PageService::getById( $homepage_id );
                    if ( $page === null || $page->status !== 'published' ) {
                        $errors[] = 'Homepage must reference a published website page.';
                    } elseif ( ! \Metis\Modules\Website\Services\HomepageService::setHomepagePageId( $homepage_id ) ) {
                        $errors[] = 'Unable to save homepage selection.';
                    } else {
                        $saved = true;
                    }
                }
            } elseif ( class_exists( '\Core_Settings_Service' ) ) {
                Core_Settings_Service::delete( 'site_homepage_page_id' );
                $saved = true;
            }
        }
    }
}

if ( ! function_exists( 'metis_settings_save_customization_section' ) ) {
    function metis_settings_save_customization_section( array &$errors, bool &$saved ): void {
        $theme_colors = [];
        foreach ( metis_settings_theme_color_fields() as $key => $field ) {
            $value = metis_hex_color_clean( (string) ( metis_request_post()['theme_colors'][ $key ] ?? '' ) );
            $theme_colors[ $key ] = $value ?: (string) ( $field['default'] ?? '' );
        }
        Core_Settings_Service::set( 'theme_colors', $theme_colors, true );

        if ( ! empty( metis_request_post()['remove_login_logo'] ) ) {
            Core_Settings_Service::delete( 'login_logo' );
            $saved = true;
        } elseif ( isset( metis_request_files()['login_logo_file'] ) && ! empty( metis_request_files()['login_logo_file']['name'] ) ) {
            $logo_upload = metis_settings_save_logo_upload( metis_request_files()['login_logo_file'] );
            if ( empty( $logo_upload['ok'] ) ) {
                $errors[] = 'Unable to save the uploaded login logo.';
            } else {
                Core_Settings_Service::set( 'login_logo', $logo_upload['logo'], false );
                $saved = true;
            }
        } else {
            $login_logo_media = metis_settings_media_asset_from_request( 'login_logo', [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ] );
            if ( ! empty( $login_logo_media['ok'] ) ) {
                Core_Settings_Service::set( 'login_logo', $login_logo_media['asset'], false );
                $saved = true;
            } elseif ( empty( $login_logo_media['empty'] ) && ! empty( $login_logo_media['error'] ) ) {
                $errors[] = (string) $login_logo_media['error'];
            }
        }

        if ( ! empty( metis_request_post()['remove_login_background_image'] ) ) {
            Core_Settings_Service::delete( 'login_background_image' );
            $saved = true;
        } elseif ( isset( metis_request_files()['login_background_image_file'] ) && ! empty( metis_request_files()['login_background_image_file']['name'] ) ) {
            $background_upload = metis_settings_save_image_upload(
                metis_request_files()['login_background_image_file'],
                'Login background image',
                4 * 1024 * 1024,
                [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ]
            );
            if ( empty( $background_upload['ok'] ) ) {
                $errors[] = 'Unable to save the uploaded login background image.';
            } else {
                Core_Settings_Service::set( 'login_background_image', $background_upload['asset'], false );
                $saved = true;
            }
        } else {
            $background_media = metis_settings_media_asset_from_request( 'login_background_image', [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ] );
            if ( ! empty( $background_media['ok'] ) ) {
                Core_Settings_Service::set( 'login_background_image', $background_media['asset'], false );
                $saved = true;
            } elseif ( empty( $background_media['empty'] ) && ! empty( $background_media['error'] ) ) {
                $errors[] = (string) $background_media['error'];
            }
        }

        Core_Settings_Service::set( 'login_background_color', metis_hex_color_clean( (string) ( metis_request_post()['login_background_color'] ?? '' ) ) ?: '#edf2f7', true );
        Core_Settings_Service::set( 'login_welcome_text', metis_textarea_clean( (string) ( metis_request_post()['login_welcome_text'] ?? '' ) ), true );
        Core_Settings_Service::set( 'login_organization_name', metis_text_clean( (string) ( metis_request_post()['login_organization_name'] ?? '' ) ), true );
        Core_Settings_Service::set( 'login_footer_text', metis_text_clean( (string) ( metis_request_post()['login_footer_text'] ?? '' ) ), true );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_accessibility_section' ) ) {
    function metis_settings_save_accessibility_section( array &$errors, bool &$saved ): void {
        $profiles = function_exists( 'metis_accessibility_profiles' ) ? metis_accessibility_profiles() : [ 'none' => [] ];
        $default_profile = metis_key_clean( (string) ( metis_request_post()['accessibility_default_profile'] ?? 'none' ) );
        if ( ! isset( $profiles[ $default_profile ] ) ) {
            $default_profile = 'none';
        }

        Core_Settings_Service::set( 'accessibility_toolbar_enabled', ! empty( metis_request_post()['accessibility_toolbar_enabled'] ) ? 1 : 0, true );
        Core_Settings_Service::set( 'accessibility_allow_overrides', ! empty( metis_request_post()['accessibility_allow_overrides'] ) ? 1 : 0, true );
        Core_Settings_Service::set( 'accessibility_default_profile', $default_profile, true );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_logging_section' ) ) {
    function metis_settings_save_logging_section( array &$errors, bool &$saved ): void {
        $allowed_levels = [ 'INFO', 'WARN', 'ERROR' ];
        $logging_min_level = strtoupper( metis_text_clean( (string) ( metis_request_post()['logging_min_level'] ?? 'INFO' ) ) );
        if ( ! in_array( $logging_min_level, $allowed_levels, true ) ) {
            $logging_min_level = 'INFO';
        }

        $logging_enabled = ! empty( metis_request_post()['logging_enabled'] ) ? 1 : 0;
        $logging_force_url_token = trim( metis_text_clean( (string) metis_runtime_unslash( metis_request_post()['logging_force_url_token'] ?? '' ) ) );
        if ( $logging_force_url_token !== '' && strlen( $logging_force_url_token ) < 16 ) {
            $errors[] = 'Force logging token must be at least 16 characters when enabled.';
            return;
        }
        if ( $logging_force_url_token !== '' && ! preg_match( '/^[A-Za-z0-9._-]+$/', $logging_force_url_token ) ) {
            $errors[] = 'Force logging token may only contain letters, numbers, dot, underscore, and hyphen.';
            return;
        }

        if ( ! empty( metis_request_post()['logging_clear_log'] ) && class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::clear();
            $saved = true;
        }

        Core_Settings_Service::set( 'logging_enabled', $logging_enabled, true );
        Core_Settings_Service::set( 'logging_min_level', $logging_min_level, true );
        Core_Settings_Service::set( 'logging_force_url_token', $logging_force_url_token, false );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_build_logging_viewer_state' ) ) {
    function metis_settings_build_logging_viewer_state( array $input = [] ): array {
        $logging_view_lines = 200;
        if ( isset( $input['logging_view_lines'] ) ) {
            $requested_lines = (int) $input['logging_view_lines'];
            if ( $requested_lines >= 50 && $requested_lines <= 1000 ) {
                $logging_view_lines = $requested_lines;
            }
        }

        $logging_available_logs = class_exists( 'Metis_Logger' ) ? (array) Metis_Logger::available_log_files() : [];

        $logging_view_file = '';
        if ( isset( $input['logging_view_file'] ) ) {
            $logging_view_file = trim( metis_text_clean( (string) metis_runtime_unslash( $input['logging_view_file'] ) ) );
        }

        $logging_search = '';
        if ( isset( $input['logging_search'] ) ) {
            $logging_search = trim( metis_text_clean( (string) metis_runtime_unslash( $input['logging_search'] ) ) );
        }

        $logging_page = isset( $input['logging_page'] ) ? max( 1, (int) $input['logging_page'] ) : 1;
        if ( isset( $input['logging_jump'] ) && isset( $input['logging_page_jump'] ) ) {
            $jump_page = (int) metis_text_clean( (string) metis_runtime_unslash( $input['logging_page_jump'] ) );
            if ( $jump_page > 0 ) {
                $logging_page = $jump_page;
            }
        }

        $logging_auto_refresh = ! empty( $input['logging_auto_refresh'] );
        $logging_auto_refresh_seconds = isset( $input['logging_auto_refresh_seconds'] ) ? (int) $input['logging_auto_refresh_seconds'] : 10;
        if ( $logging_auto_refresh_seconds < 5 || $logging_auto_refresh_seconds > 60 ) {
            $logging_auto_refresh_seconds = 10;
        }

        $logging_per_page = 50;
        $logging_log_path = class_exists( 'Metis_Logger' ) ? (string) Metis_Logger::viewer_log_path( $logging_view_file ) : '';
        $logging_entries_all = class_exists( 'Metis_Logger' )
            ? (array) Metis_Logger::entries( $logging_view_lines, $logging_view_file, true )
            : [];

        if ( $logging_search !== '' ) {
            $needle = strtolower( $logging_search );
            $tokens = preg_split( '/\s+/', $needle ) ?: [];
            $tokens = array_values( array_filter( array_map( 'trim', $tokens ), static fn( string $t ): bool => $t !== '' ) );
            $logging_entries_all = array_values(
                array_filter(
                    $logging_entries_all,
                    static function ( $entry ) use ( $needle, $tokens ): bool {
                        if ( ! is_array( $entry ) ) {
                            return false;
                        }

                        $level = strtolower( (string) ( $entry['level'] ?? '' ) );
                        $message = strtolower( (string) ( $entry['message'] ?? '' ) );
                        $timestamp = strtolower( (string) ( $entry['timestamp_display'] ?? '' ) );
                        $context_json = strtolower( (string) metis_json_encode( (array) ( $entry['context'] ?? [] ) ) );
                        $haystack = strtolower(
                            $timestamp . ' '
                            . $level . ' '
                            . 'level:' . $level . ' '
                            . 'level ' . $level . ' '
                            . $message . ' '
                            . $context_json
                        );
                        if ( $needle !== '' && strpos( $haystack, $needle ) !== false ) {
                            return true;
                        }

                        if ( $tokens === [] ) {
                            return false;
                        }

                        foreach ( $tokens as $token ) {
                            if ( strpos( $haystack, $token ) === false ) {
                                return false;
                            }
                        }

                        return true;
                    }
                )
            );
        }

        $logging_total_entries = count( $logging_entries_all );
        $logging_total_pages = max( 1, (int) ceil( $logging_total_entries / $logging_per_page ) );
        if ( $logging_page > $logging_total_pages ) {
            $logging_page = $logging_total_pages;
        }
        $logging_offset = ( $logging_page - 1 ) * $logging_per_page;
        $logging_entries = array_slice( $logging_entries_all, $logging_offset, $logging_per_page );

        return compact(
            'logging_view_lines',
            'logging_available_logs',
            'logging_view_file',
            'logging_search',
            'logging_page',
            'logging_auto_refresh',
            'logging_auto_refresh_seconds',
            'logging_per_page',
            'logging_log_path',
            'logging_total_entries',
            'logging_total_pages',
            'logging_entries'
        );
    }
}

if ( ! function_exists( 'metis_settings_save_menu_section' ) ) {
    function metis_settings_save_menu_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        if ( ! $is_system_admin ) {
            $errors[] = 'Only system admins can manage menu configurations.';
            return;
        }

        if ( ! function_exists( 'metis_navigation_service' ) ) {
            $errors[] = 'Navigation service is unavailable.';
            return;
        }

        $raw_payload = metis_request_post()['navigation_structure'] ?? '';
        if ( is_array( $raw_payload ) ) {
            $decoded = $raw_payload;
        } else {
            $raw = (string) $raw_payload;
            if ( $raw === '' ) {
                $errors[] = 'Navigation structure payload is missing.';
                return;
            }

            // Decode raw first; fallback to unslashed payload for environments that add request slashes.
            $decoded = json_decode( $raw, true );
            if ( ! is_array( $decoded ) ) {
                $decoded = json_decode( (string) metis_runtime_unslash( $raw ), true );
            }
        }

        if ( ! is_array( $decoded ) ) {
            $errors[] = 'Navigation structure payload is invalid.';
            return;
        }

        $seen = [];
        $validate = static function ( array $nodes, int $depth = 1 ) use ( &$validate, &$errors, &$seen ): bool {
            foreach ( $nodes as $node ) {
                if ( ! is_array( $node ) ) {
                    continue;
                }

                $id = (int) ( $node['id'] ?? 0 );
                if ( $id > 0 ) {
                    if ( in_array( $id, $seen, true ) ) {
                        $errors[] = 'Circular or duplicate menu reference detected.';
                        return false;
                    }
                    $seen[] = $id;
                }

                $children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
                if ( $children === [] ) {
                    continue;
                }

                if ( $depth >= 2 ) {
                    $errors[] = 'Menu nesting depth cannot exceed 2 levels.';
                    return false;
                }

                if ( ! $validate( $children, $depth + 1 ) ) {
                    return false;
                }
            }

            return true;
        };

        if ( ! $validate( $decoded, 1 ) ) {
            return;
        }

        metis_navigation_service()->saveStructure( $decoded );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_profile_section' ) ) {
    function metis_settings_save_profile_section( array &$errors, bool &$saved ): void {
        $profile_allow_name_edit = ! empty( metis_request_post()['profile_allow_name_edit'] ) ? '1' : '0';
        Core_Settings_Service::set( 'profile_allow_name_edit', $profile_allow_name_edit );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_upsert_credential_reference' ) ) {
    function metis_settings_upsert_credential_reference( string $setting_key, string $type, string $raw_secret, string $label ): bool {
        $raw_secret = trim( $raw_secret );
        if ( $raw_secret === '' ) {
            return false;
        }

        $existing_id = trim( (string) Core_Settings_Service::get( $setting_key . '_credential_id', '' ) );
        $credential_id = \Metis\Core\Services\CredentialService::storeCredential( $type, $label, $raw_secret, $existing_id );
        if ( $credential_id === '' ) {
            return false;
        }

        Core_Settings_Service::set( $setting_key . '_credential_id', $credential_id, false );
        return true;
    }
}

if ( ! function_exists( 'metis_settings_get_credential_value' ) ) {
    function metis_settings_get_credential_value( string $setting_key ): string {
        return \Metis\Core\Services\CredentialService::getBySetting( $setting_key );
    }
}

if ( ! function_exists( 'metis_settings_save_user_experience_section' ) ) {
    function metis_settings_save_user_experience_section( array &$errors, bool &$saved ): void {
        metis_settings_save_profile_section( $errors, $saved );
        metis_settings_save_accessibility_section( $errors, $saved );
    }
}

if ( ! function_exists( 'metis_settings_save_newsletter_section' ) ) {
    function metis_settings_save_newsletter_section( array &$errors, bool &$saved ): void {
        $newsletter_default_from_name = metis_text_clean( metis_request_post()['newsletter_default_from_name'] ?? '' );
        Core_Settings_Service::set( 'newsletter_default_from_name', $newsletter_default_from_name, true );
        $saved = true;

        $newsletter_default_from_email = metis_email_clean( metis_request_post()['newsletter_default_from_email'] ?? '' );
        if ( $newsletter_default_from_email !== '' && ! metis_email_is_valid( $newsletter_default_from_email ) ) {
            $errors[] = 'Newsletter default from email must be a valid email.';
        } else {
            Core_Settings_Service::set( 'newsletter_default_from_email', strtolower( (string) $newsletter_default_from_email ), true );
            $saved = true;
        }

        $newsletter_default_reply_to = metis_email_clean( metis_request_post()['newsletter_default_reply_to'] ?? '' );
        if ( $newsletter_default_reply_to !== '' && ! metis_email_is_valid( $newsletter_default_reply_to ) ) {
            $errors[] = 'Newsletter default reply-to must be a valid email.';
        } else {
            Core_Settings_Service::set( 'newsletter_default_reply_to', strtolower( (string) $newsletter_default_reply_to ), true );
            $saved = true;
        }

        $newsletter_google_daily_limit = (int) ( metis_request_post()['newsletter_google_daily_limit'] ?? 2000 );
        if ( $newsletter_google_daily_limit < 100 ) {
            $newsletter_google_daily_limit = 100;
        } elseif ( $newsletter_google_daily_limit > 100000 ) {
            $newsletter_google_daily_limit = 100000;
        }
        Core_Settings_Service::set( 'newsletter_google_daily_limit', (string) $newsletter_google_daily_limit, true );

        $newsletter_klipy_credential_id = metis_text_clean( (string) ( metis_request_post()['newsletter_klipy_credential_id'] ?? '' ) );
        if ( $newsletter_klipy_credential_id !== '' ) {
            Core_Settings_Service::set( 'newsletter_klipy_api_key_credential_id', $newsletter_klipy_credential_id, false );
            $saved = true;
        }
        $newsletter_klipy_api_key = trim( metis_text_clean( (string) ( metis_request_post()['newsletter_klipy_api_key'] ?? '' ) ) );
        if ( $newsletter_klipy_api_key !== '' ) {
            if ( ! metis_settings_upsert_credential_reference( 'newsletter_klipy_api_key', 'klipy_api_key', $newsletter_klipy_api_key, 'Klipy API Key' ) ) {
                $errors[] = 'Unable to store Klipy API key.';
            } else {
                $saved = true;
            }
        }
        $newsletter_klipy_search_url = metis_settings_normalize_klipy_search_url( (string) ( metis_request_post()['newsletter_klipy_search_url'] ?? '' ) );
        if ( $newsletter_klipy_search_url === '' ) {
            $errors[] = 'Klipy search endpoint must be exactly <code>https://api.klipy.com/v1/gifs/search</code>.';
        } else {
            Core_Settings_Service::set( 'newsletter_klipy_search_url', (string) $newsletter_klipy_search_url, true );
        }
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_workspace_section' ) ) {
    function metis_settings_save_workspace_section( array &$errors, bool &$saved ): void {
        $workspace_customer_id = metis_text_clean( metis_request_post()['workspace_customer_id'] ?? '' );
        Core_Settings_Service::set( 'workspace_customer_id', $workspace_customer_id, false );
        $saved = true;

        $workspace_domain = metis_text_clean( metis_request_post()['workspace_domain'] ?? '' );
        if ( $workspace_domain !== '' ) {
            $workspace_domain = strtolower( preg_replace( '/[^a-z0-9\.\-]/', '', $workspace_domain ) );
        }
        Core_Settings_Service::set( 'workspace_domain', $workspace_domain, false );
        $saved = true;

        $workspace_stripe_sso_schema = metis_text_clean( metis_request_post()['workspace_stripe_sso_schema'] ?? '' );
        Core_Settings_Service::set( 'workspace_stripe_sso_schema', (string) $workspace_stripe_sso_schema, false );
        $saved = true;

        $workspace_stripe_sso_field = metis_text_clean( metis_request_post()['workspace_stripe_sso_field'] ?? '' );
        Core_Settings_Service::set( 'workspace_stripe_sso_field', (string) $workspace_stripe_sso_field, false );
        $saved = true;

        $workspace_google_sso_client_id = trim( metis_text_clean( (string) ( metis_request_post()['workspace_google_sso_client_id'] ?? '' ) ) );
        Core_Settings_Service::set( 'workspace_google_sso_client_id', $workspace_google_sso_client_id, false );
        $saved = true;

        $workspace_google_sso_client_secret_credential_id = metis_text_clean( (string) ( metis_request_post()['workspace_google_sso_client_secret_credential_id'] ?? '' ) );
        if ( $workspace_google_sso_client_secret_credential_id !== '' ) {
            Core_Settings_Service::set( 'workspace_google_sso_client_secret_credential_id', $workspace_google_sso_client_secret_credential_id, false );
        }
        $saved = true;

        $workspace_google_sso_hosted_domain = metis_text_clean( (string) ( metis_request_post()['workspace_google_sso_hosted_domain'] ?? '' ) );
        if ( $workspace_google_sso_hosted_domain !== '' ) {
            $workspace_google_sso_hosted_domain = strtolower( preg_replace( '/[^a-z0-9\.\-]/', '', $workspace_google_sso_hosted_domain ) );
        }
        Core_Settings_Service::set( 'workspace_google_sso_hosted_domain', $workspace_google_sso_hosted_domain, false );
        $saved = true;

        $workspace_stripe_access_group_email = metis_email_clean( metis_request_post()['workspace_stripe_access_group_email'] ?? '' );
        if ( $workspace_stripe_access_group_email !== '' && ! metis_email_is_valid( $workspace_stripe_access_group_email ) ) {
            $errors[] = 'Workspace Stripe access group must be a valid email.';
        } else {
            Core_Settings_Service::set( 'workspace_stripe_access_group_email', strtolower( (string) $workspace_stripe_access_group_email ), false );
            $saved = true;
        }
    }
}

if ( ! function_exists( 'metis_settings_save_drive_section' ) ) {
    function metis_settings_save_drive_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        if ( ! $is_system_admin ) {
            $errors[] = 'Only system admins can manage Drive configurations.';
            return;
        }

        $workspace_drive_configs = metis_settings_normalize_drive_rows( metis_runtime_unslash( metis_request_post()['workspace_drive_configs'] ?? [] ) );
        Core_Settings_Service::set( 'workspace_drive_configs', $workspace_drive_configs, false );
        $default_drive = '';
        foreach ( $workspace_drive_configs as $row ) {
            if ( ! empty( $row['is_default'] ) ) {
                $default_drive = (string) $row['drive_id'];
                break;
            }
        }
        Core_Settings_Service::set( 'workspace_shared_drive_id', $default_drive, false );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_calendar_section' ) ) {
    function metis_settings_save_calendar_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        if ( ! $is_system_admin ) {
            $errors[] = 'Only system admins can manage Calendar configurations.';
            return;
        }

        $workspace_calendar_configs = metis_settings_normalize_calendar_rows( metis_runtime_unslash( metis_request_post()['workspace_calendar_configs'] ?? [] ) );
        Core_Settings_Service::set( 'workspace_calendar_configs', $workspace_calendar_configs, false );
        $default_calendar = '';
        foreach ( $workspace_calendar_configs as $row ) {
            if ( ! empty( $row['is_default'] ) ) {
                $default_calendar = (string) $row['calendar_id'];
                break;
            }
        }
        Core_Settings_Service::set( 'workspace_default_calendar_id', $default_calendar, false );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_backup_section' ) ) {
    function metis_settings_save_backup_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        if ( ! $is_system_admin ) {
            $errors[] = 'Only system admins can manage backup settings.';
            return;
        }

        $drive_options = [];
        if ( function_exists( 'metis_drive_workspace_base_settings' ) && function_exists( 'metis_drive_list_shared_drives' ) ) {
            $drive_cfg = metis_drive_workspace_base_settings();
            if ( ! empty( $drive_cfg['ok'] ) ) {
                $drive_list = metis_drive_list_shared_drives( $drive_cfg );
                if ( ! empty( $drive_list['ok'] ) ) {
                    foreach ( (array) ( $drive_list['drives'] ?? [] ) as $drive ) {
                        $drive_id = trim( (string) ( $drive['id'] ?? '' ) );
                        if ( $drive_id !== '' ) {
                            $drive_options[] = $drive_id;
                        }
                    }
                }
            }
        }

        $backup_drive_id = metis_text_clean( (string) metis_runtime_unslash( metis_request_post()['backup_drive_id'] ?? '' ) );
        if ( $backup_drive_id !== '' && ! in_array( $backup_drive_id, $drive_options, true ) ) {
            $errors[] = 'Backup Drive must match one of the configured Drive settings.';
        } else {
            Core_Settings_Service::set( 'backup_drive_id', $backup_drive_id, false );
            $saved = true;
        }

        $backup_retention_runs = (int) metis_runtime_unslash( metis_request_post()['backup_retention_runs'] ?? 14 );
        $backup_retention_runs = max( 1, min( 365, $backup_retention_runs ) );
        Core_Settings_Service::set( 'backup_retention_runs', $backup_retention_runs, false );
        $saved = true;

        $backup_environment = metis_key_clean( (string) metis_runtime_unslash( metis_request_post()['backup_environment'] ?? '' ) );
        Core_Settings_Service::set( 'backup_environment', $backup_environment, false );
        $saved = true;

    }
}

if ( ! function_exists( 'metis_settings_save_payments_section' ) ) {
    function metis_settings_save_payments_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        if ( ! $is_system_admin ) {
            $errors[] = 'Only system admins can manage payment defaults.';
            return;
        }

        $stripe_platform_fee_percent = (float) ( metis_request_post()['stripe_platform_fee_percent'] ?? 2.9 );
        $stripe_platform_fee_percent = max( 0, min( 100, $stripe_platform_fee_percent ) );
        \Core_Settings_Service::set( 'stripe_platform_fee_percent', round( $stripe_platform_fee_percent, 2 ), false );
        $saved = true;

        $stripe_platform_fee_fixed = (float) ( metis_request_post()['stripe_platform_fee_fixed'] ?? 0.30 );
        $stripe_platform_fee_fixed = max( 0, $stripe_platform_fee_fixed );
        \Core_Settings_Service::set( 'stripe_platform_fee_fixed', round( $stripe_platform_fee_fixed, 2 ), false );
        $saved = true;

        $stripe_cover_fee_label = metis_text_clean( (string) ( metis_request_post()['stripe_cover_fee_label'] ?? '' ) );
        \Core_Settings_Service::set(
            'stripe_cover_fee_label',
            $stripe_cover_fee_label !== '' ? $stripe_cover_fee_label : 'I would like to cover the processing fees.',
            false
        );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_api_section' ) ) {
    function metis_settings_save_api_section( bool $is_system_admin, array &$errors, bool &$saved, ?array &$carddav_token_notice = null ): void {
        $submitted_sensitive = false;
        foreach ( [ 'stripe_secret', 'stripe_publishable_key', 'stripe_webhook_secret', 'workspace_impersonation_admin', 'workspace_service_account_json', 'workspace_google_sso_client_secret', 'communications_inbound_pubsub_service_account_email' ] as $sensitive_key ) {
            if ( isset( metis_request_post()[ $sensitive_key ] ) && trim( (string) metis_runtime_unslash( metis_request_post()[ $sensitive_key ] ) ) !== '' ) {
                $submitted_sensitive = true;
                break;
            }
        }

        if ( ! $is_system_admin ) {
            if ( $submitted_sensitive ) {
                $errors[] = 'Only system admins can update API keys and service account credentials.';
            }
            return;
        }

        $stripe_secret = metis_text_clean( metis_request_post()['stripe_secret'] ?? '' );
        if ( $stripe_secret !== '' ) {
            if ( ! str_starts_with( $stripe_secret, 'sk_' ) ) {
                $errors[] = 'Stripe secret key must begin with <code>sk_</code>.';
            } else {
                if ( ! metis_settings_upsert_credential_reference( 'stripe_secret', 'stripe_key', $stripe_secret, 'Stripe Secret Key' ) ) {
                    $errors[] = 'Unable to store Stripe secret key.';
                } else {
                    $saved = true;
                }
            }
        }

        $stripe_credential_id = metis_text_clean( (string) ( metis_request_post()['stripe_secret_credential_id'] ?? '' ) );
        if ( $stripe_credential_id !== '' ) {
            Core_Settings_Service::set( 'stripe_secret_credential_id', $stripe_credential_id, false );
            $saved = true;
        }

        $stripe_publishable_key = metis_text_clean( metis_request_post()['stripe_publishable_key'] ?? '' );
        if ( $stripe_publishable_key !== '' ) {
            if ( ! str_starts_with( $stripe_publishable_key, 'pk_' ) ) {
                $errors[] = 'Stripe publishable key must begin with <code>pk_</code>.';
            } else {
                Core_Settings_Service::set( 'stripe_publishable_key', $stripe_publishable_key, false );
                $saved = true;
            }
        }

        $webhook_secret = metis_text_clean( metis_request_post()['stripe_webhook_secret'] ?? '' );
        if ( $webhook_secret !== '' ) {
            if ( ! str_starts_with( $webhook_secret, 'whsec_' ) ) {
                $errors[] = 'Webhook secret must begin with <code>whsec_</code>.';
            } else {
                if ( ! metis_settings_upsert_credential_reference( 'stripe_webhook_secret', 'webhook_secret', $webhook_secret, 'Stripe Webhook Secret' ) ) {
                    $errors[] = 'Unable to store webhook secret.';
                } else {
                    $saved = true;
                }
            }
        }

        $webhook_credential_id = metis_text_clean( (string) ( metis_request_post()['stripe_webhook_secret_credential_id'] ?? '' ) );
        if ( $webhook_credential_id !== '' ) {
            Core_Settings_Service::set( 'stripe_webhook_secret_credential_id', $webhook_credential_id, false );
            $saved = true;
        }

        $workspace_impersonation_admin = metis_email_clean( metis_request_post()['workspace_impersonation_admin'] ?? '' );
        if ( $workspace_impersonation_admin !== '' ) {
            if ( ! metis_email_is_valid( $workspace_impersonation_admin ) ) {
                $errors[] = 'Workspace impersonation admin must be a valid email.';
            } else {
                Core_Settings_Service::set( 'workspace_impersonation_admin', strtolower( $workspace_impersonation_admin ), false );
                $saved = true;
            }
        }

        $workspace_service_account_json = '';
        if ( isset( metis_request_files()['workspace_service_account_json_file'] ) && ! empty( metis_request_files()['workspace_service_account_json_file']['name'] ) ) {
            $upload = metis_settings_read_uploaded_json_file( metis_request_files()['workspace_service_account_json_file'], 'Workspace service account JSON file' );
            if ( empty( $upload['ok'] ) ) {
                $errors[] = 'Unable to read the uploaded service account JSON file.';
            } else {
                $workspace_service_account_json = trim( (string) ( $upload['json'] ?? '' ) );
            }
        } else {
            $workspace_service_account_json = trim( (string) metis_runtime_unslash( metis_request_post()['workspace_service_account_json'] ?? '' ) );
        }

        if ( $workspace_service_account_json !== '' ) {
            $decoded = json_decode( $workspace_service_account_json, true );
            if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) || empty( $decoded['token_uri'] ) ) {
                $errors[] = 'Workspace service account JSON is invalid or missing required keys.';
            } else {
                if ( ! metis_settings_upsert_credential_reference( 'workspace_service_account_json', 'google_service_account', $workspace_service_account_json, 'Google Service Account JSON' ) ) {
                    $errors[] = 'Unable to store Workspace service account JSON.';
                } else {
                    $saved = true;
                }
            }
        }

        $workspace_service_account_credential_id = metis_text_clean( (string) ( metis_request_post()['workspace_service_account_json_credential_id'] ?? '' ) );
        if ( $workspace_service_account_credential_id !== '' ) {
            Core_Settings_Service::set( 'workspace_service_account_json_credential_id', $workspace_service_account_credential_id, false );
            $saved = true;
        }

        $workspace_google_sso_client_secret = trim( metis_text_clean( (string) ( metis_request_post()['workspace_google_sso_client_secret'] ?? '' ) ) );
        if ( $workspace_google_sso_client_secret !== '' ) {
            if ( ! metis_settings_upsert_credential_reference( 'workspace_google_sso_client_secret', 'google_oauth_client_secret', $workspace_google_sso_client_secret, 'Google OAuth Client Secret' ) ) {
                $errors[] = 'Unable to store Google OAuth client secret.';
            } else {
                $saved = true;
            }
        }

        $workspace_google_sso_client_secret_credential_id = metis_text_clean( (string) ( metis_request_post()['workspace_google_sso_client_secret_credential_id'] ?? '' ) );
        if ( $workspace_google_sso_client_secret_credential_id !== '' ) {
            Core_Settings_Service::set( 'workspace_google_sso_client_secret_credential_id', $workspace_google_sso_client_secret_credential_id, false );
            $saved = true;
        }

        $communications_project_id = trim( metis_text_clean( (string) ( metis_request_post()['communications_inbound_google_project_id'] ?? '' ) ) );
        Core_Settings_Service::set( 'communications_inbound_google_project_id', $communications_project_id, false );
        $saved = true;

        $communications_topic = trim( metis_text_clean( (string) ( metis_request_post()['communications_inbound_pubsub_topic'] ?? '' ) ) );
        Core_Settings_Service::set( 'communications_inbound_pubsub_topic', $communications_topic, false );
        $saved = true;

        $communications_audience = trim( metis_text_clean( (string) ( metis_request_post()['communications_inbound_pubsub_audience'] ?? '' ) ) );
        Core_Settings_Service::set( 'communications_inbound_pubsub_audience', $communications_audience, false );
        $saved = true;

        $communications_service_account_email = strtolower( trim( metis_email_clean( (string) ( metis_request_post()['communications_inbound_pubsub_service_account_email'] ?? '' ) ) ) );
        if ( $communications_service_account_email !== '' && ! metis_email_is_valid( $communications_service_account_email ) ) {
            $errors[] = 'Inbound Pub/Sub push service account email must be a valid email.';
        } else {
            Core_Settings_Service::set( 'communications_inbound_pubsub_service_account_email', $communications_service_account_email, false );
            $saved = true;
        }

        $communications_mailboxes_input = metis_runtime_unslash( metis_request_post()['communications_inbound_mailboxes'] ?? null );
        if ( $communications_mailboxes_input === null ) {
            $communications_mailboxes_input = trim( (string) metis_runtime_unslash( metis_request_post()['communications_inbound_mailboxes_json'] ?? '[]' ) );
        }
        $communications_mailboxes = metis_settings_normalize_inbound_mailbox_rows( $communications_mailboxes_input );
        Core_Settings_Service::set( 'communications_inbound_mailboxes', $communications_mailboxes, false );
        $saved = true;

        $communications_log_verbosity = metis_key_clean( (string) ( metis_request_post()['communications_inbound_log_verbosity'] ?? 'standard' ) );
        if ( ! in_array( $communications_log_verbosity, [ 'quiet', 'standard', 'verbose' ], true ) ) {
            $communications_log_verbosity = 'standard';
        }
        Core_Settings_Service::set( 'communications_inbound_log_verbosity', $communications_log_verbosity, false );
        $saved = true;

        $communications_full_sync_days = max( 1, min( 90, (int) ( metis_request_post()['communications_inbound_full_sync_days'] ?? 30 ) ) );
        Core_Settings_Service::set( 'communications_inbound_full_sync_days', $communications_full_sync_days, false );
        $saved = true;

        Core_Settings_Service::set( 'communications_inbound_allow_reprocess', ! empty( metis_request_post()['communications_inbound_allow_reprocess'] ) ? 1 : 0, false );
        Core_Settings_Service::set( 'communications_inbound_enable_bounce_handler', ! empty( metis_request_post()['communications_inbound_enable_bounce_handler'] ) ? 1 : 0, false );
        Core_Settings_Service::set( 'communications_inbound_enable_unsubscribe_handler', ! empty( metis_request_post()['communications_inbound_enable_unsubscribe_handler'] ) ? 1 : 0, false );
        Core_Settings_Service::set( 'communications_inbound_enable_grandys_stash_handler', ! empty( metis_request_post()['communications_inbound_enable_grandys_stash_handler'] ) ? 1 : 0, false );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_runtime_section' ) ) {
    function metis_settings_save_runtime_section( array &$errors, bool &$saved ): void {
        metis_settings_save_logging_section( $errors, $saved );
    }
}

if ( ! function_exists( 'metis_settings_save_jobs_tasks_section' ) ) {
    function metis_settings_save_jobs_tasks_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        metis_settings_save_scheduler_section( $is_system_admin, $errors, $saved );
    }
}

if ( ! function_exists( 'metis_settings_save_scheduler_section' ) ) {
    function metis_settings_save_scheduler_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        if ( ! $is_system_admin ) {
            $errors[] = 'Only system admins can manage the scheduler secret.';
            return;
        }

        $registered_tasks = array_keys( Metis_Cron_Manager::registered_tasks() );
        $selected_tasks = metis_runtime_unslash( metis_request_post()['system_cron_enabled_tasks'] ?? [] );
        $selected_tasks = is_array( $selected_tasks ) ? $selected_tasks : [];
        $enabled_tasks = [];

        foreach ( $selected_tasks as $task_slug ) {
            $task_slug = metis_key_clean( (string) $task_slug );
            if ( $task_slug !== '' && in_array( $task_slug, $registered_tasks, true ) && ! in_array( $task_slug, $enabled_tasks, true ) ) {
                $enabled_tasks[] = $task_slug;
            }
        }

        $disabled_tasks = [];
        foreach ( $registered_tasks as $task_slug ) {
            if ( ! in_array( $task_slug, $enabled_tasks, true ) ) {
                $disabled_tasks[] = $task_slug;
            }
        }

        Core_Settings_Service::set( 'system_cron_disabled_tasks', $disabled_tasks, false );
        $saved = true;

        $submitted_intervals = metis_runtime_unslash( metis_request_post()['system_cron_task_intervals'] ?? [] );
        $submitted_intervals = is_array( $submitted_intervals ) ? $submitted_intervals : [];
        $interval_overrides = [];

        foreach ( Metis_Cron_Manager::registered_tasks() as $task_slug => $task_config ) {
            $default_interval = max( 60, (int) ( $task_config['default_interval'] ?? $task_config['interval'] ?? 300 ) );
            $submitted_minutes = isset( $submitted_intervals[ $task_slug ] ) ? (int) $submitted_intervals[ $task_slug ] : 0;
            $submitted_seconds = max( 60, $submitted_minutes * MINUTE_IN_SECONDS );

            if ( $submitted_minutes < 1 ) {
                $errors[] = sprintf( 'Task "%s" must have a schedule of at least 1 minute.', $task_slug );
                continue;
            }

            if ( $submitted_seconds !== $default_interval ) {
                $interval_overrides[ $task_slug ] = $submitted_seconds;
            }
        }

        Core_Settings_Service::set( 'system_cron_task_intervals', $interval_overrides, false );
        $saved = true;

        if ( ! empty( metis_request_post()['metis_generate_cron_secret'] ) ) {
            $secret = bin2hex( random_bytes( 24 ) );
            Core_Settings_Service::set( 'system_cron_secret', $secret, false );
            $saved = true;
            return;
        }

        $secret = trim( metis_text_clean( (string) metis_runtime_unslash( metis_request_post()['system_cron_secret'] ?? '' ) ) );
        if ( $secret === '' ) {
            return;
        }

        if ( strlen( $secret ) < 24 ) {
            $errors[] = 'Scheduler secret must be at least 24 characters.';
            return;
        }

        Core_Settings_Service::set( 'system_cron_secret', $secret, false );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_help_section' ) ) {
    function metis_settings_save_help_section( array &$errors, bool &$saved ): void {
        $help_enabled = ! empty( metis_request_post()['help_enabled'] ) ? 1 : 0;
        $walkthrough_enabled = ! empty( metis_request_post()['walkthrough_enabled'] ) ? 1 : 0;
        $topic_overrides_raw = (string) metis_runtime_unslash( metis_request_post()['help_topic_overrides_json'] ?? '{}' );
        $custom_topics_raw = (string) metis_runtime_unslash( metis_request_post()['help_custom_topics_json'] ?? '{}' );
        $custom_walkthroughs_raw = (string) metis_runtime_unslash( metis_request_post()['help_custom_walkthroughs_json'] ?? '{}' );

        $topic_overrides = json_decode( $topic_overrides_raw !== '' ? $topic_overrides_raw : '{}', true );
        if ( ! is_array( $topic_overrides ) ) {
            $errors[] = 'Topic overrides must be valid JSON.';
            $topic_overrides = [];
        }

        $custom_topics = json_decode( $custom_topics_raw !== '' ? $custom_topics_raw : '{}', true );
        if ( ! is_array( $custom_topics ) ) {
            $errors[] = 'Custom help topics must be valid JSON.';
            $custom_topics = [];
        }

        $custom_walkthroughs = json_decode( $custom_walkthroughs_raw !== '' ? $custom_walkthroughs_raw : '{}', true );
        if ( ! is_array( $custom_walkthroughs ) ) {
            $errors[] = 'Custom walkthroughs must be valid JSON.';
            $custom_walkthroughs = [];
        }

        if ( ! empty( $errors ) ) {
            return;
        }

        Core_Settings_Service::set( 'help_enabled', $help_enabled );
        Core_Settings_Service::set( 'walkthrough_enabled', $walkthrough_enabled );
        Core_Settings_Service::set( 'help_topic_overrides', $topic_overrides, false );
        Core_Settings_Service::set( 'help_custom_topics', $custom_topics, false );
        Core_Settings_Service::set( 'help_custom_walkthroughs', $custom_walkthroughs, false );

        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_bootstrap' ) ) {
    function metis_settings_bootstrap( string $section ): array {
        $can_admin_settings = metis_settings_is_system_admin() || metis_settings_is_developer();
        if ( ! $can_admin_settings ) {
            return [ 'allowed' => false, 'section' => 'general' ];
        }

        $is_system_admin = metis_settings_is_system_admin();
        $sections = metis_settings_sections( $is_system_admin );
        if ( ! array_key_exists( $section, $sections ) ) {
            return [ 'allowed' => false, 'section' => 'general' ];
        }

        $saved  = false;
        $errors = [];
        $carddav_token_notice = null;
        $nonce_action = 'metis_save_settings_' . $section;
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset( metis_request_post()['metis_settings_nonce'] )
            && metis_runtime_verify_nonce( metis_request_post()['metis_settings_nonce'], $nonce_action )
        ) {
            metis_install_db();
            switch ( $section ) {
                case 'general':
                    metis_settings_save_general_section( $errors, $saved );
                    break;
                case 'user_experience':
                    metis_settings_save_user_experience_section( $errors, $saved );
                    break;
                case 'branding':
                    metis_settings_save_customization_section( $errors, $saved );
                    break;
                case 'navigation':
                    metis_settings_save_menu_section( $is_system_admin, $errors, $saved );
                    break;
                case 'email':
                    metis_settings_save_newsletter_section( $errors, $saved );
                    break;
                case 'payments':
                    metis_settings_save_payments_section( $is_system_admin, $errors, $saved );
                    break;
                case 'google_workspace':
                    metis_settings_save_workspace_section( $errors, $saved );
                    break;
                case 'drive':
                    metis_settings_save_drive_section( $is_system_admin, $errors, $saved );
                    break;
                case 'backup':
                    metis_settings_save_backup_section( $is_system_admin, $errors, $saved );
                    break;
                case 'calendar':
                    metis_settings_save_calendar_section( $is_system_admin, $errors, $saved );
                    break;
                case 'developers_api':
                    $carddav_token_notice = null;
                    metis_settings_save_api_section( $is_system_admin, $errors, $saved, $carddav_token_notice );
                    break;
                case 'runtime':
                    metis_settings_save_runtime_section( $errors, $saved );
                    break;
                case 'jobs_tasks':
                    metis_settings_save_jobs_tasks_section( $is_system_admin, $errors, $saved );
                    break;
                case 'help':
                    metis_settings_save_help_section( $errors, $saved );
                    break;
            }
        }

        $stripe_secret      = metis_settings_get_credential_value( 'stripe_secret' );
        $stripe_publishable_key = Core_Settings_Service::get( 'stripe_publishable_key', '' );
        $webhook_secret     = metis_settings_get_credential_value( 'stripe_webhook_secret' );
        $portal_name        = Core_Settings_Service::get( 'portal_name', '' );
        $org_name           = Core_Settings_Service::get( 'org_name', '' );
        $org_tagline        = Core_Settings_Service::get( 'org_tagline', '' );
        $timezone = (string) Core_Settings_Service::get( 'timezone', Core_Settings_Service::get( 'site_timezone', 'UTC' ) );
        if ( $timezone === '' || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            $timezone = 'UTC';
        }
        $date_format = (string) Core_Settings_Service::get( 'date_format', 'm/d/y' );
        $time_format = (string) Core_Settings_Service::get( 'time_format', 'g:i:s a' );
        $date_presets = metis_settings_date_presets();
        $time_presets = metis_settings_time_presets();
        $date_preset_choice = array_search( $date_format, $date_presets, true );
        $time_preset_choice = array_search( $time_format, $time_presets, true );
        $date_format_choice = is_string( $date_preset_choice ) ? $date_preset_choice : 'custom';
        $time_format_choice = is_string( $time_preset_choice ) ? $time_preset_choice : 'custom';
        $date_format_custom = (string) Core_Settings_Service::get( 'date_format_custom', '' );
        $time_format_custom = (string) Core_Settings_Service::get( 'time_format_custom', '' );
        if ( $date_format_choice === 'custom' && $date_format_custom === '' ) {
            $date_format_custom = 'yyyy.mm.dd';
        }
        if ( $time_format_choice === 'custom' && $time_format_custom === '' ) {
            $time_format_custom = 'h:m:s a/m';
        }
        $portal_logo        = Core_Settings_Service::get( 'portal_logo', [] );
        $portal_logo_src    = metis_settings_asset_src( $portal_logo );
        $portal_favicon     = Core_Settings_Service::get( 'portal_favicon', [] );
        $portal_favicon_src = metis_settings_asset_src( $portal_favicon );
        $theme_color_fields = metis_settings_theme_color_fields();
        $theme_colors       = metis_settings_get_saved_theme_colors();
        $login_logo = Core_Settings_Service::get( 'login_logo', [] );
        $login_logo_src = metis_settings_asset_src( $login_logo );
        $login_background_image = Core_Settings_Service::get( 'login_background_image', [] );
        $login_background_image_src = metis_settings_asset_src( $login_background_image );
        $login_background_color = metis_hex_color_clean( (string) Core_Settings_Service::get( 'login_background_color', '#edf2f7' ) ) ?: '#edf2f7';
        $login_welcome_text = (string) Core_Settings_Service::get( 'login_welcome_text', 'Use a passkey first, Google Workspace next, or your local password if needed.' );
        $login_organization_name = (string) Core_Settings_Service::get( 'login_organization_name', 'Metis' );
        $login_footer_text = (string) Core_Settings_Service::get( 'login_footer_text', 'Secure access powered by Metis.' );
        $accessibility_profiles = function_exists( 'metis_accessibility_profiles' ) ? metis_accessibility_profiles() : [ 'none' => [ 'label' => 'Standard' ] ];
        $accessibility_toolbar_enabled = (int) Core_Settings_Service::get( 'accessibility_toolbar_enabled', 1 ) === 1;
        $accessibility_allow_overrides = (int) Core_Settings_Service::get( 'accessibility_allow_overrides', 1 ) === 1;
        $accessibility_default_profile = metis_key_clean( (string) Core_Settings_Service::get( 'accessibility_default_profile', 'none' ) );
        if ( ! isset( $accessibility_profiles[ $accessibility_default_profile ] ) ) {
            $accessibility_default_profile = 'none';
        }
        $logging_enabled = (int) Core_Settings_Service::get( 'logging_enabled', 1 ) === 1;
        $logging_min_level  = strtoupper( (string) Core_Settings_Service::get( 'logging_min_level', 'INFO' ) );
        if ( ! in_array( $logging_min_level, [ 'INFO', 'WARN', 'ERROR' ], true ) ) {
            $logging_min_level = 'INFO';
        }
        $logging_force_url_token = (string) Core_Settings_Service::get( 'logging_force_url_token', '' );
        $logging_view_lines = 200;
        $logging_available_logs = [];
        $logging_view_file = '';
        $logging_search = '';
        $logging_page = 1;
        $logging_auto_refresh = false;
        $logging_auto_refresh_seconds = 10;
        $logging_per_page = 50;
        $logging_log_path = class_exists( 'Metis_Logger' ) ? (string) Metis_Logger::viewer_log_path( '' ) : '';
        $logging_total_entries = 0;
        $logging_total_pages = 1;
        $logging_entries = [];
        if ( metis_settings_should_load_logging_state( $section ) ) {
            extract( metis_settings_build_logging_viewer_state( metis_request_post() ), EXTR_OVERWRITE );
        }

        $menu_modules = [];
        if ( metis_settings_should_load_navigation_state( $section ) ) {
            $menu_modules = metis_settings_menu_modules();
        }
        $workspace_impersonation_admin = Core_Settings_Service::get( 'workspace_impersonation_admin', '' );
        $workspace_customer_id         = Core_Settings_Service::get( 'workspace_customer_id', '' );
        $workspace_domain              = Core_Settings_Service::get( 'workspace_domain', '' );
        $workspace_shared_drive_id     = Core_Settings_Service::get( 'workspace_shared_drive_id', '' );
        $workspace_default_calendar_id = Core_Settings_Service::get( 'workspace_default_calendar_id', '' );
        $workspace_drive_configs       = metis_settings_normalize_drive_rows( Core_Settings_Service::get( 'workspace_drive_configs', [] ) );
        $backup_drive_id               = (string) Core_Settings_Service::get( 'backup_drive_id', '' );
        $backup_retention_runs         = max( 1, (int) Core_Settings_Service::get( 'backup_retention_runs', 14 ) );
        $backup_environment            = (string) Core_Settings_Service::get( 'backup_environment', '' );
        $workspace_calendar_configs    = metis_settings_normalize_calendar_rows( Core_Settings_Service::get( 'workspace_calendar_configs', [] ) );
        $workspace_service_account_json = metis_settings_get_credential_value( 'workspace_service_account_json' );
        $workspace_service_account_present = is_string( $workspace_service_account_json ) && trim( $workspace_service_account_json ) !== '';
        $carddav_tokens = [];
        $carddav_username = '';
        $carddav_endpoint = function_exists( 'metis_contacts_carddav_endpoint_url' ) ? metis_contacts_carddav_endpoint_url( 'addressbooks/' ) : '';
        $stripe_webhook_endpoint = function_exists( 'metis_webhook_url' ) ? (string) metis_webhook_url( 'stripe' ) : '';
        $webhook_base_endpoint = function_exists( 'metis_webhook_base_url' ) ? (string) metis_webhook_base_url() : '';
        $ajax_endpoint_url = function_exists( 'metis_ajax_endpoint_url' ) ? (string) metis_ajax_endpoint_url() : '';
        $ajax_endpoint_path = function_exists( 'metis_ajax_endpoint_path' ) ? (string) metis_ajax_endpoint_path() : '/api/ajax';
        $batch_api_endpoint = (string) metis_home_url( '/api/batch' );
        $api_auth_resolve_endpoint = (string) metis_home_url( '/api/auth/resolve' );
        $api_auth_passkeys_complete_endpoint = (string) metis_home_url( '/api/auth/passkeys/complete' );
        $settings_save_action = 'metis_settings_save_section';
        $settings_save_nonce_action = function_exists( 'metis_ajax_nonce_action' ) ? (string) metis_ajax_nonce_action( $settings_save_action ) : '';
        $current_user = metis_runtime_current_user();
        if ( $current_user instanceof MetisUser ) {
            $carddav_username = (string) $current_user->user_login;
        }
        $workspace_stripe_sso_schema    = Core_Settings_Service::get( 'workspace_stripe_sso_schema', '' );
        $workspace_stripe_sso_field     = Core_Settings_Service::get( 'workspace_stripe_sso_field', '' );
        $workspace_google_sso_client_id = Core_Settings_Service::get( 'workspace_google_sso_client_id', '' );
        $workspace_google_sso_client_secret = metis_settings_get_credential_value( 'workspace_google_sso_client_secret' );
        $workspace_google_sso_client_secret_credential_id = (string) Core_Settings_Service::get( 'workspace_google_sso_client_secret_credential_id', '' );
        $workspace_google_sso_hosted_domain = Core_Settings_Service::get( 'workspace_google_sso_hosted_domain', '' );
        $workspace_stripe_access_group_email = Core_Settings_Service::get( 'workspace_stripe_access_group_email', '' );
        $communications_inbound_google_project_id = metis_settings_scalar_string( Core_Settings_Service::get( 'communications_inbound_google_project_id', '' ) );
        $communications_inbound_pubsub_topic = metis_settings_scalar_string( Core_Settings_Service::get( 'communications_inbound_pubsub_topic', '' ) );
        $communications_inbound_pubsub_audience = metis_settings_scalar_string( Core_Settings_Service::get( 'communications_inbound_pubsub_audience', '' ) );
        $communications_inbound_pubsub_service_account_email = strtolower( metis_settings_scalar_string( Core_Settings_Service::get( 'communications_inbound_pubsub_service_account_email', '' ) ) );
        $communications_inbound_mailboxes = metis_settings_normalize_inbound_mailbox_rows( Core_Settings_Service::get( 'communications_inbound_mailboxes', [] ) );
        $communications_inbound_mailboxes_json = is_array( $communications_inbound_mailboxes )
            ? json_encode( $communications_inbound_mailboxes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            : '[]';
        $communications_inbound_mailboxes_json = is_string( $communications_inbound_mailboxes_json ) ? $communications_inbound_mailboxes_json : '[]';
        if ( $communications_inbound_mailboxes === [] ) {
            $communications_inbound_mailboxes[] = [
                'mailbox_key' => '',
                'mailbox_email' => '',
                'display_name' => '',
                'delegated_user' => '',
                'enabled' => 1,
                'label_ids' => [],
                'label_filter_behavior' => '',
            ];
        }
        $communications_inbound_log_verbosity = metis_key_clean( metis_settings_scalar_string( Core_Settings_Service::get( 'communications_inbound_log_verbosity', 'standard' ), 'standard' ) );
        if ( ! in_array( $communications_inbound_log_verbosity, [ 'quiet', 'standard', 'verbose' ], true ) ) {
            $communications_inbound_log_verbosity = 'standard';
        }
        $communications_inbound_full_sync_days = metis_settings_int_range( Core_Settings_Service::get( 'communications_inbound_full_sync_days', 30 ), 30, 1, 90 );
        $communications_inbound_allow_reprocess = metis_settings_bool_flag( Core_Settings_Service::get( 'communications_inbound_allow_reprocess', 0 ), false );
        $communications_inbound_enable_bounce_handler = metis_settings_bool_flag( Core_Settings_Service::get( 'communications_inbound_enable_bounce_handler', 1 ), true );
        $communications_inbound_enable_unsubscribe_handler = metis_settings_bool_flag( Core_Settings_Service::get( 'communications_inbound_enable_unsubscribe_handler', 1 ), true );
        $communications_inbound_enable_grandys_stash_handler = metis_settings_bool_flag( Core_Settings_Service::get( 'communications_inbound_enable_grandys_stash_handler', 1 ), true );
        $gmail_pubsub_webhook_endpoint = function_exists( 'metis_webhook_url' ) ? (string) metis_webhook_url( 'gmail_pubsub' ) : '';
        $newsletter_default_from_name = Core_Settings_Service::get( 'newsletter_default_from_name', '' );
        $newsletter_default_from_email = Core_Settings_Service::get( 'newsletter_default_from_email', '' );
        $newsletter_default_reply_to = Core_Settings_Service::get( 'newsletter_default_reply_to', '' );
        $newsletter_google_daily_limit = (int) Core_Settings_Service::get( 'newsletter_google_daily_limit', 2000 );
        $newsletter_google_daily_limit = $newsletter_google_daily_limit < 100 ? 2000 : $newsletter_google_daily_limit;
        $newsletter_klipy_api_key = metis_settings_get_credential_value( 'newsletter_klipy_api_key' );
        $newsletter_klipy_credential_id = (string) Core_Settings_Service::get( 'newsletter_klipy_api_key_credential_id', '' );
        $klipy_credentials = [];
        $stripe_credentials = [];
        $webhook_credentials = [];
        $google_service_account_credentials = [];
        $google_oauth_client_secret_credentials = [];
        if ( metis_settings_should_load_credential_lists( $section ) ) {
            $klipy_credentials = \Metis\Core\Services\CredentialService::listCredentials( 'klipy_api_key' );
            $stripe_credentials = \Metis\Core\Services\CredentialService::listCredentials( 'stripe_key' );
            $webhook_credentials = \Metis\Core\Services\CredentialService::listCredentials( 'webhook_secret' );
            $google_service_account_credentials = \Metis\Core\Services\CredentialService::listCredentials( 'google_service_account' );
            $google_oauth_client_secret_credentials = \Metis\Core\Services\CredentialService::listCredentials( 'google_oauth_client_secret' );
        }
        $newsletter_klipy_search_url = metis_settings_normalize_klipy_search_url( (string) Core_Settings_Service::get( 'newsletter_klipy_search_url', metis_settings_default_klipy_search_url() ) );
        if ( $newsletter_klipy_search_url === '' ) {
            $newsletter_klipy_search_url = metis_settings_default_klipy_search_url();
        }
        $email_usage_snapshot = [];
        if ( metis_settings_should_load_email_usage( $section ) ) {
            $email_usage_snapshot = metis_settings_build_email_usage_snapshot();
        }
        $profile_allow_name_edit = (int) Core_Settings_Service::get( 'profile_allow_name_edit', 0 ) === 1;
        $system_cron_secret_masked = Metis_Cron_Manager::configured_secret_masked();
        $system_cron_endpoint = Metis_Cron_Manager::endpoint_url();
        $system_cron_header = 'x-metis-cron-secret';
        $system_cron_tasks = [];
        $queue_summary = [ 'cron' => [], 'operations' => [] ];
        $recent_async_jobs = [];
        $operations_recent_jobs = [];
        $system_cron_recent_jobs = [];
        $operations_command_catalog = [];
        $integrity_baseline_status = [];
        $release_status = [];
        $system_version = [ 'metis_version' => '', 'build' => '', 'modules' => [] ];
        $system_cron_task_rows = [];
        if ( metis_settings_should_load_scheduler_snapshot( $section ) ) {
            $scheduler_snapshot = metis_settings_build_scheduler_snapshot( (string) $timezone, (string) $date_format, (string) $time_format );
            $system_cron_tasks = (array) ( $scheduler_snapshot['system_cron_tasks'] ?? [] );
            $queue_summary = (array) ( $scheduler_snapshot['queue_summary'] ?? [ 'cron' => [], 'operations' => [] ] );
            $recent_async_jobs = (array) ( $scheduler_snapshot['recent_async_jobs'] ?? [] );
            $operations_recent_jobs = (array) ( $scheduler_snapshot['operations_recent_jobs'] ?? [] );
            $system_cron_recent_jobs = (array) ( $scheduler_snapshot['system_cron_recent_jobs'] ?? [] );
            $system_cron_task_rows = (array) ( $scheduler_snapshot['system_cron_task_rows'] ?? [] );
            $operations_command_catalog = \Metis\Core\Application::has_service( 'operations' )
                ? metis_operations()->commandCatalog()
                : [];
        }
        if ( metis_settings_section_matches( $section, [ 'operations' ] ) ) {
            $operations_command_catalog = \Metis\Core\Application::has_service( 'operations' )
                ? metis_operations()->commandCatalog()
                : [];
        }
        if ( metis_settings_should_load_release_state( $section ) ) {
            $integrity_baseline_status = Metis_Integrity_Manager::verify_baseline( false );
            $release_status = function_exists( 'metis_release_status_snapshot' )
                ? metis_release_status_snapshot()
                : ( function_exists( 'metis_release_status' ) ? metis_release_status( false ) : [] );
            $system_version = \Metis\Core\Application::has_service( 'system_version' )
                ? (array) \Metis\Core\Application::service( 'system_version' )->current()
                : [ 'metis_version' => '', 'build' => '', 'modules' => [] ];
        }
        $system_cron_configured = $system_cron_secret_masked !== '';
        $performance_security_report = [
            'score' => 0,
            'generated_at' => '',
            'status_counts' => [ 'pass' => 0, 'warn' => 0, 'fail' => 0 ],
            'checks' => [],
            'kpis' => [],
        ];
        $backup_runs = [];

        $stripe_connected   = is_string( $stripe_secret ) && str_starts_with( $stripe_secret, 'sk_' );
        $webhook_configured = is_string( $webhook_secret ) && str_starts_with( $webhook_secret, 'whsec_' );
        $workspace_configured = is_string( $workspace_impersonation_admin ) && metis_email_is_valid( $workspace_impersonation_admin )
            && is_string( $workspace_service_account_json ) && trim( $workspace_service_account_json ) !== '';
        $workspace_google_sso_configured = is_string( $workspace_google_sso_client_id ) && trim( $workspace_google_sso_client_id ) !== ''
            && is_string( $workspace_google_sso_client_secret ) && trim( $workspace_google_sso_client_secret ) !== '';

        $workspace_shared_drive_options = [];
        $workspace_shared_drive_error = '';
        if (
            metis_settings_should_load_drive_options( $section )
            && $is_system_admin
            && $workspace_configured
            && function_exists( 'metis_drive_workspace_settings' )
            && function_exists( 'metis_drive_list_shared_drives' )
        ) {
            $drive_cfg = function_exists( 'metis_drive_workspace_base_settings' ) ? metis_drive_workspace_base_settings() : metis_drive_workspace_settings();
            if ( ! empty( $drive_cfg['ok'] ) ) {
                $drive_list = metis_drive_list_shared_drives( $drive_cfg );
                if ( ! empty( $drive_list['ok'] ) ) {
                    $workspace_shared_drive_options = (array) ( $drive_list['drives'] ?? [] );
                } else {
                    $workspace_shared_drive_error = 'Unable to load shared drives.';
                }
            } else {
                $workspace_shared_drive_error = 'Drive workspace configuration is incomplete.';
            }
        }
        $backup_drive_options = $workspace_shared_drive_options;
        $backup_drive_error = $workspace_shared_drive_error;

        $workspace_calendar_options = [];
        $workspace_calendar_error = '';
        $help_enabled = (int) Core_Settings_Service::get( 'help_enabled', 1 ) === 1;
        $walkthrough_enabled = (int) Core_Settings_Service::get( 'walkthrough_enabled', 1 ) === 1;
        $help_topic_overrides = Core_Settings_Service::get( 'help_topic_overrides', [] );
        $help_custom_topics = Core_Settings_Service::get( 'help_custom_topics', [] );
        $help_custom_walkthroughs = Core_Settings_Service::get( 'help_custom_walkthroughs', [] );
        $site_homepage_page_id = (int) Core_Settings_Service::get( 'site_homepage_page_id', 0 );
        $website_pages_for_homepage = [];
        if ( metis_settings_should_load_homepage_pages( $section ) && class_exists( '\Metis\Modules\Website\Services\PageService' ) ) {
            $website_pages_for_homepage = array_values(
                \Metis\Modules\Website\Services\PageService::getAll( [ 'status' => 'published' ] )
            );
        }
        if (
            metis_settings_should_load_calendar_options( $section )
            && $is_system_admin
            && $workspace_configured
            && function_exists( 'metis_calendar_workspace_base_settings' )
            && function_exists( 'metis_calendar_list_calendars' )
        ) {
            $calendar_cfg = metis_calendar_workspace_base_settings();
            if ( ! empty( $calendar_cfg['ok'] ) ) {
                $calendar_list = metis_calendar_list_calendars( $calendar_cfg );
                if ( ! empty( $calendar_list['ok'] ) ) {
                    $workspace_calendar_options = (array) ( $calendar_list['calendars'] ?? [] );
                } else {
                    $workspace_calendar_error = 'Unable to load calendars.';
                }
            } else {
                $workspace_calendar_error = 'Calendar workspace configuration is incomplete.';
            }
        }

        if ( empty( $workspace_drive_configs ) && $workspace_shared_drive_id !== '' ) {
            $workspace_drive_configs[] = [
                'label' => 'Primary Drive',
                'drive_id' => (string) $workspace_shared_drive_id,
                'drive_name' => '',
                'is_default' => 1,
                'is_users_home' => 0,
            ];
        }
        if ( empty( $workspace_calendar_configs ) && $workspace_default_calendar_id !== '' ) {
            $workspace_calendar_configs[] = [
                'label' => 'Primary Calendar',
                'calendar_id' => (string) $workspace_default_calendar_id,
                'calendar_name' => '',
                'is_default' => 1,
            ];
        }

        $workspace_google_sso_redirect_uri = function_exists( 'metis_auth_google_callback_url' )
            ? (string) metis_auth_google_callback_url()
            : '';

        $redirect_url = '';

        return compact(
            'section',
            'saved',
            'errors',
            'redirect_url',
            'is_system_admin',
            'portal_name',
            'org_name',
            'org_tagline',
            'timezone',
            'date_format',
            'time_format',
            'date_presets',
            'time_presets',
            'date_format_choice',
            'time_format_choice',
            'date_format_custom',
            'time_format_custom',
            'portal_logo',
            'portal_logo_src',
            'portal_favicon',
            'portal_favicon_src',
            'theme_color_fields',
            'theme_colors',
            'login_logo',
            'login_logo_src',
            'login_background_image',
            'login_background_image_src',
            'login_background_color',
            'login_welcome_text',
            'login_organization_name',
            'login_footer_text',
            'accessibility_profiles',
            'accessibility_toolbar_enabled',
            'accessibility_allow_overrides',
            'accessibility_default_profile',
            'logging_enabled',
            'logging_min_level',
            'logging_force_url_token',
            'logging_view_lines',
            'logging_view_file',
            'logging_available_logs',
            'logging_log_path',
            'logging_search',
            'logging_page',
            'logging_auto_refresh',
            'logging_auto_refresh_seconds',
            'logging_per_page',
            'logging_total_entries',
            'logging_total_pages',
            'logging_entries',
            'menu_modules',
            'workspace_impersonation_admin',
            'workspace_customer_id',
            'workspace_domain',
            'workspace_shared_drive_id',
            'workspace_default_calendar_id',
            'workspace_drive_configs',
            'backup_drive_id',
            'backup_retention_runs',
            'backup_environment',
            'backup_runs',
            'backup_drive_options',
            'backup_drive_error',
            'workspace_calendar_configs',
            'workspace_service_account_json',
            'workspace_service_account_present',
            'carddav_tokens',
            'carddav_token_notice',
            'carddav_username',
            'carddav_endpoint',
            'stripe_webhook_endpoint',
            'webhook_base_endpoint',
            'ajax_endpoint_url',
            'ajax_endpoint_path',
            'batch_api_endpoint',
            'api_auth_resolve_endpoint',
            'api_auth_passkeys_complete_endpoint',
            'settings_save_action',
            'settings_save_nonce_action',
            'workspace_stripe_sso_schema',
            'workspace_stripe_sso_field',
            'workspace_google_sso_client_id',
            'workspace_google_sso_client_secret',
            'workspace_google_sso_client_secret_credential_id',
            'workspace_google_sso_hosted_domain',
            'workspace_google_sso_configured',
            'workspace_google_sso_redirect_uri',
            'workspace_stripe_access_group_email',
            'communications_inbound_google_project_id',
            'communications_inbound_pubsub_topic',
            'communications_inbound_pubsub_audience',
            'communications_inbound_pubsub_service_account_email',
            'communications_inbound_mailboxes',
            'communications_inbound_mailboxes_json',
            'communications_inbound_log_verbosity',
            'communications_inbound_full_sync_days',
            'communications_inbound_allow_reprocess',
            'communications_inbound_enable_bounce_handler',
            'communications_inbound_enable_unsubscribe_handler',
            'communications_inbound_enable_grandys_stash_handler',
            'gmail_pubsub_webhook_endpoint',
            'newsletter_default_from_name',
            'newsletter_default_from_email',
            'newsletter_default_reply_to',
            'newsletter_google_daily_limit',
            'newsletter_klipy_api_key',
            'newsletter_klipy_credential_id',
            'klipy_credentials',
            'stripe_credentials',
            'webhook_credentials',
            'google_service_account_credentials',
            'google_oauth_client_secret_credentials',
            'newsletter_klipy_search_url',
            'email_usage_snapshot',
            'profile_allow_name_edit',
            'system_cron_secret_masked',
            'system_cron_endpoint',
            'system_cron_header',
            'system_cron_task_rows',
            'queue_summary',
            'recent_async_jobs',
            'performance_security_report',
            'operations_recent_jobs',
            'system_cron_recent_jobs',
            'operations_command_catalog',
            'system_cron_configured',
            'system_version',
            'integrity_baseline_status',
            'release_status',
            'stripe_secret',
            'webhook_secret',
            'stripe_connected',
            'webhook_configured',
            'workspace_configured',
            'workspace_shared_drive_options',
            'workspace_shared_drive_error',
            'workspace_calendar_options',
            'workspace_calendar_error',
            'help_enabled',
            'walkthrough_enabled',
            'help_topic_overrides',
            'help_custom_topics',
            'help_custom_walkthroughs',
            'site_homepage_page_id',
            'website_pages_for_homepage'
        ) + [ 'allowed' => true ];
    }
}

if ( ! function_exists( 'metis_settings_build_email_usage_snapshot' ) ) {
    function metis_settings_build_email_usage_snapshot(): array {
        $db = metis_db();
        $today = metis_runtime_date( 'Y-m-d', time(), metis_newsletter_resolved_timezone() );
        $daily_limit = function_exists( 'metis_newsletter_google_usage_daily_limit' ) ? metis_newsletter_google_usage_daily_limit() : 2000;

        $messages_table = Metis_Tables::get( 'newsletter_messages' );
        $campaigns_table = Metis_Tables::get( 'newsletter_campaigns' );
        $google_usage_table = Metis_Tables::get( 'newsletter_google_usage_daily' );
        $service_usage_table = Metis_Tables::get( 'email_usage_daily' );
        $service_events_table = Metis_Tables::get( 'email_send_events' );

        $newsletter_sent_total = (int) $db->scalar( "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent'" );
        $newsletter_30 = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent' AND sent_at >= %s",
            [ ( new DateTimeImmutable( 'now', metis_newsletter_resolved_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' ) ]
        );
        $newsletter_campaigns = (int) $db->scalar( "SELECT COUNT(*) FROM {$campaigns_table}" );

        $google_today_sent = 0;
        $google_daily_rows = [];
        if ( function_exists( 'metis_newsletter_table_exists' ) && metis_newsletter_table_exists( $google_usage_table ) ) {
            $google_today_sent = (int) $db->scalar(
                "SELECT COALESCE(SUM(sent_count), 0) FROM {$google_usage_table} WHERE usage_date = %s",
                [ $today ]
            );
            $google_daily_rows = $db->fetchAll(
                "SELECT usage_date, COALESCE(SUM(sent_count), 0) AS sent_total
                 FROM {$google_usage_table}
                 GROUP BY usage_date
                 ORDER BY usage_date DESC
                 LIMIT 30"
            ) ?: [];
        }
        $google_today_pct = $daily_limit > 0 ? min( 100, max( 0, (int) round( ( $google_today_sent / $daily_limit ) * 100 ) ) ) : 0;

        if ( class_exists( '\Metis\Core\Services\EmailService' ) ) { \Metis\Core\Services\EmailService::ensureUsageTrackingReady(); }

        $service_module_rows = [];
        $service_today_total = 0;
        $service_30_total = 0;
        $service_all_total = 0;
        $service_usage_exists = false;
        $service_event_rows = [];
        try {
            $table_check = $db->fetchOne( 'SHOW TABLES LIKE %s', [ $service_usage_table ] );
            $service_usage_exists = ! empty( $table_check );
        } catch ( Throwable $e ) {
            $service_usage_exists = false;
        }
        if ( $service_usage_exists ) {
            $service_module_rows = $db->fetchAll(
                "SELECT module_slug,
                        SUM(CASE WHEN usage_date = CURDATE() THEN sent_count ELSE 0 END) AS today_sent,
                        SUM(CASE WHEN usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN sent_count ELSE 0 END) AS sent_30d,
                        SUM(sent_count) AS sent_all,
                        SUM(failed_count) AS failed_all
                 FROM {$service_usage_table}
                 GROUP BY module_slug
                 ORDER BY sent_30d DESC, sent_all DESC, module_slug ASC"
            ) ?: [];
            $service_today_total = (int) $db->scalar( "SELECT COALESCE(SUM(sent_count), 0) FROM {$service_usage_table} WHERE usage_date = CURDATE()" );
            $service_30_total = (int) $db->scalar( "SELECT COALESCE(SUM(sent_count), 0) FROM {$service_usage_table} WHERE usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" );
            $service_all_total = (int) $db->scalar( "SELECT COALESCE(SUM(sent_count), 0) FROM {$service_usage_table}" );
        }
        try {
            $event_table_check = $db->fetchOne( 'SHOW TABLES LIKE %s', [ $service_events_table ] );
            if ( ! empty( $event_table_check ) ) {
                $service_event_rows = $db->fetchAll(
                    "SELECT event_at, module_slug, status, provider, to_email, subject, error_message
                     FROM {$service_events_table}
                     ORDER BY event_at DESC
                     LIMIT 25"
                ) ?: [];
            }
        } catch ( Throwable $e ) {
            $service_event_rows = [];
        }

        return [
            'google_today_sent' => $google_today_sent,
            'google_today_pct' => $google_today_pct,
            'google_daily_limit' => $daily_limit,
            'google_daily_rows' => $google_daily_rows,
            'newsletter_sent_total' => $newsletter_sent_total,
            'newsletter_30' => $newsletter_30,
            'newsletter_campaigns' => $newsletter_campaigns,
            'service_module_rows' => $service_module_rows,
            'service_event_rows' => $service_event_rows,
            'service_today_total' => $service_today_total,
            'service_30_total' => $service_30_total,
            'service_all_total' => $service_all_total,
        ];
    }
}
