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

final class HermesReportingShapeFakeDb {
    public function scalar( string $sql, array $params = [] ): int {
        if ( str_contains( $sql, 'FROM metis_transactions t' ) ) {
            return 89;
        }
        if ( str_contains( $sql, 'FROM metis_campaigns' ) ) {
            return 4;
        }

        return 0;
    }

    public function fetchAll( string $sql, array $params = [] ): array {
        if ( str_contains( $sql, 'COALESCE(NULLIF(TRIM(CONCAT' ) && str_contains( $sql, 'FROM metis_transactions t' ) ) {
            return [
                [
                    'id' => 101,
                    'transaction_uid' => 'T-101',
                    'transaction_date' => '2026-06-01 09:30:00',
                    'amount' => 250.00,
                    'status' => 'completed',
                    'payment_method' => 'card',
                    'donor_code' => 'D-100',
                    'campaign_code' => 'C-2026-BOARD',
                    'donor_name' => 'JD Vitarius',
                    'campaign_name' => 'Board Campaign',
                ],
                [
                    'id' => 100,
                    'transaction_uid' => 'T-100',
                    'transaction_date' => '2026-05-30 14:15:00',
                    'amount' => 100.00,
                    'status' => 'completed',
                    'payment_method' => 'ach',
                    'donor_code' => 'D-101',
                    'campaign_code' => 'C-2026-GENERAL',
                    'donor_name' => 'Ada Lovelace',
                    'campaign_name' => 'General Fund',
                ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_campaigns' ) ) {
            return [
                [
                    'campaign_uid' => 'C-2026-BOARD',
                    'name' => 'Board Campaign',
                    'active' => 1,
                    'goal' => 5000.00,
                    'created_at' => '2026-05-01 08:00:00',
                ],
            ];
        }

        return [];
    }
}

\Metis\Core\Application::instance( 'db', new HermesReportingShapeFakeDb() );

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$engine = \Metis\Core\Application::service( 'hermes_operational_engine' );

$donations = $engine->process( 'what are the last 5 donations' );
$donationsReport = (array) ( $donations['response']['report'] ?? [] );
$donationRows = array_values( (array) ( $donationsReport['data'] ?? [] ) );
$firstDonation = (array) ( $donationRows[0] ?? [] );

$assert( (string) ( $donations['response']['status'] ?? '' ) === 'success', 'Donation history prompt should execute successfully.' );
$assert( (int) ( $donations['intent']['limit'] ?? 0 ) === 5, 'Donation history prompt should preserve a last-5 limit instead of collapsing to one record.' );
$assert( (string) ( $donationsReport['entity'] ?? '' ) === 'donation_transaction', 'Donation history prompt should resolve the donation transaction entity.' );
$assert( (string) ( $firstDonation['donor_name'] ?? '' ) === 'JD Vitarius', 'Donation history rows should expose the donor name.' );
$assert( (string) ( $firstDonation['transaction_date'] ?? '' ) === '2026-06-01 09:30:00', 'Donation history rows should expose the donation date.' );
$assert( (string) ( $firstDonation['campaign_name'] ?? '' ) === 'Board Campaign', 'Donation history rows should expose the campaign name.' );
$assert( (float) ( $firstDonation['amount'] ?? 0 ) === 250.00, 'Donation history rows should expose the donation amount.' );

$campaign = $engine->process( 'what is the current campaign' );
$campaignReport = (array) ( $campaign['response']['report'] ?? [] );
$campaignRows = array_values( (array) ( $campaignReport['data'] ?? [] ) );
$firstCampaign = (array) ( $campaignRows[0] ?? [] );

$assert( (string) ( $campaign['response']['status'] ?? '' ) === 'success', 'Current campaign prompt should execute successfully.' );
$assert( (string) ( $campaignReport['entity'] ?? '' ) === 'donation_campaign', 'Current campaign prompt should resolve the donation campaign entity.' );
$assert( (string) ( $firstCampaign['name'] ?? '' ) === 'Board Campaign', 'Current campaign rows should expose the campaign name.' );
$assert( (string) ( $firstCampaign['status'] ?? '' ) === 'active', 'Current campaign rows should expose a normalized status.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes reporting shape runtime checks passed.\n" );
