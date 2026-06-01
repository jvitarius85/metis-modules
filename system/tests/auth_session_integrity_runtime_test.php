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

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/admin/contacts/contact/?cid=CON-489051';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'MetisSessionIntegrityTest/1.0';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$_SESSION['metis_auth_user_id'] = 101;
$_SESSION['metis_person_id'] = 202;
$_SESSION['metis_session_token'] = 'session-integrity-test-token';
$_SESSION['metis_auth_method'] = 'google_workspace';
$_SESSION['metis_user'] = [
    'ID' => 101,
    'person_id' => 202,
    'roles' => [ 'board' ],
];

metis_auth_refresh_session_integrity();

$runtime_context = metis_security_runtime_request_context();
$kernel = \Metis\Core\Application::service( 'security_kernel' );
$context = $kernel->buildContext(
    'route.portal_page.contacts.view',
    (array) ( $runtime_context['input'] ?? [] ),
    (array) ( $runtime_context['meta'] ?? [] ),
    (array) ( $runtime_context['actor'] ?? [] )
);

$assert(
    (string) ( $runtime_context['meta']['auth_method'] ?? '' ) === 'google_workspace',
    'Runtime request context must preserve the current session auth method.'
);
$assert(
    metis_auth_session_integrity_fingerprint() === (string) ( $_SESSION['metis_session_integrity'] ?? '' ),
    'Session integrity fingerprint refresh must preserve the active auth method.'
);
$assert(
    \Metis\Core\Application::service( 'security_authorization_gate' )->hasValidSession( $context ),
    'Authorization gate must accept keepalive/runtime contexts for non-password session auth methods.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Auth session integrity runtime checks passed.\n" );
