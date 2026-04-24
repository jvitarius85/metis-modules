<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Core Settings Service
 *
 * Centralized configuration store for Metis.
 * Reads and writes to the metis_settings table via the Metis DB service.
 *
 * Boot notes:
 *   - init() is safe to call early (stores the table name, schedules preload)
 *   - Actual DB reads are deferred to the runtime loaded hook so the DB layer is ready
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
     * Call once from metis.php. Safe to call before the DB connection is fully ready.
     * Defers the actual DB preload until the runtime loaded event.
     */
    public static function init(): void {

        if ( self::$booted ) return;

        $prefix = '';
        $db_connection = $GLOBALS['metis_db_connection'] ?? null;
        if ( is_object( $db_connection ) && isset( $db_connection->prefix ) ) {
            $prefix = (string) $db_connection->prefix;
        }

        // Table name is self-contained and can be resolved before the service container is fully ready.
        self::$table  = $prefix . 'metis_settings';
        self::$booted = true;

        // Defer preload until the runtime and DB layer are fully ready.
        metis_on( 'metis_runtime_loaded', [ self::class, 'preload' ], 1 );
    }

    /**
     * Preload all autoloaded settings into the in-memory cache.
     * Called automatically via the runtime loaded event. Safe to call manually if needed.
     */
    public static function preload(): void {

        if ( self::$preloaded ) return;
        if ( ! self::$table )   return;

        // Verify table exists before querying — avoids fatal errors during first install
        try {
            $exists = self::db()->scalar( "SHOW TABLES LIKE %s", [ self::$table ] );
        } catch ( \Throwable ) {
            return;
        }

        if ( ! $exists ) {
            self::$preloaded = true;
            return;
        }

        try {
            $rows = self::db()->fetchAll( "SELECT setting_key, setting_value FROM " . self::$table . " WHERE autoload = 1" );
        } catch ( \Throwable ) {
            return;
        }

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

        // Guard: if table doesn't exist yet (first install), return default quietly
        if ( ! self::table_exists() ) return $default;

        try {
            $value = self::db()->scalar( "SELECT setting_value FROM " . self::$table . " WHERE setting_key = %s LIMIT 1", [ $key ] );
        } catch ( \Throwable ) {
            return $default;
        }

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

        try {
            $result = self::db()->replace(
                self::$table,
                [
                    'setting_key'   => $key,
                    'setting_value' => metis_json_encode( $value ),
                    'autoload'      => $autoload ? 1 : 0,
                    'updated_at'    => metis_current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%d', '%s' ]
            );
        } catch ( \Throwable ) {
            return false;
        }

        if ( $result === false ) return false;

        self::$cache[ $key ] = $value;

        return true;
    }

    /**
     * Delete a setting.
     */
    public static function delete( string $key ): bool {

        if ( ! self::table_exists() ) return false;

        try {
            $deleted = self::db()->delete(
                self::$table,
                [ 'setting_key' => $key ],
                [ '%s' ]
            );
        } catch ( \Throwable ) {
            return false;
        }

        unset( self::$cache[ $key ] );

        return $deleted !== false;
    }

    /**
     * Check whether a setting key exists in the database.
     */
    public static function has( string $key ): bool {

        if ( array_key_exists( $key, self::$cache ) ) return true;
        if ( ! self::table_exists() ) return false;

        try {
            return (int) self::db()->scalar( "SELECT COUNT(*) FROM " . self::$table . " WHERE setting_key = %s", [ $key ] ) > 0;
        } catch ( \Throwable ) {
            return false;
        }
    }

    /**
     * Return all settings as a key => value array.
     */
    public static function all(): array {

        if ( ! self::table_exists() ) return [];

        self::import_legacy_settings_if_needed();

        try {
            $rows = self::db()->fetchAll( "SELECT setting_key, setting_value FROM " . self::$table );
        } catch ( \Throwable ) {
            return [];
        }
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

        static $checked = null;

        if ( $checked === null ) {
            try {
                $checked = (bool) self::db()->scalar( "SHOW TABLES LIKE %s", [ self::$table ] );
            } catch ( \Throwable ) {
                $checked = false;
            }
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
            self::set( '_legacy_settings_imported_v1', metis_current_time( 'mysql' ), false );
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

        self::set( '_legacy_settings_imported_v1', metis_current_time( 'mysql' ), false );

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

        $db = self::db();
        if ( ! $db ) {
            return $legacy;
        }

        foreach ( [ 'options', 'legacy_options' ] as $table ) {
            $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
            if ( $exists !== $table ) {
                continue;
            }

            $keys = array_keys( self::legacy_settings_map() );
            $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
            $sql = $db->prepare(
                "SELECT option_name, option_value FROM {$table} WHERE option_name IN ({$placeholders})",
                ...$keys
            );
            $rows = $db->fetchAll( $sql );
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

    private static function db(): \Metis\Services\DatabaseService {
        if ( class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'db' ) ) {
            /** @var \Metis\Services\DatabaseService $db */
            $db = \Metis\Core\Application::service( 'db' );
            return $db;
        }

        return new \Metis\Services\DatabaseService();
    }
}
