<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class PersonProfileService {
    private const PUBLIC_VISIBILITIES = [ 'private', 'staff', 'board', 'volunteer', 'all' ];

    public static function getById( int $person_id ): ?array {
        if ( $person_id < 1 ) {
            return null;
        }

        $people_table = \Metis_Tables::get( 'people' );
        $row = \metis_db()->fetchOne(
            "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1",
            [ $person_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function updateSelfProfile( int $person_id, array $payload ): ?array {
        $people_table = \Metis_Tables::get( 'people' );
        $public_profile = self::normalizePublicProfilePayload( $payload, $person_id );

        $ok = \metis_db()->update(
            $people_table,
            [
                'first_name' => $payload['first_name'] !== '' ? $payload['first_name'] : null,
                'last_name' => $payload['last_name'] !== '' ? $payload['last_name'] : null,
                'display_name' => $payload['display_name'],
                'public_slug' => $public_profile['public_slug'],
                'public_tagline' => $public_profile['public_tagline'],
                'public_bio_html' => $public_profile['public_bio_html'],
                'public_visibility' => $public_profile['public_visibility'],
                'public_updated_at' => \metis_current_time( 'mysql' ),
                'email_notifications' => ! empty( $payload['email_notifications'] ) ? 1 : 0,
                'requires_2fa' => ! empty( $payload['requires_2fa'] ) ? 1 : 0,
                'mfa_method' => (string) $payload['mfa_method'],
                'notification_prefs_json' => $payload['notification_prefs_json'] ?? null,
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $person_id ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to save profile.', 500 );
        }

        return self::getById( $person_id );
    }

    public static function updateAvatar( int $person_id, string $avatar_url ): void {
        $people_table = \Metis_Tables::get( 'people' );

        \metis_db()->update(
            $people_table,
            [ 'avatar_url' => $avatar_url ],
            [ 'id' => $person_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    public static function saveProfile( array $data, array $roles, array $role_windows, array $workspace_group_emails, ?int $actor_id ): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get( 'people' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );
        $workspace_groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $workspace_members_table = \Metis_Tables::get( 'people_workspace_group_members' );

        $person_id = (int) ( $data['person_id'] ?? 0 );
        $email = strtolower( trim( (string) ( $data['email'] ?? '' ) ) );
        $workspace_email = strtolower( trim( (string) ( $data['workspace_email'] ?? '' ) ) );
        $linked_donor_id = strtoupper( trim( (string) ( $data['linked_donor_id'] ?? '' ) ) );
        $manager_pid = strtoupper( trim( (string) ( $data['manager_pid'] ?? '' ) ) );
        $workspace_role = \metis_key_clean( (string) ( $data['workspace_role'] ?? '' ) );
        $stripe_role = \metis_key_clean( (string) ( $data['stripe_role'] ?? '' ) );
        $is_workspace_user = ! empty( $data['is_workspace_user'] ) ? 1 : 0;
        $workspace_is_protected = ! empty( $data['workspace_is_protected'] ) ? 1 : 0;
        $status = \metis_key_clean( (string) ( $data['status'] ?? 'active' ) );
        $lifecycle_status = \metis_key_clean( (string) ( $data['lifecycle_status'] ?? 'active' ) );
        $public_profile = self::normalizePublicProfilePayload( $data, $person_id );

        if ( $linked_donor_id !== '' ) {
            $donor_exists = (int) $db->scalar(
                "SELECT id FROM {$contacts_table} WHERE did = %s LIMIT 1",
                [ $linked_donor_id ]
            );
            if ( $donor_exists < 1 ) {
                \metis_runtime_send_json_error( 'Linked donor ID was not found in Contacts.', 400 );
            }
            $donor_conflict = (int) $db->scalar(
                "SELECT id FROM {$people_table} WHERE linked_donor_id = %s AND id <> %d LIMIT 1",
                [ $linked_donor_id, $person_id ]
            );
            if ( $donor_conflict > 0 ) {
                \metis_runtime_send_json_error( 'That donor is already linked to another person profile.', 400 );
            }
        }

        if ( $manager_pid !== '' ) {
            $manager_person_id = (int) $db->scalar(
                "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1",
                [ $manager_pid ]
            );
            if ( $manager_person_id < 1 ) {
                \metis_runtime_send_json_error( 'Manager PID was not found.', 400 );
            }
            if ( $person_id > 0 && $manager_person_id === $person_id ) {
                \metis_runtime_send_json_error( 'A person cannot be their own manager.', 400 );
            }
        }

        if ( $workspace_role !== '' ) {
            $valid_workspace_role = (int) $db->scalar(
                "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'workspace' LIMIT 1",
                [ $workspace_role ]
            );
            if ( $valid_workspace_role < 1 ) {
                \metis_runtime_send_json_error( 'Invalid Google Workspace role selected.', 400 );
            }
        }
        if ( $stripe_role !== '' ) {
            $valid_stripe_role = (int) $db->scalar(
                "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'stripe' LIMIT 1",
                [ $stripe_role ]
            );
            if ( $valid_stripe_role < 1 ) {
                \metis_runtime_send_json_error( 'Invalid Stripe role selected.', 400 );
            }
        }

        $email_conflict = (int) $db->scalar(
            "SELECT id FROM {$people_table} WHERE email = %s AND id <> %d LIMIT 1",
            [ $email, $person_id ]
        );
        if ( $email_conflict > 0 ) {
            \metis_runtime_send_json_error( 'Email already exists in People.', 400 );
        }
        if ( $person_id > 0 && $is_workspace_user === 0 ) {
            $protected_workspace_user_id = (int) $db->scalar(
                "SELECT id
                 FROM {$workspace_users_table}
                 WHERE person_id = %d
                   AND is_protected = 1
                 LIMIT 1",
                [ $person_id ]
            );
            if ( $protected_workspace_user_id > 0 ) {
                \metis_runtime_send_json_error( 'This profile is linked to a protected Workspace account and cannot be removed from Workspace.', 400 );
            }
        }

        $payload = [
            'auth_provider' => (string) ( $data['auth_provider'] ?? 'metis' ),
            'email' => $email,
            'first_name' => (string) ( $data['first_name'] ?? '' ) !== '' ? (string) $data['first_name'] : null,
            'last_name' => (string) ( $data['last_name'] ?? '' ) !== '' ? (string) $data['last_name'] : null,
            'display_name' => (string) ( $data['display_name'] ?? '' ),
            'public_slug' => $public_profile['public_slug'],
            'public_tagline' => $public_profile['public_tagline'],
            'public_bio_html' => $public_profile['public_bio_html'],
            'public_visibility' => $public_profile['public_visibility'],
            'public_sort_order' => isset( $data['public_sort_order'] ) ? (int) $data['public_sort_order'] : 0,
            'public_updated_at' => \metis_current_time( 'mysql' ),
            'linked_donor_id' => $linked_donor_id !== '' ? $linked_donor_id : null,
            'is_workspace_user' => $is_workspace_user,
            'workspace_email' => $workspace_email !== '' ? $workspace_email : null,
            'workspace_role' => $workspace_role !== '' ? $workspace_role : null,
            'stripe_role' => $stripe_role !== '' ? $stripe_role : null,
            'manager_pid' => $manager_pid !== '' ? $manager_pid : null,
            'department' => (string) ( $data['department'] ?? '' ) !== '' ? (string) $data['department'] : null,
            'date_joined' => self::normalizeOptionalDate( (string) ( $data['date_joined'] ?? '' ) ),
            'board_term_start' => (string) ( $data['board_term_start'] ?? '' ) !== '' ? (string) $data['board_term_start'] : null,
            'board_term_end' => (string) ( $data['board_term_end'] ?? '' ) !== '' ? (string) $data['board_term_end'] : null,
            'volunteer_area' => (string) ( $data['volunteer_area'] ?? '' ) !== '' ? (string) $data['volunteer_area'] : null,
            'lifecycle_status' => $lifecycle_status,
            'email_notifications' => ! empty( $data['email_notifications'] ) ? 1 : 0,
            'sms_notifications' => ! empty( $data['sms_notifications'] ) ? 1 : 0,
            'notification_prefs_json' => $data['notification_prefs_json'] ?? null,
            'requires_2fa' => ! empty( $data['requires_2fa'] ) ? 1 : 0,
            'mfa_method' => (string) ( $data['mfa_method'] ?? 'none' ),
            'is_staff' => ! empty( $data['is_staff'] ) ? 1 : 0,
            'is_board' => ! empty( $data['is_board'] ) ? 1 : 0,
            'board_position' => ! empty( $data['is_board'] ) && trim( (string) ( $data['board_position'] ?? '' ) ) !== '' ? trim( (string) $data['board_position'] ) : null,
            'staff_position' => ! empty( $data['is_staff'] ) && trim( (string) ( $data['staff_position'] ?? '' ) ) !== '' ? trim( (string) $data['staff_position'] ) : null,
            'is_volunteer' => ! empty( $data['is_volunteer'] ) ? 1 : 0,
            'volunteer_position' => ! empty( $data['is_volunteer'] ) && trim( (string) ( $data['volunteer_position'] ?? '' ) ) !== '' ? trim( (string) $data['volunteer_position'] ) : null,
            'status' => $status,
            'offboarded_at' => ( $status === 'inactive' || $lifecycle_status === 'alumni' ) ? \metis_current_time( 'mysql' ) : null,
        ];
        $format = [
            '%s', // auth_provider
            '%s', // email
            '%s', // first_name
            '%s', // last_name
            '%s', // display_name
            '%s', // public_slug
            '%s', // public_tagline
            '%s', // public_bio_html
            '%s', // public_visibility
            '%d', // public_sort_order
            '%s', // public_updated_at
            '%s', // linked_donor_id
            '%d', // is_workspace_user
            '%s', // workspace_email
            '%s', // workspace_role
            '%s', // stripe_role
            '%s', // manager_pid
            '%s', // department
            '%s', // date_joined
            '%s', // board_term_start
            '%s', // board_term_end
            '%s', // volunteer_area
            '%s', // lifecycle_status
            '%d', // email_notifications
            '%d', // sms_notifications
            '%s', // notification_prefs_json
            '%d', // requires_2fa
            '%s', // mfa_method
            '%d', // is_staff
            '%d', // is_board
            '%s', // board_position
            '%s', // staff_position
            '%d', // is_volunteer
            '%s', // volunteer_position
            '%s', // status
            '%s', // offboarded_at
        ];
        if ( count( $payload ) !== count( $format ) ) {
            \metis_runtime_send_json_error( 'Person save contract is out of sync.', 500 );
        }

        $previous_person = null;
        $previous_workspace_email = '';
        if ( $person_id > 0 ) {
            $previous_person = $db->fetchOne(
                "SELECT id, pid, is_workspace_user, workspace_email, stripe_role, status, lifecycle_status FROM {$people_table} WHERE id = %d LIMIT 1",
                [ $person_id ]
            );
            $previous_workspace_email = strtolower( trim( (string) ( $previous_person['workspace_email'] ?? '' ) ) );
            if ( ! \metis_email_is_valid( $previous_workspace_email ) ) {
                $previous_workspace_email = strtolower( trim( (string) $db->scalar(
                    "SELECT primary_email FROM {$workspace_users_table} WHERE person_id = %d ORDER BY id ASC LIMIT 1",
                    [ $person_id ]
                ) ) );
            }
            $ok = $db->update( $people_table, $payload, [ 'id' => $person_id ], $format, [ '%d' ] );
            if ( $ok === false ) {
                self::logSaveProfileError( 'person_update_failed', [
                    'person_id' => $person_id,
                    'pid' => (string) ( $previous_person['pid'] ?? '' ),
                    'db_error' => method_exists( $db, 'lastError' ) ? (string) $db->lastError() : '',
                ] );
                \metis_runtime_send_json_error( 'Failed to update person.', 500 );
            }
        } else {
            if ( function_exists( 'metis_entity_id_service' ) ) {
                $payload = \metis_entity_id_service()->assignForInsert( 'person', $payload );
                $format[] = '%s';
            } else {
                $payload['pid'] = \metis_generate_code( 'PE', $people_table, 'pid' );
            }
            $format[] = '%s';
            $ok = $db->insert( $people_table, $payload, $format );
            if ( ! $ok ) {
                self::logSaveProfileError( 'person_create_failed', [
                    'person_id' => $person_id,
                    'db_error' => method_exists( $db, 'lastError' ) ? (string) $db->lastError() : '',
                ] );
                \metis_runtime_send_json_error( 'Failed to create person.', 500 );
            }
            $person_id = (int) $db->lastInsertId();
            if ( $person_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'person', $person_id, (string) ( $payload['person_uid'] ?? $payload['pid'] ?? '' ) );
            }
        }

        $db->delete( $user_roles_table, [ 'person_id' => $person_id ], [ '%d' ] );
        foreach ( $roles as $role_key ) {
            $role_id = (int) $db->scalar( "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1", [ $role_key ] );
            if ( $role_id < 1 ) {
                continue;
            }
            $window = $role_windows[ $role_key ] ?? [ 'start_at' => '', 'end_at' => '' ];
            $db->insert(
                $user_roles_table,
                [
                    'person_id' => $person_id,
                    'role_id' => $role_id,
                    'start_at' => $window['start_at'] !== '' ? $window['start_at'] : null,
                    'end_at' => $window['end_at'] !== '' ? $window['end_at'] : null,
                ],
                [ '%d', '%d', '%s', '%s' ]
            );
        }

        $person_pid = (string) $db->scalar( "SELECT pid FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );
        $stripe_payload = [
            'person_id' => $person_id,
            'pid' => $person_pid,
            'workspace_email' => $workspace_email,
            'stripe_role' => $stripe_role,
        ];
        $can_stripe_provision = ( $is_workspace_user === 1 && $workspace_email !== '' && $status === 'active' && $lifecycle_status !== 'alumni' );
        $had_stripe_before = ! empty( $previous_person['stripe_role'] );
        try {
            if ( $can_stripe_provision && $stripe_role !== '' ) {
                \metis_people_workspace_queue_job( 'stripe_user_upsert', 'person', $person_id, $actor_id, $stripe_payload );
            } elseif ( $had_stripe_before || $stripe_role === '' || ! $can_stripe_provision ) {
                \metis_people_workspace_queue_job(
                    'stripe_user_disable',
                    'person',
                    $person_id,
                    $actor_id,
                    array_merge( $stripe_payload, [
                        'reason' => $status !== 'active' || $lifecycle_status === 'alumni' ? 'person_inactive' : 'role_or_workspace_removed',
                    ] )
                );
            }
        } catch ( \Throwable $exception ) {
            self::logSaveProfileError( 'stripe_queue_failed', [
                'person_id' => $person_id,
                'pid' => $person_pid,
                'message' => $exception->getMessage(),
            ] );
        }

        $linked_workspace_user_id = 0;
        if ( $is_workspace_user === 1 && $workspace_email !== '' ) {
            $linked_workspace_user_id = (int) $db->scalar(
                "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
                [ $person_id, $workspace_email ]
            );
            if ( $linked_workspace_user_id > 0 ) {
                $db->update(
                    $workspace_users_table,
                    [ 'person_id' => $person_id, 'is_protected' => $workspace_is_protected, 'sync_status' => 'queued' ],
                    [ 'id' => $linked_workspace_user_id ],
                    [ '%d', '%d', '%s' ],
                    [ '%d' ]
                );
                try {
                    \metis_people_workspace_queue_job(
                        'workspace_user_upsert',
                        'workspace_user',
                        $linked_workspace_user_id,
                        $actor_id,
                        [
                            'person_id' => $person_id,
                            'workspace_email' => $workspace_email,
                            'workspace_is_protected' => $workspace_is_protected,
                            'workspace_role' => $workspace_role,
                            'stripe_role' => $stripe_role,
                            'previous_primary_email' => $previous_workspace_email,
                            'add_alias_email' => ( $previous_workspace_email !== '' && $previous_workspace_email !== $workspace_email ) ? $previous_workspace_email : '',
                        ]
                    );
                } catch ( \Throwable $exception ) {
                    self::logSaveProfileError( 'workspace_user_queue_failed', [
                        'person_id' => $person_id,
                        'pid' => $person_pid,
                        'workspace_user_id' => $linked_workspace_user_id,
                        'message' => $exception->getMessage(),
                    ] );
                }
            }
        }

        if ( $linked_workspace_user_id > 0 ) {
            try {
                self::syncWorkspaceGroupAssignments( $workspace_group_emails, $linked_workspace_user_id, $workspace_groups_table, $workspace_members_table, $actor_id );
            } catch ( \Throwable $exception ) {
                self::logSaveProfileError( 'workspace_group_sync_failed', [
                    'person_id' => $person_id,
                    'pid' => $person_pid,
                    'workspace_user_id' => $linked_workspace_user_id,
                    'message' => $exception->getMessage(),
                ] );
            }
        }

        $drive_folder = null;
        if ( $is_workspace_user === 1 && $workspace_email !== '' ) {
            try {
                $drive_folder = \metis_people_autocreate_drive_folder_for_person( $person_id, $person_pid );
                if ( is_array( $drive_folder ) && empty( $drive_folder['ok'] ) ) {
                    self::logSaveProfileError( 'drive_folder_autocreate_failed', [
                        'person_id' => $person_id,
                        'pid' => $person_pid,
                        'error' => (string) ( $drive_folder['error'] ?? '' ),
                    ] );
                }
            } catch ( \Throwable $exception ) {
                self::logSaveProfileError( 'drive_folder_autocreate_exception', [
                    'person_id' => $person_id,
                    'pid' => $person_pid,
                    'message' => $exception->getMessage(),
                ] );
                $drive_folder = [
                    'ok' => false,
                    'created' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'person_id' => $person_id,
            'pid' => $person_pid,
            'status' => $status,
            'lifecycle_status' => $lifecycle_status,
            'drive_folder' => $drive_folder,
            'workspace_groups_count' => count( $workspace_group_emails ),
        ];
    }

    public static function offboardByPid( string $pid, ?int $actor_id ): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get( 'people' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );

        $person = $db->fetchOne( "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ] );
        if ( ! $person ) {
            \metis_runtime_send_json_error( 'Person not found.', 404 );
        }
        $person_id = (int) $person['id'];
        $person_before = $db->fetchOne(
            "SELECT id, pid, workspace_email, stripe_role FROM {$people_table} WHERE id = %d LIMIT 1",
            [ $person_id ]
        );
        $protected_workspace_user_id = (int) $db->scalar(
            "SELECT id
             FROM {$workspace_users_table}
             WHERE person_id = %d
               AND is_protected = 1
             LIMIT 1",
            [ $person_id ]
        );
        if ( $protected_workspace_user_id > 0 ) {
            \metis_runtime_send_json_error( 'This person has a protected Workspace account and cannot be offboarded from Metis.', 400 );
        }

        $db->update(
            $people_table,
            [
                'status' => 'inactive',
                'lifecycle_status' => 'alumni',
                'is_workspace_user' => 0,
                'workspace_email' => null,
                'workspace_role' => null,
                'stripe_role' => null,
                'offboarded_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $person_id ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        $db->delete( $user_roles_table, [ 'person_id' => $person_id ], [ '%d' ] );

        $workspace_email = strtolower( trim( (string) ( $person_before['workspace_email'] ?? '' ) ) );
        if ( \metis_email_is_valid( $workspace_email ) ) {
            $workspace_user_id = (int) $db->scalar(
                "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
                [ $person_id, $workspace_email ]
            );
            if ( $workspace_user_id > 0 ) {
                $db->update( $workspace_users_table, [ 'is_suspended' => 1, 'sync_status' => 'queued' ], [ 'id' => $workspace_user_id ], [ '%d', '%s' ], [ '%d' ] );
                \metis_people_workspace_queue_job(
                    'workspace_security_action',
                    'workspace_user',
                    $workspace_user_id,
                    $actor_id,
                    [ 'action_type' => 'suspend_account', 'reason' => 'person_offboarded' ]
                );
            }
        }

        \metis_people_workspace_queue_job(
            'stripe_user_disable',
            'person',
            $person_id,
            $actor_id,
            [
                'person_id' => $person_id,
                'pid' => (string) ( $person_before['pid'] ?? $pid ),
                'workspace_email' => $workspace_email,
                'stripe_role' => (string) ( $person_before['stripe_role'] ?? '' ),
                'reason' => 'person_offboarded',
            ]
        );

        return [
            'person_id' => $person_id,
            'pid' => (string) ( $person_before['pid'] ?? $pid ),
        ];
    }

    public static function expectedFolderName( int $person_id ): string {
        if ( $person_id < 1 ) {
            return '';
        }

        if ( function_exists( 'metis_drive_person_folder_display_name' ) ) {
            $folder_name = (string) \metis_drive_person_folder_display_name( $person_id, 0 );
            if ( $folder_name !== '' ) {
                return $folder_name;
            }
        }

        $people_table = \Metis_Tables::get( 'people' );
        if ( ! $people_table ) {
            return '';
        }

        $person_row = \metis_db()->fetchOne(
            "SELECT first_name, last_name, display_name, email
             FROM {$people_table}
             WHERE id = %d
             LIMIT 1",
            [ $person_id ]
        );
        if ( ! is_array( $person_row ) ) {
            return '';
        }

        $folder_name = trim( (string) ( $person_row['first_name'] ?? '' ) . ' ' . (string) ( $person_row['last_name'] ?? '' ) );
        if ( $folder_name === '' ) {
            $folder_name = trim( (string) ( $person_row['display_name'] ?? '' ) );
        }
        if ( $folder_name === '' ) {
            $folder_name = trim( (string) ( $person_row['email'] ?? '' ) );
        }

        return $folder_name;
    }

    private static function syncWorkspaceGroupAssignments( array $workspace_group_emails, int $linked_workspace_user_id, string $workspace_groups_table, string $workspace_members_table, ?int $actor_id ): void {
        $db = \metis_db();
        $available_group_ids_by_email = [];
        if ( ! empty( $workspace_group_emails ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $workspace_group_emails ), '%s' ) );
            $group_rows = $db->fetchAll(
                "SELECT id, group_email
                 FROM {$workspace_groups_table}
                 WHERE group_email IN ({$placeholders})",
                $workspace_group_emails
            ) ?: [];
            foreach ( $group_rows as $group_row ) {
                $group_id = (int) ( $group_row['id'] ?? 0 );
                $group_email = strtolower( trim( (string) ( $group_row['group_email'] ?? '' ) ) );
                if ( $group_id < 1 || ! \metis_email_is_valid( $group_email ) ) {
                    continue;
                }
                $available_group_ids_by_email[ $group_email ] = $group_id;
            }
        }

        $desired_group_ids = array_values( array_unique( array_map( 'intval', array_values( $available_group_ids_by_email ) ) ) );
        $existing_group_ids = $db->column(
            "SELECT group_id
             FROM {$workspace_members_table}
             WHERE workspace_user_id = %d",
            [ $linked_workspace_user_id ]
        );
        $existing_group_ids = array_values( array_unique( array_map( 'intval', $existing_group_ids ) ) );
        $to_add = array_values( array_diff( $desired_group_ids, $existing_group_ids ) );
        $to_remove = array_values( array_diff( $existing_group_ids, $desired_group_ids ) );
        $touched_group_ids = [];

        foreach ( $to_add as $group_id ) {
            if ( $group_id < 1 ) {
                continue;
            }
            $ok = $db->insert(
                $workspace_members_table,
                [
                    'group_id' => $group_id,
                    'workspace_user_id' => $linked_workspace_user_id,
                    'member_role' => 'member',
                ],
                [ '%d', '%d', '%s' ]
            );
            if ( $ok ) {
                $touched_group_ids[ $group_id ] = true;
            }
        }
        foreach ( $to_remove as $group_id ) {
            if ( $group_id < 1 ) {
                continue;
            }
            $deleted = $db->delete(
                $workspace_members_table,
                [ 'group_id' => $group_id, 'workspace_user_id' => $linked_workspace_user_id ],
                [ '%d', '%d' ]
            );
            if ( $deleted !== false ) {
                $touched_group_ids[ $group_id ] = true;
            }
        }

        foreach ( array_keys( $touched_group_ids ) as $group_id ) {
            $group_id = (int) $group_id;
            if ( $group_id < 1 ) {
                continue;
            }
            $db->execute(
                "UPDATE {$workspace_groups_table}
                 SET direct_members_count = (SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d),
                     sync_status = 'queued'
                 WHERE id = %d",
                [ $group_id, $group_id ]
            );
            $group_email = strtolower( trim( (string) $db->scalar(
                "SELECT group_email FROM {$workspace_groups_table} WHERE id = %d LIMIT 1",
                [ $group_id ]
            ) ) );
            \metis_people_workspace_queue_job(
                'workspace_group_members_bulk_sync',
                'workspace_group',
                $group_id,
                $actor_id,
                [
                    'group_email' => $group_email,
                    'member_count' => (int) $db->scalar(
                        "SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d",
                        [ $group_id ]
                    ),
                ]
            );
        }
    }

    public static function publicProfileUrl( array $person ): string {
        $slug = trim( (string) ( $person['public_slug'] ?? '' ) );
        if ( $slug === '' ) {
            return '';
        }

        return \metis_home_url( '/people/' . rawurlencode( $slug ) . '/' );
    }

    private static function normalizePublicProfilePayload( array $payload, int $person_id = 0 ): array {
        $public_slug = \metis_slug_clean( (string) ( $payload['public_slug'] ?? '' ) );
        if ( $public_slug === '' && $person_id > 0 ) {
            $existing_person = self::getById( $person_id );
            $public_slug = \metis_slug_clean( (string) ( $existing_person['public_slug'] ?? '' ) );
        }

        $first_name = trim( (string) ( $payload['first_name'] ?? '' ) );
        $last_name = trim( (string) ( $payload['last_name'] ?? '' ) );
        $display_name = trim( (string) ( $payload['display_name'] ?? '' ) );
        $name_slug_source = trim( $first_name . ' ' . $last_name );
        if ( $public_slug === '' && $name_slug_source !== '' ) {
            $public_slug = \metis_slug_clean( $name_slug_source );
        }
        if ( $public_slug === '' && $display_name !== '' ) {
            $public_slug = \metis_slug_clean( $display_name );
        }

        $public_visibility = \metis_key_clean( (string) ( $payload['public_visibility'] ?? 'private' ) );
        if ( ! in_array( $public_visibility, self::PUBLIC_VISIBILITIES, true ) ) {
            $public_visibility = 'private';
        }

        $public_tagline = trim( (string) ( $payload['public_tagline'] ?? '' ) );
        $public_tagline = $public_tagline !== '' ? mb_substr( $public_tagline, 0, 191 ) : null;

        $public_bio_html = self::sanitizeRichTextHtml( (string) ( $payload['public_bio_html'] ?? '' ) );
        if ( $public_bio_html === '' ) {
            $public_bio_html = null;
        }

        if ( $public_visibility === 'private' ) {
            $public_slug = $public_slug !== '' ? self::uniquePublicSlug( $public_slug, $person_id ) : null;
        } else {
            $public_slug = $public_slug !== '' ? self::uniquePublicSlug( $public_slug, $person_id ) : null;
        }

        return [
            'public_slug' => $public_slug,
            'public_tagline' => $public_tagline,
            'public_bio_html' => $public_bio_html,
            'public_visibility' => $public_visibility,
        ];
    }

    private static function uniquePublicSlug( string $candidate, int $person_id = 0 ): string {
        $candidate = \metis_slug_clean( $candidate );
        if ( $candidate === '' ) {
            return '';
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'people' );
        $slug = $candidate;
        $suffix = 2;

        while ( true ) {
            $existing_id = (int) $db->scalar(
                "SELECT id FROM {$table} WHERE public_slug = %s AND id <> %d LIMIT 1",
                [ $slug, $person_id ]
            );
            if ( $existing_id < 1 ) {
                return $slug;
            }
            $slug = $candidate . '-' . $suffix;
            $suffix++;
        }
    }

    private static function normalizeOptionalDate( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }

        $timestamp = strtotime( $value );
        if ( ! $timestamp ) {
            return null;
        }

        return gmdate( 'Y-m-d', $timestamp );
    }

    private static function logSaveProfileError( string $event, array $context = [] ): void {
        $message = '[people.saveProfile] ' . $event;
        $json = function_exists( 'metis_json_encode' ) ? \metis_json_encode( $context ) : json_encode( $context );
        if ( is_string( $json ) && $json !== '' ) {
            $message .= ' ' . $json;
        }
        error_log( $message );
    }

    private static function sanitizeRichTextHtml( string $html ): string {
        $html = trim( $html );
        if ( $html === '' ) {
            return '';
        }

        if ( class_exists( \Metis\Modules\Website\Services\WebsiteRenderer::class ) && method_exists( \Metis\Modules\Website\Services\WebsiteRenderer::class, 'sanitizePublicRichText' ) ) {
            return (string) \Metis\Modules\Website\Services\WebsiteRenderer::sanitizePublicRichText( $html );
        }

        return function_exists( 'metis_runtime_kses_post' )
            ? (string) \metis_runtime_kses_post( $html )
            : strip_tags( $html, '<p><br><strong><b><em><i><u><ul><ol><li><a><span><div><h2><h3><h4><blockquote>' );
    }
}
