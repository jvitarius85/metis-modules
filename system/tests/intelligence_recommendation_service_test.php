<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'ajax', 'router', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();
\Metis\Modules\Help\HelpModule::boot();
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$service = \Metis\Core\Application::service( 'intelligence_recommendations' );
$operations = \Metis\Core\Application::service( 'hermes_operations_registry' )->definitions();
$alerts = [
    [ 'severity' => 'high', 'module_slug' => 'hermes', 'title' => 'Worker queue pressure', 'summary' => 'Queue is backed up.' ],
    [ 'severity' => 'high', 'module_slug' => 'finance', 'title' => 'Reconciliation anomalies open', 'summary' => 'Finance anomalies are open.' ],
    [ 'severity' => 'high', 'module_slug' => 'board', 'title' => 'Board workspace integrity', 'summary' => 'Workspace is missing.' ],
    [ 'severity' => 'medium', 'module_slug' => 'newsletter', 'title' => 'Digest sender is lagging', 'summary' => 'Digest worker is lagging.' ],
    [ 'severity' => 'medium', 'module_slug' => 'people', 'title' => 'People permission mismatch', 'summary' => 'Permission map diverges.' ],
];
$integrationFailures = [
    [ 'severity' => 'high', 'title' => 'Hermes worker queue contains failed jobs', 'summary' => 'Failed jobs are present.', 'surface' => 'worker' ],
];
$moduleSummaries = [
    [ 'module_slug' => 'finance', 'status' => 'at-risk', 'severity' => 'high' ],
    [ 'module_slug' => 'newsletter', 'status' => 'monitoring', 'severity' => 'medium' ],
];
$diagnostics = [
    'findings' => [
        [ 'key' => 'board_workspace_health', 'severity' => 'high' ],
    ],
];

$recommendations = $service->build( $alerts, $integrationFailures, $moduleSummaries, $diagnostics, $operations );

$assert( count( $recommendations ) === 5, 'Recommendation intelligence should emit deterministic recommendations for each active rule surface.' );
$assert( (string) ( $recommendations[0]['recommended_operation']['operation_key'] ?? '' ) === 'check_workers', 'Recommendation intelligence should prioritize worker diagnostics when queue pressure exists.' );
$assert( (string) ( $recommendations[1]['recommended_operation']['operation_key'] ?? '' ) === 'run_full_diagnostics', 'Recommendation intelligence should map finance anomalies to a real Hermes operation.' );
$assert( (string) ( $recommendations[2]['recommended_operation']['operation_key'] ?? '' ) === 'scan_integrity', 'Recommendation intelligence should recommend integrity scans for board workspace drift.' );
$assert( (string) ( $recommendations[4]['recommended_operation']['operation_key'] ?? '' ) === 'audit_permissions', 'Recommendation intelligence should route permission issues to permission audits.' );
$assert( ! array_key_exists( 'supported', (array) ( $operations['service_restart'] ?? [] ) ) || empty( $operations['service_restart']['supported'] ), 'Recommendation intelligence fixture should expose blocked operations from the registry.' );
$assert( ! empty( $recommendations[0]['recommended_operation']['supported'] ), 'Recommendation intelligence should surface supported metadata for recommended operations.' );
$assert( (string) ( $recommendations[0]['recommended_operation']['unsupported_message'] ?? '' ) === '', 'Recommendation intelligence should not attach blocked-operation guidance to supported recommendations.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Intelligence recommendation service checks passed.\n" );
