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

$seedRecentEntitySession = static function ( string $prompt ) use ( $state ): string {
    $sessionCode = 'TESTENTITY' . strtoupper( substr( md5( uniqid( 'entity', true ) ), 0, 8 ) );
    $turn = $state->openTurn( 0, 'Show me John Smith', $sessionCode );
    $session = (array) ( $turn['session'] ?? [] );
    $processed = [
        'intent' => [
            'action' => 'lookup_profile',
            'top_level_intent' => 'LOOKUP',
            'payload' => [
                'profile_request' => [
                    'subject' => 'John Smith',
                    'entity_hint' => 'person',
                ],
            ],
        ],
    ];
    $response = [
        'status' => 'success',
        'response_type' => 'ProfileLookup',
        'message' => 'Found John Smith.',
        'entity' => 'person',
        'id' => 42,
    ];

    $state->completeTurn( $session, 'Show me John Smith', $processed, $response );

    return $sessionCode;
};

$seedContentEntitySession = static function ( string $subject, string $entityHint ) use ( $state ): string {
    $sessionCode = 'TESTENTITY' . strtoupper( substr( md5( uniqid( 'entity', true ) ), 0, 8 ) );
    $turn = $state->openTurn( 0, 'Show me ' . $subject, $sessionCode );
    $session = (array) ( $turn['session'] ?? [] );
    $processed = [
        'intent' => [
            'action' => 'lookup_profile',
            'top_level_intent' => 'LOOKUP',
            'payload' => [
                'profile_request' => [
                    'subject' => $subject,
                    'entity_hint' => $entityHint,
                ],
            ],
        ],
    ];
    $response = [
        'status' => 'success',
        'response_type' => 'ProfileLookup',
        'message' => 'Found ' . $subject . '.',
        'entity' => $entityHint,
        'id' => 42,
    ];

    $state->completeTurn( $session, 'Show me ' . $subject, $processed, $response );

    return $sessionCode;
};

$state = \Metis\Core\Application::service( 'hermes_conversation_state' );
$memory = \Metis\Core\Application::service( 'hermes_memory_store' );
$parser = \Metis\Core\Application::service( 'hermes_conversational_parser' );
$gateway = \Metis\Core\Application::service( 'hermes_gateway' );

$sessionCode = $seedRecentEntitySession( 'Show me John Smith' );
$turn = $state->openTurn( 0, 'hydrate recent entity', $sessionCode );
$session = (array) ( $turn['session'] ?? [] );

$recentEntity = $memory->recallRecentEntity( $sessionCode );
$hydrated = $state->hydrateRuntimeContext( $session, [] );
$attributeFollowUp = $parser->parse( 'what is his email', $sessionCode );
$actionFollowUp = $parser->parse( 'disable that user', $sessionCode );
$workspaceResetFollowUp = $parser->parse( 'reset his workspace password', $sessionCode );
$genericResetFollowUp = $parser->parse( 'reset his password', $sessionCode );
$enableFollowUp = $parser->parse( 'enable that user', $sessionCode );
$updateFollowUp = $parser->parse( 'update that user', $sessionCode );
$workspaceEnableFollowUp = $parser->parse( 'enable workspace access', $sessionCode );
$workspaceUpdateFollowUp = $parser->parse( 'update workspace access', $sessionCode );
$workspaceResetDirectFollowUp = $parser->parse( 'reset workspace password', $sessionCode );
$workspaceCreateFollowUp = $parser->parse( 'create workspace access', $sessionCode );
$workspaceDisableFollowUp = $parser->parse( 'disable workspace access', $sessionCode );
$assignRoleFollowUp = $parser->parse( 'assign role administrator', $sessionCode );
$removeRoleFollowUp = $parser->parse( 'remove role administrator', $sessionCode );
$workspaceGroupFollowUp = $parser->parse( 'remove workspace group finance-team@example.org', $sessionCode );
$driveFolderFollowUp = $parser->parse( 'link drive folder id 1AbCdEfGhIJkLmNoPqRsTuVwXyZ', $sessionCode );
$contentSessionCode = $seedContentEntitySession( 'June Board Update', 'campaign' );
$newsletterCancelFollowUp = $parser->parse( 'cancel newsletter', $contentSessionCode );
$newsletterScheduleFollowUp = $parser->parse( 'schedule newsletter', $contentSessionCode );
$campaignPublishFollowUp = $parser->parse( 'publish campaign', $contentSessionCode );
$campaignArchiveFollowUp = $parser->parse( 'archive campaign', $contentSessionCode );
$newsletterCancelContextFollowUp = $parser->parse( 'cancel that newsletter', $contentSessionCode );
$newsletterScheduleContextFollowUp = $parser->parse( 'schedule that newsletter', $contentSessionCode );
$campaignPublishContextFollowUp = $parser->parse( 'publish that campaign', $contentSessionCode );
$campaignArchiveContextFollowUp = $parser->parse( 'archive that campaign', $contentSessionCode );
$newsletterDeleteContextFollowUp = $parser->parse( 'delete that newsletter', $contentSessionCode );
$newsletterSendContextFollowUp = $parser->parse( 'send that newsletter', $contentSessionCode );
$disableGatewayFollowUp = $gateway->converse( 'disable that user', $seedRecentEntitySession( 'Show me John Smith' ) );
$enableGatewayFollowUp = $gateway->converse( 'enable that user', $seedRecentEntitySession( 'Show me John Smith' ) );
$updateGatewayFollowUp = $gateway->converse( 'update that user', $seedRecentEntitySession( 'Show me John Smith' ) );
$unlockGatewayFollowUp = $gateway->converse( 'unlock user', $seedRecentEntitySession( 'Show me John Smith' ) );
$mfaGatewayFollowUp = $gateway->converse( 'reset mfa', $seedRecentEntitySession( 'Show me John Smith' ) );
$deleteGatewayFollowUp = $gateway->converse( 'delete user', $seedRecentEntitySession( 'Show me John Smith' ) );
$workspaceEnableGatewayFollowUp = $gateway->converse( 'enable workspace access', $seedRecentEntitySession( 'Show me John Smith' ) );
$workspaceUpdateGatewayFollowUp = $gateway->converse( 'update workspace access', $seedRecentEntitySession( 'Show me John Smith' ) );
$genericResetGatewayFollowUp = $gateway->converse( 'reset password', $seedRecentEntitySession( 'Show me John Smith' ) );
$pronounResetGatewayFollowUp = $gateway->converse( 'reset his password', $seedRecentEntitySession( 'Show me John Smith' ) );
$workspaceResetGatewayFollowUp = $gateway->converse( 'reset workspace password', $seedRecentEntitySession( 'Show me John Smith' ) );
$workspaceCreateGatewayFollowUp = $gateway->converse( 'create workspace access', $seedRecentEntitySession( 'Show me John Smith' ) );
$workspaceDisableGatewayFollowUp = $gateway->converse( 'disable workspace access', $seedRecentEntitySession( 'Show me John Smith' ) );
$assignRoleGatewayFollowUp = $gateway->converse( 'assign role administrator', $seedRecentEntitySession( 'Show me John Smith' ) );
$removeRoleGatewayFollowUp = $gateway->converse( 'remove role administrator', $seedRecentEntitySession( 'Show me John Smith' ) );
$workspaceGroupGatewayFollowUp = $gateway->converse( 'remove workspace group finance-team@example.org', $seedRecentEntitySession( 'Show me John Smith' ) );
$driveFolderGatewayFollowUp = $gateway->converse( 'link drive folder id 1AbCdEfGhIJkLmNoPqRsTuVwXyZ', $seedRecentEntitySession( 'Show me John Smith' ) );
$newsletterCancelGatewayFollowUp = $gateway->converse( 'cancel newsletter', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$newsletterScheduleGatewayFollowUp = $gateway->converse( 'schedule newsletter', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$campaignPublishGatewayFollowUp = $gateway->converse( 'publish campaign', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$campaignArchiveGatewayFollowUp = $gateway->converse( 'archive campaign', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$newsletterCancelContextGatewayFollowUp = $gateway->converse( 'cancel that newsletter', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$newsletterScheduleContextGatewayFollowUp = $gateway->converse( 'schedule that newsletter', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$campaignPublishContextGatewayFollowUp = $gateway->converse( 'publish that campaign', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$campaignArchiveContextGatewayFollowUp = $gateway->converse( 'archive that campaign', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$newsletterDeleteContextGatewayFollowUp = $gateway->converse( 'delete that newsletter', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$newsletterSendContextGatewayFollowUp = $gateway->converse( 'send that newsletter', $seedContentEntitySession( 'June Board Update', 'campaign' ) );
$passwordContinuationSessionCode = $seedRecentEntitySession( 'Show me John Smith' );
$passwordContinuationStart = $gateway->converse( 'reset his password', $passwordContinuationSessionCode );
$passwordContinuationReview = $gateway->converse( 'Workspace password', $passwordContinuationSessionCode );
$passwordCancellationSessionCode = $seedRecentEntitySession( 'Show me John Smith' );
$passwordCancellationStart = $gateway->converse( 'reset his password', $passwordCancellationSessionCode );
$passwordCancellationResult = $gateway->converse( 'no', $passwordCancellationSessionCode );
$newsletterContinuationSessionCode = $seedContentEntitySession( 'June Board Update', 'campaign' );
$newsletterContinuationStart = $gateway->converse( 'schedule that newsletter', $newsletterContinuationSessionCode );
$newsletterContinuationReview = $gateway->converse( '2026-06-15 09:00:00', $newsletterContinuationSessionCode );
$newsletterCancellationSessionCode = $seedContentEntitySession( 'June Board Update', 'campaign' );
$newsletterCancellationStart = $gateway->converse( 'schedule that newsletter', $newsletterCancellationSessionCode );
$newsletterCancellationResult = $gateway->converse( 'no', $newsletterCancellationSessionCode );
$approvalContinuationPeopleSessionCode = $seedRecentEntitySession( 'Show me John Smith' );
$approvalContinuationPeoplePrompt = $gateway->converse( 'assign role administrator', $approvalContinuationPeopleSessionCode );
$approvalContinuationPeopleResult = $gateway->converse( 'yes', $approvalContinuationPeopleSessionCode );
$approvalRejectionPeopleSessionCode = $seedRecentEntitySession( 'Show me John Smith' );
$approvalRejectionPeoplePrompt = $gateway->converse( 'assign role administrator', $approvalRejectionPeopleSessionCode );
$approvalRejectionPeopleResult = $gateway->converse( 'no', $approvalRejectionPeopleSessionCode );
$approvalContinuationContentSessionCode = $seedContentEntitySession( 'June Board Update', 'campaign' );
$approvalContinuationContentPrompt = $gateway->converse( 'cancel that newsletter', $approvalContinuationContentSessionCode );
$approvalContinuationContentResult = $gateway->converse( 'yes', $approvalContinuationContentSessionCode );
$approvalRejectionContentSessionCode = $seedContentEntitySession( 'June Board Update', 'campaign' );
$approvalRejectionContentPrompt = $gateway->converse( 'cancel that newsletter', $approvalRejectionContentSessionCode );
$approvalRejectionContentResult = $gateway->converse( 'no', $approvalRejectionContentSessionCode );
$passwordExpirySessionCode = $seedRecentEntitySession( 'Show me John Smith' );
$passwordExpiryStart = $gateway->converse( 'reset his password', $passwordExpirySessionCode );
$db->update(
    \Metis_Tables::get( 'hermes_memory' ),
    [ 'updated_at' => date( 'Y-m-d H:i:s', time() - 1200 ) ],
    [ 'memory_key' => 'workflow:' . $passwordExpirySessionCode ],
    [ '%s' ],
    [ '%s' ]
);
$passwordExpiryResume = $gateway->converse( 'Workspace password', $passwordExpirySessionCode );
$newsletterExpirySessionCode = $seedContentEntitySession( 'June Board Update', 'campaign' );
$newsletterExpiryStart = $gateway->converse( 'schedule that newsletter', $newsletterExpirySessionCode );
$db->update(
    \Metis_Tables::get( 'hermes_memory' ),
    [ 'updated_at' => date( 'Y-m-d H:i:s', time() - 1200 ) ],
    [ 'memory_key' => 'workflow:' . $newsletterExpirySessionCode ],
    [ '%s' ],
    [ '%s' ]
);
$newsletterExpiryResume = $gateway->converse( '2026-06-15 09:00:00', $newsletterExpirySessionCode );
$approvalExpirySessionCode = $seedRecentEntitySession( 'Show me John Smith' );
$approvalExpiryPrompt = $gateway->converse( 'assign role administrator', $approvalExpirySessionCode );
$db->update(
    \Metis_Tables::get( 'hermes_actions' ),
    [ 'created_at' => date( 'Y-m-d H:i:s', time() - 1200 ) ],
    [
        'session_id' => (int) ( $approvalExpiryPrompt['session']['id'] ?? 0 ),
        'approval_status' => 'pending',
    ],
    [ '%s' ],
    [ '%d', '%s' ]
);
$approvalExpiryResult = $gateway->converse( 'yes', $approvalExpirySessionCode );

$assert( (string) ( $recentEntity['subject'] ?? '' ) === 'John Smith', 'Recent entity memory should persist the latest resolved subject.' );
$assert( (string) ( $recentEntity['entity_hint'] ?? '' ) === 'person', 'Recent entity memory should retain the entity hint.' );
$assert( (string) ( $hydrated['recent_entity']['subject'] ?? '' ) === 'John Smith', 'Hydrated runtime context should include the recent entity.' );
$assert( (string) ( $attributeFollowUp['selected_intent'] ?? '' ) === 'get_entity_attribute', 'Pronoun attribute follow-up should resolve to get_entity_attribute.' );
$assert( (string) ( $attributeFollowUp['intents'][0]['payload']['attribute_request']['subject'] ?? '' ) === 'John Smith', 'Pronoun attribute follow-up should reuse the recent entity subject.' );
$assert( (string) ( $actionFollowUp['selected_intent'] ?? '' ) === 'disable_user', 'Contextual action follow-up should resolve to disable_user.' );
$assert( (string) ( $actionFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual action follow-up should reuse the recent entity subject.' );
$assert( empty( $actionFollowUp['requires_clarification'] ), 'Contextual action follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $workspaceResetFollowUp['selected_intent'] ?? '' ) === 'workspace_user_password_reset', 'Contextual workspace password reset follow-up should resolve to workspace_user_password_reset.' );
$assert( (string) ( $workspaceResetFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual workspace password reset should reuse the recent entity subject.' );
$assert( empty( $workspaceResetFollowUp['requires_clarification'] ), 'Scoped workspace password reset follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $genericResetFollowUp['selected_intent'] ?? '' ) === 'user_password_reset', 'Generic password reset follow-up should still resolve to user_password_reset before workflow clarification.' );
$assert( (string) ( $genericResetFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Generic password reset follow-up should reuse the recent entity subject.' );
$assert( empty( $genericResetFollowUp['requires_clarification'] ), 'Generic password reset follow-up should gain enough confidence from recent entity context.' );
$assert( (string) ( $enableFollowUp['selected_intent'] ?? '' ) === 'enable_user', 'Contextual enable follow-up should resolve to enable_user.' );
$assert( (string) ( $enableFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual enable follow-up should reuse the recent entity subject.' );
$assert( empty( $enableFollowUp['requires_clarification'] ), 'Contextual enable follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $updateFollowUp['selected_intent'] ?? '' ) === 'update_user', 'Contextual update follow-up should resolve to update_user.' );
$assert( (string) ( $updateFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual update follow-up should reuse the recent entity subject.' );
$assert( empty( $updateFollowUp['requires_clarification'] ), 'Contextual update follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $workspaceEnableFollowUp['selected_intent'] ?? '' ) === 'workspace_user_enable', 'Workspace enable follow-up should resolve to workspace_user_enable.' );
$assert( (string) ( $workspaceEnableFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Workspace enable follow-up should reuse the recent entity subject.' );
$assert( empty( $workspaceEnableFollowUp['requires_clarification'] ), 'Workspace enable follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $workspaceUpdateFollowUp['selected_intent'] ?? '' ) === 'workspace_user_update', 'Workspace update follow-up should resolve to workspace_user_update.' );
$assert( (string) ( $workspaceUpdateFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Workspace update follow-up should reuse the recent entity subject.' );
$assert( empty( $workspaceUpdateFollowUp['requires_clarification'] ), 'Workspace update follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $workspaceResetDirectFollowUp['selected_intent'] ?? '' ) === 'workspace_user_password_reset', 'Direct workspace reset follow-up should resolve to workspace_user_password_reset.' );
$assert( (string) ( $workspaceResetDirectFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Direct workspace reset follow-up should reuse the recent entity subject.' );
$assert( empty( $workspaceResetDirectFollowUp['requires_clarification'] ), 'Direct workspace reset follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $workspaceCreateFollowUp['selected_intent'] ?? '' ) === 'workspace_user_create', 'Workspace create follow-up should resolve to workspace_user_create.' );
$assert( empty( $workspaceCreateFollowUp['requires_clarification'] ), 'Workspace create follow-up should resolve confidently before workflow collection.' );
$assert( (string) ( $workspaceDisableFollowUp['selected_intent'] ?? '' ) === 'workspace_user_disable', 'Workspace disable follow-up should resolve to workspace_user_disable.' );
$assert( (string) ( $workspaceDisableFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Workspace disable follow-up should reuse the recent entity subject.' );
$assert( empty( $workspaceDisableFollowUp['requires_clarification'] ), 'Workspace disable follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $assignRoleFollowUp['selected_intent'] ?? '' ) === 'assign_role', 'Role assignment follow-up should resolve to assign_role.' );
$assert( (string) ( $assignRoleFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Role assignment follow-up should reuse the recent entity subject.' );
$assert( (array) ( $assignRoleFollowUp['intents'][0]['payload']['roles'] ?? [] ) === [ 'administrator' ], 'Role assignment follow-up should preserve the requested role list.' );
$assert( empty( $assignRoleFollowUp['requires_clarification'] ), 'Role assignment follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $removeRoleFollowUp['selected_intent'] ?? '' ) === 'remove_role', 'Role removal follow-up should resolve to remove_role.' );
$assert( (string) ( $removeRoleFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Role removal follow-up should reuse the recent entity subject.' );
$assert( (array) ( $removeRoleFollowUp['intents'][0]['payload']['roles'] ?? [] ) === [ 'administrator' ], 'Role removal follow-up should preserve the requested role list.' );
$assert( empty( $removeRoleFollowUp['requires_clarification'] ), 'Role removal follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $workspaceGroupFollowUp['selected_intent'] ?? '' ) === 'manage_workspace_groups', 'Workspace group follow-up should resolve to manage_workspace_groups.' );
$assert( (string) ( $workspaceGroupFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Workspace group follow-up should reuse the recent entity subject.' );
$assert( (array) ( $workspaceGroupFollowUp['intents'][0]['payload']['group_emails'] ?? [] ) === [ 'finance-team@example.org' ], 'Workspace group follow-up should preserve the requested group emails.' );
$assert( (string) ( $workspaceGroupFollowUp['intents'][0]['payload']['mode'] ?? '' ) === 'remove', 'Workspace group follow-up should preserve remove mode.' );
$assert( empty( $workspaceGroupFollowUp['requires_clarification'] ), 'Workspace group follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $driveFolderFollowUp['selected_intent'] ?? '' ) === 'link_drive_folder', 'Drive folder follow-up should resolve to link_drive_folder.' );
$assert( (string) ( $driveFolderFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Drive folder follow-up should reuse the recent entity subject.' );
$assert( (string) ( $driveFolderFollowUp['intents'][0]['payload']['folder_id'] ?? '' ) === '1abcdefghijklmnopqrstuvwxyz', 'Drive folder follow-up should preserve the requested folder ID.' );
$assert( empty( $driveFolderFollowUp['requires_clarification'] ), 'Drive folder follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $newsletterCancelFollowUp['selected_intent'] ?? '' ) === 'newsletter_cancel', 'Newsletter cancel follow-up should resolve to newsletter_cancel.' );
$assert( (string) ( $newsletterCancelFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Newsletter cancel follow-up should reuse the recent campaign subject.' );
$assert( empty( $newsletterCancelFollowUp['requires_clarification'] ), 'Newsletter cancel follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $newsletterScheduleFollowUp['selected_intent'] ?? '' ) === 'newsletter_schedule', 'Newsletter schedule follow-up should resolve to newsletter_schedule.' );
$assert( (string) ( $newsletterScheduleFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Newsletter schedule follow-up should reuse the recent campaign subject.' );
$assert( empty( $newsletterScheduleFollowUp['requires_clarification'] ), 'Newsletter schedule follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $campaignPublishFollowUp['selected_intent'] ?? '' ) === 'campaign_publish', 'Campaign publish follow-up should resolve to campaign_publish.' );
$assert( (string) ( $campaignPublishFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Campaign publish follow-up should reuse the recent campaign subject.' );
$assert( empty( $campaignPublishFollowUp['requires_clarification'] ), 'Campaign publish follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $campaignArchiveFollowUp['selected_intent'] ?? '' ) === 'campaign_archive', 'Campaign archive follow-up should resolve to campaign_archive.' );
$assert( (string) ( $campaignArchiveFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Campaign archive follow-up should reuse the recent campaign subject.' );
$assert( empty( $campaignArchiveFollowUp['requires_clarification'] ), 'Campaign archive follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $newsletterCancelContextFollowUp['selected_intent'] ?? '' ) === 'newsletter_cancel', 'Contextual newsletter cancel follow-up should resolve to newsletter_cancel.' );
$assert( (string) ( $newsletterCancelContextFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual newsletter cancel follow-up should reuse the recent campaign subject.' );
$assert( empty( $newsletterCancelContextFollowUp['requires_clarification'] ), 'Contextual newsletter cancel follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $newsletterScheduleContextFollowUp['selected_intent'] ?? '' ) === 'newsletter_schedule', 'Contextual newsletter schedule follow-up should resolve to newsletter_schedule.' );
$assert( (string) ( $newsletterScheduleContextFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual newsletter schedule follow-up should reuse the recent campaign subject.' );
$assert( empty( $newsletterScheduleContextFollowUp['requires_clarification'] ), 'Contextual newsletter schedule follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $campaignPublishContextFollowUp['selected_intent'] ?? '' ) === 'campaign_publish', 'Contextual campaign publish follow-up should resolve to campaign_publish.' );
$assert( (string) ( $campaignPublishContextFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual campaign publish follow-up should reuse the recent campaign subject.' );
$assert( empty( $campaignPublishContextFollowUp['requires_clarification'] ), 'Contextual campaign publish follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $campaignArchiveContextFollowUp['selected_intent'] ?? '' ) === 'campaign_archive', 'Contextual campaign archive follow-up should resolve to campaign_archive.' );
$assert( (string) ( $campaignArchiveContextFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual campaign archive follow-up should reuse the recent campaign subject.' );
$assert( empty( $campaignArchiveContextFollowUp['requires_clarification'] ), 'Contextual campaign archive follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $newsletterDeleteContextFollowUp['selected_intent'] ?? '' ) === 'newsletter_delete', 'Contextual newsletter delete follow-up should resolve to newsletter_delete.' );
$assert( (string) ( $newsletterDeleteContextFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual newsletter delete follow-up should reuse the recent campaign subject.' );
$assert( empty( $newsletterDeleteContextFollowUp['requires_clarification'] ), 'Contextual newsletter delete follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $newsletterSendContextFollowUp['selected_intent'] ?? '' ) === 'newsletter_send', 'Contextual newsletter send follow-up should resolve to newsletter_send.' );
$assert( (string) ( $newsletterSendContextFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual newsletter send follow-up should reuse the recent campaign subject.' );
$assert( empty( $newsletterSendContextFollowUp['requires_clarification'] ), 'Contextual newsletter send follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $disableGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Disable gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $disableGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Disable gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $enableGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Enable gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $enableGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Enable gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $updateGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Update gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $updateGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Update gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $unlockGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Unlock gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $unlockGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Unlock gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $mfaGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'MFA reset gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $mfaGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'MFA reset gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $deleteGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Delete gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $deleteGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Delete gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $workspaceEnableGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Workspace enable gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $workspaceEnableGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Workspace enable gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $workspaceUpdateGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Workspace update gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $workspaceUpdateGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Workspace update gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $genericResetGatewayFollowUp['status'] ?? '' ) === 'workflow_question', 'Generic password gateway follow-up should branch into password-scope workflow clarification.' );
$assert( str_contains( (string) ( $genericResetGatewayFollowUp['message'] ?? '' ), 'John Smith\'s Metis password or Workspace password' ), 'Generic password gateway follow-up should ask which password target to reset.' );
$assert( (string) ( $pronounResetGatewayFollowUp['status'] ?? '' ) === 'workflow_question', 'Pronoun password gateway follow-up should also branch into password-scope workflow clarification.' );
$assert( str_contains( (string) ( $pronounResetGatewayFollowUp['message'] ?? '' ), 'John Smith\'s Metis password or Workspace password' ), 'Pronoun password gateway follow-up should ask which password target to reset.' );
$assert( (string) ( $workspaceResetGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Workspace reset gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $workspaceResetGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Workspace reset gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $workspaceCreateGatewayFollowUp['status'] ?? '' ) === 'workflow_question', 'Workspace create gateway follow-up should still collect required workflow data.' );
$assert( (string) ( $workspaceCreateGatewayFollowUp['message'] ?? '' ) === 'What is the user\'s name?', 'Workspace create gateway follow-up should still begin the workspace-user workflow.' );
$assert( (string) ( $workspaceDisableGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Workspace disable gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $workspaceDisableGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Workspace disable gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $assignRoleGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Role assignment gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $assignRoleGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Role assignment gateway follow-up should reuse the recent entity subject.' );
$assert( (array) ( $assignRoleGatewayFollowUp['actions'][0]['payload']['command_payload']['roles'] ?? [] ) === [ 'administrator' ], 'Role assignment gateway follow-up should preserve the requested role list.' );
$assert( (string) ( $assignRoleGatewayFollowUp['actions'][0]['payload']['command_payload']['mode'] ?? '' ) === 'add', 'Role assignment gateway follow-up should preserve add mode.' );
$assert( (string) ( $removeRoleGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Role removal gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $removeRoleGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Role removal gateway follow-up should reuse the recent entity subject.' );
$assert( (array) ( $removeRoleGatewayFollowUp['actions'][0]['payload']['command_payload']['roles'] ?? [] ) === [ 'administrator' ], 'Role removal gateway follow-up should preserve the requested role list.' );
$assert( (string) ( $removeRoleGatewayFollowUp['actions'][0]['payload']['command_payload']['mode'] ?? '' ) === 'remove', 'Role removal gateway follow-up should preserve remove mode.' );
$assert( (string) ( $workspaceGroupGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Workspace group gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $workspaceGroupGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Workspace group gateway follow-up should reuse the recent entity subject.' );
$assert( (array) ( $workspaceGroupGatewayFollowUp['actions'][0]['payload']['command_payload']['group_emails'] ?? [] ) === [ 'finance-team@example.org' ], 'Workspace group gateway follow-up should preserve the requested group emails.' );
$assert( (string) ( $workspaceGroupGatewayFollowUp['actions'][0]['payload']['command_payload']['mode'] ?? '' ) === 'remove', 'Workspace group gateway follow-up should preserve remove mode.' );
$assert( (string) ( $driveFolderGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Drive folder gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $driveFolderGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Drive folder gateway follow-up should reuse the recent entity subject.' );
$assert( (string) ( $driveFolderGatewayFollowUp['actions'][0]['payload']['command_payload']['folder_id'] ?? '' ) === '1abcdefghijklmnopqrstuvwxyz', 'Drive folder gateway follow-up should preserve the requested folder ID.' );
$assert( (string) ( $newsletterCancelGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Newsletter cancel gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $newsletterCancelGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Newsletter cancel gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $newsletterScheduleGatewayFollowUp['status'] ?? '' ) === 'workflow_question', 'Newsletter schedule gateway follow-up should still collect the schedule time.' );
$assert( (string) ( $newsletterScheduleGatewayFollowUp['message'] ?? '' ) === 'When should the newsletter be scheduled?', 'Newsletter schedule gateway follow-up should ask for the schedule time after reusing the subject.' );
$assert( (string) ( $campaignPublishGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Campaign publish gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $campaignPublishGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Campaign publish gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $campaignArchiveGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Campaign archive gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $campaignArchiveGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Campaign archive gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $newsletterCancelContextGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Contextual newsletter cancel gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $newsletterCancelContextGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual newsletter cancel gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $newsletterScheduleContextGatewayFollowUp['status'] ?? '' ) === 'workflow_question', 'Contextual newsletter schedule gateway follow-up should still collect the schedule time.' );
$assert( (string) ( $newsletterScheduleContextGatewayFollowUp['message'] ?? '' ) === 'When should the newsletter be scheduled?', 'Contextual newsletter schedule gateway follow-up should ask for the schedule time after reusing the subject.' );
$assert( (string) ( $campaignPublishContextGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Contextual campaign publish gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $campaignPublishContextGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual campaign publish gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $campaignArchiveContextGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Contextual campaign archive gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $campaignArchiveContextGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual campaign archive gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $newsletterDeleteContextGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Contextual newsletter delete gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $newsletterDeleteContextGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual newsletter delete gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $newsletterSendContextGatewayFollowUp['status'] ?? '' ) === 'awaiting_approval', 'Contextual newsletter send gateway follow-up should enter approval when recent entity memory exists.' );
$assert( (string) ( $newsletterSendContextGatewayFollowUp['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Contextual newsletter send gateway follow-up should reuse the recent campaign subject.' );
$assert( (string) ( $passwordContinuationStart['status'] ?? '' ) === 'workflow_question', 'Password follow-up continuation should begin with password-scope clarification.' );
$assert( str_contains( (string) ( $passwordContinuationStart['message'] ?? '' ), 'John Smith\'s Metis password or Workspace password' ), 'Password follow-up continuation should ask which password target to reset.' );
$assert( (string) ( $passwordContinuationReview['status'] ?? '' ) === 'awaiting_approval', 'Password follow-up continuation should re-enter approval after the target is chosen.' );
$assert( (string) ( $passwordContinuationReview['response_type'] ?? '' ) === 'WorkflowReview', 'Password follow-up continuation should return a workflow review after the target is chosen.' );
$assert( (string) ( $passwordContinuationReview['actions'][0]['payload']['operation'] ?? '' ) === 'workspace_user_password_reset', 'Password follow-up continuation should canonicalize to the workspace password reset operation.' );
$assert( (string) ( $passwordContinuationReview['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Password follow-up continuation should preserve the recent entity subject into approval.' );
$assert( (string) ( $passwordCancellationStart['status'] ?? '' ) === 'workflow_question', 'Password follow-up cancellation should begin with password-scope clarification.' );
$assert( (string) ( $passwordCancellationResult['status'] ?? '' ) === 'cancelled', 'No should cancel a recent-entity password workflow question.' );
$assert( (string) ( $passwordCancellationResult['response_type'] ?? '' ) === 'WorkflowCancellation', 'No should return a workflow cancellation response for recent-entity password questions.' );
$assert( (string) ( $passwordCancellationResult['workflow']['type'] ?? '' ) === 'clarify_password_reset', 'Recent-entity password workflow cancellation should report the pending workflow type.' );
$assert( $memory->recallPendingWorkflow( $passwordCancellationSessionCode ) === [], 'Cancelled recent-entity password workflows should be cleared from memory.' );
$assert( (string) ( $newsletterContinuationStart['status'] ?? '' ) === 'workflow_question', 'Newsletter schedule follow-up continuation should begin with schedule-time collection.' );
$assert( (string) ( $newsletterContinuationStart['message'] ?? '' ) === 'When should the newsletter be scheduled?', 'Newsletter schedule follow-up continuation should ask for the schedule time.' );
$assert( (string) ( $newsletterContinuationReview['status'] ?? '' ) === 'awaiting_approval', 'Newsletter schedule follow-up continuation should re-enter approval after the time is provided.' );
$assert( (string) ( $newsletterContinuationReview['response_type'] ?? '' ) === 'WorkflowReview', 'Newsletter schedule follow-up continuation should return a workflow review after the time is provided.' );
$assert( (string) ( $newsletterContinuationReview['actions'][0]['payload']['operation'] ?? '' ) === 'newsletter_schedule', 'Newsletter schedule follow-up continuation should preserve the newsletter_schedule operation key.' );
$assert( (string) ( $newsletterContinuationReview['actions'][0]['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Newsletter schedule follow-up continuation should preserve the recent content subject into approval.' );
$assert( (string) ( $newsletterContinuationReview['actions'][0]['payload']['command_payload']['scheduled_at'] ?? '' ) === '2026-06-15 09:00:00', 'Newsletter schedule follow-up continuation should preserve the collected schedule time into approval.' );
$assert( (string) ( $newsletterCancellationStart['status'] ?? '' ) === 'workflow_question', 'Newsletter schedule follow-up cancellation should begin with schedule-time collection.' );
$assert( (string) ( $newsletterCancellationResult['status'] ?? '' ) === 'cancelled', 'No should cancel a recent-entity newsletter scheduling workflow question.' );
$assert( (string) ( $newsletterCancellationResult['response_type'] ?? '' ) === 'WorkflowCancellation', 'No should return a workflow cancellation response for recent-entity newsletter scheduling questions.' );
$assert( (string) ( $newsletterCancellationResult['workflow']['type'] ?? '' ) === 'newsletter_schedule', 'Recent-entity newsletter scheduling cancellation should report the pending workflow type.' );
$assert( $memory->recallPendingWorkflow( $newsletterCancellationSessionCode ) === [], 'Cancelled recent-entity newsletter scheduling workflows should be cleared from memory.' );
$assert( (string) ( $approvalContinuationPeoplePrompt['status'] ?? '' ) === 'awaiting_approval', 'Recent-entity people follow-up should enter approval before yes-continuation.' );
$assert( (string) ( $approvalContinuationPeopleResult['response_type'] ?? '' ) === 'WorkflowContinuationResult', 'Yes should continue a recent-entity people approval prompt.' );
$assert( (string) ( $approvalContinuationPeopleResult['action']['approval_status'] ?? '' ) === 'executed', 'Recent-entity people approval continuation should execute the pending action.' );
$assert( (string) ( $approvalContinuationPeopleResult['action']['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Recent-entity people approval continuation should preserve the reused subject.' );
$assert( (array) ( $approvalContinuationPeopleResult['action']['payload']['command_payload']['roles'] ?? [] ) === [ 'administrator' ], 'Recent-entity people approval continuation should preserve the role payload.' );
$assert( (string) ( $approvalRejectionPeoplePrompt['status'] ?? '' ) === 'awaiting_approval', 'Recent-entity people rejection flow should begin from approval.' );
$assert( (string) ( $approvalRejectionPeopleResult['response_type'] ?? '' ) === 'WorkflowCancellation', 'No should cancel a recent-entity people approval prompt.' );
$assert( (string) ( $approvalRejectionPeopleResult['status'] ?? '' ) === 'cancelled', 'No should mark a recent-entity people approval prompt as cancelled.' );
$assert( (string) ( $approvalRejectionPeopleResult['action']['approval_status'] ?? '' ) === 'cancelled', 'Recent-entity people rejection should transition the action to cancelled state.' );
$assert( (string) ( $approvalRejectionPeopleResult['action']['payload']['command_payload']['subject'] ?? '' ) === 'John Smith', 'Recent-entity people rejection should preserve the reused subject on the cancelled action.' );
$assert( (string) ( $approvalContinuationContentPrompt['status'] ?? '' ) === 'awaiting_approval', 'Recent-entity content follow-up should enter approval before yes-continuation.' );
$assert( (string) ( $approvalContinuationContentResult['response_type'] ?? '' ) === 'WorkflowContinuationResult', 'Yes should continue a recent-entity content approval prompt.' );
$assert( (string) ( $approvalContinuationContentResult['action']['approval_status'] ?? '' ) === 'executed', 'Recent-entity content approval continuation should execute the pending action.' );
$assert( (string) ( $approvalContinuationContentResult['action']['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Recent-entity content approval continuation should preserve the reused subject.' );
$assert( (string) ( $approvalContinuationContentResult['action']['payload']['operation'] ?? '' ) === 'newsletter_cancel', 'Recent-entity content approval continuation should preserve the newsletter_cancel operation.' );
$assert( (string) ( $approvalRejectionContentPrompt['status'] ?? '' ) === 'awaiting_approval', 'Recent-entity content rejection flow should begin from approval.' );
$assert( (string) ( $approvalRejectionContentResult['response_type'] ?? '' ) === 'WorkflowCancellation', 'No should cancel a recent-entity content approval prompt.' );
$assert( (string) ( $approvalRejectionContentResult['status'] ?? '' ) === 'cancelled', 'No should mark a recent-entity content approval prompt as cancelled.' );
$assert( (string) ( $approvalRejectionContentResult['action']['approval_status'] ?? '' ) === 'cancelled', 'Recent-entity content rejection should transition the action to cancelled state.' );
$assert( (string) ( $approvalRejectionContentResult['action']['payload']['command_payload']['subject'] ?? '' ) === 'June Board Update', 'Recent-entity content rejection should preserve the reused subject on the cancelled action.' );
$assert( (string) ( $passwordExpiryStart['status'] ?? '' ) === 'workflow_question', 'Recent-entity password expiry flow should begin from a workflow question.' );
$assert( (string) ( $passwordExpiryResume['status'] ?? '' ) === 'workflow_expired', 'Expired recent-entity password follow-up should not continue.' );
$assert( (string) ( $passwordExpiryResume['response_type'] ?? '' ) === 'WorkflowExpiredPrompt', 'Expired recent-entity password follow-up should return a workflow expiration prompt.' );
$assert( (string) ( $newsletterExpiryStart['status'] ?? '' ) === 'workflow_question', 'Recent-entity newsletter expiry flow should begin from a workflow question.' );
$assert( (string) ( $newsletterExpiryResume['status'] ?? '' ) === 'workflow_expired', 'Expired recent-entity newsletter scheduling follow-up should not continue.' );
$assert( (string) ( $newsletterExpiryResume['response_type'] ?? '' ) === 'WorkflowExpiredPrompt', 'Expired recent-entity newsletter scheduling follow-up should return a workflow expiration prompt.' );
$assert( (string) ( $approvalExpiryPrompt['status'] ?? '' ) === 'awaiting_approval', 'Recent-entity approval expiry flow should begin from an approval prompt.' );
$assert( (string) ( $approvalExpiryResult['status'] ?? '' ) === 'workflow_expired', 'Expired recent-entity approval follow-up should not continue.' );
$assert( (string) ( $approvalExpiryResult['response_type'] ?? '' ) === 'WorkflowExpiredPrompt', 'Expired recent-entity approval follow-up should return a workflow expiration prompt.' );
$assert( (string) ( $approvalExpiryResult['action']['approval_status'] ?? '' ) === 'expired', 'Expired recent-entity approval follow-up should transition the pending action to expired state.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes recent entity memory checks passed.\n" );
