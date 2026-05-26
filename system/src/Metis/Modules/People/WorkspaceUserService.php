<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class WorkspaceUserService {
    public static function roleKeysForUser( int $workspace_user_id ): array {
        if ( $workspace_user_id < 1 ) {
            return [];
        }

        $user_roles_table = \Metis_Tables::get( 'people_workspace_user_roles' );
        $rows = \metis_db()->fetchAll(
            "SELECT role_key
             FROM {$user_roles_table}
             WHERE workspace_user_id = %d",
            [ $workspace_user_id ]
        ) ?: [];
        $keys = [];
        foreach ( $rows as $row ) {
            $role_key = \metis_key_clean( (string) ( $row['role_key'] ?? '' ) );
            if ( $role_key !== '' ) {
                $keys[] = $role_key;
            }
        }

        return array_values( array_unique( $keys ) );
    }

    public static function saveUser( array $data, ?int $actor_id ): array {
        $db = \metis_db();
        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $user_roles_table = \Metis_Tables::get( 'people_workspace_user_roles' );
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $group_members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        $people_table = \Metis_Tables::get( 'people' );

        $workspace_user_id = (int) ( $data['workspace_user_id'] ?? 0 );
        $primary_email = strtolower( trim( (string) ( $data['primary_email'] ?? '' ) ) );
        $first_name = trim( (string) ( $data['first_name'] ?? '' ) );
        $last_name = trim( (string) ( $data['last_name'] ?? '' ) );
        $display_name = trim( (string) ( $data['display_name'] ?? '' ) );
        $org_unit_path = trim( (string) ( $data['org_unit_path'] ?? '/' ) );
        $secondary_email = strtolower( trim( (string) ( $data['secondary_email'] ?? '' ) ) );
        $recovery_email = strtolower( trim( (string) ( $data['recovery_email'] ?? '' ) ) );
        $linked_pid = strtoupper( trim( (string) ( $data['linked_pid'] ?? '' ) ) );
        $is_suspended = ! empty( $data['is_suspended'] ) ? 1 : 0;
        $is_protected = ! empty( $data['is_protected'] ) ? 1 : 0;
        $is_hidden = ! empty( $data['is_hidden'] ) ? 1 : 0;
        $create_metis_user = ! empty( $data['create_metis_user'] );
        $create_drive_folder = ! empty( $data['create_drive_folder'] );
        $role_keys = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $data['role_keys'] ?? [] ) ) ) ) );
        $group_ids = array_values( array_unique( array_map( 'intval', (array) ( $data['group_ids'] ?? [] ) ) ) );

        if ( ! \metis_email_is_valid( $primary_email ) ) {
            \metis_runtime_send_json_error( 'Valid primary email is required.', 400 );
        }
        if ( $recovery_email !== '' && ! \metis_email_is_valid( $recovery_email ) ) {
            \metis_runtime_send_json_error( 'Recovery email is invalid.', 400 );
        }
        if ( $secondary_email !== '' && ! \metis_email_is_valid( $secondary_email ) ) {
            \metis_runtime_send_json_error( 'Secondary email is invalid.', 400 );
        }
        if ( $org_unit_path === '' ) {
            $org_unit_path = '/';
        }
        if ( $display_name === '' ) {
            $display_name = trim( $first_name . ' ' . $last_name );
        }
        if ( $display_name === '' ) {
            $display_name = $primary_email;
        }
        if ( $create_drive_folder && ! $create_metis_user && $linked_pid === '' ) {
            \metis_runtime_send_json_error( 'Create a linked Metis user before creating a Drive folder.', 400 );
        }

        $person_id = null;
        if ( $linked_pid !== '' ) {
            $person_id = (int) $db->scalar( "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $linked_pid ] );
            if ( $person_id < 1 ) {
                \metis_runtime_send_json_error( 'Linked PID was not found.', 400 );
            }
        }

        $email_conflict = (int) $db->scalar(
            "SELECT id FROM {$users_table} WHERE primary_email = %s AND id <> %d LIMIT 1",
            [ $primary_email, $workspace_user_id ]
        );
        if ( $email_conflict > 0 ) {
            if ( $workspace_user_id < 1 ) {
                $existing_user = $db->fetchOne(
                    "SELECT id, primary_email, first_name, last_name, display_name, org_unit_path, recovery_email, is_suspended, is_protected, metadata_json, person_id
                     FROM {$users_table}
                     WHERE id = %d
                     LIMIT 1",
                    [ $email_conflict ]
                );
                if ( $existing_user ) {
                    $existing_metadata = json_decode( (string) ( $existing_user['metadata_json'] ?? '' ), true );
                    if ( ! is_array( $existing_metadata ) ) {
                        $existing_metadata = [];
                    }
                    $existing_group_rows = $db->fetchAll(
                        "SELECT group_id
                         FROM {$group_members_table}
                         WHERE workspace_user_id = %d",
                        [ $email_conflict ]
                    ) ?: [];
                    $existing_group_ids = [];
                    foreach ( $existing_group_rows as $existing_group_row ) {
                        $existing_group_id = (int) ( $existing_group_row['group_id'] ?? 0 );
                        if ( $existing_group_id > 0 ) {
                            $existing_group_ids[] = $existing_group_id;
                        }
                    }
                    return [
                        'existing' => true,
                        'workspace_user_id' => $email_conflict,
                        'sync_warning' => 'That Workspace email already exists in Metis. The existing account was returned instead of creating a duplicate.',
                        'user' => [
                            'id' => $email_conflict,
                            'primary_email' => (string) ( $existing_user['primary_email'] ?? $primary_email ),
                            'display_name' => (string) ( $existing_user['display_name'] ?? '' ),
                            'first_name' => (string) ( $existing_user['first_name'] ?? '' ),
                            'last_name' => (string) ( $existing_user['last_name'] ?? '' ),
                            'org_unit_path' => (string) ( $existing_user['org_unit_path'] ?? '/' ),
                            'recovery_email' => (string) ( $existing_user['recovery_email'] ?? '' ),
                            'secondary_email' => (string) ( $existing_metadata['secondary_email'] ?? '' ),
                            'linked_pid' => '',
                            'linked_name' => '',
                            'person_url' => '',
                            'is_suspended' => ! empty( $existing_user['is_suspended'] ) ? 1 : 0,
                            'is_protected' => ! empty( $existing_user['is_protected'] ) ? 1 : 0,
                            'is_hidden' => ! empty( $existing_metadata['ui_hidden'] ) ? 1 : 0,
                            'role_keys' => \metis_people_workspace_role_keys_for_user( $email_conflict ),
                            'group_ids' => $existing_group_ids,
                        ],
                    ];
                }
            }
            \metis_runtime_send_json_error( 'Primary email already exists in workspace users.', 400 );
        }
        if ( $person_id !== null && $person_id > 0 ) {
            $person_email_conflict = (int) $db->scalar(
                "SELECT id FROM {$people_table} WHERE email = %s AND id <> %d LIMIT 1",
                [ $primary_email, $person_id ]
            );
            if ( $person_email_conflict > 0 ) {
                \metis_runtime_send_json_error( 'Email is already used by a different Metis profile.', 400 );
            }
        }

        $group_email_by_id = self::validateGroups( $group_ids, $groups_table );
        $payload = [
            'person_id' => $person_id ?: null,
            'primary_email' => $primary_email,
            'first_name' => $first_name !== '' ? $first_name : null,
            'last_name' => $last_name !== '' ? $last_name : null,
            'display_name' => $display_name,
            'org_unit_path' => $org_unit_path,
            'recovery_email' => $recovery_email !== '' ? $recovery_email : null,
            'is_suspended' => $is_suspended,
            'is_protected' => $is_protected,
            'sync_status' => 'queued',
        ];
        $previous_primary_email = '';
        $existing_metadata = [];
        if ( $workspace_user_id > 0 ) {
            $existing_row = $db->fetchOne(
                "SELECT primary_email, metadata_json
                 FROM {$users_table}
                 WHERE id = %d
                 LIMIT 1",
                [ $workspace_user_id ]
            );
            $previous_primary_email = strtolower( trim( (string) ( $existing_row['primary_email'] ?? '' ) ) );
            $existing_metadata = json_decode( (string) ( $existing_row['metadata_json'] ?? '' ), true );
            if ( ! is_array( $existing_metadata ) ) {
                $existing_metadata = [];
            }
        }
        if ( $is_hidden ) {
            $existing_metadata['ui_hidden'] = 1;
        } else {
            unset( $existing_metadata['ui_hidden'] );
        }
        if ( $secondary_email !== '' ) {
            $existing_metadata['secondary_email'] = $secondary_email;
        } else {
            unset( $existing_metadata['secondary_email'] );
        }
        $payload['metadata_json'] = \metis_json_encode( $existing_metadata );
        $is_new_user = $workspace_user_id < 1;
        if ( $is_new_user && $recovery_email === '' ) {
            \metis_runtime_send_json_error( 'A secondary/recovery email is required so Metis can send the first sign-in instructions.', 400 );
        }

        if ( $workspace_user_id > 0 ) {
            $ok = $db->update( $users_table, $payload, [ 'id' => $workspace_user_id ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ], [ '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update workspace user.', 500 );
            }
        } else {
            $ok = $db->insert( $users_table, $payload, [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ] );
            if ( ! $ok ) {
                \metis_runtime_send_json_error( 'Failed to create workspace user.', 500 );
            }
            $workspace_user_id = (int) $db->lastInsertId();
        }

        self::storeRoleKeys( $workspace_user_id, $role_keys, $user_roles_table );
        $affected_group_ids = self::replaceGroupMemberships( $workspace_user_id, $group_ids, $groups_table, $group_members_table, $group_email_by_id );

        $sync_payload = [
            'primary_email' => $primary_email,
            'roles' => $role_keys,
            'is_suspended' => $is_suspended,
            'previous_primary_email' => $previous_primary_email,
            'add_alias_email' => ( $previous_primary_email !== '' && $previous_primary_email !== $primary_email ) ? $previous_primary_email : '',
        ];
        $job_type = $is_new_user ? 'workspace_user_create' : 'workspace_user_upsert';
        [ $job_id, $sync_warning ] = self::syncWorkspaceUser( $workspace_user_id, $users_table, $job_type, $sync_payload, $primary_email, $recovery_email, $display_name, $group_ids, $affected_group_ids, $group_email_by_id, $actor_id );

        $linked_pid_out = '';
        $linked_name_out = '';
        $person_url_out = '';
        if ( $person_id !== null && $person_id > 0 ) {
            [ $linked_pid_out, $linked_name_out, $person_url_out ] = self::updateLinkedPerson( $person_id, $people_table, $primary_email, $first_name, $last_name, $display_name );
        }

        $metis_user = null;
        if ( $create_metis_user && ( $person_id === null || $person_id < 1 ) ) {
            $metis_user = self::linkOrCreateMetisUser( $workspace_user_id, $users_table, $people_table, $primary_email, $first_name, $last_name, $display_name );
            $person_id = (int) ( $metis_user['person_id'] ?? 0 );
            $linked_pid_out = (string) ( $metis_user['pid'] ?? '' );
            $person_url_out = (string) ( $metis_user['person_url'] ?? '' );
            if ( $person_id > 0 ) {
                $linked_name_out = trim( $first_name . ' ' . $last_name );
                if ( $linked_name_out === '' ) {
                    $linked_name_out = $display_name;
                }
            }
        } elseif ( $create_metis_user && $person_id !== null && $person_id > 0 ) {
            $metis_user = [
                'ok' => true,
                'created' => false,
                'person_id' => $person_id,
                'pid' => $linked_pid_out !== '' ? $linked_pid_out : $linked_pid,
                'person_url' => $person_url_out,
            ];
        }

        $drive_folder = null;
        if ( $create_drive_folder && $person_id !== null && $person_id > 0 ) {
            $drive_folder = \metis_people_workspace_autocreate_drive_folder( (int) $person_id );
        }

        return [
            'existing' => false,
            'workspace_user_id' => $workspace_user_id,
            'job_id' => $job_id,
            'metis_user' => $metis_user,
            'drive_folder' => $drive_folder,
            'sync_warning' => $sync_warning,
            'person_id' => $person_id,
            'user' => [
                'id' => $workspace_user_id,
                'primary_email' => $primary_email,
                'display_name' => $display_name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'org_unit_path' => $org_unit_path,
                'recovery_email' => $recovery_email,
                'secondary_email' => $secondary_email,
                'linked_pid' => $linked_pid_out !== '' ? $linked_pid_out : $linked_pid,
                'linked_name' => $linked_name_out,
                'person_url' => $person_url_out,
                'is_suspended' => $is_suspended,
                'is_protected' => $is_protected,
                'is_hidden' => $is_hidden,
                'role_keys' => $role_keys,
                'group_ids' => $group_ids,
            ],
        ];
    }

    public static function createMetisUser( int $workspace_user_id ): array {
        $db = \metis_db();
        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $people_table = \Metis_Tables::get( 'people' );

        $workspace_user = $db->fetchOne(
            "SELECT id, person_id, primary_email, first_name, last_name, display_name
             FROM {$users_table}
             WHERE id = %d
             LIMIT 1",
            [ $workspace_user_id ]
        );
        if ( ! $workspace_user ) {
            \metis_runtime_send_json_error( 'Workspace user not found.', 404 );
        }

        $primary_email = strtolower( trim( (string) ( $workspace_user['primary_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $primary_email ) ) {
            \metis_runtime_send_json_error( 'Workspace user email is invalid.', 400 );
        }

        $linked_person_id = (int) ( $workspace_user['person_id'] ?? 0 );
        if ( $linked_person_id > 0 ) {
            $pid = (string) $db->scalar( "SELECT pid FROM {$people_table} WHERE id = %d LIMIT 1", [ $linked_person_id ] );
            return [
                'person_id' => $linked_person_id,
                'pid' => $pid,
                'already_linked' => 1,
                'person_url' => $pid !== '' ? (string) \metis_people_person_url( $pid ) : '',
                'drive_folder' => \metis_people_workspace_autocreate_drive_folder( $linked_person_id ),
            ];
        }

        $first_name = trim( (string) ( $workspace_user['first_name'] ?? '' ) );
        $last_name = trim( (string) ( $workspace_user['last_name'] ?? '' ) );
        $display_name = $first_name;
        if ( $display_name === '' ) {
            $display_name = trim( (string) ( $workspace_user['display_name'] ?? '' ) );
        }
        if ( $display_name === '' ) {
            $display_name = trim( $first_name . ' ' . $last_name );
        }
        if ( $display_name === '' ) {
            $display_name = $primary_email;
        }

        $metis_user = self::linkOrCreateMetisUser( $workspace_user_id, $users_table, $people_table, $primary_email, $first_name, $last_name, $display_name );
        $person_id = (int) ( $metis_user['person_id'] ?? 0 );
        $pid = (string) ( $metis_user['pid'] ?? '' );

        return [
            'person_id' => $person_id,
            'pid' => $pid,
            'person_url' => $pid !== '' ? (string) \metis_people_person_url( $pid ) : '',
            'workspace_user_id' => $workspace_user_id,
            'drive_folder' => $person_id > 0 ? \metis_people_workspace_autocreate_drive_folder( $person_id ) : null,
        ];
    }

    public static function deleteUser( int $workspace_user_id ): array {
        $db = \metis_db();
        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $user_roles_table = \Metis_Tables::get( 'people_workspace_user_roles' );
        $group_members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        $security_actions_table = \Metis_Tables::get( 'people_workspace_security_actions' );

        $user_row = $db->fetchOne(
            "SELECT id, person_id, primary_email, is_protected
             FROM {$users_table}
             WHERE id = %d
             LIMIT 1",
            [ $workspace_user_id ]
        );
        if ( ! $user_row ) {
            \metis_runtime_send_json_error( 'Workspace user not found.', 404 );
        }
        if ( ! empty( $user_row['is_protected'] ) ) {
            \metis_runtime_send_json_error( 'Protected workspace users cannot be deleted.', 400 );
        }
        if ( (int) ( $user_row['person_id'] ?? 0 ) > 0 ) {
            \metis_runtime_send_json_error( 'Linked Metis users cannot be deleted here.', 400 );
        }

        $primary_email = strtolower( trim( (string) ( $user_row['primary_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $primary_email ) ) {
            \metis_runtime_send_json_error( 'Workspace email is invalid.', 400 );
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( ! empty( $cfg['ok'] ) ) {
            $remote = \metis_people_workspace_google_request( 'delete', 'users/' . rawurlencode( $primary_email ), null, $cfg );
            if ( empty( $remote['ok'] ) ) {
                \metis_runtime_send_json_error( 'Failed to delete workspace account in Google.', 400 );
            }
        }

        $db->delete( $group_members_table, [ 'workspace_user_id' => $workspace_user_id ], [ '%d' ] );
        $db->delete( $user_roles_table, [ 'workspace_user_id' => $workspace_user_id ], [ '%d' ] );
        $db->delete( $security_actions_table, [ 'workspace_user_id' => $workspace_user_id ], [ '%d' ] );
        $deleted = $db->delete( $users_table, [ 'id' => $workspace_user_id ], [ '%d' ] );
        if ( $deleted === false ) {
            \metis_runtime_send_json_error( 'Failed to delete workspace user record.', 500 );
        }

        return [
            'workspace_user_id' => $workspace_user_id,
            'primary_email' => $primary_email,
        ];
    }

    public static function updateFlags( int $workspace_user_id, bool $has_hidden, bool $has_protected, int $hidden_value, int $protected_value ): array {
        if ( ! $has_hidden && ! $has_protected ) {
            \metis_runtime_send_json_error( 'No flag update was provided.', 400 );
        }

        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $row = \metis_db()->fetchOne(
            "SELECT id, person_id, primary_email, is_suspended, is_protected, metadata_json
             FROM {$users_table}
             WHERE id = %d
             LIMIT 1",
            [ $workspace_user_id ]
        );
        if ( ! $row ) {
            \metis_runtime_send_json_error( 'Workspace user not found.', 404 );
        }
        if ( (int) ( $row['person_id'] ?? 0 ) > 0 ) {
            \metis_runtime_send_json_error( 'Only non-Metis email users can be hidden or protected here.', 400 );
        }

        $metadata = json_decode( (string) ( $row['metadata_json'] ?? '' ), true );
        if ( ! is_array( $metadata ) ) {
            $metadata = [];
        }

        $is_hidden = ! empty( $metadata['ui_hidden'] ) ? 1 : 0;
        $is_protected = ! empty( $row['is_protected'] ) ? 1 : 0;
        if ( $has_hidden ) {
            $is_hidden = $hidden_value ? 1 : 0;
        }
        if ( $has_protected ) {
            $is_protected = $protected_value ? 1 : 0;
        }

        if ( $is_hidden ) {
            $metadata['ui_hidden'] = 1;
        } else {
            unset( $metadata['ui_hidden'] );
        }

        $ok = \metis_db()->update(
            $users_table,
            [
                'is_protected' => $is_protected,
                'metadata_json' => \metis_json_encode( $metadata ),
            ],
            [ 'id' => $workspace_user_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to update user flags.', 500 );
        }

        return [
            'workspace_user_id' => $workspace_user_id,
            'primary_email' => (string) ( $row['primary_email'] ?? '' ),
            'is_hidden' => $is_hidden,
            'is_protected' => $is_protected,
            'is_suspended' => ! empty( $row['is_suspended'] ) ? 1 : 0,
        ];
    }

    public static function queueSecurityAction( int $workspace_user_id, string $action_type, string $reason, ?int $actor_id ): array {
        $allowed_actions = [ 'reset_password', 'revoke_sessions', 'force_2fa_reenroll', 'suspend_account', 'unsuspend_account' ];
        if ( $workspace_user_id < 1 || ! in_array( $action_type, $allowed_actions, true ) || trim( $reason ) === '' ) {
            \metis_runtime_send_json_error( 'Valid user, action, and reason are required.', 400 );
        }

        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $actions_table = \Metis_Tables::get( 'people_workspace_security_actions' );
        $user_row = \metis_db()->fetchOne(
            "SELECT id, person_id, primary_email FROM {$users_table} WHERE id = %d LIMIT 1",
            [ $workspace_user_id ]
        );
        if ( ! $user_row ) {
            \metis_runtime_send_json_error( 'Workspace user not found.', 404 );
        }

        \metis_db()->insert( $actions_table, [
            'workspace_user_id' => $workspace_user_id,
            'action_type' => $action_type,
            'requested_by_person_id' => $actor_id,
            'status' => 'pending',
            'reason' => $reason,
        ], [ '%d', '%s', '%d', '%s', '%s' ] );

        if ( $action_type === 'suspend_account' || $action_type === 'unsuspend_account' ) {
            \metis_db()->update( $users_table, [
                'is_suspended' => $action_type === 'suspend_account' ? 1 : 0,
                'sync_status' => 'queued',
            ], [ 'id' => $workspace_user_id ], [ '%d', '%s' ], [ '%d' ] );
        }

        $job_id = \metis_people_workspace_queue_job(
            'workspace_security_action',
            'workspace_user',
            $workspace_user_id,
            $actor_id,
            [ 'action_type' => $action_type, 'reason' => $reason ]
        );

        return [
            'job_id' => $job_id,
            'action_type' => $action_type,
            'person_id' => (int) ( $user_row['person_id'] ?? 0 ),
            'primary_email' => (string) ( $user_row['primary_email'] ?? '' ),
            'workspace_user_id' => $workspace_user_id,
        ];
    }

    public static function bulkAction( string $action_type, string $org_unit_path, array $person_pids, ?int $actor_id ): array {
        $allowed_actions = [
            'set_org_unit',
            'suspend_account',
            'unsuspend_account',
            'reset_password',
            'set_hidden',
            'clear_hidden',
            'create_drive_folder',
            'sync_now',
        ];
        if ( $person_pids === [] ) {
            \metis_runtime_send_json_error( 'Select at least one person.', 400 );
        }
        if ( ! in_array( $action_type, $allowed_actions, true ) ) {
            \metis_runtime_send_json_error( 'Invalid workspace action.', 400 );
        }
        if ( $action_type === 'set_org_unit' ) {
            $org_unit_path = trim( $org_unit_path );
            if ( $org_unit_path === '' ) {
                $org_unit_path = '/';
            }
            if ( substr( $org_unit_path, 0, 1 ) !== '/' ) {
                \metis_runtime_send_json_error( 'Org Unit must start with "/".', 400 );
            }
        }

        $needs_remote = in_array( $action_type, [ 'set_org_unit', 'suspend_account', 'unsuspend_account', 'reset_password', 'sync_now' ], true );
        $cfg = [];
        if ( $needs_remote ) {
            $cfg = \metis_people_workspace_sync_settings();
            if ( empty( $cfg['ok'] ) ) {
                \metis_runtime_send_json_error( 'Workspace settings are not configured.', 400 );
            }
        }

        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        foreach ( $person_pids as $pid ) {
            $person = self::bulkActionPersonByPid( $pid );
            if ( ! $person ) {
                $skipped++;
                continue;
            }

            $person_id = (int) ( $person['id'] ?? 0 );
            if ( $action_type === 'create_drive_folder' ) {
                if ( $person_id < 1 ) {
                    $skipped++;
                    continue;
                }
                $created = \metis_people_workspace_autocreate_drive_folder( $person_id );
                if ( empty( $created['ok'] ) ) {
                    $failed++;
                    $errors[] = (string) ( $person['display_name'] ?? $pid ) . ': ' . (string) ( $created['error'] ?? 'Drive folder creation failed.' );
                    continue;
                }
                $updated++;
                continue;
            }

            $workspace_user = self::bulkActionWorkspaceUserForPerson( $person_id, (string) ( $person['workspace_email'] ?? '' ), (string) ( $person['email'] ?? '' ) );
            if ( ! $workspace_user ) {
                $skipped++;
                continue;
            }
            $workspace_user_id = (int) ( $workspace_user['id'] ?? 0 );
            if ( $workspace_user_id < 1 ) {
                $skipped++;
                continue;
            }

            if ( $action_type === 'set_hidden' || $action_type === 'clear_hidden' ) {
                $result = self::updateFlags(
                    $workspace_user_id,
                    true,
                    false,
                    $action_type === 'set_hidden' ? 1 : 0,
                    0
                );
                if ( empty( $result['workspace_user_id'] ) ) {
                    $failed++;
                    $errors[] = (string) ( $person['display_name'] ?? $pid ) . ': failed to update local hidden flag.';
                    continue;
                }
                $updated++;
                continue;
            }

            if ( $action_type === 'set_org_unit' ) {
                \metis_db()->update(
                    \Metis_Tables::get( 'people_workspace_users' ),
                    [ 'org_unit_path' => $org_unit_path, 'sync_status' => 'queued', 'updated_at' => \metis_current_time( 'mysql' ) ],
                    [ 'id' => $workspace_user_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                $job_payload = [
                    'primary_email' => (string) ( $workspace_user['primary_email'] ?? '' ),
                    'roles' => \metis_people_workspace_role_keys_for_user( $workspace_user_id ),
                    'is_suspended' => ! empty( $workspace_user['is_suspended'] ) ? 1 : 0,
                    'reason' => 'bulk_set_org_unit',
                ];
                $result = \metis_people_workspace_execute_job( [
                    'job_type' => 'workspace_user_upsert',
                    'entity_type' => 'workspace_user',
                    'entity_id' => $workspace_user_id,
                    'payload_json' => \metis_json_encode( $job_payload ),
                ], $cfg, false );
                if ( empty( $result['ok'] ) ) {
                    $failed++;
                    $errors[] = (string) ( $person['display_name'] ?? $pid ) . ': ' . (string) ( $result['error'] ?? 'Org Unit update failed.' );
                    continue;
                }
                $updated++;
                continue;
            }

            if ( $action_type === 'sync_now' ) {
                $job_payload = [
                    'primary_email' => (string) ( $workspace_user['primary_email'] ?? '' ),
                    'roles' => \metis_people_workspace_role_keys_for_user( $workspace_user_id ),
                    'is_suspended' => ! empty( $workspace_user['is_suspended'] ) ? 1 : 0,
                    'reason' => 'bulk_sync_now',
                ];
                $result = \metis_people_workspace_execute_job( [
                    'job_type' => 'workspace_user_upsert',
                    'entity_type' => 'workspace_user',
                    'entity_id' => $workspace_user_id,
                    'payload_json' => \metis_json_encode( $job_payload ),
                ], $cfg, false );
                if ( empty( $result['ok'] ) ) {
                    $failed++;
                    $errors[] = (string) ( $person['display_name'] ?? $pid ) . ': ' . (string) ( $result['error'] ?? 'Sync failed.' );
                    continue;
                }
                $updated++;
                continue;
            }

            if ( in_array( $action_type, [ 'suspend_account', 'unsuspend_account', 'reset_password' ], true ) ) {
                $result = self::queueSecurityAction( $workspace_user_id, $action_type, 'bulk_workspace_action', $actor_id );
                $exec = \metis_people_workspace_execute_job( [
                    'job_type' => 'workspace_security_action',
                    'entity_type' => 'workspace_user',
                    'entity_id' => $workspace_user_id,
                    'payload_json' => \metis_json_encode( [ 'action_type' => $action_type, 'reason' => 'bulk_workspace_action' ] ),
                ], $cfg, false );
                if ( empty( $exec['ok'] ) ) {
                    $failed++;
                    $errors[] = (string) ( $person['display_name'] ?? $pid ) . ': ' . (string) ( $exec['error'] ?? 'Workspace security action failed.' );
                    continue;
                }
                $updated++;
                continue;
            }
        }

        return [
            'workspace_action' => $action_type,
            'org_unit_path' => $action_type === 'set_org_unit' ? $org_unit_path : '',
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => array_slice( $errors, 0, 5 ),
        ];
    }

    private static function bulkActionPersonByPid( string $pid ): ?array {
        $people_table = \Metis_Tables::get( 'people' );
        $row = \metis_db()->fetchOne(
            "SELECT id, pid, display_name, email, workspace_email
             FROM {$people_table}
             WHERE pid = %s
             LIMIT 1",
            [ $pid ]
        );

        return is_array( $row ) ? $row : null;
    }

    private static function bulkActionWorkspaceUserForPerson( int $person_id, string $workspace_email, string $fallback_email ): ?array {
        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $workspace_email = strtolower( trim( $workspace_email ) );
        if ( ! \metis_email_is_valid( $workspace_email ) ) {
            $workspace_email = strtolower( trim( $fallback_email ) );
        }

        $workspace_user = null;
        if ( $person_id > 0 ) {
            $workspace_user = \metis_db()->fetchOne(
                "SELECT id, person_id, primary_email, org_unit_path, is_suspended, metadata_json
                 FROM {$users_table}
                 WHERE person_id = %d
                 LIMIT 1",
                [ $person_id ]
            );
        }
        if ( ! $workspace_user && \metis_email_is_valid( $workspace_email ) ) {
            $workspace_user = \metis_db()->fetchOne(
                "SELECT id, person_id, primary_email, org_unit_path, is_suspended, metadata_json
                 FROM {$users_table}
                 WHERE primary_email = %s
                 LIMIT 1",
                [ $workspace_email ]
            );
        }

        return is_array( $workspace_user ) ? $workspace_user : null;
    }

    private static function validateGroups( array $group_ids, string $groups_table ): array {
        if ( $group_ids === [] ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
        $group_rows = \metis_db()->fetchAll(
            "SELECT id, group_email
             FROM {$groups_table}
             WHERE id IN ({$placeholders})",
            $group_ids
        ) ?: [];

        $valid_group_ids = [];
        $group_email_by_id = [];
        foreach ( $group_rows as $group_row ) {
            $gid = (int) ( $group_row['id'] ?? 0 );
            if ( $gid < 1 ) {
                continue;
            }
            $valid_group_ids[ $gid ] = true;
            $group_email = strtolower( trim( (string) ( $group_row['group_email'] ?? '' ) ) );
            if ( \metis_email_is_valid( $group_email ) ) {
                $group_email_by_id[ $gid ] = $group_email;
            }
        }
        foreach ( $group_ids as $gid ) {
            if ( empty( $valid_group_ids[ $gid ] ) ) {
                \metis_runtime_send_json_error( 'One of the selected Workspace groups was not found.', 400 );
            }
        }

        return $group_email_by_id;
    }

    private static function storeRoleKeys( int $workspace_user_id, array $role_keys, string $user_roles_table ): void {
        \metis_db()->delete( $user_roles_table, [ 'workspace_user_id' => $workspace_user_id ], [ '%d' ] );
        foreach ( $role_keys as $role_key ) {
            \metis_db()->insert( $user_roles_table, [
                'workspace_user_id' => $workspace_user_id,
                'role_key' => $role_key,
            ], [ '%d', '%s' ] );
        }
    }

    private static function replaceGroupMemberships( int $workspace_user_id, array $group_ids, string $groups_table, string $group_members_table, array $group_email_by_id ): array {
        $db = \metis_db();
        $existing_group_rows = $db->fetchAll(
            "SELECT gm.group_id, wg.group_email
             FROM {$group_members_table} gm
             INNER JOIN {$groups_table} wg ON wg.id = gm.group_id
             WHERE gm.workspace_user_id = %d",
            [ $workspace_user_id ]
        ) ?: [];
        $existing_group_ids = [];
        $existing_group_email_by_id = [];
        foreach ( $existing_group_rows as $group_row ) {
            $gid = (int) ( $group_row['group_id'] ?? 0 );
            if ( $gid < 1 ) {
                continue;
            }
            $existing_group_ids[] = $gid;
            $group_email = strtolower( trim( (string) ( $group_row['group_email'] ?? '' ) ) );
            if ( \metis_email_is_valid( $group_email ) ) {
                $existing_group_email_by_id[ $gid ] = $group_email;
            }
        }
        $affected_group_ids = array_values( array_unique( array_merge( $existing_group_ids, $group_ids ) ) );
        $db->delete( $group_members_table, [ 'workspace_user_id' => $workspace_user_id ], [ '%d' ] );
        foreach ( $group_ids as $gid ) {
            $db->insert( $group_members_table, [
                'group_id' => $gid,
                'workspace_user_id' => $workspace_user_id,
                'member_role' => 'member',
            ], [ '%d', '%d', '%s' ] );
        }
        foreach ( $affected_group_ids as $gid ) {
            $member_count = (int) $db->scalar(
                "SELECT COUNT(*) FROM {$group_members_table} WHERE group_id = %d",
                [ (int) $gid ]
            );
            $db->update(
                $groups_table,
                [ 'direct_members_count' => $member_count, 'sync_status' => 'queued' ],
                [ 'id' => (int) $gid ],
                [ '%d', '%s' ],
                [ '%d' ]
            );
            if ( ! isset( $group_email_by_id[ $gid ] ) && isset( $existing_group_email_by_id[ $gid ] ) ) {
                $group_email_by_id[ $gid ] = $existing_group_email_by_id[ $gid ];
            }
        }

        return [ 'ids' => $affected_group_ids, 'emails' => $group_email_by_id ];
    }

    private static function syncWorkspaceUser( int $workspace_user_id, string $users_table, string $job_type, array $sync_payload, string $primary_email, string $recovery_email, string $display_name, array $group_ids, array $affected_group_data, ?int $actor_id ): array {
        $db = \metis_db();
        $job_id = 0;
        $sync_warning = '';
        if ( function_exists( 'metis_people_workspace_sync_settings' ) && function_exists( 'metis_people_workspace_execute_job' ) ) {
            $cfg = \metis_people_workspace_sync_settings();
            if ( empty( $cfg['ok'] ) ) {
                $db->update( $users_table, [ 'sync_status' => 'pending' ], [ 'id' => $workspace_user_id ], [ '%s' ], [ '%d' ] );
                $sync_warning = 'Workspace settings are not configured. Save was applied locally only.';
            } else {
                $sync_result = \metis_people_workspace_execute_job( [
                    'job_type' => $job_type,
                    'entity_type' => 'workspace_user',
                    'entity_id' => $workspace_user_id,
                    'payload_json' => \metis_json_encode( $sync_payload ),
                ], $cfg, false );
                if ( empty( $sync_result['ok'] ) ) {
                    $db->update( $users_table, [ 'sync_status' => 'failed' ], [ 'id' => $workspace_user_id ], [ '%s' ], [ '%d' ] );
                    $sync_warning = trim( (string) ( $sync_result['error'] ?? '' ) );
                    if ( $sync_warning === '' ) {
                        $sync_warning = 'Workspace user saved locally, but Workspace sync failed.';
                    }
                } elseif ( $job_type === 'workspace_user_create' && ! empty( $sync_result['temporary_password'] ) ) {
                    $welcome = \metis_people_workspace_send_welcome_email( $primary_email, $recovery_email, $display_name, (string) $sync_result['temporary_password'] );
                    if ( empty( $welcome['ok'] ) ) {
                        $sync_warning = trim( (string) ( $welcome['error'] ?? '' ) );
                        if ( $sync_warning === '' ) {
                            $sync_warning = 'Workspace user was created, but the welcome email could not be sent.';
                        }
                    }
                }
                if ( ! empty( $sync_result['ok'] ) && \metis_email_is_valid( $primary_email ) && ! empty( $affected_group_data['ids'] ) ) {
                    foreach ( $affected_group_data['ids'] as $gid ) {
                        $group_email = (string) ( $affected_group_data['emails'][ $gid ] ?? '' );
                        if ( ! \metis_email_is_valid( $group_email ) ) {
                            continue;
                        }
                        $include = in_array( (int) $gid, $group_ids, true );
                        $membership = \metis_people_workspace_set_group_membership( $group_email, $primary_email, $include, $cfg );
                        if ( empty( $membership['ok'] ) && $sync_warning === '' ) {
                            $sync_warning = trim( (string) ( $membership['error'] ?? 'Workspace group membership sync failed.' ) );
                        }
                    }
                }
            }
        } else {
            $job_id = \metis_people_workspace_queue_job( $job_type, 'workspace_user', $workspace_user_id, $actor_id, $sync_payload );
        }

        return [ $job_id, $sync_warning ];
    }

    private static function updateLinkedPerson( int $person_id, string $people_table, string $primary_email, string $first_name, string $last_name, string $display_name ): array {
        $db = \metis_db();
        $person_update = [
            'auth_provider' => 'workspace',
            'email' => $primary_email,
            'first_name' => $first_name !== '' ? $first_name : null,
            'last_name' => $last_name !== '' ? $last_name : null,
            'display_name' => $display_name,
            'is_workspace_user' => 1,
            'workspace_email' => $primary_email,
        ];
        $person_update_ok = $db->update(
            $people_table,
            $person_update,
            [ 'id' => $person_id ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );
        if ( $person_update_ok === false ) {
            \metis_runtime_send_json_error( 'Workspace user saved, but Metis profile update failed.', 500 );
        }

        $person_row = $db->fetchOne(
            "SELECT pid, display_name, first_name, last_name
             FROM {$people_table}
             WHERE id = %d
             LIMIT 1",
            [ $person_id ]
        );
        $linked_pid_out = '';
        $linked_name_out = '';
        $person_url_out = '';
        if ( $person_row ) {
            $linked_pid_out = (string) ( $person_row['pid'] ?? '' );
            $linked_name_out = trim( (string) ( $person_row['first_name'] ?? '' ) . ' ' . (string) ( $person_row['last_name'] ?? '' ) );
            if ( $linked_name_out === '' ) {
                $linked_name_out = trim( (string) ( $person_row['display_name'] ?? '' ) );
            }
            if ( $linked_pid_out !== '' && function_exists( 'metis_people_person_url' ) ) {
                $person_url_out = (string) \metis_people_person_url( $linked_pid_out );
            }
        }

        return [ $linked_pid_out, $linked_name_out, $person_url_out ];
    }

    private static function linkOrCreateMetisUser( int $workspace_user_id, string $users_table, string $people_table, string $primary_email, string $first_name, string $last_name, string $display_name ): array {
        $db = \metis_db();
        $existing_person = $db->fetchOne(
            "SELECT id, pid, display_name, first_name, last_name, is_workspace_user, workspace_email
             FROM {$people_table}
             WHERE workspace_email = %s OR email = %s
             ORDER BY id ASC
             LIMIT 1",
            [ $primary_email, $primary_email ]
        );
        $person_id = (int) ( $existing_person['id'] ?? 0 );
        $created_metis_user = false;
        if ( $person_id > 0 ) {
            $update_payload = [
                'auth_provider' => 'workspace',
                'is_workspace_user' => 1,
                'workspace_email' => $primary_email,
            ];
            $update_format = [ '%s', '%d', '%s' ];
            if ( trim( (string) ( $existing_person['display_name'] ?? '' ) ) === '' && $display_name !== '' ) {
                $update_payload['display_name'] = $display_name;
                $update_format[] = '%s';
            }
            if ( trim( (string) ( $existing_person['first_name'] ?? '' ) ) === '' && $first_name !== '' ) {
                $update_payload['first_name'] = $first_name;
                $update_format[] = '%s';
            }
            if ( trim( (string) ( $existing_person['last_name'] ?? '' ) ) === '' && $last_name !== '' ) {
                $update_payload['last_name'] = $last_name;
                $update_format[] = '%s';
            }
            $updated = $db->update( $people_table, $update_payload, [ 'id' => $person_id ], $update_format, [ '%d' ] );
            if ( $updated === false ) {
                \metis_runtime_send_json_error( 'Workspace user saved, but Metis user linking failed.', 500 );
            }
        } else {
            $person_payload = [
                'auth_provider' => 'workspace',
                'email' => $primary_email,
                'first_name' => $first_name !== '' ? $first_name : null,
                'last_name' => $last_name !== '' ? $last_name : null,
                'display_name' => $display_name,
                'is_workspace_user' => 1,
                'workspace_email' => $primary_email,
            ];
            $person_format = [ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ];
            if ( function_exists( 'metis_entity_id_service' ) ) {
                $person_payload = \metis_entity_id_service()->assignForInsert( 'person', $person_payload );
                $person_format[] = '%s';
            } else {
                $person_payload['pid'] = \metis_generate_code( 'PE', $people_table, 'pid' );
                $person_format[] = '%s';
            }
            $ok = $db->insert( $people_table, $person_payload, $person_format );
            if ( ! $ok ) {
                \metis_runtime_send_json_error( 'Workspace user saved, but Metis user creation failed.', 500 );
            }
            $person_id = (int) $db->lastInsertId();
            $created_metis_user = true;
            if ( $person_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'person', $person_id, (string) ( $person_payload['person_uid'] ?? $person_payload['pid'] ?? '' ) );
            }
        }

        $linked_pid_out = $person_id > 0 ? (string) $db->scalar( "SELECT pid FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] ) : '';
        if ( $person_id > 0 ) {
            $link_ok = $db->update(
                $users_table,
                [ 'person_id' => $person_id, 'sync_status' => 'synced' ],
                [ 'id' => $workspace_user_id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );
            if ( $link_ok === false ) {
                \metis_runtime_send_json_error( 'Metis user was created but linking failed.', 500 );
            }
            \metis_people_log_activity( $person_id, 'workspace_user_linked_to_person', 'Linked workspace user to Metis person', [
                'workspace_user_id' => $workspace_user_id,
                'primary_email' => $primary_email,
                'pid' => $linked_pid_out,
            ] );
        }

        return [
            'ok' => true,
            'created' => $created_metis_user,
            'person_id' => $person_id,
            'pid' => $linked_pid_out,
            'person_url' => $linked_pid_out !== '' ? (string) \metis_people_person_url( $linked_pid_out ) : '',
        ];
    }
}
