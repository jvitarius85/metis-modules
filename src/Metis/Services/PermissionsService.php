<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Application;
use Metis\Core\ModuleLoader;

final class PermissionsService {
    public function can( ?string $module, string $permission = 'view', array $actor = [] ): bool {
        if ( function_exists( 'metis_current_user_can' ) && \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        $module = \metis_key_clean( (string) $module );
        if ( $module === '' ) {
            return true;
        }

        if ( function_exists( 'metis_people_can' ) ) {
            return (bool) \metis_people_can( $module, $permission );
        }

        $roles   = array_map( 'strval', (array) ( $actor['roles'] ?? [] ) );
        $modules = Application::service( 'modules' )->all();
        $allowed = ModuleLoader::rolesForPermission( (array) ( $modules[ $module ]['config'] ?? [] ), $permission );

        foreach ( $roles as $role ) {
            if ( in_array( $role, $allowed, true ) ) {
                return true;
            }
        }

        return false;
    }
}
