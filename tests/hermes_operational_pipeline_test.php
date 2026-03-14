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

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return $capability === 'manage_options';
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

$tests['intent_parser_maps_backup_requests'] = function (): void {
    $parser = new \Metis\Hermes\HermesIntentParser(new \Metis\Hermes\HermesCommandRegistry());
    $intent = $parser->parse('run a backup');

    assert_same('run_backup', $intent['action'] ?? '', 'Backup request should map to run_backup.');
    assert_true(is_array($intent['command'] ?? null), 'Mapped backup intent should include a command definition.');
};

$tests['context_loader_resolves_command_packs'] = function (): void {
    $library = new \Metis\Services\HermesDefinitionLibrary(dirname(__DIR__) . '/');
    $loader = new \Metis\Hermes\HermesContextPackLoader($library);
    $registry = new \Metis\Hermes\HermesCommandRegistry();

    $packs = $loader->loadForCommand($registry->definition('diagnose_permissions') ?? []);
    $packKeys = array_map(static fn (array $pack): string => (string) ($pack['key'] ?? ''), $packs);

    assert_true(in_array('people', $packKeys, true), 'Diagnose permissions should load the people context pack.');
    assert_true(in_array('permissions', $packKeys, true), 'Diagnose permissions should load the permissions context pack.');
    assert_true(in_array('drive', $packKeys, true), 'Diagnose permissions should load the drive context pack.');
};

$tests['operational_engine_returns_approval_first_response'] = function (): void {
    $engine = new \Metis\Hermes\HermesOperationalEngine(
        new \Metis\Hermes\HermesIntentParser(new \Metis\Hermes\HermesCommandRegistry()),
        new \Metis\Hermes\HermesContextPackLoader(new \Metis\Services\HermesDefinitionLibrary(dirname(__DIR__) . '/')),
        new \Metis\Hermes\HermesCommandRegistry(),
        new \Metis\Hermes\HermesPermissionValidator(new \Metis\Services\PermissionsService()),
        new \Metis\Hermes\HermesExecutionEngine(),
        new \Metis\Hermes\HermesResponseRenderer()
    );

    $result = $engine->process('run a backup');

    assert_same('awaiting_approval', $result['response']['status'] ?? '', 'Backup flow should stop at approval.');
    assert_same('system.backup.execute', $result['action_plan']['required_permission'] ?? '', 'Backup flow should expose the required permission.');
};

$tests['execution_engine_calls_registered_service_layer'] = function (): void {
    \Metis::set_registry(new \Metis_Service_Registry());
    \Metis::instance('stub_backup', new class {
        public function runBackup(string $trigger): array {
            return ['status' => 'success', 'trigger' => $trigger];
        }
    });

    $engine = new \Metis\Hermes\HermesExecutionEngine();
    $result = $engine->execute([
        'service' => [
            'service' => 'stub_backup',
            'method' => 'runBackup',
            'arguments' => ['manual'],
        ],
    ]);

    assert_same('success', $result['status'] ?? '', 'Execution engine should return the service result.');
    assert_same('manual', $result['trigger'] ?? '', 'Execution engine should pass configured arguments to the service.');
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

echo "Hermes operational pipeline tests passed.\n";
