<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Jobs\JobQueue;
use Metis\Core\Jobs\JobWorkerRegistry;
use Metis\Services\DatabaseService;
use RuntimeException;

// @metis-governance ajax-security: queued operations preserve caller nonce, csrf, permission, and SecureEnclave validation context.
final class OperationsService {
    private const JOB_TYPE = 'system.operation';

    private bool $registered = false;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly JobQueue $jobs,
        private readonly JobWorkerRegistry $workers
    ) {
        $this->registerWorker();
    }

    public function queueOperation( string $operation, array $payload = [], array $options = [] ): array {
        $spec = $this->operationSpec( $operation );
        if ( $spec === null ) {
            return [ 'ok' => false, 'message' => 'Operation is not allowed.' ];
        }

        $createdBy = (int) ( $options['created_by'] ?? ( \function_exists( 'metis_current_user_id' ) ? \metis_current_user_id() : 0 ) );
        $dedupeKey = trim( (string) ( $options['dedupe_key'] ?? $this->defaultDedupeKey( $operation, $payload ) ) );

        return $this->jobs->enqueue(
            self::JOB_TYPE,
            [
                'operation'  => $this->normalizeOperation( $operation ),
                'payload'    => $payload,
                'label'      => (string) $spec['label'],
                'requested_by' => $createdBy,
                'requested_at' => \metis_current_time( 'mysql' ),
            ],
            [
                'queue'        => 'system',
                'priority'     => (int) ( $spec['priority'] ?? 20 ),
                'max_attempts' => (int) ( $spec['max_attempts'] ?? 2 ),
                'dedupe_key'   => $dedupeKey,
                'created_by'   => $createdBy,
            ]
        );
    }

    public function queueCommand( string $command, int $createdBy = 0 ): array {
        $parsed = $this->parseCommand( $command );
        if ( empty( $parsed['ok'] ) ) {
            return $parsed;
        }

        $queued = $this->queueOperation(
            (string) $parsed['operation'],
            (array) ( $parsed['payload'] ?? [] ),
            [
                'created_by' => $createdBy,
                'dedupe_key' => (string) ( $parsed['dedupe_key'] ?? '' ),
            ]
        );

        if ( empty( $queued['ok'] ) ) {
            return $queued;
        }

        $queued['normalized_command'] = (string) ( $parsed['normalized_command'] ?? '' );
        return $queued;
    }

    public function commandCatalog(): array {
        return [
            [ 'command' => 'cron run <task-slug>', 'description' => 'Queue a specific cron task immediately.' ],
            [ 'command' => 'queue drain', 'description' => 'Process queued background jobs now.' ],
            [ 'command' => 'drive sync', 'description' => 'Queue a full configured Drive sync.' ],
            [ 'command' => 'calendar sync', 'description' => 'Queue a full configured Calendar sync.' ],
            [ 'command' => 'cache clear', 'description' => 'Queue a runtime cache clear.' ],
            [ 'command' => 'backup run', 'description' => 'Queue an immediate system backup.' ],
            [ 'command' => 'backup restore <run-uuid>', 'description' => 'Queue a restore from a specific backup run.' ],
            [ 'command' => 'release check', 'description' => 'Refresh trusted release metadata.' ],
            [ 'command' => 'release apply <tag>', 'description' => 'Queue application of a trusted release.' ],
            [ 'command' => 'release rollback', 'description' => 'Queue rollback to the previous trusted release.' ],
            [ 'command' => 'integrity baseline', 'description' => 'Rebuild the integrity baseline.' ],
            [ 'command' => 'module compliance', 'description' => 'Run module compliance verification.' ],
            [ 'command' => 'board prepare workspace <meeting-id>', 'description' => 'Create or link the Drive workspace folders for a board meeting.' ],
        ];
    }

    public function queueSummary(): array {
        $this->jobs->recoverExpiredProcessingJobs();
        $this->jobs->pruneCompletedJobs();

        $table = \Metis_Tables::get( 'job_queue' );
        $rows = $this->db->fetchAll(
            "SELECT job_type, status, COUNT(*) AS total
             FROM {$table}
             WHERE job_type IN (%s, %s, %s, %s)
             GROUP BY job_type, status",
            [ 'system.cron.task', self::JOB_TYPE, 'hermes.diagnostics', 'hermes.diagnostics.full' ]
        );

        $summary = [
            'cron' => [ 'queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0 ],
            'operations' => [ 'queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0 ],
            'hermes' => [ 'queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0 ],
        ];

        foreach ( $rows as $row ) {
            $jobType = (string) ( $row['job_type'] ?? '' );
            $bucket = match ( $jobType ) {
                'system.cron.task' => 'cron',
                'hermes.diagnostics', 'hermes.diagnostics.full' => 'hermes',
                default => 'operations',
            };
            $status = strtolower( (string) ( $row['status'] ?? '' ) );
            if ( isset( $summary[ $bucket ][ $status ] ) ) {
                $summary[ $bucket ][ $status ] = (int) ( $row['total'] ?? 0 );
            }
        }

        return $summary;
    }

    public function recentJobs( int $limit = 20, array $jobTypes = [ 'system.cron.task', self::JOB_TYPE, 'hermes.diagnostics', 'hermes.diagnostics.full' ] ): array {
        $this->jobs->recoverExpiredProcessingJobs();

        $limit = max( 1, min( 100, $limit ) );
        $placeholders = implode( ', ', array_fill( 0, count( $jobTypes ), '%s' ) );
        $args = array_values( $jobTypes );
        $args[] = $limit;

        $table = \Metis_Tables::get( 'job_queue' );
        $rows = $this->db->fetchAll(
            "SELECT id, job_code, queue_name, job_type, status, attempts, max_attempts, payload_json, result_json, last_error, created_by, available_at, reserved_at, reserved_until, started_at, completed_at, failed_at
             FROM {$table}
             WHERE job_type IN ({$placeholders})
             ORDER BY CASE WHEN status IN ('failed', 'processing', 'queued') THEN 0 ELSE 1 END, id DESC
             LIMIT %d",
            $args
        );

        return array_map( function ( array $row ): array {
            $payload = $this->decodeJson( (string) ( $row['payload_json'] ?? '' ) );
            $result  = $this->decodeJson( (string) ( $row['result_json'] ?? '' ) );
            $job_type = (string) ( $row['job_type'] ?? '' );
            $raw_last_error = (string) ( $row['last_error'] ?? '' );
            $public_last_error = '';
            if ( $raw_last_error !== '' ) {
                $public_last_error = $job_type === 'system.cron.task'
                    ? 'Task failed. Review logs for details.'
                    : 'Operation failed. Review logs for details.';
            }
            return [
                'id'           => (int) ( $row['id'] ?? 0 ),
                'job_code'     => (string) ( $row['job_code'] ?? '' ),
                'queue_name'   => (string) ( $row['queue_name'] ?? '' ),
                'job_type'     => $job_type,
                'status'       => (string) ( $row['status'] ?? '' ),
                'attempts'     => (int) ( $row['attempts'] ?? 0 ),
                'max_attempts' => (int) ( $row['max_attempts'] ?? 0 ),
                'label'        => (string) ( $payload['label'] ?? '' ),
                'operation'    => (string) ( $payload['operation'] ?? '' ),
                'task'         => (string) (
                    $payload['task']
                    ?? $payload['task_slug']
                    ?? $payload['payload']['task_slug']
                    ?? $payload['payload']['task']
                    ?? ''
                ),
                'created_by'   => (int) ( $row['created_by'] ?? 0 ),
                'available_at' => (string) ( $row['available_at'] ?? '' ),
                'reserved_at'  => (string) ( $row['reserved_at'] ?? '' ),
                'reserved_until' => (string) ( $row['reserved_until'] ?? '' ),
                'started_at'   => (string) ( $row['started_at'] ?? '' ),
                'completed_at' => (string) ( $row['completed_at'] ?? '' ),
                'failed_at'    => (string) ( $row['failed_at'] ?? '' ),
                'last_error'   => $public_last_error,
                'payload'      => $payload,
                'result'       => $result,
            ];
        }, $rows );
    }

    public function executeQueuedOperation( array $jobPayload ): array {
        $operation = $this->normalizeOperation( (string) ( $jobPayload['operation'] ?? '' ) );
        $payload = is_array( $jobPayload['payload'] ?? null ) ? $jobPayload['payload'] : [];

        return match ( $operation ) {
            'cron.task.run' => $this->runCronTaskOperation( $payload ),
            'queue.drain' => [ 'operation' => $operation, 'result' => \Metis_Cron_Manager::drain_job_queue( 'operations_console' ) ],
            'drive.sync' => $this->runDriveSyncOperation( $operation ),
            'calendar.sync' => $this->runCalendarSyncOperation( $operation ),
            'cache.clear' => $this->runCacheClearOperation( $operation ),
            'backup.run' => $this->runBackupOperation( $operation ),
            'backup.stage' => $this->runBackupStageOperation( $operation, $payload ),
            'backup.restore' => $this->runBackupRestoreOperation( $operation, $payload ),
            'release.check' => $this->runReleaseCheckOperation( $operation ),
            'release.apply' => $this->runReleaseApplyOperation( $operation, $payload ),
            'release.rollback' => $this->runReleaseRollbackOperation( $operation ),
            'integrity.baseline' => $this->runIntegrityBaselineOperation( $operation ),
            'module.compliance.audit' => $this->runModuleComplianceAuditOperation( $operation ),
            'board.workspace.prepare' => $this->runBoardWorkspacePrepareOperation( $operation, $payload ),
            default => throw new RuntimeException( 'Unknown queued operation.' ),
        };
    }

    private function registerWorker(): void {
        if ( $this->registered ) {
            return;
        }

        $this->workers->register(
            self::JOB_TYPE,
            fn ( array $payload ): array => $this->executeQueuedOperation( $payload )
        );

        $this->registered = true;
    }

    private function parseCommand( string $command ): array {
        $command = trim( preg_replace( '/\s+/', ' ', $command ) ?? '' );
        $lower = strtolower( $command );

        if ( preg_match( '/^cron run ([a-z0-9_-]+)$/', $lower, $matches ) ) {
            $task = \metis_key_clean( (string) $matches[1] );
            return [
                'ok' => true,
                'operation' => 'cron.task.run',
                'payload' => [ 'task_slug' => $task ],
                'dedupe_key' => 'operation:cron.task.run:' . $task,
                'normalized_command' => 'cron run ' . $task,
            ];
        }

        if ( $lower === 'queue drain' ) {
            return [
                'ok' => true,
                'operation' => 'queue.drain',
                'payload' => [],
                'dedupe_key' => 'operation:queue.drain',
                'normalized_command' => 'queue drain',
            ];
        }

        if ( $lower === 'drive sync' ) {
            return [ 'ok' => true, 'operation' => 'drive.sync', 'payload' => [], 'dedupe_key' => 'operation:drive.sync', 'normalized_command' => 'drive sync' ];
        }

        if ( $lower === 'calendar sync' ) {
            return [ 'ok' => true, 'operation' => 'calendar.sync', 'payload' => [], 'dedupe_key' => 'operation:calendar.sync', 'normalized_command' => 'calendar sync' ];
        }

        if ( $lower === 'cache clear' || $lower === 'clear cache' ) {
            return [ 'ok' => true, 'operation' => 'cache.clear', 'payload' => [], 'dedupe_key' => 'operation:cache.clear', 'normalized_command' => 'cache clear' ];
        }

        if ( $lower === 'backup run' ) {
            return [ 'ok' => true, 'operation' => 'backup.run', 'payload' => [], 'dedupe_key' => 'operation:backup.run', 'normalized_command' => 'backup run' ];
        }

        if ( preg_match( '/^backup restore ([a-z0-9_-]+)$/', $lower, $matches ) ) {
            $runUuid = trim( (string) $matches[1] );
            return [
                'ok' => true,
                'operation' => 'backup.restore',
                'payload' => [ 'run_uuid' => $runUuid ],
                'dedupe_key' => 'operation:backup.restore:' . $runUuid,
                'normalized_command' => 'backup restore ' . $runUuid,
            ];
        }

        if ( $lower === 'release check' ) {
            return [ 'ok' => true, 'operation' => 'release.check', 'payload' => [], 'dedupe_key' => 'operation:release.check', 'normalized_command' => 'release check' ];
        }

        if ( preg_match( '/^release apply ([a-z0-9._-]+)$/', $command, $matches ) ) {
            $tag = trim( (string) $matches[1] );
            return [
                'ok' => true,
                'operation' => 'release.apply',
                'payload' => [ 'tag' => $tag ],
                'dedupe_key' => 'operation:release.apply:' . strtolower( $tag ),
                'normalized_command' => 'release apply ' . $tag,
            ];
        }

        if ( $lower === 'release rollback' ) {
            return [ 'ok' => true, 'operation' => 'release.rollback', 'payload' => [], 'dedupe_key' => 'operation:release.rollback', 'normalized_command' => 'release rollback' ];
        }

        if ( $lower === 'integrity baseline' ) {
            return [ 'ok' => true, 'operation' => 'integrity.baseline', 'payload' => [], 'dedupe_key' => 'operation:integrity.baseline', 'normalized_command' => 'integrity baseline' ];
        }

        if ( $lower === 'module compliance' || $lower === 'compliance module' ) {
            return [ 'ok' => true, 'operation' => 'module.compliance.audit', 'payload' => [], 'dedupe_key' => 'operation:module.compliance.audit', 'normalized_command' => 'module compliance' ];
        }

        if ( preg_match( '/^board prepare workspace ([a-z0-9_-]+)$/', $lower, $matches ) ) {
            $meeting = trim( (string) $matches[1] );
            return [
                'ok' => true,
                'operation' => 'board.workspace.prepare',
                'payload' => [ 'meeting' => $meeting ],
                'dedupe_key' => 'operation:board.workspace.prepare:' . $meeting,
                'normalized_command' => 'board prepare workspace ' . $meeting,
            ];
        }

        return [ 'ok' => false, 'message' => 'Command is not allowed.' ];
    }

    private function operationSpec( string $operation ): ?array {
        return match ( $this->normalizeOperation( $operation ) ) {
            'cron.task.run'      => [ 'label' => 'Run Cron Task', 'priority' => 8, 'max_attempts' => 2 ],
            'queue.drain'        => [ 'label' => 'Drain Queue', 'priority' => 5, 'max_attempts' => 1 ],
            'drive.sync'         => [ 'label' => 'Drive Sync', 'priority' => 15, 'max_attempts' => 2 ],
            'calendar.sync'      => [ 'label' => 'Calendar Sync', 'priority' => 15, 'max_attempts' => 2 ],
            'cache.clear'        => [ 'label' => 'Clear Runtime Cache', 'priority' => 10, 'max_attempts' => 1 ],
            'backup.run'         => [ 'label' => 'Run Backup', 'priority' => 12, 'max_attempts' => 2 ],
            'backup.stage'       => [ 'label' => 'Run Backup Stage', 'priority' => 11, 'max_attempts' => 1 ],
            'backup.restore'     => [ 'label' => 'Restore Backup', 'priority' => 6, 'max_attempts' => 1 ],
            'release.check'      => [ 'label' => 'Check Releases', 'priority' => 16, 'max_attempts' => 2 ],
            'release.apply'      => [ 'label' => 'Apply Release', 'priority' => 7, 'max_attempts' => 1 ],
            'release.rollback'   => [ 'label' => 'Rollback Release', 'priority' => 7, 'max_attempts' => 1 ],
            'integrity.baseline' => [ 'label' => 'Build Integrity Baseline', 'priority' => 14, 'max_attempts' => 1 ],
            'module.compliance.audit' => [ 'label' => 'Module Compliance Audit', 'priority' => 8, 'max_attempts' => 1 ],
            'board.workspace.prepare' => [ 'label' => 'Prepare Board Workspace', 'priority' => 12, 'max_attempts' => 1 ],
            default => null,
        };
    }

    private function defaultDedupeKey( string $operation, array $payload ): string {
        $normalized = $this->normalizeOperation( $operation );
        if ( isset( $payload['task_slug'] ) ) {
            return 'operation:' . $normalized . ':' . \metis_key_clean( (string) $payload['task_slug'] );
        }
        if ( isset( $payload['run_uuid'] ) ) {
            $stage = isset( $payload['stage'] ) ? ':' . \metis_key_clean( (string) $payload['stage'] ) : '';
            return 'operation:' . $normalized . ':' . trim( (string) $payload['run_uuid'] ) . $stage;
        }
        if ( isset( $payload['tag'] ) ) {
            return 'operation:' . $normalized . ':' . strtolower( trim( (string) $payload['tag'] ) );
        }
        if ( isset( $payload['meeting'] ) ) {
            return 'operation:' . $normalized . ':' . \metis_key_clean( (string) $payload['meeting'] );
        }

        return 'operation:' . $normalized;
    }

    private function normalizeOperation( string $operation ): string {
        return trim( strtolower( preg_replace( '/[^a-z0-9._-]+/', '', $operation ) ?? '' ) );
    }

    private function decodeJson( string $json ): array {
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function runCronTaskOperation( array $payload ): array {
        $taskSlug = \metis_key_clean( (string) ( $payload['task_slug'] ?? '' ) );
        if ( $taskSlug === '' ) {
            throw new RuntimeException( 'Task slug is required.' );
        }

        return [
            'operation' => 'cron.task.run',
            'task_slug' => $taskSlug,
            'result'    => \Metis_Cron_Manager::run_task_now( $taskSlug, 'settings_operations' ),
        ];
    }

    private function runDriveSyncOperation( string $operation ): array {
        if ( ! \function_exists( 'metis_drive_sync_all_configured_drives' ) ) {
            return [
                'operation' => $operation,
                'result' => [
                    'status' => 'skipped',
                    'message' => 'Drive sync runtime is not available.',
                ],
            ];
        }

        return [ 'operation' => $operation, 'result' => \metis_drive_sync_all_configured_drives() ];
    }

    private function runCalendarSyncOperation( string $operation ): array {
        if ( ! \function_exists( 'metis_calendar_sync_all_configured_calendars' ) ) {
            throw new RuntimeException( 'Calendar sync is not available.' );
        }

        return [ 'operation' => $operation, 'result' => \metis_calendar_sync_all_configured_calendars() ];
    }

    private function runCacheClearOperation( string $operation ): array {
        \Metis\Core\Cache\CacheService::clearAll();
        if ( \function_exists( 'metis_standalone_invalidate_config_cache' ) ) {
            \metis_standalone_invalidate_config_cache();
        }
        if ( \function_exists( 'metis_reports_clear_cache' ) ) {
            \metis_reports_clear_cache();
        }

        return [
            'operation' => $operation,
            'result' => [
                'cleared' => true,
                'groups' => [ 'runtime', 'query', 'fragments', 'hermes', 'reports' ],
            ],
        ];
    }

    private function runBackupOperation( string $operation ): array {
        if ( ! \function_exists( 'metis_backup_run_now' ) ) {
            throw new RuntimeException( 'Backup service is not available.' );
        }

        $result = \metis_backup_run_now( 'settings_operations' );
        if ( empty( $result['ok'] ) ) {
            throw new RuntimeException( (string) ( $result['error'] ?? 'Backup could not be queued.' ) );
        }

        return [ 'operation' => $operation, 'result' => $result ];
    }

    private function runBackupStageOperation( string $operation, array $payload ): array {
        if ( ! \function_exists( 'metis_backup_run_stage' ) ) {
            throw new RuntimeException( 'Backup stage service is not available.' );
        }

        $runUuid = trim( (string) ( $payload['run_uuid'] ?? '' ) );
        $stage = \metis_key_clean( (string) ( $payload['stage'] ?? '' ) );
        if ( $runUuid === '' || $stage === '' ) {
            throw new RuntimeException( 'Backup stage payload is required.' );
        }

        $result = \metis_backup_run_stage( $runUuid, $stage );
        if ( empty( $result['ok'] ) ) {
            throw new RuntimeException( (string) ( $result['error'] ?? 'Backup stage failed.' ) );
        }

        return [ 'operation' => $operation, 'run_uuid' => $runUuid, 'stage' => $stage, 'result' => $result ];
    }

    private function runBackupRestoreOperation( string $operation, array $payload ): array {
        if ( ! \function_exists( 'metis_backup_restore_run' ) ) {
            throw new RuntimeException( 'Backup restore service is not available.' );
        }

        $runUuid = trim( (string) ( $payload['run_uuid'] ?? '' ) );
        if ( $runUuid === '' ) {
            throw new RuntimeException( 'Backup run ID is required.' );
        }

        return [ 'operation' => $operation, 'run_uuid' => $runUuid, 'result' => \metis_backup_restore_run( $runUuid ) ];
    }

    private function runReleaseCheckOperation( string $operation ): array {
        if ( ! \function_exists( 'metis_release_check_for_updates' ) ) {
            return [
                'operation' => $operation,
                'result' => [
                    'status' => 'skipped',
                    'message' => 'Release manager is not available.',
                ],
            ];
        }

        return [ 'operation' => $operation, 'result' => \metis_release_check_for_updates( true, 'settings_operations' ) ];
    }

    private function runReleaseApplyOperation( string $operation, array $payload ): array {
        if ( ! \function_exists( 'metis_release_apply' ) ) {
            return [
                'operation' => $operation,
                'result' => [
                    'status' => 'skipped',
                    'message' => 'Release manager is not available.',
                ],
            ];
        }

        $tag = trim( (string) ( $payload['tag'] ?? '' ) );
        if ( $tag === '' ) {
            throw new RuntimeException( 'Release tag is required.' );
        }

        return [ 'operation' => $operation, 'tag' => $tag, 'result' => \metis_release_apply( $tag, 'settings_operations' ) ];
    }

    private function runReleaseRollbackOperation( string $operation ): array {
        if ( ! \function_exists( 'metis_release_rollback' ) ) {
            return [
                'operation' => $operation,
                'result' => [
                    'status' => 'skipped',
                    'message' => 'Release manager is not available.',
                ],
            ];
        }

        return [ 'operation' => $operation, 'result' => \metis_release_rollback( 'settings_operations' ) ];
    }

    private function runIntegrityBaselineOperation( string $operation ): array {
        if ( ! \class_exists( 'Metis_Integrity_Manager' ) ) {
            throw new RuntimeException( 'Integrity manager is not available.' );
        }

        return [ 'operation' => $operation, 'built' => \Metis_Integrity_Manager::initialize_baseline( 'settings_operations' ) ];
    }

    private function runModuleComplianceAuditOperation( string $operation ): array {
        if ( ! \function_exists( 'metis_module_compliance_report' ) ) {
            return [
                'operation' => $operation,
                'result' => [
                    'status' => 'skipped',
                    'message' => 'Module compliance report service is not available.',
                ],
            ];
        }

        $report = (array) \metis_module_compliance_report( true );
        $summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : [];
        $results = is_array( $report['results'] ?? null ) ? $report['results'] : [];
        $failures = array_values(
            array_filter(
                $results,
                static fn ( mixed $row ): bool => is_array( $row ) && (string) ( $row['status'] ?? '' ) === 'failed'
            )
        );
        $failed = (int) ( $summary['failed'] ?? count( $failures ) );

        return [
            'operation' => $operation,
            'result' => [
                'status' => $failed > 0 ? 'failed' : 'ok',
                'summary' => $summary,
                'failures' => $failures,
                'report' => $report,
            ],
        ];
    }

    private function runBoardWorkspacePrepareOperation( string $operation, array $payload ): array {
        $meeting = \metis_key_clean( (string) ( $payload['meeting'] ?? '' ) );
        if ( $meeting === '' ) {
            throw new RuntimeException( 'Meeting ID or code is required.' );
        }

        $meetingId = ctype_digit( $meeting ) ? (int) $meeting : 0;
        if ( $meetingId < 1 ) {
            $table = \Metis_Tables::get( 'board_meetings' );
            $row = $this->db->fetchOne(
                "SELECT id FROM {$table} WHERE LOWER(meeting_code) = %s LIMIT 1",
                [ strtolower( $meeting ) ]
            );
            $meetingId = (int) ( $row['id'] ?? 0 );
        }

        if ( $meetingId < 1 ) {
            throw new RuntimeException( 'Board meeting was not found.' );
        }

        if ( ! \function_exists( 'metis_board_prepare_workspace_folders' ) ) {
            $boardAjax = \METIS_MODULES_PATH . 'board/assets/board.ajax.php';
            if ( is_file( $boardAjax ) ) {
                require_once $boardAjax;
            }
        }

        if ( ! \function_exists( 'metis_board_prepare_workspace_folders' ) ) {
            throw new RuntimeException( 'Board workspace preparation service is not available.' );
        }

        return [
            'operation' => $operation,
            'meeting_id' => $meetingId,
            'result' => \metis_board_prepare_workspace_folders( $meetingId ),
        ];
    }
}
