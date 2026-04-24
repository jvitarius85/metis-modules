<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesToolRegistry {
    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array {
        $defs = [];

        foreach ( $this->requiredToolMatrix() as $tool_key => $spec ) {
            $defs[ $tool_key ] = $this->buildTool(
                $tool_key,
                (string) $spec['description'],
                (string) $spec['module'],
                (array) ( $spec['required_permissions'] ?? [] ),
                (array) ( $spec['input_schema'] ?? [ 'type' => 'object', 'properties' => [], 'required' => [] ] ),
                (array) ( $spec['output_schema'] ?? [ 'type' => 'object', 'required' => [ 'status' ] ] ),
                (string) $spec['enclave_action'],
                (string) $spec['risk_level'],
                (bool) $spec['requires_approval'],
                (bool) $spec['worker_supported'],
                (string) $spec['service'],
                (string) $spec['method']
            );
        }

        return $defs;
    }

    /**
     * @return array<string,mixed>
     */
    public function definition( string $tool_key ): array {
        return $this->definitions()[ $tool_key ] ?? [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function requiredToolMatrix(): array {
        $basicSubject = [
            'type' => 'object',
            'properties' => [
                'subject' => [ 'type' => 'string' ],
                'email' => [ 'type' => 'string' ],
                'query' => [ 'type' => 'string' ],
                'roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'job_code' => [ 'type' => 'string' ],
                'job_type' => [ 'type' => 'string' ],
                'module_key' => [ 'type' => 'string' ],
                'file_key' => [ 'type' => 'string' ],
                'nonce' => [ 'type' => 'string' ],
                'nonce_action' => [ 'type' => 'string' ],
            ],
            'required' => [],
        ];

        return [
            'hermes.user.create_user' => [ 'description' => 'Create a user.', 'module' => 'people', 'required_permissions' => [ 'people.create' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'createUser' ],
            'hermes.user.update_user' => [ 'description' => 'Update a user.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'updateUser' ],
            'hermes.user.disable_user' => [ 'description' => 'Disable a user.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'disableUser' ],
            'hermes.user.enable_user' => [ 'description' => 'Enable a user.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'enableUser' ],
            'hermes.user.assign_role' => [ 'description' => 'Assign a role.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'assignRole' ],
            'hermes.user.remove_role' => [ 'description' => 'Remove a role.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'removeRole' ],
            'hermes.user.list_users' => [ 'description' => 'List users.', 'module' => 'people', 'required_permissions' => [ 'people.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'listUsers' ],
            'hermes.user.get_user' => [ 'description' => 'Get a user.', 'module' => 'people', 'required_permissions' => [ 'people.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'getUser' ],

            'hermes.system.clear_cache' => [ 'description' => 'Clear cache.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.system.clear_cache', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'clearCache' ],
            'hermes.system.rebuild_indexes' => [ 'description' => 'Rebuild indexes.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'rebuildIndexes' ],
            'hermes.system.reload_config' => [ 'description' => 'Reload config.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'reloadConfig' ],
            'hermes.system.get_system_status' => [ 'description' => 'Get system status.', 'module' => 'settings', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'getSystemStatus' ],

            'hermes.diagnostics.run_full_diagnostics' => [ 'description' => 'Run full diagnostics.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'runFullDiagnostics' ],
            'hermes.diagnostics.scan_integrity' => [ 'description' => 'Scan integrity.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'scanIntegrity' ],
            'hermes.diagnostics.check_db' => [ 'description' => 'Check database health.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'checkDb' ],
            'hermes.diagnostics.check_workers' => [ 'description' => 'Check worker health.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'checkWorkers' ],

            'hermes.recovery.recover_module' => [ 'description' => 'Recover a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'recoverModule' ],
            'hermes.recovery.restore_file' => [ 'description' => 'Restore a file.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.system.restore', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'restoreFile' ],
            'hermes.recovery.rollback_module' => [ 'description' => 'Rollback a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'rollbackModule' ],

            'hermes.module.enable_module' => [ 'description' => 'Enable a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.module.enable', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'enableModule' ],
            'hermes.module.disable_module' => [ 'description' => 'Disable a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.module.disable', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'disableModule' ],
            'hermes.module.install_module' => [ 'description' => 'Install a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'installModule' ],
            'hermes.module.update_module' => [ 'description' => 'Update a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'updateModule' ],

            'hermes.data.export_data' => [ 'description' => 'Export data.', 'module' => 'reports', 'required_permissions' => [ 'reports.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'exportData' ],
            'hermes.data.import_data' => [ 'description' => 'Import data.', 'module' => 'import', 'required_permissions' => [ 'edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'importData' ],
            'hermes.data.deduplicate' => [ 'description' => 'Deduplicate records.', 'module' => 'contacts', 'required_permissions' => [ 'edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'deduplicate' ],

            'hermes.workers.create_job' => [ 'description' => 'Create a worker job.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'createJob' ],
            'hermes.workers.cancel_job' => [ 'description' => 'Cancel a worker job.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'cancelJob' ],
            'hermes.workers.retry_job' => [ 'description' => 'Retry a worker job.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'retryJob' ],
            'hermes.workers.list_jobs' => [ 'description' => 'List worker jobs.', 'module' => 'settings', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'listJobs' ],

            'hermes.security.audit_permissions' => [ 'description' => 'Audit permissions.', 'module' => 'people', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'auditPermissions' ],
            'hermes.security.verify_integrity' => [ 'description' => 'Verify integrity.', 'module' => 'settings', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'verifyIntegrity' ],
            'hermes.security.rotate_keys' => [ 'description' => 'Rotate keys.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'rotateKeys' ],

            'hermes.metis.validate_routes' => [ 'description' => 'Validate routes.', 'module' => 'core', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'validateRoutes' ],
            'hermes.metis.verify_nonce' => [ 'description' => 'Verify a nonce.', 'module' => 'core', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'verifyNonce' ],
            'hermes.metis.run_enclave_test' => [ 'description' => 'Run an enclave runtime test.', 'module' => 'core', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'runEnclaveTest' ],
        ];
    }

    /**
     * @param array<int,string> $required_permissions
     * @param array<string,mixed> $input_schema
     * @param array<string,mixed> $output_schema
     * @return array<string,mixed>
     */
    private function buildTool(
        string $tool_key,
        string $description,
        string $module,
        array $required_permissions,
        array $input_schema,
        array $output_schema,
        string $enclave_action,
        string $risk_level,
        bool $requires_approval,
        bool $worker_supported,
        string $service,
        string $method
    ): array {
        return [
            'tool_key' => $tool_key,
            'description' => $description,
            'module' => $module,
            'required_permissions' => $required_permissions,
            'input_schema' => $input_schema,
            'output_schema' => $output_schema,
            'enclave_action' => $enclave_action,
            'risk_level' => $risk_level,
            'requires_approval' => $requires_approval,
            'worker_supported' => $worker_supported,
            'dispatch' => [
                'service' => $service,
                'method' => $method,
                'pass_payload' => true,
                'pass_context' => false,
            ],
        ];
    }
}
