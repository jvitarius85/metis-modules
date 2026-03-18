<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

require_once __DIR__ . '/Runtime/SidebarLayout.php';
require_once __DIR__ . '/Runtime/SidebarModuleLayout.php';

/**
 * Metis Core Helpers
 *
 * Global utility functions available to all modules.
 *
 * Code prefixes in use:
 *   DP  Deposits
 *   MW  Donor IDs
 *   TR  Transactions
 *   CP  Campaigns
 *   CT  Contacts
 *   NL  Newsletter
 *   BT  Batches
 */

// -------------------------------------------------------------------------
// metis_generate_code()
//
// Generates a unique prefixed alphanumeric code.
//
// Usage:
//   metis_generate_code( 'DP' )               → DP87D83K  (no uniqueness check)
//   metis_generate_code( 'TR', $table, 'tid') → TR4J9X2M  (guaranteed unique in DB)
//
// Parameters:
//   $prefix       2–4 character uppercase prefix (e.g. 'DP', 'TR')
//   $table        Optional. Fully-qualified table name to check for collisions.
//   $column       Optional. Column name to check against (default: 'id').
//   $length       Total code length including prefix (default: 8).
//   $max_attempts Max collision retries before throwing (default: 20).
// -------------------------------------------------------------------------

function metis_generate_code(
    string $prefix,
    string $table   = '',
    string $column  = 'id',
    int    $length  = 8,
    int    $max_attempts = 20
): string {

    $prefix  = strtoupper( $prefix );

    if ( class_exists( '\Metis\Core\EntityCatalog' ) && function_exists( 'metis_entity_id_service' ) ) {
        $definition = \Metis\Core\EntityCatalog::definitionForLegacyCode( $prefix, $table, $column );
        if ( is_array( $definition ) ) {
            return metis_entity_id_service()->generate( (string) ( $definition['entity_type'] ?? '' ) );
        }
    }

    $chars   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid confusion
    $suffix_len = $length - strlen( $prefix );

    if ( $suffix_len < 1 ) {
        throw new InvalidArgumentException( "metis_generate_code: length ({$length}) must exceed prefix length." );
    }

    $attempts = 0;

    do {
        $suffix = '';
        for ( $i = 0; $i < $suffix_len; $i++ ) {
            $suffix .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
        }
        $code = $prefix . $suffix;
        $attempts++;

        // No table provided — return immediately without checking
        if ( $table === '' ) {
            return $code;
        }

        // Check for collision
        $exists = (int) metis_db()->scalar(
            "SELECT COUNT(1) FROM `{$table}` WHERE `{$column}` = %s",
            [ $code ]
        );

    } while ( $exists > 0 && $attempts < $max_attempts );

    if ( $attempts >= $max_attempts && $exists > 0 ) {
        Metis_Logger::error( 'metis_generate_code: max collision attempts reached', [
            'prefix' => $prefix,
            'table'  => $table,
            'column' => $column,
        ] );
        throw new RuntimeException( "metis_generate_code: could not generate unique code for prefix '{$prefix}' after {$max_attempts} attempts." );
    }

    return $code;
}

// -------------------------------------------------------------------------
// metis_breadcrumb()
//
// Renders a breadcrumb nav. Pass an array of [ 'label' => '', 'url' => '' ]
// The last item is the current page and should omit 'url'.
//
// Usage:
//   metis_breadcrumb([
//       [ 'label' => 'Donations', 'url' => '/mw-portal/donations/' ],
//       [ 'label' => 'Deposits',  'url' => '/mw-portal/donations/deposits/' ],
//       [ 'label' => 'DP4FFULS' ],
//   ]);
// -------------------------------------------------------------------------
// -------------------------------------------------------------------------
// metis_set_page_title()
//
// Called by detail-page templates to register the record label that the
// manager will append as the final breadcrumb segment.
//
// Usage (in a template, before or after content — order doesn't matter):
//   metis_set_page_title( 'JD Vitarius' );
//   metis_set_page_title( $deposit->provider_ref );
// -------------------------------------------------------------------------
function metis_set_page_title( string $label ): void {
    $GLOBALS['_metis_page_title'] = $label;
}

function metis_get_page_title(): string {
    return $GLOBALS['_metis_page_title'] ?? '';
}

function metis_breadcrumb( array $items ): void {
    if ( empty( $items ) ) return;
    echo '<nav class="mw-breadcrumb" aria-label="Breadcrumb">';
    foreach ( $items as $i => $item ) {
        $label   = esc_html( $item['label'] ?? '' );
        $url     = esc_url( $item['url'] ?? '' );
        $is_last = ( $i === count( $items ) - 1 );
        if ( $i > 0 ) {
            echo '<span class="mw-breadcrumb-sep" aria-hidden="true">›</span>';
        }
        if ( ! $is_last && $url ) {
            echo '<a href="' . $url . '">' . $label . '</a>';
        } else {
            echo '<span class="mw-breadcrumb-current" aria-current="page">' . $label . '</span>';
        }
    }
    echo '</nav>';
}


function metis_default_module_order(): array {
    return [ 'portal', 'donations', 'finance', 'contacts', 'newsletter', 'board', 'drive', 'calendar', 'website', 'settings', 'people' ];
}

function metis_module_label( string|array $module, string $fallback_slug = '' ): string {
    $config = [];
    $slug = $fallback_slug;

    if ( is_string( $module ) ) {
        $slug = sanitize_key( $module );
        $resolved = function_exists( 'metis_get_module' ) ? metis_get_module( $slug ) : null;
        if ( is_array( $resolved ) ) {
            $config = (array) ( $resolved['config'] ?? [] );
            $slug = (string) ( $resolved['slug'] ?? $slug );
        }
    } elseif ( is_array( $module ) ) {
        $config = isset( $module['config'] ) && is_array( $module['config'] ) ? (array) $module['config'] : $module;
        $slug = sanitize_key( (string) ( $module['slug'] ?? $fallback_slug ) );
    }

    $label = trim( (string) ( $config['label'] ?? '' ) );
    if ( $label !== '' ) {
        return $label;
    }

    $name = trim( (string) ( $config['name'] ?? '' ) );
    if ( $name !== '' ) {
        return $name;
    }

    return $slug !== '' ? ucwords( str_replace( '_', ' ', $slug ) ) : '';
}

function metis_module_icon( array $module, string $fallback_icon = '' ): string {
    $config = (array) ( $module['config'] ?? [] );
    $icon = trim( (string) ( $config['icon'] ?? '' ) );

    if ( $icon !== '' ) {
        return $icon;
    }

    return $fallback_icon;
}

function metis_current_module(): ?array {
    $slug = sanitize_key( (string) metis_get_query_var( 'metis_domain' ) );
    if ( $slug === '' || ! function_exists( 'metis_get_module' ) ) {
        return null;
    }

    $module = metis_get_module( $slug );
    return is_array( $module ) ? $module : null;
}

function metis_current_module_label( string $fallback = '' ): string {
    $module = metis_current_module();
    if ( is_array( $module ) ) {
        return metis_module_label( $module, (string) ( $module['slug'] ?? '' ) );
    }

    return $fallback;
}

function metis_current_view_label( string $fallback = '' ): string {
    $module = metis_current_module();
    $view = sanitize_key( (string) metis_get_query_var( 'metis_view' ) );

    if ( ! is_array( $module ) || $view === '' ) {
        return $fallback;
    }

    $config = (array) ( $module['config'] ?? [] );
    $menu_items = (array) ( $config['menu']['items'] ?? [] );
    $label = trim( (string) ( $menu_items[ $view ] ?? '' ) );
    if ( $label !== '' ) {
        return $label;
    }

    return $fallback !== '' ? $fallback : ucwords( str_replace( '_', ' ', $view ) );
}

function metis_sidebar_nav( array $modules, string $active_domain ): string {
    if ( empty( $modules ) ) {
        return '';
    }

    $ordered = function_exists( 'metis_order_modules_for_navigation' )
        ? metis_order_modules_for_navigation( $modules )
        : $modules;

    $html = '';
    $fallback_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>';

    $active_view = sanitize_key( (string) metis_get_query_var( 'metis_view' ) );
    foreach ( $ordered as $slug => $mod ) {
        if ( $slug === 'profile' ) {
            continue;
        }

        $cfg = $mod['config'] ?? [];
        $label = function_exists( 'metis_module_label' )
            ? metis_module_label( $mod, (string) $slug )
            : ( $cfg['label'] ?? ucfirst( (string) $slug ) );
        $menu = $cfg['menu'] ?? [ 'type' => 'single' ];
        $type = $menu['type'] ?? 'single';
        $items = $menu['items'] ?? [];
        $is_active = ( $slug === $active_domain );
        $icon = function_exists( 'metis_module_icon' )
            ? metis_module_icon( $mod, $fallback_icon )
            : ( $cfg['icon'] ?? $fallback_icon );
        $url = esc_url( metis_portal_url( (string) $slug ) );
        $active_cls = $is_active ? ' is-active' : '';
        $icon_class = str_contains( (string) $icon, '<img' ) ? ' mw-sidebar-icon-image' : '';
        $default_view = sanitize_key( (string) ( $cfg['default_view'] ?? 'dashboard' ) );
        $is_root_active = $is_active && ( $active_view === '' || $active_view === $default_view );
        $aria_current = $is_root_active ? ' aria-current="page"' : '';

        if ( $type === 'single' ) {
            $html .= '<a href="' . $url . '" class="mw-sidebar-item' . $active_cls . '" data-tooltip="' . esc_attr( $label ) . '" data-help="' . esc_attr( $slug . '.dashboard' ) . '" aria-label="' . esc_attr( $label ) . '"' . $aria_current . '>'
                . '<span class="mw-sidebar-icon' . $icon_class . '">' . $icon . '</span>'
                . '<span class="mw-sidebar-label">' . esc_html( $label ) . '</span>'
                . '</a>';
            continue;
        }

        if ( $type === 'dropdown' && ! empty( $items ) ) {
            $submenu_id = 'mw-sidebar-submenu-' . sanitize_key( (string) $slug );
            $html .= '<div class="mw-sidebar-group' . $active_cls . '">';
            $html .= '<a href="' . $url . '" class="mw-sidebar-item mw-sidebar-group-link' . $active_cls . '" data-tooltip="' . esc_attr( $label ) . '" data-help="' . esc_attr( $slug . '.dashboard' ) . '" aria-label="' . esc_attr( $label ) . '"' . $aria_current . '>'
                . '<span class="mw-sidebar-icon' . $icon_class . '">' . $icon . '</span>'
                . '<span class="mw-sidebar-label">' . esc_html( $label ) . '</span>'
                . '</a>';
            $html .= '<div class="mw-sidebar-submenu" id="' . esc_attr( $submenu_id ) . '" aria-label="' . esc_attr( $label . ' navigation' ) . '">';
            $html .= '<div class="mw-sidebar-submenu-title">' . esc_html( $label ) . '</div>';
            if ( ! isset( $items[ $default_view ] ) ) {
                $html .= '<a class="mw-sidebar-subitem' . ( $is_root_active ? ' is-active' : '' ) . '" data-help="' . esc_attr( $slug . '.dashboard' ) . '" href="' . $url . '"' . $aria_current . '>Overview</a>';
            }
            foreach ( $items as $view => $view_label ) {
                $view_key = sanitize_key( (string) $view );
                $is_subitem_active = $is_active && ( $view_key === $active_view || ( $active_view === '' && $view_key === $default_view ) );
                $view_url = $view_key === $default_view ? $url : esc_url( metis_portal_url( (string) $slug, $view_key ) );
                $html .= '<a class="mw-sidebar-subitem' . ( $is_subitem_active ? ' is-active' : '' ) . '" data-help="' . esc_attr( $slug . '.' . $view_key ) . '" href="' . $view_url . '"' . ( $is_subitem_active ? ' aria-current="page"' : '' ) . '>'
                    . esc_html( (string) $view_label ) . '</a>';
            }
            $html .= '</div>';
            $html .= '</div>';
        }
    }

    return $html;
}

function metis_nav_build( array $modules, string $active_domain ): string {
    return metis_sidebar_nav( $modules, $active_domain );
}

function metis_current_module_view_title( string $fallback = '' ): string {
    $module_label = metis_current_module_label();
    $view = sanitize_key( (string) metis_get_query_var( 'metis_view' ) );
    $default_view = '';
    $module = metis_current_module();

    if ( is_array( $module ) ) {
        $default_view = sanitize_key( (string) ( $module['config']['default_view'] ?? 'dashboard' ) );
    }

    if ( $module_label === '' ) {
        return $fallback !== '' ? $fallback : metis_current_view_label( '' );
    }

    if ( $view === '' || $view === 'dashboard' || ( $default_view !== '' && $view === $default_view ) ) {
        return $module_label;
    }

    $view_label = metis_current_view_label( '' );
    if ( $view_label === '' ) {
        return $fallback !== '' ? $fallback : $module_label;
    }

    return $module_label . ' ' . $view_label;
}

function metis_order_modules_for_navigation( array $modules ): array {
    if ( empty( $modules ) ) {
        return [];
    }

    $default_order = metis_default_module_order();
    $saved_order   = Core_Settings_Service::get( 'menu_module_order', [] );
    $merged_order  = [];

    if ( is_array( $saved_order ) ) {
        foreach ( $saved_order as $slug ) {
            $slug = sanitize_key( (string) $slug );
            if ( $slug !== '' && isset( $modules[ $slug ] ) && ! in_array( $slug, $merged_order, true ) ) {
                $merged_order[] = $slug;
            }
        }
    }

    foreach ( $default_order as $slug ) {
        if ( isset( $modules[ $slug ] ) && ! in_array( $slug, $merged_order, true ) ) {
            $merged_order[] = $slug;
        }
    }

    foreach ( array_keys( $modules ) as $slug ) {
        if ( ! in_array( $slug, $merged_order, true ) ) {
            $merged_order[] = $slug;
        }
    }

    $ordered = [];
    foreach ( $merged_order as $slug ) {
        if ( isset( $modules[ $slug ] ) ) {
            $ordered[ $slug ] = $modules[ $slug ];
        }
    }

    return $ordered;
}


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

function metis_portal_logo_asset(): array {
    $logo = Core_Settings_Service::get( 'portal_logo', [] );
    return is_array( $logo ) ? $logo : [];
}

function metis_portal_logo_url(): string {
    return metis_settings_asset_src( metis_portal_logo_asset() );
}

function metis_portal_favicon_asset(): array {
    $favicon = Core_Settings_Service::get( 'portal_favicon', [] );
    return is_array( $favicon ) ? $favicon : [];
}

function metis_portal_favicon_url(): string {
    return metis_settings_asset_src( metis_portal_favicon_asset() );
}

function metis_sync_state_ensure_schema(): void {
    static $done = false;
    if ( $done ) {
        return;
    }

    $table   = Metis_Tables::get( 'sync_state' );
    $connection = metis_db()->connection();
    $charset = method_exists( $connection, 'get_charset_collate' ) ? (string) $connection->get_charset_collate() : '';

    metis_db_delta(
        "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service VARCHAR(191) NOT NULL,
            last_sync DATETIME DEFAULT NULL,
            sync_token LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY service (service),
            KEY last_sync (last_sync)
        ) {$charset};"
    );

    $done = true;
}

function metis_sync_state_get( string $service ): array {
    metis_sync_state_ensure_schema();

    $service = trim( $service );
    if ( $service === '' ) {
        return [];
    }

    $table = Metis_Tables::get( 'sync_state' );
    $row   = metis_db()->fetchOne(
        "SELECT * FROM {$table} WHERE service = %s LIMIT 1",
        [ $service ]
    );

    return is_array( $row ) ? $row : [];
}

function metis_sync_state_update( string $service, array $data ): void {
    metis_sync_state_ensure_schema();

    $service = trim( $service );
    if ( $service === '' ) {
        return;
    }

    $table    = Metis_Tables::get( 'sync_state' );
    $existing = metis_sync_state_get( $service );
    $payload = [
        'service'    => $service,
        'last_sync'  => array_key_exists( 'last_sync', $data ) ? ( $data['last_sync'] ?: null ) : ( $existing['last_sync'] ?? null ),
        'sync_token' => array_key_exists( 'sync_token', $data ) ? (string) ( $data['sync_token'] ?? '' ) : (string) ( $existing['sync_token'] ?? '' ),
        'updated_at' => metis_current_time( 'mysql' ),
    ];

    if ( ! empty( $existing['id'] ) ) {
        metis_db()->update(
            $table,
            $payload,
            [ 'id' => (int) $existing['id'] ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        return;
    }

    metis_db()->insert( $table, $payload, [ '%s', '%s', '%s', '%s' ] );
}

function metis_avatar_fallback_url( string $email = '', int $size = 160 ): string {
    return \Metis\Core\AvatarService::fallbackDataUri( $email !== '' ? $email : 'Metis User', $size );
}

function metis_avatar_url( string $name = '', string $avatar_url = '', int $size = 96, string $avatar_key = '' ): string {
    return \Metis\Core\AvatarService::resolveUrl( $name, $avatar_url, $size, $avatar_key );
}

function metis_avatar_payload( string $name = '', string $avatar_url = '', int $size = 96, string $avatar_key = '' ): array {
    return \Metis\Core\AvatarService::payload( $name, $avatar_url, $size, $avatar_key );
}

function metis_avatar_directory( string $avatar_key ): string {
    return \Metis\Core\AvatarService::avatarStorageDir( $avatar_key );
}

function metis_avatar_file_path( string $avatar_key ): string {
    return \Metis\Core\AvatarService::avatarStoragePath( $avatar_key );
}

function metis_avatar_public_url( string $avatar_key, ?int $version = null ): string {
    return \Metis\Core\AvatarService::avatarPublicUrl( $avatar_key, $version );
}

function metis_avatar_stored_url( string $avatar_key, bool $cache_bust = true ): string {
    return \Metis\Core\AvatarService::storedAvatarUrl( $avatar_key, $cache_bust );
}

function metis_avatar_decode_base64_payload( string $base64 ): array {
    if ( $base64 === '' ) {
        return [ 'ok' => false, 'error' => 'Image data is required.' ];
    }

    if ( ! preg_match( '/^data:image\/(png|jpeg);base64,/', $base64 ) ) {
        return [ 'ok' => false, 'error' => 'Invalid image format.' ];
    }

    $raw = preg_replace( '/^data:image\/(png|jpeg);base64,/', '', $base64 );
    $bin = base64_decode( (string) $raw, true );
    if ( $bin === false || strlen( $bin ) < 100 ) {
        return [ 'ok' => false, 'error' => 'Invalid image payload.' ];
    }

    return [ 'ok' => true, 'binary' => $bin ];
}

function metis_avatar_store_cropped_image( string $avatar_key, string $image_bits ): array {
    $avatar_key = \Metis\Core\AvatarService::storageKey( $avatar_key );
    if ( $avatar_key === '' ) {
        return [ 'ok' => false, 'error' => 'Invalid avatar key.' ];
    }

    $detected_mime = function_exists( 'metis_detect_binary_mime_type' ) ? metis_detect_binary_mime_type( $image_bits ) : '';
    $validation = ( new \Metis\Core\Services\UploadPolicyService() )->validateBinary(
        $avatar_key . '.jpg',
        $detected_mime,
        strlen( $image_bits ),
        [ 'policy' => 'avatars' ]
    );
    if ( empty( $validation['ok'] ) ) {
        return [ 'ok' => false, 'error' => (string) ( $validation['error'] ?? 'Avatar upload is not allowed.' ) ];
    }

    if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
        return [ 'ok' => false, 'error' => 'Avatar processing is not available on this server.' ];
    }

    $source = @imagecreatefromstring( $image_bits );
    if ( ! is_resource( $source ) && ! ( $source instanceof \GdImage ) ) {
        return [ 'ok' => false, 'error' => 'Uploaded avatar could not be decoded.' ];
    }

    $target_dir = metis_avatar_directory( $avatar_key );
    if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0755, true ) && ! is_dir( $target_dir ) ) {
        if ( is_resource( $source ) || $source instanceof \GdImage ) {
            imagedestroy( $source );
        }
        return [ 'ok' => false, 'error' => 'Avatar directory could not be created.' ];
    }
    @chmod( $target_dir, 0755 );

    $target_path = metis_avatar_file_path( $avatar_key );
    foreach ( glob( $target_dir . '/avatar.*' ) ?: [] as $existing ) {
        if ( is_string( $existing ) && $existing !== $target_path && is_file( $existing ) ) {
            @unlink( $existing );
        }
    }

    $target = imagecreatetruecolor( 256, 256 );
    if ( ! $target ) {
        if ( is_resource( $source ) || $source instanceof \GdImage ) {
            imagedestroy( $source );
        }
        return [ 'ok' => false, 'error' => 'Avatar canvas could not be created.' ];
    }

    imagealphablending( $target, true );
    imagesavealpha( $target, false );
    $white = imagecolorallocate( $target, 255, 255, 255 );
    imagefill( $target, 0, 0, $white );

    $width = (int) imagesx( $source );
    $height = (int) imagesy( $source );
    if ( $width < 1 || $height < 1 ) {
        imagedestroy( $source );
        imagedestroy( $target );
        return [ 'ok' => false, 'error' => 'Avatar dimensions are invalid.' ];
    }

    imagecopyresampled( $target, $source, 0, 0, 0, 0, 256, 256, $width, $height );
    $saved = imagejpeg( $target, $target_path, 80 );

    imagedestroy( $source );
    imagedestroy( $target );

    if ( ! $saved ) {
        return [ 'ok' => false, 'error' => 'Avatar could not be saved.' ];
    }

    @chmod( $target_path, 0644 );

    return [
        'ok' => true,
        'path' => $target_path,
        'url' => metis_avatar_stored_url( $avatar_key, true ),
    ];
}

function metis_workspace_service_account_payload(): array {
    if ( ! class_exists( 'Core_Settings_Service' ) ) {
        return [];
    }

    $stored = Core_Settings_Service::get( 'workspace_service_account_json', '' );
    $service = [];

    if ( is_array( $stored ) ) {
        $service = $stored;
    } elseif ( is_string( $stored ) && trim( $stored ) !== '' ) {
        $decoded = json_decode( $stored, true );
        if ( is_array( $decoded ) ) {
            $service = $decoded;
        }
    }

    if ( empty( $service ) ) {
        return [];
    }

    $private_key = (string) ( $service['private_key'] ?? '' );
    if ( $private_key !== '' ) {
        $private_key = str_replace( [ '\\r\\n', '\\n', "\r\n", "\r" ], "\n", $private_key );
        $private_key = stripcslashes( $private_key );
        $private_key = str_replace( [ "\r\n", "\r" ], "\n", $private_key );
        $service['private_key'] = trim( $private_key ) . "\n";
    }

    if ( empty( $service['token_uri'] ) ) {
        $service['token_uri'] = 'https://oauth2.googleapis.com/token';
    }

    return $service;
}

function metis_workspace_service_account_error( array $service ): string {
    if ( empty( $service ) ) {
        return 'Workspace service account JSON or impersonation admin is not configured.';
    }

    foreach ( [ 'client_email', 'private_key', 'token_uri' ] as $key ) {
        if ( empty( $service[ $key ] ) ) {
            return 'Invalid Workspace service account JSON in settings.';
        }
    }

    $private_key = (string) $service['private_key'];
    if ( ! str_contains( $private_key, '-----BEGIN PRIVATE KEY-----' ) || ! str_contains( $private_key, '-----END PRIVATE KEY-----' ) ) {
        return 'Workspace service account private key is malformed. Paste the original Google JSON file contents exactly.';
    }

    if ( function_exists( 'openssl_pkey_get_private' ) ) {
        $key = @openssl_pkey_get_private( $private_key );
        if ( $key === false ) {
            return 'Workspace service account private key is invalid.';
        }
        if ( is_object( $key ) || is_resource( $key ) ) {
            @openssl_free_key( $key );
        }
    }

    return '';
}
