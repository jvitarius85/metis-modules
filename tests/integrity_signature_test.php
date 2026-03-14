<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/metis-integrity-test-' . bin2hex(random_bytes(4));
mkdir($root . '/includes', 0775, true);
mkdir($root . '/assets', 0775, true);
mkdir($root . '/config', 0775, true);

file_put_contents($root . '/metis.php', "<?php\nreturn true;\n");
file_put_contents($root . '/includes/example.php', "<?php\nfunction metis_example(): string { return 'ok'; }\n");
file_put_contents($root . '/assets/example.css', "body{color:#123456;}\n");

define('ABSPATH', $root . '/');
define('METIS_PATH', $root . '/');
define('METIS_PREFIX', 'metis');
define('METIS_VERSION', 'test');

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string {
        return rtrim($value, '/') . '/';
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql'): string|int {
        return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time();
    }
}

if (!function_exists('metis_json_encode')) {
    function metis_json_encode(mixed $value, int $flags = 0): string|false {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('metis_make_dir')) {
    function metis_make_dir(string $target): bool {
        return is_dir($target) || mkdir($target, 0775, true);
    }
}

if (!function_exists('metis_normalize_path')) {
    function metis_normalize_path(string $path): string {
        return str_replace('\\', '/', $path);
    }
}

if (!function_exists('metis_audit_log_security')) {
    function metis_audit_log_security(string $action, array $context = []): void {}
}

if (!function_exists('metis_mail')) {
    function metis_mail(array|string $to, string $subject, string $message): bool {
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed {
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, mixed $value, bool $autoload = true): bool {
        return true;
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('get_users')) {
    function get_users(array $args = []): array {
        return [];
    }
}

if (!class_exists('Core_Settings_Service')) {
    final class Core_Settings_Service {
        private static array $values = [];

        public static function has(string $key): bool {
            return array_key_exists($key, self::$values);
        }

        public static function get(string $key, mixed $default = null): mixed {
            return self::$values[$key] ?? $default;
        }

        public static function set(string $key, mixed $value, bool $autoload = true): bool {
            self::$values[$key] = $value;
            return true;
        }
    }
}

if (!class_exists('Metis_Logger')) {
    final class Metis_Logger {
        public static function info(string $message, array $context = []): void {}
        public static function warn(string $message, array $context = []): void {}
        public static function error(string $message, array $context = []): void {}
    }
}

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap('integrity');

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

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function create_keypair(string $privatePath, string $publicPath): void {
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Failed to create test keypair.');
    }

    $private = '';
    openssl_pkey_export($resource, $private);
    $details = openssl_pkey_get_details($resource);
    if (!is_array($details) || empty($details['key'])) {
        throw new RuntimeException('Failed to export test public key.');
    }

    mkdir(dirname($privatePath), 0775, true);
    file_put_contents($privatePath, $private);
    file_put_contents($publicPath, (string) $details['key']);
}

function run_command(array $command, ?string $cwd = null): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd ?? $GLOBALS['root_test_dir'] ?? null);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function require_git_repo(string $root): void {
    $GLOBALS['root_test_dir'] = $root;
    $commands = [
        ['git', 'init', '-b', 'main'],
        ['git', 'config', 'user.email', 'tests@example.com'],
        ['git', 'config', 'user.name', 'Metis Tests'],
        ['git', 'add', 'metis.php', 'includes/example.php', 'assets/example.css', 'config/integrity.php'],
        ['git', 'commit', '-m', 'Initial baseline'],
    ];

    foreach ($commands as $command) {
        $result = run_command($command, $root);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Git command failed: ' . implode(' ', $command) . ' ' . $result['stderr']);
        }
    }
}

$privateKey = $root . '/.metis-integrity/keys/private.pem';
$publicKey = $root . '/.metis-integrity/keys/public.pem';
create_keypair($privateKey, $publicKey);
file_put_contents(
    $root . '/config/integrity.php',
    "<?php\nreturn " . var_export([
        'require_signature' => true,
        'private_key_path' => '.metis-integrity/keys/private.pem',
        'public_key_path' => '.metis-integrity/keys/public.pem',
    ], true) . ";\n"
);

$tests = [];

$tests['signed_baseline_verifies_cleanly'] = function () use ($root): void {
    assert_true(Metis_Integrity_Manager::build_baseline('test'), 'Baseline build should succeed.');
    assert_true(Metis_Integrity_Manager::sign_baseline(), 'Signing baseline should succeed.');

    $result = Metis_Integrity_Manager::verify_baseline();
    assert_true(!empty($result['ok']), 'Signed baseline should verify.');
    assert_same('verified', $result['status'], 'Baseline should report verified signature state.');
    assert_same([], $result['recovery_mismatches'], 'Recovery snapshot should match manifest.');
};

$tests['tampered_manifest_fails_verification'] = function () use ($root): void {
    $manifestPath = $root . '/.metis-integrity/manifest.json';
    $manifestBackupPath = $root . '/.metis-integrity/manifest.backup.json';
    $raw = file_get_contents($manifestPath);
    assert_true(is_string($raw) && $raw !== '', 'Manifest should exist before tamper test.');
    file_put_contents($manifestPath, $raw . "\n");
    file_put_contents($manifestBackupPath, $raw . "\n");

    $result = Metis_Integrity_Manager::verify_baseline();
    assert_true(empty($result['ok']), 'Tampered manifest should fail verification.');
    assert_same('signature_mismatch', $result['status'], 'Tampered manifest should report signature mismatch.');

    Metis_Integrity_Manager::build_baseline('test-restored');
    Metis_Integrity_Manager::sign_baseline();
};

$tests['poisoned_recovery_snapshot_is_not_used_for_restore'] = function () use ($root): void {
    Core_Settings_Service::set('integrity_auto_heal_enabled', true);
    Core_Settings_Service::set('integrity_quarantine_enabled', true);

    $livePath = $root . '/includes/example.php';
    $recoveryPath = $root . '/.metis-integrity/recovery/includes/example.php';

    file_put_contents($recoveryPath, "<?php\nfunction metis_example(): string { return 'poison'; }\n");
    file_put_contents($livePath, "<?php\nfunction metis_example(): string { return 'mutated'; }\n");

    $result = Metis_Integrity_Manager::scan_and_heal('test-poisoned-recovery');
    assert_same([], $result['restored'], 'Restore should refuse poisoned recovery snapshot.');
    assert_true(!empty($result['quarantined']), 'Modified live file should be quarantined before restore is refused.');
    assert_true(!is_file($livePath), 'Modified live file should not remain in place after quarantine.');
};

$tests['git_tracked_file_can_restore_without_snapshot'] = function () use ($root): void {
    $gitAvailable = run_command(['git', '--version'], $root);
    if ($gitAvailable['exit_code'] !== 0) {
        return;
    }

    Core_Settings_Service::set('integrity_auto_heal_enabled', true);
    Core_Settings_Service::set('integrity_quarantine_enabled', true);

    file_put_contents($root . '/includes/example.php', "<?php\nfunction metis_example(): string { return 'ok'; }\n");
    file_put_contents($root . '/assets/example.css', "body{color:#123456;}\n");

    require_git_repo($root);
    assert_true(Metis_Integrity_Manager::build_baseline('git-test'), 'Baseline build should succeed for git restore test.');
    assert_true(Metis_Integrity_Manager::sign_baseline(), 'Signing baseline should succeed for git restore test.');

    $livePath = $root . '/includes/example.php';
    $snapshotPath = $root . '/.metis-integrity/recovery/includes/example.php';

    @unlink($snapshotPath);
    file_put_contents($livePath, "<?php\nfunction metis_example(): string { return 'tampered'; }\n");

    $result = Metis_Integrity_Manager::scan_and_heal('test-git-restore');

    assert_true(in_array('includes/example.php', $result['restored'], true), 'Tracked file should restore from git.');
    assert_same("<?php\nfunction metis_example(): string { return 'ok'; }\n", file_get_contents($livePath), 'Git restore should recover committed file contents.');
};

try {
    foreach ($tests as $test) {
        $test();
    }
    echo "All integrity signature tests passed.\n";
} finally {
    rrmdir($root);
}
