<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

final class SystemOperationsService extends AbstractOperationsService {
    public function operationKeys(): array {
        return [
            'run_backup',
            'backup_start',
            'backup_restore',
            'backup_validate',
            'clear_cache',
            'cache_clear',
            'rebuild_indexes',
            'reload_config',
            'check_system_updates',
            'update_check',
            'aut_update_check',
            'update_install',
            'aut_update_install',
            'release_rollback',
            'drive_sync',
            'calendar_sync',
            'queue_drain',
            'integrity_baseline',
            'module_compliance_audit',
            'board_workspace_prepare',
            'run_full_diagnostics',
            'diagnostics_run',
            'check_modules',
            'scan_integrity',
            'check_db',
            'check_workers',
            'recover_module',
            'restore_file',
            'rollback_module',
            'enable_module',
            'disable_module',
            'install_module',
            'update_module',
            'create_job',
            'cancel_job',
            'retry_job',
            'list_jobs',
            'audit_permissions',
            'verify_integrity',
            'rotate_keys',
        ];
    }

    public function family(): string {
        return 'system';
    }
}
