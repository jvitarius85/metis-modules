<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class AuditLogService {
    public function activity( string $action, array $context = [], array $args = [] ): void {
        if ( ! \function_exists( 'metis_audit_log_activity' ) ) {
            return;
        }

        \metis_audit_log_activity(
            $action,
            \array_merge(
                [
                    'module' => 'core',
                    'context' => $context,
                ],
                $args
            )
        );
    }

    public function security( string $action, array $context = [], string $severity = 'warning', string $outcome = 'blocked', array $args = [] ): void {
        if ( ! \function_exists( 'metis_audit_log_security' ) ) {
            return;
        }

        \metis_audit_log_security(
            $action,
            \array_merge(
                [
                    'module' => 'core',
                    'severity' => $severity,
                    'outcome' => $outcome,
                    'context' => $context,
                ],
                $args
            )
        );
    }
}
