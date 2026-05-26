<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class WorkspaceDirectoryService {
    public static function importDirectorySnapshot( array $cfg, int $limit = 500, bool $include_groups = false, int $groups_limit = 300 ): array {
        $db = \metis_db();
        $limit = max( 1, min( 2000, $limit ) );
        $groups_limit = max( 1, min( 1000, $groups_limit ) );

        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $user_roles_table = \Metis_Tables::get( 'people_workspace_user_roles' );
        $people_table = \Metis_Tables::get( 'people' );
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $group_members_table = \Metis_Tables::get( 'people_workspace_group_members' );

        $imported = 0;
        $created = 0;
        $updated = 0;
        $linked = 0;
        $imported_workspace_user_ids = [];
        $local_workspace_user_by_google_id = [];
        $customer = trim( (string) ( $cfg['customer_id'] ?? '' ) );
        if ( $customer === '' ) {
            $customer = 'my_customer';
        }
        $customer_query_value = rawurlencode( $customer );
        $page_token = '';
        $pages = 0;
        while ( $imported < $limit && $pages < 20 ) {
            $pages++;
            $remaining = $limit - $imported;
            $page_size = min( 100, $remaining );
            $query = 'users?customer=' . $customer_query_value . '&maxResults=' . $page_size . '&orderBy=email&projection=full';
            if ( $page_token !== '' ) {
                $query .= '&pageToken=' . rawurlencode( $page_token );
            }
            $resp = \metis_people_workspace_google_request( 'GET', $query, null, $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Failed to fetch users from Workspace.' ];
            }
            $users = (array) ( $resp['body']['users'] ?? [] );
            if ( empty( $users ) ) {
                break;
            }
            foreach ( $users as $google_user ) {
                if ( $imported >= $limit ) {
                    break;
                }
                $primary_email = strtolower( trim( (string) ( $google_user['primaryEmail'] ?? '' ) ) );
                if ( ! \metis_email_is_valid( $primary_email ) ) {
                    continue;
                }
                $google_id = (string) ( $google_user['id'] ?? '' );
                $first_name = (string) ( $google_user['name']['givenName'] ?? '' );
                $last_name = (string) ( $google_user['name']['familyName'] ?? '' );
                $display_name = (string) ( $google_user['name']['fullName'] ?? '' );
                if ( $display_name === '' ) {
                    $display_name = trim( $first_name . ' ' . $last_name );
                }
                if ( $display_name === '' ) {
                    $display_name = $primary_email;
                }
                $org_unit_path = (string) ( $google_user['orgUnitPath'] ?? '/' );
                if ( $org_unit_path === '' ) {
                    $org_unit_path = '/';
                }
                $recovery_email = strtolower( trim( (string) ( $google_user['recoveryEmail'] ?? '' ) ) );
                if ( ! \metis_email_is_valid( $recovery_email ) ) {
                    $recovery_email = '';
                }
                $is_suspended = ! empty( $google_user['suspended'] ) ? 1 : 0;

                $person_id = (int) $db->scalar(
                    "SELECT id FROM {$people_table}
                     WHERE workspace_email = %s OR email = %s
                     ORDER BY id ASC
                     LIMIT 1",
                    [ $primary_email, $primary_email ]
                );
                if ( $person_id > 0 ) {
                    $linked++;
                }

                $existing_id = (int) $db->scalar(
                    "SELECT id FROM {$users_table} WHERE primary_email = %s LIMIT 1",
                    [ $primary_email ]
                );
                $payload = [
                    'person_id' => $person_id > 0 ? $person_id : null,
                    'workspace_user_id' => $google_id !== '' ? $google_id : null,
                    'primary_email' => $primary_email,
                    'first_name' => $first_name !== '' ? $first_name : null,
                    'last_name' => $last_name !== '' ? $last_name : null,
                    'display_name' => $display_name,
                    'org_unit_path' => $org_unit_path,
                    'recovery_email' => $recovery_email !== '' ? $recovery_email : null,
                    'is_suspended' => $is_suspended,
                    'sync_status' => 'synced',
                ];
                $fmt = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ];
                if ( $existing_id > 0 ) {
                    $ok = $db->update( $users_table, $payload, [ 'id' => $existing_id ], $fmt, [ '%d' ] );
                    if ( $ok !== false ) {
                        $updated++;
                        $local_workspace_user_id = $existing_id;
                    } else {
                        $local_workspace_user_id = 0;
                    }
                } else {
                    $ok = $db->insert( $users_table, $payload, $fmt );
                    if ( $ok ) {
                        $created++;
                        $local_workspace_user_id = (int) $db->lastInsertId();
                    } else {
                        $local_workspace_user_id = 0;
                    }
                }
                if ( $local_workspace_user_id > 0 ) {
                    $imported_workspace_user_ids[] = $local_workspace_user_id;
                    if ( $google_id !== '' ) {
                        $local_workspace_user_by_google_id[ $google_id ] = $local_workspace_user_id;
                    }
                }
                $imported++;
            }
            $page_token = (string) ( $resp['body']['nextPageToken'] ?? '' );
            if ( $page_token === '' ) {
                break;
            }
        }

        $roles_synced = 0;
        $role_assignments_seen = 0;
        $role_sync_error = '';
        if ( ! empty( $local_workspace_user_by_google_id ) && ! empty( $imported_workspace_user_ids ) ) {
            $customer = $customer_query_value;

            $role_key_by_google_role_id = [];
            $roles_page_token = '';
            $roles_pages = 0;
            while ( $roles_pages < 20 ) {
                $roles_pages++;
                $roles_query = "customer/{$customer}/roles?maxResults=100";
                if ( $roles_page_token !== '' ) {
                    $roles_query .= '&pageToken=' . rawurlencode( $roles_page_token );
                }
                $roles_resp = \metis_people_workspace_google_request( 'GET', $roles_query, null, $cfg );
                if ( empty( $roles_resp['ok'] ) ) {
                    $role_sync_error = 'Failed to fetch Workspace roles.';
                    break;
                }
                $google_roles = (array) ( $roles_resp['body']['items'] ?? [] );
                foreach ( $google_roles as $google_role ) {
                    $google_role_id = trim( (string) ( $google_role['roleId'] ?? '' ) );
                    $google_role_name = trim( (string) ( $google_role['roleName'] ?? '' ) );
                    $google_role_description = trim( (string) ( $google_role['roleDescription'] ?? '' ) );
                    if ( $google_role_id === '' || $google_role_name === '' ) {
                        continue;
                    }
                    $resolved_role = \metis_people_workspace_resolve_role_meta( $google_role_name, $google_role_description );
                    $role_key = (string) ( $resolved_role['role_key'] ?? '' );
                    $role_label = (string) ( $resolved_role['role_label'] ?? $google_role_name );
                    if ( $role_key === '' ) {
                        continue;
                    }
                    $role_key_by_google_role_id[ $google_role_id ] = $role_key;

                    $existing_role_id = (int) $db->scalar(
                        "SELECT id FROM {$roles_table} WHERE role_domain = 'workspace' AND role_key = %s LIMIT 1",
                        [ $role_key ]
                    );
                    if ( $existing_role_id > 0 ) {
                        $db->update(
                            $roles_table,
                            [ 'role_name' => $role_label, 'description' => $google_role_description !== '' ? $google_role_description : null, 'is_system' => 1 ],
                            [ 'id' => $existing_role_id ],
                            [ '%s', '%s', '%d' ],
                            [ '%d' ]
                        );
                    } else {
                        $db->insert( $roles_table, [
                            'role_key' => $role_key,
                            'role_domain' => 'workspace',
                            'role_name' => $role_label,
                            'description' => $google_role_description !== '' ? $google_role_description : 'Imported from Google Workspace admin roles.',
                            'is_system' => 1,
                        ], [ '%s', '%s', '%s', '%s', '%d' ] );
                    }
                }
                $roles_page_token = trim( (string) ( $roles_resp['body']['nextPageToken'] ?? '' ) );
                if ( $roles_page_token === '' ) {
                    break;
                }
            }

            if ( $role_sync_error === '' ) {
                $assignments_by_workspace_user = [];
                $assign_page_token = '';
                $assign_pages = 0;
                while ( $assign_pages < 50 ) {
                    $assign_pages++;
                    $assign_query = "customer/{$customer}/roleassignments?maxResults=100";
                    if ( $assign_page_token !== '' ) {
                        $assign_query .= '&pageToken=' . rawurlencode( $assign_page_token );
                    }
                    $assign_resp = \metis_people_workspace_google_request( 'GET', $assign_query, null, $cfg );
                    if ( empty( $assign_resp['ok'] ) ) {
                        $role_sync_error = 'Failed to fetch Workspace role assignments.';
                        break;
                    }
                    $assignments = (array) ( $assign_resp['body']['items'] ?? [] );
                    foreach ( $assignments as $assignment ) {
                        $role_assignments_seen++;
                        $assigned_to = trim( (string) ( $assignment['assignedTo'] ?? '' ) );
                        $google_role_id = trim( (string) ( $assignment['roleId'] ?? '' ) );
                        if ( $assigned_to === '' || $google_role_id === '' ) {
                            continue;
                        }
                        if ( ! isset( $local_workspace_user_by_google_id[ $assigned_to ] ) ) {
                            continue;
                        }
                        if ( ! isset( $role_key_by_google_role_id[ $google_role_id ] ) ) {
                            continue;
                        }
                        $local_workspace_user_id = (int) $local_workspace_user_by_google_id[ $assigned_to ];
                        if ( $local_workspace_user_id < 1 ) {
                            continue;
                        }
                        $role_key = (string) $role_key_by_google_role_id[ $google_role_id ];
                        if ( ! isset( $assignments_by_workspace_user[ $local_workspace_user_id ] ) ) {
                            $assignments_by_workspace_user[ $local_workspace_user_id ] = [];
                        }
                        $assignments_by_workspace_user[ $local_workspace_user_id ][ $role_key ] = true;
                    }
                    $assign_page_token = trim( (string) ( $assign_resp['body']['nextPageToken'] ?? '' ) );
                    if ( $assign_page_token === '' ) {
                        break;
                    }
                }

                if ( $role_sync_error === '' ) {
                    $imported_workspace_user_ids = array_values( array_unique( array_map( 'intval', $imported_workspace_user_ids ) ) );
                    foreach ( $imported_workspace_user_ids as $local_workspace_user_id ) {
                        if ( $local_workspace_user_id < 1 ) {
                            continue;
                        }
                        $db->delete( $user_roles_table, [ 'workspace_user_id' => $local_workspace_user_id ], [ '%d' ] );
                        $role_keys = array_keys( (array) ( $assignments_by_workspace_user[ $local_workspace_user_id ] ?? [] ) );
                        foreach ( $role_keys as $role_key ) {
                            $inserted = $db->insert( $user_roles_table, [
                                'workspace_user_id' => $local_workspace_user_id,
                                'role_key' => $role_key,
                            ], [ '%d', '%s' ] );
                            if ( $inserted ) {
                                $roles_synced++;
                            }
                        }
                    }
                }
            }
        }

        $groups_imported = 0;
        $groups_created = 0;
        $groups_updated = 0;
        $groups_removed = 0;
        $group_members_synced = 0;
        $group_sync_error = '';
        $seen_workspace_group_emails = [];
        if ( $include_groups ) {
            $workspace_user_email_rows = $db->fetchAll(
                "SELECT id, primary_email FROM {$users_table} WHERE primary_email IS NOT NULL AND primary_email <> ''"
            ) ?: [];
            $workspace_user_id_by_email = [];
            foreach ( $workspace_user_email_rows as $row ) {
                $email_key = strtolower( trim( (string) ( $row['primary_email'] ?? '' ) ) );
                $wid = (int) ( $row['id'] ?? 0 );
                if ( $email_key === '' || $wid < 1 ) {
                    continue;
                }
                $workspace_user_id_by_email[ $email_key ] = $wid;
            }

            $group_page_token = '';
            $group_pages = 0;
            while ( $groups_imported < $groups_limit && $group_pages < 20 ) {
                $group_pages++;
                $remaining = $groups_limit - $groups_imported;
                $page_size = min( 100, $remaining );
                $group_query = 'groups?customer=' . $customer_query_value . '&maxResults=' . $page_size . '&orderBy=email';
                if ( $group_page_token !== '' ) {
                    $group_query .= '&pageToken=' . rawurlencode( $group_page_token );
                }
                $group_resp = \metis_people_workspace_google_request( 'GET', $group_query, null, $cfg );
                if ( empty( $group_resp['ok'] ) ) {
                    $group_sync_error = 'Failed to fetch groups from Workspace.';
                    break;
                }
                $groups = (array) ( $group_resp['body']['groups'] ?? [] );
                if ( empty( $groups ) ) {
                    break;
                }

                foreach ( $groups as $group_row ) {
                    if ( $groups_imported >= $groups_limit ) {
                        break;
                    }
                    $group_email = strtolower( trim( (string) ( $group_row['email'] ?? '' ) ) );
                    if ( ! \metis_email_is_valid( $group_email ) ) {
                        continue;
                    }
                    $seen_workspace_group_emails[ $group_email ] = true;
                    $group_name = trim( (string) ( $group_row['name'] ?? '' ) );
                    if ( $group_name === '' ) {
                        $group_name = $group_email;
                    }
                    $google_group_id = trim( (string) ( $group_row['id'] ?? '' ) );
                    $description = trim( (string) ( $group_row['description'] ?? '' ) );

                    $existing_group_id = (int) $db->scalar(
                        "SELECT id FROM {$groups_table} WHERE group_email = %s LIMIT 1",
                        [ $group_email ]
                    );
                    $group_payload = [
                        'workspace_group_id' => $google_group_id !== '' ? $google_group_id : null,
                        'group_email' => $group_email,
                        'group_name' => $group_name,
                        'description' => $description !== '' ? $description : null,
                        'source' => 'workspace',
                        'sync_status' => 'synced',
                    ];
                    if ( $existing_group_id > 0 ) {
                        $ok = $db->update(
                            $groups_table,
                            $group_payload,
                            [ 'id' => $existing_group_id ],
                            [ '%s', '%s', '%s', '%s', '%s', '%s' ],
                            [ '%d' ]
                        );
                        $local_group_id = $existing_group_id;
                        if ( $ok !== false ) {
                            $groups_updated++;
                        }
                    } else {
                        $ok = $db->insert(
                            $groups_table,
                            $group_payload,
                            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
                        );
                        $local_group_id = $ok ? (int) $db->lastInsertId() : 0;
                        if ( $ok ) {
                            $groups_created++;
                        }
                    }

                    if ( $local_group_id > 0 ) {
                        $member_ids = [];
                        $member_page_token = '';
                        $member_pages = 0;
                        while ( $member_pages < 20 ) {
                            $member_pages++;
                            $members_query = 'groups/' . rawurlencode( $group_email ) . '/members?maxResults=100';
                            if ( $member_page_token !== '' ) {
                                $members_query .= '&pageToken=' . rawurlencode( $member_page_token );
                            }
                            $members_resp = \metis_people_workspace_google_request( 'GET', $members_query, null, $cfg );
                            if ( empty( $members_resp['ok'] ) ) {
                                break;
                            }
                            $members = (array) ( $members_resp['body']['members'] ?? [] );
                            foreach ( $members as $member_row ) {
                                $member_email = strtolower( trim( (string) ( $member_row['email'] ?? '' ) ) );
                                $member_type = strtolower( trim( (string) ( $member_row['type'] ?? '' ) ) );
                                if ( $member_email === '' || $member_type === 'group' ) {
                                    continue;
                                }
                                $workspace_member_id = (int) ( $workspace_user_id_by_email[ $member_email ] ?? 0 );
                                if ( $workspace_member_id < 1 ) {
                                    continue;
                                }
                                $member_ids[ $workspace_member_id ] = strtolower( trim( (string) ( $member_row['role'] ?? 'member' ) ) );
                            }
                            $member_page_token = trim( (string) ( $members_resp['body']['nextPageToken'] ?? '' ) );
                            if ( $member_page_token === '' ) {
                                break;
                            }
                        }

                        $db->delete( $group_members_table, [ 'group_id' => $local_group_id ], [ '%d' ] );
                        $inserted_members = 0;
                        foreach ( $member_ids as $workspace_member_id => $member_role ) {
                            if ( ! in_array( $member_role, [ 'member', 'manager', 'owner' ], true ) ) {
                                $member_role = 'member';
                            }
                            $inserted = $db->insert( $group_members_table, [
                                'group_id' => $local_group_id,
                                'workspace_user_id' => (int) $workspace_member_id,
                                'member_role' => $member_role,
                            ], [ '%d', '%d', '%s' ] );
                            if ( $inserted ) {
                                $inserted_members++;
                            }
                        }
                        $group_members_synced += $inserted_members;
                        $db->update(
                            $groups_table,
                            [ 'direct_members_count' => $inserted_members, 'sync_status' => 'synced' ],
                            [ 'id' => $local_group_id ],
                            [ '%d', '%s' ],
                            [ '%d' ]
                        );
                    }
                    $groups_imported++;
                }

                $group_page_token = trim( (string) ( $group_resp['body']['nextPageToken'] ?? '' ) );
                if ( $group_page_token === '' ) {
                    break;
                }
            }

            if ( $group_sync_error === '' ) {
                $existing_workspace_groups = $db->fetchAll(
                    "SELECT id, group_email
                     FROM {$groups_table}
                     WHERE source = 'workspace'
                        OR (workspace_group_id IS NOT NULL AND workspace_group_id <> '')"
                ) ?: [];
                foreach ( $existing_workspace_groups as $existing_group ) {
                    $existing_group_id = (int) ( $existing_group['id'] ?? 0 );
                    $existing_group_email = strtolower( trim( (string) ( $existing_group['group_email'] ?? '' ) ) );
                    if ( $existing_group_id < 1 || $existing_group_email === '' ) {
                        continue;
                    }
                    if ( isset( $seen_workspace_group_emails[ $existing_group_email ] ) ) {
                        continue;
                    }
                    $db->delete( $group_members_table, [ 'group_id' => $existing_group_id ], [ '%d' ] );
                    $deleted_group = $db->delete( $groups_table, [ 'id' => $existing_group_id, 'source' => 'workspace' ], [ '%d', '%s' ] );
                    if ( $deleted_group ) {
                        $groups_removed++;
                    }
                }
            }
        }

        \metis_people_log_activity( null, 'workspace_directory_import', 'Imported existing Google Workspace users', [
            'imported' => $imported,
            'created' => $created,
            'updated' => $updated,
            'linked' => $linked,
            'roles_synced' => $roles_synced,
            'role_assignments_seen' => $role_assignments_seen,
            'role_sync_error' => $role_sync_error,
            'groups_imported' => $groups_imported,
            'groups_created' => $groups_created,
            'groups_updated' => $groups_updated,
            'groups_removed' => $groups_removed,
            'group_members_synced' => $group_members_synced,
            'group_sync_error' => $group_sync_error,
            'full_sync' => $include_groups ? 1 : 0,
        ] );

        return [
            'ok' => true,
            'imported' => $imported,
            'created' => $created,
            'updated' => $updated,
            'linked' => $linked,
            'roles_synced' => $roles_synced,
            'role_assignments_seen' => $role_assignments_seen,
            'role_sync_error' => $role_sync_error,
            'groups_imported' => $groups_imported,
            'groups_created' => $groups_created,
            'groups_updated' => $groups_updated,
            'groups_removed' => $groups_removed,
            'group_members_synced' => $group_members_synced,
            'group_sync_error' => $group_sync_error,
            'full_sync' => $include_groups ? 1 : 0,
        ];
    }

    public static function roleMap( array $cfg ): array {
        $db = \metis_db();
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );
        $workspace_user_roles_table = \Metis_Tables::get( 'people_workspace_user_roles' );

        $customer = trim( (string) ( $cfg['customer_id'] ?? '' ) );
        if ( $customer === '' ) {
            $customer = 'my_customer';
        }

        $roles = [];
        $roles_by_google_role_id = [];
        $page_token = '';
        $page_guard = 0;
        while ( $page_guard < 20 ) {
            $page_guard++;
            $query = "customer/{$customer}/roles?maxResults=100";
            if ( $page_token !== '' ) {
                $query .= '&pageToken=' . rawurlencode( $page_token );
            }
            $resp = \metis_people_workspace_google_request( 'GET', $query, null, $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Failed to fetch workspace roles.' ];
            }
            $items = (array) ( $resp['body']['items'] ?? [] );
            foreach ( $items as $role_row ) {
                $google_role_id = trim( (string) ( $role_row['roleId'] ?? '' ) );
                if ( $google_role_id !== '' ) {
                    $roles_by_google_role_id[ $google_role_id ] = $role_row;
                }
                $roles[] = $role_row;
            }
            $page_token = trim( (string) ( $resp['body']['nextPageToken'] ?? '' ) );
            if ( $page_token === '' ) {
                break;
            }
        }

        $assignments_by_role_id = [];
        $assignments_by_local_user = [];
        $user_rows = $db->fetchAll(
            "SELECT id, workspace_user_id FROM {$workspace_users_table} WHERE workspace_user_id IS NOT NULL AND workspace_user_id <> ''"
        ) ?: [];
        $local_workspace_user_by_google_id = [];
        foreach ( $user_rows as $user_row ) {
            $local_id = (int) ( $user_row['id'] ?? 0 );
            $google_id = trim( (string) ( $user_row['workspace_user_id'] ?? '' ) );
            if ( $local_id < 1 || $google_id === '' ) {
                continue;
            }
            $local_workspace_user_by_google_id[ $google_id ] = $local_id;
        }

        $assign_page_token = '';
        $assign_guard = 0;
        while ( $assign_guard < 50 ) {
            $assign_guard++;
            $assign_query = "customer/{$customer}/roleassignments?maxResults=100";
            if ( $assign_page_token !== '' ) {
                $assign_query .= '&pageToken=' . rawurlencode( $assign_page_token );
            }
            $assign_resp = \metis_people_workspace_google_request( 'GET', $assign_query, null, $cfg );
            if ( empty( $assign_resp['ok'] ) ) {
                break;
            }
            $assign_items = (array) ( $assign_resp['body']['items'] ?? [] );
            foreach ( $assign_items as $assign_row ) {
                $google_role_id = trim( (string) ( $assign_row['roleId'] ?? '' ) );
                if ( $google_role_id === '' ) {
                    continue;
                }
                if ( ! isset( $assignments_by_role_id[ $google_role_id ] ) ) {
                    $assignments_by_role_id[ $google_role_id ] = 0;
                }
                $assignments_by_role_id[ $google_role_id ]++;

                $assigned_to = trim( (string) ( $assign_row['assignedTo'] ?? '' ) );
                $local_user_id = (int) ( $local_workspace_user_by_google_id[ $assigned_to ] ?? 0 );
                if ( $local_user_id < 1 ) {
                    continue;
                }

                $role_row = (array) ( $roles_by_google_role_id[ $google_role_id ] ?? [] );
                $google_role_name = trim( (string) ( $role_row['roleName'] ?? '' ) );
                $google_role_desc = trim( (string) ( $role_row['roleDescription'] ?? '' ) );
                $resolved = \metis_people_workspace_resolve_role_meta( $google_role_name, $google_role_desc );
                $role_key = (string) ( $resolved['role_key'] ?? '' );
                if ( $role_key === '' ) {
                    continue;
                }
                if ( ! isset( $assignments_by_local_user[ $local_user_id ] ) ) {
                    $assignments_by_local_user[ $local_user_id ] = [];
                }
                $assignments_by_local_user[ $local_user_id ][ $role_key ] = true;
            }
            $assign_page_token = trim( (string) ( $assign_resp['body']['nextPageToken'] ?? '' ) );
            if ( $assign_page_token === '' ) {
                break;
            }
        }

        $out = [];
        foreach ( $roles as $role_row ) {
            $google_role_id = trim( (string) ( $role_row['roleId'] ?? '' ) );
            $google_role_name = trim( (string) ( $role_row['roleName'] ?? '' ) );
            $google_role_description = trim( (string) ( $role_row['roleDescription'] ?? '' ) );
            if ( $google_role_id === '' || $google_role_name === '' ) {
                continue;
            }
            $resolved = \metis_people_workspace_resolve_role_meta( $google_role_name, $google_role_description );
            $metis_role_key = (string) ( $resolved['role_key'] ?? '' );
            $friendly_name = (string) ( $resolved['role_label'] ?? $google_role_name );
            if ( $metis_role_key === '' ) {
                continue;
            }

            $existing_role_id = (int) $db->scalar(
                "SELECT id FROM {$roles_table} WHERE role_domain = 'workspace' AND role_key = %s LIMIT 1",
                [ $metis_role_key ]
            );
            if ( $existing_role_id > 0 ) {
                $db->update(
                    $roles_table,
                    [ 'role_name' => $friendly_name, 'description' => $google_role_description !== '' ? $google_role_description : null, 'is_system' => 1 ],
                    [ 'id' => $existing_role_id ],
                    [ '%s', '%s', '%d' ],
                    [ '%d' ]
                );
            } else {
                $db->insert( $roles_table, [
                    'role_key' => $metis_role_key,
                    'role_domain' => 'workspace',
                    'role_name' => $friendly_name,
                    'description' => $google_role_description !== '' ? $google_role_description : 'Imported from Google Workspace admin roles.',
                    'is_system' => 1,
                ], [ '%s', '%s', '%s', '%s', '%d' ] );
            }

            $out[] = [
                'friendly_name' => $friendly_name,
                'google_role_name' => $google_role_name,
                'google_role_id' => $google_role_id,
                'metis_role_key' => $metis_role_key,
                'description' => $google_role_description,
                'assigned_count' => (int) ( $assignments_by_role_id[ $google_role_id ] ?? 0 ),
            ];
        }

        usort( $out, static function ( $a, $b ) {
            return strcasecmp( (string) ( $a['friendly_name'] ?? '' ), (string) ( $b['friendly_name'] ?? '' ) );
        } );

        foreach ( $local_workspace_user_by_google_id as $local_user_id ) {
            $local_user_id = (int) $local_user_id;
            if ( $local_user_id < 1 ) {
                continue;
            }
            $db->delete( $workspace_user_roles_table, [ 'workspace_user_id' => $local_user_id ], [ '%d' ] );
            $role_keys = array_keys( (array) ( $assignments_by_local_user[ $local_user_id ] ?? [] ) );
            foreach ( $role_keys as $role_key ) {
                if ( $role_key === '' ) {
                    continue;
                }
                $db->insert( $workspace_user_roles_table, [
                    'workspace_user_id' => $local_user_id,
                    'role_key' => $role_key,
                ], [ '%d', '%s' ] );
            }
        }

        return [
            'ok' => true,
            'roles' => $out,
            'total_roles' => count( $out ),
        ];
    }
}
