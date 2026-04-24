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

    public function violation( string $violation_key, string $detail = '' ): void {
        \metis_audit_log_security( 'hermes_safety_violation', [
            'module'    => 'hermes',
            'severity'  => 'warning',
            'outcome'   => 'rejected',
            'violation' => $violation_key,
            'detail'    => $detail,
        ] );
    }

    public function queryExecuted( string $entity, string $report_type, int $row_count, array $context = [] ): void {
        \metis_audit_log_activity( 'hermes_query_executed', [
            'module'   => 'hermes',
            'resource' => [
                'type'  => 'entity',
                'id'    => $entity,
                'label' => $report_type,
            ],
            'context'  => array_merge( [ 'row_count' => $row_count ], $context ),
        ] );
    }

    public function permissionDenied( string $operation, string $permission, array $context = [] ): void {
        \metis_audit_log_security( 'hermes_permission_denied', [
            'module'    => 'hermes',
            'severity'  => 'warning',
            'outcome'   => 'denied',
            'operation' => $operation,
            'permission'=> $permission,
            'context'   => $context,
        ] );
    }

    public function enclaveExecution( string $operation, string $status, array $context = [] ): void {
        \metis_audit_log_activity( 'hermes_enclave_execution', [
            'module'   => 'hermes',
            'resource' => [
                'type'  => 'enclave_operation',
                'id'    => $operation,
                'label' => $status,
            ],
            'context'  => $context,
        ] );
    }

    public function commandTrace( array $entry ): void {
        $this->repository->logCommandTrace( $entry );
    }

    public function helpIssueResolution( array $entry ): void {
        $this->repository->logHelpIssueResolution( $entry );
    }
}
