<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesCommandRegistry {
    public function definitions(): array {
        return [
            'run_backup' => [
                'key' => 'run_backup',
                'title' => 'Run System Backup',
                'domain' => 'system',
                'service' => [
                    'service' => 'backup',
                    'method' => 'runBackup',
                    'arguments' => [ 'manual' ],
                ],
                'permission' => 'system.backup.execute',
                'permission_check' => [
                    'module' => 'hermes',
                    'action' => 'edit',
                ],
                'context' => [ 'system', 'backup' ],
                'steps' => [
                    'validate_backup_configuration',
                    'generate_backup_archive',
                    'store_backup_archive',
                ],
            ],
            'send_announcement' => [
                'key' => 'send_announcement',
                'title' => 'Send Announcement',
                'domain' => 'communications',
                'service' => [
                    'service' => 'communications',
                    'method' => 'sendAnnouncement',
                    'arguments_from_payload' => [ 'announcement' ],
                ],
                'permission' => 'communications.announcement.send',
                'permission_check' => [
                    'module' => 'newsletter',
                    'action' => 'create',
                ],
                'context' => [ 'contacts', 'communications' ],
                'steps' => [
                    'resolve_target_audience',
                    'prepare_announcement_payload',
                    'dispatch_announcement',
                ],
            ],
            'diagnose_permissions' => [
                'key' => 'diagnose_permissions',
                'title' => 'Diagnose Permissions',
                'domain' => 'security',
                'service' => [
                    'service' => 'security_diagnostics',
                    'method' => 'diagnosePermissions',
                    'arguments_from_payload' => [ 'diagnostic_request' ],
                ],
                'permission' => 'system.diagnostics.view',
                'permission_check' => [
                    'module' => 'hermes',
                    'action' => 'view',
                ],
                'context' => [ 'people', 'permissions', 'drive', 'board' ],
                'steps' => [
                    'verify_user_role',
                    'verify_drive_acl',
                    'verify_group_membership',
                    'verify_permission_inheritance',
                ],
            ],
        ];
    }

    public function definition( string $command ): ?array {
        return $this->definitions()[ \sanitize_key( $command ) ] ?? null;
    }
}
