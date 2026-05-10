<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

if ( ! defined( 'METIS_ROOT' ) ) {
    define( 'METIS_ROOT', dirname( __DIR__ ) );
}

if ( ! class_exists( 'Core_Settings_Service', false ) ) {
    final class Core_Settings_Service {
        public static array $settings = [
            'timezone' => 'America/Chicago',
            'site_timezone' => 'UTC',
            'date_format' => 'Y.m.d',
            'time_format' => 'H:i',
        ];

        public static function get( string $key, mixed $default = null ): mixed {
            return self::$settings[ $key ] ?? $default;
        }
    }
}

function metis_runtime_get_option( string $key, mixed $default = null ): mixed {
    return $default;
}

function metis_on( string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return true;
}

require_once dirname( __DIR__ ) . '/src/Metis/Core/Runtime/HelpersRuntime.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert( metis_runtime_timezone_name() === 'America/Chicago', 'Configured timezone should resolve from Core_Settings_Service.' );
$assert( metis_runtime_date_format() === 'Y.m.d', 'Configured date format should resolve from Core_Settings_Service.' );
$assert( metis_runtime_time_format() === 'H:i', 'Configured time format should resolve from Core_Settings_Service.' );
$assert(
    metis_runtime_format_datetime( '2026-05-10 14:30:00' ) === '2026.05.10 14:30',
    'Naive Metis timestamps should render in the configured timezone and format.'
);
$assert(
    metis_runtime_format_datetime( '2026-05-10T19:30:00+00:00' ) === '2026.05.10 14:30',
    'Timezone-aware timestamps should convert to the configured display timezone.'
);
$assert(
    preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) metis_runtime_current_time( 'Y-m-d' ) ) === 1,
    'metis_current_time-compatible custom formats must return formatted dates, not raw timestamps.'
);

metis_runtime_sync_default_timezone();
$assert( date_default_timezone_get() === 'America/Chicago', 'PHP default timezone should sync to the configured Metis timezone.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Runtime time settings checks passed.\n" );
