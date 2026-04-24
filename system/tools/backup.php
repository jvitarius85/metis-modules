<?php
declare(strict_types=1);

define( 'METIS_STANDALONE', true );
define( 'METIS_PATH', dirname( __DIR__, 2 ) . '/' );

require_once dirname( __DIR__ ) . '/src/Metis/Core/CoreBootstrap.php';
metis_core_bootstrap( 'standalone_bootstrap' );
metis_standalone_boot();

$trigger = 'cli_manual';
foreach ( $argv as $arg ) {
    if ( str_starts_with( (string) $arg, '--trigger=' ) ) {
        $trigger = trim( substr( (string) $arg, 10 ) ) ?: $trigger;
    }
}

$result = metis_backup_run_now( $trigger );

if ( empty( $result['ok'] ) ) {
    fwrite( STDERR, 'Backup failed: ' . (string) ( $result['error'] ?? 'Unknown error' ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, 'Backup completed: ' . (string) ( $result['run_uuid'] ?? '' ) . PHP_EOL );
fwrite( STDOUT, 'Environment: ' . (string) ( $result['environment'] ?? '' ) . PHP_EOL );
fwrite( STDOUT, 'Drive folder: ' . (string) ( $result['drive_folder_id'] ?? '' ) . PHP_EOL );
