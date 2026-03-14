<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Metis Logger
 *
 * Centralized logging for the Metis framework.
 * Logs are organized by year.
 *
 * Usage:
 *   Metis_Logger::info( 'Initialization Started' );
 *   Metis_Logger::error( 'Something failed', ['context' => 'value'] );
 *   Metis_Logger::module( 'donations' );
 *   Metis_Logger::boot_start();
 *   Metis_Logger::boot_end();
 *
 * Log format:
 *   [2026-03-04 08:00:00] [INFO ] Initialization Started
 *   [2026-03-04 08:00:00] [ERROR] Something failed {"context":"value"}
 *
 * Log file location:
 *   /project_metis/logs/2026/metis-2026-03.log
 */

class Metis_Logger {

    const DEBUG = 'DEBUG';
    const INFO  = 'INFO';
    const WARN  = 'WARN';
    const ERROR = 'ERROR';
    const MAX_LOG_BYTES = 10485760;
    const RETENTION_DAYS = 90;
    private const LEVEL_PRIORITY = [
        self::DEBUG => 10,
        self::INFO  => 20,
        self::WARN  => 30,
        self::ERROR => 40,
    ];

    private static string $log_dir     = '';
    private static string $log_file    = '';
    private static bool   $initialized = false;
    private static string $last_prune_run = '';

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    /**
     * Called once from metis.php after METIS_PATH is defined.
     */
    public static function init(): void {

        if ( self::$initialized ) {
            return;
        }

        $base          = defined( 'METIS_PATH' ) ? METIS_PATH : WP_CONTENT_DIR . '/';
        self::$log_dir = $base . 'logs/';

        self::prune_old_logs();
        self::$log_file    = self::resolve_log_file();
        self::$initialized = true;
    }

    /**
     * Resolve the log file path for the current period.
     * Creates the directory structure if it doesn't exist.
     *
     * Structure: /logs/YYYY/metis-YYYY-MM.log
     */
    private static function resolve_log_file( int $incoming_bytes = 0 ): string {

        $base_path = self::resolve_period_base_file();
        $paths     = self::period_log_paths( $base_path );

        if ( empty( $paths ) ) {
            return $base_path;
        }

        $path = end( $paths );
        if ( ! is_string( $path ) || $path === '' ) {
            return $base_path;
        }

        clearstatcache( true, $path );
        $size = file_exists( $path ) ? (int) filesize( $path ) : 0;
        if ( $size + $incoming_bytes < self::MAX_LOG_BYTES ) {
            return $path;
        }

        return self::part_path( $base_path, count( $paths ) + 1 );
    }

    private static function resolve_period_base_file(): string {

        $year  = date( 'Y' );
        $month = date( 'm' );
        $dir   = self::$log_dir . "{$year}/";

        if ( ! is_dir( $dir ) ) {
            metis_make_dir( $dir );
        }

        $path        = $dir . "metis-{$year}-{$month}.log";
        $legacy_path = self::$log_dir . "{$year}/{$month}/metis-{$year}-{$month}.log";

        if ( ! file_exists( $path ) && file_exists( $legacy_path ) ) {
            @rename( $legacy_path, $path );
        }

        return $path;
    }

    private static function period_log_paths( ?string $base_path = null ): array {

        $base_path = $base_path ?: self::resolve_period_base_file();
        $paths     = [];

        if ( file_exists( $base_path ) ) {
            $paths[] = $base_path;
        }

        for ( $part = 2; $part <= 999; $part++ ) {
            $part_path = self::part_path( $base_path, $part );
            if ( ! file_exists( $part_path ) ) {
                break;
            }

            $paths[] = $part_path;
        }

        return $paths;
    }

    private static function part_path( string $base_path, int $part ): string {
        if ( $part <= 1 ) {
            return $base_path;
        }

        return substr( $base_path, 0, -4 ) . '-part' . str_pad( (string) $part, 2, '0', STR_PAD_LEFT ) . '.log';
    }

    private static function prune_old_logs(): void {

        $today = date( 'Y-m-d' );
        if ( self::$last_prune_run === $today ) {
            return;
        }
        self::$last_prune_run = $today;

        if ( ! is_dir( self::$log_dir ) ) {
            return;
        }

        $cutoff = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
        $years  = glob( self::$log_dir . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR );
        if ( ! is_array( $years ) ) {
            return;
        }

        foreach ( $years as $year_dir ) {
            $logs = glob( rtrim( $year_dir, '/' ) . '/*.log' );
            if ( ! is_array( $logs ) ) {
                continue;
            }

            foreach ( $logs as $path ) {
                $modified = @filemtime( $path );
                if ( $modified === false || $modified >= $cutoff ) {
                    continue;
                }

                @unlink( $path );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public static function debug( string $message, array $context = [] ): void {
        self::write( self::DEBUG, $message, $context );
    }

    public static function info( string $message, array $context = [] ): void {
        self::write( self::INFO, $message, $context );
    }

    public static function warn( string $message, array $context = [] ): void {
        self::write( self::WARN, $message, $context );
    }

    public static function error( string $message, array $context = [] ): void {
        self::write( self::ERROR, $message, $context );
    }

    // -------------------------------------------------------------------------
    // Boot sequence helpers
    // -------------------------------------------------------------------------

    public static function boot_start(): void {
        self::info( 'Initialization Started' );
    }

    public static function core_loaded(): void {
        self::info( 'Core Loaded' );
    }

    public static function boot_modules_start(): void {
        self::info( 'Booting Modules' );
    }

    public static function module( string $slug ): void {
        self::info( "Loading module: {$slug}" );
    }

    public static function module_registered( string $slug ): void {
        self::info( "Module registered: {$slug}" );
    }

    public static function boot_end(): void {
        self::info( 'Finished booting modules' );
        self::info( 'Initialization Completed' );
    }

    // -------------------------------------------------------------------------
    // Error handler bridge
    // -------------------------------------------------------------------------

    public static function handle_error( string $message, array $context = [] ): void {
        self::error( $message, $context );
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Return the current log file path.
     */
    public static function log_path(): string {
        return self::$log_file;
    }

    /**
     * Return the root logs directory path.
     */
    public static function log_dir(): string {
        return self::$log_dir;
    }

    /**
     * Clear the current log file.
     */
    public static function clear(): void {
        $base_path = self::resolve_period_base_file();
        $paths     = self::period_log_paths( $base_path );

        foreach ( $paths as $path ) {
            if ( file_exists( $path ) ) {
                file_put_contents( $path, '' );
            }
        }

        self::$log_file = $base_path;
    }

    /**
     * Return the last N lines of the current log.
     */
    public static function tail( int $lines = 50 ): string {

        $paths = self::period_log_paths();
        if ( empty( $paths ) ) {
            return '';
        }

        $all = [];
        foreach ( $paths as $path ) {
            $chunk = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if ( is_array( $chunk ) ) {
                $all = array_merge( $all, $chunk );
            }
        }

        if ( ! $all ) {
            return '';
        }

        return implode( PHP_EOL, array_slice( $all, -$lines ) );
    }

    // -------------------------------------------------------------------------
    // Internal writer
    // -------------------------------------------------------------------------

    /**
     * Public entry point for dynamic log levels (used by error handler).
     */
    public static function write_level( string $level, string $message, array $context = [] ): void {
        self::write( $level, $message, $context );
    }

    public static function configured_min_level(): string {
        $default = self::INFO;

        if ( ! self::settings_service_available() ) {
            return $default;
        }

        $configured = strtoupper( (string) Core_Settings_Service::get( 'logging_min_level', $default ) );
        return array_key_exists( $configured, self::LEVEL_PRIORITY ) ? $configured : $default;
    }

    private static function write( string $level, string $message, array $context = [] ): void {

        if ( ! self::$initialized ) {
            self::init();
        }

        self::prune_old_logs();

        if ( ! self::should_write_level( $level ) ) {
            return;
        }

        $timestamp = date( 'Y-m-d H:i:s' );
        $padded    = str_pad( $level, 5 );
        $line      = "[{$timestamp}] [{$padded}] {$message}";

        if ( ! empty( $context ) ) {
            $line .= ' ' . metis_json_encode( $context );
        }

        $line .= PHP_EOL;

        // Re-resolve each write so the file name follows the current date and rotates at 10 MB.
        self::$log_file = self::resolve_log_file( strlen( $line ) );

        @file_put_contents( self::$log_file, $line, FILE_APPEND | LOCK_EX );
    }

    private static function should_write_level( string $level ): bool {
        $level = strtoupper( $level );
        if ( ! array_key_exists( $level, self::LEVEL_PRIORITY ) ) {
            $level = self::INFO;
        }

        $minimum = self::configured_min_level();
        if ( self::request_forces_logging() && self::LEVEL_PRIORITY[ self::INFO ] < self::LEVEL_PRIORITY[ $minimum ] ) {
            $minimum = self::INFO;
        }

        return self::LEVEL_PRIORITY[ $level ] >= self::LEVEL_PRIORITY[ $minimum ];
    }

    private static function request_forces_logging(): bool {
        if ( ! self::settings_service_available() ) {
            return false;
        }

        $token = trim( (string) Core_Settings_Service::get( 'logging_force_url_token', '' ) );
        if ( $token === '' ) {
            return false;
        }

        $request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        return $request_uri !== '' && strpos( $request_uri, $token ) !== false;
    }

    private static function settings_service_available(): bool {
        if ( ! class_exists( 'Core_Settings_Service' ) ) {
            $settings_path = __DIR__ . '/settings_service.php';
            if ( file_exists( $settings_path ) ) {
                require_once $settings_path;
            }
        }

        if ( ! class_exists( 'Core_Settings_Service' ) ) {
            return false;
        }

        Core_Settings_Service::init();
        return true;
    }
}

// -------------------------------------------------------------------------
// Global bridges
// -------------------------------------------------------------------------

if ( ! function_exists( 'metis_error' ) ) {
    function metis_error( string $message, array $context = [] ): void {
        Metis_Logger::error( $message, $context );
    }
}

if ( ! function_exists( 'metis_warn' ) ) {
    function metis_warn( string $message, array $context = [] ): void {
        Metis_Logger::warn( $message, $context );
    }
}

if ( ! function_exists( 'metis_log' ) ) {
    function metis_log( string $message, array $context = [] ): void {
        Metis_Logger::info( $message, $context );
    }
}

// -------------------------------------------------------------------------
// PHP error & exception hooks — funnel into Metis_Logger
// -------------------------------------------------------------------------

set_exception_handler( function ( Throwable $e ) {
    Metis_Logger::error( 'Uncaught exception: ' . $e->getMessage(), [
        'class' => get_class( $e ),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ] );
    // Re-throw so the runtime / PHP still handles it normally
    throw $e;
} );

set_error_handler( function ( int $errno, string $errstr, string $errfile, int $errline ): bool {
    // Only capture errors that are in the active error_reporting mask
    if ( ! ( error_reporting() & $errno ) ) {
        return false;
    }

    $level_map = [
        E_ERROR             => Metis_Logger::ERROR,
        E_WARNING           => Metis_Logger::WARN,
        E_NOTICE            => Metis_Logger::DEBUG,
        E_USER_ERROR        => Metis_Logger::ERROR,
        E_USER_WARNING      => Metis_Logger::WARN,
        E_USER_NOTICE       => Metis_Logger::DEBUG,
        E_RECOVERABLE_ERROR => Metis_Logger::ERROR,
        E_DEPRECATED        => Metis_Logger::DEBUG,
        E_USER_DEPRECATED   => Metis_Logger::DEBUG,
    ];

    $log_level = $level_map[ $errno ] ?? Metis_Logger::WARN;

    // Only write WARN and above to the log to avoid noise from notices
    if ( in_array( $log_level, [ Metis_Logger::ERROR, Metis_Logger::WARN ], true ) ) {
        Metis_Logger::write_level( $log_level, $errstr, [
            'errno' => $errno,
            'file'  => $errfile,
            'line'  => $errline,
        ] );
    }

    // Return false to let PHP's standard error handler run too
    return false;
}, E_ALL );
