<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/ConversationalParser.php';
require_once $root . '/src/Metis/Hermes/HermesIntentRegistry.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$registry = new \Metis\Hermes\HermesCommandRegistry();
$parser = new \Metis\Hermes\ConversationalParser( $registry, null, null, null, new \Metis\Hermes\HermesIntentRegistry() );

$multi = $parser->parse( 'please disable john@example.com and run diagnostics' );
$assert( $multi['normalized_input'] === 'disable john@example.com and run diagnostics', 'Normalization should strip filler phrases.' );
$assert( count( (array) ( $multi['execution_plan'] ?? [] ) ) === 2, 'Multi-step input should produce a two-step execution plan.' );
$assert( (string) ( $multi['intents'][0]['intent'] ?? '' ) === 'disable_user', 'First fragment should resolve to disable_user.' );
$assert( (string) ( $multi['intents'][1]['intent'] ?? '' ) === 'run_full_diagnostics', 'Second fragment should resolve to run_full_diagnostics.' );
$assert( ! empty( $multi['entities'] ), 'Email entity should be pre-resolved.' );

$context = $parser->parse( 'do it again' );
$assert( ! empty( $context['requires_clarification'] ), 'Context-dependent shorthand without memory should require clarification.' );

$single = $parser->parse( 'list users' );
$assert( (string) ( $single['selected_intent'] ?? '' ) === 'list_users', 'Simple command should map to list_users.' );
$assert( (string) ( $single['top_level_intent'] ?? '' ) === 'LOOKUP', 'Simple list commands should expose the LOOKUP top-level intent.' );
$assert( (string) ( $single['confidence_label'] ?? '' ) === 'high', 'Direct command phrases should achieve high confidence.' );

$lookup = $parser->parse( 'who is meg wallace' );
$assert( (string) ( $lookup['selected_intent'] ?? '' ) === 'lookup_profile', '"who is <name>" should map to lookup_profile.' );
$assert( empty( $lookup['requires_clarification'] ), '"who is <name>" should not require clarification.' );

$lookupInitials = $parser->parse( 'who is JD?' );
$assert( (string) ( $lookupInitials['selected_intent'] ?? '' ) === 'lookup_profile', '"who is JD?" should map to lookup_profile.' );
$assert( empty( $lookupInitials['requires_clarification'] ), '"who is JD?" should not require clarification.' );
$assert( (string) ( $lookupInitials['intents'][0]['payload']['profile_request']['subject'] ?? '' ) === 'jd', '"who is JD?" should capture the abbreviated subject.' );

$lookupMixedCase = $parser->parse( 'who is JD Vitarius?' );
$assert( (string) ( $lookupMixedCase['selected_intent'] ?? '' ) === 'lookup_profile', '"who is JD Vitarius?" should map to lookup_profile.' );
$assert( empty( $lookupMixedCase['requires_clarification'] ), '"who is JD Vitarius?" should not require clarification.' );
$assert( (string) ( $lookupMixedCase['intents'][0]['payload']['profile_request']['subject'] ?? '' ) === 'jd vitarius', '"who is JD Vitarius?" should capture the mixed-case subject.' );

$showProfile = $parser->parse( "show JD's profile" );
$assert( (string) ( $showProfile['selected_intent'] ?? '' ) === 'lookup_profile', '"show JD\'s profile" should map to lookup_profile.' );
$assert( empty( $showProfile['requires_clarification'] ), '"show JD\'s profile" should not require clarification.' );
$assert( (string) ( $showProfile['intents'][0]['payload']['profile_request']['subject'] ?? '' ) === 'jd', '"show JD\'s profile" should capture the abbreviated subject.' );

$attribute = $parser->parse( 'what is meg wallace email' );
$assert( (string) ( $attribute['selected_intent'] ?? '' ) === 'get_entity_attribute', '"what is <name> email" should map to get_entity_attribute.' );

$capability = $parser->parse( 'who has board access' );
$assert( (string) ( $capability['selected_intent'] ?? '' ) === 'query_capability_actors', '"who has board access" should map to query_capability_actors.' );

$help = $parser->parse( "I can't create a new GL entry" );
$assert( (string) ( $help['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Natural-language help issue should map to resolve_help_issue.' );
$assert( (string) ( $help['top_level_intent'] ?? '' ) === 'HELP', 'Natural-language help issue should expose the HELP top-level intent.' );
$assert( (string) ( $help['intents'][0]['payload']['user_message'] ?? '' ) !== '', 'Help issue payload should preserve the user message.' );

$instructional = $parser->parse( 'how do I create a new donation?' );
$assert( (string) ( $instructional['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Instructional help phrasing should map to resolve_help_issue.' );
$assert( empty( $instructional['requires_clarification'] ), 'Instructional help phrasing should resolve without clarification.' );

$calendarHelp = $parser->parse( 'create an event' );
$assert( (string) ( $calendarHelp['selected_intent'] ?? '' ) === 'resolve_help_issue', '"create an event" should map to resolve_help_issue.' );
$assert( empty( $calendarHelp['requires_clarification'] ), '"create an event" should resolve without clarification.' );

$userHelp = $parser->parse( 'how do I create a new user?' );
$assert( (string) ( $userHelp['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Instructional user-management phrasing should map to resolve_help_issue.' );
$assert( empty( $userHelp['requires_clarification'] ), 'Instructional user-management phrasing should not require clarification.' );

$userAction = $parser->parse( 'create a new user for Riley with email riley@example.com' );
$assert( (string) ( $userAction['selected_intent'] ?? '' ) === 'create_user', 'Concrete user creation request should map to create_user.' );
$assert( empty( $userAction['requires_clarification'] ), 'Concrete user creation request should not require clarification.' );
$assert( (string) ( $userAction['intents'][0]['payload']['email'] ?? '' ) === 'riley@example.com', 'Concrete user creation request should capture the email payload.' );

$workspaceUser = $parser->parse( 'create a workspace user for Riley with email riley@example.com' );
$assert( (string) ( $workspaceUser['selected_intent'] ?? '' ) === 'workspace_user_create', 'Workspace user creation request should map to workspace_user_create.' );
$assert( empty( $workspaceUser['requires_clarification'] ), 'Workspace user creation request should not require clarification.' );

$workspaceDisable = $parser->parse( 'disable workspace access for Riley' );
$assert( (string) ( $workspaceDisable['selected_intent'] ?? '' ) === 'workspace_user_disable', 'Workspace access disable request should map to workspace_user_disable.' );
$assert( empty( $workspaceDisable['requires_clarification'] ), 'Workspace access disable request should not require clarification.' );

$userDelete = $parser->parse( 'delete user Riley' );
$assert( (string) ( $userDelete['selected_intent'] ?? '' ) === 'user_delete', 'User delete request should map to user_delete.' );
$assert( empty( $userDelete['requires_clarification'] ), 'User delete request should not require clarification.' );

$bareUserDelete = $parser->parse( 'Delete John Smith.' );
$bareUserDeletePayload = (array) ( $bareUserDelete['intents'][0]['payload'] ?? [] );
$assert( (string) ( $bareUserDelete['selected_intent'] ?? '' ) === 'user_delete', 'Bare person delete request should map to user_delete.' );
$assert( (string) ( $bareUserDeletePayload['subject'] ?? '' ) === 'John Smith', 'Bare person delete request should preserve the target subject.' );
$assert( empty( $bareUserDelete['requires_clarification'] ), 'Bare person delete request should not require clarification.' );

$userUnlock = $parser->parse( 'unlock user Riley' );
$assert( (string) ( $userUnlock['selected_intent'] ?? '' ) === 'user_unlock', 'User unlock request should map to user_unlock.' );
$assert( empty( $userUnlock['requires_clarification'] ), 'User unlock request should not require clarification.' );

$workspacePasswordReset = $parser->parse( "reset Meg's workspace password." );
$workspacePasswordResetPayload = (array) ( $workspacePasswordReset['intents'][0]['payload'] ?? [] );
$assert( (string) ( $workspacePasswordReset['selected_intent'] ?? '' ) === 'workspace_user_password_reset', 'Possessive workspace password reset request should map to workspace_user_password_reset.' );
$assert( (string) ( $workspacePasswordResetPayload['subject'] ?? '' ) === 'meg', 'Possessive workspace password reset request should preserve the target subject.' );
$assert( empty( $workspacePasswordReset['requires_clarification'] ), 'Possessive workspace password reset request should not require clarification.' );

$mfaReset = $parser->parse( 'reset mfa for Riley' );
$assert( (string) ( $mfaReset['selected_intent'] ?? '' ) === 'reset_user_mfa', 'MFA reset request should map to reset_user_mfa.' );
$assert( empty( $mfaReset['requires_clarification'] ), 'MFA reset request should not require clarification.' );

$driveSync = $parser->parse( 'sync drives' );
$assert( (string) ( $driveSync['selected_intent'] ?? '' ) === 'drive_sync', 'Drive sync request should map to drive_sync.' );
$assert( empty( $driveSync['requires_clarification'] ), 'Drive sync request should not require clarification.' );

$queueDrain = $parser->parse( 'drain queue' );
$assert( (string) ( $queueDrain['selected_intent'] ?? '' ) === 'queue_drain', 'Queue drain request should map to queue_drain.' );
$assert( empty( $queueDrain['requires_clarification'] ), 'Queue drain request should not require clarification.' );

$restoreFile = $parser->parse( 'restore file "storage/public-media/reports/summary.pdf" from run_20260601_abc12345' );
$restoreFilePayload = (array) ( $restoreFile['intents'][0]['payload'] ?? [] );
$assert( (string) ( $restoreFile['selected_intent'] ?? '' ) === 'restore_file', 'Restore file request should map to restore_file.' );
$assert( (string) ( $restoreFilePayload['run_uuid'] ?? '' ) === 'run_20260601_abc12345', 'Restore file request should capture the backup run ID.' );
$assert( (string) ( $restoreFilePayload['relative_path'] ?? '' ) === 'storage/public-media/reports/summary.pdf', 'Restore file request should capture the backup-relative file path.' );

$moduleCompliance = $parser->parse( 'run module compliance audit' );
$assert( (string) ( $moduleCompliance['selected_intent'] ?? '' ) === 'module_compliance_audit', 'Module compliance audit request should map to module_compliance_audit.' );
$assert( empty( $moduleCompliance['requires_clarification'] ), 'Module compliance audit request should not require clarification.' );

$createJob = $parser->parse( 'queue job task module_compliance_audit' );
$createJobPayload = (array) ( $createJob['intents'][0]['payload'] ?? [] );
$assert( (string) ( $createJob['selected_intent'] ?? '' ) === 'create_job', 'Cron task queue request should map to create_job.' );
$assert( (string) ( $createJobPayload['task_slug'] ?? '' ) === 'module_compliance_audit', 'Cron task queue request should capture the task slug.' );
$assert( empty( $createJob['requires_clarification'] ), 'Cron task queue request should not require clarification when the task slug is present.' );

$cancelJob = $parser->parse( 'cancel job JOBABC123' );
$cancelJobPayload = (array) ( $cancelJob['intents'][0]['payload'] ?? [] );
$assert( (string) ( $cancelJob['selected_intent'] ?? '' ) === 'cancel_job', 'Worker cancel request should map to cancel_job.' );
$assert( (string) ( $cancelJobPayload['subject'] ?? '' ) === 'JOBABC123', 'Worker cancel request should capture the job code as the subject.' );
$assert( (string) ( $cancelJobPayload['job_key'] ?? '' ) === 'jobabc123', 'Worker cancel request should normalize the job code key.' );

$retryJob = $parser->parse( 'retry job JOBXYZ789' );
$retryJobPayload = (array) ( $retryJob['intents'][0]['payload'] ?? [] );
$assert( (string) ( $retryJob['selected_intent'] ?? '' ) === 'retry_job', 'Worker retry request should map to retry_job.' );
$assert( (string) ( $retryJobPayload['subject'] ?? '' ) === 'JOBXYZ789', 'Worker retry request should capture the job code as the subject.' );
$assert( (string) ( $retryJobPayload['job_key'] ?? '' ) === 'jobxyz789', 'Worker retry request should normalize the job code key.' );

$workspaceGroupUpdate = $parser->parse( 'add workspace group finance-team@example.org to Riley' );
$workspaceGroupPayload = (array) ( $workspaceGroupUpdate['intents'][0]['payload'] ?? [] );
$assert( (string) ( $workspaceGroupUpdate['selected_intent'] ?? '' ) === 'manage_workspace_groups', 'Workspace group update request should map to manage_workspace_groups.' );
$assert( (string) ( $workspaceGroupPayload['subject'] ?? '' ) === 'riley', 'Workspace group update request should preserve the target user subject.' );
$assert( (array) ( $workspaceGroupPayload['group_emails'] ?? [] ) === [ 'finance-team@example.org' ], 'Workspace group update request should capture Workspace group emails.' );
$assert( (string) ( $workspaceGroupPayload['mode'] ?? '' ) === 'add', 'Workspace group update request should infer add mode by default.' );

$workspaceGroupRemoval = $parser->parse( 'remove workspace group finance-team@example.org from Riley' );
$workspaceGroupRemovalPayload = (array) ( $workspaceGroupRemoval['intents'][0]['payload'] ?? [] );
$assert( (string) ( $workspaceGroupRemoval['selected_intent'] ?? '' ) === 'manage_workspace_groups', 'Workspace group removal request should map to manage_workspace_groups.' );
$assert( (string) ( $workspaceGroupRemovalPayload['subject'] ?? '' ) === 'riley', 'Workspace group removal request should preserve the target user subject.' );
$assert( (array) ( $workspaceGroupRemovalPayload['group_emails'] ?? [] ) === [ 'finance-team@example.org' ], 'Workspace group removal request should capture Workspace group emails.' );
$assert( (string) ( $workspaceGroupRemovalPayload['mode'] ?? '' ) === 'remove', 'Workspace group removal request should infer remove mode.' );

$removeRole = $parser->parse( 'remove role administrator from Riley' );
$removeRolePayload = (array) ( $removeRole['intents'][0]['payload'] ?? [] );
$assert( (string) ( $removeRole['selected_intent'] ?? '' ) === 'remove_role', 'Remove role request should map to remove_role.' );
$assert( (string) ( $removeRolePayload['subject'] ?? '' ) === 'riley', 'Remove role request should preserve the target user subject.' );
$assert( (array) ( $removeRolePayload['roles'] ?? [] ) === [ 'administrator' ], 'Remove role request should capture the requested role list.' );
$assert( (string) ( $removeRolePayload['mode'] ?? '' ) === 'remove', 'Remove role request should preserve remove mode.' );

$linkDriveFolder = $parser->parse( 'link drive folder https://drive.google.com/drive/folders/1AbCdEfGhIJkLmNoPqRsTuVwXyZ to Riley' );
$linkDriveFolderPayload = (array) ( $linkDriveFolder['intents'][0]['payload'] ?? [] );
$assert( (string) ( $linkDriveFolder['selected_intent'] ?? '' ) === 'link_drive_folder', 'Drive folder link request should map to link_drive_folder.' );
$assert( (string) ( $linkDriveFolderPayload['subject'] ?? '' ) === 'riley', 'Drive folder link request should preserve the target user subject.' );
$assert( (string) ( $linkDriveFolderPayload['folder_id'] ?? '' ) === '1abcdefghijklmnopqrstuvwxyz', 'Drive folder link request should capture the explicit Drive folder ID.' );

$linkDriveFolderById = $parser->parse( 'link drive folder id 1AbCdEfGhIJkLmNoPqRsTuVwXyZ to Riley' );
$linkDriveFolderByIdPayload = (array) ( $linkDriveFolderById['intents'][0]['payload'] ?? [] );
$assert( (string) ( $linkDriveFolderById['selected_intent'] ?? '' ) === 'link_drive_folder', 'Drive folder ID request should map to link_drive_folder.' );
$assert( (string) ( $linkDriveFolderByIdPayload['subject'] ?? '' ) === 'riley', 'Drive folder ID request should preserve the target user subject.' );
$assert( (string) ( $linkDriveFolderByIdPayload['folder_id'] ?? '' ) === '1abcdefghijklmnopqrstuvwxyz', 'Drive folder ID request should capture the explicit Drive folder ID.' );

$boardWorkspace = $parser->parse( 'prepare board workspace for Q3-2026' );
$assert( (string) ( $boardWorkspace['selected_intent'] ?? '' ) === 'board_workspace_prepare', 'Board workspace request should map to board_workspace_prepare.' );
$assert( empty( $boardWorkspace['requires_clarification'] ), 'Board workspace request should not require clarification when the meeting reference is present.' );

$newsletterCancel = $parser->parse( 'cancel newsletter' );
$assert( (string) ( $newsletterCancel['selected_intent'] ?? '' ) === 'newsletter_cancel', 'Newsletter cancel request should map to newsletter_cancel.' );
$assert( empty( $newsletterCancel['requires_clarification'] ), 'Newsletter cancel request should not require clarification.' );

$deleteCampaign = $parser->parse( 'Delete campaign Summer Giving.' );
$deleteCampaignPayload = (array) ( $deleteCampaign['intents'][0]['payload'] ?? [] );
$assert( (string) ( $deleteCampaign['selected_intent'] ?? '' ) === 'campaign_delete', 'Campaign delete request should map to campaign_delete.' );
$assert( (string) ( $deleteCampaignPayload['subject'] ?? '' ) === 'Summer Giving', 'Campaign delete request should preserve the target campaign subject.' );
$assert( empty( $deleteCampaign['requires_clarification'] ), 'Campaign delete request should not require clarification.' );

$noSplit = $parser->parse( 'how do I create a GL entry with debit and credit lines?' );
$assert( count( (array) ( $noSplit['execution_plan'] ?? [] ) ) === 1, 'Instructional GL phrasing should not split on debit and credit wording.' );
$assert( (string) ( $noSplit['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Instructional GL phrasing with accounting terms should still map to resolve_help_issue.' );

$knownActionPrompts = [
    'please run a module diagnotic' => 'check_modules',
    'please run a module diagnostic' => 'check_modules',
    'run full diagnostics' => 'run_full_diagnostics',
    'check database health' => 'check_db',
    'check worker queue' => 'check_workers',
    'show system status' => 'get_system_status',
    'check for system updates' => 'check_system_updates',
    'check updates' => 'check_system_updates',
    'check updates and install' => 'check_system_updates',
    'scan integrity' => 'scan_integrity',
    'audit permissions' => 'audit_permissions',
    'validate routes' => 'validate_routes',
    'run enclave test' => 'run_enclave_test',
    'list jobs' => 'list_jobs',
];

foreach ( $knownActionPrompts as $prompt => $expectedIntent ) {
    $parsed = $parser->parse( $prompt );
    $assert(
        (string) ( $parsed['selected_intent'] ?? '' ) === $expectedIntent,
        sprintf( 'Known action prompt [%s] should map to [%s].', $prompt, $expectedIntent )
    );
    $assert(
        (string) ( $parsed['confidence_label'] ?? '' ) === 'high',
        sprintf( 'Known action prompt [%s] should resolve with high confidence.', $prompt )
    );
    $assert(
        empty( $parsed['requires_clarification'] ),
        sprintf( 'Known action prompt [%s] should not require clarification.', $prompt )
    );
}

$updateInstallMulti = $parser->parse( 'check updates and install' );
$assert( count( (array) ( $updateInstallMulti['execution_plan'] ?? [] ) ) === 2, '"check updates and install" should produce a two-step execution plan.' );
$assert( (string) ( $updateInstallMulti['intents'][0]['intent'] ?? '' ) === 'check_system_updates', 'First step of "check updates and install" should resolve to check_system_updates.' );
$assert( (string) ( $updateInstallMulti['intents'][1]['intent'] ?? '' ) === 'update_install', 'Second step of "check updates and install" should resolve to update_install.' );
$assert( empty( $updateInstallMulti['requires_clarification'] ), '"check updates and install" should not require clarification.' );

$singularUpdateInstallMulti = $parser->parse( 'check for an update and install' );
$assert( count( (array) ( $singularUpdateInstallMulti['execution_plan'] ?? [] ) ) === 2, '"check for an update and install" should produce a two-step execution plan.' );
$assert( (string) ( $singularUpdateInstallMulti['intents'][0]['intent'] ?? '' ) === 'check_system_updates', 'First step of "check for an update and install" should resolve to check_system_updates.' );
$assert( (string) ( $singularUpdateInstallMulti['intents'][1]['intent'] ?? '' ) === 'update_install', 'Second step of "check for an update and install" should resolve to update_install.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes conversational parser checks passed.\n" );
