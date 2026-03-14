<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesAuditLogger {
    public function __construct(
        private readonly HermesRepository $repository
    ) {}

    public function conversation( string $event, array $context = [] ): void {
        \metis_audit_log_activity( 'hermes_' . $event, [
            'module'  => 'hermes',
            'context' => $context,
        ] );
    }

    public function approval( string $event, string $action_code, array $context = [] ): void {
        \metis_audit_log_activity( 'hermes_' . $event, [
            'module'   => 'hermes',
            'resource' => [
                'type'  => 'hermes_action',
                'id'    => $action_code,
                'label' => $event,
            ],
            'context'  => $context,
        ] );
    }

    public function security( string $event, array $context = [] ): void {
        \metis_audit_log_security( 'hermes_' . $event, [
            'module'   => 'hermes',
            'severity' => 'warning',
            'outcome'  => 'blocked',
            'context'  => $context,
        ] );
    }
}
