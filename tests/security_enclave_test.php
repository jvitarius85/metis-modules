<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');

if (!class_exists('WP_User')) {
    final class WP_User {
        public int $ID = 0;
        public array $roles = [];
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\\-]/', '', $key) ?? '';
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return false;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool {
        return true;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): WP_User {
        return new WP_User();
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return 1;
    }
}

if (!function_exists('wp_get_session_token')) {
    function wp_get_session_token(): string {
        return 'session-token';
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $nonce_key): bool {
        return $nonce === 'valid-nonce:' . $nonce_key;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        return '00000000-0000-4000-8000-000000000000';
    }
}

if (!function_exists('metis_people_can')) {
    function metis_people_can(string $module, string $permission = 'view'): bool {
        return $module === 'people' && $permission === 'view';
    }
}

if (!function_exists('metis_get_modules')) {
    function metis_get_modules(): array {
        return [
            'people' => [
                'config' => [
                    'permissions' => [
                        'view' => ['board'],
                        'edit' => ['administrator'],
                    ],
                ],
            ],
        ];
    }
}

if (!function_exists('metis_audit_log_activity')) {
    function metis_audit_log_activity(string $event, array $context = []): void {}
}

if (!function_exists('metis_audit_log_security')) {
    function metis_audit_log_security(string $event, array $context = []): void {}
}

if (!class_exists('Metis_Logger')) {
    final class Metis_Logger {
        public static function info(string $message, array $context = []): void {}
        public static function warn(string $message, array $context = []): void {}
    }
}

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap(['security_enclave', 'security_runtime_bridge']);

final class TestLogger implements Metis_Security_Audit_Logger_Interface {
    public array $audit = [];
    public array $security = [];

    public function audit(string $event, array $context = []): void {
        $this->audit[] = [$event, $context];
    }

    public function security(string $event, array $context = []): void {
        $this->security[] = [$event, $context];
    }
}

final class TestSessionStore implements Metis_Security_Session_Store_Interface {
    public function __construct(private readonly bool $valid) {}

    public function is_valid(string $session_id, array $actor, array $context = []): bool {
        return $this->valid && $session_id !== '';
    }
}

final class TestRateLimiter implements Metis_Security_Rate_Limiter_Interface {
    private array $counts = [];

    public function consume(string $bucket, int $limit, int $window_seconds): bool {
        $this->counts[$bucket] = ($this->counts[$bucket] ?? 0) + 1;
        return $this->counts[$bucket] <= $limit;
    }
}

final class TestNonceVerifier implements Metis_Security_Nonce_Verifier_Interface {
    public function __construct(private readonly bool $valid) {}

    public function is_valid(string $nonce, string $nonce_key, array $context = []): bool {
        return $this->valid && $nonce === 'nonce-ok' && $nonce_key !== '';
    }
}

final class TestDbGateway implements Metis_Security_Database_Gateway_Interface {
    public function select(string $statement, array $params = [], array $context = []): mixed { return ['ok' => true]; }
    public function insert(string $target, array $payload, array $context = []): mixed { return ['target' => $target, 'payload' => $payload]; }
    public function update(string $target, array $payload, array $where, array $context = []): mixed { return true; }
    public function delete(string $target, array $where, array $context = []): mixed { return true; }
    public function execute(string $statement, array $params = [], array $context = []): mixed { return 1; }
}

final class TestFileGateway implements Metis_Security_File_Gateway_Interface {
    public function read(string $path, array $context = []): string { return 'ok'; }
    public function write(string $path, string $contents, array $context = []): void {}
    public function delete(string $path, array $context = []): void {}
    public function exists(string $path, array $context = []): bool { return true; }
}

final class TestModuleGateway implements Metis_Security_Module_Gateway_Interface {
    public function dispatch(string $module, string $action, array $payload = [], array $context = []): mixed {
        return [$module, $action, $payload];
    }
}

function make_enclave(
    ?TestLogger $logger = null,
    ?Metis_Security_Session_Store_Interface $sessions = null,
    ?Metis_Security_Rate_Limiter_Interface $rateLimiter = null,
    ?Metis_Security_Nonce_Verifier_Interface $nonceVerifier = null,
    ?callable $permissionResolver = null
): Metis_Security_Enclave {
    return new Metis_Security_Enclave(
        $logger ?? new TestLogger(),
        $sessions ?? new TestSessionStore(true),
        $rateLimiter ?? new TestRateLimiter(),
        $nonceVerifier ?? new TestNonceVerifier(true),
        new Metis_Security_Gateway_Bundle(
            new TestDbGateway(),
            new TestFileGateway(),
            new TestModuleGateway()
        ),
        $permissionResolver
    );
}

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

function expect_exception(callable $callback, string $codeName): Metis_Security_Enclave_Exception {
    try {
        $callback();
    } catch (Metis_Security_Enclave_Exception $e) {
        assert_same($codeName, $e->code_name(), 'Unexpected exception code');
        return $e;
    }

    throw new RuntimeException('Expected enclave exception ' . $codeName);
}

$tests = [];

$tests['rejects_unregistered_operation'] = function (): void {
    $enclave = make_enclave();
    expect_exception(
        fn () => $enclave->handle('missing.op', [], static fn () => true),
        'operation_not_registered'
    );
};

$tests['requires_authentication'] = function (): void {
    $enclave = make_enclave();
    $enclave->register_policy(new Metis_Security_Policy('secure.op'));
    expect_exception(
        fn () => $enclave->handle('secure.op', ['actor' => [], 'meta' => [], 'input' => ['nonce' => 'nonce-ok']], static fn () => true),
        'authentication_required'
    );
};

$tests['requires_valid_session'] = function (): void {
    $enclave = make_enclave(sessions: new TestSessionStore(false));
    $enclave->register_policy(new Metis_Security_Policy('secure.op'));
    expect_exception(
        fn () => $enclave->handle('secure.op', [
            'actor' => ['id' => '1', 'session_id' => 'bad'],
            'meta' => [],
            'input' => ['nonce' => 'nonce-ok'],
        ], static fn () => true),
        'invalid_session'
    );
};

$tests['requires_nonce'] = function (): void {
    $enclave = make_enclave(nonceVerifier: new TestNonceVerifier(false));
    $enclave->register_policy(new Metis_Security_Policy('secure.op'));
    expect_exception(
        fn () => $enclave->handle('secure.op', [
            'actor' => ['id' => '1', 'session_id' => 'session-ok'],
            'meta' => [],
            'input' => ['nonce' => 'bad'],
        ], static fn () => true),
        'invalid_nonce'
    );
};

$tests['enforces_rate_limit'] = function (): void {
    $enclave = make_enclave(rateLimiter: new TestRateLimiter());
    $enclave->register_policy(new Metis_Security_Policy('secure.op', rate_limit: 1));

    $request = [
        'actor' => ['id' => '1', 'session_id' => 'session-ok'],
        'meta' => [],
        'input' => ['nonce' => 'nonce-ok'],
    ];

    $result = $enclave->handle('secure.op', $request, static fn () => 'ok');
    assert_same('ok', $result, 'First request should pass');

    expect_exception(
        fn () => $enclave->handle('secure.op', $request, static fn () => 'ok'),
        'rate_limit_exceeded'
    );
};

$tests['enforces_permission_resolver'] = function (): void {
    $enclave = make_enclave(permissionResolver: static fn () => false);
    $enclave->register_policy(new Metis_Security_Policy('secure.op', 'people', 'edit'));
    expect_exception(
        fn () => $enclave->handle('secure.op', [
            'actor' => ['id' => '1', 'session_id' => 'session-ok'],
            'meta' => [],
            'input' => ['nonce' => 'nonce-ok'],
        ], static fn () => true),
        'permission_denied'
    );
};

$tests['sanitizes_input_payload'] = function (): void {
    $enclave = make_enclave();
    $enclave->register_policy(new Metis_Security_Policy('secure.op'));

    $result = $enclave->handle('secure.op', [
        'actor' => ['id' => '1', 'session_id' => 'session-ok'],
        'meta' => [],
        'input' => [
            'nonce' => 'nonce-ok',
            '<bad key>' => "  hello\0 ",
            'nested' => ['we!rd' => "  value\t "],
        ],
    ], static fn (array $input) => $input);

    assert_true(isset($result['badkey']), 'Sanitized key missing');
    assert_same('hello', $result['badkey'], 'String value should be trimmed and null-stripped');
    assert_same('value', $result['nested']['werd'], 'Nested value should be sanitized');
};

$tests['requires_trusted_context_for_gateways'] = function (): void {
    $enclave = make_enclave();
    expect_exception(fn () => $enclave->db(), 'untrusted_execution');
    expect_exception(fn () => $enclave->files(), 'untrusted_execution');
    expect_exception(fn () => $enclave->modules(), 'untrusted_execution');
};

$tests['allows_gateways_inside_trusted_context'] = function (): void {
    $enclave = make_enclave();
    $enclave->register_policy(new Metis_Security_Policy('secure.op'));

    $result = $enclave->handle('secure.op', [
        'actor' => ['id' => '1', 'session_id' => 'session-ok'],
        'meta' => [],
        'input' => ['nonce' => 'nonce-ok'],
    ], static function (array $input, array $context, Metis_Security_Gateway_Bundle $gateways) use ($enclave) {
        return [
            'db' => $enclave->db()->select('SELECT 1'),
            'file' => $enclave->files()->exists(__FILE__),
            'module' => $enclave->modules()->dispatch('people', 'view'),
        ];
    });

    assert_true(is_array($result['db']), 'Trusted db call should work');
    assert_true($result['file'] === true, 'Trusted file call should work');
    assert_same(['people', 'view', []], $result['module'], 'Trusted module call should work');
};

$tests['wp_file_gateway_blocks_paths_outside_metis'] = function (): void {
    $gateway = new Metis_Runtime_File_Gateway();
    expect_exception(
        fn () => $gateway->exists('/tmp/outside-metis-test'),
        'invalid_file_path'
    );
};

$tests['wp_file_gateway_allows_paths_inside_metis'] = function (): void {
    $gateway = new Metis_Runtime_File_Gateway();
    $path = METIS_PATH . 'storage/runtime/security-test-' . bin2hex(random_bytes(4)) . '.txt';
    $gateway->write($path, 'ok');
    assert_true($gateway->exists($path), 'File should exist inside Metis boundary');
    assert_same('ok', $gateway->read($path), 'File contents should round-trip');
    $gateway->delete($path);
    assert_true(!$gateway->exists($path), 'File should be deleted');
};

$tests['bridge_registers_expected_module_policies'] = function (): void {
    $enclave = make_enclave();
    Metis_Security_Enclave_Container::set($enclave);

    metis_security_register_module_policies('people', [
        'assets' => ['nonce_action' => 'metis_people'],
    ]);

    $viewPolicy = $enclave->policy('module.view.people');
    $editPolicy = $enclave->policy('module.edit.people');

    assert_same(false, $viewPolicy->require_nonce, 'View policy should not require nonce');
    assert_same(true, $editPolicy->require_nonce, 'Edit policy should require nonce');
    assert_same('metis_people', $editPolicy->nonce_key, 'Module nonce key should propagate');
};

$failures = [];

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failures[] = [$name, $e];
        echo "FAIL {$name}: " . $e->getMessage() . "\n";
    }
}

if ($failures !== []) {
    exit(1);
}

echo "All security enclave tests passed.\n";
