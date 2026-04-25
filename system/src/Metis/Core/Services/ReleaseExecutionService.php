<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use RuntimeException;

final class ReleaseExecutionService {
    public function __construct(
        private readonly AuditLogService $audit = new AuditLogService()
    ) {}

    public function isEnabled(): bool {
        $raw = (string) ( \getenv( 'METIS_ENABLE_RELEASE_MANAGER' ) ?: '' );
        if ( $raw !== '' ) {
            return \in_array( \strtolower( $raw ), [ '1', 'true', 'yes', 'on' ], true );
        }

        try {
            if ( \class_exists( 'Core_Settings_Service' ) ) {
                return (bool) \Core_Settings_Service::get( 'release_manager_enabled', true );
            }
        } catch ( \Throwable ) {
        }

        return false;
    }

    public function assertEnabled(): void {
        if ( ! $this->isEnabled() ) {
            throw new RuntimeException( 'Release manager execution is disabled.' );
        }
    }

    public function assertSystemAdministrator( string $trigger = 'manual' ): void {
        if ( \in_array( $trigger, [ 'cli', 'settings_direct', 'settings_operations', 'system_cron' ], true ) ) {
            return;
        }

        if ( ! \function_exists( 'metis_current_user_can' ) ) {
            return;
        }

        if ( ! \function_exists( 'metis_current_user_can' ) || ! \metis_current_user_can( 'manage_options' ) ) {
            throw new RuntimeException( 'System administrator access is required.' );
        }
    }

    public function auditAction( string $action, array $context = [], string $outcome = 'attempted' ): void {
        $this->audit->activity( 'release_' . \metis_key_clean( $action ), $context + [ 'outcome' => $outcome ], [
            'module' => 'release',
        ] );
    }
}
