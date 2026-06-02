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

        $workflowType = (string) ( $workflow['type'] ?? '' );
        if ( ! in_array( $workflowType, [ 'create_user', 'workspace_user_create' ], true ) ) {
            return null;
        }

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

    public function beginIfApplicable( array $session, array $processed ): ?array {
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        if ( $sessionCode === '' ) {
            return null;
        }

        $command = (array) ( $processed['command'] ?? [] );
        $intentAction = (string) ( $processed['intent']['action'] ?? '' );
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

        return [
            'intent' => [
                'action' => $workflowType . '_workflow',
                'top_level_intent' => 'CREATE',
                'payload' => [ 'user_request' => $request ],
            ],
            'command' => $command,
            'context_packs' => [],
            'action_plan' => [],
            'permission' => [ 'status' => 'not_applicable', 'required_permission' => '', 'reason' => '' ],
            'response' => $this->questionResponse( $step ),
            'parsed' => (array) ( $processed['parsed'] ?? [] ),
        ];
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
