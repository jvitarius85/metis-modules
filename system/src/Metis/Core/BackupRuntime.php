<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/Autoload.php';

function metis_backup_service(): \Metis\Backup\BackupManager {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'backup' );
}

function metis_backup_can_manage(): bool {
    return function_exists( 'metis_current_user_can' ) ? metis_current_user_can( 'manage_options' ) : false;
}

function metis_backup_run_now( string $trigger = 'manual' ): array {
    return metis_backup_service()->runBackup( $trigger );
}

function metis_backup_run_stage( string $run_uuid, string $stage ): array {
    return metis_backup_service()->runBackupStage( $run_uuid, $stage );
}

function metis_backup_list_runs( int $limit = 20 ): array {
    return metis_backup_service()->listRuns( $limit );
}

function metis_backup_pause_status(): array {
    return metis_backup_service()->pauseStatus();
}

function metis_backup_restore_run( string $run_uuid ): array {
    return metis_backup_service()->restoreRun( $run_uuid );
}

function metis_backup_restore_file( string $run_uuid, string $relative_path ): array {
    return metis_backup_service()->restoreFile( $run_uuid, $relative_path );
}

metis_on(
    'init',
    static function (): void {
        metis_backup_service()->ensureSchema();
    },
    5
);

if ( class_exists( 'Metis_Cron_Manager' ) ) {
    Metis_Cron_Manager::register_task(
        'system_backup_snapshot',
        static function (): array {
            return metis_backup_service()->runBackup( 'system_cron' );
        },
        [
            'label'    => 'System Backup Snapshot',
            'interval' => DAY_IN_SECONDS,
            'lock_ttl' => 3 * HOUR_IN_SECONDS,
            'module'   => 'core',
        ]
    );
}
