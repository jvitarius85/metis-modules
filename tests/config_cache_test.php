<?php
declare(strict_types=1);

define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');

require_once dirname(__DIR__) . '/includes/core/bootstrap.php';
metis_core_bootstrap('standalone_bootstrap');

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

$cachePath = metis_standalone_config_cache_path();
$cacheBackup = is_file($cachePath) ? file_get_contents($cachePath) : false;
$fixtureName = 'config-cache-test-' . bin2hex(random_bytes(4));
$fixturePath = dirname(__DIR__) . '/config/' . $fixtureName . '.php';

try {
    file_put_contents(
        $fixturePath,
        "<?php\nreturn " . var_export(['version' => 1, 'feature' => 'alpha'], true) . ";\n",
        LOCK_EX
    );

    metis_standalone_invalidate_config_cache();
    $compiled = metis_standalone_compiled_config(true);

    assert_true(is_file($cachePath), 'Compiled config cache should be written.');
    assert_same(1, (int) ($compiled['config'][$fixtureName]['version'] ?? 0), 'Compiled cache should include the fixture config.');
    assert_same('alpha', (string) metis_standalone_read_config($fixtureName)['feature'], 'Read helper should use the compiled payload.');
    assert_true(!isset($compiled['config']['index']), 'Directory guard files must not be compiled into the config cache.');

    file_put_contents(
        $fixturePath,
        "<?php\nreturn " . var_export(['version' => 2, 'feature' => 'beta'], true) . ";\n",
        LOCK_EX
    );
    touch($fixturePath, time() + 5);

    metis_standalone_forget_compiled_config();
    $recompiled = metis_standalone_compiled_config();

    assert_same(2, (int) ($recompiled['config'][$fixtureName]['version'] ?? 0), 'Changed config should trigger a cache rebuild.');
    assert_same('beta', (string) metis_standalone_read_config($fixtureName)['feature'], 'Read helper should return rebuilt config values.');

    unlink($fixturePath);
    metis_standalone_forget_compiled_config();
    $withoutFixture = metis_standalone_compiled_config();

    assert_true(!isset($withoutFixture['config'][$fixtureName]), 'Removed config files should be dropped from the compiled cache.');
} finally {
    if (is_file($fixturePath)) {
        unlink($fixturePath);
    }

    if ($cacheBackup !== false) {
        file_put_contents($cachePath, $cacheBackup, LOCK_EX);
    } else {
        @unlink($cachePath);
    }

    metis_standalone_forget_compiled_config();
}

echo "Configuration cache tests passed.\n";
