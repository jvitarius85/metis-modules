<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');

$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_USER_AGENT'] = 'RouterSecurityTest/1.0';
$GLOBALS['metis_runtime_config'] = [
    'app_key' => 'test-app-key',
    'base_path' => '/metis',
    'csrf_ttl' => 300,
];

if (!class_exists('Metis_Cron_Manager')) {
    final class Metis_Cron_Manager {
        public static function register_task(string $slug, callable $callback, array $config = []): void {}
        public static function matches_request(Metis_Http_Request $request): bool { return false; }
    }
}

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap([
    'standalone_runtime',
    'http',
    'security_enclave',
    'security_runtime_bridge',
    'auth',
    'ajax',
    'router',
]);

function assert_true(bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = 'Values are not equal'): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function run_request_security(Metis_Http_Request $request): Metis_Http_Response {
    return metis_wp_router_require_request_security(
        $request,
        static fn (Metis_Http_Request $resolved): Metis_Http_Response => Metis_Http_Response::json([
            'success' => true,
            'data' => [
                'validated' => (array) $resolved->attribute('request_security', []),
            ],
        ])
    );
}

function seed_authenticated_session(string $sessionToken): void {
    $_SESSION['metis_user'] = [
        'ID' => 1,
        'user_login' => 'admin',
        'user_email' => 'admin@example.test',
        'display_name' => 'Admin',
        'first_name' => 'Admin',
        'last_name' => 'User',
        'roles' => ['administrator'],
        'user_pass' => '',
    ];
    $_SESSION['metis_auth_user_id'] = 1;
    $_SESSION['metis_person_id'] = 1;
    $_SESSION['metis_session_token'] = $sessionToken;
    metis_auth_refresh_session_integrity();
}

$tests = [];

$tests['ajax_request_accepts_action_scoped_nonce'] = function (): void {
    seed_authenticated_session('router-session');

    $request = new Metis_Http_Request(
        'POST',
        '/api/ajax',
        '/api/ajax',
        [],
        [
            'action' => 'metis_profile_save',
            'metis_action_nonce' => metis_create_nonce(metis_ajax_nonce_action('metis_profile_save')),
        ],
        [
            'origin' => 'https://example.test/metis/profile',
            'x-requested-with' => 'XMLHttpRequest',
            'x-metis-csrf-token' => metis_create_nonce(metis_ajax_nonce_action('metis_profile_save')),
        ]
    );

    $response = run_request_security(
        $request
            ->with_attribute('route_name', 'ajax.metis.api')
            ->with_attribute('ajax_action', 'metis_profile_save')
    );

    assert_same(200, $response->status(), 'Action-scoped AJAX nonce should pass router security.');
};

$tests['ajax_request_accepts_action_scoped_nonce_in_legacy_field'] = function (): void {
    seed_authenticated_session('router-session');

    $token = metis_create_nonce(metis_ajax_nonce_action('metis_profile_save'));
    $request = new Metis_Http_Request(
        'POST',
        '/api/ajax',
        '/api/ajax',
        [],
        [
            'action' => 'metis_profile_save',
            'nonce' => $token,
        ],
        [
            'origin' => 'https://example.test/metis/profile',
            'x-metis-csrf-token' => $token,
        ]
    );

    $response = run_request_security(
        $request
            ->with_attribute('route_name', 'ajax.metis.api')
            ->with_attribute('ajax_action', 'metis_profile_save')
    );

    assert_same(200, $response->status(), 'Action-scoped nonce should pass even when sent via the legacy field name.');
};

$tests['ajax_request_rejects_legacy_module_nonce'] = function (): void {
    seed_authenticated_session('router-session');

    $legacy = metis_create_nonce('metis_profile');
    $request = new Metis_Http_Request(
        'POST',
        '/api/ajax',
        '/api/ajax',
        [],
        [
            'action' => 'metis_profile_save',
            'nonce' => $legacy,
        ],
        [
            'origin' => 'https://example.test/metis/profile',
            'x-metis-csrf-token' => $legacy,
        ]
    );

    $response = run_request_security(
        $request
            ->with_attribute('route_name', 'ajax.metis.api')
            ->with_attribute('ajax_action', 'metis_profile_save')
    );

    assert_same(403, $response->status(), 'Module-scoped legacy nonce should now be rejected.');
};

$tests['portal_post_accepts_mapped_nonce'] = function (): void {
    seed_authenticated_session('router-session');

    $nonce = metis_create_nonce('metis_save_settings_general');
    $request = new Metis_Http_Request(
        'POST',
        '/settings/general',
        '/settings/general',
        [],
        [
            'metis_settings_nonce' => $nonce,
        ],
        [
            'referer' => 'https://example.test/metis/settings/general',
        ]
    );

    $response = run_request_security(
        $request
            ->with_attribute('route_name', 'portal.page')
            ->with_attribute('domain', 'settings')
            ->with_attribute('view', 'general')
    );

    assert_same(200, $response->status(), 'Mapped portal nonce should pass router security.');
};

$tests['request_security_rejects_cross_site_origin'] = function (): void {
    seed_authenticated_session('router-session');

    $request = new Metis_Http_Request(
        'POST',
        '/api/ajax',
        '/api/ajax',
        [],
        [
            'action' => 'metis_profile_save',
            'metis_action_nonce' => metis_create_nonce(metis_ajax_nonce_action('metis_profile_save')),
        ],
        [
            'origin' => 'https://evil.example',
        ]
    );

    $response = run_request_security(
        $request
            ->with_attribute('route_name', 'ajax.metis.api')
            ->with_attribute('ajax_action', 'metis_profile_save')
    );

    assert_same(403, $response->status(), 'Cross-site origin should be rejected.');
};

$tests['request_security_rejects_session_integrity_mismatch'] = function (): void {
    seed_authenticated_session('router-session');
    $_SESSION['metis_session_integrity'] = 'tampered';

    $request = new Metis_Http_Request(
        'POST',
        '/api/ajax',
        '/api/ajax',
        [],
        [
            'action' => 'metis_profile_save',
            'metis_action_nonce' => metis_create_nonce(metis_ajax_nonce_action('metis_profile_save')),
        ],
        [
            'origin' => 'https://example.test/metis/profile',
        ]
    );

    $response = run_request_security(
        $request
            ->with_attribute('route_name', 'ajax.metis.api')
            ->with_attribute('ajax_action', 'metis_profile_save')
    );

    assert_same(401, $response->status(), 'Session integrity mismatches should be rejected.');
};

foreach ($tests as $name => $test) {
    $test();
}

echo "Router request security tests passed.\n";
