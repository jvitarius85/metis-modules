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
        return \in_array( \strtolower( $raw ), [ '1', 'true', 'yes', 'on' ], true );
    }

    public function assertEnabled(): void {
        if ( ! $this->isEnabled() ) {
            throw new RuntimeException( 'Release manager execution is disabled.' );
        }
    }

    public function assertSystemAdministrator(): void {
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
