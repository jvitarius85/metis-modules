<?php
declare(strict_types=1);

namespace Metis\Backup;

final class BackupRunner {
    public function __construct(
        private readonly BackupService $service
    ) {}

    public function run( string $trigger = 'manual' ): array {
        return $this->service->runBackup( $trigger );
    }
}
