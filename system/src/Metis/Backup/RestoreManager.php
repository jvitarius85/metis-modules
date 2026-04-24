<?php
declare(strict_types=1);

namespace Metis\Backup;

final class RestoreManager {
    public function __construct(
        private readonly BackupService $service
    ) {}

    public function restore( string $runUuid ): array {
        return $this->service->restoreRun( $runUuid );
    }
}
