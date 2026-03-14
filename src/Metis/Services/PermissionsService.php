<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Application;

final class PermissionsService {
    public function can( ?string $module, string $permission = 'view', array $actor = [] ): bool {
        if ( function_exists( 'current_user_can' ) && \current_user_can( 'manage_options' ) ) {
            return true;
        }

        $module = \sanitize_key( (string) $module );
        if ( $module === '' ) {
            return true;
        }

        if ( function_exists( 'metis_people_can' ) ) {
            return (bool) \metis_people_can( $module, $permission );
        }

        $roles   = array_map( 'strval', (array) ( $actor['roles'] ?? [] ) );
        $modules = Application::service( 'modules' )->all();
        $allowed = (array) ( $modules[ $module ]['config']['permissions'][ $permission ] ?? [] );

        foreach ( $roles as $role ) {
            if ( in_array( $role, $allowed, true ) ) {
                return true;
            }
        }

        return false;
    }
}
