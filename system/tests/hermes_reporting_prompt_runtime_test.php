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
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$engine = \Metis\Core\Application::service( 'hermes_operational_engine' );

$topDonors = $engine->process( 'Who were the top 5 donors last month?' );
$topDonorsResponse = (array) ( $topDonors['response'] ?? [] );
$topDonorsReport = (array) ( $topDonorsResponse['report'] ?? [] );
$assert( (string) ( $topDonorsResponse['status'] ?? '' ) === 'success', 'Top donors prompt should execute through the reporting path.' );
$assert( (string) ( $topDonors['intent']['action'] ?? '' ) === 'top', 'Top donors prompt should preserve the top data action.' );
$assert( (string) ( $topDonorsReport['entity'] ?? '' ) === 'donor', 'Top donors prompt should resolve the donor reporting entity.' );
$assert( (string) ( $topDonorsReport['report_type'] ?? '' ) === 'top', 'Top donors prompt should return a top-style reporting result.' );

$currentCampaign = $engine->process( 'What is the current campaign?' );
$currentCampaignResponse = (array) ( $currentCampaign['response'] ?? [] );
$currentCampaignReport = (array) ( $currentCampaignResponse['report'] ?? [] );
$assert( (string) ( $currentCampaignResponse['status'] ?? '' ) === 'success', 'Current campaign prompt should execute through the reporting path.' );
$assert( (string) ( $currentCampaign['intent']['action'] ?? '' ) === 'list', 'Current campaign prompt should resolve to a bounded list data action.' );
$assert( (string) ( $currentCampaignReport['entity'] ?? '' ) === 'donation_campaign', 'Current campaign prompt should resolve the donation campaign reporting entity.' );
$assert( (int) ( $currentCampaignReport['limit'] ?? 0 ) === 1, 'Current campaign prompt should request a single latest record.' );

$lastDonation = $engine->process( 'What was the last donation?' );
$lastDonationResponse = (array) ( $lastDonation['response'] ?? [] );
$lastDonationReport = (array) ( $lastDonationResponse['report'] ?? [] );
$assert( (string) ( $lastDonationResponse['status'] ?? '' ) === 'success', 'Last donation prompt should execute through the reporting path.' );
$assert( (string) ( $lastDonation['intent']['action'] ?? '' ) === 'list', 'Last donation prompt should resolve to a bounded list data action.' );
$assert( (string) ( $lastDonationReport['entity'] ?? '' ) === 'donation_transaction', 'Last donation prompt should resolve the donation transaction reporting entity.' );
$assert( (int) ( $lastDonationReport['limit'] ?? 0 ) === 1, 'Last donation prompt should request a single latest record.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes reporting prompt runtime checks passed.\n" );
