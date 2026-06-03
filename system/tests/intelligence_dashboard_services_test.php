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

$ranker = \Metis\Core\Application::service( 'intelligence_severity_ranker' );
$alertService = \Metis\Core\Application::service( 'intelligence_alerts' );
$integrationService = \Metis\Core\Application::service( 'intelligence_integration_failures' );
$recommendationService = \Metis\Core\Application::service( 'intelligence_recommendations' );
$trendService = \Metis\Core\Application::service( 'intelligence_diagnostic_trends' );
$operations = \Metis\Core\Application::service( 'hermes_operations_registry' )->definitions();
$moduleService = new \Metis\Intelligence\Services\ModuleHealthIntelligenceService(
    $ranker,
    static fn ( string $module, string $action ): bool => ! ( $module === 'board' && $action === 'view' )
);

$cron = [
    'tasks' => [
        [ 'health' => 'lagging', 'severity' => 'medium', 'module' => 'newsletter', 'label' => 'Digest sender', 'last_error' => '' ],
        [ 'health' => 'failed', 'severity' => 'high', 'module' => 'finance', 'label' => 'Finance sync', 'last_error' => 'Sync timeout' ],
    ],
];
$queue = [
    'failed_count' => 2,
    'queued_count' => 6,
    'processing_count' => 18,
];
$reconciliation = [
    'summary' => [
        'anomaly_count' => 1,
        'open_count' => 2,
        'variance_count' => 3,
    ],
];
$permissionIssues = [
    [
        'severity' => 'medium',
        'module_slug' => 'people',
        'title' => 'People permission mismatch',
        'summary' => 'Context pack coverage differs from the module manifest.',
    ],
    [
        'severity' => 'low',
        'module_slug' => 'board',
        'title' => 'Board restricted',
        'summary' => 'Restricted for the current operator.',
    ],
];
$diagnostics = [
    'findings' => [
        [
            'key' => 'board_workspace_health',
            'severity' => 'high',
            'title' => 'Board workspace integrity',
            'summary' => 'A board workspace is missing.',
            'evidence' => [ 'missing_workspaces' => 1 ],
        ],
    ],
];
$contextPacks = [
    [
        'key' => 'finance_overview',
        'module_slug' => 'finance',
        'title' => 'Finance',
        'description' => 'Finance module snapshot.',
        'available_actions' => [ 'reconcile' ],
        'diagnostics' => [ 'ledger drift' ],
        'common_operational_issues' => [ 'variance' ],
        'source_modules' => [ 'finance' ],
    ],
    [
        'key' => 'board_overview',
        'module_slug' => 'board',
        'title' => 'Board',
        'description' => 'Board workspace health.',
        'available_actions' => [ 'repair' ],
        'diagnostics' => [ 'workspace audit' ],
        'common_operational_issues' => [ 'workspace missing' ],
        'source_modules' => [ 'board' ],
    ],
];
$reports = [
    [
        'report_code' => 'REP-NEW',
        'updated_at' => '2026-06-02 08:00:00',
        'report_type' => 'diagnostic',
        'summary' => [
            'summary' => [ 'finding_count' => 4, 'high_severity' => 2 ],
            'findings' => [],
        ],
    ],
    [
        'report_code' => 'REP-OLD',
        'updated_at' => '2026-06-01 08:00:00',
        'report_type' => 'diagnostic',
        'summary' => [
            'findings' => [
                [ 'severity' => 'high' ],
                [ 'severity' => 'medium' ],
            ],
        ],
    ],
];

$alerts = $alertService->build( $cron, $queue, $reconciliation, $permissionIssues, $diagnostics );
$integrationFailures = $integrationService->build( $cron, $queue, $reconciliation, $permissionIssues, $diagnostics );
$moduleSummaries = $moduleService->build( $contextPacks, $alerts, $permissionIssues, $reconciliation, $queue, $diagnostics );
$recommendations = $recommendationService->build( $alerts, $integrationFailures, $moduleSummaries, $diagnostics, $operations );
$trends = $trendService->build( $reports );

$assert( isset( $alerts[0]['severity'] ) && (string) $alerts[0]['severity'] === 'high', 'Alert intelligence should sort highest severity alerts first.' );
$assert( count( $alerts ) === 6, 'Alert intelligence should produce queue, cron, reconciliation, permission, and board alerts across each matching rule surface.' );
$assert( count( $integrationFailures ) === 6, 'Integration-failure intelligence should mirror the deterministic dashboard failure surfaces.' );
$assert( count( $recommendations ) >= 4, 'Recommendation intelligence should synthesize next actions from the active dashboard evidence.' );
$assert( (string) ( $recommendations[0]['recommended_operation']['operation_key'] ?? '' ) === 'check_workers', 'Recommendation intelligence should expose a concrete worker diagnostic action first.' );
$assert( (string) ( $moduleSummaries[0]['module_slug'] ?? '' ) === 'finance', 'Module health should rank at-risk modules ahead of restricted modules.' );
$assert( (string) ( $moduleSummaries[1]['status'] ?? '' ) === 'restricted', 'Module health should mark inaccessible modules as restricted.' );
$assert( isset( $moduleSummaries[1]['live_diagnostic']['key'] ) && (string) ( $moduleSummaries[1]['live_diagnostic']['key'] ?? '' ) === 'board_workspace_health', 'Module health should attach live board diagnostics when present.' );
$assert( count( (array) ( $trends['points'] ?? [] ) ) === 2, 'Diagnostic trends should emit one point per report.' );
$assert( (string) ( $trends['points'][0]['report_code'] ?? '' ) === 'REP-OLD', 'Diagnostic trends should return oldest-first ordering for charting.' );
$assert( (int) ( $trends['max_finding_count'] ?? 0 ) === 4, 'Diagnostic trends should track the highest finding count.' );
$assert( (string) ( $trends['comparisons']['finding_count']['delta_label'] ?? '' ) === 'up 100.0% vs previous week', 'Diagnostic trends should expose centralized comparison labels.' );
$assert( (string) ( $trends['comparisons']['high_severity']['delta_class'] ?? '' ) === 'positive', 'Diagnostic trends should expose centralized comparison classes.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Intelligence dashboard service checks passed.\n" );
