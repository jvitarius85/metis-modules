<?php
declare(strict_types=1);

namespace Metis\Core\Jobs;

use Metis\Core\Application;
use Metis\Services\DatabaseService;

class JobQueue {
    private const STATUS_QUEUED = 'queued';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';
    private const DEFAULT_QUEUE = 'default';
    private const DEFAULT_LEASE_TTL = 900;

    public function __construct(
        private readonly JobWorkerRegistry $workers,
        private readonly ?DatabaseService $db = null
    ) {}

    public function enqueue( string $job_type, array $payload = [], array $options = [] ): array {
        $this->ensureSchema();

        $job_type = $this->normalizeJobType( $job_type );
        if ( $job_type === '' ) {
            return [ 'ok' => false, 'message' => 'Job type is required.' ];
        }

        $table       = \Metis_Tables::get( 'job_queue' );
        $queue_name  = $this->normalizeQueueName( (string) ( $options['queue'] ?? self::DEFAULT_QUEUE ) );
        $dedupe_key  = trim( (string) ( $options['dedupe_key'] ?? '' ) );
        $available_at = $this->normalizeDatetime( $options['available_at'] ?? '' );
        $max_attempts = max( 1, (int) ( $options['max_attempts'] ?? 3 ) );
        $priority     = max( 0, min( 100, (int) ( $options['priority'] ?? 50 ) ) );
        $created_by   = (int) ( $options['created_by'] ?? 0 );
        $now_mysql    = \metis_current_time( 'mysql' );

        if ( $dedupe_key !== '' ) {
            $existing = $this->database()->fetchOne(
                "SELECT id, job_code, status
                 FROM {$table}
                 WHERE dedupe_key = %s
                   AND job_type = %s
                   AND (
                       status = %s
                       OR ( status = %s AND ( reserved_until IS NULL OR reserved_until = '' OR reserved_until >= %s ) )
                   )
                 ORDER BY id DESC
                 LIMIT 1",
                [ $dedupe_key, $job_type, self::STATUS_QUEUED, self::STATUS_PROCESSING, $now_mysql ]
            );

            if ( is_array( $existing ) ) {
                return [
                    'ok'        => true,
                    'queued'    => false,
                    'duplicate' => true,
                    'job_id'    => (int) ( $existing['id'] ?? 0 ),
                    'job_code'  => (string) ( $existing['job_code'] ?? '' ),
                    'status'    => (string) ( $existing['status'] ?? self::STATUS_QUEUED ),
                ];
            }
        }

        $job_code = \function_exists( 'metis_generate_code' )
            ? \metis_generate_code( 'JOB', $table, 'job_code' )
            : 'JOB' . strtoupper( bin2hex( random_bytes( 6 ) ) );

        $inserted = $this->database()->insert(
            $table,
            [
                'job_code'      => $job_code,
                'queue_name'    => $queue_name,
                'job_type'      => $job_type,
                'status'        => self::STATUS_QUEUED,
                'dedupe_key'    => $dedupe_key !== '' ? $dedupe_key : null,
                'priority'      => $priority,
                'attempts'      => 0,
                'max_attempts'  => $max_attempts,
                'available_at'  => $available_at,
                'payload_json'  => $this->encodeJson( $payload ),
                'created_by'    => $created_by > 0 ? $created_by : null,
                'reserved_at'   => null,
                'reserved_until'=> null,
                'started_at'    => null,
                'completed_at'  => null,
                'failed_at'     => null,
                'last_error'    => null,
                'result_json'   => null,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $inserted === false ) {
            return [ 'ok' => false, 'message' => 'Failed to queue job.' ];
        }

        return [
            'ok'       => true,
            'queued'   => true,
            'job_id'   => (int) $this->database()->lastInsertId(),
            'job_code' => $job_code,
            'status'   => self::STATUS_QUEUED,
        ];
    }

    public function process( int $limit = 25, string $worker_name = 'system_cron' ): array {
        $this->ensureSchema();
        $this->requeueExpiredProcessingJobs();

        $claimed   = $this->claimJobs( $limit );
        $processed = 0;
        $completed = 0;
        $failed    = 0;
        $results   = [];

        foreach ( $claimed as $job ) {
            $processed++;
            $job_id   = (int) ( $job['id'] ?? 0 );
            $job_type = (string) ( $job['job_type'] ?? '' );
            $payload  = $this->decodeJson( (string) ( $job['payload_json'] ?? '' ) );

            try {
                $result = $this->workers->run( $job_type, $payload, $job );
                $this->markCompleted( $job_id, is_array( $result ) ? $result : [ 'result' => $result ] );
                $completed++;
                $results[] = [
                    'job_id'   => $job_id,
                    'job_type' => $job_type,
                    'status'   => self::STATUS_COMPLETED,
                ];
            } catch ( \Throwable $e ) {
                if ( Application::has_service( 'error_logger' ) && Application::has_service( 'error_kernel' ) ) {
                    /** @var \Metis\Core\Error\ErrorKernel $kernel */
                    $kernel = Application::service( 'error_kernel' );
                    /** @var \Metis\Core\Error\ErrorLogger $errorLogger */
                    $errorLogger = Application::service( 'error_logger' );
                    $context = $kernel->contextForThrowable(
                        $e,
                        [
                            'boundary' => 'background_job',
                            'service' => 'queue',
                            'meta' => [
                                'job_id' => $job_id,
                                'job_type' => $job_type,
                                'worker' => $worker_name,
                            ],
                        ]
                    );
                    $context->set( 'degraded', true );
                    $errorLogger->log( $context );
                }

                $final = $this->markFailed( $job, $e );
                $failed++;
                $results[] = [
                    'job_id'   => $job_id,
                    'job_type' => $job_type,
                    'status'   => $final,
                    'message'  => 'Job execution failed. Review logs for details.',
                ];

                if ( \class_exists( 'Metis_Logger' ) ) {
                    \Metis_Logger::error( 'Background job failed', [
                        'job_id'   => $job_id,
                        'job_type' => $job_type,
                        'worker'   => $worker_name,
                        'error'    => $e->getMessage(),
                    ] );
                }
            }
        }

        return [
            'processed' => $processed,
            'completed' => $completed,
            'failed'    => $failed,
            'results'   => $results,
        ];
    }

    private function requeueExpiredProcessingJobs(): void {
        $table = \Metis_Tables::get( 'job_queue' );
        $now   = \metis_current_time( 'mysql' );

        $this->database()->query(
            "UPDATE {$table}
             SET status = %s,
                 reserved_at = NULL,
                 reserved_until = NULL
             WHERE status = %s
               AND reserved_until IS NOT NULL
               AND reserved_until <> ''
               AND reserved_until < %s",
            [ self::STATUS_QUEUED, self::STATUS_PROCESSING, $now ]
        );
    }

    public function registeredWorkers(): array {
        return $this->workers->all();
    }

    private function claimJobs( int $limit ): array {
        $limit = max( 1, min( 100, $limit ) );
        $table = \Metis_Tables::get( 'job_queue' );
        $now   = \metis_current_time( 'mysql' );

        $jobs = $this->database()->fetchAll(
            "SELECT *
             FROM {$table}
             WHERE status = %s
               AND available_at <= %s
               AND (reserved_until IS NULL OR reserved_until = '' OR reserved_until < %s)
             ORDER BY priority ASC, available_at ASC, id ASC
             LIMIT %d",
            [ self::STATUS_QUEUED, $now, $now, $limit ]
        );

        $claimed = [];
        $lease_until = $this->formatTimestamp( \metis_current_time( 'timestamp' ) + self::DEFAULT_LEASE_TTL );

        foreach ( $jobs as $job ) {
            $updated = $this->database()->update(
                $table,
                [
                    'status'         => self::STATUS_PROCESSING,
                    'attempts'       => (int) ( $job['attempts'] ?? 0 ) + 1,
                    'reserved_at'    => $now,
                    'reserved_until' => $lease_until,
                    'started_at'     => (string) ( $job['started_at'] ?? '' ) !== '' ? $job['started_at'] : $now,
                ],
                [
                    'id'            => (int) $job['id'],
                    'status'        => self::STATUS_QUEUED,
                ],
                [ '%s', '%d', '%s', '%s', '%s' ],
                [ '%d', '%s' ]
            );

            if ( $updated !== false && $updated > 0 ) {
                $job['status']         = self::STATUS_PROCESSING;
                $job['attempts']       = (int) ( $job['attempts'] ?? 0 ) + 1;
                $job['reserved_at']    = $now;
                $job['reserved_until'] = $lease_until;
                $job['started_at']     = (string) ( $job['started_at'] ?? '' ) !== '' ? (string) $job['started_at'] : $now;
                $claimed[]             = $job;
            }
        }

        return $claimed;
    }

    private function markCompleted( int $job_id, array $result ): void {
        $this->database()->update(
            \Metis_Tables::get( 'job_queue' ),
            [
                'status'         => self::STATUS_COMPLETED,
                'completed_at'   => \metis_current_time( 'mysql' ),
                'reserved_until' => null,
                'last_error'     => null,
                'result_json'    => $this->encodeJson( $result ),
            ],
            [ 'id' => $job_id ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    private function markFailed( array $job, \Throwable $e ): string {
        $table        = \Metis_Tables::get( 'job_queue' );
        $attempts     = (int) ( $job['attempts'] ?? 0 );
        $max_attempts = max( 1, (int) ( $job['max_attempts'] ?? 1 ) );
        $job_id       = (int) ( $job['id'] ?? 0 );
        $message      = 'Job execution failed. Review logs for details.';

        if ( $attempts < $max_attempts ) {
            $retry_at = $this->formatTimestamp( \metis_current_time( 'timestamp' ) + min( 3600, max( 60, $attempts * 60 ) ) );
            $this->database()->update(
                $table,
                [
                    'status'         => self::STATUS_QUEUED,
                    'available_at'   => $retry_at,
                    'reserved_until' => null,
                    'last_error'     => $message,
                ],
                [ 'id' => $job_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            return self::STATUS_QUEUED;
        }

        $this->database()->update(
            $table,
            [
                'status'         => self::STATUS_FAILED,
                'failed_at'      => \metis_current_time( 'mysql' ),
                'reserved_until' => null,
                'last_error'     => $message,
            ],
            [ 'id' => $job_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return self::STATUS_FAILED;
    }

    private function ensureSchema(): void {
        if ( \function_exists( 'metis_install_db' ) ) {
            \metis_install_db();
        }
    }

    private function normalizeJobType( string $job_type ): string {
        return trim( strtolower( $job_type ) );
    }

    private function normalizeQueueName( string $queue_name ): string {
        $queue_name = trim( strtolower( $queue_name ) );
        return $queue_name !== '' ? $queue_name : self::DEFAULT_QUEUE;
    }

    private function normalizeDatetime( mixed $value ): string {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( $value === '' ) {
            return \metis_current_time( 'mysql' );
        }

        $timestamp = strtotime( $value );
        return $timestamp === false ? \metis_current_time( 'mysql' ) : $this->formatTimestamp( $timestamp );
    }

    private function encodeJson( array $data ): string {
        if ( \function_exists( 'metis_json_encode' ) ) {
            return \metis_json_encode( $data );
        }

        return (string) json_encode( $data );
    }

    private function decodeJson( string $json ): array {
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function formatTimestamp( int $timestamp ): string {
        return \function_exists( 'metis_date' )
            ? \metis_runtime_date( 'Y-m-d H:i:s', $timestamp )
            : date( 'Y-m-d H:i:s', $timestamp );
    }

    private function database(): DatabaseService {
        if ( $this->db instanceof DatabaseService ) {
            return $this->db;
        }

        /** @var DatabaseService $db */
        $db = \metis_db();
        return $db;
    }
}
