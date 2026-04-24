<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;

final class HermesSystemOperationsService {
    public function runBackup( mixed $request = null ): array {
        return $this->queueOperation( 'backup.run', 'Backup operation queued.' );
    }

    public function syncDrive( mixed $request = null ): array {
        if ( ! \function_exists( 'metis_drive_sync_all_configured_drives' ) ) {
            return [
                'status' => 'skipped',
                'sync' => [ 'target' => 'drive', 'result' => [ 'status' => 'skipped' ] ],
                'message' => 'Drive sync runtime is not available.',
            ];
        }

        $result = \metis_drive_sync_all_configured_drives();
        return [
            'status' => 'success',
            'sync' => [ 'target' => 'drive', 'result' => $result ],
            'message' => 'Drive sync completed.',
        ];
    }

    public function syncCalendar( mixed $request = null ): array {
        if ( ! \function_exists( 'metis_calendar_sync_all_configured_calendars' ) ) {
            throw new \RuntimeException( 'Calendar sync is not available.' );
        }

        $result = \metis_calendar_sync_all_configured_calendars();
        return [
            'status' => 'success',
            'sync' => [ 'target' => 'calendar', 'result' => $result ],
            'message' => 'Calendar sync completed.',
        ];
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

    private function queueOperation( string $operation, string $message ): array {
        if ( ! Application::has_service( 'operations' ) ) {
            throw new \RuntimeException( 'Operations service is not available.' );
        }

        $queued = Application::service( 'operations' )->queueOperation( $operation, [] );
        if ( ! is_array( $queued ) || empty( $queued['ok'] ) ) {
            throw new \RuntimeException( (string) ( $queued['message'] ?? 'Failed to queue system operation.' ) );
        }

        return [
            'status' => 'success',
            'queued' => [
                'operation' => $operation,
                'job_code' => (string) ( $queued['job_code'] ?? '' ),
                'queue' => (string) ( $queued['queue_name'] ?? 'system' ),
            ],
            'message' => $message,
        ];
    }
}
