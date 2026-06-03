<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesToolRegistry {
    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array {
        $defs = [];
        $commandMetadataByTool = [];

        foreach ( ( new HermesCommandRegistry() )->definitions() as $command ) {
            if ( ! is_array( $command ) ) {
                continue;
            }

            $toolKey = (string) ( $command['tool_key'] ?? '' );
            if ( $toolKey === '' ) {
                continue;
            }

            if ( ! isset( $commandMetadataByTool[ $toolKey ] ) ) {
                $commandMetadataByTool[ $toolKey ] = [
                    'supported' => true,
                    'unsupported_message' => '',
                ];
            }

            if ( array_key_exists( 'supported', $command ) && empty( $command['supported'] ) ) {
                $commandMetadataByTool[ $toolKey ] = [
                    'supported' => false,
                    'unsupported_message' => trim( (string) ( $command['unsupported_message'] ?? '' ) ),
                ];
            }
        }

        foreach ( $this->requiredToolMatrix() as $tool_key => $spec ) {
            $commandMetadata = (array) ( $commandMetadataByTool[ $tool_key ] ?? [] );
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
                ! array_key_exists( 'supported', $commandMetadata ) || ! empty( $commandMetadata['supported'] ),
                (string) ( $commandMetadata['unsupported_message'] ?? '' ),
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
                'new_password' => [ 'type' => 'string' ],
                'run_uuid' => [ 'type' => 'string' ],
                'tag' => [ 'type' => 'string' ],
                'job_code' => [ 'type' => 'string' ],
                'job_type' => [ 'type' => 'string' ],
                'module_key' => [ 'type' => 'string' ],
                'file_key' => [ 'type' => 'string' ],
                'folder_id' => [ 'type' => 'string' ],
                'group_emails' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'current_route' => [ 'type' => 'string' ],
                'current_module' => [ 'type' => 'string' ],
                'user_message' => [ 'type' => 'string' ],
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
            'hermes.user.delete_user' => [ 'description' => 'Soft-delete a user.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'deleteUser' ],
            'hermes.user.unlock_user' => [ 'description' => 'Clear account-level login lockout state for a user.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'unlockUser' ],
            'hermes.user.reset_metis_password' => [ 'description' => 'Reset a user Metis password.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'resetMetisPassword' ],
            'hermes.user.reset_workspace_password' => [ 'description' => 'Reset a workspace user password.', 'module' => 'people', 'required_permissions' => [ 'people.workspace_manage' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'resetWorkspacePassword' ],
            'hermes.user.update_workspace_user' => [ 'description' => 'Update a workspace user.', 'module' => 'people', 'required_permissions' => [ 'people.workspace_manage' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'updateWorkspaceUser' ],
            'hermes.user.disable_workspace_user' => [ 'description' => 'Disable workspace access for a user.', 'module' => 'people', 'required_permissions' => [ 'people.workspace_manage' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'disableWorkspaceUser' ],
            'hermes.user.enable_workspace_user' => [ 'description' => 'Enable workspace access for a user.', 'module' => 'people', 'required_permissions' => [ 'people.workspace_manage' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'enableWorkspaceUser' ],
            'hermes.user.delete_workspace_user' => [ 'description' => 'Delete a workspace user.', 'module' => 'people', 'required_permissions' => [ 'people.workspace_manage' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'deleteWorkspaceUser' ],
            'hermes.user.assign_role' => [ 'description' => 'Assign a role.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'assignRole' ],
            'hermes.user.remove_role' => [ 'description' => 'Remove a role.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'removeRole' ],
            'hermes.user.manage_workspace_groups' => [ 'description' => 'Manage Workspace group membership for a user.', 'module' => 'people', 'required_permissions' => [ 'people.workspace_manage' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'manageWorkspaceGroups' ],
            'hermes.user.reset_user_mfa' => [ 'description' => 'Reset MFA for a user.', 'module' => 'people', 'required_permissions' => [ 'people.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'resetUserMfa' ],
            'hermes.user.link_drive_folder' => [ 'description' => 'Link a Drive folder for a user.', 'module' => 'people', 'required_permissions' => [ 'people.workspace_manage' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'linkDriveFolder' ],
            'hermes.user.list_users' => [ 'description' => 'List users.', 'module' => 'people', 'required_permissions' => [ 'people.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'listUsers' ],
            'hermes.user.get_user' => [ 'description' => 'Get a user.', 'module' => 'people', 'required_permissions' => [ 'people.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'getUser' ],
            'hermes.directory.lookup_profile' => [ 'description' => 'Lookup a person, contact, or donor profile.', 'module' => 'people', 'required_permissions' => [ 'people.view' ], 'input_schema' => [ 'type' => 'object', 'properties' => [ 'profile_request' => [ 'type' => 'object', 'properties' => [ 'subject' => [ 'type' => 'string' ], 'entity_hint' => [ 'type' => 'string' ] ], 'required' => [ 'subject' ] ] ], 'required' => [ 'profile_request' ] ], 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'lookupProfile' ],
            'hermes.directory.get_entity_attribute' => [ 'description' => 'Get a specific entity attribute.', 'module' => 'people', 'required_permissions' => [ 'people.view' ], 'input_schema' => [ 'type' => 'object', 'properties' => [ 'attribute_request' => [ 'type' => 'object', 'properties' => [ 'subject' => [ 'type' => 'string' ], 'attribute' => [ 'type' => 'string' ], 'entity_hint' => [ 'type' => 'string' ] ], 'required' => [ 'subject', 'attribute' ] ] ], 'required' => [ 'attribute_request' ] ], 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'getEntityAttribute' ],
            'hermes.help.resolve_issue' => [ 'description' => 'Resolve a natural-language help issue.', 'module' => 'help', 'required_permissions' => [ 'help.view' ], 'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_message' => [ 'type' => 'string' ], 'current_route' => [ 'type' => 'string' ], 'current_module' => [ 'type' => 'string' ], 'session_context' => [ 'type' => 'object', 'properties' => [] ] ], 'required' => [ 'user_message' ] ], 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'resolveHelpIssue' ],
            'hermes.security.diagnose_permissions' => [ 'description' => 'Diagnose permissions for a person.', 'module' => 'people', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => [ 'type' => 'object', 'properties' => [ 'diagnostic_request' => [ 'type' => 'object', 'properties' => [ 'query' => [ 'type' => 'string' ] ], 'required' => [ 'query' ] ] ], 'required' => [ 'diagnostic_request' ] ], 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'diagnosePermissions' ],
            'hermes.donations.query_giving_summary' => [ 'description' => 'Get giving summary.', 'module' => 'donations', 'required_permissions' => [ 'directory.lookup' ], 'input_schema' => [ 'type' => 'object', 'properties' => [ 'giving_request' => [ 'type' => 'object', 'properties' => [ 'period' => [ 'type' => 'string' ] ] ] ], 'required' => [] ], 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'queryGivingSummary' ],
            'hermes.security.query_capability_actors' => [ 'description' => 'Find people with a capability.', 'module' => 'people', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => [ 'type' => 'object', 'properties' => [ 'capability_request' => [ 'type' => 'object', 'properties' => [ 'permission_key' => [ 'type' => 'string' ], 'board_only' => [ 'type' => 'boolean' ] ], 'required' => [ 'permission_key' ] ] ], 'required' => [ 'capability_request' ] ], 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'queryCapabilityActors' ],

            'hermes.system.clear_cache' => [ 'description' => 'Clear cache.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.system.clear_cache', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'clearCache' ],
            'hermes.system.start_backup' => [ 'description' => 'Queue a full system backup.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'startBackup' ],
            'hermes.system.restore_backup' => [ 'description' => 'Queue a restore from a backup run.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'restoreBackup' ],
            'hermes.system.validate_backup' => [ 'description' => 'Queue backup validation for a backup run.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'validateBackup' ],
            'hermes.system.rebuild_indexes' => [ 'description' => 'Rebuild indexes.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'rebuildIndexes' ],
            'hermes.system.reload_config' => [ 'description' => 'Reload config.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'reloadConfig' ],
            'hermes.system.get_system_status' => [ 'description' => 'Get system status.', 'module' => 'settings', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'getSystemStatus' ],
            'hermes.system.check_updates' => [ 'description' => 'Check trusted system releases for updates.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'checkSystemUpdates' ],
            'hermes.system.install_update' => [ 'description' => 'Queue a trusted system update install.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'installSystemUpdate' ],
            'hermes.system.rollback_release' => [ 'description' => 'Queue rollback to the previous trusted release.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'rollbackRelease' ],
            'hermes.system.restart_service' => [ 'description' => 'Restart a service.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'restartService' ],
            'hermes.system.sync_drive' => [ 'description' => 'Queue Drive sync across configured drives.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'queueDriveSync' ],
            'hermes.system.sync_calendar' => [ 'description' => 'Queue Calendar sync across configured calendars.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'queueCalendarSync' ],
            'hermes.system.drain_queue' => [ 'description' => 'Queue a system queue drain.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'drainQueue' ],
            'hermes.system.integrity_baseline' => [ 'description' => 'Queue an integrity baseline build.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'buildIntegrityBaseline' ],
            'hermes.system.module_compliance_audit' => [ 'description' => 'Queue a module compliance audit.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'runModuleComplianceAudit' ],
            'hermes.system.prepare_board_workspace' => [ 'description' => 'Queue board meeting workspace preparation.', 'module' => 'board', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'prepareBoardWorkspace' ],
            'hermes.campaign.create_campaign' => [ 'description' => 'Create a campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.create' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'createCampaign' ],
            'hermes.campaign.update_campaign' => [ 'description' => 'Update a campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'updateCampaign' ],
            'hermes.campaign.publish_campaign' => [ 'description' => 'Publish or send a campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'publishCampaign' ],
            'hermes.campaign.archive_campaign' => [ 'description' => 'Archive a campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'archiveCampaign' ],
            'hermes.campaign.delete_campaign' => [ 'description' => 'Delete a campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.delete' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'deleteCampaign' ],
            'hermes.newsletter.create_newsletter' => [ 'description' => 'Create a newsletter campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.create' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'createNewsletter' ],
            'hermes.newsletter.send_newsletter' => [ 'description' => 'Send a newsletter campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'sendNewsletter' ],
            'hermes.newsletter.schedule_newsletter' => [ 'description' => 'Schedule a newsletter campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'scheduleNewsletter' ],
            'hermes.newsletter.cancel_newsletter' => [ 'description' => 'Cancel a scheduled or queued newsletter campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'cancelNewsletter' ],
            'hermes.newsletter.delete_newsletter' => [ 'description' => 'Delete a newsletter campaign.', 'module' => 'newsletter', 'required_permissions' => [ 'newsletter.delete' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'deleteNewsletter' ],

            'hermes.diagnostics.run_full_diagnostics' => [ 'description' => 'Run full diagnostics.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'runFullDiagnostics' ],
            'hermes.diagnostics.check_modules' => [ 'description' => 'Check module status.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'checkModules' ],
            'hermes.diagnostics.scan_integrity' => [ 'description' => 'Scan integrity.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'scanIntegrity' ],
            'hermes.diagnostics.check_db' => [ 'description' => 'Check database health.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'checkDb' ],
            'hermes.diagnostics.check_workers' => [ 'description' => 'Check worker health.', 'module' => 'hermes', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'checkWorkers' ],

            'hermes.recovery.recover_module' => [ 'description' => 'Recover a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'recoverModule' ],
            'hermes.recovery.restore_file' => [ 'description' => 'Restore a file.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.system.restore', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'restoreFile' ],
            'hermes.recovery.rollback_module' => [ 'description' => 'Rollback a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'rollbackModule' ],

            'hermes.module.enable_module' => [ 'description' => 'Enable a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.module.enable', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'enableModule' ],
            'hermes.module.disable_module' => [ 'description' => 'Disable a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.module.disable', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'disableModule' ],
            'hermes.module.install_module' => [ 'description' => 'Install a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'installModule' ],
            'hermes.module.update_module' => [ 'description' => 'Update a module.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'updateModule' ],

            'hermes.data.export_data' => [ 'description' => 'Export data.', 'module' => 'reports', 'required_permissions' => [ 'reports.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'exportData' ],
            'hermes.data.import_data' => [ 'description' => 'Import data.', 'module' => 'import', 'required_permissions' => [ 'edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'importData' ],
            'hermes.data.deduplicate' => [ 'description' => 'Deduplicate records.', 'module' => 'contacts', 'required_permissions' => [ 'edit' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'deduplicate' ],

            'hermes.workers.create_job' => [ 'description' => 'Create a worker job.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => true, 'service' => 'hermes_capabilities', 'method' => 'createJob' ],
            'hermes.workers.cancel_job' => [ 'description' => 'Cancel a worker job.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'high', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'cancelJob' ],
            'hermes.workers.retry_job' => [ 'description' => 'Retry a worker job.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'retryJob' ],
            'hermes.workers.list_jobs' => [ 'description' => 'List worker jobs.', 'module' => 'settings', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'listJobs' ],

            'hermes.security.audit_permissions' => [ 'description' => 'Audit permissions.', 'module' => 'people', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'low', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'auditPermissions' ],
            'hermes.security.verify_integrity' => [ 'description' => 'Verify integrity.', 'module' => 'settings', 'required_permissions' => [ 'system.diagnostics.view' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'medium', 'requires_approval' => false, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'verifyIntegrity' ],
            'hermes.security.rotate_keys' => [ 'description' => 'Rotate keys.', 'module' => 'settings', 'required_permissions' => [ 'system.backup.execute' ], 'input_schema' => $basicSubject, 'enclave_action' => 'hermes.tool.execute', 'risk_level' => 'critical', 'requires_approval' => true, 'worker_supported' => false, 'service' => 'hermes_capabilities', 'method' => 'rotateKeys' ],

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
        bool $supported,
        string $unsupported_message,
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
            'supported' => $supported,
            'unsupported_message' => $unsupported_message,
            'dispatch' => [
                'service' => $service,
                'method' => $method,
                'pass_payload' => true,
                'pass_context' => false,
            ],
        ];
    }
}
