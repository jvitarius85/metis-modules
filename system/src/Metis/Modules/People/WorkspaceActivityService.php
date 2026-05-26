<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class WorkspaceActivityService {
    public static function payload( int $sync_page = 1, int $security_page = 1, int $sync_page_size = 12, int $security_page_size = 12 ): array {
        $db = \metis_db();
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );
        $workspace_groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $workspace_security_actions_table = \Metis_Tables::get( 'people_workspace_security_actions' );
        $workspace_sync_jobs_table = \Metis_Tables::get( 'people_workspace_sync_jobs' );
        $people_table = \Metis_Tables::get( 'people' );
        $labels = self::labelMap();
        $job_type_labels = (array) ( $labels['job'] ?? [] );
        $security_action_labels = (array) ( $labels['security'] ?? [] );
        $status_labels = (array) ( $labels['status'] ?? [] );

        if ( $sync_page < 1 ) {
            $sync_page = 1;
        }
        if ( $security_page < 1 ) {
            $security_page = 1;
        }
        if ( $sync_page_size < 1 ) {
            $sync_page_size = 12;
        }
        if ( $security_page_size < 1 ) {
            $security_page_size = 12;
        }

        $sync_total = (int) $db->scalar( "SELECT COUNT(*) FROM {$workspace_sync_jobs_table}" );
        $security_total = (int) $db->scalar( "SELECT COUNT(*) FROM {$workspace_security_actions_table}" );
        $sync_total_pages = max( 1, (int) ceil( $sync_total / $sync_page_size ) );
        $security_total_pages = max( 1, (int) ceil( $security_total / $security_page_size ) );
        if ( $sync_page > $sync_total_pages ) {
            $sync_page = $sync_total_pages;
        }
        if ( $security_page > $security_total_pages ) {
            $security_page = $security_total_pages;
        }
        $sync_offset = ( $sync_page - 1 ) * $sync_page_size;
        $security_offset = ( $security_page - 1 ) * $security_page_size;

        $sync_jobs = $db->fetchAll(
            "SELECT *
             FROM {$workspace_sync_jobs_table}
             ORDER BY created_at DESC
             LIMIT {$sync_page_size} OFFSET {$sync_offset}"
        ) ?: [];
        $security_actions = $db->fetchAll(
            "SELECT sa.*, wu.primary_email, wu.display_name, wu.person_id,
                    p.pid AS person_pid, p.display_name AS person_display_name
             FROM {$workspace_security_actions_table} sa
             INNER JOIN {$workspace_users_table} wu ON wu.id = sa.workspace_user_id
             LEFT JOIN {$people_table} p ON p.id = wu.person_id
             ORDER BY sa.created_at DESC
             LIMIT {$security_page_size} OFFSET {$security_offset}"
        ) ?: [];

        $normalize_person_name = static function ( string $name ): string {
            $trimmed = trim( $name );
            if ( $trimmed === '' ) {
                return '';
            }
            return trim( (string) preg_replace( '/\s*\([A-Z]{2,6}-\d+\)\s*$/', '', $trimmed ) );
        };

        $sync_workspace_user_names = [];
        $sync_workspace_group_names = [];
        $sync_person_names = [];
        $sync_workspace_user_urls = [];
        $sync_person_urls = [];
        $sync_workspace_user_ids = [];
        $sync_workspace_group_ids = [];
        $sync_person_ids = [];
        foreach ( $sync_jobs as $sync_job_row ) {
            $entity_type = strtolower( trim( (string) ( $sync_job_row['entity_type'] ?? '' ) ) );
            $entity_id = (int) ( $sync_job_row['entity_id'] ?? 0 );
            if ( $entity_id < 1 ) {
                continue;
            }
            if ( $entity_type === 'workspace_user' ) {
                $sync_workspace_user_ids[ $entity_id ] = true;
            }
            if ( $entity_type === 'workspace_group' ) {
                $sync_workspace_group_ids[ $entity_id ] = true;
            }
            if ( $entity_type === 'person' ) {
                $sync_person_ids[ $entity_id ] = true;
            }
        }
        if ( ! empty( $sync_workspace_user_ids ) ) {
            $ids = implode( ',', array_map( 'intval', array_keys( $sync_workspace_user_ids ) ) );
            $rows = $db->fetchAll(
                "SELECT wu.id, wu.primary_email, wu.display_name, wu.first_name, wu.last_name,
                        p.pid AS person_pid, p.display_name AS person_display_name,
                        p.first_name AS person_first_name, p.last_name AS person_last_name
                 FROM {$workspace_users_table} wu
                 LEFT JOIN {$people_table} p ON p.id = wu.person_id
                 WHERE wu.id IN ({$ids})"
            ) ?: [];
            foreach ( $rows as $row ) {
                $id = (int) ( $row['id'] ?? 0 );
                if ( $id < 1 ) {
                    continue;
                }
                $person_pid = trim( (string) ( $row['person_pid'] ?? '' ) );
                $person_display = $normalize_person_name( trim( (string) ( $row['person_display_name'] ?? '' ) ) );
                $person_name = trim( (string) ( $row['person_first_name'] ?? '' ) . ' ' . (string) ( $row['person_last_name'] ?? '' ) );
                if ( $person_name === '' ) {
                    $person_name = $person_display;
                }
                if ( $person_display !== '' ) {
                    $sync_workspace_user_names[ $id ] = $person_name;
                    if ( $person_pid !== '' ) {
                        $sync_workspace_user_urls[ $id ] = \metis_people_person_url( $person_pid );
                    }
                    continue;
                }
                $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                if ( $name === '' ) {
                    $name = trim( (string) ( $row['display_name'] ?? '' ) );
                }
                if ( $name === '' ) {
                    $name = 'Workspace user';
                }
                $sync_workspace_user_names[ $id ] = $name;
            }
        }
        if ( ! empty( $sync_workspace_group_ids ) ) {
            $ids = implode( ',', array_map( 'intval', array_keys( $sync_workspace_group_ids ) ) );
            $rows = $db->fetchAll(
                "SELECT id, group_name, group_email
                 FROM {$workspace_groups_table}
                 WHERE id IN ({$ids})"
            ) ?: [];
            foreach ( $rows as $row ) {
                $id = (int) ( $row['id'] ?? 0 );
                if ( $id < 1 ) {
                    continue;
                }
                $group_name = trim( (string) ( $row['group_name'] ?? '' ) );
                $group_email = trim( (string) ( $row['group_email'] ?? '' ) );
                if ( $group_name === '' ) {
                    $group_name = $group_email;
                }
                $sync_workspace_group_names[ $id ] = $group_name !== '' ? $group_name : 'Workspace group';
            }
        }
        if ( ! empty( $sync_person_ids ) ) {
            $ids = implode( ',', array_map( 'intval', array_keys( $sync_person_ids ) ) );
            $rows = $db->fetchAll(
                "SELECT id, pid, display_name, first_name, last_name
                 FROM {$people_table}
                 WHERE id IN ({$ids})"
            ) ?: [];
            foreach ( $rows as $row ) {
                $id = (int) ( $row['id'] ?? 0 );
                if ( $id < 1 ) {
                    continue;
                }
                $display_name = $normalize_person_name( trim( (string) ( $row['display_name'] ?? '' ) ) );
                $person_name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                if ( $person_name === '' ) {
                    $person_name = $display_name;
                }
                $pid = trim( (string) ( $row['pid'] ?? '' ) );
                if ( $person_name === '' ) {
                    $person_name = 'Person';
                }
                $sync_person_names[ $id ] = $person_name;
                if ( $pid !== '' ) {
                    $sync_person_urls[ $id ] = \metis_people_person_url( $pid );
                }
            }
        }

        $format_entity = static function ( ?string $entity_type, $entity_id ) use ( $sync_workspace_user_names, $sync_workspace_group_names, $sync_person_names ): string {
            $type = strtolower( trim( (string) $entity_type ) );
            $id = (int) $entity_id;
            if ( $type === 'workspace_user' ) {
                return $id > 0 ? ( $sync_workspace_user_names[ $id ] ?? 'Workspace user' ) : 'Workspace user';
            }
            if ( $type === 'workspace_group' ) {
                return $id > 0 ? ( $sync_workspace_group_names[ $id ] ?? 'Workspace group' ) : 'Workspace group';
            }
            if ( $type === 'person' ) {
                return $id > 0 ? ( $sync_person_names[ $id ] ?? 'Person' ) : 'Person';
            }
            return $type !== '' ? ucwords( str_replace( '_', ' ', $type ) ) : 'Entity';
        };
        $format_entity_url = static function ( ?string $entity_type, $entity_id ) use ( $sync_workspace_user_urls, $sync_person_urls ): string {
            $type = strtolower( trim( (string) $entity_type ) );
            $id = (int) $entity_id;
            if ( $id < 1 ) {
                return '';
            }
            if ( $type === 'person' ) {
                return (string) ( $sync_person_urls[ $id ] ?? '' );
            }
            if ( $type === 'workspace_user' ) {
                return (string) ( $sync_workspace_user_urls[ $id ] ?? '' );
            }
            return '';
        };

        $sync_rows_out = [];
        foreach ( $sync_jobs as $j ) {
            $job_type = strtolower( trim( (string) ( $j['job_type'] ?? '' ) ) );
            $job_status = strtolower( trim( (string) ( $j['status'] ?? 'queued' ) ) );
            $job_title = (string) ( $job_type_labels[ $job_type ] ?? ucwords( str_replace( '_', ' ', $job_type ) ) );
            $job_time = self::formatTime( (string) ( $j['created_at'] ?? '' ) );
            $job_error = trim( (string) ( $j['last_error'] ?? '' ) );
            $is_stripe_skip = $job_type === 'stripe_user_disable' && $job_status === 'failed' && stripos( $job_error, 'workspace email not set' ) !== false;
            $effective_job_status = $is_stripe_skip && $job_status === 'failed' ? 'completed' : $job_status;
            if ( $is_stripe_skip ) {
                $job_error = '';
            }
            $sync_rows_out[] = [
                'title' => $job_title,
                'error' => $job_error !== '' ? ( 'Failed: ' . substr( $job_error, 0, 160 ) ) : '',
                'entity_label' => $format_entity( (string) ( $j['entity_type'] ?? '' ), (int) ( $j['entity_id'] ?? 0 ) ),
                'entity_url' => $format_entity_url( (string) ( $j['entity_type'] ?? '' ), (int) ( $j['entity_id'] ?? 0 ) ),
                'status_label' => self::formatStatus( $effective_job_status, $status_labels ),
                'status_class' => self::statusChipClass( $effective_job_status ),
                'time' => $job_time,
            ];
        }

        $security_rows_out = [];
        foreach ( $security_actions as $s ) {
            $action_type = strtolower( trim( (string) ( $s['action_type'] ?? '' ) ) );
            $action_status = strtolower( trim( (string) ( $s['status'] ?? 'pending' ) ) );
            $action_title = (string) ( $security_action_labels[ $action_type ] ?? ucwords( str_replace( '_', ' ', $action_type ) ) );
            $security_name = trim( (string) ( $s['person_display_name'] ?? '' ) );
            if ( $security_name === '' ) {
                $security_name = trim( (string) ( $s['display_name'] ?? '' ) );
            }
            $security_name = $normalize_person_name( $security_name );
            if ( $security_name === '' ) {
                $security_name = 'Workspace user';
            }
            $security_pid = trim( (string) ( $s['person_pid'] ?? '' ) );
            $security_url = $security_pid !== '' ? \metis_people_person_url( $security_pid ) : '';
            $reason = trim( (string) ( $s['reason'] ?? '' ) );
            if ( $reason === 'bulk_workspace_action' ) {
                $reason = 'Bulk update';
            }
            if ( $reason === 'person_offboarded' ) {
                $reason = 'Offboarding';
            }
            if ( $reason === 'workspace_or_status_ineligible' ) {
                $reason = '';
            }
            $security_rows_out[] = [
                'title' => $action_title,
                'reason' => $reason,
                'user_name' => $security_name,
                'user_url' => $security_url,
                'status_label' => self::formatStatus( $action_status, $status_labels ),
                'status_class' => self::statusChipClass( $action_status ),
                'time' => self::formatTime( (string) ( $s['created_at'] ?? '' ) ),
            ];
        }

        return [
            'sync' => [
                'rows' => $sync_rows_out,
                'page' => $sync_page,
                'total_pages' => $sync_total_pages,
                'has_prev' => $sync_page > 1,
                'has_next' => $sync_page < $sync_total_pages,
                'prev_page' => $sync_page > 1 ? ( $sync_page - 1 ) : 1,
                'next_page' => $sync_page < $sync_total_pages ? ( $sync_page + 1 ) : $sync_total_pages,
            ],
            'security' => [
                'rows' => $security_rows_out,
                'page' => $security_page,
                'total_pages' => $security_total_pages,
                'has_prev' => $security_page > 1,
                'has_next' => $security_page < $security_total_pages,
                'prev_page' => $security_page > 1 ? ( $security_page - 1 ) : 1,
                'next_page' => $security_page < $security_total_pages ? ( $security_page + 1 ) : $security_total_pages,
            ],
        ];
    }

    public static function countQueuedJobs(): int {
        $jobs_table = \Metis_Tables::get( 'people_workspace_sync_jobs' );
        return (int) \metis_db()->scalar( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'queued'" );
    }

    private static function labelMap(): array {
        return [
            'job' => [
                'workspace_user_create' => 'Create Workspace user',
                'workspace_user_upsert' => 'Update Workspace user',
                'workspace_security_action' => 'Run security action',
                'workspace_group_create' => 'Create Workspace group',
                'workspace_group_upsert' => 'Update Workspace group',
                'workspace_group_member_upsert' => 'Update group member',
                'workspace_group_members_sync' => 'Sync group members',
                'workspace_group_members_bulk_sync' => 'Sync group members',
                'workspace_group_permissions_sync' => 'Sync group permissions',
                'workspace_directory_import' => 'Import directory users',
                'stripe_user_upsert' => 'Apply Stripe access',
                'stripe_user_disable' => 'Remove Stripe access',
            ],
            'security' => [
                'reset_password' => 'Force password reset',
                'revoke_sessions' => 'Revoke sessions',
                'force_2fa_reenroll' => 'Reset 2FA enrollment',
                'suspend_account' => 'Suspend account',
                'unsuspend_account' => 'Unsuspend account',
            ],
            'status' => [
                'queued' => 'Queued',
                'processing' => 'In Progress',
                'pending' => 'Pending',
                'completed' => 'Completed',
                'synced' => 'Completed',
                'failed' => 'Failed',
            ],
        ];
    }

    private static function statusChipClass( string $status ): string {
        $key = strtolower( trim( $status ) );
        if ( in_array( $key, [ 'completed', 'synced' ], true ) ) {
            return 'metis-chip-success';
        }
        if ( $key === 'failed' ) {
            return 'metis-chip-danger';
        }
        return '';
    }

    private static function formatStatus( string $status, array $status_labels ): string {
        $key = strtolower( trim( $status ) );
        if ( $key === '' ) {
            return 'Unknown';
        }
        return (string) ( $status_labels[ $key ] ?? ucwords( str_replace( [ '_', '-' ], ' ', $key ) ) );
    }

    private static function formatTime( string $value ): string {
        $raw = trim( $value );
        if ( $raw === '' ) {
            return 'Unknown time';
        }
        $ts = strtotime( $raw );
        if ( ! $ts ) {
            return $raw;
        }
        return date( 'M j, Y g:i a', $ts );
    }
}
