<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function assert_true(bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void {
    assert_true(str_contains($haystack, $needle), $message);
}

$htaccess = file_get_contents($root . '/.htaccess');
assert_true(is_string($htaccess) && $htaccess !== '', 'Root .htaccess should exist.');
assert_contains('Options -Indexes', $htaccess, 'Directory listing should be disabled.');
assert_contains('^storage/(?!uploads/).*$', $htaccess, 'Non-public storage should be blocked.');

foreach ([
    'config',
    'includes',
    'logs',
    'tests',
    'tools',
    'cloudflare',
    '\\.metis-integrity',
] as $blockedDirectory) {
    assert_contains($blockedDirectory, $htaccess, $blockedDirectory . ' should be blocked in .htaccess.');
}

foreach ([
    'config/index.php',
    'includes/index.php',
    'logs/index.php',
    'storage/index.php',
    'storage/uploads/index.php',
    'tests/index.php',
    'tools/index.php',
    'cloudflare/index.php',
    '.metis-integrity/index.php',
] as $relativePath) {
    $path = $root . '/' . $relativePath;
    assert_true(is_file($path), $relativePath . ' should exist.');
    $contents = file_get_contents($path);
    assert_true(is_string($contents) && str_contains($contents, 'http_response_code(403);'), $relativePath . ' should deny access.');
}

$uploadsHtaccess = file_get_contents($root . '/storage/uploads/.htaccess');
assert_true(is_string($uploadsHtaccess) && $uploadsHtaccess !== '', 'Uploads .htaccess should exist.');
assert_contains('php_flag engine off', $uploadsHtaccess, 'Uploads directory should disable PHP execution.');
assert_contains('php[0-9]?', $uploadsHtaccess, 'Uploads directory should block script extensions.');
assert_contains('^[A-Fa-f0-9]{32}', $uploadsHtaccess, 'Uploads directory should only allow randomized upload filenames.');

echo "Directory access protections verified.\n";
