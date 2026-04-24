<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
define('METIS_STANDALONE', true);
define('METIS_PREFIX', 'metis');
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
$defaultLegacyCmsTables = [
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

$database = $GLOBALS['metis_db_connection'] ?? null;
if (!is_object($database)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

$tables = array_map('strval', $database->get_col('SHOW TABLES') ?: []);
$metisTables = array_values(class_exists('Metis_Tables') ? Metis_Tables::definitions() : []);
$metisTables = array_map('strval', $metisTables);

$workspaceFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    if (!preg_match('/\.(php|json|md)$/', $path)) {
        continue;
    }

    $workspaceFiles[] = $path;
}

$referenceCache = [];
$referenceCount = static function (string $needle) use (&$referenceCache, $workspaceFiles): int {
    if (isset($referenceCache[$needle])) {
        return $referenceCache[$needle];
    }

    $count = 0;
    foreach ($workspaceFiles as $path) {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            continue;
        }

        if (str_contains($contents, $needle)) {
            $count++;
        }
    }

    $referenceCache[$needle] = $count;
    return $count;
};

$candidates = [];
foreach ($tables as $table) {
    if (in_array($table, $metisTables, true)) {
        continue;
    }

    if (!in_array($table, $defaultLegacyCmsTables, true) && !str_starts_with($table, $legacyPrefix)) {
        continue;
    }

    $references = $referenceCount($table);
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
        $database->query("DROP TABLE IF EXISTS `{$table}`");
        $removed[] = $table;

        if (function_exists('metis_audit_log_activity')) {
            metis_audit_log_activity('database_cleanup_removed_table', [
                'module' => 'core',
                'resource' => ['type' => 'database_table', 'id' => $table, 'label' => $table],
                'context' => ['table' => $table],
            ]);
        }
    }
}

$result = [
    'apply' => $apply,
    'all_tables' => $tables,
    'candidate_tables' => $candidates,
    'removed_tables' => $removed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
