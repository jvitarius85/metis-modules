<?php
declare(strict_types=1);

$fixture_root = sys_get_temp_dir() . '/metis-release-test-' . bin2hex(random_bytes(4));
mkdir($fixture_root . '/includes', 0775, true);
mkdir($fixture_root . '/assets', 0775, true);
mkdir($fixture_root . '/config', 0775, true);

file_put_contents($fixture_root . '/metis.php', "<?php\nreturn true;\n");
file_put_contents($fixture_root . '/includes/example.php', "<?php\nfunction metis_release_fixture(): string { return 'v1'; }\n");
file_put_contents($fixture_root . '/assets/example.css', "body { color: #111; }\n");

define('METIS_STANDALONE', true);
define('ABSPATH', $fixture_root . '/');
define('METIS_PATH', $fixture_root . '/');
define('METIS_PREFIX', 'metis');
define('METIS_VERSION', '1.0.0');

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
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

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string {
        return rtrim($value, '/') . '/';
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

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}

if (!function_exists('metis_standalone_invalidate_config_cache')) {
    function metis_standalone_invalidate_config_cache(): void {}
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

if (!function_exists('metis_backup_run_now')) {
    function metis_backup_run_now(string $trigger = 'manual'): array {
        return [
            'ok' => true,
            'status' => 'success',
            'run_uuid' => 'backup-' . preg_replace('/[^a-z0-9]+/i', '-', $trigger),
        ];
    }
}

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap(['service_registry', 'release']);

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

function rrmdir_release(string $dir): void {
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
            rrmdir_release($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function run_release_command(array $command, string $cwd): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to execute command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function require_release_repo(string $root): void {
    $commands = [
        ['git', 'init', '-b', 'main'],
        ['git', 'config', 'user.email', 'tests@example.com'],
        ['git', 'config', 'user.name', 'Metis Tests'],
        ['git', 'add', 'metis.php', 'includes/example.php', 'assets/example.css'],
        ['git', 'commit', '-m', 'Release 1.0.0'],
        ['git', 'tag', 'v1.0.0'],
    ];

    foreach ($commands as $command) {
        $result = run_release_command($command, $root);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Git command failed: ' . implode(' ', $command) . ' ' . $result['stderr']);
        }
    }

    file_put_contents($root . '/includes/example.php', "<?php\nfunction metis_release_fixture(): string { return 'v1.1'; }\n");

    $commands = [
        ['git', 'add', 'includes/example.php'],
        ['git', 'commit', '-m', 'Release 1.1.0'],
        ['git', 'tag', 'v1.1.0'],
        ['git', 'checkout', '--detach', 'refs/tags/v1.0.0'],
    ];

    foreach ($commands as $command) {
        $result = run_release_command($command, $root);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Git command failed: ' . implode(' ', $command) . ' ' . $result['stderr']);
        }
    }
}

require_release_repo($fixture_root);

$manager = metis_release_manager();

try {
    assert_true(Metis_Integrity_Manager::initialize_baseline('release-test'), 'Integrity baseline should be initialized for update tests.');
    $status = $manager->status(true);
    assert_true(!empty($status['ok']), 'Release status should resolve.');
    assert_same('v1.0.0', (string) ($status['current']['tag'] ?? ''), 'Current exact tag should be detected.');
    assert_same('v1.1.0', (string) ($status['latest']['tag'] ?? ''), 'Latest trusted tag should be the newest semantic release.');
    assert_true(!empty($status['update_available']), 'Status should flag update availability when a newer tag exists.');

    $apply = $manager->applyRelease('v1.1.0', 'test');
    assert_true(!empty($apply['ok']), 'Applying a trusted release should succeed.');
    assert_same('v1.1.0', (string) ($apply['release']['tag'] ?? ''), 'Apply result should report the requested tag.');

    $after_apply = $manager->status(false);
    assert_same('v1.1.0', (string) ($after_apply['current']['tag'] ?? ''), 'Repository should now be checked out at the new tag.');
    assert_true(empty($after_apply['update_available']), 'No newer release should remain after applying the latest tag.');

    $rollback = $manager->rollback('test');
    assert_true(!empty($rollback['ok']), 'Rollback should return to the previous trusted release.');

    $after_rollback = $manager->status(false);
    assert_same('v1.0.0', (string) ($after_rollback['current']['tag'] ?? ''), 'Rollback should restore the previous tag.');
    assert_true(count((array) ($after_rollback['history'] ?? [])) >= 2, 'Release history should record apply and rollback actions.');
} finally {
    rrmdir_release($fixture_root);
}

echo "Release manager tests passed.\n";
