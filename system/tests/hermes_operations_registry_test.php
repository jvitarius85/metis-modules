<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesToolRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesIntentRegistry.php';
require_once $root . '/src/Metis/Operations/Contracts/OperationsRegistryInterface.php';
require_once $root . '/src/Metis/Operations/DTOs/OperationDefinition.php';
require_once $root . '/src/Metis/Operations/Services/AbstractOperationsService.php';
require_once $root . '/src/Metis/Operations/Services/UserOperationsService.php';
require_once $root . '/src/Metis/Operations/Services/WorkspaceUserOperationsService.php';
require_once $root . '/src/Metis/Operations/Services/CampaignOperationsService.php';
require_once $root . '/src/Metis/Operations/Services/NewsletterOperationsService.php';
require_once $root . '/src/Metis/Operations/Services/SystemOperationsService.php';
require_once $root . '/src/Metis/Operations/Services/OperationsServiceCatalog.php';
require_once $root . '/src/Metis/Operations/Services/OperationDefinitionBuilder.php';
require_once $root . '/src/Metis/Operations/Registry/OperationsRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesOperationsRegistry.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$registry = new \Metis\Hermes\HermesOperationsRegistry(
    new \Metis\Hermes\HermesCommandRegistry(),
    new \Metis\Hermes\HermesToolRegistry(),
    new \Metis\Hermes\HermesIntentRegistry()
);

$operations = $registry->definitions();
$createUser = (array) ( $operations['create_user'] ?? [] );
$workspaceUserCreate = (array) ( $operations['workspace_user_create'] ?? [] );
$workspaceUserUpdate = (array) ( $operations['workspace_user_update'] ?? [] );
$workspaceUserDisable = (array) ( $operations['workspace_user_disable'] ?? [] );
$workspaceUserEnable = (array) ( $operations['workspace_user_enable'] ?? [] );
$workspaceUserDelete = (array) ( $operations['workspace_user_delete'] ?? [] );
$userDelete = (array) ( $operations['user_delete'] ?? [] );
$userUnlock = (array) ( $operations['user_unlock'] ?? [] );
$userPasswordReset = (array) ( $operations['user_password_reset'] ?? [] );
$manageWorkspaceGroups = (array) ( $operations['manage_workspace_groups'] ?? [] );
$resetUserMfa = (array) ( $operations['reset_user_mfa'] ?? [] );
$linkDriveFolder = (array) ( $operations['link_drive_folder'] ?? [] );
$workspacePasswordReset = (array) ( $operations['workspace_user_password_reset'] ?? [] );
$backupStart = (array) ( $operations['backup_start'] ?? [] );
$backupValidate = (array) ( $operations['backup_validate'] ?? [] );
$updateInstall = (array) ( $operations['update_install'] ?? [] );
$releaseRollback = (array) ( $operations['release_rollback'] ?? [] );
$driveSync = (array) ( $operations['drive_sync'] ?? [] );
$calendarSync = (array) ( $operations['calendar_sync'] ?? [] );
$queueDrain = (array) ( $operations['queue_drain'] ?? [] );
$integrityBaseline = (array) ( $operations['integrity_baseline'] ?? [] );
$moduleComplianceAudit = (array) ( $operations['module_compliance_audit'] ?? [] );
$boardWorkspacePrepare = (array) ( $operations['board_workspace_prepare'] ?? [] );
$campaignCreate = (array) ( $operations['campaign_create'] ?? [] );
$newsletterSend = (array) ( $operations['newsletter_send'] ?? [] );
$newsletterCancel = (array) ( $operations['newsletter_cancel'] ?? [] );
$lookupProfile = (array) ( $operations['lookup_profile'] ?? [] );

$assert( $createUser !== [], 'Operations registry should include create_user.' );
$assert( (string) ( $createUser['tool_key'] ?? '' ) === 'hermes.user.create_user', 'Operations registry should retain tool mapping.' );
$assert( (string) ( $createUser['top_level_intent'] ?? '' ) === 'CREATE', 'Create operations should surface the CREATE top-level intent.' );
$assert( ! empty( $createUser['dispatch']['method'] ), 'Operations registry should expose dispatch metadata.' );
$assert( $workspaceUserCreate !== [], 'Operations registry should include workspace_user_create.' );
$assert( (string) ( $workspaceUserCreate['tool_key'] ?? '' ) === 'hermes.user.create_user', 'Workspace user create should reuse the user creation tool mapping.' );
$assert( (string) ( $workspaceUserCreate['top_level_intent'] ?? '' ) === 'CREATE', 'Workspace user create should surface the CREATE top-level intent.' );
$assert( (string) ( $workspaceUserCreate['handler_metadata']['operation_family'] ?? '' ) === 'workspace_user', 'Workspace user create should expose workspace-user handler metadata.' );
$assert( (string) ( $workspaceUserUpdate['tool_key'] ?? '' ) === 'hermes.user.update_workspace_user', 'Workspace user update should map to the workspace-user update tool.' );
$assert( (string) ( $workspaceUserUpdate['top_level_intent'] ?? '' ) === 'UPDATE', 'Workspace user update should surface the UPDATE top-level intent.' );
$assert( (string) ( $workspaceUserDisable['tool_key'] ?? '' ) === 'hermes.user.disable_workspace_user', 'Workspace user disable should map to the workspace-user disable tool.' );
$assert( ! empty( $workspaceUserDisable['worker_supported'] ), 'Workspace user disable should preserve worker support for queued security actions.' );
$assert( (string) ( $workspaceUserEnable['tool_key'] ?? '' ) === 'hermes.user.enable_workspace_user', 'Workspace user enable should map to the workspace-user enable tool.' );
$assert( (string) ( $workspaceUserDelete['top_level_intent'] ?? '' ) === 'DELETE', 'Workspace user delete should surface the DELETE top-level intent.' );
$assert( (string) ( $userDelete['tool_key'] ?? '' ) === 'hermes.user.delete_user', 'User delete should map to the dedicated user delete tool.' );
$assert( (string) ( $userDelete['top_level_intent'] ?? '' ) === 'DELETE', 'User delete should surface the DELETE top-level intent.' );
$assert( (string) ( $userUnlock['tool_key'] ?? '' ) === 'hermes.user.unlock_user', 'User unlock should map to the dedicated user unlock tool.' );
$assert( (string) ( $userUnlock['top_level_intent'] ?? '' ) === 'EXECUTE', 'User unlock should surface the EXECUTE top-level intent.' );
$assert( (string) ( $userPasswordReset['tool_key'] ?? '' ) === 'hermes.user.reset_metis_password', 'User password reset should map to the Metis password reset tool.' );
$assert( (string) ( $userPasswordReset['top_level_intent'] ?? '' ) === 'EXECUTE', 'User password reset should surface the EXECUTE top-level intent.' );
$assert( (string) ( $manageWorkspaceGroups['tool_key'] ?? '' ) === 'hermes.user.manage_workspace_groups', 'Workspace group management should map to the dedicated workspace-group tool.' );
$assert( (string) ( $manageWorkspaceGroups['top_level_intent'] ?? '' ) === 'UPDATE', 'Workspace group management should surface the UPDATE top-level intent.' );
$assert( (string) ( $resetUserMfa['tool_key'] ?? '' ) === 'hermes.user.reset_user_mfa', 'MFA reset should map to the dedicated MFA reset tool.' );
$assert( (string) ( $resetUserMfa['top_level_intent'] ?? '' ) === 'EXECUTE', 'MFA reset should surface the EXECUTE top-level intent.' );
$assert( (string) ( $linkDriveFolder['tool_key'] ?? '' ) === 'hermes.user.link_drive_folder', 'Drive folder linking should map to the dedicated drive-link tool.' );
$assert( (string) ( $workspacePasswordReset['required_permission'] ?? '' ) === 'people.workspace_manage', 'Workspace password reset should require workspace-management permission.' );
$assert( (string) ( $backupStart['tool_key'] ?? '' ) === 'hermes.system.start_backup', 'Backup start should map to the trusted backup tool.' );
$assert( ! empty( $backupStart['requires_approval'] ), 'Backup start should require approval.' );
$assert( (string) ( $backupValidate['tool_key'] ?? '' ) === 'hermes.system.validate_backup', 'Backup validate should map to the trusted backup validation tool.' );
$assert( (string) ( $backupValidate['handler_metadata']['operation_family'] ?? '' ) === 'system', 'Backup validate should expose system handler metadata.' );
$assert( (string) ( $updateInstall['tool_key'] ?? '' ) === 'hermes.system.install_update', 'Update install should map to the trusted update-install tool.' );
$assert( (string) ( $releaseRollback['tool_key'] ?? '' ) === 'hermes.system.rollback_release', 'Release rollback should map to the trusted rollback tool.' );
$assert( (string) ( $releaseRollback['top_level_intent'] ?? '' ) === 'EXECUTE', 'Release rollback should surface the EXECUTE top-level intent.' );
$assert( (string) ( $driveSync['tool_key'] ?? '' ) === 'hermes.system.sync_drive', 'Drive sync should map to the queued drive-sync tool.' );
$assert( ! empty( $driveSync['requires_approval'] ), 'Drive sync should require approval.' );
$assert( (string) ( $calendarSync['tool_key'] ?? '' ) === 'hermes.system.sync_calendar', 'Calendar sync should map to the queued calendar-sync tool.' );
$assert( (string) ( $queueDrain['tool_key'] ?? '' ) === 'hermes.system.drain_queue', 'Queue drain should map to the queued queue-drain tool.' );
$assert( (string) ( $integrityBaseline['tool_key'] ?? '' ) === 'hermes.system.integrity_baseline', 'Integrity baseline should map to the queued integrity-baseline tool.' );
$assert( (string) ( $moduleComplianceAudit['tool_key'] ?? '' ) === 'hermes.system.module_compliance_audit', 'Module compliance audit should map to the queued module-compliance tool.' );
$assert( (string) ( $boardWorkspacePrepare['tool_key'] ?? '' ) === 'hermes.system.prepare_board_workspace', 'Board workspace prepare should map to the queued board-workspace tool.' );
$assert( (string) ( $campaignCreate['tool_key'] ?? '' ) === 'hermes.campaign.create_campaign', 'Campaign create should map to the campaign creation tool.' );
$assert( (string) ( $campaignCreate['handler_metadata']['operation_family'] ?? '' ) === 'campaign', 'Campaign create should expose campaign handler metadata.' );
$assert( (string) ( $newsletterSend['tool_key'] ?? '' ) === 'hermes.newsletter.send_newsletter', 'Newsletter send should map to the newsletter send tool.' );
$assert( (string) ( $newsletterCancel['tool_key'] ?? '' ) === 'hermes.newsletter.cancel_newsletter', 'Newsletter cancel should map to the newsletter cancel tool.' );
$assert( (string) ( $newsletterCancel['top_level_intent'] ?? '' ) === 'UPDATE', 'Newsletter cancel should surface the UPDATE top-level intent.' );
$assert( (string) ( $newsletterSend['handler_metadata']['operation_family'] ?? '' ) === 'newsletter', 'Newsletter send should expose newsletter handler metadata.' );

$assert( (string) ( $lookupProfile['top_level_intent'] ?? '' ) === 'LOOKUP', 'Lookup operations should surface the LOOKUP top-level intent.' );
$assert( (string) ( $lookupProfile['risk_level'] ?? '' ) === 'low', 'Lookup operations should inherit risk level from the tool registry.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes operations registry checks passed.\n" );
