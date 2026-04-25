<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once dirname( __DIR__ ) . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( __DIR__, 2 ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'release' ] );

function metis_release_cli_usage(): never {
    $script = 'php ' . METIS_TOOLS_PATH . 'release.php';
    $lines = [
        'Metis release CLI',
        '',
        'Usage:',
        '  ' . $script . ' status [--refresh]',
        '  ' . $script . ' check [--refresh]',
        '  ' . $script . ' apply <tag>',
        '  ' . $script . ' rollback',
    ];

    fwrite( STDERR, implode( PHP_EOL, $lines ) . PHP_EOL );
    exit( 1 );
}

function metis_release_cli_boot(): void {
    if ( ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();
}

function metis_release_cli_print( array $result ): never {
    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ( ! is_string( $json ) || $json === '' ) {
        $json = json_encode(
            [
                'ok' => false,
                'status' => 'json_encode_failed',
                'message' => 'Release command completed, but the result could not be encoded safely.',
                'json_error' => json_last_error_msg(),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    echo ( is_string( $json ) && $json !== '' ? $json : '{"ok":false,"status":"json_encode_failed"}' ) . PHP_EOL;
    exit( ! empty( $result['ok'] ) ? 0 : 1 );
}

$command = $argv[1] ?? '';
if ( $command === '' || in_array( $command, [ '-h', '--help', 'help' ], true ) ) {
    metis_release_cli_usage();
}

try {
    metis_release_cli_boot();
} catch ( Throwable $throwable ) {
    metis_release_cli_print( [
        'ok' => false,
        'status' => 'boot_failed',
        'message' => $throwable->getMessage(),
    ] );
}

$force_refresh = in_array( '--refresh', $argv, true );

try {
    switch ( $command ) {
        case 'status':
            metis_release_cli_print( metis_release_status( $force_refresh ) );

        case 'check':
            metis_release_cli_print( metis_release_check_for_updates( $force_refresh, 'cli' ) );

        case 'apply':
            $tag = (string) ( $argv[2] ?? '' );
            if ( trim( $tag ) === '' ) {
                metis_release_cli_print( [
                    'ok' => false,
                    'status' => 'invalid_tag',
                    'message' => 'A release tag is required.',
                ] );
            }

            metis_release_cli_print( metis_release_apply( $tag, 'cli' ) );

        case 'rollback':
            metis_release_cli_print( metis_release_rollback( 'cli' ) );

        default:
            fwrite( STDERR, 'Unknown command: ' . $command . PHP_EOL );
            metis_release_cli_usage();
    }
} catch ( Throwable $throwable ) {
    metis_release_cli_print( [
        'ok' => false,
        'status' => 'exception',
        'message' => $throwable->getMessage(),
        'exception' => get_class( $throwable ),
    ] );
}
