<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( 'standalone_bootstrap' );

$show_help = in_array('--help', $argv, true) || in_array('-h', $argv, true);
if ($show_help) {
    echo "Usage: php tools/repair_entity_codes.php [--apply] [--preserve-valid] [--no-legacy-sync] [--dry-run]\n";
    echo "  --apply          Persist changes. Without this flag, the script runs in dry-run mode.\n";
    echo "  --preserve-valid Keep valid current codes and only repair invalid/mismatched rows.\n";
    echo "  --no-legacy-sync Do not mirror the UID into legacy columns.\n";
    echo "  --dry-run        Force dry-run mode (overrides --apply).\n";
    exit(0);
}

try {
    if ( ! function_exists( 'metis_standalone_has_database_config' ) || ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();

    \Metis\Modules\Contacts\SchemaManager::ensureSchema();
    \Metis\Modules\People\SchemaManager::ensureSchema();
    \Metis\Modules\Forms\SchemaManager::ensureSchema();
    \Metis\Modules\Newsletter\SchemaManager::ensureSchema();
    \Metis\Modules\Board\SchemaManager::ensureSchema();
    \Metis\Modules\Calendar\SyncStore::ensureSchema();
    \Metis\Modules\Finance\SchemaManager::ensureSchema();
    \Metis\Modules\Hermes\SchemaManager::ensureSchema();
    \Metis\Modules\Website\SchemaManager::ensureSchema();

    $rewrite_all = !in_array('--preserve-valid', $argv, true);
    $rewrite_all = $rewrite_all || in_array('--rewrite-all', $argv, true);
    $apply = in_array('--apply', $argv, true);
    $dry_run = in_array('--dry-run', $argv, true) || !$apply;
    $sync_legacy = ! in_array('--no-legacy-sync', $argv, true);

    $service = \metis_entity_id_service();
    $service->ensureSchema();
    $summary = $service->repairEntityCodes($sync_legacy, $rewrite_all, $dry_run);

    echo $dry_run ? "Metis entity code repair dry run complete.\n" : "Metis entity code repair complete.\n";
    echo 'Rewrite all: ' . ($rewrite_all ? 'yes' : 'no') . "\n";
    echo 'Sync legacy: ' . ($sync_legacy ? 'yes' : 'no') . "\n";
    echo 'Updated rows: ' . (int) ($summary['updated_rows'] ?? 0) . "\n";
    echo 'Registry rows: ' . (int) ($summary['registry_rows'] ?? 0) . "\n";

    foreach ((array) ($summary['entities'] ?? []) as $entity_type => $entity_summary) {
        echo sprintf(
            "- %s: table=%s updated=%d registry=%d\n",
            (string) $entity_type,
            (string) ($entity_summary['table'] ?? ''),
            (int) ($entity_summary['updated_rows'] ?? 0),
            (int) ($entity_summary['registry_rows'] ?? 0)
        );
    }
} catch ( \Throwable $e ) {
    fwrite( STDERR, 'repair_entity_codes failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}
