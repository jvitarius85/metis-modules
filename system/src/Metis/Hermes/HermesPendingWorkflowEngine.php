<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesPendingWorkflowEngine {
    private const WORKFLOW_TTL_SECONDS = 900;

    public function __construct(
        private readonly HermesMemoryStore $memory,
        private readonly HermesCommandRegistry $commands,
        private readonly HermesResponseRenderer $responses
    ) {}

    public function continueIfApplicable( string $query, array $session ): ?array {
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        if ( $sessionCode === '' ) {
            return null;
        }

        $stored = $this->memory->recallPendingWorkflow( $sessionCode );
        $workflow = (array) ( $stored['contents'] ?? [] );
        if ( $workflow === [] ) {
            return null;
        }

        if ( $this->isExpired( (string) ( $stored['updated_at'] ?? '' ) ) ) {
            $this->memory->clearPendingWorkflow( $sessionCode );

            return $this->workflowResponse(
                'workflow_expired',
                'WorkflowExpiredPrompt',
                'The previous workflow has expired. Would you like to start again?'
            );
        }

        if ( $this->workflowCancellationDecision( $query ) === 'reject' ) {
            $this->memory->clearPendingWorkflow( $sessionCode );

            return [
                'status' => 'cancelled',
                'message' => 'Cancelled the pending workflow.',
                'response_type' => 'WorkflowCancellation',
                'workflow' => [
                    'type' => (string) ( $workflow['type'] ?? '' ),
                    'step' => (string) ( $workflow['step'] ?? '' ),
                ],
            ];
        }

        $workflowType = (string) ( $workflow['type'] ?? '' );
        if ( in_array( $workflowType, [ 'create_user', 'workspace_user_create' ], true ) ) {
            return $this->continueCreateUserWorkflow( $sessionCode, $workflowType, $query, $workflow );
        }

        if ( in_array( $workflowType, [ 'user_password_reset', 'workspace_user_password_reset', 'clarify_password_reset' ], true ) ) {
            return $this->continuePasswordResetWorkflow( $sessionCode, $workflowType, $query, $workflow );
        }

        if ( in_array( $workflowType, [ 'backup_restore', 'backup_validate', 'restore_file' ], true ) ) {
            return $this->continueBackupRunWorkflow( $sessionCode, $workflowType, $query, $workflow );
        }

        if ( $workflowType === 'create_job' ) {
            return $this->continueCronTaskWorkflow( $sessionCode, $query, $workflow );
        }

        if ( in_array( $workflowType, [ 'cancel_job', 'retry_job' ], true ) ) {
            return $this->continueWorkerJobWorkflow( $sessionCode, $workflowType, $query, $workflow );
        }

        if ( $this->isPersonActionWorkflow( $workflowType ) ) {
            return $this->continuePersonActionWorkflow( $sessionCode, $workflowType, $query, $workflow );
        }

        if ( $this->isPeopleMembershipWorkflow( $workflowType ) ) {
            return $this->continuePeopleMembershipWorkflow( $sessionCode, $workflowType, $query, $workflow );
        }

        if ( $this->isContentOperationWorkflow( $workflowType ) ) {
            return $this->continueContentOperationWorkflow( $sessionCode, $workflowType, $query, $workflow );
        }

        return null;
    }

    public function beginIfApplicable( array $session, array $processed ): ?array {
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        if ( $sessionCode === '' ) {
            return null;
        }

        $command = (array) ( $processed['command'] ?? [] );
        $intentAction = (string) ( $processed['intent']['action'] ?? '' );

        $createWorkflow = $this->beginCreateUserWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $createWorkflow ) ) {
            return $createWorkflow;
        }

        $passwordResetWorkflow = $this->beginPasswordResetWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $passwordResetWorkflow ) ) {
            return $passwordResetWorkflow;
        }

        $backupRunWorkflow = $this->beginBackupRunWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $backupRunWorkflow ) ) {
            return $backupRunWorkflow;
        }

        $cronTaskWorkflow = $this->beginCronTaskWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $cronTaskWorkflow ) ) {
            return $cronTaskWorkflow;
        }

        $workerJobWorkflow = $this->beginWorkerJobWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $workerJobWorkflow ) ) {
            return $workerJobWorkflow;
        }

        $personActionWorkflow = $this->beginPersonActionWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $personActionWorkflow ) ) {
            return $personActionWorkflow;
        }

        $peopleMembershipWorkflow = $this->beginPeopleMembershipWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $peopleMembershipWorkflow ) ) {
            return $peopleMembershipWorkflow;
        }

        $contentOperationWorkflow = $this->beginContentOperationWorkflow( $sessionCode, $command, $intentAction, $processed );
        if ( is_array( $contentOperationWorkflow ) ) {
            return $contentOperationWorkflow;
        }

        return null;
    }

    private function questionResponse( string $step ): array {
        return match ( $step ) {
            'display_name' => $this->workflowResponse( 'workflow_question', 'WorkflowQuestion', 'What is the user\'s name?' ),
            'email' => $this->workflowResponse( 'workflow_question', 'WorkflowQuestion', 'What is the user\'s email?' ),
            'roles' => $this->workflowResponse( 'workflow_question', 'WorkflowQuestion', 'What role should be assigned? You can say "no role".' ),
            default => $this->workflowResponse( 'workflow_question', 'WorkflowQuestion', 'Please provide the next workflow detail.' ),
        };
    }

    private function workflowResponse( string $status, string $responseType, string $message ): array {
        return [
            'status' => $status,
            'message' => $message,
            'response_type' => $responseType,
        ];
    }

    private function workflowCancellationDecision( string $query ): string {
        $normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $query ) ?? $query ) );

        if ( in_array( $normalized, [ 'no', 'n', 'cancel', 'stop', 'never mind', 'nevermind', 'do not', 'don\'t' ], true ) ) {
            return 'reject';
        }

        return '';
    }

    private function nextStep( array $request, string $currentStep ): string {
        $name = trim( (string) ( $request['display_name'] ?? '' ) );
        $email = strtolower( trim( (string) ( $request['email'] ?? '' ) ) );
        $roles = array_values( array_filter( array_map( 'strval', (array) ( $request['roles'] ?? [] ) ) ) );

        if ( $name === '' ) {
            return 'display_name';
        }

        if ( $email === '' || ! ( function_exists( 'metis_email_is_valid' ) ? \metis_email_is_valid( $email ) : filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ) {
            return 'email';
        }

        if ( $currentStep !== 'roles' && ! array_key_exists( 'roles', $request ) ) {
            return 'roles';
        }

        if ( $roles === [] && ! array_key_exists( 'roles', $request ) ) {
            return 'roles';
        }

        return 'review';
    }

    private function mergeName( array $request, string $query ): array {
        $name = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
        if ( $name === '' ) {
            return $request;
        }

        $request['display_name'] = $name;
        $parts = preg_split( '/\s+/', $name ) ?: [];
        $request['first_name'] = (string) ( $parts[0] ?? '' );
        $request['last_name'] = count( $parts ) > 1 ? implode( ' ', array_slice( $parts, 1 ) ) : '';

        return $request;
    }

    private function mergeEmail( array $request, string $query ): array {
        if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches ) ) {
            $email = strtolower( trim( (string) ( $matches[0] ?? '' ) ) );
            $request['email'] = $email;
            $request['workspace_email'] = $email;
        }

        return $request;
    }

    /**
     * @return array<int,string>
     */
    private function extractRoles( string $query ): array {
        $normalized = strtolower( trim( $query ) );
        if ( in_array( $normalized, [ 'none', 'no role', 'no roles', 'skip' ], true ) ) {
            return [];
        }

        $roles = [];
        $map = [
            'administrator' => [ 'administrator', 'admin' ],
            'board' => [ 'board administrator', 'board member', 'board' ],
            'donor_admin' => [ 'donor admin' ],
            'donor_user' => [ 'donor user' ],
            'newsletter_admin' => [ 'newsletter admin' ],
        ];
        foreach ( $map as $role => $phrases ) {
            foreach ( $phrases as $phrase ) {
                if ( str_contains( $normalized, $phrase ) ) {
                    $roles[] = $role;
                    break;
                }
            }
        }

        return array_values( array_unique( $roles ) );
    }

    private function buildReviewMessage( array $request, bool $workspaceUser = false ): string {
        $lines = [
            'Review:',
            'Name: ' . (string) ( $request['display_name'] ?? '' ),
            'Email: ' . (string) ( $request['email'] ?? '' ),
            'Role: ' . ( (array) ( $request['roles'] ?? [] ) !== [] ? implode( ', ', (array) $request['roles'] ) : 'No role' ),
            '',
            $workspaceUser ? 'Create workspace user?' : 'Create user?',
        ];

        return implode( "\n", $lines );
    }

    private function buildCreateUserReview( array $request, string $workflowType = 'create_user' ): array {
        $operation = in_array( $workflowType, [ 'create_user', 'workspace_user_create' ], true ) ? $workflowType : 'create_user';
        if ( $operation === 'workspace_user_create' ) {
            $request['workspace_enabled'] = true;
        }

        $command = $this->commands->definition( $operation );
        $intent = [
            'action' => $operation,
            'top_level_intent' => 'CREATE',
            'payload' => [ 'user_request' => $request ],
        ];
        $plan = [
            'operation' => $operation,
            'title' => (string) ( $command['title'] ?? ( $operation === 'workspace_user_create' ? 'Create Workspace User' : 'Create User' ) ),
            'required_permission' => (string) ( $command['permission'] ?? 'people.create' ),
            'steps' => [ $operation ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        $response['message'] = $this->buildReviewMessage( $request, $operation === 'workspace_user_create' );
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => [
                'name' => (string) ( $request['display_name'] ?? '' ),
                'email' => (string) ( $request['email'] ?? '' ),
                'roles' => array_values( (array) ( $request['roles'] ?? [] ) ),
            ],
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? 'people.create' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => $operation,
                'top_level_intent' => 'CREATE',
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => $operation === 'workspace_user_create' ? 'create workspace user workflow review' : 'create user workflow review',
                        'intent' => $operation,
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => [ 'user_request' => $request ],
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function continueCreateUserWorkflow( string $sessionCode, string $workflowType, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $step = (string) ( $workflow['step'] ?? 'display_name' );

        if ( $step === 'display_name' ) {
            $request = $this->mergeName( $request, $query );
        } elseif ( $step === 'email' ) {
            $request = $this->mergeEmail( $request, $query );
        } elseif ( $step === 'roles' ) {
            $request['roles'] = $this->extractRoles( $query );
        }

        $step = $this->nextStep( $request, $step );

        if ( $step !== 'review' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => $workflowType,
                'step' => $step,
                'request' => $request,
            ] );

            return $this->questionResponse( $step );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );
        return $this->buildCreateUserReview( $request, $workflowType );
    }

    private function beginCreateUserWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        if ( ! in_array( $intentAction, [ 'create_user', 'workspace_user_create' ], true ) && ! in_array( (string) ( $command['key'] ?? '' ), [ 'create_user', 'workspace_user_create' ], true ) ) {
            return null;
        }

        $workflowType = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? 'create_user' );
        $request = (array) ( $processed['intent']['payload']['user_request'] ?? [] );
        if ( $workflowType === 'workspace_user_create' ) {
            $request['workspace_enabled'] = true;
        }
        $step = $this->nextStep( $request, '' );
        if ( $step === 'review' ) {
            return null;
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => $workflowType,
            'step' => $step,
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            $workflowType,
            'CREATE',
            [ 'user_request' => $request ],
            $command,
            $processed,
            $step
        );
    }

    private function continuePasswordResetWorkflow( string $sessionCode, string $workflowType, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $step = (string) ( $workflow['step'] ?? 'subject' );

        if ( $step === 'subject' ) {
            $request['subject'] = $this->extractWorkflowSubject( $query );
        } elseif ( $step === 'scope' ) {
            $scope = $this->extractPasswordScope( $query );
            if ( $scope !== '' ) {
                $request['scope'] = $scope;
            }
        }

        $step = $this->nextPasswordResetStep( $workflowType, $request );
        if ( $step !== 'review' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => $workflowType,
                'step' => $step,
                'request' => $request,
            ] );

            return $this->passwordResetQuestionResponse( $step, $request );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );
        return $this->buildPasswordResetReview( $workflowType, $request );
    }

    private function beginPasswordResetWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        $passwordActions = [ 'user_password_reset', 'workspace_user_password_reset', 'reset_metis_password', 'reset_workspace_password', 'clarify_password_reset' ];
        if ( ! in_array( $intentAction, $passwordActions, true ) && ! in_array( (string) ( $command['key'] ?? '' ), [ 'user_password_reset', 'workspace_user_password_reset' ], true ) ) {
            return null;
        }

        $workflowType = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? 'user_password_reset' );
        $request = $this->passwordResetRequestFromProcessed( $processed );
        if ( $workflowType === 'user_password_reset' && $this->shouldClarifyPasswordScope( $processed ) ) {
            $workflowType = 'clarify_password_reset';
        }
        $step = $this->nextPasswordResetStep( $workflowType, $request );
        if ( $step === 'review' ) {
            return $this->buildPasswordResetReview( $workflowType, $request );
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => $workflowType,
            'step' => $step,
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            $workflowType,
            'EXECUTE',
            [ 'password_request' => $request ],
            $command,
            $processed,
            $step,
            $this->passwordResetQuestionResponse( $step, $request )
        );
    }

    private function continueBackupRunWorkflow( string $sessionCode, string $workflowType, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $step = (string) ( $workflow['step'] ?? 'run_uuid' );
        if ( $step === 'run_uuid' ) {
            $runUuid = $this->extractRunUuid( $query );
            if ( $runUuid !== '' ) {
                $request['run_uuid'] = $runUuid;
            }
        } elseif ( $step === 'relative_path' ) {
            $relativePath = $this->extractRestoreFilePath( $query );
            if ( $relativePath !== '' ) {
                $request['relative_path'] = $relativePath;
            }
        }

        $nextStep = $this->nextBackupWorkflowStep( $workflowType, $request );
        if ( $nextStep !== 'review' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => $workflowType,
                'step' => $nextStep,
                'request' => $request,
            ] );

            return $this->backupRunQuestionResponse( $workflowType, $nextStep );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );
        return $this->buildBackupRunReview( $workflowType, $request );
    }

    private function beginBackupRunWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        $operation = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? '' );
        if ( ! in_array( $operation, [ 'backup_restore', 'backup_validate', 'restore_file' ], true ) ) {
            return null;
        }

        $request = (array) ( $processed['intent']['payload'] ?? [] );
        $step = $this->nextBackupWorkflowStep( $operation, $request );
        if ( $step === 'review' ) {
            return null;
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => $operation,
            'step' => $step,
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            $operation,
            'EXECUTE',
            $request,
            $command,
            $processed,
            $step,
            $this->backupRunQuestionResponse( $operation, $step )
        );
    }

    private function continueCronTaskWorkflow( string $sessionCode, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $taskSlug = trim( strtolower( $query ) );
        $taskSlug = function_exists( 'metis_key_clean' ) ? \metis_key_clean( $taskSlug ) : preg_replace( '/[^a-z0-9_-]+/', '', $taskSlug );
        if ( is_string( $taskSlug ) && $taskSlug !== '' ) {
            $request['task_slug'] = $taskSlug;
        }

        if ( trim( (string) ( $request['task_slug'] ?? '' ) ) === '' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => 'create_job',
                'step' => 'task_slug',
                'request' => $request,
            ] );

            return $this->workflowResponse( 'workflow_question', 'WorkflowQuestion', 'Which cron task should be queued?' );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );

        return $this->buildCronTaskReview( $request );
    }

    private function beginCronTaskWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        $operation = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? '' );
        if ( $operation !== 'create_job' ) {
            return null;
        }

        $request = (array) ( $processed['intent']['payload'] ?? [] );
        if ( trim( (string) ( $request['task_slug'] ?? '' ) ) !== '' ) {
            return null;
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => 'create_job',
            'step' => 'task_slug',
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            'create_job',
            'CREATE',
            $request,
            $command,
            $processed,
            'task_slug',
            $this->workflowResponse( 'workflow_question', 'WorkflowQuestion', 'Which cron task should be queued?' )
        );
    }

    private function continueWorkerJobWorkflow( string $sessionCode, string $operation, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $jobCode = strtoupper( preg_replace( '/[^A-Z0-9_-]+/i', '', trim( $query ) ) ?? '' );
        if ( $jobCode !== '' ) {
            $request['job_code'] = $jobCode;
        }

        if ( trim( (string) ( $request['job_code'] ?? $request['job_key'] ?? '' ) ) === '' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => $operation,
                'step' => 'job_code',
                'request' => $request,
            ] );

            return $this->workerJobQuestionResponse( $operation );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );

        return $this->buildWorkerJobReview( $operation, $request );
    }

    private function beginWorkerJobWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        $operation = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? '' );
        if ( ! in_array( $operation, [ 'cancel_job', 'retry_job' ], true ) ) {
            return null;
        }

        $request = (array) ( $processed['intent']['payload'] ?? [] );
        if ( trim( (string) ( $request['job_code'] ?? $request['job_key'] ?? '' ) ) !== '' ) {
            return null;
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => $operation,
            'step' => 'job_code',
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            $operation,
            'EXECUTE',
            $request,
            $command,
            $processed,
            'job_code',
            $this->workerJobQuestionResponse( $operation )
        );
    }

    private function continuePersonActionWorkflow( string $sessionCode, string $operation, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $subject = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
        if ( $subject !== '' ) {
            $request['subject'] = $subject;
        }

        if ( trim( (string) ( $request['subject'] ?? '' ) ) === '' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => $operation,
                'step' => 'subject',
                'request' => $request,
            ] );

            return $this->personActionQuestionResponse( $operation );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );

        return $this->buildPersonActionReview( $operation, $request );
    }

    private function beginPersonActionWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        $operation = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? '' );
        if ( ! $this->isPersonActionWorkflow( $operation ) ) {
            return null;
        }

        $request = (array) ( $processed['intent']['payload'] ?? [] );
        if ( trim( (string) ( $request['subject'] ?? '' ) ) !== '' ) {
            return null;
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => $operation,
            'step' => 'subject',
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            $operation,
            (string) ( $command['top_level_intent'] ?? 'EXECUTE' ),
            $request,
            $command,
            $processed,
            'subject',
            $this->personActionQuestionResponse( $operation )
        );
    }

    private function continuePeopleMembershipWorkflow( string $sessionCode, string $operation, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $step = (string) ( $workflow['step'] ?? 'subject' );

        if ( $step === 'subject' ) {
            $subject = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
            if ( $subject !== '' ) {
                $request['subject'] = $subject;
            }
        } elseif ( $step === 'roles' ) {
            $request['roles'] = $this->extractRoles( $query );
        } elseif ( $step === 'group_emails' ) {
            $request['group_emails'] = $this->extractGroupEmails( $query );
        }

        $nextStep = $this->nextPeopleMembershipStep( $operation, $request );
        if ( $nextStep !== 'review' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => $operation,
                'step' => $nextStep,
                'request' => $request,
            ] );

            return $this->peopleMembershipQuestionResponse( $operation, $nextStep );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );

        return $this->buildPeopleMembershipReview( $operation, $request );
    }

    private function beginPeopleMembershipWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        $operation = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? '' );
        if ( ! $this->isPeopleMembershipWorkflow( $operation ) ) {
            return null;
        }

        $request = (array) ( $processed['intent']['payload'] ?? [] );
        $normalizedInput = strtolower( trim( (string) ( $processed['parsed']['normalized_input'] ?? '' ) ) );
        if ( $operation === 'assign_role' ) {
            $request['mode'] = 'add';
        } elseif ( $operation === 'remove_role' ) {
            $request['mode'] = 'remove';
        } elseif ( $operation === 'manage_workspace_groups' ) {
            $request['mode'] = str_contains( $normalizedInput, 'remove workspace group' ) ? 'remove' : 'add';
        }

        $step = $this->nextPeopleMembershipStep( $operation, $request );
        if ( $step === 'review' ) {
            return null;
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => $operation,
            'step' => $step,
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            $operation,
            'EXECUTE',
            $request,
            $command,
            $processed,
            $step,
            $this->peopleMembershipQuestionResponse( $operation, $step )
        );
    }

    private function continueContentOperationWorkflow( string $sessionCode, string $operation, string $query, array $workflow ): array {
        $request = (array) ( $workflow['request'] ?? [] );
        $step = (string) ( $workflow['step'] ?? 'subject' );

        if ( $step === 'subject' ) {
            $request['subject'] = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
        } elseif ( $step === 'scheduled_at' ) {
            $request['scheduled_at'] = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
        }

        $step = $this->nextContentOperationStep( $operation, $request );
        if ( $step !== 'review' ) {
            $this->memory->rememberPendingWorkflow( $sessionCode, [
                'type' => $operation,
                'step' => $step,
                'request' => $request,
            ] );

            return $this->contentOperationQuestionResponse( $operation, $step );
        }

        $this->memory->clearPendingWorkflow( $sessionCode );
        return $this->buildContentOperationReview( $operation, $request );
    }

    private function beginContentOperationWorkflow( string $sessionCode, array $command, string $intentAction, array $processed ): ?array {
        $operation = $intentAction !== '' ? $intentAction : (string) ( $command['key'] ?? '' );
        if ( ! $this->isContentOperationWorkflow( $operation ) ) {
            return null;
        }

        $request = (array) ( $processed['intent']['payload'] ?? [] );
        $step = $this->nextContentOperationStep( $operation, $request );
        if ( $step === 'review' ) {
            return null;
        }

        $this->memory->rememberPendingWorkflow( $sessionCode, [
            'type' => $operation,
            'step' => $step,
            'request' => $request,
        ] );

        return $this->buildWorkflowQuestionEnvelope(
            $operation,
            $this->commands->definition( $operation )['top_level_intent'] ?? 'EXECUTE',
            $request,
            $command,
            $processed,
            $step,
            $this->contentOperationQuestionResponse( $operation, $step )
        );
    }

    private function buildWorkflowQuestionEnvelope(
        string $workflowType,
        string $topLevelIntent,
        array $payload,
        array $command,
        array $processed,
        string $step,
        ?array $response = null
    ): array {
        return [
            'intent' => [
                'action' => $workflowType . '_workflow',
                'top_level_intent' => $topLevelIntent,
                'payload' => $payload,
            ],
            'command' => $command,
            'context_packs' => [],
            'action_plan' => [],
            'permission' => [ 'status' => 'not_applicable', 'required_permission' => '', 'reason' => '' ],
            'response' => $response ?? $this->questionResponse( $step ),
            'parsed' => (array) ( $processed['parsed'] ?? [] ),
        ];
    }

    private function nextContentOperationStep( string $operation, array $request ): string {
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        if ( $subject === '' ) {
            return 'subject';
        }

        if ( $operation === 'newsletter_schedule' ) {
            $scheduledAt = trim( (string) ( $request['scheduled_at'] ?? '' ) );
            if ( $scheduledAt === '' ) {
                return 'scheduled_at';
            }
        }

        return 'review';
    }

    private function contentOperationQuestionResponse( string $operation, string $step ): array {
        if ( $step === 'scheduled_at' ) {
            return $this->workflowResponse(
                'workflow_question',
                'WorkflowQuestion',
                'When should the newsletter be scheduled?'
            );
        }

        $label = $this->contentOperationLabel( $operation );
        return $this->workflowResponse(
            'workflow_question',
            'WorkflowQuestion',
            sprintf( 'Which %s should be %s?', $label, $this->contentOperationVerb( $operation ) )
        );
    }

    private function buildContentOperationReview( string $operation, array $request ): array {
        $command = $this->commands->definition( $operation );
        $payload = [ 'subject' => trim( (string) ( $request['subject'] ?? '' ) ) ];
        if ( $operation === 'newsletter_schedule' ) {
            $payload['scheduled_at'] = trim( (string) ( $request['scheduled_at'] ?? '' ) );
        }

        $intent = [
            'action' => $operation,
            'top_level_intent' => (string) ( $command['top_level_intent'] ?? 'EXECUTE' ),
            'payload' => $payload,
        ];
        $plan = [
            'operation' => $operation,
            'title' => (string) ( $command['title'] ?? ucwords( str_replace( '_', ' ', $operation ) ) ),
            'required_permission' => (string) ( $command['permission'] ?? '' ),
            'steps' => [ $operation ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        $response['message'] = $this->buildContentOperationReviewMessage( $operation, $payload );
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => $payload,
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? '' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => $operation,
                'top_level_intent' => (string) ( $command['top_level_intent'] ?? 'EXECUTE' ),
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => strtolower( str_replace( '_', ' ', $operation ) ) . ' workflow review',
                        'intent' => $operation,
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => $payload,
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function buildContentOperationReviewMessage( string $operation, array $payload ): string {
        $lines = [
            'Review:',
            ucfirst( $this->contentOperationLabel( $operation ) ) . ': ' . (string) ( $payload['subject'] ?? '' ),
        ];
        if ( isset( $payload['scheduled_at'] ) ) {
            $lines[] = 'Schedule time: ' . (string) $payload['scheduled_at'];
        }
        $lines[] = '';
        $lines[] = ucfirst( $this->contentOperationVerb( $operation ) ) . ' this ' . $this->contentOperationLabel( $operation ) . '?';

        return implode( "\n", $lines );
    }

    private function nextPasswordResetStep( string $workflowType, array $request ): string {
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        if ( $subject === '' ) {
            return 'subject';
        }

        if ( $workflowType === 'clarify_password_reset' ) {
            $scope = (string) ( $request['scope'] ?? '' );
            if ( ! in_array( $scope, [ 'metis', 'workspace' ], true ) ) {
                return 'scope';
            }
        }

        return 'review';
    }

    private function passwordResetQuestionResponse( string $step, array $request ): array {
        if ( $step === 'scope' ) {
            $subject = trim( (string) ( $request['subject'] ?? 'this user' ) );
            return $this->workflowResponse(
                'workflow_question',
                'WorkflowQuestion',
                sprintf( 'Do you want to reset %s\'s Metis password or Workspace password?', $subject )
            );
        }

        return $this->workflowResponse( 'workflow_question', 'WorkflowQuestion', 'Which user password should be reset?' );
    }

    private function buildPasswordResetReview( string $workflowType, array $request ): array {
        $operation = $this->canonicalPasswordResetOperation( $workflowType, $request );
        $command = $this->commands->definition( $operation );
        $payload = [
            'subject' => trim( (string) ( $request['subject'] ?? '' ) ),
        ];
        $newPassword = trim( (string) ( $request['new_password'] ?? '' ) );
        if ( $newPassword !== '' ) {
            $payload['new_password'] = $newPassword;
        }

        $intent = [
            'action' => $operation,
            'top_level_intent' => 'EXECUTE',
            'payload' => $payload,
        ];
        $plan = [
            'operation' => $operation,
            'title' => (string) ( $command['title'] ?? ( $operation === 'workspace_user_password_reset' ? 'Reset Workspace Password' : 'Reset User Password' ) ),
            'required_permission' => (string) ( $command['permission'] ?? 'people.edit' ),
            'steps' => [ $operation ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        $response['message'] = sprintf(
            'Review: Reset the %s password for %s?',
            $operation === 'workspace_user_password_reset' ? 'Workspace' : 'Metis',
            (string) ( $payload['subject'] ?? '' ),
        );
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => [
                'subject' => (string) ( $payload['subject'] ?? '' ),
                'target' => $operation === 'workspace_user_password_reset' ? 'workspace' : 'metis',
            ],
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? 'people.edit' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => $operation,
                'top_level_intent' => 'EXECUTE',
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => 'password reset workflow review',
                        'intent' => $operation,
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => $payload,
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function canonicalPasswordResetOperation( string $workflowType, array $request ): string {
        if ( $workflowType === 'workspace_user_password_reset' || $workflowType === 'reset_workspace_password' ) {
            return 'workspace_user_password_reset';
        }

        if ( $workflowType === 'clarify_password_reset' ) {
            return (string) ( $request['scope'] ?? '' ) === 'workspace'
                ? 'workspace_user_password_reset'
                : 'user_password_reset';
        }

        return 'user_password_reset';
    }

    private function backupRunQuestionResponse( string $operation, string $step = 'run_uuid' ): array {
        if ( $operation === 'restore_file' && $step === 'relative_path' ) {
            return $this->workflowResponse(
                'workflow_question',
                'WorkflowQuestion',
                'Which file path should be restored? Use a backup-relative path like "storage/public-media/example.pdf".'
            );
        }

        return $this->workflowResponse(
            'workflow_question',
            'WorkflowQuestion',
            $operation === 'backup_validate'
                ? 'Which backup run ID should be validated?'
                : ( $operation === 'restore_file'
                    ? 'Which backup run ID contains the file to restore?'
                    : 'Which backup run ID should be restored?' )
        );
    }

    private function buildBackupRunReview( string $operation, array $request ): array {
        $command = $this->commands->definition( $operation );
        $payload = [ 'run_uuid' => trim( (string) ( $request['run_uuid'] ?? '' ) ) ];
        if ( $operation === 'restore_file' ) {
            $payload['relative_path'] = trim( (string) ( $request['relative_path'] ?? '' ) );
        }
        $intent = [
            'action' => $operation,
            'top_level_intent' => 'EXECUTE',
            'payload' => $payload,
        ];
        $plan = [
            'operation' => $operation,
            'title' => (string) ( $command['title'] ?? ( $operation === 'backup_validate' ? 'Backup Validate' : 'Backup Restore' ) ),
            'required_permission' => (string) ( $command['permission'] ?? 'system.backup.execute' ),
            'steps' => [ $operation ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        if ( $operation === 'restore_file' ) {
            $response['message'] = sprintf(
                "Review:\nBackup run ID: %s\nFile path: %s\n\nRestore this file?",
                (string) ( $payload['run_uuid'] ?? '' ),
                (string) ( $payload['relative_path'] ?? '' )
            );
        } else {
            $response['message'] = sprintf(
                $operation === 'backup_validate'
                    ? "Review:\nBackup run ID: %s\n\nValidate this backup?"
                    : "Review:\nBackup run ID: %s\n\nRestore this backup?",
                (string) ( $payload['run_uuid'] ?? '' ),
            );
        }
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => $operation === 'restore_file'
                ? [
                    'run_uuid' => (string) ( $payload['run_uuid'] ?? '' ),
                    'relative_path' => (string) ( $payload['relative_path'] ?? '' ),
                ]
                : [
                    'run_uuid' => (string) ( $payload['run_uuid'] ?? '' ),
                ],
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? 'system.backup.execute' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => $operation,
                'top_level_intent' => 'EXECUTE',
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => $operation === 'backup_validate'
                            ? 'backup validate workflow review'
                            : ( $operation === 'restore_file' ? 'backup file restore workflow review' : 'backup restore workflow review' ),
                        'intent' => $operation,
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => $payload,
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function buildCronTaskReview( array $request ): array {
        $command = $this->commands->definition( 'create_job' );
        $payload = [ 'task_slug' => trim( (string) ( $request['task_slug'] ?? '' ) ) ];
        $intent = [
            'action' => 'create_job',
            'top_level_intent' => 'CREATE',
            'payload' => $payload,
        ];
        $plan = [
            'operation' => 'create_job',
            'title' => (string) ( $command['title'] ?? 'Queue Cron Task' ),
            'required_permission' => (string) ( $command['permission'] ?? '' ),
            'steps' => [ 'create_job' ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        $response['message'] = sprintf(
            "Review:\nCron task: %s\n\nQueue this cron task?",
            (string) ( $payload['task_slug'] ?? '' )
        );
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => $payload,
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? '' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => 'create_job',
                'top_level_intent' => 'CREATE',
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => 'create job workflow review',
                        'intent' => 'create_job',
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => $payload,
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function workerJobQuestionResponse( string $operation ): array {
        return $this->workflowResponse(
            'workflow_question',
            'WorkflowQuestion',
            $operation === 'retry_job'
                ? 'Which job code should be retried?'
                : 'Which job code should be canceled?'
        );
    }

    private function buildWorkerJobReview( string $operation, array $request ): array {
        $command = $this->commands->definition( $operation );
        $payload = [
            'job_code' => strtoupper( trim( (string) ( $request['job_code'] ?? $request['job_key'] ?? '' ) ) ),
        ];
        $intent = [
            'action' => $operation,
            'top_level_intent' => 'EXECUTE',
            'payload' => $payload,
        ];
        $plan = [
            'operation' => $operation,
            'title' => (string) ( $command['title'] ?? ucwords( str_replace( '_', ' ', $operation ) ) ),
            'required_permission' => (string) ( $command['permission'] ?? '' ),
            'steps' => [ $operation ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        $response['message'] = sprintf(
            "Review:\nJob code: %s\n\n%s this job?",
            (string) ( $payload['job_code'] ?? '' ),
            $operation === 'retry_job' ? 'Retry' : 'Cancel'
        );
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => $payload,
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? '' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => $operation,
                'top_level_intent' => 'EXECUTE',
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => strtolower( str_replace( '_', ' ', $operation ) ) . ' workflow review',
                        'intent' => $operation,
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => $payload,
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function isPersonActionWorkflow( string $operation ): bool {
        return in_array( $operation, [
            'disable_user',
            'offboard_user',
            'enable_user',
            'user_delete',
            'user_unlock',
            'workspace_user_disable',
            'workspace_user_enable',
            'workspace_user_delete',
            'reset_user_mfa',
            'link_drive_folder',
        ], true );
    }

    private function personActionQuestionResponse( string $operation ): array {
        return $this->workflowResponse(
            'workflow_question',
            'WorkflowQuestion',
            match ( $operation ) {
                'disable_user', 'offboard_user' => 'Which user should be disabled?',
                'enable_user' => 'Which user should be enabled?',
                'user_delete' => 'Which user should be deleted?',
                'user_unlock' => 'Which user should be unlocked?',
                'workspace_user_disable' => 'Which user should have Workspace access disabled?',
                'workspace_user_enable' => 'Which user should have Workspace access enabled?',
                'workspace_user_delete' => 'Which Workspace user should be deleted?',
                'reset_user_mfa' => 'Which user should have MFA reset?',
                'link_drive_folder' => 'Which user should get a Drive folder linked?',
                default => 'Which user should be updated?',
            }
        );
    }

    private function buildPersonActionReview( string $operation, array $request ): array {
        $command = $this->commands->definition( $operation );
        $payload = [
            'subject' => trim( (string) ( $request['subject'] ?? '' ) ),
        ];
        $intent = [
            'action' => $operation,
            'top_level_intent' => (string) ( $command['top_level_intent'] ?? 'EXECUTE' ),
            'payload' => $payload,
        ];
        $plan = [
            'operation' => $operation,
            'title' => (string) ( $command['title'] ?? ucwords( str_replace( '_', ' ', $operation ) ) ),
            'required_permission' => (string) ( $command['permission'] ?? '' ),
            'steps' => [ $operation ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        $response['message'] = sprintf(
            "Review:\nUser: %s\n\n%s?",
            (string) ( $payload['subject'] ?? '' ),
            $this->personActionPromptLabel( $operation )
        );
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => $payload,
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? '' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => $operation,
                'top_level_intent' => (string) ( $command['top_level_intent'] ?? 'EXECUTE' ),
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => strtolower( str_replace( '_', ' ', $operation ) ) . ' workflow review',
                        'intent' => $operation,
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => $payload,
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function personActionPromptLabel( string $operation ): string {
        return match ( $operation ) {
            'disable_user', 'offboard_user' => 'Disable this user',
            'enable_user' => 'Enable this user',
            'user_delete' => 'Delete this user',
            'user_unlock' => 'Unlock this user',
            'workspace_user_disable' => 'Disable Workspace access for this user',
            'workspace_user_enable' => 'Enable Workspace access for this user',
            'workspace_user_delete' => 'Delete this Workspace user',
            'reset_user_mfa' => 'Reset MFA for this user',
            'link_drive_folder' => 'Link a Drive folder for this user',
            default => 'Proceed with this user action',
        };
    }

    private function isPeopleMembershipWorkflow( string $operation ): bool {
        return in_array( $operation, [
            'assign_role',
            'remove_role',
            'manage_user_roles',
            'manage_workspace_groups',
        ], true );
    }

    private function nextPeopleMembershipStep( string $operation, array $request ): string {
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        if ( $subject === '' ) {
            return 'subject';
        }

        if ( in_array( $operation, [ 'assign_role', 'remove_role', 'manage_user_roles' ], true ) ) {
            $roles = array_values( array_filter( array_map( 'strval', (array) ( $request['roles'] ?? [] ) ) ) );
            if ( $roles === [] ) {
                return 'roles';
            }
        }

        if ( $operation === 'manage_workspace_groups' ) {
            $groupEmails = array_values( array_filter( array_map( 'strval', (array) ( $request['group_emails'] ?? [] ) ) ) );
            if ( $groupEmails === [] ) {
                return 'group_emails';
            }
        }

        return 'review';
    }

    private function peopleMembershipQuestionResponse( string $operation, string $step ): array {
        if ( $step === 'roles' ) {
            return $this->workflowResponse(
                'workflow_question',
                'WorkflowQuestion',
                in_array( $operation, [ 'remove_role' ], true )
                    ? 'Which role should be removed?'
                    : 'Which role should be assigned?'
            );
        }

        if ( $step === 'group_emails' ) {
            return $this->workflowResponse(
                'workflow_question',
                'WorkflowQuestion',
                'Which Workspace group email should be updated?'
            );
        }

        return $this->workflowResponse(
            'workflow_question',
            'WorkflowQuestion',
            $operation === 'manage_workspace_groups'
                ? 'Which user should have Workspace groups updated?'
                : 'Which user should have roles updated?'
        );
    }

    private function buildPeopleMembershipReview( string $operation, array $request ): array {
        $command = $this->commands->definition( $operation );
        $payload = [
            'subject' => trim( (string) ( $request['subject'] ?? '' ) ),
        ];
        if ( in_array( $operation, [ 'assign_role', 'remove_role', 'manage_user_roles' ], true ) ) {
            $payload['roles'] = array_values( array_filter( array_map( 'strval', (array) ( $request['roles'] ?? [] ) ) ) );
            $payload['mode'] = (string) ( $request['mode'] ?? ( $operation === 'remove_role' ? 'remove' : 'add' ) );
        } else {
            $payload['group_emails'] = array_values( array_filter( array_map( 'strval', (array) ( $request['group_emails'] ?? [] ) ) ) );
            $payload['mode'] = (string) ( $request['mode'] ?? 'add' );
        }

        $intent = [
            'action' => $operation,
            'top_level_intent' => 'EXECUTE',
            'payload' => $payload,
        ];
        $plan = [
            'operation' => $operation,
            'title' => (string) ( $command['title'] ?? ucwords( str_replace( '_', ' ', $operation ) ) ),
            'required_permission' => (string) ( $command['permission'] ?? '' ),
            'steps' => [ $operation ],
        ];
        $response = $this->responses->awaitingApproval( $intent, $plan, [] );
        $response['response_type'] = 'WorkflowReview';
        $response['message'] = $this->buildPeopleMembershipReviewMessage( $operation, $payload );
        $response['ui_components'][] = [
            'type' => 'WorkflowReview',
            'fields' => $payload,
        ];

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => [],
            'action_plan' => $plan,
            'permission' => [
                'status' => 'granted',
                'required_permission' => (string) ( $command['permission'] ?? '' ),
                'reason' => '',
            ],
            'response' => $response,
            'parsed' => [
                'selected_intent' => $operation,
                'top_level_intent' => 'EXECUTE',
                'execution_plan' => [
                    [
                        'step' => 1,
                        'fragment' => strtolower( str_replace( '_', ' ', $operation ) ) . ' workflow review',
                        'intent' => $operation,
                        'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                        'payload' => $payload,
                        'requires_approval' => true,
                    ],
                ],
            ],
        ];
    }

    private function buildPeopleMembershipReviewMessage( string $operation, array $payload ): string {
        $lines = [
            'Review:',
            'User: ' . (string) ( $payload['subject'] ?? '' ),
        ];

        if ( in_array( $operation, [ 'assign_role', 'remove_role', 'manage_user_roles' ], true ) ) {
            $lines[] = 'Roles: ' . implode( ', ', (array) ( $payload['roles'] ?? [] ) );
            $lines[] = 'Mode: ' . (string) ( $payload['mode'] ?? 'add' );
            $lines[] = '';
            $lines[] = 'Update this user\'s roles?';
        } else {
            $lines[] = 'Workspace groups: ' . implode( ', ', (array) ( $payload['group_emails'] ?? [] ) );
            $lines[] = 'Mode: ' . (string) ( $payload['mode'] ?? 'add' );
            $lines[] = '';
            $lines[] = 'Update this user\'s Workspace groups?';
        }

        return implode( "\n", $lines );
    }

    /**
     * @return array<int,string>
     */
    private function extractGroupEmails( string $query ): array {
        if ( ! preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches ) ) {
            return [];
        }

        return array_values( array_unique( array_map(
            static fn ( string $email ): string => strtolower( trim( $email ) ),
            (array) ( $matches[0] ?? [] )
        ) ) );
    }

    private function isContentOperationWorkflow( string $operation ): bool {
        return in_array( $operation, [
            'campaign_update',
            'campaign_publish',
            'campaign_archive',
            'campaign_delete',
            'newsletter_send',
            'newsletter_schedule',
            'newsletter_cancel',
            'newsletter_delete',
            'board_workspace_prepare',
        ], true );
    }

    private function contentOperationLabel( string $operation ): string {
        if ( $operation === 'board_workspace_prepare' ) {
            return 'board meeting workspace';
        }

        return str_starts_with( $operation, 'campaign_' ) ? 'campaign' : 'newsletter';
    }

    private function contentOperationVerb( string $operation ): string {
        return match ( $operation ) {
            'campaign_update' => 'updated',
            'campaign_publish', 'newsletter_send' => 'sent',
            'campaign_archive' => 'archived',
            'campaign_delete', 'newsletter_delete' => 'deleted',
            'newsletter_schedule' => 'scheduled',
            'newsletter_cancel' => 'canceled',
            'board_workspace_prepare' => 'prepared',
            default => 'updated',
        };
    }

    private function extractWorkflowSubject( string $query ): string {
        $subject = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
        $subject = preg_replace( '/^(?:for|user|person)\s+/i', '', $subject ) ?? $subject;
        $subject = preg_replace( '/^(?:reset|change|set)\s+/i', '', $subject ) ?? $subject;
        $subject = preg_replace( '/\s+(?:metis|workspace|google|local|internal)?\s*password$/i', '', $subject ) ?? $subject;
        return trim( $subject );
    }

    private function extractPasswordScope( string $query ): string {
        $normalized = strtolower( trim( $query ) );
        if ( str_contains( $normalized, 'workspace' ) || str_contains( $normalized, 'google' ) ) {
            return 'workspace';
        }
        if ( str_contains( $normalized, 'metis' ) || str_contains( $normalized, 'local' ) || str_contains( $normalized, 'internal' ) ) {
            return 'metis';
        }

        return '';
    }

    private function extractRunUuid( string $query ): string {
        if ( preg_match( '/\b(run_[a-z0-9_-]+)\b/i', $query, $matches ) || preg_match( '/\b(?:from|run)\s+([a-z0-9_-]{8,})\b/i', $query, $matches ) ) {
            return trim( strtolower( (string) ( $matches[1] ?? '' ) ) );
        }

        return '';
    }

    private function extractRestoreFilePath( string $query ): string {
        if ( preg_match( '/["\']([^"\']+)["\']/', $query, $matches ) ) {
            return trim( str_replace( '\\', '/', (string) ( $matches[1] ?? '' ) ) );
        }

        if ( preg_match( '#\b(config/[^\s]+|storage/[^\s]+)#i', $query, $matches ) ) {
            return trim( str_replace( '\\', '/', (string) ( $matches[1] ?? '' ) ) );
        }

        return '';
    }

    private function nextBackupWorkflowStep( string $operation, array $request ): string {
        if ( trim( (string) ( $request['run_uuid'] ?? '' ) ) === '' ) {
            return 'run_uuid';
        }

        if ( $operation === 'restore_file' && trim( (string) ( $request['relative_path'] ?? '' ) ) === '' ) {
            return 'relative_path';
        }

        return 'review';
    }

    private function passwordResetRequestFromProcessed( array $processed ): array {
        $payload = (array) ( $processed['intent']['payload'] ?? [] );
        $request = (array) ( $payload['password_request'] ?? $payload );
        if ( isset( $request['subject'] ) ) {
            $request['subject'] = $this->extractWorkflowSubject( (string) $request['subject'] );
        }

        return $request;
    }

    private function shouldClarifyPasswordScope( array $processed ): bool {
        $parsed = (array) ( $processed['parsed'] ?? [] );
        $normalizedInput = strtolower( trim( (string) ( $parsed['normalized_input'] ?? '' ) ) );
        $mentionsPassword = str_contains( $normalizedInput, 'password' );
        $mentionsScope = str_contains( $normalizedInput, 'workspace' )
            || str_contains( $normalizedInput, 'google' )
            || str_contains( $normalizedInput, 'metis' )
            || str_contains( $normalizedInput, 'local' )
            || str_contains( $normalizedInput, 'internal' );

        if ( $mentionsPassword && ! $mentionsScope ) {
            return true;
        }

        foreach ( (array) ( $parsed['alternative_intents'][0] ?? [] ) as $candidate ) {
            if ( strtolower( trim( (string) ( $candidate['intent'] ?? '' ) ) ) === 'workspace_user_password_reset' ) {
                return true;
            }
        }

        return false;
    }

    private function isExpired( string $updatedAt ): bool {
        if ( $updatedAt === '' ) {
            return false;
        }

        $timestamp = strtotime( $updatedAt );
        if ( $timestamp === false ) {
            return false;
        }

        return $timestamp < ( time() - self::WORKFLOW_TTL_SECONDS );
    }
}
