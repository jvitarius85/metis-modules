<?php
declare(strict_types=1);

namespace Metis\Backup;

final class BackupManager {
    private BackupService $service;

    public function __construct( ?BackupService $service = null ) {
        $this->service = $service ?? new BackupService();
    }

    public function ensureSchema(): void {
        $this->service->ensureSchema();
    }

    public function runBackup( string $trigger = 'manual' ): array {
        return ( new BackupRunner( $this->service ) )->run( $trigger );
    }

    public function listRuns( int $limit = 20 ): array {
        return $this->service->listRuns( $limit );
    }

    public function restoreRun( string $runUuid ): array {
        return ( new RestoreManager( $this->service ) )->restore( $runUuid );
    }
}
