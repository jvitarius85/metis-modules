<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
define('METIS_STANDALONE', true);
define('METIS_PREFIX', 'metis');
define('METIS_ROOT', $root . '/');
define('METIS_PATH', $root . '/');
define('METIS_URL', 'http://localhost/metis/');

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_core_bootstrap('standalone_bootstrap');

if (!metis_standalone_has_database_config()) {
    fwrite(STDERR, "Missing database configuration.\n");
    exit(1);
}

metis_standalone_boot();

$apply = in_array('--apply', $argv, true);
$legacyPrefix = 'w' . 'p_';
$legacyCmsTables = [
    $legacyPrefix . 'posts',
    $legacyPrefix . 'postmeta',
    $legacyPrefix . 'users',
    $legacyPrefix . 'usermeta',
    $legacyPrefix . 'options',
    $legacyPrefix . 'comments',
    $legacyPrefix . 'links',
    $legacyPrefix . 'terms',
    $legacyPrefix . 'term_relationships',
    $legacyPrefix . 'term_taxonomy',
];

$database = function_exists('metis_db') ? metis_db() : null;
if (!is_object($database) || !method_exists($database, 'column')) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$tables = array_map('strval', $database->column('SHOW TABLES') ?: []);
$codeFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    if (!preg_match('/\.(php|json|md|js|css)$/', $path)) {
        continue;
    }

    $codeFiles[] = $path;
}

$countReferences = static function (string $needle) use ($codeFiles): int {
    $matches = 0;
    foreach ($codeFiles as $path) {
        $contents = @file_get_contents($path);
        if (!is_string($contents) || !str_contains($contents, $needle)) {
            continue;
        }

        $matches++;
    }

    return $matches;
};

$candidates = [];
foreach ($tables as $table) {
    if (!in_array($table, $legacyCmsTables, true) && !str_starts_with($table, $legacyPrefix)) {
        continue;
    }

    $references = $countReferences($table);
    $candidates[] = [
        'table' => $table,
        'references' => $references,
        'drop_safe' => $references === 0,
    ];
}

$removed = [];
if ($apply) {
    foreach ($candidates as $candidate) {
        if (empty($candidate['drop_safe'])) {
            continue;
        }

        $table = (string) $candidate['table'];
        $database->execute("DROP TABLE IF EXISTS `{$table}`");
        $removed[] = $table;
    }
}

echo json_encode([
    'apply' => $apply,
    'candidate_tables' => $candidates,
    'removed_tables' => $removed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
