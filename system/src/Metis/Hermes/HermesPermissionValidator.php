<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\PermissionsService;

final class HermesPermissionValidator {
    public function __construct(
        private readonly PermissionsService $permissions
    ) {}

    public function validate( array $command, array $actor = [] ): array {
        $required = (string) ( $command['permission'] ?? '' );
        $requirements = $this->requirementsForCommand( $command );
        $actor        = $this->normalizeActor( $actor );
        $allowed      = true;

        foreach ( $requirements as $requirement ) {
            if ( ! $this->requirementIsAllowed( $requirement, $actor ) ) {
                $allowed = false;
                break;
            }
        }

        if ( $allowed ) {
            return [
                'status' => 'granted',
                'required_permission' => $required,
                'reason' => '',
            ];
        }

        \metis_audit_log_security( 'hermes_permission_denied', [
            'module'     => 'hermes',
            'severity'   => 'warning',
            'outcome'    => 'denied',
            'permission' => $required,
        ] );

        return [
            'status' => 'denied',
            'required_permission' => $required,
            'reason' => $required !== ''
                ? 'Missing permission ' . $required
                : 'Permission validation failed.',
        ];
    }

    private function requirementsForCommand( array $command ): array {
        $requirements = array_values( array_filter(
            (array) ( $command['permission_requirements'] ?? [] ),
            static fn ( mixed $requirement ): bool => is_array( $requirement )
        ) );

        if ( $requirements !== [] ) {
            return $requirements;
        }

        $check  = (array) ( $command['permission_check'] ?? [] );
        $module = \metis_key_clean( (string) ( $check['module'] ?? '' ) );
        $action = \metis_key_clean( (string) ( $check['action'] ?? 'view' ) );
        if ( $module === '' ) {
            return [];
        }

        return [
            [
                'type' => 'module_action',
                'module' => $module,
                'action' => $action,
                'permission_key' => (string) ( $command['permission'] ?? '' ),
            ],
        ];
    }

    private function normalizeActor( array $actor ): array {
        $roles = array_values( array_filter( array_map( 'strval', (array) ( $actor['roles'] ?? [] ) ) ) );
        if ( $roles !== [] ) {
            return [
                'roles' => $roles,
            ];
        }

        if ( function_exists( 'metis_current_user' ) ) {
            $user = \metis_runtime_current_user();
            if ( is_object( $user ) ) {
                return [
                    'roles' => array_values( array_filter( array_map( 'strval', (array) ( $user->roles ?? [] ) ) ) ),
                ];
            }
        }

        return [ 'roles' => [] ];
    }

    private function requirementIsAllowed( array $requirement, array $actor ): bool {
        $type = \metis_key_clean( (string) ( $requirement['type'] ?? 'module_action' ) );

        return match ( $type ) {
            'role_permission' => $this->rolePermissionAllowed( $requirement, $actor ),
            'module_action' => $this->moduleActionAllowed( $requirement, $actor ),
            default => false,
        };
    }

    private function rolePermissionAllowed( array $requirement, array $actor ): bool {
        if ( function_exists( 'metis_current_user_can' ) && \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        $allowedRoles = array_values( array_filter( array_map(
            'strval',
            (array) ( $requirement['allowed_roles'] ?? [] )
        ) ) );

        if ( $allowedRoles === [] ) {
            return true;
        }

        foreach ( (array) ( $actor['roles'] ?? [] ) as $role ) {
            if ( in_array( (string) $role, $allowedRoles, true ) ) {
                return true;
            }
        }

        return false;
    }

    private function moduleActionAllowed( array $requirement, array $actor ): bool {
        $module = \metis_key_clean( (string) ( $requirement['module'] ?? '' ) );
        $action = \metis_key_clean( (string) ( $requirement['action'] ?? 'view' ) );

        return $module === ''
            ? true
            : $this->permissions->can( $module, $action, $actor );
    }
}
