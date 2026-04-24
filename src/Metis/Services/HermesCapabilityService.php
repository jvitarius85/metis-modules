<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Application;
use Metis\Core\Jobs\JobQueue;

final class HermesCapabilityService {
    public function __construct(
        private readonly DatabaseService $db,
        private readonly HermesDirectoryService $directory,
        private readonly HermesUserAdminService $userAdmin,
        private readonly HermesSystemOperationsService $systemOps,
        private readonly ?JobQueue $jobs = null
    ) {}

    public function createUser( array $payload ): array {
        return $this->userAdmin->createUser( $payload );
    }

    public function updateUser( array $payload ): array {
        return $this->userAdmin->updateUser( $payload );
    }

    public function disableUser( array $payload ): array {
        return $this->userAdmin->disableUser( $payload );
    }

    public function enableUser( array $payload ): array {
        return $this->userAdmin->enableUser( $payload );
    }

    public function assignRole( array $payload ): array {
        $payload['mode'] = 'add';
        return $this->userAdmin->manageUserRoles( $payload );
    }

    public function removeRole( array $payload ): array {
        $payload['mode'] = 'remove';
        return $this->userAdmin->manageUserRoles( $payload );
    }

    public function listUsers( array $payload ): array {
        $peopleTable = \Metis_Tables::get( 'people' );
        $query = trim( strtolower( (string) ( $payload['query'] ?? '' ) ) );
        $args = [];
        $where = "WHERE status <> 'deleted'";

        if ( $query !== '' ) {
            $needle = '%' . $this->db->escapeLike( $query ) . '%';
            $where .= " AND (LOWER(COALESCE(display_name, '')) LIKE %s OR LOWER(COALESCE(email, '')) LIKE %s)";
            $args[] = $needle;
            $args[] = $needle;
        }

        $rows = $this->db->fetchAll(
            "SELECT id, pid, display_name, email, status, lifecycle_status, workspace_email
             FROM {$peopleTable}
             {$where}
             ORDER BY updated_at DESC, id DESC
             LIMIT 100",
            $args
        );

        return [
            'status' => 'success',
            'users' => $rows,
            'count' => count( $rows ),
            'message' => sprintf( 'Found %d users.', count( $rows ) ),
        ];
    }

    public function getUser( array $payload ): array {
        return $this->directory->lookupProfile( [
            'subject' => (string) ( $payload['subject'] ?? $payload['email'] ?? $payload['query'] ?? '' ),
            'entity_hint' => 'person',
        ] );
    }

    public function clearCache( array $payload ): array {
        return $this->systemOps->clearCache( $payload );
    }

    public function rebuildIndexes( array $payload ): array {
        return $this->queueGenericJob( 'hermes.index.rebuild', $payload, 'Rebuild indexes queued.' );
    }

    public function reloadConfig( array $payload ): array {
        return [
            'status' => 'success',
            'result' => $this->systemOps->clearCache( $payload ),
            'message' => 'Configuration reload requested through cache invalidation.',
        ];
    }

    public function getSystemStatus( array $payload ): array {
        $ops = Application::has_service( 'operations' ) ? Application::service( 'operations' )->queueSummary() : [];
        return [
            'status' => 'success',
            'system' => [
                'php_version' => PHP_VERSION,
                'hermes_loaded' => true,
                'queue_summary' => $ops,
            ],
            'message' => 'System status loaded.',
        ];
    }

    public function runFullDiagnostics( array $payload ): array {
        return $this->queueGenericJob( 'hermes.diagnostics.full', $payload, 'Full diagnostics queued.' );
    }

    public function scanIntegrity( array $payload ): array {
        return $this->queueGenericJob( 'hermes.integrity.scan', $payload, 'Integrity scan queued.' );
    }

    public function checkDb( array $payload ): array {
        $row = $this->db->fetchOne( 'SELECT 1 AS ok' );
        return [
            'status' => $row !== null ? 'success' : 'error',
            'database' => [ 'connected' => $row !== null ],
            'message' => $row !== null ? 'Database connectivity looks healthy.' : 'Database connectivity check failed.',
        ];
    }

    public function checkWorkers( array $payload ): array {
        $workers = function_exists( 'metis_job_queue' ) ? \metis_job_queue()->registeredWorkers() : [];
        $jobs = Application::has_service( 'operations' ) ? Application::service( 'operations' )->recentJobs( 20 ) : [];

        return [
            'status' => 'success',
            'workers' => $workers,
            'jobs' => $jobs,
            'message' => 'Worker status loaded.',
        ];
    }

    public function recoverModule( array $payload ): array {
        return $this->queueGenericJob( 'hermes.module.recover', $payload, 'Module recovery queued.' );
    }

    public function restoreFile( array $payload ): array {
        return $this->queueGenericJob( 'hermes.file.restore', $payload, 'File restore queued.' );
    }

    public function rollbackModule( array $payload ): array {
        return $this->queueGenericJob( 'hermes.module.rollback', $payload, 'Module rollback queued.' );
    }

    public function enableModule( array $payload ): array {
        return $this->queueGenericJob( 'hermes.module.enable', $payload, 'Enable module queued.' );
    }

    public function disableModule( array $payload ): array {
        return $this->queueGenericJob( 'hermes.module.disable', $payload, 'Disable module queued.' );
    }

    public function installModule( array $payload ): array {
        return $this->queueGenericJob( 'hermes.module.install', $payload, 'Install module queued.' );
    }

    public function updateModule( array $payload ): array {
        return $this->queueGenericJob( 'hermes.module.update', $payload, 'Update module queued.' );
    }

    public function exportData( array $payload ): array {
        return $this->queueGenericJob( 'hermes.data.export', $payload, 'Data export queued.' );
    }

    public function importData( array $payload ): array {
        return $this->queueGenericJob( 'hermes.data.import', $payload, 'Data import queued.' );
    }

    public function deduplicate( array $payload ): array {
        return $this->queueGenericJob( 'hermes.data.deduplicate', $payload, 'Deduplication queued.' );
    }

    public function createJob( array $payload ): array {
        return $this->queueGenericJob(
            trim( (string) ( $payload['job_type'] ?? 'hermes.manual.job' ) ),
            $payload,
            'Job queued.'
        );
    }

    public function cancelJob( array $payload ): array {
        $jobCode = trim( (string) ( $payload['subject'] ?? $payload['job_code'] ?? '' ) );
        if ( $jobCode === '' ) {
            return $this->error( 'INVALID_INPUT', 'A job code is required.' );
        }

        $updated = $this->db->update(
            \Metis_Tables::get( 'job_queue' ),
            [ 'status' => 'failed', 'last_error' => 'Canceled by Hermes operator.' ],
            [ 'job_code' => $jobCode ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        return [
            'status' => $updated ? 'success' : 'error',
            'job_code' => $jobCode,
            'message' => $updated ? 'Job canceled.' : 'Job was not updated.',
        ];
    }

    public function retryJob( array $payload ): array {
        $jobCode = trim( (string) ( $payload['subject'] ?? $payload['job_code'] ?? '' ) );
        if ( $jobCode === '' ) {
            return $this->error( 'INVALID_INPUT', 'A job code is required.' );
        }

        $updated = $this->db->update(
            \Metis_Tables::get( 'job_queue' ),
            [
                'status' => 'queued',
                'available_at' => \metis_current_time( 'mysql' ),
                'failed_at' => null,
                'last_error' => null,
            ],
            [ 'job_code' => $jobCode ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%s' ]
        );

        return [
            'status' => $updated ? 'success' : 'error',
            'job_code' => $jobCode,
            'message' => $updated ? 'Job re-queued.' : 'Job was not updated.',
        ];
    }

    public function listJobs( array $payload ): array {
        $jobs = Application::has_service( 'operations' ) ? Application::service( 'operations' )->recentJobs( 50 ) : [];
        return [
            'status' => 'success',
            'jobs' => $jobs,
            'count' => count( $jobs ),
            'message' => sprintf( 'Found %d jobs.', count( $jobs ) ),
        ];
    }

    public function auditPermissions( array $payload ): array {
        if ( ! Application::has_service( 'security_diagnostics' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Security diagnostics service is unavailable.' );
        }

        return Application::service( 'security_diagnostics' )->diagnosePermissions( [
            'query' => (string) ( $payload['query'] ?? $payload['subject'] ?? 'audit permissions' ),
        ] );
    }

    public function verifyIntegrity( array $payload ): array {
        return $this->queueGenericJob( 'hermes.integrity.verify', $payload, 'Integrity verification queued.' );
    }

    public function rotateKeys( array $payload ): array {
        return $this->queueGenericJob( 'hermes.security.rotate_keys', $payload, 'Key rotation queued.' );
    }

    public function validateRoutes( array $payload ): array {
        $router = function_exists( 'metis_http_router' ) ? \metis_http_router() : null;
        return [
            'status' => is_object( $router ) ? 'success' : 'error',
            'message' => is_object( $router ) ? 'Routes are loaded.' : 'Router runtime is unavailable.',
        ];
    }

    public function verifyNonce( array $payload ): array {
        $token = (string) ( $payload['nonce'] ?? '' );
        $action = (string) ( $payload['nonce_action'] ?? 'metis_hermes_execute_action' );
        if ( $token === '' || ! function_exists( 'metis_runtime_verify_nonce' ) ) {
            return $this->error( 'INVALID_INPUT', 'Nonce and runtime verifier are required.' );
        }

        return [
            'status' => \metis_runtime_verify_nonce( $token, $action ) ? 'success' : 'error',
            'message' => \metis_runtime_verify_nonce( $token, $action ) ? 'Nonce verified.' : 'Nonce verification failed.',
        ];
    }

    public function runEnclaveTest( array $payload ): array {
        if ( ! function_exists( 'metis_security_enclave' ) ) {
            return $this->error( 'ENCLAVE_UNAVAILABLE', 'Secure Enclave is unavailable.' );
        }

        return [
            'status' => 'success',
            'message' => 'Secure Enclave runtime is available.',
        ];
    }

    private function queueGenericJob( string $jobType, array $payload, string $message ): array {
        $jobType = trim( strtolower( preg_replace( '/[^a-z0-9._-]+/', '.', $jobType ) ?? '' ) );
        if ( $jobType === '' ) {
            return $this->error( 'INVALID_INPUT', 'A valid job type is required.' );
        }

        $jobQueue = $this->jobs ?? ( function_exists( 'metis_job_queue' ) ? \metis_job_queue() : null );
        if ( ! $jobQueue instanceof JobQueue ) {
            return $this->error( 'EXECUTION_FAILED', 'Job queue is unavailable.' );
        }

        $queued = $jobQueue->enqueue(
            $jobType,
            $payload,
            [
                'queue' => 'hermes',
                'priority' => 20,
                'max_attempts' => 2,
                'created_by' => function_exists( 'metis_current_user_id' ) ? \metis_current_user_id() : 0,
            ]
        );

        if ( empty( $queued['ok'] ) ) {
            return $this->error( 'EXECUTION_FAILED', (string) ( $queued['message'] ?? 'Job queue request failed.' ) );
        }

        return [
            'status' => 'queued',
            'job_id' => (int) ( $queued['job_id'] ?? 0 ),
            'job_code' => (string) ( $queued['job_code'] ?? '' ),
            'message' => $message,
        ];
    }

    private function error( string $code, string $message ): array {
        return [
            'status' => 'error',
            'error_code' => $code,
            'message' => $message,
        ];
    }
}
