<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class WorkspaceGroupService {
    public static function findTemplateGroupRow( string $template_key ): ?array {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $needle = $template_key === 'board' ? 'board' : 'suppl';
        $row = \metis_db()->fetchOne(
            "SELECT id, group_name, group_email, metadata_json
             FROM {$groups_table}
             WHERE LOWER(group_name) LIKE %s OR LOWER(group_email) LIKE %s
             ORDER BY id ASC
             LIMIT 1",
            [ '%' . $needle . '%', '%' . $needle . '%' ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function groupIdByEmail( string $group_email ): int {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$groups_table} WHERE group_email = %s LIMIT 1",
            [ $group_email ]
        );
    }

    public static function groupEmailConflictId( string $group_email, int $group_id ): int {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$groups_table} WHERE group_email = %s AND id <> %d LIMIT 1",
            [ $group_email, $group_id ]
        );
    }

    public static function personWorkspaceSummaryByPid( string $pid ): ?array {
        $people_table = \Metis_Tables::get( 'people' );
        $row = \metis_db()->fetchOne(
            "SELECT id, workspace_email FROM {$people_table} WHERE pid = %s LIMIT 1",
            [ $pid ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function workspaceUserIdForPerson( int $person_id, string $workspace_email ): int {
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            [ $person_id, $workspace_email ]
        );
    }

    public static function existingGroupMemberId( int $group_id, int $workspace_user_id ): int {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$members_table} WHERE group_id = %d AND workspace_user_id = %d LIMIT 1",
            [ $group_id, $workspace_user_id ]
        );
    }

    public static function updateMemberRoleById( int $member_id, string $member_role ): bool {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        $ok = \metis_db()->update(
            $members_table,
            [ 'member_role' => $member_role ],
            [ 'id' => $member_id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $ok !== false;
    }

    public static function insertGroupMember( int $group_id, int $workspace_user_id, string $member_role ): bool {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );

        return (bool) \metis_db()->insert(
            $members_table,
            [
                'group_id' => $group_id,
                'workspace_user_id' => $workspace_user_id,
                'member_role' => $member_role,
            ],
            [ '%d', '%d', '%s' ]
        );
    }

    public static function deleteGroupMember( int $group_id, int $workspace_user_id ): int {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );

        return (int) \metis_db()->delete(
            $members_table,
            [ 'group_id' => $group_id, 'workspace_user_id' => $workspace_user_id ],
            [ '%d', '%d' ]
        );
    }

    public static function refreshDirectMemberCountAndQueue( int $group_id ): void {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );

        \metis_db()->execute(
            "UPDATE {$groups_table}
             SET direct_members_count = (SELECT COUNT(*) FROM {$members_table} WHERE group_id = %d),
                 sync_status = 'queued'
             WHERE id = %d",
            [ $group_id, $group_id ]
        );
    }

    public static function saveGroup( int $group_id, string $group_email, string $group_name, string $description ): int {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $payload = [
            'group_email' => $group_email,
            'group_name' => $group_name,
            'description' => $description !== '' ? $description : null,
            'sync_status' => 'queued',
        ];

        if ( $group_id > 0 ) {
            $ok = \metis_db()->update( $groups_table, $payload, [ 'id' => $group_id ], [ '%s', '%s', '%s', '%s' ], [ '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update group.', 500 );
            }

            return $group_id;
        }

        $ok = \metis_db()->insert( $groups_table, $payload, [ '%s', '%s', '%s', '%s' ] );
        if ( ! $ok ) {
            \metis_runtime_send_json_error( 'Failed to create group.', 500 );
        }

        return (int) \metis_db()->lastInsertId();
    }

    public static function groupExists( int $group_id ): bool {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$groups_table} WHERE id = %d LIMIT 1",
            [ $group_id ]
        ) > 0;
    }

    public static function workspaceUserIdByPrimaryEmail( string $member_email ): int {
        $users_table = \Metis_Tables::get( 'people_workspace_users' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$users_table} WHERE primary_email = %s LIMIT 1",
            [ $member_email ]
        );
    }

    public static function groupSummary( int $group_id ): ?array {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $row = \metis_db()->fetchOne(
            "SELECT id, group_name, group_email, description
             FROM {$groups_table}
             WHERE id = %d
             LIMIT 1",
            [ $group_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function allWorkspaceUsers(): array {
        $users_table = \Metis_Tables::get( 'people_workspace_users' );

        return \metis_db()->fetchAll(
            "SELECT id, primary_email, first_name, last_name, display_name, metadata_json
             FROM {$users_table}
             ORDER BY display_name ASC, first_name ASC, last_name ASC, primary_email ASC"
        ) ?: [];
    }

    public static function memberRolesByUserId( int $group_id ): array {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        $rows = \metis_db()->fetchAll(
            "SELECT workspace_user_id, member_role
             FROM {$members_table}
             WHERE group_id = %d",
            [ $group_id ]
        ) ?: [];

        $roles_by_user_id = [];
        foreach ( $rows as $row ) {
            $workspace_user_id = (int) ( $row['workspace_user_id'] ?? 0 );
            $member_role = strtolower( trim( (string) ( $row['member_role'] ?? 'member' ) ) );
            if ( $workspace_user_id < 1 ) {
                continue;
            }
            if ( ! in_array( $member_role, [ 'member', 'manager', 'owner' ], true ) ) {
                $member_role = 'member';
            }
            $roles_by_user_id[ $workspace_user_id ] = $member_role;
        }

        return $roles_by_user_id;
    }

    public static function contactSummariesByEmails( array $emails ): array {
        if ( empty( $emails ) ) {
            return [ 'names' => [], 'cids' => [] ];
        }

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $in_placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
        $rows = \metis_db()->fetchAll(
            "SELECT email, first_name, last_name, cid
             FROM {$contacts_table}
             WHERE email IN ({$in_placeholders})",
            $emails
        ) ?: [];

        $names = [];
        $cids = [];
        foreach ( $rows as $row ) {
            $email_key = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
            if ( $email_key === '' ) {
                continue;
            }
            $contact_name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            if ( $contact_name !== '' ) {
                $names[ $email_key ] = $contact_name;
            }
            $contact_cid = trim( (string) ( $row['cid'] ?? '' ) );
            if ( $contact_cid !== '' ) {
                $cids[ $email_key ] = $contact_cid;
            }
        }

        return [ 'names' => $names, 'cids' => $cids ];
    }

    public static function groupIdentity( int $group_id ): ?array {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $row = \metis_db()->fetchOne(
            "SELECT id, group_email
             FROM {$groups_table}
             WHERE id = %d
             LIMIT 1",
            [ $group_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function workspaceUsersByIds( array $candidate_user_ids ): array {
        if ( empty( $candidate_user_ids ) ) {
            return [];
        }

        $users_table = \Metis_Tables::get( 'people_workspace_users' );
        $placeholders = implode( ',', array_fill( 0, count( $candidate_user_ids ), '%d' ) );

        return \metis_db()->fetchAll(
            "SELECT id, primary_email FROM {$users_table} WHERE id IN ({$placeholders})",
            $candidate_user_ids
        ) ?: [];
    }

    public static function replaceGroupMembers( int $group_id, array $to_insert ): int {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        \metis_db()->delete( $members_table, [ 'group_id' => $group_id ], [ '%d' ] );

        $inserted_count = 0;
        foreach ( $to_insert as $workspace_user_id => $member_role ) {
            $inserted = \metis_db()->insert(
                $members_table,
                [
                    'group_id' => $group_id,
                    'workspace_user_id' => (int) $workspace_user_id,
                    'member_role' => $member_role,
                ],
                [ '%d', '%d', '%s' ]
            );
            if ( $inserted ) {
                $inserted_count++;
            }
        }

        return $inserted_count;
    }

    public static function storeMemberCountAndSyncStatus( int $group_id, int $inserted_count, string $status ): void {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        \metis_db()->update(
            $groups_table,
            [ 'direct_members_count' => $inserted_count, 'sync_status' => $status ],
            [ 'id' => $group_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );
    }

    public static function groupWithMetadata( int $group_id ): ?array {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        $row = \metis_db()->fetchOne(
            "SELECT id, group_email, metadata_json FROM {$groups_table} WHERE id = %d LIMIT 1",
            [ $group_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function storeGroupMetadataPermissions( int $group_id, array $metadata, string $sync_status ): void {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );
        \metis_db()->update(
            $groups_table,
            [ 'metadata_json' => \metis_json_encode( $metadata ), 'sync_status' => $sync_status ],
            [ 'id' => $group_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function deleteGroupMembers( int $group_id ): void {
        $members_table = \Metis_Tables::get( 'people_workspace_group_members' );
        \metis_db()->delete( $members_table, [ 'group_id' => $group_id ], [ '%d' ] );
    }

    public static function deleteGroup( int $group_id ): bool {
        $groups_table = \Metis_Tables::get( 'people_workspace_groups' );

        return (bool) \metis_db()->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
    }
}
