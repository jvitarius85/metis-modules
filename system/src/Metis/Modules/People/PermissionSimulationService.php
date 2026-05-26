<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class PermissionSimulationService {
    public static function simulateForPerson( string $pid, string $module, string $action ): array {
        $person_id = LifecycleTaskService::findPersonIdByPid( $pid );
        if ( $person_id < 1 ) {
            \metis_runtime_send_json_error( 'Person not found.', 404 );
        }

        $permission_key = $module . '.' . $action;
        $permission_id = self::permissionIdByKey( $permission_key );
        if ( $permission_id < 1 ) {
            \metis_runtime_send_json_error( 'Permission key not found.', 404 );
        }

        $roles_table = \Metis_Tables::get( 'people_roles' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $role_perms_table = \Metis_Tables::get( 'people_role_perms' );
        $now = \metis_current_time( 'mysql' );

        $source_roles = \metis_db()->fetchAll(
            "SELECT DISTINCT r.role_key, r.role_name, ur.start_at, ur.end_at
             FROM {$user_roles_table} ur
             INNER JOIN {$roles_table} r ON r.id = ur.role_id
             INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.permission_id = %d AND rp.allow_access = 1
             WHERE ur.person_id = %d
               AND (ur.start_at IS NULL OR ur.start_at <= %s)
               AND (ur.end_at IS NULL OR ur.end_at >= %s)
             ORDER BY r.role_name ASC",
            [ $permission_id, $person_id, $now, $now ]
        ) ?: [];

        return [
            'permission_key' => $permission_key,
            'allowed' => ! empty( $source_roles ),
            'source_roles' => $source_roles,
        ];
    }

    private static function permissionIdByKey( string $permission_key ): int {
        $perms_table = \Metis_Tables::get( 'people_permissions' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1",
            [ $permission_key ]
        );
    }
}
