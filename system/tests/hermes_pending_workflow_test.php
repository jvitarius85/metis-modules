<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'ajax', 'router', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$gateway = \Metis\Core\Application::service( 'hermes_gateway' );
$memory = \Metis\Core\Application::service( 'hermes_memory_store' );
$db = \Metis\Core\Application::service( 'db' );

$sessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wf', true ) ), 0, 8 ) );

$start = $gateway->converse( 'Create a new user.', $sessionCode );
$assert( (string) ( $start['status'] ?? '' ) === 'workflow_question', 'Incomplete create user requests should start a pending workflow.' );
$assert( (string) ( $start['message'] ?? '' ) === 'What is the user\'s name?', 'User workflow should ask for the name first.' );

$name = $gateway->converse( 'John Smith', $sessionCode );
$assert( (string) ( $name['status'] ?? '' ) === 'workflow_question', 'Name-only reply should continue the pending workflow.' );
$assert( (string) ( $name['message'] ?? '' ) === 'What is the user\'s email?', 'Workflow should ask for email after name.' );

$email = $gateway->converse( 'john@example.org', $sessionCode );
$assert( (string) ( $email['status'] ?? '' ) === 'workflow_question', 'Email reply should continue the pending workflow.' );
$assert( str_contains( (string) ( $email['message'] ?? '' ), 'What role should be assigned?' ), 'Workflow should ask for role after required fields are captured.' );

$review = $gateway->converse( 'Board Administrator', $sessionCode );
$actions = (array) ( $review['actions'] ?? [] );
$assert( (string) ( $review['status'] ?? '' ) === 'awaiting_approval', 'Completed workflow should re-enter the normal approval path.' );
$assert( (string) ( $review['response_type'] ?? '' ) === 'WorkflowReview', 'Completed workflow should return a workflow review response.' );
$assert( str_contains( (string) ( $review['message'] ?? '' ), 'Review:' ), 'Workflow review should summarize the collected fields.' );
$assert( count( $actions ) === 1, 'Completed workflow should queue a single approval action.' );

$continued = $gateway->converse( 'yes', $sessionCode );
$continuedAction = (array) ( $continued['action'] ?? [] );
$assert( (string) ( $continued['response_type'] ?? '' ) === 'WorkflowContinuationResult', 'Yes should continue from workflow review into action execution.' );
$assert( (string) ( $continuedAction['approval_status'] ?? '' ) === 'executed', 'Workflow approval continuation should execute the queued action.' );

$expiredSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfx', true ) ), 0, 8 ) );
$expiredStart = $gateway->converse( 'Create a new user.', $expiredSessionCode );
$assert( (string) ( $expiredStart['status'] ?? '' ) === 'workflow_question', 'Expired workflow fixture should begin as a normal workflow.' );

$db->update(
    \Metis_Tables::get( 'hermes_memory' ),
    [ 'updated_at' => date( 'Y-m-d H:i:s', time() - 1200 ) ],
    [ 'memory_key' => 'workflow:' . $expiredSessionCode ],
    [ '%s' ],
    [ '%s' ]
);

$expired = $gateway->converse( 'John Smith', $expiredSessionCode );
$assert( (string) ( $expired['status'] ?? '' ) === 'workflow_expired', 'Stale pending workflows should expire instead of continuing.' );
$assert( (string) ( $expired['response_type'] ?? '' ) === 'WorkflowExpiredPrompt', 'Stale pending workflows should return an expiration prompt.' );
$assert( $memory->recallPendingWorkflow( $expiredSessionCode ) === [], 'Expired workflows should be cleared from memory.' );

$workspaceSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfw', true ) ), 0, 8 ) );
$workspaceStart = $gateway->converse( 'Create a workspace user.', $workspaceSessionCode );
$assert( (string) ( $workspaceStart['status'] ?? '' ) === 'workflow_question', 'Workspace user requests should start the same pending workflow path.' );

$gateway->converse( 'Casey Workspace', $workspaceSessionCode );
$gateway->converse( 'casey.workspace@example.org', $workspaceSessionCode );
$workspaceReview = $gateway->converse( 'no role', $workspaceSessionCode );

$workspaceActions = (array) ( $workspaceReview['actions'] ?? [] );
$workspaceAction = (array) ( $workspaceActions[0] ?? [] );
$workspacePayload = (array) ( $workspaceAction['payload'] ?? [] );
$workspaceRequest = (array) ( $workspacePayload['command_payload']['user_request'] ?? [] );

$assert( (string) ( $workspaceReview['status'] ?? '' ) === 'awaiting_approval', 'Workspace user workflow should end in the normal approval state.' );
$assert( str_contains( (string) ( $workspaceReview['message'] ?? '' ), 'Create workspace user?' ), 'Workspace user workflow review should reflect the workspace operation.' );
$assert( (bool) ( $workspaceRequest['workspace_enabled'] ?? false ) === true, 'Workspace user workflow should force workspace-enabled user creation.' );

$passwordSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfp', true ) ), 0, 8 ) );
$passwordStart = $gateway->converse( 'Reset John Smith password.', $passwordSessionCode );
$assert( (string) ( $passwordStart['status'] ?? '' ) === 'workflow_question', 'Ambiguous password reset should start a pending workflow.' );
$assert( str_contains( (string) ( $passwordStart['message'] ?? '' ), 'Metis password or Workspace password' ), 'Ambiguous password reset should ask which password target to reset.' );

$passwordReview = $gateway->converse( 'Workspace password', $passwordSessionCode );
$passwordActions = (array) ( $passwordReview['actions'] ?? [] );
$passwordAction = (array) ( $passwordActions[0] ?? [] );
$passwordPayload = (array) ( $passwordAction['payload'] ?? [] );
$assert( (string) ( $passwordReview['status'] ?? '' ) === 'awaiting_approval', 'Completed password reset workflow should enter approval.' );
$assert( (string) ( $passwordReview['response_type'] ?? '' ) === 'WorkflowReview', 'Password reset workflow should return a review response.' );
$assert( (string) ( $passwordPayload['operation'] ?? '' ) === 'workspace_user_password_reset', 'Password reset workflow should canonicalize to the workspace password reset operation.' );

$backupSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfb', true ) ), 0, 8 ) );
$backupStart = $gateway->converse( 'Restore backup.', $backupSessionCode );
$assert( (string) ( $backupStart['status'] ?? '' ) === 'workflow_question', 'Incomplete backup restore should start a pending workflow.' );
$assert( (string) ( $backupStart['message'] ?? '' ) === 'Which backup run ID should be restored?', 'Backup restore workflow should ask for the backup run ID.' );

$backupReview = $gateway->converse( 'run_20260601_abc12345', $backupSessionCode );
$backupActions = (array) ( $backupReview['actions'] ?? [] );
$backupAction = (array) ( $backupActions[0] ?? [] );
$backupPayload = (array) ( $backupAction['payload'] ?? [] );
$assert( (string) ( $backupReview['status'] ?? '' ) === 'awaiting_approval', 'Completed backup restore workflow should enter approval.' );
$assert( (string) ( $backupPayload['operation'] ?? '' ) === 'backup_restore', 'Backup restore workflow should preserve the backup_restore operation key.' );
$assert( (string) ( $backupPayload['command_payload']['run_uuid'] ?? '' ) === 'run_20260601_abc12345', 'Backup restore workflow should carry the captured backup run ID into approval.' );

$backupValidateSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfv', true ) ), 0, 8 ) );
$backupValidateStart = $gateway->converse( 'Validate backup.', $backupValidateSessionCode );
$assert( (string) ( $backupValidateStart['status'] ?? '' ) === 'workflow_question', 'Incomplete backup validate should start a pending workflow.' );
$assert( (string) ( $backupValidateStart['message'] ?? '' ) === 'Which backup run ID should be validated?', 'Backup validate workflow should ask for the backup run ID.' );

$backupValidateReview = $gateway->converse( 'run_20260601_def67890', $backupValidateSessionCode );
$backupValidateActions = (array) ( $backupValidateReview['actions'] ?? [] );
$backupValidateAction = (array) ( $backupValidateActions[0] ?? [] );
$backupValidatePayload = (array) ( $backupValidateAction['payload'] ?? [] );
$assert( (string) ( $backupValidateReview['status'] ?? '' ) === 'awaiting_approval', 'Completed backup validate workflow should enter approval.' );
$assert( (string) ( $backupValidatePayload['operation'] ?? '' ) === 'backup_validate', 'Backup validate workflow should preserve the backup_validate operation key.' );
$assert( (string) ( $backupValidatePayload['command_payload']['run_uuid'] ?? '' ) === 'run_20260601_def67890', 'Backup validate workflow should carry the captured backup run ID into approval.' );

$restoreFileSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wff', true ) ), 0, 8 ) );
$restoreFileStart = $gateway->converse( 'Restore file.', $restoreFileSessionCode );
$assert( (string) ( $restoreFileStart['status'] ?? '' ) === 'workflow_question', 'Incomplete restore file should start a pending workflow.' );
$assert( (string) ( $restoreFileStart['message'] ?? '' ) === 'Which backup run ID contains the file to restore?', 'Restore file workflow should ask for the backup run ID first.' );

$restoreFilePathQuestion = $gateway->converse( 'run_20260601_file12345', $restoreFileSessionCode );
$assert( (string) ( $restoreFilePathQuestion['status'] ?? '' ) === 'workflow_question', 'Restore file workflow should ask for the file path after the run ID.' );
$assert( str_contains( (string) ( $restoreFilePathQuestion['message'] ?? '' ), 'Which file path should be restored?' ), 'Restore file workflow should ask for the backup-relative file path.' );

$restoreFileReview = $gateway->converse( 'storage/public-media/reports/summary.pdf', $restoreFileSessionCode );
$restoreFileActions = (array) ( $restoreFileReview['actions'] ?? [] );
$restoreFileAction = (array) ( $restoreFileActions[0] ?? [] );
$restoreFilePayload = (array) ( $restoreFileAction['payload'] ?? [] );
$assert( (string) ( $restoreFileReview['status'] ?? '' ) === 'awaiting_approval', 'Completed restore file workflow should enter approval.' );
$assert( (string) ( $restoreFilePayload['operation'] ?? '' ) === 'restore_file', 'Restore file workflow should preserve the restore_file operation key.' );
$assert( (string) ( $restoreFilePayload['command_payload']['run_uuid'] ?? '' ) === 'run_20260601_file12345', 'Restore file workflow should carry the captured backup run ID into approval.' );
$assert( (string) ( $restoreFilePayload['command_payload']['relative_path'] ?? '' ) === 'storage/public-media/reports/summary.pdf', 'Restore file workflow should carry the captured backup-relative path into approval.' );

$cronTaskSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfj', true ) ), 0, 8 ) );
$cronTaskStart = $gateway->converse( 'Create job.', $cronTaskSessionCode );
$assert( (string) ( $cronTaskStart['status'] ?? '' ) === 'workflow_question', 'Incomplete create job should start a pending workflow.' );
$assert( (string) ( $cronTaskStart['message'] ?? '' ) === 'Which cron task should be queued?', 'Create job workflow should ask for the cron task slug.' );

$cronTaskReview = $gateway->converse( 'module_compliance_audit', $cronTaskSessionCode );
$cronTaskActions = (array) ( $cronTaskReview['actions'] ?? [] );
$cronTaskAction = (array) ( $cronTaskActions[0] ?? [] );
$cronTaskPayload = (array) ( $cronTaskAction['payload'] ?? [] );
$assert( (string) ( $cronTaskReview['status'] ?? '' ) === 'awaiting_approval', 'Completed create job workflow should enter approval.' );
$assert( (string) ( $cronTaskPayload['operation'] ?? '' ) === 'create_job', 'Create job workflow should preserve the create_job operation key.' );
$assert( (string) ( $cronTaskPayload['command_payload']['task_slug'] ?? '' ) === 'module_compliance_audit', 'Create job workflow should carry the captured cron task slug into approval.' );

$cancelJobSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfcj', true ) ), 0, 8 ) );
$cancelJobStart = $gateway->converse( 'Cancel job.', $cancelJobSessionCode );
$assert( (string) ( $cancelJobStart['status'] ?? '' ) === 'workflow_question', 'Incomplete cancel job should start a pending workflow.' );
$assert( (string) ( $cancelJobStart['message'] ?? '' ) === 'Which job code should be canceled?', 'Cancel job workflow should ask for the job code.' );

$cancelJobReview = $gateway->converse( 'JOBABC123', $cancelJobSessionCode );
$cancelJobActions = (array) ( $cancelJobReview['actions'] ?? [] );
$cancelJobAction = (array) ( $cancelJobActions[0] ?? [] );
$cancelJobPayload = (array) ( $cancelJobAction['payload'] ?? [] );
$assert( (string) ( $cancelJobReview['status'] ?? '' ) === 'awaiting_approval', 'Completed cancel job workflow should enter approval.' );
$assert( (string) ( $cancelJobPayload['operation'] ?? '' ) === 'cancel_job', 'Cancel job workflow should preserve the cancel_job operation key.' );
$assert( (string) ( $cancelJobPayload['command_payload']['job_code'] ?? '' ) === 'JOBABC123', 'Cancel job workflow should carry the captured job code into approval.' );

$retryJobSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfrj', true ) ), 0, 8 ) );
$retryJobStart = $gateway->converse( 'Retry job.', $retryJobSessionCode );
$assert( (string) ( $retryJobStart['status'] ?? '' ) === 'workflow_question', 'Incomplete retry job should start a pending workflow.' );
$assert( (string) ( $retryJobStart['message'] ?? '' ) === 'Which job code should be retried?', 'Retry job workflow should ask for the job code.' );

$retryJobReview = $gateway->converse( 'JOBXYZ789', $retryJobSessionCode );
$retryJobActions = (array) ( $retryJobReview['actions'] ?? [] );
$retryJobAction = (array) ( $retryJobActions[0] ?? [] );
$retryJobPayload = (array) ( $retryJobAction['payload'] ?? [] );
$assert( (string) ( $retryJobReview['status'] ?? '' ) === 'awaiting_approval', 'Completed retry job workflow should enter approval.' );
$assert( (string) ( $retryJobPayload['operation'] ?? '' ) === 'retry_job', 'Retry job workflow should preserve the retry_job operation key.' );
$assert( (string) ( $retryJobPayload['command_payload']['job_code'] ?? '' ) === 'JOBXYZ789', 'Retry job workflow should carry the captured job code into approval.' );

$unlockUserSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wful', true ) ), 0, 8 ) );
$unlockUserStart = $gateway->converse( 'Unlock user.', $unlockUserSessionCode );
$assert( (string) ( $unlockUserStart['status'] ?? '' ) === 'workflow_question', 'Incomplete unlock user should start a pending workflow.' );
$assert( (string) ( $unlockUserStart['message'] ?? '' ) === 'Which user should be unlocked?', 'Unlock user workflow should ask for the target user.' );

$unlockUserReview = $gateway->converse( 'Riley Adams', $unlockUserSessionCode );
$unlockUserActions = (array) ( $unlockUserReview['actions'] ?? [] );
$unlockUserAction = (array) ( $unlockUserActions[0] ?? [] );
$unlockUserPayload = (array) ( $unlockUserAction['payload'] ?? [] );
$assert( (string) ( $unlockUserReview['status'] ?? '' ) === 'awaiting_approval', 'Completed unlock user workflow should enter approval.' );
$assert( (string) ( $unlockUserPayload['operation'] ?? '' ) === 'user_unlock', 'Unlock user workflow should preserve the user_unlock operation key.' );
$assert( (string) ( $unlockUserPayload['command_payload']['subject'] ?? '' ) === 'Riley Adams', 'Unlock user workflow should carry the captured user subject into approval.' );

$workspaceDisableSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfwd', true ) ), 0, 8 ) );
$workspaceDisableStart = $gateway->converse( 'Disable workspace access.', $workspaceDisableSessionCode );
$assert( (string) ( $workspaceDisableStart['status'] ?? '' ) === 'workflow_question', 'Incomplete workspace disable should start a pending workflow.' );
$assert( (string) ( $workspaceDisableStart['message'] ?? '' ) === 'Which user should have Workspace access disabled?', 'Workspace disable workflow should ask for the target user.' );

$workspaceDisableReview = $gateway->converse( 'Taylor Example', $workspaceDisableSessionCode );
$workspaceDisableActions = (array) ( $workspaceDisableReview['actions'] ?? [] );
$workspaceDisableAction = (array) ( $workspaceDisableActions[0] ?? [] );
$workspaceDisablePayload = (array) ( $workspaceDisableAction['payload'] ?? [] );
$assert( (string) ( $workspaceDisableReview['status'] ?? '' ) === 'awaiting_approval', 'Completed workspace disable workflow should enter approval.' );
$assert( (string) ( $workspaceDisablePayload['operation'] ?? '' ) === 'workspace_user_disable', 'Workspace disable workflow should preserve the workspace_user_disable operation key.' );
$assert( (string) ( $workspaceDisablePayload['command_payload']['subject'] ?? '' ) === 'Taylor Example', 'Workspace disable workflow should carry the captured user subject into approval.' );

$mfaResetSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfmf', true ) ), 0, 8 ) );
$mfaResetStart = $gateway->converse( 'Reset mfa.', $mfaResetSessionCode );
$assert( (string) ( $mfaResetStart['status'] ?? '' ) === 'workflow_question', 'Incomplete MFA reset should start a pending workflow.' );
$assert( (string) ( $mfaResetStart['message'] ?? '' ) === 'Which user should have MFA reset?', 'MFA reset workflow should ask for the target user.' );

$mfaResetReview = $gateway->converse( 'Jordan Example', $mfaResetSessionCode );
$mfaResetActions = (array) ( $mfaResetReview['actions'] ?? [] );
$mfaResetAction = (array) ( $mfaResetActions[0] ?? [] );
$mfaResetPayload = (array) ( $mfaResetAction['payload'] ?? [] );
$assert( (string) ( $mfaResetReview['status'] ?? '' ) === 'awaiting_approval', 'Completed MFA reset workflow should enter approval.' );
$assert( (string) ( $mfaResetPayload['operation'] ?? '' ) === 'reset_user_mfa', 'MFA reset workflow should preserve the reset_user_mfa operation key.' );
$assert( (string) ( $mfaResetPayload['command_payload']['subject'] ?? '' ) === 'Jordan Example', 'MFA reset workflow should carry the captured user subject into approval.' );

$assignRoleSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfar', true ) ), 0, 8 ) );
$assignRoleStart = $gateway->converse( 'Assign role.', $assignRoleSessionCode );
$assert( (string) ( $assignRoleStart['status'] ?? '' ) === 'workflow_question', 'Incomplete assign role should start a pending workflow.' );
$assert( (string) ( $assignRoleStart['message'] ?? '' ) === 'Which user should have roles updated?', 'Assign role workflow should ask for the target user first.' );

$assignRoleQuestion = $gateway->converse( 'Morgan Example', $assignRoleSessionCode );
$assert( (string) ( $assignRoleQuestion['status'] ?? '' ) === 'workflow_question', 'Assign role workflow should ask for roles after the user.' );
$assert( (string) ( $assignRoleQuestion['message'] ?? '' ) === 'Which role should be assigned?', 'Assign role workflow should ask for the role values.' );

$assignRoleReview = $gateway->converse( 'administrator', $assignRoleSessionCode );
$assignRoleActions = (array) ( $assignRoleReview['actions'] ?? [] );
$assignRoleAction = (array) ( $assignRoleActions[0] ?? [] );
$assignRolePayload = (array) ( $assignRoleAction['payload'] ?? [] );
$assert( (string) ( $assignRoleReview['status'] ?? '' ) === 'awaiting_approval', 'Completed assign role workflow should enter approval.' );
$assert( (string) ( $assignRolePayload['operation'] ?? '' ) === 'assign_role', 'Assign role workflow should preserve the assign_role operation key.' );
$assert( (string) ( $assignRolePayload['command_payload']['subject'] ?? '' ) === 'Morgan Example', 'Assign role workflow should carry the captured user subject into approval.' );
$assert( (array) ( $assignRolePayload['command_payload']['roles'] ?? [] ) === [ 'administrator' ], 'Assign role workflow should carry the captured role keys into approval.' );

$workspaceGroupSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfwg', true ) ), 0, 8 ) );
$workspaceGroupStart = $gateway->converse( 'Add workspace group.', $workspaceGroupSessionCode );
$assert( (string) ( $workspaceGroupStart['status'] ?? '' ) === 'workflow_question', 'Incomplete Workspace group update should start a pending workflow.' );
$assert( (string) ( $workspaceGroupStart['message'] ?? '' ) === 'Which user should have Workspace groups updated?', 'Workspace group workflow should ask for the target user first.' );

$workspaceGroupQuestion = $gateway->converse( 'Skyler Example', $workspaceGroupSessionCode );
$assert( (string) ( $workspaceGroupQuestion['status'] ?? '' ) === 'workflow_question', 'Workspace group workflow should ask for group emails after the user.' );
$assert( (string) ( $workspaceGroupQuestion['message'] ?? '' ) === 'Which Workspace group email should be updated?', 'Workspace group workflow should ask for Workspace group emails.' );

$workspaceGroupReview = $gateway->converse( 'finance-team@example.org', $workspaceGroupSessionCode );
$workspaceGroupActions = (array) ( $workspaceGroupReview['actions'] ?? [] );
$workspaceGroupAction = (array) ( $workspaceGroupActions[0] ?? [] );
$workspaceGroupPayload = (array) ( $workspaceGroupAction['payload'] ?? [] );
$assert( (string) ( $workspaceGroupReview['status'] ?? '' ) === 'awaiting_approval', 'Completed Workspace group workflow should enter approval.' );
$assert( (string) ( $workspaceGroupPayload['operation'] ?? '' ) === 'manage_workspace_groups', 'Workspace group workflow should preserve the manage_workspace_groups operation key.' );
$assert( (string) ( $workspaceGroupPayload['command_payload']['subject'] ?? '' ) === 'Skyler Example', 'Workspace group workflow should carry the captured user subject into approval.' );
$assert( (array) ( $workspaceGroupPayload['command_payload']['group_emails'] ?? [] ) === [ 'finance-team@example.org' ], 'Workspace group workflow should carry the captured group emails into approval.' );

$boardWorkspaceSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfbw', true ) ), 0, 8 ) );
$boardWorkspaceStart = $gateway->converse( 'Prepare board workspace.', $boardWorkspaceSessionCode );
$assert( (string) ( $boardWorkspaceStart['status'] ?? '' ) === 'workflow_question', 'Incomplete board workspace prepare should start a pending workflow.' );
$assert( (string) ( $boardWorkspaceStart['message'] ?? '' ) === 'Which board meeting workspace should be prepared?', 'Board workspace workflow should ask for the meeting reference.' );

$boardWorkspaceReview = $gateway->converse( 'Q3-2026', $boardWorkspaceSessionCode );
$boardWorkspaceActions = (array) ( $boardWorkspaceReview['actions'] ?? [] );
$boardWorkspaceAction = (array) ( $boardWorkspaceActions[0] ?? [] );
$boardWorkspacePayload = (array) ( $boardWorkspaceAction['payload'] ?? [] );
$assert( (string) ( $boardWorkspaceReview['status'] ?? '' ) === 'awaiting_approval', 'Completed board workspace workflow should enter approval.' );
$assert( (string) ( $boardWorkspacePayload['operation'] ?? '' ) === 'board_workspace_prepare', 'Board workspace workflow should preserve the board_workspace_prepare operation key.' );
$assert( (string) ( $boardWorkspacePayload['command_payload']['subject'] ?? '' ) === 'Q3-2026', 'Board workspace workflow should carry the captured meeting reference into approval.' );

$newsletterCancelSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfn', true ) ), 0, 8 ) );
$newsletterCancelStart = $gateway->converse( 'Cancel newsletter.', $newsletterCancelSessionCode );
$assert( (string) ( $newsletterCancelStart['status'] ?? '' ) === 'workflow_question', 'Incomplete newsletter cancel should start a pending workflow.' );
$assert( (string) ( $newsletterCancelStart['message'] ?? '' ) === 'Which newsletter should be canceled?', 'Newsletter cancel workflow should ask for the newsletter reference.' );

$newsletterCancelReview = $gateway->converse( 'June Board Update', $newsletterCancelSessionCode );
$newsletterCancelActions = (array) ( $newsletterCancelReview['actions'] ?? [] );
$newsletterCancelAction = (array) ( $newsletterCancelActions[0] ?? [] );
$newsletterCancelPayload = (array) ( $newsletterCancelAction['payload'] ?? [] );
$assert( (string) ( $newsletterCancelReview['status'] ?? '' ) === 'awaiting_approval', 'Completed newsletter cancel workflow should enter approval.' );
$assert( (string) ( $newsletterCancelPayload['operation'] ?? '' ) === 'newsletter_cancel', 'Newsletter cancel workflow should preserve the newsletter_cancel operation key.' );
$assert( (string) ( $newsletterCancelPayload['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Newsletter cancel workflow should carry the captured newsletter reference into approval.' );

$newsletterScheduleSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfs', true ) ), 0, 8 ) );
$newsletterScheduleStart = $gateway->converse( 'Schedule newsletter.', $newsletterScheduleSessionCode );
$assert( (string) ( $newsletterScheduleStart['status'] ?? '' ) === 'workflow_question', 'Incomplete newsletter schedule should start a pending workflow.' );
$assert( (string) ( $newsletterScheduleStart['message'] ?? '' ) === 'Which newsletter should be scheduled?', 'Newsletter schedule workflow should ask for the newsletter reference first.' );

$newsletterScheduleTime = $gateway->converse( 'Monthly Donor Update', $newsletterScheduleSessionCode );
$assert( (string) ( $newsletterScheduleTime['status'] ?? '' ) === 'workflow_question', 'Newsletter schedule should ask for the schedule time after the reference.' );
$assert( (string) ( $newsletterScheduleTime['message'] ?? '' ) === 'When should the newsletter be scheduled?', 'Newsletter schedule workflow should ask for the schedule time.' );

$newsletterScheduleReview = $gateway->converse( '2026-06-15 09:00:00', $newsletterScheduleSessionCode );
$newsletterScheduleActions = (array) ( $newsletterScheduleReview['actions'] ?? [] );
$newsletterScheduleAction = (array) ( $newsletterScheduleActions[0] ?? [] );
$newsletterSchedulePayload = (array) ( $newsletterScheduleAction['payload'] ?? [] );
$assert( (string) ( $newsletterScheduleReview['status'] ?? '' ) === 'awaiting_approval', 'Completed newsletter schedule workflow should enter approval.' );
$assert( (string) ( $newsletterSchedulePayload['operation'] ?? '' ) === 'newsletter_schedule', 'Newsletter schedule workflow should preserve the newsletter_schedule operation key.' );
$assert( (string) ( $newsletterSchedulePayload['command_payload']['subject'] ?? '' ) === 'Monthly Donor Update', 'Newsletter schedule workflow should carry the captured newsletter reference into approval.' );
$assert( (string) ( $newsletterSchedulePayload['command_payload']['scheduled_at'] ?? '' ) === '2026-06-15 09:00:00', 'Newsletter schedule workflow should carry the captured schedule time into approval.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes pending workflow checks passed.\n" );
