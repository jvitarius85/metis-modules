<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Services\DatabaseService;

final class DataRetentionService {
    private const DEFAULT_BATCH_LIMIT = 1000;

    public function __construct(
        private readonly DatabaseService $db
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function policies(): array {
        return [
            [
                'key' => 'job_queue_completed',
                'label' => 'Completed queue jobs',
                'table_key' => 'job_queue',
                'date_column' => 'completed_at',
                'status_column' => 'status',
                'status_values' => [ 'completed' ],
                'retention_days' => 14,
                'index_columns' => [ 'status', 'completed_at' ],
            ],
            [
                'key' => 'job_queue_failed',
                'label' => 'Failed queue jobs',
                'table_key' => 'job_queue',
                'date_column' => 'failed_at',
                'status_column' => 'status',
                'status_values' => [ 'failed' ],
                'retention_days' => 90,
                'index_columns' => [ 'status', 'failed_at' ],
            ],
            [
                'key' => 'webhook_events_processed',
                'label' => 'Processed webhook events',
                'table_key' => 'webhook_events',
                'date_column' => 'created_at',
                'status_column' => 'status',
                'status_values' => [ 'processed' ],
                'retention_days' => 30,
                'index_columns' => [ 'status', 'created_at' ],
            ],
            [
                'key' => 'webhook_events_failed',
                'label' => 'Failed webhook events',
                'table_key' => 'webhook_events',
                'date_column' => 'created_at',
                'status_column' => 'status',
                'status_values' => [ 'failed' ],
                'retention_days' => 90,
                'index_columns' => [ 'status', 'created_at' ],
            ],
            [
                'key' => 'email_send_events',
                'label' => 'Email send events',
                'table_key' => 'email_send_events',
                'date_column' => 'event_at',
                'retention_days' => 90,
                'index_columns' => [ 'event_at' ],
            ],
            [
                'key' => 'audit_activity',
                'label' => 'Activity audit events',
                'table_key' => 'audit_activity',
                'date_column' => 'occurred_at',
                'retention_days' => 180,
                'index_columns' => [ 'occurred_at' ],
            ],
            [
                'key' => 'audit_security',
                'label' => 'Security audit events',
                'table_key' => 'audit_security',
                'date_column' => 'occurred_at',
                'retention_days' => 365,
                'index_columns' => [ 'occurred_at' ],
            ],
            [
                'key' => 'people_activity',
                'label' => 'People activity events',
                'table_key' => 'people_activity',
                'date_column' => 'created_at',
                'retention_days' => 180,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'newsletter_events',
                'label' => 'Newsletter tracking events',
                'table_key' => 'newsletter_events',
                'date_column' => 'event_at',
                'retention_days' => 180,
                'index_columns' => [ 'event_at' ],
            ],
            [
                'key' => 'drive_audit',
                'label' => 'Drive audit events',
                'table_key' => 'drive_audit',
                'date_column' => 'created_at',
                'retention_days' => 180,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'communications_inbound_events',
                'label' => 'Inbound communications events',
                'table_key' => 'communications_inbound_events',
                'date_column' => 'created_at',
                'retention_days' => 90,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'hermes_command_logs',
                'label' => 'Hermes command logs',
                'table_key' => 'hermes_command_logs',
                'date_column' => 'created_at',
                'retention_days' => 30,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'hermes_help_issue_logs',
                'label' => 'Hermes help issue logs',
                'table_key' => 'hermes_help_issue_logs',
                'date_column' => 'created_at',
                'retention_days' => 90,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'hermes_reports',
                'label' => 'Hermes diagnostic reports',
                'table_key' => 'hermes_reports',
                'date_column' => 'created_at',
                'retention_days' => 180,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'hermes_memory_diagnostic_reports',
                'label' => 'Hermes diagnostic report memory',
                'table_key' => 'hermes_memory',
                'date_column' => 'created_at',
                'retention_days' => 30,
                'index_columns' => [ 'memory_type', 'scope_key', 'created_at' ],
                'filters' => [
                    'memory_type' => 'diagnostic_report',
                    'scope_key' => 'reports',
                ],
            ],
            [
                'key' => 'website_revisions',
                'label' => 'Website revisions',
                'table_key' => 'website_revisions',
                'date_column' => 'created_at',
                'retention_days' => 365,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'cms_revisions',
                'label' => 'Legacy CMS revisions',
                'table_key' => 'cms_revisions',
                'date_column' => 'created_at',
                'retention_days' => 365,
                'index_columns' => [ 'created_at' ],
            ],
            [
                'key' => 'backup_runs_failed',
                'label' => 'Failed backup run metadata',
                'table_key' => 'backup_runs',
                'date_column' => 'updated_at',
                'status_column' => 'status',
                'status_values' => [ 'failed' ],
                'retention_days' => 90,
                'index_columns' => [ 'status', 'updated_at' ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function run( array $options = [] ): array {
        if ( ! $this->isEnabled() ) {
            return [
                'status' => 'skipped',
                'message' => 'Data retention cleanup is disabled.',
                'deleted_rows' => 0,
                'policies' => [],
            ];
        }

        $batchLimit = max( 100, min( 10000, (int) ( $options['batch_limit'] ?? self::DEFAULT_BATCH_LIMIT ) ) );
        $totalDeleted = 0;
        $failedPolicies = 0;
        $policyResults = [];

        foreach ( $this->policies() as $policy ) {
            try {
                $result = $this->purgePolicy( $policy, $batchLimit );
            } catch ( \Throwable $exception ) {
                $failedPolicies++;
                $result = [
                    'status' => 'failed',
                    'reason' => 'exception',
                    'message' => $exception->getMessage(),
                    'deleted_rows' => 0,
                ];
            }
            $totalDeleted += (int) ( $result['deleted_rows'] ?? 0 );
            $policyResults[ (string) $policy['key'] ] = $result;
        }

        if ( \function_exists( 'metis_audit_compact' ) ) {
            try {
                $policyResults['audit_context_compaction'] = \metis_audit_compact( $batchLimit );
            } catch ( \Throwable $exception ) {
                $failedPolicies++;
                $policyResults['audit_context_compaction'] = [
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'updated_rows' => 0,
                ];
            }
        }

        if ( \class_exists( 'Metis_Logger' ) ) {
            \Metis_Logger::info( 'Data retention cleanup completed', [
                'deleted_rows' => $totalDeleted,
                'policies' => $policyResults,
            ] );
        }

        return [
            'status' => $failedPolicies > 0 ? 'failed' : 'ok',
            'message' => $failedPolicies > 0
                ? sprintf( 'Data retention cleanup deleted %d rows with %d failed policy checks.', $totalDeleted, $failedPolicies )
                : sprintf( 'Data retention cleanup deleted %d rows.', $totalDeleted ),
            'deleted_rows' => $totalDeleted,
            'failed_policies' => $failedPolicies,
            'policies' => $policyResults,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array {
        $rows = [];
        $totalRows = 0;
        $totalExpired = 0;
        $largest = [ 'key' => '', 'label' => '', 'rows' => 0 ];

        foreach ( $this->policies() as $policy ) {
            $key = (string) $policy['key'];
            try {
                $summary = $this->policySnapshot( $policy );
            } catch ( \Throwable $exception ) {
                $summary = [
                    'label' => (string) ( $policy['label'] ?? $key ),
                    'status' => 'failed',
                    'row_count' => 0,
                    'expired_count' => 0,
                    'retention_days' => $this->retentionDays( $policy ),
                    'message' => $exception->getMessage(),
                ];
            }
            $rows[ $key ] = $summary;
            $rowCount = (int) ( $summary['row_count'] ?? 0 );
            $expired = (int) ( $summary['expired_count'] ?? 0 );
            $totalRows += $rowCount;
            $totalExpired += $expired;
            if ( $rowCount > (int) $largest['rows'] ) {
                $largest = [
                    'key' => $key,
                    'label' => (string) ( $policy['label'] ?? $key ),
                    'rows' => $rowCount,
                ];
            }
        }

        return [
            'enabled' => $this->isEnabled(),
            'total_rows' => $totalRows,
            'total_expired_rows' => $totalExpired,
            'largest_policy' => $largest,
            'policies' => $rows,
        ];
    }

    private function isEnabled(): bool {
        if ( \class_exists( 'Core_Settings_Service' ) ) {
            return $this->boolValue( \Core_Settings_Service::get( 'data_retention_enabled', true ), true );
        }

        return true;
    }

    private function boolValue( mixed $value, bool $default = false ): bool {
        if ( \is_bool( $value ) ) {
            return $value;
        }

        if ( \is_int( $value ) || \is_float( $value ) ) {
            return (int) $value === 1;
        }

        if ( \is_string( $value ) ) {
            $normalized = \strtolower( \trim( $value ) );
            if ( \in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true ) ) {
                return true;
            }
            if ( \in_array( $normalized, [ '0', 'false', 'no', 'off', '' ], true ) ) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function purgePolicy( array $policy, int $batchLimit ): array {
        $table = $this->tableName( (string) ( $policy['table_key'] ?? '' ) );
        $dateColumn = (string) ( $policy['date_column'] ?? '' );

        if ( $table === '' || ! $this->validIdentifier( $dateColumn ) ) {
            return [ 'status' => 'skipped', 'reason' => 'invalid_policy', 'deleted_rows' => 0 ];
        }

        if ( ! $this->tableExists( $table ) || ! $this->columnExists( $table, $dateColumn ) ) {
            return [ 'status' => 'skipped', 'reason' => 'missing_table_or_column', 'deleted_rows' => 0 ];
        }

        $statusColumn = (string) ( $policy['status_column'] ?? '' );
        if ( $statusColumn !== '' && ( ! $this->validIdentifier( $statusColumn ) || ! $this->columnExists( $table, $statusColumn ) ) ) {
            return [ 'status' => 'skipped', 'reason' => 'missing_status_column', 'deleted_rows' => 0 ];
        }

        $filters = $this->validFilters( $table, (array) ( $policy['filters'] ?? [] ) );
        if ( ! empty( $policy['filters'] ) && $filters === [] ) {
            return [ 'status' => 'skipped', 'reason' => 'missing_filter_column', 'deleted_rows' => 0 ];
        }

        $this->ensureRetentionIndex( $table, (array) ( $policy['index_columns'] ?? [ $dateColumn ] ) );

        $days = $this->retentionDays( $policy );
        $cutoff = $this->cutoffDate( $days );
        [ $where, $args ] = $this->whereClause( $policy, $dateColumn, $cutoff, $filters );
        $args[] = $batchLimit;

        $deleted = $this->db->executePrepared(
            "DELETE FROM {$table}
             WHERE {$where}
             ORDER BY {$dateColumn} ASC, id ASC
             LIMIT %d",
            $args
        );

        return [
            'status' => 'ok',
            'deleted_rows' => max( 0, (int) $deleted ),
            'retention_days' => $days,
            'cutoff' => $cutoff,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function policySnapshot( array $policy ): array {
        $key = (string) ( $policy['key'] ?? '' );
        $label = (string) ( $policy['label'] ?? $key );
        $table = $this->tableName( (string) ( $policy['table_key'] ?? '' ) );
        $dateColumn = (string) ( $policy['date_column'] ?? '' );

        if ( $table === '' || ! $this->validIdentifier( $dateColumn ) || ! $this->tableExists( $table ) || ! $this->columnExists( $table, $dateColumn ) ) {
            return [
                'label' => $label,
                'status' => 'missing',
                'row_count' => 0,
                'expired_count' => 0,
                'retention_days' => $this->retentionDays( $policy ),
            ];
        }

        $statusColumn = (string) ( $policy['status_column'] ?? '' );
        if ( $statusColumn !== '' && ( ! $this->validIdentifier( $statusColumn ) || ! $this->columnExists( $table, $statusColumn ) ) ) {
            return [
                'label' => $label,
                'status' => 'missing_status_column',
                'row_count' => 0,
                'expired_count' => 0,
                'retention_days' => $this->retentionDays( $policy ),
            ];
        }

        $filters = $this->validFilters( $table, (array) ( $policy['filters'] ?? [] ) );
        if ( ! empty( $policy['filters'] ) && $filters === [] ) {
            return [
                'label' => $label,
                'status' => 'missing_filter_column',
                'row_count' => 0,
                'expired_count' => 0,
                'retention_days' => $this->retentionDays( $policy ),
            ];
        }

        $days = $this->retentionDays( $policy );
        $cutoff = $this->cutoffDate( $days );
        [ $where, $args ] = $this->whereClause( $policy, $dateColumn, $cutoff, $filters );

        $rowCount = $this->approximateRowCount( $table );
        $expired = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
            $args
        );

        return [
            'label' => $label,
            'status' => 'ok',
            'row_count' => $rowCount,
            'expired_count' => $expired,
            'retention_days' => $days,
            'cutoff' => $cutoff,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function retentionDays( array $policy ): int {
        $key = (string) ( $policy['key'] ?? '' );
        $days = (int) ( $policy['retention_days'] ?? 90 );

        if ( \class_exists( 'Core_Settings_Service' ) ) {
            $overrides = \Core_Settings_Service::get( 'data_retention_policy_days', [] );
            if ( \is_array( $overrides ) && isset( $overrides[ $key ] ) ) {
                $days = (int) $overrides[ $key ];
            }
        }

        return max( 1, min( 3650, $days ) );
    }

    private function cutoffDate( int $retentionDays ): string {
        $timestamp = (int) \metis_current_time( 'timestamp' ) - ( max( 1, $retentionDays ) * \DAY_IN_SECONDS );
        return \function_exists( 'metis_runtime_date' )
            ? \metis_runtime_date( 'Y-m-d H:i:s', $timestamp )
            : \date( 'Y-m-d H:i:s', $timestamp );
    }

    /**
     * @param array<string,mixed> $policy
     * @return array{0:string,1:array<int,mixed>}
     */
    private function whereClause( array $policy, string $dateColumn, string $cutoff, array $filters = [] ): array {
        $where = "{$dateColumn} IS NOT NULL AND {$dateColumn} <> '' AND {$dateColumn} < %s";
        $args = [ $cutoff ];

        foreach ( $filters as $column => $value ) {
            $where .= " AND {$column} = %s";
            $args[] = $value;
        }

        $statusColumn = (string) ( $policy['status_column'] ?? '' );
        $statusValues = array_values( array_filter( array_map( 'strval', (array) ( $policy['status_values'] ?? [] ) ) ) );
        if ( $statusColumn !== '' && $statusValues !== [] ) {
            $placeholders = implode( ', ', array_fill( 0, count( $statusValues ), '%s' ) );
            $where .= " AND {$statusColumn} IN ({$placeholders})";
            foreach ( $statusValues as $status ) {
                $args[] = $status;
            }
        }

        return [ $where, $args ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,string>
     */
    private function validFilters( string $table, array $filters ): array {
        $valid = [];
        foreach ( $filters as $column => $value ) {
            $column = (string) $column;
            if ( ! $this->validIdentifier( $column ) || ! $this->columnExists( $table, $column ) ) {
                return [];
            }
            $valid[ $column ] = \is_scalar( $value ) ? (string) $value : '';
        }

        return $valid;
    }

    private function tableName( string $tableKey ): string {
        $tableKey = \metis_key_clean( $tableKey );
        if ( $tableKey === '' || ! \class_exists( 'Metis_Tables' ) || ! \Metis_Tables::has( $tableKey ) ) {
            return '';
        }

        $table = \Metis_Tables::get( $tableKey );
        return $this->validIdentifier( $table ) ? $table : '';
    }

    private function tableExists( string $table ): bool {
        return (int) $this->db->scalar(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s',
            [ $table ]
        ) > 0;
    }

    private function columnExists( string $table, string $column ): bool {
        return $this->db->scalar( "SHOW COLUMNS FROM {$table} LIKE %s", [ $column ] ) !== null;
    }

    /**
     * @param array<int,string> $columns
     */
    private function ensureRetentionIndex( string $table, array $columns ): void {
        $columns = array_values( array_filter(
            array_map( 'strval', $columns ),
            fn ( string $column ): bool => $this->validIdentifier( $column ) && $this->columnExists( $table, $column )
        ) );
        if ( $columns === [] ) {
            return;
        }

        $indexName = 'retention_' . substr( sha1( $table . ':' . implode( ':', $columns ) ), 0, 12 );
        if ( $this->db->scalar( "SHOW INDEX FROM {$table} WHERE Key_name = %s", [ $indexName ] ) !== null ) {
            return;
        }

        $columnList = implode( ', ', $columns );
        $this->db->execute( "ALTER TABLE {$table} ADD KEY {$indexName} ({$columnList})" );
    }

    private function approximateRowCount( string $table ): int {
        try {
            $row = $this->db->fetchOne(
                'SELECT TABLE_ROWS AS rows
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                 LIMIT 1',
                [ $table ]
            );
            if ( is_array( $row ) && isset( $row['rows'] ) ) {
                return max( 0, (int) $row['rows'] );
            }
        } catch ( \Throwable ) {
        }

        return max( 0, (int) $this->db->scalar( "SELECT COUNT(*) FROM {$table}" ) );
    }

    private function validIdentifier( string $identifier ): bool {
        return $identifier !== '' && preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) === 1;
    }
}
