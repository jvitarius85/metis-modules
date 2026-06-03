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

$service = \Metis\Core\Application::service( 'intelligence_trends' );
$comparisons = $service->supportedComparisons();
$month = $service->compareValues( 200.0, 100.0, 'month_over_month' );
$series = $service->compareSeries(
    [
        [ 'key' => '2026-04', 'amount' => 100.0 ],
        [ 'key' => '2026-05', 'amount' => 150.0 ],
    ],
    'amount',
    'month_over_month'
);
$quarterWindows = $service->resolveWindows(
    'quarter_over_quarter',
    new \DateTimeImmutable( '2026-06-02 12:00:00', new \DateTimeZone( 'UTC' ) )
);
$yearComparison = $service->compareValues( 0.0, null, 'yoy' );

$assert( isset( $comparisons['day_over_day'] ) && isset( $comparisons['year_over_year'] ), 'Trend service should expose the supported comparison modes.' );
$assert( (string) ( $month['delta_label'] ?? '' ) === 'up 100.0% vs previous month', 'Trend service should format month-over-month comparison labels.' );
$assert( (string) ( $month['delta_class'] ?? '' ) === 'positive', 'Trend service should classify positive comparison deltas.' );
$assert( (float) ( $series['delta_percent'] ?? 0.0 ) === 50.0, 'Trend service should compare the last two series points.' );
$assert( (string) ( $quarterWindows['current']['from'] ?? '' ) === '2026-04-01 00:00:00', 'Trend service should resolve the current quarter start.' );
$assert( (string) ( $quarterWindows['previous']['from'] ?? '' ) === '2026-01-01 00:00:00', 'Trend service should resolve the previous quarter start.' );
$assert( (string) ( $yearComparison['delta_label'] ?? '' ) === 'No prior year', 'Trend service should report missing prior periods explicitly.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Intelligence trend service checks passed.\n" );
