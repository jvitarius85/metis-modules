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
// Generates a unique prefixed code in PREFIX-###### format.
//
// Usage:
//   metis_generate_code( 'DP' )               → DP-482913  (no uniqueness check)
//   metis_generate_code( 'TR', $table, 'tid') → TR-053201  (guaranteed unique in DB)
//
// Parameters:
//   $prefix       2–4 character uppercase prefix (e.g. 'DP', 'TR')
//   $table        Optional. Fully-qualified table name to check for collisions.
//   $column       Optional. Column name to check against (default: 'id').
//   $length       Legacy parameter retained for compatibility.
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

    if ( $prefix === '' ) {
        throw new InvalidArgumentException( 'metis_generate_code: prefix is required.' );
    }

    $attempts = 0;

    do {
        $code = $prefix . '-' . str_pad( (string) random_int( 1, 999999 ), 6, '0', STR_PAD_LEFT );
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
//       [ 'label' => 'Donations', 'url' => '/metis-portal/donations/' ],
//       [ 'label' => 'Deposits',  'url' => '/metis-portal/donations/deposits/' ],
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
    echo '<nav class="metis-breadcrumb" aria-label="Breadcrumb">';
    foreach ( $items as $i => $item ) {
        $label   = metis_escape_html( $item['label'] ?? '' );
        $url     = metis_escape_url( $item['url'] ?? '' );
        $is_last = ( $i === count( $items ) - 1 );
        if ( $i > 0 ) {
            echo '<span class="metis-breadcrumb-sep" aria-hidden="true">›</span>';
        }
        if ( ! $is_last && $url ) {
            echo '<a href="' . $url . '">' . $label . '</a>';
        } else {
            echo '<span class="metis-breadcrumb-current" aria-current="page">' . $label . '</span>';
        }
    }
    echo '</nav>';
}


function metis_default_module_order(): array {
    if ( function_exists( 'metis_navigation_service' ) ) {
        $order = [];
        foreach ( metis_navigation_service()->visibleTree() as $item ) {
            $module_key = metis_key_clean( (string) ( $item['module_key'] ?? '' ) );
            if ( $module_key !== '' && ! str_contains( $module_key, ':' ) ) {
                $order[] = $module_key;
            }
            foreach ( (array) ( $item['children'] ?? [] ) as $child ) {
                $child_key = metis_key_clean( (string) ( $child['module_key'] ?? '' ) );
                if ( $child_key !== '' && ! str_contains( $child_key, ':' ) ) {
                    $order[] = $child_key;
                }
            }
        }
        return $order;
    }

    return [];
}

function metis_module_label( string|array $module, string $fallback_slug = '' ): string {
    $config = [];
    $slug = $fallback_slug;

    if ( is_string( $module ) ) {
        $slug = metis_key_clean( $module );
        $resolved = function_exists( 'metis_get_module' ) ? metis_get_module( $slug ) : null;
        if ( is_array( $resolved ) ) {
            $config = (array) ( $resolved['config'] ?? [] );
            $slug = (string) ( $resolved['slug'] ?? $slug );
        }
    } elseif ( is_array( $module ) ) {
        $config = isset( $module['config'] ) && is_array( $module['config'] ) ? (array) $module['config'] : $module;
        $slug = metis_key_clean( (string) ( $module['slug'] ?? $fallback_slug ) );
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

function metis_navigation_svg_icon_directory(): string {
    static $directory = null;
    if ( is_string( $directory ) ) {
        return $directory;
    }

    $candidates = [
        METIS_ASSETS_PATH . 'Images/icons',
    ];

    foreach ( $candidates as $candidate ) {
        if ( is_dir( $candidate ) ) {
            $directory = $candidate;
            return $directory;
        }
    }

    $directory = '';
    return $directory;
}

function metis_navigation_svg_icon_path( string $slug ): string {
    static $index = null;
    if ( ! is_array( $index ) ) {
        $index = [];
        $directory = metis_navigation_svg_icon_directory();
        if ( $directory !== '' ) {
            $entries = @scandir( $directory );
            if ( is_array( $entries ) ) {
                foreach ( $entries as $entry ) {
                    if ( ! is_string( $entry ) || ! preg_match( '/^[a-z0-9][a-z0-9-]*\.svg$/', $entry ) ) {
                        continue;
                    }

                    $name = strtolower( (string) pathinfo( $entry, PATHINFO_FILENAME ) );
                    $path = $directory . '/' . $entry;
                    if ( is_file( $path ) ) {
                        $index[ $name ] = $path;
                    }
                }
                ksort( $index );
            }
        }
    }

    $lookup = str_replace( '_', '-', metis_key_clean( $slug ) );
    if ( $lookup === '' ) {
        return '';
    }

    return isset( $index[ $lookup ] ) ? (string) $index[ $lookup ] : '';
}

function metis_navigation_svg_icon_keys(): array {
    static $keys = null;
    if ( is_array( $keys ) ) {
        return $keys;
    }

    $keys = [];
    $directory = metis_navigation_svg_icon_directory();
    if ( $directory === '' ) {
        return $keys;
    }

    $entries = @scandir( $directory );
    if ( ! is_array( $entries ) ) {
        return $keys;
    }

    foreach ( $entries as $entry ) {
        if ( ! is_string( $entry ) || ! preg_match( '/^[a-z0-9][a-z0-9-]*\.svg$/', $entry ) ) {
            continue;
        }
        $keys[] = strtolower( (string) pathinfo( $entry, PATHINFO_FILENAME ) );
    }

    $keys = array_values( array_unique( $keys ) );
    sort( $keys, SORT_STRING );
    return $keys;
}

function metis_navigation_svg_icon_url( string $slug ): string {
    $lookup = str_replace( '_', '-', metis_key_clean( $slug ) );
    if ( $lookup === '' ) {
        return '';
    }

    if ( metis_navigation_svg_icon_path( $lookup ) === '' ) {
        return '';
    }

    return metis_home_url( 'svg/' . rawurlencode( $lookup ) . '/' );
}

function metis_navigation_sanitize_svg_markup( string $svg ): string {
    $sanitized = $svg;
    if ( trim( $sanitized ) === '' ) {
        return '';
    }

    $patterns = [
        '/<g\b[^>]*\bid=["\'][^"\']*transparent(?:[_\-\s]?rectangle)?[^"\']*["\'][^>]*>.*?<\/g>/is',
        '/<(?:path|rect|polygon|polyline|line|circle|ellipse)\b[^>]*\bid=["\'][^"\']*transparent(?:[_\-\s]?rectangle)?[^"\']*["\'][^>]*\/?>/is',
    ];

    foreach ( $patterns as $pattern ) {
        $result = preg_replace( $pattern, '', $sanitized );
        if ( is_string( $result ) ) {
            $sanitized = $result;
        }
    }

    return trim( $sanitized );
}

function metis_navigation_svg_icon_markup( string $slug ): string {
    static $cache = [];
    $lookup = str_replace( '_', '-', metis_key_clean( $slug ) );
    if ( $lookup === '' ) {
        return '';
    }

    if ( array_key_exists( $lookup, $cache ) ) {
        return (string) $cache[ $lookup ];
    }

    $path = metis_navigation_svg_icon_path( $lookup );
    if ( $path === '' ) {
        $cache[ $lookup ] = '';
        return '';
    }

    $svg = file_get_contents( $path );
    if ( ! is_string( $svg ) || trim( $svg ) === '' ) {
        $cache[ $lookup ] = '';
        return '';
    }

    $svg = metis_navigation_sanitize_svg_markup( $svg );
    if ( $svg === '' ) {
        $cache[ $lookup ] = '';
        return '';
    }

    $cache[ $lookup ] = $svg;
    return $svg;
}

function metis_navigation_icon_alias_map(): array {
    static $aliases = null;
    if ( is_array( $aliases ) ) {
        return $aliases;
    }

    $aliases = [
        'grid' => 'apps',
        'calendar' => 'event-schedule',
        'contacts' => 'user-multiple',
        'donation' => 'currency-dollar',
        'forms' => 'list-checked',
        'newsletter' => 'email',
        'website' => 'workspace',
        'media' => 'image',
        'import' => 'download',
        'drive' => 'folder',
        'people' => 'user-multiple',
        'board' => 'group-presentation',
        'board-admin' => 'group-presentation',
        'portal' => 'apps',
        'logout' => 'logout',
        'chart-line' => 'chart-line',
        'chart-pie' => 'chart-histogram',
        'chart-bar' => 'chart-column',
        'database' => 'data-base',
        'users-group' => 'group',
        'pencil' => 'pen',
        'logo-facebook' => 'facebook',
        'logo-instagram' => 'instagram',
        'logo-youtube' => 'youtube',
        'check-box' => 'checkbox-checked',
        'disk' => 'save',
        'paper' => 'document',
        'spreadsheet' => 'data-table',
        'plus-circle' => 'add-filled',
        'x-circle' => 'close-filled',
        'clock' => 'time',
        'paperclip' => 'link',
        'transfer' => 'arrows-horizontal',
        'heart' => 'favorite-filled',
        'chat' => 'chat',
        'medical-badge' => 'reminder-medical',
    ];

    return $aliases;
}

function metis_navigation_resolve_icon_slug( string $slug ): string {
    $lookup = str_replace( '_', '-', metis_key_clean( $slug ) );
    if ( $lookup === '' ) {
        return '';
    }

    if ( metis_navigation_svg_icon_path( $lookup ) !== '' ) {
        return $lookup;
    }

    $aliases = metis_navigation_icon_alias_map();
    $alias = $aliases[ $lookup ] ?? '';
    if ( $alias !== '' && metis_navigation_svg_icon_path( $alias ) !== '' ) {
        return $alias;
    }

    return '';
}

function metis_navigation_legacy_svg_icon_key_map(): array {
    static $map = null;
    if ( is_array( $map ) ) {
        return $map;
    }

    $map = [];
    foreach ( metis_navigation_icon_library() as $key => $markup ) {
        $signature = preg_replace( '/\s+/', '', trim( (string) $markup ) );
        if ( is_string( $signature ) && $signature !== '' ) {
            $map[ $signature ] = str_replace( '_', '-', metis_key_clean( (string) $key ) );
        }
    }

    return $map;
}

function metis_navigation_normalize_icon_value( string $icon ): string {
    $raw = trim( $icon );
    if ( $raw === '' ) {
        return '';
    }

    if ( preg_match( '/^icon:([a-z0-9_-]+)$/i', $raw, $matches ) === 1 ) {
        $resolved = metis_navigation_resolve_icon_slug( (string) ( $matches[1] ?? '' ) );
        return $resolved !== '' ? 'icon:' . $resolved : $raw;
    }

    if ( preg_match( '#^/?svg/([a-z0-9_-]+)/?$#i', $raw, $matches ) === 1 ) {
        $resolved = metis_navigation_resolve_icon_slug( (string) ( $matches[1] ?? '' ) );
        return $resolved !== '' ? 'icon:' . $resolved : $raw;
    }

    if ( preg_match( '/^[a-z0-9_-]+$/i', $raw ) === 1 ) {
        $resolved = metis_navigation_resolve_icon_slug( $raw );
        return $resolved !== '' ? 'icon:' . $resolved : $raw;
    }

    if ( str_starts_with( $raw, '<svg' ) ) {
        $signature = preg_replace( '/\s+/', '', $raw );
        if ( is_string( $signature ) && $signature !== '' ) {
            $legacyMap = metis_navigation_legacy_svg_icon_key_map();
            $legacyKey = $legacyMap[ $signature ] ?? '';
            if ( $legacyKey !== '' ) {
                $resolved = metis_navigation_resolve_icon_slug( $legacyKey );
                if ( $resolved !== '' ) {
                    return 'icon:' . $resolved;
                }
            }
        }
    }

    return $raw;
}

function metis_navigation_icon_library(): array {
    static $icons = null;
    if ( is_array( $icons ) ) {
        return $icons;
    }

    $module_icon = static function ( string $slug, string $fallback = '' ): string {
        if ( function_exists( 'metis_get_module' ) ) {
            $module = metis_get_module( $slug );
            $config = is_array( $module ) ? (array) ( $module['config'] ?? [] ) : [];
            $icon = trim( (string) ( $config['icon'] ?? '' ) );
            if ( $icon !== '' ) {
                return $icon;
            }
        }

        return $fallback;
    };

    $icons = [
        'grid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        'contacts' => $module_icon( 'contacts', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="11" r="3"/><path d="M7.5 17a5.5 5.5 0 0 1 9 0"/></svg>' ),
        'donation' => $module_icon( 'donations', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h4l2 4 3-8 2 4h7"/><circle cx="18" cy="6" r="3"/><path d="M17 6h2"/></svg>' ),
        'finance' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19h16"/><path d="M6 15V9M10 15V5M14 15v-3M18 15v-7"/></svg>',
        'forms' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
        'newsletter' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
        'website' => $module_icon( 'website', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg>' ),
        'media' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-4-5 4-2-2-6 4"/></svg>',
        'import' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><rect x="4" y="17" width="16" height="4" rx="1"/></svg>',
        'drive' => $module_icon( 'drive', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 5h8l5 8-4 6H7L3 13z"/><path d="M8 5 3 13M16 5l5 8M7 19l4-6h10"/></svg>' ),
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h.1a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
        'people' => $module_icon( 'people', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 19a6 6 0 0 1 12 0"/><path d="M14 19a4.5 4.5 0 0 1 7 0"/></svg>' ),
        'board' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16v12H4z"/><path d="M8 6V3M16 6V3M8 21v-3M16 21v-3M4 10h16"/></svg>',
        'board_admin' => $module_icon( 'board', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16v12H4z"/><path d="M8 6V3M16 6V3M8 21v-3M16 21v-3M4 10h16"/></svg>' ),
        'portal' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18"/><path d="M12 3v18"/><circle cx="12" cy="12" r="9"/></svg>',
        'cube' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7 12 3 4 7"/><path d="M20 7v10l-8 4-8-4V7"/><path d="M12 21V11"/><path d="M4 7l8 4 8-4"/></svg>',
        'help' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4.3 1.7c-.9.9-1.8 1.4-1.8 2.8"/><circle cx="12" cy="17" r="1"/></svg>',
        'logout' => 'icon:logout',
        'chart_line' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 19h18"/><path d="m6 14 4-4 3 2 5-6"/></svg>',
        'chart_pie' => '<svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M213.333 0c116.643 0 211.421 93.612 213.305 209.805l.028 3.528H213.333V0zM170.664 4.268l.001 43.776c-73.609 18.946-127.998 85.766-127.998 165.289 0 94.257 76.41 170.667 170.666 170.667 79.524 0 146.344-54.389 165.29-127.998l43.776.001c-19.767 97.374-105.857 170.664-209.066 170.664C95.513 426.667 0 331.154 0 213.333 0 110.125 73.29 24.035 170.664 4.268z"/></svg>',
        'chart_bar' => '<svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M2 1c.513 0 .936.386.993.883L3 2v11h11c.552 0 1 .448 1 1 0 .513-.386.936-.883.993L14 15H3c-1.054 0-1.918-.816-1.995-1.851L1 13V2c0-.552.448-1 1-1zm4 6c.513 0 .936.386.993.883L7 8v2c0 .552-.448 1-1 1-.513 0-.936-.386-.993-.883L5 10V8c0-.552.448-1 1-1zm4-4c.552 0 1 .448 1 1v6c0 .552-.448 1-1 1-.552 0-1-.448-1-1V4c0-.552.448-1 1-1zm4 2c.552 0 1 .448 1 1v4c0 .552-.448 1-1 1-.552 0-1-.448-1-1V6c0-.552.448-1 1-1z"/></svg>',
        'handshake' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="m3 12 3-3a2.2 2.2 0 0 1 3.1 0l2.1 2.1a1.6 1.6 0 0 0 2.3 0L15.6 9a2.2 2.2 0 0 1 3.1 0l2.3 2.3"/><path d="m7.5 14.5 2-2"/><path d="m10.5 15.4 1.8-1.8"/><path d="m13.4 15.1 1.7-1.7"/></svg>',
        'building' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><rect x="6" y="3" width="7" height="18"/><rect x="16" y="8" width="2" height="13"/><path d="M8 7h1M8 11h1M8 15h1M10 7h1M10 11h1M10 15h1"/></svg>',
        'briefcase' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/></svg>',
        'bank' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m2 10 10-5 10 5"/><path d="M4 10v8m4-8v8m4-8v8m4-8v8m4-8v8"/><path d="M2 20h20"/></svg>',
        'shield_check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>',
        'server' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/></svg>',
        'database' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>',
        'users_group' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="8" cy="8" r="2.5"/><circle cx="16" cy="8" r="2.5"/><path d="M3.5 18a5 5 0 0 1 9 0"/><path d="M11.5 18a5 5 0 0 1 9 0"/></svg>',
        'pencil' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 21 3.8-1 10-10a2.1 2.1 0 0 0-3-3l-10 10z"/><path d="m13.6 6.4 4 4"/></svg>',
        'check_box' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="m8 12 3 3 5-6"/></svg>',
        'disk' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h11l3 3v13H5z"/><path d="M8 4v6h8V4"/><rect x="9" y="14" width="6" height="4" rx="1"/></svg>',
        'paper' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h8l4 4v14H7z"/><path d="M15 3v5h5"/><path d="M9 13h8M9 17h6"/></svg>',
        'spreadsheet' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 9v11M14 9v11"/></svg>',
        'bell' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 1 1 12 0v5l2 2H4l2-2z"/><path d="M10 18a2 2 0 0 0 4 0"/></svg>',
        'plus_circle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>',
        'minus_circle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg>',
        'x_circle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg>',
        'clock' => '<svg viewBox="-2 -2 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M11 9h4a1 1 0 0 1 0 2h-5a1 1 0 0 1-1-1V4a1 1 0 1 1 2 0v5zm-1 11C4.477 20 0 15.523 0 10S4.477 0 10 0s10 4.477 10 10-4.477 10-10 10zm0-2a8 8 0 1 0 0-16 8 8 0 0 0 0 16z"/></svg>',
        'paperclip' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-8.49 8.49a5.5 5.5 0 0 1-7.78-7.78l9.19-9.19a3.5 3.5 0 1 1 4.95 4.95L9.77 17.06a1.5 1.5 0 1 1-2.12-2.12l8.49-8.49"/></svg>',
        'transfer' => '<svg viewBox="0 0 489.2 489.2" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M365.55 485.6c2.4 2.4 5.5 3.6 8.7 3.6s6.3-1.2 8.7-3.6l94.5-94.5c4.8-4.8 4.8-12.5 0-17.3l-94.5-94.5c-4.8-4.8-12.5-4.8-17.3 0s-4.8 12.5 0 17.3l73.6 73.6H20.35c-6.8 0-12.3 5.5-12.3 12.3s5.5 12.3 12.3 12.3h418.8l-73.6 73.5c-4.8 4.8-4.8 12.6-.02 17.3zm-259.3-482-94.5 94.5c-4.8 4.8-4.8 12.5 0 17.3l94.5 94.5c2.4 2.4 5.5 3.6 8.7 3.6s6.3-1.2 8.7-3.6c4.8-4.8 4.8-12.5 0-17.3L50.05 119h418.8c6.8 0 12.3-5.5 12.3-12.3s-5.5-12.3-12.3-12.3H49.95l73.6-73.5c4.8-4.8 4.8-12.5 0-17.3s-12.6-4.8-17.3 0z"/></svg>',
        'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.4s-7.3-4.35-9.24-8.59C1.2 8.4 2.17 5.2 4.9 4.17A5.3 5.3 0 0 1 12 6.01a5.3 5.3 0 0 1 7.1-1.84c2.73 1.03 3.7 4.23 2.14 7.64C19.3 16.05 12 20.4 12 20.4z"/></svg>',
        'chat' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h16v12H8.5l-2 2-2-2H2z"/><path d="M18 11h4v8h-2.5L18 20.5z"/><path d="M6 9h.5M9.5 9h.5M13 9h.5"/></svg>',
        'medical_badge' => '<svg viewBox="-3 0 19 19" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M12.365 10.832a.473.473 0 0 1-.012.106q.012.179.012.37c0 3.239-5.865 5.063-5.865 5.063S.635 14.547.635 11.307q0-.19.012-.37a.473.473 0 0 1-.012-.105V3.643a.476.476 0 0 1 .475-.475h10.78a.476.476 0 0 1 .475.475zm-2.291-2.554a.476.476 0 0 0-.475-.475h-1.89v-1.89a.476.476 0 0 0-.474-.474h-1.47a.476.476 0 0 0-.475.475v1.889H3.401a.476.476 0 0 0-.475.475v1.47a.476.476 0 0 0 .475.474h1.89v1.889a.476.476 0 0 0 .474.475h1.47a.476.476 0 0 0 .475-.475v-1.889h1.889a.476.476 0 0 0 .475-.475z"/></svg>',
    ];

    return $icons;
}

function metis_navigation_icon_markup( string $icon ): string {
    $icon = trim( $icon );
    if ( $icon === '' ) {
        return '';
    }

    $normalized = metis_navigation_normalize_icon_value( $icon );
    if ( str_starts_with( $normalized, 'icon:' ) ) {
        $lookup = metis_key_clean( substr( $normalized, 5 ) );
        $svg_icon = metis_navigation_svg_icon_markup( $lookup );
        if ( $svg_icon !== '' ) {
            return $svg_icon;
        }
    }

    $library = metis_navigation_icon_library();
    $lookup = '';
    if ( str_starts_with( $icon, 'icon:' ) ) {
        $lookup = metis_key_clean( substr( $icon, 5 ) );
    } elseif ( preg_match( '/^[a-z0-9_-]+$/i', $icon ) === 1 ) {
        $lookup = metis_key_clean( $icon );
    }

    if ( $lookup !== '' && isset( $library[ $lookup ] ) ) {
        return (string) $library[ $lookup ];
    }

    if ( $lookup !== '' ) {
        $svg_icon = metis_navigation_svg_icon_markup( $lookup );
        if ( $svg_icon !== '' ) {
            return $svg_icon;
        }
    }

    if ( preg_match( '#^/?svg/([a-z0-9_-]+)/?$#i', $icon, $matches ) === 1 ) {
        $svg_icon = metis_navigation_svg_icon_markup( (string) ( $matches[1] ?? '' ) );
        if ( $svg_icon !== '' ) {
            return $svg_icon;
        }
    }

    return $icon;
}

function metis_current_module(): ?array {
    $slug = metis_key_clean( (string) metis_get_query_var( 'metis_domain' ) );
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
    $view = metis_key_clean( (string) metis_get_query_var( 'metis_view' ) );

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

function metis_sidebar_module_view_allowed( array $config, string $view_slug ): bool {
    $view_slug = metis_key_clean( $view_slug );
    if ( $view_slug === '' ) {
        return false;
    }

    $view_permissions = is_array( $config['view_permissions'] ?? null ) ? (array) $config['view_permissions'] : [];
    $permission = trim( (string) ( $view_permissions[ $view_slug ] ?? '' ) );
    if ( $permission === '' ) {
        return true;
    }

    return function_exists( 'metis_security_user_can' ) && metis_security_user_can( $permission );
}

function metis_sidebar_nav( array $modules, string $active_domain ): string {
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_BUILD_NAV' );
    }

    if ( ! function_exists( 'metis_navigation_service' ) ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_BUILD_NAV_DONE' );
        }
        return '';
    }

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_BUILD_NAV_TREE' );
    }
    $items = metis_navigation_service()->visibleTree();
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_BUILD_NAV_TREE_DONE' );
    }
    if ( empty( $items ) ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_BUILD_NAV_DONE' );
        }
        return '';
    }

    $html = '';
    $fallback_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>';

    foreach ( $items as $item ) {
        $raw_module_key = (string) ( $item['module_key'] ?? '' );
        if ( $raw_module_key === 'system:logout' ) {
            continue;
        }

        $label = trim( (string) ( $item['label'] ?? '' ) );
        if ( $label === '' ) {
            continue;
        }

        $route = trim( (string) ( $item['route'] ?? '' ) );
        $icon = trim( (string) ( $item['icon'] ?? '' ) );
        if ( $icon === '' ) {
            $icon = $fallback_icon;
        }
        $icon = metis_navigation_icon_markup( $icon );

        $module_key = metis_key_clean( (string) ( $item['module_key'] ?? '' ) );
        $is_active = $module_key !== '' && $module_key === $active_domain;
        $active_cls = $is_active ? ' is-active' : '';
        $aria_current = $is_active ? ' aria-current="page"' : '';
        $icon_class = str_contains( (string) $icon, '<img' ) ? ' metis-sidebar-icon-image' : '';
        $children = is_array( $item['children'] ?? null ) ? $item['children'] : [];
        $module_view_children = [];

        if ( $children === [] && $module_key !== '' && ! str_contains( $module_key, ':' ) && function_exists( 'metis_get_module' ) ) {
            $module = metis_get_module( $module_key );
            $config = is_array( $module ) ? (array) ( $module['config'] ?? [] ) : [];
            $menu_items = is_array( $config['menu']['items'] ?? null ) ? (array) $config['menu']['items'] : [];

            // If a module exposes multiple menu views, render them as a flyout submenu.
            if ( count( $menu_items ) > 1 ) {
                foreach ( $menu_items as $view_slug => $view_label ) {
                    $view_slug = metis_key_clean( (string) $view_slug );
                    $view_label = trim( (string) $view_label );
                    if ( $view_slug === '' || $view_label === '' ) {
                        continue;
                    }
                    if ( ! metis_sidebar_module_view_allowed( $config, $view_slug ) ) {
                        continue;
                    }

                    $view_route = function_exists( 'metis_portal_url' ) ? metis_portal_url( $module_key, $view_slug ) : '';
                    if ( $view_route === '' ) {
                        continue;
                    }

                    $module_view_children[] = [
                        'label' => $view_label,
                        'route' => $view_route,
                        'module_key' => $module_key,
                        'view_key' => $view_slug,
                    ];
                }
            }
        }

        if ( $children === [] && $module_view_children === [] ) {
            $url = $route !== '' ? metis_escape_url( $route ) : '#';
            $html .= '<a href="' . $url . '" class="metis-sidebar-item' . $active_cls . '" data-tooltip="' . metis_escape_attr( $label ) . '" aria-label="' . metis_escape_attr( $label ) . '"' . $aria_current . '>'
                . '<span class="metis-sidebar-icon' . $icon_class . '">' . $icon . '</span>'
                . '<span class="metis-sidebar-label">' . metis_escape_html( $label ) . '</span>'
                . '</a>';
            continue;
        }

        if ( $children === [] && $module_view_children !== [] ) {
            $submenu_id = 'metis-sidebar-submenu-' . metis_key_clean( (string) $module_key . '-' . (string) ( $item['id'] ?? 0 ) );
            $active_view = metis_key_clean( (string) metis_get_query_var( 'metis_view' ) );
            $group_active = $is_active;

            $html .= '<div class="metis-sidebar-group' . ( $group_active ? ' is-active' : '' ) . '">';
            $group_url = $route !== '' ? metis_escape_url( $route ) : '#';
            $html .= '<a href="' . $group_url . '" class="metis-sidebar-item metis-sidebar-group-link metis-sidebar-has-submenu' . ( $group_active ? ' is-active' : '' ) . '" data-tooltip="' . metis_escape_attr( $label ) . '" aria-label="' . metis_escape_attr( $label ) . '" aria-haspopup="true" aria-expanded="false" aria-controls="' . metis_escape_attr( $submenu_id ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . '>'
                . '<span class="metis-sidebar-icon' . $icon_class . '">' . $icon . '</span>'
                . '<span class="metis-sidebar-label">' . metis_escape_html( $label ) . '</span>'
                . '</a>';
            $html .= '<div class="metis-sidebar-submenu" id="' . metis_escape_attr( $submenu_id ) . '" aria-label="' . metis_escape_attr( $label . ' navigation' ) . '" aria-hidden="true">';
            $html .= '<div class="metis-sidebar-submenu-title">' . metis_escape_html( $label ) . '</div>';

            foreach ( $module_view_children as $child ) {
                $childLabel = trim( (string) ( $child['label'] ?? '' ) );
                $childRoute = trim( (string) ( $child['route'] ?? '' ) );
                $childView = metis_key_clean( (string) ( $child['view_key'] ?? '' ) );
                if ( $childLabel === '' || $childRoute === '' ) {
                    continue;
                }

                $childActive = $is_active && $active_view !== '' && $childView === $active_view;
                $html .= '<a class="metis-sidebar-subitem' . ( $childActive ? ' is-active' : '' ) . '" href="' . metis_escape_url( $childRoute ) . '" data-tooltip="' . metis_escape_attr( $childLabel ) . '" aria-label="' . metis_escape_attr( $childLabel ) . '"' . ( $childActive ? ' aria-current="page"' : '' ) . '>'
                    . metis_escape_html( $childLabel ) . '</a>';
            }

            $html .= '</div></div>';
            continue;
        }

        $submenu_id = 'metis-sidebar-submenu-' . metis_key_clean( (string) $module_key . '-' . (string) ( $item['id'] ?? 0 ) );
        $group_active = $is_active;
        foreach ( $children as $child ) {
            $child_module = metis_key_clean( (string) ( $child['module_key'] ?? '' ) );
            if ( $child_module !== '' && $child_module === $active_domain ) {
                $group_active = true;
                break;
            }
        }

        $html .= '<div class="metis-sidebar-group' . ( $group_active ? ' is-active' : '' ) . '">';
        $group_url = $route !== '' ? metis_escape_url( $route ) : '#';
        $html .= '<a href="' . $group_url . '" class="metis-sidebar-item metis-sidebar-group-link metis-sidebar-has-submenu' . ( $group_active ? ' is-active' : '' ) . '" data-tooltip="' . metis_escape_attr( $label ) . '" aria-label="' . metis_escape_attr( $label ) . '" aria-haspopup="true" aria-expanded="false" aria-controls="' . metis_escape_attr( $submenu_id ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . '>'
            . '<span class="metis-sidebar-icon' . $icon_class . '">' . $icon . '</span>'
            . '<span class="metis-sidebar-label">' . metis_escape_html( $label ) . '</span>'
            . '</a>';
        $html .= '<div class="metis-sidebar-submenu" id="' . metis_escape_attr( $submenu_id ) . '" aria-label="' . metis_escape_attr( $label . ' navigation' ) . '" aria-hidden="true">';
        $html .= '<div class="metis-sidebar-submenu-title">' . metis_escape_html( $label ) . '</div>';
        $active_view = metis_key_clean( (string) metis_get_query_var( 'metis_view' ) );

        foreach ( $children as $child ) {
            $childLabel = trim( (string) ( $child['label'] ?? '' ) );
            if ( $childLabel === '' ) {
                continue;
            }
            $childRoute = trim( (string) ( $child['route'] ?? '' ) );
            if ( $childRoute === '' ) {
                continue;
            }

            $childModule = metis_key_clean( (string) ( $child['module_key'] ?? '' ) );
            $childActive = $childModule !== '' && $childModule === $active_domain;
            $childViewChildren = [];
            if ( $childModule !== '' && ! str_contains( $childModule, ':' ) && function_exists( 'metis_get_module' ) ) {
                $childModuleCfg = metis_get_module( $childModule );
                $childConfig = is_array( $childModuleCfg ) ? (array) ( $childModuleCfg['config'] ?? [] ) : [];
                $childMenuItems = is_array( $childConfig['menu']['items'] ?? null ) ? (array) $childConfig['menu']['items'] : [];

                if ( count( $childMenuItems ) > 1 ) {
                    foreach ( $childMenuItems as $view_slug => $view_label ) {
                        $view_slug = metis_key_clean( (string) $view_slug );
                        $view_label = trim( (string) $view_label );
                        if ( $view_slug === '' || $view_label === '' ) {
                            continue;
                        }
                        if ( ! metis_sidebar_module_view_allowed( $childConfig, $view_slug ) ) {
                            continue;
                        }
                        $view_route = function_exists( 'metis_portal_url' ) ? metis_portal_url( $childModule, $view_slug ) : '';
                        if ( $view_route === '' ) {
                            continue;
                        }
                        $childViewChildren[] = [
                            'label' => $view_label,
                            'route' => $view_route,
                            'view_key' => $view_slug,
                        ];
                    }
                }
            }

            if ( $childViewChildren === [] ) {
                $html .= '<a class="metis-sidebar-subitem' . ( $childActive ? ' is-active' : '' ) . '" href="' . metis_escape_url( $childRoute ) . '" data-tooltip="' . metis_escape_attr( $childLabel ) . '" aria-label="' . metis_escape_attr( $childLabel ) . '"' . ( $childActive ? ' aria-current="page"' : '' ) . '>'
                    . metis_escape_html( $childLabel ) . '</a>';
                continue;
            }

            $subsubmenu_id = 'metis-sidebar-subsubmenu-' . metis_key_clean( (string) $module_key . '-' . (string) $childModule . '-' . (string) $childLabel );
            $html .= '<div class="metis-sidebar-subitem-group">';
            $html .= '<a class="metis-sidebar-subitem metis-sidebar-subitem-link' . ( $childActive ? ' is-active' : '' ) . '" href="' . metis_escape_url( $childRoute ) . '" data-tooltip="' . metis_escape_attr( $childLabel ) . '" aria-label="' . metis_escape_attr( $childLabel ) . '" aria-haspopup="true" aria-expanded="false" aria-controls="' . metis_escape_attr( $subsubmenu_id ) . '"' . ( $childActive ? ' aria-current="page"' : '' ) . '>'
                . '<span>' . metis_escape_html( $childLabel ) . '</span>'
                . '<span class="metis-sidebar-subitem-arrow" aria-hidden="true">›</span>'
                . '</a>';
            $html .= '<div class="metis-sidebar-subsubmenu" id="' . metis_escape_attr( $subsubmenu_id ) . '" aria-label="' . metis_escape_attr( $childLabel . ' views' ) . '" aria-hidden="true">';
            foreach ( $childViewChildren as $viewChild ) {
                $viewLabel = trim( (string) ( $viewChild['label'] ?? '' ) );
                $viewRoute = trim( (string) ( $viewChild['route'] ?? '' ) );
                $viewKey = metis_key_clean( (string) ( $viewChild['view_key'] ?? '' ) );
                if ( $viewLabel === '' || $viewRoute === '' ) {
                    continue;
                }
                $viewActive = $childActive && $active_view !== '' && $viewKey === $active_view;
                $html .= '<a class="metis-sidebar-subitem' . ( $viewActive ? ' is-active' : '' ) . '" href="' . metis_escape_url( $viewRoute ) . '" data-nav-nested-link="1" data-tooltip="' . metis_escape_attr( $viewLabel ) . '" aria-label="' . metis_escape_attr( $viewLabel ) . '"' . ( $viewActive ? ' aria-current="page"' : '' ) . '>'
                    . metis_escape_html( $viewLabel ) . '</a>';
            }
            $html .= '</div></div>';
        }

        $html .= '</div></div>';
    }

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_BUILD_NAV_DONE' );
    }
    return $html;
}

function metis_nav_build( array $modules, string $active_domain ): string {
    return metis_sidebar_nav( $modules, $active_domain );
}

function metis_sidebar_logout_nav( string $active_domain = '' ): string {
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_BUILD_NAV_LOGOUT' );
    }

    if ( ! function_exists( 'metis_navigation_service' ) ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_BUILD_NAV_LOGOUT_DONE' );
        }
        return '';
    }

    $items = metis_navigation_service()->visibleTree();
    if ( empty( $items ) ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_BUILD_NAV_LOGOUT_DONE' );
        }
        return '';
    }

    $logoutItem = null;
    foreach ( $items as $item ) {
        if ( (string) ( $item['module_key'] ?? '' ) === 'system:logout' ) {
            $logoutItem = $item;
            break;
        }
    }

    if ( ! is_array( $logoutItem ) ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_BUILD_NAV_LOGOUT_DONE' );
        }
        return '';
    }

    $label = trim( (string) ( $logoutItem['label'] ?? 'Log Out' ) );
    $route = trim( (string) ( $logoutItem['route'] ?? '' ) );
    $icon = trim( (string) ( $logoutItem['icon'] ?? '' ) );
    if ( $icon === '' ) {
        $icon = 'icon:logout';
    }
    $icon = metis_navigation_icon_markup( $icon );

    $module_key = metis_key_clean( (string) ( $logoutItem['module_key'] ?? '' ) );
    $is_active = $module_key !== '' && $module_key === $active_domain;
    $active_cls = $is_active ? ' is-active' : '';
    $aria_current = $is_active ? ' aria-current="page"' : '';
    $icon_class = str_contains( (string) $icon, '<img' ) ? ' metis-sidebar-icon-image' : '';
    $url = $route !== '' ? metis_escape_url( $route ) : '#';

    $html = '<a href="' . $url . '" class="metis-sidebar-item metis-sidebar-logout' . $active_cls . '" data-tooltip="' . metis_escape_attr( $label ) . '" aria-label="' . metis_escape_attr( $label ) . '"' . $aria_current . '>'
        . '<span class="metis-sidebar-icon' . $icon_class . '">' . $icon . '</span>'
        . '<span class="metis-sidebar-label">' . metis_escape_html( $label ) . '</span>'
        . '</a>';

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_BUILD_NAV_LOGOUT_DONE' );
    }

    return $html;
}

function metis_current_module_view_title( string $fallback = '' ): string {
    $module_label = metis_current_module_label();
    $view = metis_key_clean( (string) metis_get_query_var( 'metis_view' ) );
    $default_view = '';
    $module = metis_current_module();

    if ( is_array( $module ) ) {
        $default_view = metis_key_clean( (string) ( $module['config']['default_view'] ?? 'dashboard' ) );
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

    $merged_order = metis_default_module_order();
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
        return [ 'ok' => false, 'error' => 'Avatar upload is not allowed.' ];
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

    $stored = \Metis\Core\Services\CredentialService::getBySetting( 'workspace_service_account_json' );
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

function metis_workspace_service_account_error( array $service = [] ): string {
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
