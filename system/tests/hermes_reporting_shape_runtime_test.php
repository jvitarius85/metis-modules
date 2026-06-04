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
        if ( str_contains( $sql, 'FROM metis_people' ) ) {
            return 1;
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

        if ( str_contains( $sql, 'GROUP BY t.did' ) && str_contains( $sql, 'total_raised' ) ) {
            return [
                [
                    'did' => 'D-100',
                    'donor_name' => 'JD Vitarius',
                    'email' => 'jd@example.org',
                    'gift_count' => 4,
                    'total_raised' => 1500.00,
                    'last_gift_date' => '2026-05-31 09:30:00',
                ],
                [
                    'did' => 'D-101',
                    'donor_name' => 'Ada Lovelace',
                    'email' => 'ada@example.org',
                    'gift_count' => 3,
                    'total_raised' => 1200.00,
                    'last_gift_date' => '2026-05-30 10:15:00',
                ],
            ];
        }

        if ( str_contains( $sql, 'GROUP BY COALESCE(c.cid, t.campaign_code)' ) ) {
            return [
                [
                    'campaign_uid' => 'C-2026-BOARD',
                    'name' => 'Board Campaign',
                    'gift_count' => 6,
                    'total_raised' => 3200.00,
                    'last_gift_date' => '2026-05-31 09:30:00',
                ],
                [
                    'campaign_uid' => 'C-2026-GENERAL',
                    'name' => 'General Fund',
                    'gift_count' => 3,
                    'total_raised' => 1200.00,
                    'last_gift_date' => '2026-05-30 10:15:00',
                ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_people' ) ) {
            return [
                [
                    'total' => 1,
                ],
            ];
        }

        return [];
    }
}

\Metis\Core\Application::instance( 'db', new HermesReportingShapeFakeDb() );
\Metis\Core\Application::instance( 'authz', new class() {
    public function allows( string $permission, int $userId = 0, array $context = [] ): bool {
        return true;
    }
} );

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
$assert( (string) ( $donations['response']['message'] ?? '' ) === 'Showing the last 2 donations.', 'Donation history prompt should describe the bounded donation list.' );
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
$assert( (string) ( $campaign['response']['message'] ?? '' ) === 'The current campaign is Board Campaign.', 'Current campaign prompt should answer with the current campaign name.' );
$assert( (string) ( $campaignReport['entity'] ?? '' ) === 'donation_campaign', 'Current campaign prompt should resolve the donation campaign entity.' );
$assert( (string) ( $firstCampaign['name'] ?? '' ) === 'Board Campaign', 'Current campaign rows should expose the campaign name.' );
$assert( (string) ( $firstCampaign['status'] ?? '' ) === 'active', 'Current campaign rows should expose a normalized status.' );

$topDonors = $engine->process( 'who are the top 5 donors last month?' );
$assert( (string) ( $topDonors['response']['status'] ?? '' ) === 'success', 'Top donors prompt should execute successfully.' );
$topDonorsReport = (array) ( $topDonors['response']['report'] ?? [] );
$topDonorsRows = array_values( (array) ( $topDonorsReport['data'] ?? [] ) );
$topDonorsMessage = (string) ( $topDonors['response']['message'] ?? '' );
$assert( (string) ( $topDonorsReport['entity'] ?? '' ) === 'donor', 'Top donors prompt should resolve the donor reporting entity.' );
$assert( count( $topDonorsRows ) === 2, 'Top donors prompt should return ranked donor rows.' );
$assert( str_starts_with( $topDonorsMessage, 'Top donors:' ), 'Top donors prompt should summarize ranked donor names and totals.' );
$assert( str_contains( $topDonorsMessage, 'JD Vitarius' ), 'Top donors prompt should name the first ranked donor.' );
$assert( str_contains( $topDonorsMessage, 'Ada Lovelace' ), 'Top donors prompt should name the second ranked donor.' );

$bestCampaign = $engine->process( 'which campaign performed best?' );
$bestCampaignReport = (array) ( $bestCampaign['response']['report'] ?? [] );
$bestCampaignRows = array_values( (array) ( $bestCampaignReport['data'] ?? [] ) );
$firstBestCampaign = (array) ( $bestCampaignRows[0] ?? [] );
$assert( (string) ( $bestCampaign['response']['status'] ?? '' ) === 'success', 'Best campaign prompt should execute successfully.' );
$assert( (string) ( $bestCampaign['response']['message'] ?? '' ) === 'The best-performing campaign is Board Campaign ($3,200.00).', 'Best campaign prompt should answer with the top campaign and total raised.' );
$assert( (string) ( $bestCampaignReport['entity'] ?? '' ) === 'donation_campaign', 'Best campaign prompt should resolve the donation campaign reporting entity.' );
$assert( (string) ( $firstBestCampaign['name'] ?? '' ) === 'Board Campaign', 'Best campaign rows should expose the ranked campaign name.' );
$assert( (float) ( $firstBestCampaign['total_raised'] ?? 0 ) === 3200.00, 'Best campaign rows should expose the ranked campaign total.' );

$highestGrossingCampaign = $engine->process( 'which campaign raised the most money?' );
$assert( (string) ( $highestGrossingCampaign['response']['status'] ?? '' ) === 'success', 'Highest-grossing campaign prompt should execute successfully.' );
$assert( (string) ( $highestGrossingCampaign['response']['message'] ?? '' ) === 'The best-performing campaign is Board Campaign ($3,200.00).', 'Highest-grossing campaign prompt should answer with the top campaign and total raised.' );

$lastDonationPrompt = $engine->process( 'who made the last donation?' );
$lastDonationPromptReport = (array) ( $lastDonationPrompt['response']['report'] ?? [] );
$lastDonationPromptRows = array_values( (array) ( $lastDonationPromptReport['data'] ?? [] ) );
$assert( (string) ( $lastDonationPrompt['response']['status'] ?? '' ) === 'success', 'Last donor prompt should execute successfully.' );
$assert( (string) ( $lastDonationPrompt['response']['message'] ?? '' ) === 'JD Vitarius made the last donation of $250.00 to Board Campaign on 2026-06-01 09:30:00.', 'Last donor prompt should answer with donor, amount, campaign, and date.' );
$assert( count( $lastDonationPromptRows ) >= 1, 'Last donor prompt should still return donation rows for the UI.' );

$userCount = $engine->process( 'how many users are there?' );
$assert( (string) ( $userCount['response']['status'] ?? '' ) === 'success', 'User count prompt should execute successfully.' );
$assert( (string) ( $userCount['response']['message'] ?? '' ) === 'Found 1 user.', 'User count prompt should answer in user terms, not person terms.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes reporting shape runtime checks passed.\n" );
