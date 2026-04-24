<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

use Metis\Modules\People\AccessManager;

final class ModeService {
    public const MODE_FINANCE = 'finance';
    public const MODE_ACCOUNTING = 'accounting';

    public static function orgId(): int {
        return 1;
    }

    public static function currentMode(): string {
        SchemaManager::ensureSchema();

        $table = \Metis_Tables::get( 'finance_v2_org_mode' );
        $mode = (string) \metis_db()->scalar(
            "SELECT current_mode FROM {$table} WHERE org_id = %d LIMIT 1",
            [ self::orgId() ]
        );

        return self::normalizeMode( $mode );
    }

    public static function normalizeMode( string $mode ): string {
        $mode = strtolower( trim( $mode ) );
        return in_array( $mode, [ self::MODE_FINANCE, self::MODE_ACCOUNTING ], true )
            ? $mode
            : self::MODE_FINANCE;
    }

    public static function currentUserHasFinanceRole(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            if ( \metis_people_can( 'finance', 'create' ) || \metis_people_can( 'finance', 'edit' ) || \metis_people_can( 'finance', 'delete' ) ) {
                return true;
            }
        }

        if ( ! function_exists( 'metis_people_get_current_person_id' ) ) {
            return false;
        }

        $personId = (int) \metis_people_get_current_person_id();
        if ( $personId < 1 ) {
            return false;
        }

        $matrix = AccessManager::permissionMatrixForPerson( $personId );
        $roles = array_map( 'strval', (array) ( $matrix['roles'] ?? [] ) );

        if ( in_array( 'finance', $roles, true ) ) {
            return true;
        }

        foreach ( $roles as $role ) {
            if ( $role === 'metis.finance' || $role === 'metis_finance' || $role === 'metis:finance' ) {
                return true;
            }
        }

        return false;
    }

    public static function switchStatus(): array {
        SchemaManager::ensureSchema();

        $orgModeTable = \Metis_Tables::get( 'finance_v2_org_mode' );
        $jobsTable = \Metis_Tables::get( 'finance_v2_mode_switch_jobs' );
        $orgId = self::orgId();

        $modeRow = \metis_db()->fetchOne(
            "SELECT current_mode, effective_at, switched_at, switch_status, switch_job_id
             FROM {$orgModeTable}
             WHERE org_id = %d
             LIMIT 1",
            [ $orgId ]
        ) ?: [];

        $pending = \metis_db()->fetchOne(
            "SELECT id, target_mode, effective_at, status, queue_job_id, queue_job_code, created_at
             FROM {$jobsTable}
             WHERE org_id = %d
               AND status IN ('queued', 'running')
             ORDER BY effective_at ASC, id ASC
             LIMIT 1",
            [ $orgId ]
        );

        return [
            'org_id' => $orgId,
            'current_mode' => self::normalizeMode( (string) ( $modeRow['current_mode'] ?? self::MODE_FINANCE ) ),
            'switch_status' => (string) ( $modeRow['switch_status'] ?? 'idle' ),
            'effective_at' => (string) ( $modeRow['effective_at'] ?? '' ),
            'switched_at' => (string) ( $modeRow['switched_at'] ?? '' ),
            'pending_switch' => is_array( $pending ) ? $pending : null,
        ];
    }

    public static function scheduleSwitch( string $targetMode, string $effectiveAt, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $targetMode = self::normalizeMode( $targetMode );
        $currentMode = self::currentMode();
        if ( $targetMode === $currentMode ) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Target mode already active.',
            ];
        }

        $effectiveAt = trim( $effectiveAt );
        $effectiveTimestamp = strtotime( $effectiveAt );
        if ( $effectiveTimestamp === false ) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Effective date/time is invalid.',
            ];
        }

        if ( $effectiveTimestamp <= time() ) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Effective date/time must be in the future.',
            ];
        }

        $effectiveMysql = gmdate( 'Y-m-d H:i:s', $effectiveTimestamp );
        $db = \metis_db();
        $orgId = self::orgId();

        $jobsTable = \Metis_Tables::get( 'finance_v2_mode_switch_jobs' );
        $orgModeTable = \Metis_Tables::get( 'finance_v2_org_mode' );

        $db->insert(
            $jobsTable,
            [
                'org_id' => $orgId,
                'target_mode' => $targetMode,
                'effective_at' => $effectiveMysql,
                'status' => 'queued',
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        $jobId = (int) $db->lastInsertId();
        if ( $jobId < 1 ) {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Failed to create mode switch job.',
            ];
        }

        $queued = \metis_job_queue()->enqueue(
            'finance_v2.mode_switch.execute',
            [
                'mode_switch_job_id' => $jobId,
                'org_id' => $orgId,
            ],
            [
                'queue' => 'finance_v2',
                'available_at' => $effectiveMysql,
                'dedupe_key' => 'finance_v2.mode_switch.org:' . $orgId,
                'max_attempts' => 1,
                'priority' => 5,
                'created_by' => $requestedBy,
            ]
        );

        if ( empty( $queued['ok'] ) ) {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Failed to queue mode switch execution.',
            ];
        }

        $queueJobId = (int) ( $queued['job_id'] ?? 0 );
        $queueJobCode = (string) ( $queued['job_code'] ?? '' );

        $db->update(
            $jobsTable,
            [
                'queue_job_id' => $queueJobId > 0 ? $queueJobId : null,
                'queue_job_code' => $queueJobCode !== '' ? $queueJobCode : null,
                'updated_at' => Support::now(),
            ],
            [ 'id' => $jobId ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        $db->update(
            $orgModeTable,
            [
                'effective_at' => $effectiveMysql,
                'switch_status' => 'scheduled',
                'switch_job_id' => $jobId,
                'updated_at' => Support::now(),
            ],
            [ 'org_id' => $orgId ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        return [
            'ok' => true,
            'mode_switch_job_id' => $jobId,
            'queue_job_id' => $queueJobId,
            'queue_job_code' => $queueJobCode,
            'target_mode' => $targetMode,
            'effective_at' => $effectiveMysql,
        ];
    }
}
