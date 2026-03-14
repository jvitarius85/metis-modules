<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\PermissionsService;

final class HermesPermissionValidator {
    public function __construct(
        private readonly PermissionsService $permissions
    ) {}

    public function validate( array $command ): array {
        $required = (string) ( $command['permission'] ?? '' );
        $check    = (array) ( $command['permission_check'] ?? [] );
        $module   = \sanitize_key( (string) ( $check['module'] ?? '' ) );
        $action   = \sanitize_key( (string) ( $check['action'] ?? 'view' ) );
        $allowed  = $required === '' ? true : $this->permissions->can( $module, $action );

        if ( $allowed ) {
            return [
                'status' => 'granted',
                'required_permission' => $required,
                'reason' => '',
            ];
        }

        return [
            'status' => 'denied',
            'required_permission' => $required,
            'reason' => $required !== ''
                ? 'Missing permission ' . $required
                : 'Permission validation failed.',
        ];
    }
}
