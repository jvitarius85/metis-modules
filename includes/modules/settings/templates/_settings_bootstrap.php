<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'metis_settings_sections' ) ) {
    function metis_settings_sections(): array {
        return [
            'general' => 'General',
            'logging' => 'Logging',
            'customization' => 'Customization',
            'menu' => 'Menu',
            'profile' => 'Profile',
            'newsletter' => 'Newsletter',
            'workspace' => 'Workspace',
            'drive' => 'Drive',
            'calendar' => 'Calendar',
            'api' => 'API Keys',
            'scheduler' => 'Scheduler',
        ];
    }
}

if ( ! function_exists( 'metis_settings_render_section_nav' ) ) {
    function metis_settings_render_section_nav( string $current_section ): void {
        echo '<nav class="metis-settings-links" aria-label="Settings sections">';
        foreach ( metis_settings_sections() as $slug => $label ) {
            $class = $slug === $current_section ? ' is-active' : '';
            echo '<a class="metis-settings-link' . esc_attr( $class ) . '" href="' . esc_url( metis_portal_url( 'settings', $slug ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }
}

if ( ! function_exists( 'metis_settings_render_messages' ) ) {
    function metis_settings_render_messages( bool $saved, array $errors ): void {
        if ( $saved && empty( $errors ) ) {
            echo '<div class="mw-alert mw-alert-success">Settings saved.</div>';
        }
        if ( ! empty( $errors ) ) {
            echo '<div class="mw-alert mw-alert-error">';
            foreach ( $errors as $error ) {
                echo '<p>' . metis_kses_post( (string) $error ) . '</p>';
            }
            echo '</div>';
        }
    }
}

if ( ! function_exists( 'metis_settings_section_url' ) ) {
    function metis_settings_section_url( string $section ): string {
        $sections = metis_settings_sections();
        $section  = array_key_exists( $section, $sections ) ? $section : 'general';
        return metis_portal_url( 'settings', $section );
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
                'filename' => sanitize_file_name( (string) ( $file['name'] ?? strtolower( $label ) ) ),
                'mime_type' => $mime_type,
                'size' => $size,
                'data_base64' => base64_encode( $contents ),
                'updated_at' => current_time( 'mysql' ),
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

        $mime_type = (string) ( $asset['mime_type'] ?? '' );
        $data      = (string) ( $asset['data_base64'] ?? '' );
        if ( $mime_type === '' || $data === '' ) {
            return '';
        }

        return 'data:' . $mime_type . ';base64,' . $data;
    }
}

if ( ! function_exists( 'metis_settings_theme_color_fields' ) ) {
    function metis_settings_theme_color_fields(): array {
        return [
            'mw_primary' => [ 'css_var' => '--mw-primary', 'label' => 'Primary', 'default' => '#485bc7' ],
            'mw_primary_dark' => [ 'css_var' => '--mw-primary-dark', 'label' => 'Primary Dark', 'default' => '#3246a7' ],
            'mw_accent' => [ 'css_var' => '--mw-accent', 'label' => 'Accent', 'default' => '#ff7542' ],
            'mw_bg' => [ 'css_var' => '--mw-bg', 'label' => 'Background', 'default' => '#f5f6fa' ],
            'mw_surface' => [ 'css_var' => '--mw-surface', 'label' => 'Surface', 'default' => '#ffffff' ],
            'mw_border' => [ 'css_var' => '--mw-border', 'label' => 'Border', 'default' => '#e0e2ea' ],
            'mw_text' => [ 'css_var' => '--mw-text', 'label' => 'Text', 'default' => '#1f2330' ],
            'mw_text_muted' => [ 'css_var' => '--mw-text-muted', 'label' => 'Muted Text', 'default' => '#6d7485' ],
            'mw_header_bg' => [ 'css_var' => '--mw-header-bg', 'label' => 'Header Background', 'default' => '#eceeff' ],
            'mw_row_odd_bg' => [ 'css_var' => '--mw-row-odd-bg', 'label' => 'Row Odd Background', 'default' => '#ffffff' ],
            'mw_row_even_bg' => [ 'css_var' => '--mw-row-even-bg', 'label' => 'Row Even Background', 'default' => '#f8f9fd' ],
            'mw_row_hover_bg' => [ 'css_var' => '--mw-row-hover-bg', 'label' => 'Row Hover Background', 'default' => '#eef2ff' ],
            'mw_sidebar_bg' => [ 'css_var' => '--mw-sidebar-bg', 'label' => 'Sidebar Background', 'default' => '#16192b' ],
            'mw_sidebar_icon_color' => [ 'css_var' => '--mw-sidebar-icon-color', 'label' => 'Sidebar Icon', 'default' => '#7a82a6' ],
            'mw_sidebar_active_color' => [ 'css_var' => '--mw-sidebar-active-color', 'label' => 'Sidebar Active', 'default' => '#a8b4ff' ],
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
            $result[ $key ] = sanitize_hex_color( $value ) ?: $default;
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

        $ordered = function_exists( 'metis_order_modules_for_navigation' )
            ? metis_order_modules_for_navigation( $modules )
            : $modules;

        $result = [];
        foreach ( $ordered as $slug => $module ) {
            if ( $slug === 'profile' ) {
                continue;
            }
            $config = is_array( $module['config'] ?? null ) ? $module['config'] : [];
            $result[] = [
                'slug' => $slug,
                'label' => (string) ( $config['label'] ?? ucfirst( $slug ) ),
            ];
        }

        return $result;
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
            $drive_id = sanitize_text_field( $row['drive_id'] ?? '' );
            if ( $drive_id === '' ) {
                continue;
            }
            $normalized[] = [
                'label' => sanitize_text_field( $row['label'] ?? '' ),
                'drive_id' => $drive_id,
                'drive_name' => sanitize_text_field( $row['drive_name'] ?? '' ),
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
            $calendar_id = sanitize_text_field( $row['calendar_id'] ?? '' );
            if ( $calendar_id === '' ) {
                continue;
            }
            $normalized[] = [
                'label' => sanitize_text_field( $row['label'] ?? '' ),
                'calendar_id' => $calendar_id,
                'calendar_name' => sanitize_text_field( $row['calendar_name'] ?? '' ),
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
        if ( ! empty( $_POST['remove_portal_logo'] ) ) {
            Core_Settings_Service::delete( 'portal_logo' );
            $saved = true;
        } elseif ( isset( $_FILES['portal_logo_file'] ) && ! empty( $_FILES['portal_logo_file']['name'] ) ) {
            $logo_upload = metis_settings_save_logo_upload( $_FILES['portal_logo_file'] );
            if ( empty( $logo_upload['ok'] ) ) {
                $errors[] = (string) ( $logo_upload['error'] ?? 'Unable to save the uploaded logo.' );
            } else {
                Core_Settings_Service::set( 'portal_logo', $logo_upload['logo'], false );
                $saved = true;
            }
        }

        if ( ! empty( $_POST['remove_portal_favicon'] ) ) {
            Core_Settings_Service::delete( 'portal_favicon' );
            $saved = true;
        } elseif ( isset( $_FILES['portal_favicon_file'] ) && ! empty( $_FILES['portal_favicon_file']['name'] ) ) {
            $favicon_upload = metis_settings_save_favicon_upload( $_FILES['portal_favicon_file'] );
            if ( empty( $favicon_upload['ok'] ) ) {
                $errors[] = (string) ( $favicon_upload['error'] ?? 'Unable to save the uploaded favicon.' );
            } else {
                Core_Settings_Service::set( 'portal_favicon', $favicon_upload['favicon'], false );
                $saved = true;
            }
        }

        $portal_name = sanitize_text_field( $_POST['portal_name'] ?? '' );
        if ( $portal_name !== '' ) {
            Core_Settings_Service::set( 'portal_name', $portal_name );
            $saved = true;
        }

        $org_name = sanitize_text_field( $_POST['org_name'] ?? '' );
        if ( $org_name !== '' ) {
            Core_Settings_Service::set( 'org_name', $org_name );
            $saved = true;
        }
    }
}

if ( ! function_exists( 'metis_settings_save_customization_section' ) ) {
    function metis_settings_save_customization_section( array &$errors, bool &$saved ): void {
        $theme_colors = [];
        foreach ( metis_settings_theme_color_fields() as $key => $field ) {
            $value = sanitize_hex_color( (string) ( $_POST['theme_colors'][ $key ] ?? '' ) );
            $theme_colors[ $key ] = $value ?: (string) ( $field['default'] ?? '' );
        }
        Core_Settings_Service::set( 'theme_colors', $theme_colors, true );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_logging_section' ) ) {
    function metis_settings_save_logging_section( array &$errors, bool &$saved ): void {
        $allowed_levels = [ 'INFO', 'WARN', 'ERROR' ];
        $logging_min_level = strtoupper( sanitize_text_field( (string) ( $_POST['logging_min_level'] ?? 'INFO' ) ) );
        if ( ! in_array( $logging_min_level, $allowed_levels, true ) ) {
            $logging_min_level = 'INFO';
        }

        $logging_force_url_token = trim( sanitize_text_field( (string) metis_unslash( $_POST['logging_force_url_token'] ?? '' ) ) );

        Core_Settings_Service::set( 'logging_min_level', $logging_min_level, true );
        Core_Settings_Service::set( 'logging_force_url_token', $logging_force_url_token, false );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_menu_section' ) ) {
    function metis_settings_save_menu_section( array &$errors, bool &$saved ): void {
        $available = [];
        foreach ( metis_settings_menu_modules() as $module ) {
            $available[] = sanitize_key( (string) ( $module['slug'] ?? '' ) );
        }

        $submitted = metis_unslash( $_POST['menu_module_order'] ?? [] );
        $submitted = is_array( $submitted ) ? $submitted : [];
        $clean = [];
        foreach ( $submitted as $slug ) {
            $slug = sanitize_key( (string) $slug );
            if ( $slug !== '' && in_array( $slug, $available, true ) && ! in_array( $slug, $clean, true ) ) {
                $clean[] = $slug;
            }
        }

        foreach ( $available as $slug ) {
            if ( ! in_array( $slug, $clean, true ) ) {
                $clean[] = $slug;
            }
        }

        Core_Settings_Service::set( 'menu_module_order', $clean, true );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_profile_section' ) ) {
    function metis_settings_save_profile_section( array &$errors, bool &$saved ): void {
        $profile_allow_name_edit = ! empty( $_POST['profile_allow_name_edit'] ) ? '1' : '0';
        Core_Settings_Service::set( 'profile_allow_name_edit', $profile_allow_name_edit );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_newsletter_section' ) ) {
    function metis_settings_save_newsletter_section( array &$errors, bool &$saved ): void {
        $newsletter_default_from_name = sanitize_text_field( $_POST['newsletter_default_from_name'] ?? '' );
        Core_Settings_Service::set( 'newsletter_default_from_name', $newsletter_default_from_name, true );
        $saved = true;

        $newsletter_default_from_email = sanitize_email( $_POST['newsletter_default_from_email'] ?? '' );
        if ( $newsletter_default_from_email !== '' && ! is_email( $newsletter_default_from_email ) ) {
            $errors[] = 'Newsletter default from email must be a valid email.';
        } else {
            Core_Settings_Service::set( 'newsletter_default_from_email', strtolower( (string) $newsletter_default_from_email ), true );
            $saved = true;
        }

        $newsletter_default_reply_to = sanitize_email( $_POST['newsletter_default_reply_to'] ?? '' );
        if ( $newsletter_default_reply_to !== '' && ! is_email( $newsletter_default_reply_to ) ) {
            $errors[] = 'Newsletter default reply-to must be a valid email.';
        } else {
            Core_Settings_Service::set( 'newsletter_default_reply_to', strtolower( (string) $newsletter_default_reply_to ), true );
            $saved = true;
        }

        $newsletter_google_daily_limit = (int) ( $_POST['newsletter_google_daily_limit'] ?? 2000 );
        if ( $newsletter_google_daily_limit < 100 ) {
            $newsletter_google_daily_limit = 100;
        } elseif ( $newsletter_google_daily_limit > 100000 ) {
            $newsletter_google_daily_limit = 100000;
        }
        Core_Settings_Service::set( 'newsletter_google_daily_limit', (string) $newsletter_google_daily_limit, true );

        $newsletter_klipy_api_key = sanitize_text_field( $_POST['newsletter_klipy_api_key'] ?? '' );
        Core_Settings_Service::set( 'newsletter_klipy_api_key', (string) $newsletter_klipy_api_key, false );
        $newsletter_klipy_search_url = esc_url_raw( (string) ( $_POST['newsletter_klipy_search_url'] ?? '' ) );
        if ( $newsletter_klipy_search_url === '' ) {
            $newsletter_klipy_search_url = 'https://api.klipy.com/v1/gifs/search';
        }
        Core_Settings_Service::set( 'newsletter_klipy_search_url', (string) $newsletter_klipy_search_url, true );
        $saved = true;
    }
}

if ( ! function_exists( 'metis_settings_save_workspace_section' ) ) {
    function metis_settings_save_workspace_section( array &$errors, bool &$saved ): void {
        $workspace_customer_id = sanitize_text_field( $_POST['workspace_customer_id'] ?? '' );
        Core_Settings_Service::set( 'workspace_customer_id', $workspace_customer_id, false );
        $saved = true;

        $workspace_domain = sanitize_text_field( $_POST['workspace_domain'] ?? '' );
        if ( $workspace_domain !== '' ) {
            $workspace_domain = strtolower( preg_replace( '/[^a-z0-9\.\-]/', '', $workspace_domain ) );
        }
        Core_Settings_Service::set( 'workspace_domain', $workspace_domain, false );
        $saved = true;

        $workspace_stripe_sso_schema = sanitize_text_field( $_POST['workspace_stripe_sso_schema'] ?? '' );
        Core_Settings_Service::set( 'workspace_stripe_sso_schema', (string) $workspace_stripe_sso_schema, false );
        $saved = true;

        $workspace_stripe_sso_field = sanitize_text_field( $_POST['workspace_stripe_sso_field'] ?? '' );
        Core_Settings_Service::set( 'workspace_stripe_sso_field', (string) $workspace_stripe_sso_field, false );
        $saved = true;

        $workspace_stripe_access_group_email = sanitize_email( $_POST['workspace_stripe_access_group_email'] ?? '' );
        if ( $workspace_stripe_access_group_email !== '' && ! is_email( $workspace_stripe_access_group_email ) ) {
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

        $workspace_drive_configs = metis_settings_normalize_drive_rows( metis_unslash( $_POST['workspace_drive_configs'] ?? [] ) );
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

        $workspace_calendar_configs = metis_settings_normalize_calendar_rows( metis_unslash( $_POST['workspace_calendar_configs'] ?? [] ) );
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

if ( ! function_exists( 'metis_settings_save_api_section' ) ) {
    function metis_settings_save_api_section( bool $is_system_admin, array &$errors, bool &$saved, ?array &$carddav_token_notice = null ): void {
        if ( isset( $_POST['metis_carddav_generate_token'] ) && function_exists( 'metis_contacts_carddav_issue_token' ) ) {
            $label  = sanitize_text_field( (string) metis_unslash( $_POST['metis_carddav_token_label'] ?? 'CardDAV device' ) );
            $issued = metis_contacts_carddav_issue_token( metis_current_user_id(), $label );
            if ( empty( $issued['ok'] ) ) {
                $errors[] = (string) ( $issued['error'] ?? 'Unable to generate CardDAV token.' );
            } else {
                $saved = true;
                $carddav_token_notice = $issued;
            }
        }

        if ( isset( $_POST['metis_carddav_revoke_token'] ) && function_exists( 'metis_contacts_carddav_revoke_token' ) ) {
            $token_id = isset( $_POST['metis_carddav_token_id'] ) ? (int) metis_unslash( $_POST['metis_carddav_token_id'] ) : 0;
            if ( $token_id < 1 || ! metis_contacts_carddav_revoke_token( $token_id, metis_current_user_id() ) ) {
                $errors[] = 'Unable to revoke that CardDAV token.';
            } else {
                $saved = true;
            }
        }

        $submitted_sensitive = false;
        foreach ( [ 'stripe_secret', 'stripe_webhook_secret', 'workspace_impersonation_admin', 'workspace_service_account_json' ] as $sensitive_key ) {
            if ( isset( $_POST[ $sensitive_key ] ) && trim( (string) metis_unslash( $_POST[ $sensitive_key ] ) ) !== '' ) {
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

        $stripe_secret = sanitize_text_field( $_POST['stripe_secret'] ?? '' );
        if ( $stripe_secret !== '' ) {
            if ( ! str_starts_with( $stripe_secret, 'sk_' ) ) {
                $errors[] = 'Stripe secret key must begin with <code>sk_</code>.';
            } else {
                Core_Settings_Service::set( 'stripe_secret', $stripe_secret, false );
                $saved = true;
            }
        }

        $webhook_secret = sanitize_text_field( $_POST['stripe_webhook_secret'] ?? '' );
        if ( $webhook_secret !== '' ) {
            if ( ! str_starts_with( $webhook_secret, 'whsec_' ) ) {
                $errors[] = 'Webhook secret must begin with <code>whsec_</code>.';
            } else {
                Core_Settings_Service::set( 'stripe_webhook_secret', $webhook_secret, false );
                $saved = true;
            }
        }

        $workspace_impersonation_admin = sanitize_email( $_POST['workspace_impersonation_admin'] ?? '' );
        if ( $workspace_impersonation_admin !== '' ) {
            if ( ! is_email( $workspace_impersonation_admin ) ) {
                $errors[] = 'Workspace impersonation admin must be a valid email.';
            } else {
                Core_Settings_Service::set( 'workspace_impersonation_admin', strtolower( $workspace_impersonation_admin ), false );
                $saved = true;
            }
        }

        $workspace_service_account_json = '';
        if ( isset( $_FILES['workspace_service_account_json_file'] ) && ! empty( $_FILES['workspace_service_account_json_file']['name'] ) ) {
            $upload = metis_settings_read_uploaded_json_file( $_FILES['workspace_service_account_json_file'], 'Workspace service account JSON file' );
            if ( empty( $upload['ok'] ) ) {
                $errors[] = (string) ( $upload['error'] ?? 'Unable to read the uploaded service account JSON file.' );
            } else {
                $workspace_service_account_json = trim( (string) ( $upload['json'] ?? '' ) );
            }
        } else {
            $workspace_service_account_json = trim( (string) metis_unslash( $_POST['workspace_service_account_json'] ?? '' ) );
        }

        if ( $workspace_service_account_json !== '' ) {
            $decoded = json_decode( $workspace_service_account_json, true );
            if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) || empty( $decoded['token_uri'] ) ) {
                $errors[] = 'Workspace service account JSON is invalid or missing required keys.';
            } else {
                Core_Settings_Service::set( 'workspace_service_account_json', $workspace_service_account_json, false );
                $saved = true;
            }
        }
    }
}

if ( ! function_exists( 'metis_settings_save_scheduler_section' ) ) {
    function metis_settings_save_scheduler_section( bool $is_system_admin, array &$errors, bool &$saved ): void {
        if ( ! $is_system_admin ) {
            $errors[] = 'Only system admins can manage the scheduler secret.';
            return;
        }

        $registered_tasks = array_keys( Metis_Cron_Manager::registered_tasks() );
        $selected_tasks = metis_unslash( $_POST['system_cron_enabled_tasks'] ?? [] );
        $selected_tasks = is_array( $selected_tasks ) ? $selected_tasks : [];
        $enabled_tasks = [];

        foreach ( $selected_tasks as $task_slug ) {
            $task_slug = sanitize_key( (string) $task_slug );
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

        $submitted_intervals = metis_unslash( $_POST['system_cron_task_intervals'] ?? [] );
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

        if ( ! empty( $_POST['metis_generate_cron_secret'] ) ) {
            $secret = bin2hex( random_bytes( 24 ) );
            Core_Settings_Service::set( 'system_cron_secret', $secret, false );
            $saved = true;
            return;
        }

        $secret = trim( sanitize_text_field( (string) metis_unslash( $_POST['system_cron_secret'] ?? '' ) ) );
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

if ( ! function_exists( 'metis_settings_bootstrap' ) ) {
    function metis_settings_bootstrap( string $section ): array {
        $sections = metis_settings_sections();
        $section  = array_key_exists( $section, $sections ) ? $section : 'general';

        $can_admin_settings = metis_current_user_can( 'manage_options' ) || ( function_exists( 'metis_people_can_manage' ) && metis_people_can_manage() );
        if ( ! $can_admin_settings ) {
            return [ 'allowed' => false, 'section' => $section ];
        }

        $is_system_admin = metis_current_user_can( 'manage_options' );
        metis_install_db();

        $saved  = false;
        $errors = [];
        $carddav_token_notice = null;
        $nonce_action = 'metis_save_settings_' . $section;
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset( $_POST['metis_settings_nonce'] )
            && metis_verify_nonce( $_POST['metis_settings_nonce'], $nonce_action )
        ) {
            switch ( $section ) {
                case 'general':
                    metis_settings_save_general_section( $errors, $saved );
                    break;
                case 'customization':
                    metis_settings_save_customization_section( $errors, $saved );
                    break;
                case 'logging':
                    metis_settings_save_logging_section( $errors, $saved );
                    break;
                case 'menu':
                    metis_settings_save_menu_section( $errors, $saved );
                    break;
                case 'profile':
                    metis_settings_save_profile_section( $errors, $saved );
                    break;
                case 'newsletter':
                    metis_settings_save_newsletter_section( $errors, $saved );
                    break;
                case 'workspace':
                    metis_settings_save_workspace_section( $errors, $saved );
                    break;
                case 'drive':
                    metis_settings_save_drive_section( $is_system_admin, $errors, $saved );
                    break;
                case 'calendar':
                    metis_settings_save_calendar_section( $is_system_admin, $errors, $saved );
                    break;
                case 'api':
                    $carddav_token_notice = null;
                    metis_settings_save_api_section( $is_system_admin, $errors, $saved, $carddav_token_notice );
                    break;
                case 'scheduler':
                    metis_settings_save_scheduler_section( $is_system_admin, $errors, $saved );
                    break;
            }
        }

        $stripe_secret      = Core_Settings_Service::get( 'stripe_secret', '' );
        $webhook_secret     = Core_Settings_Service::get( 'stripe_webhook_secret', '' );
        $portal_name        = Core_Settings_Service::get( 'portal_name', '' );
        $org_name           = Core_Settings_Service::get( 'org_name', '' );
        $portal_logo        = Core_Settings_Service::get( 'portal_logo', [] );
        $portal_logo_src    = metis_settings_asset_src( $portal_logo );
        $portal_favicon     = Core_Settings_Service::get( 'portal_favicon', [] );
        $portal_favicon_src = metis_settings_asset_src( $portal_favicon );
        $theme_color_fields = metis_settings_theme_color_fields();
        $theme_colors       = metis_settings_get_saved_theme_colors();
        $logging_min_level  = strtoupper( (string) Core_Settings_Service::get( 'logging_min_level', 'INFO' ) );
        if ( ! in_array( $logging_min_level, [ 'INFO', 'WARN', 'ERROR' ], true ) ) {
            $logging_min_level = 'INFO';
        }
        $logging_force_url_token = (string) Core_Settings_Service::get( 'logging_force_url_token', '' );
        $menu_modules       = metis_settings_menu_modules();
        $workspace_impersonation_admin = Core_Settings_Service::get( 'workspace_impersonation_admin', '' );
        $workspace_customer_id         = Core_Settings_Service::get( 'workspace_customer_id', '' );
        $workspace_domain              = Core_Settings_Service::get( 'workspace_domain', '' );
        $workspace_shared_drive_id     = Core_Settings_Service::get( 'workspace_shared_drive_id', '' );
        $workspace_default_calendar_id = Core_Settings_Service::get( 'workspace_default_calendar_id', '' );
        $workspace_drive_configs       = metis_settings_normalize_drive_rows( Core_Settings_Service::get( 'workspace_drive_configs', [] ) );
        $workspace_calendar_configs    = metis_settings_normalize_calendar_rows( Core_Settings_Service::get( 'workspace_calendar_configs', [] ) );
        $workspace_service_account_json = Core_Settings_Service::get( 'workspace_service_account_json', '' );
        $workspace_service_account_present = is_string( $workspace_service_account_json ) && trim( $workspace_service_account_json ) !== '';
        $carddav_tokens = function_exists( 'metis_contacts_carddav_list_tokens' ) ? metis_contacts_carddav_list_tokens( metis_current_user_id() ) : [];
        $carddav_username = '';
        $carddav_endpoint = function_exists( 'metis_contacts_carddav_endpoint_url' ) ? metis_contacts_carddav_endpoint_url( 'addressbooks/' ) : '';
        $current_user = metis_current_user();
        if ( $current_user instanceof WP_User ) {
            $carddav_username = (string) $current_user->user_login;
        }
        $workspace_stripe_sso_schema    = Core_Settings_Service::get( 'workspace_stripe_sso_schema', '' );
        $workspace_stripe_sso_field     = Core_Settings_Service::get( 'workspace_stripe_sso_field', '' );
        $workspace_stripe_access_group_email = Core_Settings_Service::get( 'workspace_stripe_access_group_email', '' );
        $newsletter_default_from_name = Core_Settings_Service::get( 'newsletter_default_from_name', '' );
        $newsletter_default_from_email = Core_Settings_Service::get( 'newsletter_default_from_email', '' );
        $newsletter_default_reply_to = Core_Settings_Service::get( 'newsletter_default_reply_to', '' );
        $newsletter_google_daily_limit = (int) Core_Settings_Service::get( 'newsletter_google_daily_limit', 2000 );
        $newsletter_google_daily_limit = $newsletter_google_daily_limit < 100 ? 2000 : $newsletter_google_daily_limit;
        $newsletter_klipy_api_key = Core_Settings_Service::get( 'newsletter_klipy_api_key', '' );
        $newsletter_klipy_search_url = Core_Settings_Service::get( 'newsletter_klipy_search_url', 'https://api.klipy.com/v1/gifs/search' );
        $profile_allow_name_edit = (int) Core_Settings_Service::get( 'profile_allow_name_edit', 0 ) === 1;
        $system_cron_secret_masked = Metis_Cron_Manager::configured_secret_masked();
        $system_cron_endpoint = Metis_Cron_Manager::endpoint_url();
        $system_cron_header = 'x-metis-cron-secret';
        $system_cron_tasks = Metis_Cron_Manager::registered_tasks();
        $integrity_baseline_status = Metis_Integrity_Manager::verify_baseline();
        $system_cron_task_rows = [];
        foreach ( $system_cron_tasks as $task_slug => $task_config ) {
            $task_state = get_option( 'metis_cron_task_state_' . $task_slug, [] );
            $task_state = is_array( $task_state ) ? $task_state : [];
            $system_cron_task_rows[] = [
                'slug' => $task_slug,
                'label' => (string) ( $task_config['label'] ?? $task_slug ),
                'module' => (string) ( $task_config['module'] ?? 'core' ),
                'enabled' => ! empty( $task_config['enabled'] ),
                'interval_minutes' => max( 1, (int) ceil( ( (int) ( $task_config['interval'] ?? 300 ) ) / MINUTE_IN_SECONDS ) ),
                'default_interval_minutes' => max( 1, (int) ceil( ( (int) ( $task_config['default_interval'] ?? $task_config['interval'] ?? 300 ) ) / MINUTE_IN_SECONDS ) ),
                'interval_label' => metis_settings_format_interval( (int) ( $task_config['interval'] ?? 300 ) ),
                'last_status' => (string) ( $task_state['last_status'] ?? 'never' ),
                'last_finished_at' => (string) ( $task_state['last_finished_at'] ?? '' ),
                'last_error' => (string) ( $task_state['last_error'] ?? '' ),
            ];
        }
        $system_cron_configured = $system_cron_secret_masked !== '';

        $stripe_connected   = is_string( $stripe_secret ) && str_starts_with( $stripe_secret, 'sk_' );
        $webhook_configured = is_string( $webhook_secret ) && str_starts_with( $webhook_secret, 'whsec_' );
        $workspace_configured = is_string( $workspace_impersonation_admin ) && is_email( $workspace_impersonation_admin )
            && is_string( $workspace_service_account_json ) && trim( $workspace_service_account_json ) !== '';

        $workspace_shared_drive_options = [];
        $workspace_shared_drive_error = '';
        if ( $is_system_admin && $workspace_configured && function_exists( 'metis_drive_workspace_settings' ) && function_exists( 'metis_drive_list_shared_drives' ) ) {
            $drive_cfg = function_exists( 'metis_drive_workspace_base_settings' ) ? metis_drive_workspace_base_settings() : metis_drive_workspace_settings();
            if ( ! empty( $drive_cfg['ok'] ) ) {
                $drive_list = metis_drive_list_shared_drives( $drive_cfg );
                if ( ! empty( $drive_list['ok'] ) ) {
                    $workspace_shared_drive_options = (array) ( $drive_list['drives'] ?? [] );
                } else {
                    $workspace_shared_drive_error = (string) ( $drive_list['error'] ?? 'Unable to load shared drives.' );
                }
            } else {
                $workspace_shared_drive_error = (string) ( $drive_cfg['error'] ?? 'Drive workspace config is incomplete.' );
            }
        }

        $workspace_calendar_options = [];
        $workspace_calendar_error = '';
        if ( $is_system_admin && $workspace_configured && function_exists( 'metis_calendar_workspace_base_settings' ) && function_exists( 'metis_calendar_list_calendars' ) ) {
            $calendar_cfg = metis_calendar_workspace_base_settings();
            if ( ! empty( $calendar_cfg['ok'] ) ) {
                $calendar_list = metis_calendar_list_calendars( $calendar_cfg );
                if ( ! empty( $calendar_list['ok'] ) ) {
                    $workspace_calendar_options = (array) ( $calendar_list['calendars'] ?? [] );
                } else {
                    $workspace_calendar_error = (string) ( $calendar_list['error'] ?? 'Unable to load calendars.' );
                }
            } else {
                $workspace_calendar_error = (string) ( $calendar_cfg['error'] ?? 'Calendar workspace config is incomplete.' );
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

        $redirect_url = '';

        return compact(
            'section',
            'saved',
            'errors',
            'redirect_url',
            'is_system_admin',
            'portal_name',
            'org_name',
            'portal_logo',
            'portal_logo_src',
            'portal_favicon',
            'portal_favicon_src',
            'theme_color_fields',
            'theme_colors',
            'logging_min_level',
            'logging_force_url_token',
            'menu_modules',
            'workspace_impersonation_admin',
            'workspace_customer_id',
            'workspace_domain',
            'workspace_shared_drive_id',
            'workspace_default_calendar_id',
            'workspace_drive_configs',
            'workspace_calendar_configs',
            'workspace_service_account_json',
            'workspace_service_account_present',
            'carddav_tokens',
            'carddav_token_notice',
            'carddav_username',
            'carddav_endpoint',
            'workspace_stripe_sso_schema',
            'workspace_stripe_sso_field',
            'workspace_stripe_access_group_email',
            'newsletter_default_from_name',
            'newsletter_default_from_email',
            'newsletter_default_reply_to',
            'newsletter_google_daily_limit',
            'newsletter_klipy_api_key',
            'newsletter_klipy_search_url',
            'profile_allow_name_edit',
            'system_cron_secret_masked',
            'system_cron_endpoint',
            'system_cron_header',
            'system_cron_task_rows',
            'system_cron_configured',
            'integrity_baseline_status',
            'stripe_secret',
            'webhook_secret',
            'stripe_connected',
            'webhook_configured',
            'workspace_configured',
            'workspace_shared_drive_options',
            'workspace_shared_drive_error',
            'workspace_calendar_options',
            'workspace_calendar_error'
        ) + [ 'allowed' => true ];
    }
}
