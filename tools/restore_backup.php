<?php
declare(strict_types=1);

define( 'METIS_STANDALONE', true );
define( 'METIS_PATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/includes/core/bootstrap.php';
metis_core_bootstrap( 'standalone_bootstrap' );
metis_standalone_boot();

$run_uuid = '';
foreach ( $argv as $arg ) {
    if ( str_starts_with( (string) $arg, '--run=' ) ) {
        $run_uuid = trim( substr( (string) $arg, 6 ) );
    }
}

if ( $run_uuid === '' ) {
    fwrite( STDERR, "Usage: php tools/restore_backup.php --run=<run_uuid>\n" );
    exit( 1 );
}

$result = metis_backup_restore_run( $run_uuid );

if ( empty( $result['ok'] ) ) {
    fwrite( STDERR, 'Restore failed: ' . (string) ( $result['error'] ?? 'Unknown error' ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, 'Restore completed: ' . (string) ( $result['run_uuid'] ?? '' ) . PHP_EOL );
fwrite( STDOUT, 'Restored at: ' . (string) ( $result['restored_at'] ?? '' ) . PHP_EOL );
