<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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
        global $wpdb;
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(1) FROM `{$table}` WHERE `{$column}` = %s", $code )
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

    // Auto-register in the universal code registry so it is immediately resolvable.
    // internal_id and resolve_url are unknown at generation time; callers should
    // call Metis_Code_Registry::update_url() or ::register() again once they have
    // the insert_id and can construct a URL.
    if ( $table !== '' && class_exists( 'Metis_Code_Registry' ) ) {
        Metis_Code_Registry::register( $code );
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

    global $wpdb;
    $table   = Metis_Tables::get( 'sync_state' );
    $charset = $wpdb->get_charset_collate();

    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta(
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
    global $wpdb;

    $service = trim( $service );
    if ( $service === '' ) {
        return [];
    }

    $table = Metis_Tables::get( 'sync_state' );
    $row   = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE service = %s LIMIT 1", $service ),
        ARRAY_A
    );

    return is_array( $row ) ? $row : [];
}

function metis_sync_state_update( string $service, array $data ): void {
    metis_sync_state_ensure_schema();
    global $wpdb;

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
        'updated_at' => current_time( 'mysql' ),
    ];

    if ( ! empty( $existing['id'] ) ) {
        $wpdb->update(
            $table,
            $payload,
            [ 'id' => (int) $existing['id'] ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        return;
    }

    $wpdb->insert( $table, $payload, [ '%s', '%s', '%s', '%s' ] );
}

function metis_avatar_fallback_url( string $email = '', int $size = 160 ): string {
    $email = strtolower( trim( $email ) );
    $hash  = md5( $email );
    return 'https://www.gravatar.com/avatar/' . $hash . '?s=' . max( 16, $size ) . '&d=mp';
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
