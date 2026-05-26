<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class WorkspaceSyncJobService {
    public static function queueJob( string $job_type, string $entity_type, ?int $entity_id, ?int $requested_by_person_id, array $payload = [] ): int {
        $table = \Metis_Tables::get( 'people_workspace_sync_jobs' );
        $job_type = \metis_key_clean( $job_type );
        $entity_type = \metis_key_clean( $entity_type );
        $entity_id = ( $entity_id && $entity_id > 0 ) ? (int) $entity_id : null;
        $requested_by_person_id = ( $requested_by_person_id && $requested_by_person_id > 0 ) ? (int) $requested_by_person_id : null;

        if ( $entity_id !== null && in_array( $job_type, [ 'stripe_user_upsert', 'stripe_user_disable' ], true ) ) {
            $existing = \metis_db()->fetchOne(
                "SELECT id, status
                 FROM {$table}
                 WHERE entity_type = %s
                   AND entity_id = %d
                   AND job_type IN ('stripe_user_upsert', 'stripe_user_disable')
                   AND status IN ('queued', 'processing')
                 ORDER BY id DESC
                 LIMIT 1",
                [ $entity_type, $entity_id ]
            );
            $payload_json = ! empty( $payload ) ? \metis_json_encode( $payload ) : null;
            if ( $existing && (int) ( $existing['id'] ?? 0 ) > 0 ) {
                $existing_id = (int) $existing['id'];
                if ( (string) ( $existing['status'] ?? '' ) === 'queued' ) {
                    $update_payload = [
                        'job_type' => $job_type,
                        'payload_json' => $payload_json,
                        'last_error' => null,
                        'status' => 'queued',
                    ];
                    $update_format = [ '%s', '%s', '%s', '%s' ];
                    if ( $requested_by_person_id !== null ) {
                        $update_payload['requested_by_person_id'] = $requested_by_person_id;
                        $update_format[] = '%d';
                    }
                    \metis_db()->update(
                        $table,
                        $update_payload,
                        [ 'id' => $existing_id ],
                        $update_format,
                        [ '%d' ]
                    );
                }
                return $existing_id;
            }
        }

        $ok = \metis_db()->insert( $table, [
            'job_type' => $job_type,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'requested_by_person_id' => $requested_by_person_id,
            'payload_json' => ! empty( $payload ) ? \metis_json_encode( $payload ) : null,
            'status' => 'queued',
        ], [ '%s', '%s', '%d', '%d', '%s', '%s' ] );

        if ( ! $ok ) {
            return 0;
        }

        return (int) \metis_db()->lastInsertId();
    }

    public static function processJobs( array $cfg, int $limit = 10, bool $dry_run = false, int $specific_job_id = 0 ): array {
        $jobs_table = \Metis_Tables::get( 'people_workspace_sync_jobs' );
        $rows = $specific_job_id > 0
            ? \metis_db()->fetchAll(
                "SELECT * FROM {$jobs_table} WHERE id = %d AND status IN ('queued','failed') LIMIT 1",
                [ $specific_job_id ]
            )
            : \metis_db()->fetchAll(
                "SELECT * FROM {$jobs_table}
                 WHERE status = 'queued'
                 ORDER BY created_at ASC, id ASC
                 LIMIT %d",
                [ max( 1, min( 100, $limit ) ) ]
            );

        $processed = 0;
        $completed = 0;
        $failed = 0;
        $messages = [];

        foreach ( $rows as $job ) {
            $job_id = (int) ( $job['id'] ?? 0 );
            if ( $job_id < 1 || ! self::claimJob( $jobs_table, $job_id ) ) {
                continue;
            }

            $processed++;
            $result = self::executeJob( $job, $cfg, $dry_run );
            if ( ! empty( $result['ok'] ) ) {
                $completed++;
                self::markJobCompleted( $jobs_table, $job_id );
                if ( ! empty( $result['message'] ) ) {
                    $messages[] = (string) $result['message'];
                }
                continue;
            }

            $failed++;
            $error = isset( $result['error'] ) && is_scalar( $result['error'] ) && trim( (string) $result['error'] ) !== ''
                ? (string) $result['error']
                : 'Workspace sync job failed.';
            self::markJobFailed( $jobs_table, $job_id, $error );
            $messages[] = $error;
        }

        return [
            'processed' => $processed,
            'completed' => $completed,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    public static function executeJob( array $job, array $cfg, bool $dry_run = false ): array {
        $job_type = (string) ( $job['job_type'] ?? '' );
        $entity_id = (int) ( $job['entity_id'] ?? 0 );
        $payload = json_decode( (string) ( $job['payload_json'] ?? '' ), true );
        if ( ! is_array( $payload ) ) {
            $payload = [];
        }

        if ( in_array( $job_type, [ 'stripe_user_upsert', 'stripe_user_disable' ], true ) ) {
            return self::executeStripeRoleJob( $job_type, $entity_id, $payload, $cfg, $dry_run );
        }
        if ( in_array( $job_type, [ 'workspace_user_create', 'workspace_user_upsert' ], true ) ) {
            return self::executeWorkspaceUserUpsertJob( $entity_id, $cfg, $payload, $dry_run );
        }
        if ( $job_type === 'workspace_group_upsert' ) {
            return self::executeWorkspaceGroupUpsertJob( $entity_id, $cfg, $dry_run );
        }
        if ( in_array( $job_type, [ 'workspace_group_member_upsert', 'workspace_group_members_bulk_sync' ], true ) ) {
            return self::executeWorkspaceGroupMemberJob( $job_type, $entity_id, $cfg, $payload, $dry_run );
        }
        if ( $job_type === 'workspace_security_action' ) {
            return self::executeWorkspaceSecurityActionJob( $entity_id, $cfg, $payload, $dry_run );
        }

        return [ 'ok' => false, 'error' => 'Unsupported job type: ' . $job_type ];
    }

    private static function executeStripeRoleJob( string $job_type, int $entity_id, array $payload, array $cfg, bool $dry_run ): array {
        $workspace_email = strtolower( trim( (string) ( $payload['workspace_email'] ?? '' ) ) );
        $stripe_role = \metis_key_clean( (string) ( $payload['stripe_role'] ?? '' ) );
        $stripe_access_group_email = strtolower( trim( (string) ( $cfg['stripe_access_group_email'] ?? '' ) ) );

        if ( $entity_id > 0 ) {
            $linked_workspace_email = self::workspacePrimaryEmailForPerson( $entity_id );
            if ( \metis_email_is_valid( $linked_workspace_email ) ) {
                $workspace_email = $linked_workspace_email;
            } elseif ( ! \metis_email_is_valid( $workspace_email ) ) {
                $workspace_email = self::personWorkspaceEmail( $entity_id );
            }
        }

        if ( ! \metis_email_is_valid( $workspace_email ) ) {
            return [ 'ok' => true ];
        }
        if ( $dry_run ) {
            return [ 'ok' => true, 'message' => 'Dry run: would sync Stripe SSO role for ' . $workspace_email ];
        }
        return self::finishStripeRoleJob( $job_type, $workspace_email, $stripe_role, $stripe_access_group_email, $cfg );
    }

    private static function finishStripeRoleJob( string $job_type, string $workspace_email, string $stripe_role, string $stripe_access_group_email, array $cfg ): array {
        if ( $job_type === 'stripe_user_disable' ) {
            $result = \metis_people_workspace_apply_stripe_sso_role( $workspace_email, null, $cfg );
            if ( empty( $result['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Failed to disable Stripe access in Workspace.' ];
            }
            if ( \metis_email_is_valid( $stripe_access_group_email ) ) {
                $membership = \metis_people_workspace_set_group_membership( $stripe_access_group_email, $workspace_email, false, $cfg );
                if ( empty( $membership['ok'] ) ) {
                    return [ 'ok' => false, 'error' => 'Failed to remove Stripe access group membership.' ];
                }
            }
            return [ 'ok' => true, 'message' => 'Disabled Stripe access via Workspace (role cleared' . ( \metis_email_is_valid( $stripe_access_group_email ) ? ', group removed' : '' ) . ') for ' . $workspace_email ];
        }

        if ( $stripe_role === '' ) {
            return [ 'ok' => true ];
        }

        $result = \metis_people_workspace_apply_stripe_sso_role( $workspace_email, $stripe_role, $cfg );
        if ( empty( $result['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Failed to apply Stripe role in Workspace.' ];
        }
        if ( \metis_email_is_valid( $stripe_access_group_email ) ) {
            $membership = \metis_people_workspace_set_group_membership( $stripe_access_group_email, $workspace_email, true, $cfg );
            if ( empty( $membership['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Failed to add Stripe access group membership.' ];
            }
        }

        return [ 'ok' => true, 'message' => 'Enabled Stripe access via Workspace (role set' . ( \metis_email_is_valid( $stripe_access_group_email ) ? ', group added' : '' ) . ') for ' . $workspace_email ];
    }

    private static function executeWorkspaceUserUpsertJob( int $entity_id, array $cfg, array $payload, bool $dry_run ): array {
        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $row = \metis_db()->fetchOne( "SELECT * FROM {$users_table} WHERE id = %d LIMIT 1", [ $entity_id ] );
        if ( ! $row ) {
            return [ 'ok' => false, 'error' => 'Workspace user row not found.' ];
        }

        $primary_email = strtolower( trim( (string) ( $row['primary_email'] ?? '' ) ) );
        $previous_primary_email = strtolower( trim( (string) ( $payload['previous_primary_email'] ?? '' ) ) );
        $add_alias_email = strtolower( trim( (string) ( $payload['add_alias_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $primary_email ) ) {
            return [ 'ok' => false, 'error' => 'Workspace user email is invalid.' ];
        }

        $user_body = [
            'primaryEmail' => $primary_email,
            'name' => [
                'givenName' => (string) ( $row['first_name'] ?? '' ),
                'familyName' => (string) ( $row['last_name'] ?? '' ),
            ],
            'recoveryEmail' => (string) ( $row['recovery_email'] ?? '' ),
            'orgUnitPath' => (string) ( $row['org_unit_path'] ?? '/' ),
            'suspended' => ! empty( $row['is_suspended'] ),
        ];
        if ( $dry_run ) {
            return [ 'ok' => true, 'message' => 'Dry run: would upsert Workspace user ' . $primary_email ];
        }

        $lookup_email = \metis_email_is_valid( $previous_primary_email ) ? $previous_primary_email : $primary_email;
        $existing = \metis_people_workspace_google_request( 'GET', 'users/' . rawurlencode( $lookup_email ), null, $cfg );
        if ( empty( $existing['ok'] ) && $lookup_email !== $primary_email ) {
            $existing = \metis_people_workspace_google_request( 'GET', 'users/' . rawurlencode( $primary_email ), null, $cfg );
        }
        if ( ! empty( $existing['ok'] ) ) {
            $user_key = $lookup_email;
            if ( empty( $existing['body']['primaryEmail'] ) || strtolower( (string) ( $existing['body']['primaryEmail'] ?? '' ) ) === $primary_email ) {
                $user_key = $primary_email;
            }
            $resp = \metis_people_workspace_google_request( 'PUT', 'users/' . rawurlencode( $user_key ), $user_body, $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Failed to update workspace user.' ];
            }
            $google_id = (string) ( ( $resp['body']['id'] ?? '' ) ?: ( $existing['body']['id'] ?? '' ) );
            \metis_db()->update( $users_table, [ 'workspace_user_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced' ], [ 'id' => $entity_id ], [ '%s', '%s' ], [ '%d' ] );
            if ( \metis_email_is_valid( $add_alias_email ) && $add_alias_email !== $primary_email ) {
                $alias_resp = \metis_people_workspace_google_request(
                    'POST',
                    'users/' . rawurlencode( $primary_email ) . '/aliases',
                    [ 'alias' => $add_alias_email ],
                    $cfg
                );
                $alias_status = (int) ( $alias_resp['status'] ?? 0 );
                if ( empty( $alias_resp['ok'] ) && ! in_array( $alias_status, [ 409, 412 ], true ) ) {
                    return [ 'ok' => false, 'error' => 'Updated user but failed to add old email alias.' ];
                }
            }
            return [ 'ok' => true, 'message' => 'Updated Workspace user ' . $primary_email ];
        }

        $temporary_password = \metis_people_workspace_random_password( 20 );
        $user_body['password'] = $temporary_password;
        $user_body['changePasswordAtNextLogin'] = true;
        $create = \metis_people_workspace_google_request( 'POST', 'users', $user_body, $cfg );
        if ( empty( $create['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Failed to create workspace user.' ];
        }

        $google_id = (string) ( $create['body']['id'] ?? '' );
        \metis_db()->update( $users_table, [ 'workspace_user_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced' ], [ 'id' => $entity_id ], [ '%s', '%s' ], [ '%d' ] );
        return [
            'ok' => true,
            'message' => 'Created Workspace user ' . $primary_email,
            'temporary_password' => $temporary_password,
        ];
    }

    private static function executeWorkspaceGroupUpsertJob( int $entity_id, array $cfg, bool $dry_run ): array {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $row = \metis_db()->fetchOne( "SELECT * FROM {$groups_table} WHERE id = %d LIMIT 1", [ $entity_id ] );
        if ( ! $row ) {
            return [ 'ok' => false, 'error' => 'Workspace group row not found.' ];
        }

        $group_email = strtolower( trim( (string) ( $row['group_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $group_email ) ) {
            return [ 'ok' => false, 'error' => 'Workspace group email is invalid.' ];
        }
        $group_body = [
            'email' => $group_email,
            'name' => (string) ( $row['group_name'] ?? '' ),
            'description' => (string) ( $row['description'] ?? '' ),
        ];
        if ( $dry_run ) {
            return [ 'ok' => true, 'message' => 'Dry run: would upsert Workspace group ' . $group_email ];
        }

        $existing = \metis_people_workspace_google_request( 'GET', 'groups/' . rawurlencode( $group_email ), null, $cfg );
        if ( ! empty( $existing['ok'] ) ) {
            $resp = \metis_people_workspace_google_request( 'PUT', 'groups/' . rawurlencode( $group_email ), $group_body, $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Failed to update workspace group.' ];
            }
            $google_id = (string) ( ( $resp['body']['id'] ?? '' ) ?: ( $existing['body']['id'] ?? '' ) );
            \metis_db()->update( $groups_table, [ 'workspace_group_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced' ], [ 'id' => $entity_id ], [ '%s', '%s' ], [ '%d' ] );
            return [ 'ok' => true, 'message' => 'Updated Workspace group ' . $group_email ];
        }

        $create = \metis_people_workspace_google_request( 'POST', 'groups', $group_body, $cfg );
        if ( empty( $create['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Failed to create Workspace group.' ];
        }

        $google_id = (string) ( $create['body']['id'] ?? '' );
        \metis_db()->update( $groups_table, [ 'workspace_group_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced' ], [ 'id' => $entity_id ], [ '%s', '%s' ], [ '%d' ] );
        return [ 'ok' => true, 'message' => 'Created Workspace group ' . $group_email ];
    }

    private static function executeWorkspaceGroupMemberJob( string $job_type, int $entity_id, array $cfg, array $payload, bool $dry_run ): array {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $group_email = strtolower( trim( (string) ( $payload['group_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $group_email ) && $entity_id > 0 ) {
            $group_email = self::workspaceGroupEmail( $entity_id, $groups_table );
        }
        if ( ! \metis_email_is_valid( $group_email ) ) {
            return [ 'ok' => false, 'error' => 'Group email not found for membership sync.' ];
        }

        $members = $job_type === 'workspace_group_member_upsert'
            ? self::singleWorkspaceGroupMemberPayload( $payload )
            : self::workspaceGroupMembersForSync( $entity_id );
        if ( $members === [] && $job_type === 'workspace_group_member_upsert' ) {
            return [ 'ok' => false, 'error' => 'Member email invalid for group member sync.' ];
        }
        if ( $dry_run ) {
            return [ 'ok' => true, 'message' => 'Dry run: would sync ' . count( $members ) . ' members for ' . $group_email ];
        }

        if ( $job_type === 'workspace_group_members_bulk_sync' ) {
            self::removeUnexpectedRemoteMembers( $group_email, $members, $cfg );
        }

        foreach ( $members as $member ) {
            $member_email = strtolower( trim( (string) ( $member['email'] ?? '' ) ) );
            $role = self::normalizeWorkspaceMemberRole( (string) ( $member['role'] ?? 'MEMBER' ) );
            $payload_body = [ 'email' => $member_email, 'role' => $role ];
            $create = \metis_people_workspace_google_request( 'POST', 'groups/' . rawurlencode( $group_email ) . '/members', $payload_body, $cfg );
            if ( empty( $create['ok'] ) ) {
                $upsert = \metis_people_workspace_google_request( 'PUT', 'groups/' . rawurlencode( $group_email ) . '/members/' . rawurlencode( $member_email ), $payload_body, $cfg );
                if ( empty( $upsert['ok'] ) ) {
                    return [ 'ok' => false, 'error' => 'Failed to sync group member.' ];
                }
            }
        }

        if ( $entity_id > 0 ) {
            \metis_db()->update( $groups_table, [ 'sync_status' => 'synced' ], [ 'id' => $entity_id ], [ '%s' ], [ '%d' ] );
        }

        return [ 'ok' => true, 'message' => 'Synced group members for ' . $group_email ];
    }

    private static function executeWorkspaceSecurityActionJob( int $entity_id, array $cfg, array $payload, bool $dry_run ): array {
        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $actions_table = \Metis_Tables::get( 'people_workspace_security_actions' );
        $row = \metis_db()->fetchOne( "SELECT * FROM {$users_table} WHERE id = %d LIMIT 1", [ $entity_id ] );
        if ( ! $row ) {
            return [ 'ok' => false, 'error' => 'Workspace user not found for security action.' ];
        }

        $user_email = strtolower( trim( (string) ( $row['primary_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $user_email ) ) {
            return [ 'ok' => false, 'error' => 'Workspace user email invalid for security action.' ];
        }
        $action_type = \metis_key_clean( (string) ( $payload['action_type'] ?? '' ) );
        if ( $action_type === '' ) {
            return [ 'ok' => false, 'error' => 'Security action type is missing.' ];
        }
        if ( $dry_run ) {
            return [ 'ok' => true, 'message' => 'Dry run: would run security action ' . $action_type . ' for ' . $user_email ];
        }

        if ( $action_type === 'reset_password' ) {
            $resp = \metis_people_workspace_google_request( 'PUT', 'users/' . rawurlencode( $user_email ), [
                'password' => \metis_people_workspace_random_password( 20 ),
                'changePasswordAtNextLogin' => true,
            ], $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Password reset failed.' ];
            }
        } elseif ( $action_type === 'revoke_sessions' ) {
            $resp = \metis_people_workspace_google_request( 'POST', 'users/' . rawurlencode( $user_email ) . '/signOut', [], $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Session revoke failed.' ];
            }
        } elseif ( $action_type === 'force_2fa_reenroll' ) {
            $resp = \metis_people_workspace_google_request( 'POST', 'users/' . rawurlencode( $user_email ) . '/twoStepVerification/turnOff', [], $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => '2FA re-enroll reset failed.' ];
            }
        } elseif ( $action_type === 'suspend_account' || $action_type === 'unsuspend_account' ) {
            $suspended = $action_type === 'suspend_account';
            $resp = \metis_people_workspace_google_request( 'PUT', 'users/' . rawurlencode( $user_email ), [ 'suspended' => $suspended ], $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Suspend/unsuspend failed.' ];
            }
            \metis_db()->update( $users_table, [ 'is_suspended' => $suspended ? 1 : 0, 'sync_status' => 'synced' ], [ 'id' => $entity_id ], [ '%d', '%s' ], [ '%d' ] );
        } else {
            return [ 'ok' => false, 'error' => 'Unsupported security action type: ' . $action_type ];
        }

        self::markLatestSecurityActionCompleted( $actions_table, $entity_id, $action_type );
        return [ 'ok' => true, 'message' => 'Completed security action ' . $action_type . ' for ' . $user_email ];
    }

    private static function claimJob( string $jobs_table, int $job_id ): bool {
        $claimed_at = \metis_current_time( 'mysql' );
        $claimed = (int) \metis_db()->execute(
            "UPDATE {$jobs_table}
             SET status = 'processing', updated_at = %s
             WHERE id = %d AND status IN ('queued','failed')",
            [ $claimed_at, $job_id ]
        );

        return $claimed > 0;
    }

    private static function markJobCompleted( string $jobs_table, int $job_id ): void {
        \metis_db()->update( $jobs_table, [
            'status' => 'completed',
            'last_error' => null,
            'processed_at' => \metis_current_time( 'mysql' ),
        ], [ 'id' => $job_id ], [ '%s', '%s', '%s' ], [ '%d' ] );
    }

    private static function markJobFailed( string $jobs_table, int $job_id, string $error ): void {
        \metis_db()->update( $jobs_table, [
            'status' => 'failed',
            'last_error' => $error,
            'processed_at' => \metis_current_time( 'mysql' ),
        ], [ 'id' => $job_id ], [ '%s', '%s', '%s' ], [ '%d' ] );
    }

    private static function workspacePrimaryEmailForPerson( int $person_id ): string {
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );

        return strtolower( trim( (string) \metis_db()->scalar(
            "SELECT primary_email FROM {$workspace_users_table} WHERE person_id = %d ORDER BY id ASC LIMIT 1",
            [ $person_id ]
        ) ) );
    }

    private static function personWorkspaceEmail( int $person_id ): string {
        $people_table = \Metis_Tables::get( 'people' );

        return strtolower( trim( (string) \metis_db()->scalar(
            "SELECT workspace_email FROM {$people_table} WHERE id = %d LIMIT 1",
            [ $person_id ]
        ) ) );
    }

    private static function workspaceGroupEmail( int $entity_id, string $groups_table ): string {
        return strtolower( trim( (string) \metis_db()->scalar(
            "SELECT group_email FROM {$groups_table} WHERE id = %d LIMIT 1",
            [ $entity_id ]
        ) ) );
    }

    private static function singleWorkspaceGroupMemberPayload( array $payload ): array {
        $member_email = strtolower( trim( (string) ( $payload['member_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $member_email ) ) {
            return [];
        }

        return [
            [
                'email' => $member_email,
                'role' => (string) ( $payload['member_role'] ?? 'MEMBER' ),
            ],
        ];
    }

    private static function workspaceGroupMembersForSync( int $entity_id ): array {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $rows = \metis_db()->fetchAll(
            "SELECT wu.primary_email, gm.member_role
             FROM {$members_table} gm
             INNER JOIN {$users_table} wu ON wu.id = gm.workspace_user_id
             WHERE gm.group_id = %d",
            [ $entity_id ]
        ) ?: [];

        $members = [];
        foreach ( $rows as $row ) {
            $member_email = strtolower( trim( (string) ( $row['primary_email'] ?? '' ) ) );
            if ( ! \metis_email_is_valid( $member_email ) ) {
                continue;
            }
            $members[] = [
                'email' => $member_email,
                'role' => (string) ( $row['member_role'] ?? 'member' ),
            ];
        }

        return $members;
    }

    private static function removeUnexpectedRemoteMembers( string $group_email, array $members, array $cfg ): void {
        $desired_member_emails = [];
        foreach ( $members as $member ) {
            $member_email = strtolower( trim( (string) ( $member['email'] ?? '' ) ) );
            if ( \metis_email_is_valid( $member_email ) ) {
                $desired_member_emails[ $member_email ] = true;
            }
        }

        $page_token = '';
        $page_guard = 0;
        while ( $page_guard < 20 ) {
            $page_guard++;
            $remote_query = 'groups/' . rawurlencode( $group_email ) . '/members?maxResults=100';
            if ( $page_token !== '' ) {
                $remote_query .= '&pageToken=' . rawurlencode( $page_token );
            }
            $remote = \metis_people_workspace_google_request( 'GET', $remote_query, null, $cfg );
            if ( empty( $remote['ok'] ) ) {
                break;
            }
            $remote_members = (array) ( $remote['body']['members'] ?? [] );
            foreach ( $remote_members as $remote_member ) {
                $remote_email = strtolower( trim( (string) ( $remote_member['email'] ?? '' ) ) );
                $remote_type = strtolower( trim( (string) ( $remote_member['type'] ?? '' ) ) );
                if ( ! \metis_email_is_valid( $remote_email ) || $remote_type === 'group' || isset( $desired_member_emails[ $remote_email ] ) ) {
                    continue;
                }
                \metis_people_workspace_google_request(
                    'delete',
                    'groups/' . rawurlencode( $group_email ) . '/members/' . rawurlencode( $remote_email ),
                    null,
                    $cfg
                );
            }
            $page_token = trim( (string) ( $remote['body']['nextPageToken'] ?? '' ) );
            if ( $page_token === '' ) {
                break;
            }
        }
    }

    private static function normalizeWorkspaceMemberRole( string $role ): string {
        $role = strtoupper( $role );
        if ( $role === 'OWNER' ) {
            return 'OWNER';
        }
        if ( $role === 'MANAGER' ) {
            return 'MANAGER';
        }

        return 'MEMBER';
    }

    private static function markLatestSecurityActionCompleted( string $actions_table, int $entity_id, string $action_type ): void {
        $completed_at = \metis_current_time( 'mysql' );
        \metis_db()->execute(
            "UPDATE {$actions_table}
             SET status = 'completed', completed_at = %s, updated_at = %s
             WHERE workspace_user_id = %d
               AND action_type = %s
               AND status IN ('pending','queued')
             ORDER BY id DESC
             LIMIT 1",
            [ $completed_at, $completed_at, $entity_id, $action_type ]
        );
    }
}
