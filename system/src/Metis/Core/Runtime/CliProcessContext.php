<?php
declare(strict_types=1);

function metis_cli_process_context( string $operation, string $permission = 'system.tools.execute', array $audit = [] ): array {
    $operation = trim( $operation );
    if ( $operation === '' ) {
        $operation = 'cli_tool';
    }

    return [
        'security_context' => [
            'operation' => $operation,
            'source' => 'cli_tool',
        ],
        'audit_context' => [
            'event' => (string) ( $audit['event'] ?? 'cli_process_execution' ),
            'tool' => (string) ( $audit['tool'] ?? basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? 'cli' ) ) ),
        ],
        'permission_context' => [
            'permission' => $permission,
            'preauthorized' => true,
            'authorization_source' => 'metis_require_cli_tool',
            'enforce' => false,
        ],
    ];
}
