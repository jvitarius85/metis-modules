<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\\-]/', '', $key) ?? '';
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $path): string {
        return rtrim($path, '/') . '/';
    }
}

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap(['service_registry']);

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

$tests = [];

$tests['hermes_library_loads_static_definitions'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    metis_register_core_services();

    /** @var \Metis\Services\HermesDefinitionLibrary $library */
    $library = Metis::service('hermes_library');

    $packs = $library->contextPacks();
    $playbooks = $library->playbooks();
    $missions = $library->missions();

    assert_same(13, count($packs), 'Expected the Hermes context pack set to load.');
    assert_same(7, count($playbooks), 'Expected the initial Hermes playbook set to load.');
    assert_same(5, count($missions), 'Expected the initial Hermes mission set to load.');
    assert_true(isset($packs['inventory']), 'Inventory context pack should be addressable by key.');
    assert_true(isset($packs['backup']), 'Backup context pack should be addressable by key.');
    assert_true(isset($packs['permissions']), 'Permissions context pack should be addressable by key.');
    assert_true(isset($playbooks['donation_reconciliation']), 'Donation reconciliation playbook should be registered.');
    assert_true(isset($missions['publishing_announcements']), 'Publishing announcements mission should be registered.');
};

$tests['hermes_library_exposes_dynamic_layer_snapshot'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    metis_register_core_services();

    /** @var \Metis\Services\HermesDefinitionLibrary $library */
    $library = Metis::service('hermes_library');
    $dynamic = $library->dynamicLayer();

    assert_same('dynamic_context_schema', $dynamic['schema']['type'] ?? null, 'Dynamic layer should expose its schema.');
    assert_same('dynamic_context_snapshot', $dynamic['snapshot']['type'] ?? null, 'Dynamic layer should expose the current snapshot.');
    assert_same([], $dynamic['snapshot']['failure_patterns'] ?? null, 'Baseline dynamic snapshot should start empty.');
};

$tests['hermes_runtime_snapshot_returns_list_shapes'] = function (): void {
    Metis::set_registry(new Metis_Service_Registry());
    metis_register_core_services();

    /** @var \Metis\Services\HermesDefinitionLibrary $library */
    $library = Metis::service('hermes_library');
    $snapshot = $library->runtimeSnapshot();

    assert_true(array_is_list($snapshot['context_packs']), 'Runtime context packs should be returned as a list.');
    assert_true(array_is_list($snapshot['playbooks']), 'Runtime playbooks should be returned as a list.');
    assert_true(array_is_list($snapshot['missions']), 'Runtime missions should be returned as a list.');
};

$failures = [];

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failures[] = "[FAIL] {$name}: " . $e->getMessage();
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . "\n");
    }

    exit(1);
}

echo "Hermes definition library tests passed.\n";
