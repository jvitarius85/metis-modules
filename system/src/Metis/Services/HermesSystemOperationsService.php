<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;

final class HermesSystemOperationsService {
    public function runBackup( mixed $request = null ): array {
        return $this->queueOperation( 'backup.run', [], 'Backup operation queued.' );
    }

    public function restoreBackup( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $runUuid = trim( (string) ( $request['run_uuid'] ?? '' ) );
        if ( $runUuid === '' ) {
            throw new \RuntimeException( 'Backup run ID is required.' );
        }

        return $this->queueOperation(
            'backup.restore',
            [ 'run_uuid' => $runUuid ],
            sprintf( 'Backup restore for run [%s] queued.', $runUuid )
        );
    }

    public function validateBackup( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $runUuid = trim( (string) ( $request['run_uuid'] ?? '' ) );
        if ( $runUuid === '' ) {
            throw new \RuntimeException( 'Backup run ID is required.' );
        }

        return $this->queueOperation(
            'backup.stage',
            [
                'run_uuid' => $runUuid,
                'stage' => 'verify',
            ],
            sprintf( 'Backup validation for run [%s] queued.', $runUuid )
        );
    }

    public function restoreFile( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $runUuid = trim( (string) ( $request['run_uuid'] ?? '' ) );
        $relativePath = trim( str_replace( '\\', '/', (string) ( $request['relative_path'] ?? $request['file_key'] ?? '' ) ) );
        if ( $runUuid === '' || $relativePath === '' ) {
            throw new \RuntimeException( 'Backup run ID and restore file path are required.' );
        }

        return $this->queueOperation(
            'backup.file_restore',
            [
                'run_uuid' => $runUuid,
                'relative_path' => $relativePath,
            ],
            sprintf( 'Backup file restore for [%s] from run [%s] queued.', $relativePath, $runUuid )
        );
    }

    public function installSystemUpdate( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $tag = trim( (string) ( $request['tag'] ?? '' ) );

        if ( $tag !== '' ) {
            return $this->queueOperation(
                'release.apply',
                [ 'tag' => $tag ],
                sprintf( 'System update [%s] queued.', $tag )
            );
        }

        return $this->queueOperation( 'release.auto_update', [], 'System update queued.' );
    }

    public function rollbackRelease( mixed $request = null ): array {
        return $this->queueOperation( 'release.rollback', [], 'Release rollback queued.' );
    }

    public function queueDriveSync( mixed $request = null ): array {
        return $this->queueOperation( 'drive.sync', [], 'Drive sync queued.' );
    }

    public function queueCalendarSync( mixed $request = null ): array {
        return $this->queueOperation( 'calendar.sync', [], 'Calendar sync queued.' );
    }

    public function drainQueue( mixed $request = null ): array {
        return $this->queueOperation( 'queue.drain', [], 'Queue drain queued.' );
    }

    public function buildIntegrityBaseline( mixed $request = null ): array {
        return $this->queueOperation( 'integrity.baseline', [], 'Integrity baseline build queued.' );
    }

    public function runModuleComplianceAudit( mixed $request = null ): array {
        return $this->queueOperation( 'module.compliance.audit', [], 'Module compliance audit queued.' );
    }

    public function prepareBoardWorkspace( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $meeting = trim( (string) ( $request['meeting'] ?? $request['subject'] ?? '' ) );
        if ( $meeting === '' ) {
            throw new \RuntimeException( 'Board meeting ID or code is required.' );
        }

        return $this->queueOperation(
            'board.workspace.prepare',
            [ 'meeting' => $meeting ],
            sprintf( 'Board workspace preparation for [%s] queued.', $meeting )
        );
    }

    public function clearCache( mixed $request = null ): array {
        CacheService::clearAll();
        if ( \function_exists( 'metis_standalone_invalidate_config_cache' ) ) {
            \metis_standalone_invalidate_config_cache();
        }
        if ( \function_exists( 'metis_reports_clear_cache' ) ) {
            \metis_reports_clear_cache();
        }

        return [
            'status' => 'success',
            'cache' => [
                'cleared' => true,
                'groups' => [ 'runtime', 'query', 'fragments', 'hermes', 'reports' ],
            ],
            'message' => 'Cache cleared.',
        ];
    }

    private function queueOperation( string $operation, array $payload, string $message ): array {
        if ( ! Application::has_service( 'operations' ) ) {
            throw new \RuntimeException( 'Operations service is not available.' );
        }

        $queued = Application::service( 'operations' )->queueOperation( $operation, $payload );
        if ( ! is_array( $queued ) || empty( $queued['ok'] ) ) {
            throw new \RuntimeException( (string) ( $queued['message'] ?? 'Failed to queue system operation.' ) );
        }

        return [
            'status' => 'success',
            'queued' => [
                'operation' => $operation,
                'job_code' => (string) ( $queued['job_code'] ?? '' ),
                'queue' => (string) ( $queued['queue_name'] ?? 'system' ),
                'payload' => $payload,
            ],
            'message' => $message,
        ];
    }
}
