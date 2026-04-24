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

    $service = \metis_entity_id_service();
    $service->ensureSchema();
    $summary = $service->migrateExistingRecords(true);

    echo "Metis entity identifier migration complete.\n";
    echo 'Updated rows: ' . (int) ($summary['updated_rows'] ?? 0) . "\n";
    echo 'Registry rows: ' . (int) ($summary['registry_rows'] ?? 0) . "\n";

    foreach ((array) ($summary['entities'] ?? []) as $entity_type => $entity_summary) {
        echo sprintf(
            "- %s: table=%s updated=%d registry=%d next=%d\n",
            (string) $entity_type,
            (string) ($entity_summary['table'] ?? ''),
            (int) ($entity_summary['updated_rows'] ?? 0),
            (int) ($entity_summary['registry_rows'] ?? 0),
            (int) ($entity_summary['next_value'] ?? 0)
        );
    }
} catch ( \Throwable $e ) {
    fwrite( STDERR, 'migrate_entity_ids failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}
