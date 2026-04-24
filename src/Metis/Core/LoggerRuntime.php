<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( class_exists( 'Metis_Logger' ) ) {
    return;
}

/**
 * Metis Logger
 *
 * Centralized logging for the Metis framework.
 * Logs are organized monthly with capped part files.
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
 *   /project_metis/storage/logs/2026/metis-2026-03.log
 */

class Metis_Logger {

    const DEBUG = 'DEBUG';
    const INFO  = 'INFO';
    const WARN  = 'WARN';
    const ERROR = 'ERROR';
    const MAX_LOG_BYTES = 5242880; // 5MB per log file
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

    private static function ensure_initialized(): void {
        if ( self::$initialized ) {
            return;
        }

        self::init();
    }

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

        $base          = defined( 'METIS_PATH' ) ? METIS_PATH : ( defined( 'METIS_ROOT' ) ? METIS_ROOT : dirname( __DIR__, 2 ) . '/' );
        self::$log_dir = $base . 'storage/logs/';
        self::ensure_directory_access( rtrim( self::$log_dir, '/\\' ) );

        self::prune_old_logs();
        self::$log_file    = self::resolve_log_file();
        self::$initialized = true;
    }

    /**
     * Resolve the current log file path.
     *
     * Structure: /storage/logs/YYYY/metis-YYYY-MM.log
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
        if ( self::path_allows_append( $path ) && $size + $incoming_bytes < self::MAX_LOG_BYTES ) {
            return $path;
        }

        $next_path = self::part_path( $base_path, count( $paths ) + 1 );
        if ( self::path_allows_append( $next_path ) ) {
            return $next_path;
        }

        return $base_path;
    }

    private static function resolve_period_base_file(): string {
        $year  = date( 'Y' );
        $month = date( 'm' );
        $dir   = self::$log_dir . "{$year}/";

        self::ensure_directory_access( rtrim( $dir, '/\\' ) );

        $path        = $dir . "metis-{$year}-{$month}.log";
        $legacy_path = self::$log_dir . "{$year}/{$month}/metis-{$year}-{$month}.log";

        if ( ! file_exists( $path ) && file_exists( $legacy_path ) ) {
            @rename( $legacy_path, $path );
        }

        return $path;
    }

    private static function ensure_directory_access( string $dir ): void {
        if ( $dir === '' ) {
            return;
        }

        if ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0775, true );
        }

        if ( is_dir( $dir ) ) {
            @chmod( $dir, 0775 );
        }
    }

    private static function ensure_file_access( string $path ): void {
        if ( $path === '' ) {
            return;
        }

        $directory = dirname( $path );
        self::ensure_directory_access( $directory );

        if ( ! file_exists( $path ) ) {
            @touch( $path );
        }

        if ( file_exists( $path ) ) {
            @chmod( $path, 0664 );
        }
    }

    private static function path_allows_append( string $path ): bool {
        if ( $path === '' ) {
            return false;
        }

        clearstatcache( true, $path );
        if ( file_exists( $path ) ) {
            return is_writable( $path );
        }

        $directory = dirname( $path );
        self::ensure_directory_access( $directory );

        return is_dir( $directory ) && is_writable( $directory );
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
        $paths = [];
        $years = glob( self::$log_dir . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR ) ?: [];
        foreach ( $years as $year_dir ) {
            $year_logs = glob( rtrim( $year_dir, '/\\' ) . '/*.log' ) ?: [];
            $paths = array_merge( $paths, $year_logs );
        }

        foreach ( array_unique( $paths ) as $path ) {
            $modified = @filemtime( $path );
            if ( $modified === false || $modified >= $cutoff ) {
                continue;
            }

            @unlink( $path );
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
        self::ensure_initialized();
        return self::$log_file;
    }

    /**
     * Return the root logs directory path.
     */
    public static function log_dir(): string {
        self::ensure_initialized();
        return self::$log_dir;
    }

    /**
     * Clear the current log file.
     */
    public static function clear(): void {
        self::ensure_initialized();
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
    public static function tail( int $lines = 50, ?string $relative_path = null ): string {
        $path = self::viewer_log_path( $relative_path );
        if ( $path === '' || ! file_exists( $path ) ) {
            return '';
        }

        $chunk = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $chunk ) || $chunk === [] ) {
            return '';
        }

        return implode( PHP_EOL, array_slice( $chunk, -$lines ) );
    }

    /**
     * @return array<int,array{value:string,label:string,path:string,size:int,modified:int}>
     */
    public static function available_log_files(): array {
        if ( ! self::$initialized ) {
            self::init();
        }

        $files = [];
        $legacy_single = rtrim( self::$log_dir, '/\\' ) . '/metis.log';
        if ( file_exists( $legacy_single ) ) {
            $files[] = [
                'value' => 'metis.log',
                'label' => 'metis.log (legacy single file)',
                'path' => $legacy_single,
                'size' => (int) @filesize( $legacy_single ),
                'modified' => (int) @filemtime( $legacy_single ),
            ];
        }

        $years = glob( self::$log_dir . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR ) ?: [];
        foreach ( $years as $year_dir ) {
            $logs = glob( rtrim( $year_dir, '/\\' ) . '/metis-*.log' ) ?: [];
            foreach ( $logs as $log ) {
                $relative = str_replace( rtrim( self::$log_dir, '/\\' ) . '/', '', $log );
                $files[] = [
                    'value' => $relative,
                    'label' => $relative,
                    'path' => $log,
                    'size' => (int) @filesize( $log ),
                    'modified' => (int) @filemtime( $log ),
                ];
            }
        }

        usort(
            $files,
            static fn ( array $a, array $b ): int => (int) ( $b['modified'] ?? 0 ) <=> (int) ( $a['modified'] ?? 0 )
        );

        return $files;
    }

    public static function viewer_log_path( ?string $relative_path = null ): string {
        if ( ! self::$initialized ) {
            self::init();
        }

        if ( ! is_string( $relative_path ) || trim( $relative_path ) === '' ) {
            return self::log_path();
        }

        $needle = trim( str_replace( '\\', '/', $relative_path ), '/' );
        foreach ( self::available_log_files() as $file ) {
            if ( (string) ( $file['value'] ?? '' ) === $needle ) {
                return (string) ( $file['path'] ?? '' );
            }
        }

        return self::log_path();
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

    public static function logging_enabled(): bool {
        if ( ! self::settings_service_available() ) {
            return true;
        }

        return (int) Core_Settings_Service::get( 'logging_enabled', 1 ) === 1;
    }

    /**
     * Return parsed log entries for settings UI rendering.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function entries( int $lines = 200, ?string $relative_path = null, bool $newest_first = true ): array {
        $raw = self::tail( $lines, $relative_path );
        if ( $raw === '' ) {
            return [];
        }

        $entries = [];
        foreach ( preg_split( '/\R/', $raw ) ?: [] as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) {
                continue;
            }

            $entry = [
                'timestamp' => '',
                'timestamp_display' => '',
                'level' => self::INFO,
                'message' => $line,
                'context' => null,
                'raw' => $line,
            ];

            if ( preg_match( '/^\[([^\]]+)\]\s+\[([A-Z]+)\s*\]\s+(.*)$/', $line, $parts ) ) {
                $entry['timestamp'] = (string) ( $parts[1] ?? '' );
                $entry['timestamp_display'] = self::format_display_timestamp( $entry['timestamp'] );
                $parsed_level = strtoupper( (string) ( $parts[2] ?? self::INFO ) );
                $entry['level'] = array_key_exists( $parsed_level, self::LEVEL_PRIORITY ) ? $parsed_level : self::INFO;

                $tail = (string) ( $parts[3] ?? '' );
                $message = $tail;
                $context = null;

                if ( preg_match( '/^(.*)\s+(\{.*\})$/', $tail, $tail_parts ) ) {
                    $candidate = (string) ( $tail_parts[2] ?? '' );
                    $decoded = json_decode( $candidate, true );
                    if ( is_array( $decoded ) ) {
                        $message = trim( (string) ( $tail_parts[1] ?? '' ) );
                        $context = $decoded;
                    }
                }

                $entry['message'] = $message !== '' ? $message : $tail;
                $entry['context'] = $context;
            }

            $entries[] = $entry;
        }

        return $newest_first ? array_reverse( $entries ) : $entries;
    }

    private static function format_display_timestamp( string $timestamp ): string {
        $timestamp = trim( $timestamp );
        if ( $timestamp === '' ) {
            return '';
        }

        $date_format = 'm/d/y';
        $time_format = 'g:i:s a';
        $timezone_name = 'UTC';

        if ( self::settings_service_available() ) {
            $configured_date = (string) Core_Settings_Service::get( 'date_format', $date_format );
            if ( $configured_date !== '' && strlen( $configured_date ) <= 64 ) {
                $date_format = $configured_date;
            }

            $configured_time = (string) Core_Settings_Service::get( 'time_format', $time_format );
            if ( $configured_time !== '' && strlen( $configured_time ) <= 64 ) {
                $time_format = $configured_time;
            }

            $configured_tz = (string) Core_Settings_Service::get( 'timezone', Core_Settings_Service::get( 'site_timezone', $timezone_name ) );
            if ( $configured_tz !== '' && in_array( $configured_tz, timezone_identifiers_list(), true ) ) {
                $timezone_name = $configured_tz;
            }
        }

        try {
            $normalized = $timestamp;
            // If the log line already carries timezone info, preserve it.
            if ( preg_match( '/(Z|[+\-]\d{2}:?\d{2})$/', $normalized ) ) {
                $dt = new DateTimeImmutable( $normalized );
            } else {
                // Legacy/plain timestamps are interpreted as UTC.
                $dt = new DateTimeImmutable( $normalized, new DateTimeZone( 'UTC' ) );
            }

            return $dt->setTimezone( new DateTimeZone( $timezone_name ) )->format( $date_format . ' ' . $time_format );
        } catch ( Throwable ) {
            return $timestamp;
        }
    }

    private static function write( string $level, string $message, array $context = [] ): void {

        if ( ! self::$initialized ) {
            self::init();
        }

        self::prune_old_logs();

        if ( ! self::should_write_level( $level ) ) {
            return;
        }

        // Always write log timestamps in UTC so display conversion is deterministic.
        $timestamp = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
        $padded    = str_pad( $level, 5 );
        $line      = "[{$timestamp}] [{$padded}] {$message}";

        if ( ! empty( $context ) ) {
            $preview = self::context_preview( $context );
            if ( $preview !== '' ) {
                $line .= ' {' . $preview . '}';
            }

            $encoded = self::encode_context_for_log( $context );
            if ( $encoded !== '' ) {
                $line .= ' ' . $encoded;
            }
        }

        $line .= PHP_EOL;

        // Re-resolve each write so runtime path changes are respected.
        self::$log_file = self::resolve_log_file( strlen( $line ) );
        self::ensure_file_access( self::$log_file );

        $written = @file_put_contents( self::$log_file, $line, FILE_APPEND | LOCK_EX );
        if ( $written === false ) {
            @error_log(
                sprintf(
                    '[metis.logger.write_failed] path=%s message=%s',
                    self::$log_file,
                    $message
                )
            );
        }
    }

    /**
     * Build a compact human-readable preview so raw log files are easier to scan.
     */
    private static function context_preview( array $context ): string {
        $pairs = [];
        $limit = 8;

        foreach ( $context as $key => $value ) {
            if ( count( $pairs ) >= $limit ) {
                break;
            }

            $label = metis_key_clean( (string) $key );
            if ( $label === '' ) {
                $label = (string) $key;
            }

            if ( is_scalar( $value ) || $value === null ) {
                $pairs[] = $label . '=' . self::stringify_context_value( $value );
                continue;
            }

            if ( is_array( $value ) ) {
                $scalar_items = [];
                foreach ( $value as $sub_key => $sub_value ) {
                    if ( ! ( is_scalar( $sub_value ) || $sub_value === null ) ) {
                        continue;
                    }

                    $scalar_items[] = (string) $sub_key . '=' . self::stringify_context_value( $sub_value );
                    if ( count( $scalar_items ) >= 3 ) {
                        break;
                    }
                }

                if ( ! empty( $scalar_items ) ) {
                    $pairs[] = $label . '=[' . implode( ',', $scalar_items ) . ']';
                } else {
                    $pairs[] = $label . '=[complex]';
                }
            }
        }

        if ( empty( $pairs ) ) {
            return '';
        }

        return implode( '; ', $pairs );
    }

    private static function stringify_context_value( mixed $value ): string {
        if ( $value === null ) {
            return 'null';
        }
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }

        $text = trim( (string) $value );
        if ( $text === '' ) {
            return "''";
        }

        $text = preg_replace( '/\s+/', ' ', $text ) ?: $text;
        if ( strlen( $text ) > 120 ) {
            $text = substr( $text, 0, 117 ) . '...';
        }

        return '"' . str_replace( '"', '\"', $text ) . '"';
    }

    private static function encode_context_for_log( array $context ): string {
        $flags = JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR
            | JSON_PRESERVE_ZERO_FRACTION;

        $encoded = metis_json_encode( $context, $flags );
        if ( is_string( $encoded ) && $encoded !== '' ) {
            return $encoded;
        }

        $fallback = json_encode( $context, $flags );
        return is_string( $fallback ) ? $fallback : '';
    }

    private static function configured_timezone_name(): string {
        $timezone_name = 'UTC';
        if ( self::settings_service_available() ) {
            $configured_tz = (string) Core_Settings_Service::get( 'timezone', Core_Settings_Service::get( 'site_timezone', '' ) );
            if ( $configured_tz !== '' && in_array( $configured_tz, timezone_identifiers_list(), true ) ) {
                $timezone_name = $configured_tz;
            }
        }
        return $timezone_name;
    }

    private static function should_write_level( string $level ): bool {
        if ( ! self::logging_enabled() ) {
            return false;
        }

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
        if ( $token === '' || strlen( $token ) < 16 || ! preg_match( '/^[A-Za-z0-9._-]+$/', $token ) ) {
            return false;
        }

        $query_token = isset( $_GET['metis_log_token'] ) ? trim( metis_text_clean( (string) $_GET['metis_log_token'] ) ) : '';
        if ( $query_token !== '' ) {
            return hash_equals( $token, $query_token );
        }

        $header_token = trim( (string) ( $_SERVER['HTTP_X_METIS_LOG_TOKEN'] ?? '' ) );
        if ( $header_token !== '' ) {
            return hash_equals( $token, $header_token );
        }

        return false;
    }

    private static function settings_service_available(): bool {
        if ( ! class_exists( 'Core_Settings_Service' ) ) {
            $settings_path = __DIR__ . '/SettingsService.php';
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
