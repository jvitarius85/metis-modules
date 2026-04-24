<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry' ] );

function metis_module_compliance_cli_usage(): never {
    $script = 'php ' . METIS_TOOLS_PATH . 'module_compliance.php';
    $lines = [
        'Metis module compliance CLI',
        '',
        'Usage:',
        '  ' . $script . ' verify [--refresh]',
        '  ' . $script . ' report [--refresh]',
        '',
        'Commands:',
        '  verify  Run module compliance checks and fail with exit code 1 when modules are non-compliant.',
        '  report  Print the current module compliance report without enforcing exit failure.',
    ];

    fwrite( STDERR, implode( PHP_EOL, $lines ) . PHP_EOL );
    exit( 1 );
}

/**
 * @return array{
 *   ok:bool,
 *   status:string,
 *   summary:array<string,mixed>,
 *   failures:array<int,array<string,mixed>>,
 *   report:array<string,mixed>,
 *   checked_at:string
 * }
 */
function metis_module_compliance_cli_verify( bool $force_refresh ): array {
    $loader = new \Metis\Core\ModuleLoader();
    $report = (array) $loader->complianceReport( $force_refresh );
    $summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : [];
    $results = is_array( $report['results'] ?? null ) ? $report['results'] : [];
    $failures = array_values(
        array_filter(
            $results,
            static fn ( mixed $row ): bool => is_array( $row ) && (string) ( $row['status'] ?? '' ) === 'failed'
        )
    );

    $failed = (int) ( $summary['failed'] ?? count( $failures ) );

    return [
        'ok' => $failed < 1,
        'status' => $failed < 1 ? 'ok' : 'failed',
        'summary' => [
            'checked' => (int) ( $summary['checked'] ?? 0 ),
            'failed' => $failed,
            'passed' => (int) ( $summary['passed'] ?? 0 ),
        ],
        'failures' => $failures,
        'report' => $report,
        'checked_at' => gmdate( 'c' ),
    ];
}

$command = (string) ( $argv[1] ?? 'verify' );
if ( in_array( $command, [ '-h', '--help', 'help' ], true ) ) {
    metis_module_compliance_cli_usage();
}

$force_refresh = in_array( '--refresh', $argv, true );

switch ( $command ) {
    case 'verify':
        $result = metis_module_compliance_cli_verify( $force_refresh );
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( ! empty( $result['ok'] ) ? 0 : 1 );

    case 'report':
        $loader = new \Metis\Core\ModuleLoader();
        $report = (array) $loader->complianceReport( $force_refresh );
        echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( 0 );

    default:
        fwrite( STDERR, 'Unknown command: ' . $command . PHP_EOL );
        metis_module_compliance_cli_usage();
}
