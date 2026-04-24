<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( __DIR__ ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once dirname( __DIR__ ) . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( __DIR__ ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'release' ] );

function metis_release_cli_usage(): never {
    $script = 'php ' . METIS_PATH . 'tools/release.php';
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

$command = $argv[1] ?? '';
if ( $command === '' || in_array( $command, [ '-h', '--help', 'help' ], true ) ) {
    metis_release_cli_usage();
}

metis_release_cli_boot();

$force_refresh = in_array( '--refresh', $argv, true );

switch ( $command ) {
    case 'status':
        $result = metis_release_status( $force_refresh );
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( ! empty( $result['ok'] ) ? 0 : 1 );

    case 'check':
        $result = metis_release_check_for_updates( $force_refresh, 'cli' );
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( ! empty( $result['ok'] ) ? 0 : 1 );

    case 'apply':
        $tag = (string) ( $argv[2] ?? '' );
        if ( trim( $tag ) === '' ) {
            fwrite( STDERR, "A release tag is required.\n" );
            exit( 1 );
        }

        $result = metis_release_apply( $tag, 'cli' );
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( ! empty( $result['ok'] ) ? 0 : 1 );

    case 'rollback':
        $result = metis_release_rollback( 'cli' );
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( ! empty( $result['ok'] ) ? 0 : 1 );

    default:
        fwrite( STDERR, 'Unknown command: ' . $command . PHP_EOL );
        metis_release_cli_usage();
}
