<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core Settings Service
 *
 * Centralized configuration store for Metis.
 * Reads and writes to the metis_settings table via wpdb.
 *
 * Boot notes:
 *   - init() is safe to call early (stores the table name, schedules preload)
 *   - Actual DB reads are deferred to the wp_loaded hook so $wpdb is ready
 *   - Any get() call before preload fires will do a direct DB query (safe fallback)
 *
 * Usage:
 *   Core_Settings_Service::get( 'stripe_secret' )
 *   Core_Settings_Service::set( 'stripe_secret', 'sk_live_...' )
 *   Core_Settings_Service::delete( 'stripe_secret' )
 */

class Core_Settings_Service {

    private static array  $cache    = [];
    private static string $table    = '';
    private static bool   $booted   = false;
    private static bool   $preloaded = false;
    private static bool   $legacy_import_checked = false;

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    /**
     * Call once from metis.php. Safe to call before $wpdb is fully ready.
     * Defers the actual DB preload until wp_loaded.
     */
    public static function init(): void {

        if ( self::$booted ) return;

        global $wpdb;

        // Table name is self-contained — does not depend on Metis_Tables
        self::$table  = $wpdb->prefix . 'metis_settings';
        self::$booted = true;

        // Defer preload until the runtime and $wpdb are fully ready
        metis_add_action( 'wp_loaded', [ self::class, 'preload' ], 1 );
    }

    /**
     * Preload all autoloaded settings into the in-memory cache.
     * Called automatically via wp_loaded. Safe to call manually if needed.
     */
    public static function preload(): void {

        if ( self::$preloaded ) return;
        if ( ! self::$table )   return;

        global $wpdb;

        // Verify table exists before querying — avoids fatal errors during first install
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", self::$table )
        );

        if ( ! $exists ) {
            self::$preloaded = true;
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT setting_key, setting_value FROM " . self::$table . " WHERE autoload = 1",
            ARRAY_A
        );

        foreach ( $rows ?: [] as $row ) {
            self::$cache[ $row['setting_key'] ] = self::decode( $row['setting_value'] );
        }

        self::import_legacy_settings_if_needed();
        self::$preloaded = true;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Get a setting value. Returns $default if not found.
     */
    public static function get( string $key, $default = null ) {

        if ( array_key_exists( $key, self::$cache ) ) {
            return self::$cache[ $key ];
        }

        if ( ! self::$table ) return $default;

        global $wpdb;

        // Guard: if table doesn't exist yet (first install), return default quietly
        if ( ! self::table_exists() ) return $default;

        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT setting_value FROM " . self::$table . " WHERE setting_key = %s LIMIT 1",
            $key
        ) );

        if ( $value === null ) return $default;

        $decoded = self::decode( $value );
        self::$cache[ $key ] = $decoded;

        return $decoded;
    }

    /**
     * Set or update a setting.
     *
     * @param string $key
     * @param mixed  $value     Any JSON-serializable value
     * @param bool   $autoload  Whether to preload on boot (default true)
     */
    public static function set( string $key, $value, bool $autoload = true ): bool {

        if ( ! self::table_exists() ) return false;

        global $wpdb;

        $result = $wpdb->replace(
            self::$table,
            [
                'setting_key'   => $key,
                'setting_value' => metis_json_encode( $value ),
                'autoload'      => $autoload ? 1 : 0,
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%s' ]
        );

        if ( $result === false ) return false;

        self::$cache[ $key ] = $value;

        return true;
    }

    /**
     * Delete a setting.
     */
    public static function delete( string $key ): bool {

        if ( ! self::table_exists() ) return false;

        global $wpdb;

        $deleted = $wpdb->delete(
            self::$table,
            [ 'setting_key' => $key ],
            [ '%s' ]
        );

        unset( self::$cache[ $key ] );

        return $deleted !== false;
    }

    /**
     * Check whether a setting key exists in the database.
     */
    public static function has( string $key ): bool {

        if ( array_key_exists( $key, self::$cache ) ) return true;
        if ( ! self::table_exists() ) return false;

        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table . " WHERE setting_key = %s",
            $key
        ) ) > 0;
    }

    /**
     * Return all settings as a key => value array.
     */
    public static function all(): array {

        if ( ! self::table_exists() ) return [];

        self::import_legacy_settings_if_needed();

        global $wpdb;

        $rows   = $wpdb->get_results(
            "SELECT setting_key, setting_value FROM " . self::$table,
            ARRAY_A
        );
        $result = [];

        foreach ( $rows ?: [] as $row ) {
            $result[ $row['setting_key'] ] = self::decode( $row['setting_value'] );
        }

        return $result;
    }

    /**
     * Expose the resolved table name (useful for diagnostics).
     */
    public static function table_name(): string {
        return self::$table;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function table_exists(): bool {

        if ( ! self::$table ) return false;

        global $wpdb;

        static $checked = null;

        if ( $checked === null ) {
            $checked = (bool) $wpdb->get_var(
                $wpdb->prepare( "SHOW TABLES LIKE %s", self::$table )
            );
        }

        return $checked;
    }

    private static function decode( string $value ) {
        $decoded = json_decode( $value, true );
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private static function import_legacy_settings_if_needed(): void {

        if ( self::$legacy_import_checked || ! self::table_exists() ) {
            return;
        }

        self::$legacy_import_checked = true;

        if ( self::has( '_legacy_settings_imported_v1' ) ) {
            return;
        }

        $legacy = self::read_legacy_settings_sources();
        if ( empty( $legacy ) ) {
            self::set( '_legacy_settings_imported_v1', current_time( 'mysql' ), false );
            return;
        }

        $imported = 0;
        foreach ( self::legacy_settings_map() as $legacy_key => $setting_key ) {
            if ( self::has( $setting_key ) ) {
                continue;
            }

            if ( ! array_key_exists( $legacy_key, $legacy ) ) {
                continue;
            }

            $value = $legacy[ $legacy_key ];
            if ( self::legacy_value_is_empty( $value ) ) {
                continue;
            }

            if ( self::set( $setting_key, $value, false ) ) {
                $imported++;
            }
        }

        self::set( '_legacy_settings_imported_v1', current_time( 'mysql' ), false );

        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::info( 'Legacy settings import checked', [
                'imported' => $imported,
                'table'    => self::$table,
            ] );
        }
    }

    private static function legacy_settings_map(): array {
        return [
            'mwtools_stripe_secret'              => 'stripe_secret',
            'stripe_secret'                      => 'stripe_secret',
            'stripe_webhook_secret'              => 'stripe_webhook_secret',
            'workspace_impersonation_admin'      => 'workspace_impersonation_admin',
            'workspace_service_account_json'     => 'workspace_service_account_json',
            'workspace_customer_id'              => 'workspace_customer_id',
            'workspace_domain'                   => 'workspace_domain',
            'workspace_drive_configs'            => 'workspace_drive_configs',
            'workspace_shared_drive_id'          => 'workspace_shared_drive_id',
            'workspace_calendar_configs'         => 'workspace_calendar_configs',
            'workspace_default_calendar_id'      => 'workspace_default_calendar_id',
            'workspace_stripe_sso_schema'        => 'workspace_stripe_sso_schema',
            'workspace_stripe_sso_field'         => 'workspace_stripe_sso_field',
            'workspace_stripe_access_group_email'=> 'workspace_stripe_access_group_email',
        ];
    }

    private static function read_legacy_settings_sources(): array {
        $legacy = [];

        if ( function_exists( 'metis_runtime_json_store_read' ) ) {
            $store = metis_runtime_json_store_read( 'options.json' );
            if ( is_array( $store ) ) {
                $legacy = array_merge( $legacy, $store );
            }
        }

        global $wpdb;
        if ( ! $wpdb ) {
            return $legacy;
        }

        foreach ( [ 'options', 'wp_options' ] as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) {
                continue;
            }

            $keys = array_keys( self::legacy_settings_map() );
            $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
            $sql = $wpdb->prepare(
                "SELECT option_name, option_value FROM {$table} WHERE option_name IN ({$placeholders})",
                ...$keys
            );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            foreach ( $rows ?: [] as $row ) {
                $name = (string) ( $row['option_name'] ?? '' );
                if ( $name === '' ) {
                    continue;
                }
                $legacy[ $name ] = self::maybe_decode_legacy_value( $row['option_value'] ?? null );
            }
        }

        return $legacy;
    }

    private static function maybe_decode_legacy_value( mixed $value ): mixed {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        if ( function_exists( 'maybe_unserialize' ) ) {
            $value = maybe_unserialize( $value );
        }

        if ( ! is_string( $value ) ) {
            return $value;
        }

        $decoded = json_decode( $value, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $decoded;
        }

        return $value;
    }

    private static function legacy_value_is_empty( mixed $value ): bool {
        if ( is_array( $value ) ) {
            return empty( $value );
        }

        return ! is_string( $value ) ? empty( $value ) : trim( $value ) === '';
    }
}
