<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');
define('METIS_CRON_SECRET', 'cron-secret');

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

$GLOBALS['wp_filter'] = [];
$GLOBALS['status_codes'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['options'] = [];

if (!class_exists('WP_User')) {
    final class WP_User {
        public int $ID = 1;
        public array $roles = ['administrator'];
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\\-]/', '', $key) ?? '';
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed {
        return parse_url($url, $component);
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://vitarius.org' . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('metis_parse_url')) {
    function metis_parse_url(string $url, int $component = -1): mixed {
        return wp_parse_url($url, $component);
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show): string {
        return $show === 'charset' ? 'UTF-8' : '';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string {
        return rtrim($value, '/');
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool {
        return true;
    }
}

if (!function_exists('metis_user_logged_in')) {
    function metis_user_logged_in(): bool {
        return is_user_logged_in();
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return $capability === 'manage_options';
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
        return $nonce === 'nonce:' . $nonce_key;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        return '00000000-0000-4000-8000-000000000000';
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
        $GLOBALS['wp_filter'][$hook][$priority][] = [
            'function' => $callback,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
        add_action($hook, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('metis_add_filter')) {
    function metis_add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
        add_filter($hook, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): void {
        if (empty($GLOBALS['wp_filter'][$hook][$priority])) {
            return;
        }
        foreach ($GLOBALS['wp_filter'][$hook][$priority] as $index => $registered) {
            if ($registered['function'] === $callback) {
                unset($GLOBALS['wp_filter'][$hook][$priority][$index]);
            }
        }
    }
}

if (!function_exists('metis_remove_filter')) {
    function metis_remove_filter(string $hook, callable $callback, int $priority = 10): void {
        remove_filter($hook, $callback, $priority);
    }
}

if (!function_exists('has_action')) {
    function has_action(string $hook): bool {
        return !empty($GLOBALS['wp_filter'][$hook]);
    }
}

if (!function_exists('metis_has_action')) {
    function metis_has_action(string $hook): bool {
        return has_action($hook);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void {
        if (empty($GLOBALS['wp_filter'][$hook])) {
            return;
        }
        ksort($GLOBALS['wp_filter'][$hook]);
        foreach ($GLOBALS['wp_filter'][$hook] as $callbacks) {
            foreach ($callbacks as $registered) {
                $accepted = (int) ($registered['accepted_args'] ?? count($args));
                call_user_func_array($registered['function'], array_slice($args, 0, $accepted));
            }
        }
    }
}

if (!function_exists('metis_do_action')) {
    function metis_do_action(string $hook, mixed ...$args): void {
        do_action($hook, ...$args);
    }
}

if (!function_exists('status_header')) {
    function status_header(int $code): void {
        $GLOBALS['status_codes'][] = $code;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed {
        return $GLOBALS['transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $ttl): bool {
        $GLOBALS['transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        unset($GLOBALS['transients'][$key]);
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed {
        return $GLOBALS['options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, mixed $value, bool $autoload = true): bool {
        $GLOBALS['options'][$key] = $value;
        return true;
    }
}

if (!function_exists('metis_audit_ip_address')) {
    function metis_audit_ip_address(): string {
        return '127.0.0.1';
    }
}

if (!function_exists('metis_audit_user_agent')) {
    function metis_audit_user_agent(): string {
        return 'BoundaryTest/1.0';
    }
}

if (!function_exists('metis_audit_request_id')) {
    function metis_audit_request_id(): string {
        return 'req-test';
    }
}

if (!function_exists('metis_audit_log_activity')) {
    function metis_audit_log_activity(string $event, array $context = []): void {}
}

if (!function_exists('metis_audit_log_security')) {
    function metis_audit_log_security(string $event, array $context = []): void {}
}

if (!class_exists('Core_Settings_Service')) {
    final class Core_Settings_Service {
        public static function get(string $key, mixed $default = null): mixed {
            return $default;
        }
    }
}

if (!class_exists('Metis_Logger')) {
    final class Metis_Logger {
        public static function info(string $message, array $context = []): void {}
        public static function warn(string $message, array $context = []): void {}
    }
}

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap([
    'http',
    'security_enclave',
    'security_runtime_bridge',
    'ajax',
    'cron',
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

function expect_enclave_exception(callable $callback, string $codeName): Metis_Security_Enclave_Exception {
    try {
        $callback();
    } catch (Metis_Security_Enclave_Exception $e) {
        assert_same($codeName, $e->code_name(), 'Unexpected exception code');
        return $e;
    }
    throw new RuntimeException('Expected enclave exception ' . $codeName);
}

$tests = [];

$tests['ajax_same_origin_rejects_get'] = function (): void {
    $request = new Metis_Http_Request('GET', '/api/ajax', '/api/ajax');
    expect_enclave_exception(fn () => metis_ajax_verify_same_origin($request), 'invalid_method');
};

$tests['ajax_same_origin_rejects_cross_site_origin'] = function (): void {
    $request = new Metis_Http_Request('POST', '/api/ajax', '/api/ajax', [], [], ['origin' => 'https://evil.example']);
    expect_enclave_exception(fn () => metis_ajax_verify_same_origin($request), 'csrf_failed');
};

$tests['ajax_same_origin_accepts_same_origin_referer'] = function (): void {
    $request = new Metis_Http_Request('POST', '/api/ajax', '/api/ajax', [], [], ['referer' => 'https://vitarius.org/metis/people/']);
    metis_ajax_verify_same_origin($request);
    assert_true(true, 'Same-origin referer should pass');
};

$tests['ajax_validate_request_rejects_unknown_fields_when_disallowed'] = function (): void {
    $request = new Metis_Http_Request('POST', '/api/ajax', '/api/ajax', [], [
        'action' => 'metis_test',
        'nonce' => 'nonce',
        'extra' => 'oops',
    ]);
    expect_enclave_exception(
        fn () => metis_ajax_validate_request($request, [
            'schema' => [
                'fields' => [
                    'action' => ['type' => 'string', 'required' => true],
                    'nonce' => ['type' => 'string', 'required' => true],
                ],
                'allow_additional_fields' => false,
            ],
        ]),
        'invalid_payload'
    );
};

$tests['ajax_validate_request_accepts_valid_payload'] = function (): void {
    $request = new Metis_Http_Request('POST', '/api/ajax', '/api/ajax', [], [
        'action' => 'metis_test',
        'nonce' => 'nonce',
        'count' => '42',
    ]);
    $validated = metis_ajax_validate_request($request, [
        'schema' => [
            'fields' => [
                'action' => ['type' => 'string', 'required' => true, 'enum' => ['metis_test']],
                'nonce' => ['type' => 'string', 'required' => true],
                'count' => ['type' => 'integer', 'required' => true],
            ],
            'allow_additional_fields' => false,
        ],
    ]);
    assert_same('42', $validated['count'], 'Validated payload should round-trip');
};

$tests['ajax_dispatch_reports_missing_handler'] = function (): void {
    expect_enclave_exception(fn () => metis_ajax_dispatch_legacy_action('metis_missing_handler'), 'ajax_handler_missing');
};

$tests['ajax_dispatch_executes_registered_handler'] = function (): void {
    add_action('wp_ajax_metis_boundary_ok', static function (): void {
        echo wp_json_encode(['success' => true, 'data' => ['ok' => 1]]);
    });
    $response = metis_ajax_dispatch_legacy_action('metis_boundary_ok');
    assert_same(200, $response['status'], 'AJAX dispatch should default to 200');
    assert_same(true, $response['body']['success'], 'AJAX success payload expected');
};

$tests['ajax_dispatch_captures_wp_die_status'] = function (): void {
    add_action('wp_ajax_metis_boundary_die', static function (): void {
        metis_ajax_capture_wp_die_handler('Denied', '', ['response' => 403]);
    });
    $response = metis_ajax_dispatch_legacy_action('metis_boundary_die');
    assert_same(403, $response['status'], 'AJAX die status should be captured');
    assert_same(false, $response['body']['success'], 'AJAX die should return failure');
};

$tests['cron_matches_expected_path'] = function (): void {
    $request = new Metis_Http_Request('POST', '/system/cron', '/system/cron');
    assert_true(Metis_Cron_Manager::matches_request($request), 'Cron path should match');
};

$tests['cron_authorize_rejects_missing_secret'] = function (): void {
    $request = new Metis_Http_Request('POST', '/system/cron', '/system/cron');
    expect_enclave_exception(fn () => Metis_Cron_Manager::authorize_request($request), 'invalid_cron_secret');
};

$tests['cron_authorize_accepts_valid_secret'] = function (): void {
    $request = new Metis_Http_Request('POST', '/system/cron', '/system/cron', [], [], [
        'x-metis-cron-secret' => 'cron-secret',
    ]);
    $context = Metis_Cron_Manager::authorize_request($request);
    assert_same('cloudflare-worker', $context['actor']['id'], 'Cron actor should be system worker');
};

$tests['cron_authorize_accepts_fallback_secret_header'] = function (): void {
    $request = new Metis_Http_Request('POST', '/system/cron', '/system/cron', [], [], [
        'x-cron-secret' => 'cron-secret',
    ]);
    $context = Metis_Cron_Manager::authorize_request($request);
    assert_same('cloudflare-worker', $context['actor']['id'], 'Fallback header should authorize');
};

$tests['cron_normalizes_requested_task_list_via_handler_input'] = function (): void {
    $request = new Metis_Http_Request('POST', '/system/cron', '/system/cron', [
        'tasks' => 'alpha,beta,alpha,invalid task',
        'force' => '1',
    ], [], [
        'x-metis-cron-secret' => 'cron-secret',
    ]);
    $ref = new ReflectionClass(Metis_Cron_Manager::class);
    $method = $ref->getMethod('normalize_requested_tasks');
    $tasks = $method->invoke(null, 'alpha,beta,alpha,invalid task');
    assert_same(['alpha', 'beta', 'invalidtask'], $tasks, 'Task list should normalize and dedupe');
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

echo "All security boundary tests passed.\n";
