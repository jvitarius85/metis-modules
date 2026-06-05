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

$memory = \Metis\Core\Application::service( 'hermes_memory_store' );
$engine = \Metis\Core\Application::service( 'hermes_disambiguation' );
$db = \Metis\Core\Application::service( 'db' );

$sessionCode = 'TESTDIS' . strtoupper( substr( md5( uniqid( 'dis', true ) ), 0, 8 ) );
$session = [ 'session_code' => $sessionCode ];

$engine->rememberIfApplicable(
    $session,
    [
        'intent' => [
            'payload' => [
                'attribute_request' => [
                    'subject' => 'Meg',
                    'attribute' => 'email',
                    'entity_hint' => 'person',
                ],
            ],
        ],
        'parsed' => [
            'normalized_input' => 'what is meg email',
        ],
    ],
    [
        'response_type' => 'Disambiguation',
        'candidates' => [
            [ 'entity_type' => 'person', 'name' => 'Megan Smith', 'email' => 'megan.smith@example.org' ],
            [ 'entity_type' => 'person', 'name' => 'Megan Jones', 'email' => 'megan.jones@example.org' ],
        ],
    ]
);

$stored = $memory->recallPendingDisambiguation( $sessionCode );
$assert( (string) ( $stored['contents']['attribute_request']['subject'] ?? '' ) === 'Meg', 'Disambiguation memory should persist the original attribute request.' );

$selected = $engine->continueIfApplicable( '2', $session );
$assert( (string) ( $selected['kind'] ?? '' ) === 'entity_attribute', 'Numeric reply should continue the entity disambiguation flow.' );
$assert( (string) ( $selected['attribute_request']['subject'] ?? '' ) === 'Megan Jones', 'Numeric reply should map to the selected candidate subject.' );
$assert( $memory->recallPendingDisambiguation( $sessionCode ) === [], 'Successful disambiguation should clear pending state.' );

$invalidSessionCode = 'TESTDIS' . strtoupper( substr( md5( uniqid( 'inv', true ) ), 0, 8 ) );
$invalidSession = [ 'session_code' => $invalidSessionCode ];
$memory->rememberPendingDisambiguation( $invalidSessionCode, [
    'kind' => 'entity_attribute',
    'query' => 'what is meg email',
    'attribute_request' => [
        'subject' => 'Meg',
        'attribute' => 'email',
        'entity_hint' => 'person',
    ],
    'candidates' => [
        [ 'entity_type' => 'person', 'name' => 'Megan Smith', 'email' => 'megan.smith@example.org' ],
        [ 'entity_type' => 'person', 'name' => 'Megan Jones', 'email' => 'megan.jones@example.org' ],
    ],
]);

$invalid = $engine->continueIfApplicable( 'someone else', $invalidSession );
$assert( (string) ( $invalid['response']['status'] ?? '' ) === 'disambiguation_required', 'Invalid replies should keep the disambiguation prompt active.' );
$assert( str_contains( (string) ( $invalid['response']['message'] ?? '' ), 'Which person would you like?' ), 'Invalid disambiguation replies should repeat the candidate prompt.' );

$profileSessionCode = 'TESTDIS' . strtoupper( substr( md5( uniqid( 'pro', true ) ), 0, 8 ) );
$profileSession = [ 'session_code' => $profileSessionCode ];
$engine->rememberIfApplicable(
    $profileSession,
    [
        'intent' => [
            'payload' => [
                'profile_request' => [
                    'subject' => 'Brittany',
                    'entity_hint' => 'auto',
                ],
            ],
        ],
        'parsed' => [
            'normalized_input' => 'who is brittany',
        ],
    ],
    [
        'response_type' => 'Disambiguation',
        'candidates' => [
            [ 'entity_type' => 'donor', 'name' => 'Brittany Attwood', 'email' => 'brittany@example.org' ],
            [ 'entity_type' => 'contact', 'name' => 'Brittany Wallace', 'email' => 'brittany.wallace@example.org' ],
        ],
    ]
);

$profileSelected = $engine->continueIfApplicable( '2', $profileSession );
$assert( (string) ( $profileSelected['kind'] ?? '' ) === 'lookup_profile', 'Numeric reply should continue the profile lookup disambiguation flow.' );
$assert( (string) ( $profileSelected['profile_request']['subject'] ?? '' ) === 'Brittany Wallace', 'Profile disambiguation should map the reply to the selected candidate subject.' );
$assert( (string) ( $profileSelected['profile_request']['entity_hint'] ?? '' ) === 'contact', 'Profile disambiguation should preserve the selected entity type.' );
$assert( $memory->recallPendingDisambiguation( $profileSessionCode ) === [], 'Successful profile disambiguation should clear pending state.' );

$expiredSessionCode = 'TESTDIS' . strtoupper( substr( md5( uniqid( 'exp', true ) ), 0, 8 ) );
$expiredSession = [ 'session_code' => $expiredSessionCode ];
$memory->rememberPendingDisambiguation( $expiredSessionCode, [
    'kind' => 'entity_attribute',
    'query' => 'what is meg email',
    'attribute_request' => [
        'subject' => 'Meg',
        'attribute' => 'email',
        'entity_hint' => 'person',
    ],
    'candidates' => [
        [ 'entity_type' => 'person', 'name' => 'Megan Smith', 'email' => 'megan.smith@example.org' ],
    ],
]);

$db->update(
    \Metis_Tables::get( 'hermes_memory' ),
    [ 'updated_at' => date( 'Y-m-d H:i:s', time() - 1200 ) ],
    [ 'memory_key' => 'disambiguation:' . $expiredSessionCode ],
    [ '%s' ],
    [ '%s' ]
);

$expired = $engine->continueIfApplicable( '1', $expiredSession );
$assert( (string) ( $expired['response']['status'] ?? '' ) === 'workflow_expired', 'Expired disambiguation prompts should not continue.' );
$assert( $memory->recallPendingDisambiguation( $expiredSessionCode ) === [], 'Expired disambiguation prompts should be cleared.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes disambiguation engine checks passed.\n" );
