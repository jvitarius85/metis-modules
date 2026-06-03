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
require_once $root . '/tests/_support/hermes_blocked_operations_fixture.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$catalog = new \Metis\Operations\Services\OperationsServiceCatalog( [
    new \Metis\Operations\Services\UserOperationsService(),
    new \Metis\Operations\Services\WorkspaceUserOperationsService(),
    new \Metis\Operations\Services\CampaignOperationsService(),
    new \Metis\Operations\Services\NewsletterOperationsService(),
    new \Metis\Operations\Services\SystemOperationsService(),
] );
$builder = new \Metis\Operations\Services\OperationDefinitionBuilder(
    new \Metis\Hermes\HermesCommandRegistry(),
    new \Metis\Hermes\HermesToolRegistry(),
    new \Metis\Hermes\HermesIntentRegistry(),
    $catalog
);
$registry = new \Metis\Operations\Registry\OperationsRegistry( $builder );

$definitions = $registry->definitions();
$reset = (array) ( $registry->definition( 'check_workers' ) ?? [] );
$clearCache = (array) ( $definitions['clear_cache'] ?? [] );
$createUser = (array) ( $definitions['create_user'] ?? [] );
$workspaceUserCreate = (array) ( $definitions['workspace_user_create'] ?? [] );
$workspaceUserUpdate = (array) ( $definitions['workspace_user_update'] ?? [] );
$workspaceUserDisable = (array) ( $definitions['workspace_user_disable'] ?? [] );
$workspaceUserEnable = (array) ( $definitions['workspace_user_enable'] ?? [] );
$workspaceUserDelete = (array) ( $definitions['workspace_user_delete'] ?? [] );
$userDelete = (array) ( $definitions['user_delete'] ?? [] );
$userUnlock = (array) ( $definitions['user_unlock'] ?? [] );
$userPasswordReset = (array) ( $definitions['user_password_reset'] ?? [] );
$manageWorkspaceGroups = (array) ( $definitions['manage_workspace_groups'] ?? [] );
$resetUserMfa = (array) ( $definitions['reset_user_mfa'] ?? [] );
$linkDriveFolder = (array) ( $definitions['link_drive_folder'] ?? [] );
$workspacePasswordReset = (array) ( $definitions['workspace_user_password_reset'] ?? [] );
$backupStart = (array) ( $definitions['backup_start'] ?? [] );
$backupValidate = (array) ( $definitions['backup_validate'] ?? [] );
$restoreBackup = (array) ( $definitions['backup_restore'] ?? [] );
$updateInstall = (array) ( $definitions['update_install'] ?? [] );
$releaseRollback = (array) ( $definitions['release_rollback'] ?? [] );
$driveSync = (array) ( $definitions['drive_sync'] ?? [] );
$calendarSync = (array) ( $definitions['calendar_sync'] ?? [] );
$queueDrain = (array) ( $definitions['queue_drain'] ?? [] );
$integrityBaseline = (array) ( $definitions['integrity_baseline'] ?? [] );
$moduleComplianceAudit = (array) ( $definitions['module_compliance_audit'] ?? [] );
$boardWorkspacePrepare = (array) ( $definitions['board_workspace_prepare'] ?? [] );
$campaignCreate = (array) ( $definitions['campaign_create'] ?? [] );
$campaignPublish = (array) ( $definitions['campaign_publish'] ?? [] );
$newsletterSchedule = (array) ( $definitions['newsletter_schedule'] ?? [] );
$newsletterCancel = (array) ( $definitions['newsletter_cancel'] ?? [] );
$serviceRestart = (array) ( $definitions['service_restart'] ?? [] );

$assert( isset( $definitions['create_user'] ), 'Operations framework registry should expose create_user.' );
$assert( (string) ( $clearCache['enclave_action'] ?? '' ) === 'hermes.system.clear_cache', 'Operations framework should preserve enclave execution metadata.' );
$assert( ! empty( $clearCache['requires_approval'] ), 'Operations framework should retain approval requirements.' );
$assert( ! empty( $clearCache['dispatch']['method'] ), 'Operations framework should preserve dispatch metadata.' );
$assert( (string) ( $reset['top_level_intent'] ?? '' ) === 'EXECUTE', 'Operations framework should preserve current top-level intent classification for diagnostic commands.' );
$assert( (string) ( $reset['module'] ?? '' ) === 'hermes', 'Operations framework should preserve module ownership.' );
$assert( (string) ( $createUser['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\UserOperationsService::class, 'Operations framework should assign user commands to the user operations service.' );
$assert( (string) ( $workspaceUserCreate['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\WorkspaceUserOperationsService::class, 'Operations framework should assign workspace-user commands to the workspace user operations service.' );
$assert( (string) ( $workspaceUserUpdate['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\WorkspaceUserOperationsService::class, 'Operations framework should assign workspace-user updates to the workspace user operations service.' );
$assert( (string) ( $workspaceUserDisable['dispatch']['method'] ?? '' ) === 'disableWorkspaceUser', 'Operations framework should preserve workspace-user disable dispatch metadata.' );
$assert( (string) ( $workspaceUserEnable['dispatch']['method'] ?? '' ) === 'enableWorkspaceUser', 'Operations framework should preserve workspace-user enable dispatch metadata.' );
$assert( (string) ( $workspaceUserDelete['dispatch']['method'] ?? '' ) === 'deleteWorkspaceUser', 'Operations framework should preserve workspace-user delete dispatch metadata.' );
$assert( (string) ( $clearCache['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\SystemOperationsService::class, 'Operations framework should assign system commands to the system operations service.' );
$assert( (string) ( $userDelete['dispatch']['method'] ?? '' ) === 'deleteUser', 'Operations framework should preserve user-delete dispatch metadata.' );
$assert( (string) ( $userUnlock['dispatch']['method'] ?? '' ) === 'unlockUser', 'Operations framework should preserve user-unlock dispatch metadata.' );
$assert( (string) ( $userPasswordReset['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\UserOperationsService::class, 'Operations framework should assign Metis password reset to the user operations service.' );
$assert( (string) ( $manageWorkspaceGroups['dispatch']['method'] ?? '' ) === 'manageWorkspaceGroups', 'Operations framework should preserve workspace-group dispatch metadata.' );
$assert( (string) ( $resetUserMfa['dispatch']['method'] ?? '' ) === 'resetUserMfa', 'Operations framework should preserve MFA reset dispatch metadata.' );
$assert( (string) ( $linkDriveFolder['dispatch']['method'] ?? '' ) === 'linkDriveFolder', 'Operations framework should preserve drive-folder link dispatch metadata.' );
$assert( (string) ( $workspacePasswordReset['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\WorkspaceUserOperationsService::class, 'Operations framework should assign workspace password reset to the workspace-user operations service.' );
$assert( (string) ( $backupStart['dispatch']['method'] ?? '' ) === 'startBackup', 'Operations framework should preserve backup-start dispatch metadata.' );
$assert( (string) ( $backupValidate['dispatch']['method'] ?? '' ) === 'validateBackup', 'Operations framework should preserve backup-validate dispatch metadata.' );
$assert( (string) ( $restoreBackup['dispatch']['method'] ?? '' ) === 'restoreBackup', 'Operations framework should preserve backup-restore dispatch metadata.' );
$assert( (string) ( $updateInstall['dispatch']['method'] ?? '' ) === 'installSystemUpdate', 'Operations framework should preserve update-install dispatch metadata.' );
$assert( (string) ( $releaseRollback['dispatch']['method'] ?? '' ) === 'rollbackRelease', 'Operations framework should preserve release-rollback dispatch metadata.' );
$assert( (string) ( $driveSync['dispatch']['method'] ?? '' ) === 'queueDriveSync', 'Operations framework should preserve drive-sync dispatch metadata.' );
$assert( (string) ( $calendarSync['dispatch']['method'] ?? '' ) === 'queueCalendarSync', 'Operations framework should preserve calendar-sync dispatch metadata.' );
$assert( (string) ( $queueDrain['dispatch']['method'] ?? '' ) === 'drainQueue', 'Operations framework should preserve queue-drain dispatch metadata.' );
$assert( (string) ( $integrityBaseline['dispatch']['method'] ?? '' ) === 'buildIntegrityBaseline', 'Operations framework should preserve integrity-baseline dispatch metadata.' );
$assert( (string) ( $moduleComplianceAudit['dispatch']['method'] ?? '' ) === 'runModuleComplianceAudit', 'Operations framework should preserve module-compliance dispatch metadata.' );
$assert( (string) ( $boardWorkspacePrepare['dispatch']['method'] ?? '' ) === 'prepareBoardWorkspace', 'Operations framework should preserve board-workspace dispatch metadata.' );
$assert( (string) ( $campaignCreate['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\CampaignOperationsService::class, 'Operations framework should assign campaign creation to the campaign operations service.' );
$assert( (string) ( $campaignPublish['dispatch']['method'] ?? '' ) === 'publishCampaign', 'Operations framework should preserve campaign publish dispatch metadata.' );
$assert( (string) ( $newsletterSchedule['handler_metadata']['handler'] ?? '' ) === \Metis\Operations\Services\NewsletterOperationsService::class, 'Operations framework should assign newsletter scheduling to the newsletter operations service.' );
$assert( (string) ( $newsletterCancel['dispatch']['method'] ?? '' ) === 'cancelNewsletter', 'Operations framework should preserve newsletter cancel dispatch metadata.' );
$assert( array_key_exists( 'supported', $serviceRestart ), 'Operations framework should expose supported metadata for blocked operations.' );
$assert( empty( $serviceRestart['supported'] ), 'Operations framework should mark service_restart unsupported.' );
$assert( (string) ( $serviceRestart['unsupported_message'] ?? '' ) === 'Service restart does not have a trusted backend registered for Hermes execution yet.', 'Operations framework should preserve the unsupported message for service_restart.' );

foreach ( metis_hermes_blocked_operations_fixture() as $operationKey => $fixture ) {
    $definition = (array) ( $definitions[ $operationKey ] ?? [] );
    $assert( $definition !== [], sprintf( 'Operations framework should expose blocked operation %s.', $operationKey ) );
    $assert( array_key_exists( 'supported', $definition ), sprintf( 'Operations framework should expose supported metadata for %s.', $operationKey ) );
    $assert( empty( $definition['supported'] ), sprintf( 'Operations framework should mark %s unsupported.', $operationKey ) );
    $assert( (string) ( $definition['unsupported_message'] ?? '' ) === (string) $fixture['unsupported_message'], sprintf( 'Operations framework should preserve the unsupported message for %s.', $operationKey ) );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Operations framework registry checks passed.\n" );
