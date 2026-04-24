<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesCommandRegistry {
    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array {
        $defs = [];

        foreach ( $this->requiredCommands() as $name => $spec ) {
            $defs[ $name ] = [
                'key' => $name,
                'title' => ucwords( str_replace( '_', ' ', $name ) ),
                'description' => (string) $spec['description'],
                'domain' => (string) $spec['domain'],
                'module' => (string) $spec['module'],
                'tool_key' => (string) $spec['tool_key'],
                'permission' => (string) $spec['permission'],
                'phrases' => (array) ( $spec['phrases'] ?? [] ),
                'keywords' => (array) ( $spec['keywords'] ?? [] ),
                'expects_entity' => (bool) ( $spec['expects_entity'] ?? false ),
                'supports_context' => (bool) ( $spec['supports_context'] ?? false ),
                'requires_approval' => (bool) ( $spec['requires_approval'] ?? false ),
                'read_only' => ! empty( $spec['read_only'] ),
                'worker_supported' => ! empty( $spec['worker_supported'] ),
                'input_schema' => (array) ( $spec['input_schema'] ?? [ 'type' => 'object', 'properties' => [], 'required' => [] ] ),
                'output_schema' => [ 'type' => 'object', 'required' => [ 'status' ] ],
            ];
        }

        return $defs;
    }

    /**
     * @return array<string,mixed>
     */
    public function definition( string $key ): array {
        $definitions = $this->definitions();
        return $definitions[ trim( strtolower( $key ) ) ] ?? [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function requiredCommands(): array {
        $basicSchema = [
            'type' => 'object',
            'properties' => [
                'subject' => [ 'type' => 'string' ],
                'email' => [ 'type' => 'string' ],
                'roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'query' => [ 'type' => 'string' ],
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
            'create_user' => [ 'description' => 'Create a user.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.create_user', 'permission' => 'people.create', 'phrases' => [ 'create user', 'add user', 'new user' ], 'keywords' => [ 'user', 'create', 'add' ], 'expects_entity' => true, 'supports_context' => false, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'update_user' => [ 'description' => 'Update a user.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.update_user', 'permission' => 'people.edit', 'phrases' => [ 'update user', 'edit user', 'change user' ], 'keywords' => [ 'user', 'update', 'edit' ], 'expects_entity' => true, 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'disable_user' => [ 'description' => 'Disable a user.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.disable_user', 'permission' => 'people.edit', 'phrases' => [ 'disable user', 'deactivate user', 'offboard user' ], 'keywords' => [ 'disable', 'deactivate', 'offboard' ], 'expects_entity' => true, 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'enable_user' => [ 'description' => 'Enable a user.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.enable_user', 'permission' => 'people.edit', 'phrases' => [ 'enable user', 'reactivate user' ], 'keywords' => [ 'enable', 'reactivate' ], 'expects_entity' => true, 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'assign_role' => [ 'description' => 'Assign role.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.assign_role', 'permission' => 'people.edit', 'phrases' => [ 'assign role', 'add role', 'grant role' ], 'keywords' => [ 'role', 'assign', 'grant' ], 'expects_entity' => true, 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'remove_role' => [ 'description' => 'Remove role.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.remove_role', 'permission' => 'people.edit', 'phrases' => [ 'remove role', 'revoke role' ], 'keywords' => [ 'role', 'remove', 'revoke' ], 'expects_entity' => true, 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'list_users' => [ 'description' => 'List users.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.list_users', 'permission' => 'people.view', 'phrases' => [ 'list users', 'show users', 'get users' ], 'keywords' => [ 'list', 'users', 'show' ], 'expects_entity' => false, 'supports_context' => false, 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],
            'get_user' => [ 'description' => 'Get user.', 'domain' => 'people', 'module' => 'people', 'tool_key' => 'hermes.user.get_user', 'permission' => 'people.view', 'phrases' => [ 'get user', 'show user', 'find user' ], 'keywords' => [ 'user', 'show', 'find' ], 'expects_entity' => true, 'supports_context' => true, 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],

            'clear_cache' => [ 'description' => 'Clear cache.', 'domain' => 'system', 'module' => 'settings', 'tool_key' => 'hermes.system.clear_cache', 'permission' => 'system.backup.execute', 'phrases' => [ 'clear cache', 'flush cache' ], 'keywords' => [ 'cache', 'clear', 'flush' ], 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'rebuild_indexes' => [ 'description' => 'Rebuild indexes.', 'domain' => 'system', 'module' => 'settings', 'tool_key' => 'hermes.system.rebuild_indexes', 'permission' => 'system.backup.execute', 'phrases' => [ 'rebuild indexes', 'reindex' ], 'keywords' => [ 'indexes', 'rebuild', 'reindex' ], 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'reload_config' => [ 'description' => 'Reload config.', 'domain' => 'system', 'module' => 'settings', 'tool_key' => 'hermes.system.reload_config', 'permission' => 'system.backup.execute', 'phrases' => [ 'reload config', 'reload configuration' ], 'keywords' => [ 'reload', 'config' ], 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'get_system_status' => [ 'description' => 'Get system status.', 'domain' => 'system', 'module' => 'settings', 'tool_key' => 'hermes.system.get_system_status', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'system status', 'status report' ], 'keywords' => [ 'system', 'status' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],

            'run_full_diagnostics' => [ 'description' => 'Run diagnostics.', 'domain' => 'diagnostics', 'module' => 'hermes', 'tool_key' => 'hermes.diagnostics.run_full_diagnostics', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'run diagnostics', 'full diagnostics' ], 'keywords' => [ 'diagnostics', 'run', 'full' ], 'requires_approval' => false, 'read_only' => true, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'scan_integrity' => [ 'description' => 'Scan integrity.', 'domain' => 'diagnostics', 'module' => 'hermes', 'tool_key' => 'hermes.diagnostics.scan_integrity', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'scan integrity', 'integrity scan' ], 'keywords' => [ 'integrity', 'scan' ], 'requires_approval' => false, 'read_only' => true, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'check_db' => [ 'description' => 'Check DB.', 'domain' => 'diagnostics', 'module' => 'hermes', 'tool_key' => 'hermes.diagnostics.check_db', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'check db', 'check database', 'database health' ], 'keywords' => [ 'db', 'database', 'health' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],
            'check_workers' => [ 'description' => 'Check workers.', 'domain' => 'diagnostics', 'module' => 'hermes', 'tool_key' => 'hermes.diagnostics.check_workers', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'check workers', 'worker health', 'check jobs' ], 'keywords' => [ 'worker', 'jobs', 'queue' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],

            'recover_module' => [ 'description' => 'Recover module.', 'domain' => 'recovery', 'module' => 'settings', 'tool_key' => 'hermes.recovery.recover_module', 'permission' => 'system.backup.execute', 'phrases' => [ 'recover module' ], 'keywords' => [ 'recover', 'module' ], 'expects_entity' => false, 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'restore_file' => [ 'description' => 'Restore file.', 'domain' => 'recovery', 'module' => 'settings', 'tool_key' => 'hermes.recovery.restore_file', 'permission' => 'system.backup.execute', 'phrases' => [ 'restore file' ], 'keywords' => [ 'restore', 'file' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'rollback_module' => [ 'description' => 'Rollback module.', 'domain' => 'recovery', 'module' => 'settings', 'tool_key' => 'hermes.recovery.rollback_module', 'permission' => 'system.backup.execute', 'phrases' => [ 'rollback module' ], 'keywords' => [ 'rollback', 'module' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],

            'enable_module' => [ 'description' => 'Enable module.', 'domain' => 'module', 'module' => 'settings', 'tool_key' => 'hermes.module.enable_module', 'permission' => 'system.backup.execute', 'phrases' => [ 'enable module' ], 'keywords' => [ 'enable', 'module' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'disable_module' => [ 'description' => 'Disable module.', 'domain' => 'module', 'module' => 'settings', 'tool_key' => 'hermes.module.disable_module', 'permission' => 'system.backup.execute', 'phrases' => [ 'disable module' ], 'keywords' => [ 'disable', 'module' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'install_module' => [ 'description' => 'Install module.', 'domain' => 'module', 'module' => 'settings', 'tool_key' => 'hermes.module.install_module', 'permission' => 'system.backup.execute', 'phrases' => [ 'install module' ], 'keywords' => [ 'install', 'module' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'update_module' => [ 'description' => 'Update module.', 'domain' => 'module', 'module' => 'settings', 'tool_key' => 'hermes.module.update_module', 'permission' => 'system.backup.execute', 'phrases' => [ 'update module' ], 'keywords' => [ 'update', 'module' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],

            'export_data' => [ 'description' => 'Export data.', 'domain' => 'data', 'module' => 'reports', 'tool_key' => 'hermes.data.export_data', 'permission' => 'reports.view', 'phrases' => [ 'export data' ], 'keywords' => [ 'export', 'data' ], 'requires_approval' => false, 'read_only' => true, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'import_data' => [ 'description' => 'Import data.', 'domain' => 'data', 'module' => 'import', 'tool_key' => 'hermes.data.import_data', 'permission' => 'edit', 'phrases' => [ 'import data' ], 'keywords' => [ 'import', 'data' ], 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'deduplicate' => [ 'description' => 'Deduplicate data.', 'domain' => 'data', 'module' => 'contacts', 'tool_key' => 'hermes.data.deduplicate', 'permission' => 'edit', 'phrases' => [ 'deduplicate', 'dedupe' ], 'keywords' => [ 'deduplicate', 'dedupe', 'duplicates' ], 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],

            'create_job' => [ 'description' => 'Create job.', 'domain' => 'workers', 'module' => 'settings', 'tool_key' => 'hermes.workers.create_job', 'permission' => 'system.backup.execute', 'phrases' => [ 'create job', 'queue job' ], 'keywords' => [ 'job', 'queue', 'create' ], 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'cancel_job' => [ 'description' => 'Cancel job.', 'domain' => 'workers', 'module' => 'settings', 'tool_key' => 'hermes.workers.cancel_job', 'permission' => 'system.backup.execute', 'phrases' => [ 'cancel job' ], 'keywords' => [ 'cancel', 'job' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'retry_job' => [ 'description' => 'Retry job.', 'domain' => 'workers', 'module' => 'settings', 'tool_key' => 'hermes.workers.retry_job', 'permission' => 'system.backup.execute', 'phrases' => [ 'retry job' ], 'keywords' => [ 'retry', 'job' ], 'supports_context' => true, 'requires_approval' => true, 'read_only' => false, 'input_schema' => $basicSchema ],
            'list_jobs' => [ 'description' => 'List jobs.', 'domain' => 'workers', 'module' => 'settings', 'tool_key' => 'hermes.workers.list_jobs', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'list jobs', 'show jobs' ], 'keywords' => [ 'list', 'jobs' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],

            'audit_permissions' => [ 'description' => 'Audit permissions.', 'domain' => 'security', 'module' => 'people', 'tool_key' => 'hermes.security.audit_permissions', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'audit permissions', 'permission audit' ], 'keywords' => [ 'audit', 'permissions' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],
            'verify_integrity' => [ 'description' => 'Verify integrity.', 'domain' => 'security', 'module' => 'settings', 'tool_key' => 'hermes.security.verify_integrity', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'verify integrity' ], 'keywords' => [ 'verify', 'integrity' ], 'requires_approval' => false, 'read_only' => true, 'worker_supported' => true, 'input_schema' => $basicSchema ],
            'rotate_keys' => [ 'description' => 'Rotate keys.', 'domain' => 'security', 'module' => 'settings', 'tool_key' => 'hermes.security.rotate_keys', 'permission' => 'system.backup.execute', 'phrases' => [ 'rotate keys', 'key rotation' ], 'keywords' => [ 'rotate', 'keys' ], 'requires_approval' => true, 'read_only' => false, 'worker_supported' => true, 'input_schema' => $basicSchema ],

            'validate_routes' => [ 'description' => 'Validate routes.', 'domain' => 'metis', 'module' => 'core', 'tool_key' => 'hermes.metis.validate_routes', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'validate routes' ], 'keywords' => [ 'validate', 'routes' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],
            'verify_nonce' => [ 'description' => 'Verify nonce.', 'domain' => 'metis', 'module' => 'core', 'tool_key' => 'hermes.metis.verify_nonce', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'verify nonce' ], 'keywords' => [ 'verify', 'nonce' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],
            'run_enclave_test' => [ 'description' => 'Run enclave test.', 'domain' => 'metis', 'module' => 'core', 'tool_key' => 'hermes.metis.run_enclave_test', 'permission' => 'system.diagnostics.view', 'phrases' => [ 'run enclave test', 'enclave test' ], 'keywords' => [ 'enclave', 'test' ], 'requires_approval' => false, 'read_only' => true, 'input_schema' => $basicSchema ],
        ];
    }
}
