<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', $root . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( $root . '/' );
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

$store = new \Metis\Core\HelpSearchStore();
$store->ensureSeeded();

$resolver = \Metis\Core\Application::service( 'hermes_help_issue_resolver' );
$result = $resolver->resolve( "I can't create a new GL entry", 0, '/admin/finance', 'finance', [ 'session_code' => 'TESTHELP001' ] );
$assert( (string) ( $result['classification'] ?? '' ) === 'WORKFLOW', 'GL entry issue should classify as WORKFLOW.' );
$assert( (string) ( $result['module'] ?? '' ) === 'Accounting', 'GL entry issue should resolve to Accounting.' );
$assert( (string) ( $result['action'] ?? '' ) === 'create_gl_entry', 'GL entry issue should resolve to create_gl_entry.' );
$assert( (string) ( $result['confidence'] ?? '' ) === 'high', 'GL entry issue should resolve with high confidence.' );
$assert( ! empty( $result['steps'] ), 'GL entry issue should return resolution steps.' );
$assert( str_contains( (string) ( $result['formatted_response'] ?? '' ), '## Step-by-step fix' ), 'Formatted response should include step-by-step fix section.' );
$assert( ! empty( $result['guidance_links'] ), 'GL entry issue should include in-app guidance links.' );
$assert( (string) ( $result['guidance_links'][0]['topic'] ?? '' ) === 'finance.gl_entry', 'GL entry guidance should point to the finance GL entry topic.' );

$donation = $resolver->resolve( "I don't see the new donation button", 0, '/admin/donations', 'donations', [] );
$assert( (string) ( $donation['classification'] ?? '' ) === 'PERMISSION', 'Donation button issue should classify as PERMISSION.' );

$page = $resolver->resolve( "I can't publish a page", 0, '/admin/website', 'website', [] );
$assert( (string) ( $page['action'] ?? '' ) === 'publish_page', 'Publish a page issue should resolve to publish_page.' );

$newsletter = $resolver->resolve( "The newsletter test email won't send", 0, '/admin/newsletter', 'newsletter', [] );
$assert( (string) ( $newsletter['classification'] ?? '' ) === 'SYSTEM', 'Newsletter test send issue should classify as SYSTEM.' );
$assert( ! empty( $newsletter['proposed_actions'] ), 'Newsletter issue should expose proposed actions.' );
$payload = (array) ( $newsletter['proposed_actions'][0]['enclave_payload'] ?? [] );
$assert( (string) ( $payload['action'] ?? '' ) === 'hermes.resolve_help_issue', 'Proposed action payload should use hermes.resolve_help_issue action.' );

$report = $resolver->resolve( "I can't run a report", 0, '/admin/reports', 'reports', [] );
$assert( (string) ( $report['action'] ?? '' ) === 'run_report', 'Report issue should resolve to run_report.' );

$instructional = $resolver->resolve( 'how do I create a new donation?', 0, '/admin/donations', 'donations', [] );
$assert( (string) ( $instructional['action'] ?? '' ) === 'create_donation', 'Instructional donation question should resolve to create_donation.' );
$assert( ! empty( $instructional['related_articles'] ), 'Instructional donation question should return related help articles.' );
$assert( ! empty( $instructional['guidance_links'] ), 'Instructional donation question should include guidance links.' );
$assert( (string) ( $instructional['guidance_links'][0]['walkthrough_id'] ?? '' ) === 'donations_create_donation', 'Instructional donation question should offer donation walkthrough guidance.' );
$assert( (string) ( $instructional['response_mode'] ?? '' ) === 'instructional', 'How-do requests should use instructional response mode.' );
$assert( (string) ( $instructional['section_labels']['steps'] ?? '' ) === 'Step-by-step instructions', 'Instructional responses should use instructional section labels.' );
$assert( ! str_contains( (string) ( $instructional['formatted_response'] ?? '' ), '## Step-by-step fix' ), 'Instructional responses should not use troubleshooting headers.' );

$search = $store->search( "newsletter test email won't send", '', 5, 1, false );
$topTitle = strtolower( (string) ( $search['results'][0]['title'] ?? '' ) );
$assert( str_contains( $topTitle, 'test email' ), 'Help search should prioritize the test email issue article for natural-language phrase matches.' );

$logs = \Metis\Core\Application::service( 'hermes_repository' )->recentHelpIssueLogs( 5 );
$assert( $logs !== [], 'Help issue resolutions should be logged.' );
$foundLoggedIssue = false;
foreach ( $logs as $log ) {
    $normalizedIssue = strtolower( (string) ( $log['normalized_issue'] ?? '' ) );
    if ( str_contains( $normalizedIssue, "can't run a report" ) || str_contains( $normalizedIssue, 'newsletter test email' ) || str_contains( $normalizedIssue, 'create a new donation' ) ) {
        $foundLoggedIssue = true;
        break;
    }
}
$assert( $foundLoggedIssue, 'Recent help issue logs should capture normalized issue text.' );

$session = \Metis\Core\Application::service( 'hermes_repository' )->ensureSession( 0, '', 'History Contract' );
\Metis\Core\Application::service( 'hermes_repository' )->saveMessage(
    (int) ( $session['id'] ?? 0 ),
    'hermes',
    'History payload',
    [
        'answer' => 'History payload',
        'structured' => [
            'status' => 'success',
            'result' => [
                'summary' => 'I can walk you through that.',
                'related_articles' => [],
                'guidance_links' => [
                    [
                        'label' => 'Open Website Pages',
                        'url' => '/admin/website/pages/',
                    ],
                ],
            ],
        ],
    ]
);
$history = \Metis\Core\Application::service( 'hermes_repository' )->sessionMessages( (int) ( $session['id'] ?? 0 ), 5 );
$last = (array) ( end( $history ) ?: [] );
$assert( ! empty( $last['metadata'] ), 'Session history should decode stored Hermes metadata.' );
$assert( (string) ( $last['metadata']['structured']['result']['guidance_links'][0]['label'] ?? '' ) === 'Open Website Pages', 'Decoded history should preserve guidance link metadata.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes help issue resolution checks passed.\n" );
