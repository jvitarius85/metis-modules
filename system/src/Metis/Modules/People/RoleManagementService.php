<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class RoleManagementService {
    public static function saveRole( int $role_id, string $role_key, string $role_domain, string $role_name, string $description, array $permissions ): int {
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );

        if ( $role_id > 0 ) {
            $ok = \metis_db()->update(
                $roles_table,
                [
                    'role_key' => $role_key,
                    'role_domain' => $role_domain,
                    'role_name' => $role_name,
                    'description' => $description,
                ],
                [ 'id' => $role_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update role.', 500 );
            }
        } else {
            $ok = \metis_db()->insert(
                $roles_table,
                [
                    'role_key' => $role_key,
                    'role_domain' => $role_domain,
                    'role_name' => $role_name,
                    'description' => $description,
                    'is_system' => 0,
                ],
                [ '%s', '%s', '%s', '%s', '%d' ]
            );
            if ( ! $ok ) {
                \metis_runtime_send_json_error( 'Failed to create role.', 500 );
            }
            $role_id = (int) \metis_db()->lastInsertId();
        }

        \metis_db()->delete( $role_perms_table, [ 'role_id' => $role_id ], [ '%d' ] );

        foreach ( $permissions as $perm_key ) {
            $perm_id = self::permissionIdByKey( $perm_key );
            if ( $perm_id < 1 ) {
                continue;
            }

            \metis_db()->insert( $role_perms_table, [
                'role_id' => $role_id,
                'permission_id' => $perm_id,
                'allow_access' => 1,
            ], [ '%d', '%d', '%d' ] );
        }

        return $role_id;
    }

    public static function roleConflictId( string $role_key, string $role_domain, int $role_id ): int {
        $roles_table = \Metis_Tables::get( 'people_roles' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = %s AND id <> %d LIMIT 1",
            [ $role_key, $role_domain, $role_id ]
        );
    }

    public static function roleIdByKey( string $role_key, string $role_domain = 'metis' ): int {
        $roles_table = \Metis_Tables::get( 'people_roles' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = %s LIMIT 1",
            [ $role_key, $role_domain ]
        );
    }

    public static function personIdByPid( string $pid ): int {
        return LifecycleTaskService::findPersonIdByPid( $pid );
    }

    public static function hasUserRole( int $person_id, int $role_id ): bool {
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1",
            [ $person_id, $role_id ]
        ) > 0;
    }

    public static function assignUserRole( int $person_id, int $role_id ): bool {
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        return (bool) \metis_db()->insert(
            $user_roles_table,
            [ 'person_id' => $person_id, 'role_id' => $role_id ],
            [ '%d', '%d' ]
        );
    }

    public static function removeUserRole( int $person_id, int $role_id ): int {
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        return (int) \metis_db()->delete(
            $user_roles_table,
            [ 'person_id' => $person_id, 'role_id' => $role_id ],
            [ '%d', '%d' ]
        );
    }

    public static function getPersonSummaryByPid( string $pid ): ?array {
        $people_table = \Metis_Tables::get( 'people' );
        $row = \metis_db()->fetchOne(
            "SELECT id, pid, workspace_email, is_workspace_user, status, lifecycle_status FROM {$people_table} WHERE pid = %s LIMIT 1",
            [ $pid ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function updateStripeRole( int $person_id, ?string $stripe_role ): bool {
        $people_table = \Metis_Tables::get( 'people' );
        $ok = \metis_db()->update(
            $people_table,
            [ 'stripe_role' => $stripe_role, 'updated_at' => \metis_current_time( 'mysql' ) ],
            [ 'id' => $person_id ]
        );

        return $ok !== false;
    }

    public static function updateBulkProfilePosition( int $person_id, string $position_type, string $position_value ): bool {
        $people_table = \Metis_Tables::get( 'people' );
        $payload = [
            'is_board' => 0,
            'is_staff' => 0,
            'is_volunteer' => 0,
            'board_position' => null,
            'staff_position' => null,
            'volunteer_position' => null,
            'updated_at' => \metis_current_time( 'mysql' ),
        ];

        if ( $position_type === 'board' ) {
            $payload['is_board'] = 1;
            $payload['board_position'] = trim( $position_value );
        } elseif ( $position_type === 'staff' ) {
            $payload['is_staff'] = 1;
            $payload['staff_position'] = trim( $position_value );
        } elseif ( $position_type === 'volunteer' ) {
            $payload['is_volunteer'] = 1;
            $payload['volunteer_position'] = trim( $position_value );
        }

        $ok = \metis_db()->update(
            $people_table,
            $payload,
            [ 'id' => $person_id ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $ok !== false;
    }

    private static function permissionIdByKey( string $permission_key ): int {
        $perms_table = \Metis_Tables::get( 'people_permissions' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1",
            [ $permission_key ]
        );
    }
}
