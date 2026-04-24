<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

use Metis\Core\Cache\CacheService;

final class ModeSwitchService {
    public static function executeQueuedSwitch( array $payload ): array {
        SchemaManager::ensureSchema();

        $jobId = (int) ( $payload['mode_switch_job_id'] ?? 0 );
        if ( $jobId < 1 ) {
            return [ 'ok' => false, 'message' => 'Mode switch job id is missing.' ];
        }

        $db = \metis_db();
        $jobsTable = \Metis_Tables::get( 'finance_v2_mode_switch_jobs' );
        $orgModeTable = \Metis_Tables::get( 'finance_v2_org_mode' );

        $job = $db->fetchOne( "SELECT * FROM {$jobsTable} WHERE id = %d LIMIT 1", [ $jobId ] );
        if ( ! is_array( $job ) ) {
            return [ 'ok' => false, 'message' => 'Mode switch job not found.' ];
        }

        $status = strtolower( (string) ( $job['status'] ?? '' ) );
        if ( $status === 'completed' ) {
            return [ 'ok' => true, 'status' => 'completed', 'job_id' => $jobId ];
        }

        $orgId = (int) ( $job['org_id'] ?? ModeService::orgId() );
        $targetMode = ModeService::normalizeMode( (string) ( $job['target_mode'] ?? ModeService::MODE_FINANCE ) );

        $db->update(
            $jobsTable,
            [
                'status' => 'running',
                'started_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ 'id' => $jobId ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        $preflight = self::runPreflight( $orgId, $targetMode );
        if ( empty( $preflight['ok'] ) ) {
            $rollback = [
                'ok' => true,
                'message' => 'No mode mutation was applied; state unchanged.',
            ];

            $db->update(
                $jobsTable,
                [
                    'status' => 'failed_rolled_back',
                    'preflight_result_json' => Support::asJson( $preflight ),
                    'rollback_result_json' => Support::asJson( $rollback ),
                    'finished_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ 'id' => $jobId ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            $db->update(
                $orgModeTable,
                [
                    'switch_status' => 'failed_rolled_back',
                    'updated_at' => Support::now(),
                ],
                [ 'org_id' => $orgId ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            return [
                'ok' => false,
                'job_id' => $jobId,
                'status' => 'failed_rolled_back',
                'preflight' => $preflight,
            ];
        }

        $db->update(
            $orgModeTable,
            [
                'current_mode' => $targetMode,
                'switched_at' => Support::now(),
                'switch_status' => 'completed',
                'switch_job_id' => $jobId,
                'updated_at' => Support::now(),
            ],
            [ 'org_id' => $orgId ],
            [ '%s', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        $db->update(
            $jobsTable,
            [
                'status' => 'completed',
                'preflight_result_json' => Support::asJson( $preflight ),
                'finished_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ 'id' => $jobId ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        CacheService::forget( 'finance_v2.mode.org_' . $orgId );

        if ( function_exists( 'metis_audit_log_activity' ) ) {
            \metis_audit_log_activity(
                'finance_v2_mode_switch_completed',
                [
                    'module' => 'finance',
                    'severity' => 'info',
                    'outcome' => 'success',
                    'resource' => [
                        'type' => 'mode_switch',
                        'id' => (string) $jobId,
                        'label' => strtoupper( $targetMode ),
                    ],
                    'context' => [
                        'org_id' => $orgId,
                        'target_mode' => $targetMode,
                        'job_id' => $jobId,
                    ],
                ]
            );
        }

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => 'completed',
            'target_mode' => $targetMode,
            'preflight' => $preflight,
        ];
    }

    private static function runPreflight( int $orgId, string $targetMode ): array {
        $errors = [];

        if ( $orgId < 1 ) {
            $errors[] = 'Org id is invalid.';
        }

        if ( ! in_array( $targetMode, [ ModeService::MODE_FINANCE, ModeService::MODE_ACCOUNTING ], true ) ) {
            $errors[] = 'Target mode is invalid.';
        }

        $rolesTable = \Metis_Tables::get( 'people_roles' );
        $hasFinanceRole = (int) \metis_db()->scalar(
            "SELECT COUNT(*) FROM {$rolesTable} WHERE role_domain = %s AND role_key = %s",
            [ 'metis', 'finance' ]
        ) > 0;

        if ( ! $hasFinanceRole ) {
            $errors[] = 'Required metis role "finance" does not exist.';
        }

        return [
            'ok' => $errors === [],
            'checked_at' => Support::now(),
            'target_mode' => $targetMode,
            'errors' => $errors,
        ];
    }
}
