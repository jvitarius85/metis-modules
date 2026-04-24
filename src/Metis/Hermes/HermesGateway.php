<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\Cache\CacheService;

final class HermesGateway {
    private const SECRET_REVEAL_TTL_SECONDS = 600;

    public function __construct(
        private readonly HermesRepository $repository,
        private readonly HermesReasoner $reasoner,
        private readonly HermesActionPreview $preview,
        private readonly HermesToolRegistry $tools,
        private readonly HermesDiagnosticEngine $diagnostics,
        private readonly HermesMissionEngine $missions,
        private readonly HermesKnowledgeService $knowledge,
        private readonly HermesMemoryStore $memory,
        private readonly HermesAuditLogger $audit,
        private readonly HermesOperationalEngine $operations
    ) {}

    public function dashboardPayload(): array {
        $user_id       = \metis_current_user_id();
        $this->repository->purgeExpiredConversationData( 24 );
        $can_manage    = \Metis\Modules\Hermes\Access::canManage();
        $library       = \Metis\Core\Application::service( 'hermes_library' );
        $context_packs = array_values( $library->contextPacks() );
        $pending       = $user_id > 0 ? $this->repository->pendingActionsForUser( $user_id, 8 ) : [];
        $sessions      = $user_id > 0 ? $this->repository->recentSessions( $user_id, 6 ) : [];
        $chat_session  = $user_id > 0 ? $this->repository->latestSessionForUser( $user_id ) : null;
        $chat_history  = is_array( $chat_session ) ? $this->repository->sessionMessages( (int) ( $chat_session['id'] ?? 0 ), 80 ) : [];
        $dashboard = CacheService::remember( 'dashboard.kpis', 60, function () use ( $context_packs ): array {
            $reports = $this->repository->recentReports( 10 );
            $queue = $this->repository->queueSummary();
            $diagnostics = $this->diagnostics->run( [ 'context_packs' => $context_packs ] );
            $cron = $this->cronSnapshot();
            $reconciliation = $this->reconciliationSnapshot();
            $permission_issues = $this->permissionInconsistencies( $context_packs );
            $integration_failures = $this->integrationFailures( $cron, $queue, $reconciliation, $permission_issues, $diagnostics );
            $alerts = $this->alerts( $cron, $queue, $reconciliation, $permission_issues, $diagnostics );
            $module_summaries = $this->moduleSummaries( $context_packs, $alerts, $permission_issues, $reconciliation, $cron, $queue, $diagnostics );

            return [
                'reports' => $reports,
                'queue' => $queue,
                'diagnostics' => $diagnostics,
                'workers' => $cron,
                'reconciliation' => $reconciliation,
                'permission_issues' => $permission_issues,
                'integration_failures' => $integration_failures,
                'alerts' => $alerts,
                'module_summaries' => $module_summaries,
            ];
        } );
        $reports = (array) ( $dashboard['reports'] ?? [] );
        $queue = (array) ( $dashboard['queue'] ?? [] );
        $diagnostics = (array) ( $dashboard['diagnostics'] ?? [] );
        $cron = (array) ( $dashboard['workers'] ?? [] );
        $reconciliation = (array) ( $dashboard['reconciliation'] ?? [] );
        $permission_issues = (array) ( $dashboard['permission_issues'] ?? [] );
        $integration_failures = (array) ( $dashboard['integration_failures'] ?? [] );
        $alerts = (array) ( $dashboard['alerts'] ?? [] );
        $module_summaries = (array) ( $dashboard['module_summaries'] ?? [] );
        $trends = $this->diagnosticTrends( $reports );

        return [
            'generated_at' => \metis_current_time( 'mysql' ),
            'view_mode' => $can_manage ? 'operator' : 'observer',
            'capabilities' => [
                'can_manage' => $can_manage,
                'can_view'   => true,
            ],
            'overview' => [
                'module_count' => count( $module_summaries ),
                'alert_count' => count( $alerts ),
                'high_alert_count' => count( array_filter( $alerts, fn ( array $alert ): bool => $this->severityRank( (string) ( $alert['severity'] ?? 'low' ) ) >= $this->severityRank( 'high' ) ) ),
                'integration_failure_count' => count( $integration_failures ),
                'worker_issue_count' => (int) ( $cron['summary']['issue_count'] ?? 0 ) + ( (int) ( $queue['failed_count'] ?? 0 ) > 0 ? 1 : 0 ),
                'pending_action_count' => count( $pending ),
                'diagnostic_report_count' => count( $reports ),
                'reconciliation_anomaly_count' => (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ),
                'permission_issue_count' => count( $permission_issues ),
            ],
            'sessions' => $sessions,
            'chat_session' => $chat_session,
            'chat_history' => $chat_history,
            'reports' => $reports,
            'pending_actions' => $pending,
            'missions' => array_values( $library->missions() ),
            'playbooks' => array_values( $library->playbooks() ),
            'tools' => $this->tools->definitions(),
            'diagnostics' => $diagnostics,
            'alerts' => $alerts,
            'module_summaries' => $module_summaries,
            'integration_failures' => $integration_failures,
            'workers' => $cron,
            'queue' => $queue,
            'reconciliation' => $reconciliation,
            'permission_inconsistencies' => $permission_issues,
            'diagnostic_trends' => $trends,
        ];
    }

    public function converse( string $query, string $session_code = '' ): array {
        $user_id = \metis_current_user_id();
        $session = $this->repository->ensureSession( $user_id, $session_code, $this->deriveTitle( $query ) );
        $user_message = $this->repository->saveMessage( (int) $session['id'], 'user', $query );

        try {
            $processed = $this->operations->process( $query );
            $response = (array) ( $processed['response'] ?? [] );
            $actions = [];
            if ( (string) ( $response['status'] ?? '' ) === 'awaiting_approval' && is_array( $processed['command'] ?? null ) ) {
                $payload = [
                    'intent' => (array) ( $processed['intent'] ?? [] ),
                    'parser' => (array) ( $processed['parsed'] ?? [] ),
                    'query' => $query,
                    'operation' => (string) ( $processed['action_plan']['operation'] ?? '' ),
                    'command_payload' => (array) ( $processed['intent']['payload'] ?? [] ),
                    'execution_plan' => (array) ( $processed['parsed']['execution_plan'] ?? [] ),
                    'action_plan' => (array) ( $processed['action_plan'] ?? [] ),
                    'context_packs' => (array) ( $processed['context_packs'] ?? [] ),
                    'required_permission' => (string) ( $processed['action_plan']['required_permission'] ?? '' ),
                ];
                $preview = $this->preview->preview( 'hermes_command', $payload, [
                    'title' => (string) ( $processed['action_plan']['title'] ?? 'Hermes Command' ),
                    'summary' => (string) ( $response['message'] ?? 'Approval required.' ),
                ] );

                $actions[] = $this->repository->createAction(
                    (int) $session['id'],
                    0,
                    'hermes_command',
                    (string) ( $processed['action_plan']['title'] ?? 'Hermes Action' ),
                    $payload,
                    $preview
                );
            }
            $reasoning = [
                'intent' => (string) ( $processed['intent']['action'] ?? 'unknown' ),
                'answer' => (string) ( $response['message'] ?? '' ),
                'structured' => $response,
            ];
            $assistant_message = $this->repository->saveMessage( (int) $session['id'], 'hermes', (string) ( $response['message'] ?? '' ), $reasoning );

            if ( $actions !== [] ) {
                foreach ( $actions as &$action ) {
                    if ( ! is_array( $action ) ) {
                        continue;
                    }
                    $response['ui_components'][] = [
                        'type' => 'ApprovalPrompt',
                        'action_code' => (string) ( $action['action_code'] ?? '' ),
                        'buttons' => [ 'Approve', 'Cancel' ],
                    ];
                }
            }

            $this->repository->touchSession( (int) $session['id'], (string) ( $processed['intent']['action'] ?? 'conversation' ) );
            $this->memory->rememberConversation( (string) ( $session['session_code'] ?? '' ), [
                'query' => $query,
                'answer' => (string) ( $response['message'] ?? '' ),
                'intent' => (string) ( $processed['intent']['action'] ?? '' ),
            ] );
            $this->audit->commandTrace( [
                'session_code' => (string) ( $session['session_code'] ?? '' ),
                'user_id' => $user_id,
                'raw_input' => $query,
                'normalized_input' => (string) ( $processed['parsed']['normalized_input'] ?? strtolower( trim( $query ) ) ),
                'selected_intent' => (string) ( $processed['parsed']['selected_intent'] ?? $processed['intent']['action'] ?? '' ),
                'tool_key' => (string) ( $processed['command']['tool_key'] ?? '' ),
                'confidence_score' => (float) ( $processed['parsed']['confidence_score'] ?? 0 ),
                'payload' => (array) ( $processed['intent']['payload'] ?? [] ),
                'result' => (array) ( $response ?? [] ),
            ] );
            $this->audit->conversation( 'query', [
                'session_code' => (string) ( $session['session_code'] ?? '' ),
                'intent' => (string) ( $processed['intent']['action'] ?? '' ),
            ] );

            return array_merge( $response, [
                'session' => $session,
                'user_message' => $user_message,
                'assistant_message' => $assistant_message,
                'history' => $this->repository->sessionMessages( (int) $session['id'], 80 ),
                'reasoning' => $reasoning,
                'actions' => $actions,
            ] );
        } catch ( \Throwable $e ) {
            $message = 'Hermes could not complete that request.';
            $reasoning = [
                'intent' => 'error',
                'answer' => $message,
                'structured' => [
                    'status' => 'error',
                    'message' => $message,
                    'detail' => $e->getMessage(),
                ],
            ];
            $assistant_message = $this->repository->saveMessage( (int) $session['id'], 'hermes', $message, $reasoning );

            return [
                'status' => 'error',
                'message' => $message,
                'session' => $session,
                'user_message' => $user_message,
                'assistant_message' => $assistant_message,
                'history' => $this->repository->sessionMessages( (int) $session['id'], 80 ),
                'reasoning' => $reasoning,
                'actions' => [],
            ];
        }
    }

    public function diagnostics( string $query, string $session_code = '' ): array {
        $session = $this->repository->ensureSession( \metis_current_user_id(), $session_code, 'Hermes Diagnostics' );

        try {
            $reasoning = $this->reasoner->reason( $query !== '' ? $query : 'system health diagnostic', $session );
            $report = $this->repository->saveReport( 'diagnostic', (string) ( $reasoning['intent'] ?? 'diagnostic' ), (array) ( $reasoning['diagnostics'] ?? [] ), (int) ( $session['id'] ?? 0 ) );
            $this->memory->rememberReport( (string) ( $report['report_code'] ?? '' ), (array) ( $reasoning['diagnostics'] ?? [] ) );

            return [
                'session' => $session,
                'diagnostics' => $reasoning['diagnostics'],
                'report' => $report,
            ];
        } catch ( \Throwable $e ) {
            return [
                'session' => $session,
                'diagnostics' => [
                    'summary' => [ 'finding_count' => 0, 'high_severity' => 0 ],
                    'findings' => [],
                ],
                'report' => null,
                'status' => 'error',
                'message' => 'Hermes diagnostics failed.',
                'detail' => $e->getMessage(),
            ];
        }
    }

    public function previewAction( string $action_code ): array {
        $action = $this->repository->getActionByCode( $action_code );
        if ( ! is_array( $action ) ) {
            throw new \RuntimeException( 'Hermes action not found.' );
        }

        return [
            'action' => $action,
            'preview' => $action['preview'] ?? $this->preview->preview( (string) $action['action_type'], (array) $action['payload'], $this->tools->definition( (string) $action['action_type'] ) ),
        ];
    }

    public function approveAction( string $action_code, string $note = '' ): array {
        $action = $this->repository->getActionByCode( $action_code );
        if ( ! is_array( $action ) ) {
            throw new \RuntimeException( 'Hermes action could not be approved.' );
        }

        $payload = (array) ( $action['payload'] ?? [] );
        if ( (string) ( $action['action_type'] ?? '' ) === 'hermes_command' || ! empty( $payload['operation'] ) ) {
            $prepared = $this->operations->validatePreparedAction( $payload );
            $permission = (array) ( $prepared['permission'] ?? [] );
            if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
                throw new \RuntimeException( (string) ( $permission['reason'] ?? 'Permission denied.' ) );
            }
        }

        $action = $this->repository->approveAction( $action_code, \metis_current_user_id(), $note );
        if ( ! is_array( $action ) ) {
            throw new \RuntimeException( 'Hermes action could not be approved.' );
        }

        $this->audit->approval( 'action_approved', $action_code, [ 'status' => 'approved' ] );
        return $action;
    }

    public function executeAction( string $action_code ): array {
        $action = $this->repository->getActionByCode( $action_code );
        if ( ! is_array( $action ) ) {
            throw new \RuntimeException( 'Hermes action not found.' );
        }

        if ( (string) ( $action['approval_status'] ?? '' ) === 'executed' ) {
            return [
                'action' => $action,
                'result' => (array) ( $action['result'] ?? [] ),
                'replayed' => true,
            ];
        }

        if ( (string) ( $action['approval_status'] ?? '' ) !== 'approved' ) {
            throw new \RuntimeException( 'Hermes action requires approval before execution.' );
        }

        try {
            $request = \metis_security_runtime_request_context( [
                'action_code' => $action_code,
                'action_type' => (string) ( $action['action_type'] ?? '' ),
                'metis_action_nonce' => $this->requestNonce(),
                'nonce' => $this->requestNonce(),
            ] );

            $result = \metis_security_enclave()->execute(
                'hermes.action.execute',
                $request,
                function ( array $input, array $context ) use ( $action ): array {
                    $payload = (array) ( $action['payload'] ?? [] );
                    $type = (string) ( $action['action_type'] ?? '' );
                    $actor = (array) ( $context['actor'] ?? [] );

                    if ( $type === 'hermes_command' || ! empty( $payload['operation'] ) ) {
                        return $this->operations->executePreparedAction( $payload, $actor );
                    }

                    return match ( $type ) {
                        'run_diagnostic' => $this->diagnostics( (string) ( $payload['query'] ?? 'system health diagnostic' ), '' ),
                        'open_help_topic' => [ 'help_topic' => \Metis\Core\Application::service( 'hermes_help_resolver' )->topic( (string) ( $payload['topic_id'] ?? '' ) ) ],
                        'launch_walkthrough' => $this->launchWalkthrough( (string) ( $payload['walkthrough_id'] ?? '' ) ),
                        'execute_mission' => $this->executeMission( (string) ( $payload['mission_key'] ?? '' ), (string) ( $payload['query'] ?? '' ) ),
                        'queue_scheduled_diagnostics' => $this->enqueueScheduledDiagnostics(),
                        default => throw new \RuntimeException( 'Unsupported Hermes action.' ),
                    };
                }
            );
        } catch ( \Throwable $e ) {
            $result = [
                'status' => 'error',
                'message' => 'Action execution failed.',
                'detail' => $e->getMessage(),
            ];
        }

        $redacted = $this->redactSensitiveActionResult( $result, $action );
        $storedResult = (array) ( $redacted['stored'] ?? [] );
        $responseResult = (array) ( $redacted['response'] ?? [] );

        try {
            $saved = $this->repository->markActionExecuted( $action_code, $storedResult );
        } catch ( \Throwable $e ) {
            $saved = $action;
            $responseResult = [
                'status' => 'error',
                'message' => 'Action executed but result persistence failed.',
                'detail' => $e->getMessage(),
                ];
        }
        try {
            $this->audit->approval( 'action_executed', $action_code, [ 'status' => 'executed' ] );
            $this->audit->commandTrace( [
                'session_code' => (string) ( $action['session_code'] ?? '' ),
                'user_id' => \metis_current_user_id(),
                'raw_input' => (string) ( $action['title'] ?? '' ),
                'normalized_input' => (string) ( $action['title'] ?? '' ),
                'selected_intent' => (string) ( $action['action_type'] ?? '' ),
                'tool_key' => (string) ( $action['payload']['action_plan']['tool_key'] ?? '' ),
                'payload' => (array) ( $action['payload'] ?? [] ),
                'result' => $responseResult,
            ] );
        } catch ( \Throwable ) {
            // Never fail an action response due to audit write issues.
        }

        return [
            'action' => $saved,
            'result' => $responseResult,
        ];
    }

    public function revealSecret( string $reveal_token ): array {
        $reveal_token = \metis_key_clean( trim( $reveal_token ) );
        if ( $reveal_token === '' ) {
            throw new \RuntimeException( 'Missing reveal token.' );
        }

        $cacheKey = $this->secretRevealCacheKey( $reveal_token );
        $payload = CacheService::get( $cacheKey );
        if ( ! is_array( $payload ) ) {
            throw new \RuntimeException( 'This secret is no longer available. Re-run the reset action to generate a new one.' );
        }

        $actorId = \metis_current_user_id();
        $approvedBy = (int) ( $payload['approved_by'] ?? 0 );
        if ( $actorId < 1 || $approvedBy < 1 || $actorId !== $approvedBy ) {
            throw new \RuntimeException( 'Only the approving operator can reveal this secret.' );
        }

        $secret = (string) ( $payload['secret'] ?? '' );
        if ( $secret === '' ) {
            CacheService::forget( $cacheKey );
            throw new \RuntimeException( 'Secret payload is invalid.' );
        }

        CacheService::forget( $cacheKey );
        $actionCode = (string) ( $payload['action_code'] ?? '' );
        $this->audit->approval( 'secret_revealed', $actionCode, [ 'status' => 'revealed_once' ] );

        return [
            'status' => 'success',
            'label' => (string) ( $payload['label'] ?? 'Temporary password' ),
            'secret' => $secret,
            'message' => 'Secret revealed. This value will not be shown again.',
            'consumed' => true,
        ];
    }

    private function requestNonce(): string {
        foreach ( [ 'metis_action_nonce', '_wpnonce', 'security', '_ajax_nonce', 'nonce' ] as $field ) {
            $value = $_REQUEST[ $field ] ?? '';
            if ( is_string( $value ) ) {
                $value = \trim( \metis_runtime_unslash( $value ) );
                if ( $value !== '' ) {
                    return $value;
                }
            }
        }

        return '';
    }

    private function redactSensitiveActionResult( array $result, array $action ): array {
        $stored = $result;
        $response = $result;
        $reveals = [];
        $approvedBy = (int) ( $action['approved_by'] ?? 0 );
        if ( $approvedBy < 1 ) {
            $approvedBy = \metis_current_user_id();
        }
        $actionCode = (string) ( $action['action_code'] ?? '' );

        $this->redactSecretPath( $stored, $response, 'credential_package.password', 'Temporary password', $actionCode, $approvedBy, $reveals );
        $this->redactSecretPath( $stored, $response, 'workspace.password', 'Workspace temporary password', $actionCode, $approvedBy, $reveals );
        if ( isset( $stored['result'] ) && is_array( $stored['result'] ) ) {
            $nestedReveals = [];
            $this->redactSecretPath( $stored['result'], $response['result'], 'credential_package.password', 'Temporary password', $actionCode, $approvedBy, $nestedReveals );
            $this->redactSecretPath( $stored['result'], $response['result'], 'workspace.password', 'Workspace temporary password', $actionCode, $approvedBy, $nestedReveals );
            if ( $nestedReveals !== [] ) {
                $stored['result']['secret_reveals'] = array_map(
                    static fn ( array $item ): array => [
                        'label' => (string) ( $item['label'] ?? 'Secret' ),
                        'field' => (string) ( $item['field'] ?? '' ),
                        'revealed' => false,
                    ],
                    $nestedReveals
                );
                $response['result']['secret_reveals'] = $nestedReveals;
            }
            $reveals = array_merge( $reveals, $nestedReveals );
        }

        if ( $reveals !== [] && ( empty( $response['message'] ) || ! is_string( $response['message'] ) ) ) {
            $response['message'] = 'Sensitive credentials were generated. Use "Reveal once" to view them securely.';
        }

        return [
            'stored' => $stored,
            'response' => $response,
        ];
    }

    private function issueSecretRevealToken( string $secret, string $label, string $field, string $actionCode, int $approvedBy ): string {
        $token = strtolower( \metis_generate_code( 'HSR', \Metis_Tables::get( 'hermes_actions' ), 'action_code' ) );
        $token = preg_replace( '/[^a-z0-9]/', '', $token ) ?? '';
        if ( $token === '' ) {
            $token = strtolower( bin2hex( random_bytes( 10 ) ) );
        }

        CacheService::set( $this->secretRevealCacheKey( $token ), [
            'secret' => $secret,
            'label' => $label,
            'field' => $field,
            'action_code' => $actionCode,
            'approved_by' => $approvedBy,
            'created_at' => \metis_current_time( 'mysql' ),
        ], self::SECRET_REVEAL_TTL_SECONDS );

        return $token;
    }

    private function secretRevealCacheKey( string $token ): string {
        return 'hermes.secret_reveal.' . strtolower( trim( $token ) );
    }

    private function redactSecretPath( array &$stored, array &$response, string $path, string $label, string $actionCode, int $approvedBy, array &$reveals ): void {
        $parts = explode( '.', $path );
        if ( count( $parts ) !== 2 ) {
            return;
        }

        $section = (string) $parts[0];
        $field = (string) $parts[1];
        $secret = trim( (string) ( $stored[ $section ][ $field ] ?? '' ) );
        if ( $secret === '' ) {
            return;
        }

        $token = $this->issueSecretRevealToken( $secret, $label, $path, $actionCode, $approvedBy );
        $stored[ $section ][ $field ] = '';
        $response[ $section ][ $field ] = '';
        $response[ $section ]['password_masked'] = true;
        $response[ $section ]['reveal_once'] = true;
        $response[ $section ]['reveal_ttl_seconds'] = self::SECRET_REVEAL_TTL_SECONDS;
        $reveals[] = [
            'token' => $token,
            'label' => $label,
            'field' => $path,
        ];
    }

    public function launchWalkthrough( string $walkthrough_id ): array {
        $resolver = \Metis\Core\Application::service( 'hermes_walkthrough_resolver' );
        $walkthrough = $resolver->get( $walkthrough_id );
        if ( ! is_array( $walkthrough ) ) {
            throw new \RuntimeException( 'Walkthrough not found.' );
        }

        $resolver->markStarted( $walkthrough_id );

        return [
            'walkthrough' => $walkthrough,
            'launched' => true,
        ];
    }

    public function executeMission( string $mission_key, string $query = '' ): array {
        $plan = $this->missions->plan( $mission_key );
        if ( ! is_array( $plan ) ) {
            throw new \RuntimeException( 'Mission not found.' );
        }

        $report = $this->repository->saveReport( 'mission', $mission_key, [
            'mission' => $plan,
            'query' => $query,
            'executed_at' => \metis_current_time( 'mysql' ),
        ] );

        return [
            'mission' => $plan,
            'report' => $report,
        ];
    }

    public function enqueueScheduledDiagnostics(): array {
        $queued = \metis_job_queue()->enqueue(
            'hermes.diagnostics',
            [ 'scope' => 'system' ],
            [
                'queue' => 'hermes',
                'priority' => 15,
                'dedupe_key' => 'hermes:diagnostics:' . gmdate( 'YmdH' ),
                'created_by' => \metis_current_user_id(),
            ]
        );

        return [
            'queued' => $queued,
        ];
    }

    public function runScheduledDiagnostics( string $scope = 'system' ): array {
        $result = $this->diagnostics( $scope . ' health diagnostic' );
        $this->audit->conversation( 'scheduled_diagnostics', [ 'scope' => $scope ] );
        return $result;
    }

    private function cronSnapshot(): array {
        $tasks = \Metis_Cron_Manager::registered_tasks();
        $now   = \metis_current_time( 'timestamp' );
        $rows  = [];
        $issue_count = 0;

        foreach ( $tasks as $slug => $task ) {
            $state = \metis_get_option( 'metis_cron_task_state_' . $slug, [] );
            $state = \is_array( $state ) ? $state : [];
            $last_finished = $this->timestampFromString( (string) ( $state['last_finished_at'] ?? '' ) );
            $next_due = $last_finished > 0 ? $last_finished + (int) ( $task['interval'] ?? 0 ) : $now;
            $lag_seconds = max( 0, $now - $next_due );
            $health = 'healthy';
            $severity = 'low';

            if ( empty( $task['enabled'] ) ) {
                $health = 'disabled';
            } elseif ( ! empty( $state['running'] ) ) {
                $health = 'running';
                $severity = 'medium';
            } elseif ( (string) ( $state['last_status'] ?? '' ) === 'failed' ) {
                $health = 'failed';
                $severity = 'high';
                $issue_count++;
            } elseif ( $lag_seconds > max( (int) ( $task['interval'] ?? 0 ), 300 ) ) {
                $health = 'lagging';
                $severity = 'medium';
                $issue_count++;
            }

            $rows[] = [
                'slug' => $slug,
                'label' => (string) ( $task['label'] ?? $slug ),
                'module' => (string) ( $task['module'] ?? 'core' ),
                'interval' => (int) ( $task['interval'] ?? 0 ),
                'enabled' => ! empty( $task['enabled'] ),
                'health' => $health,
                'severity' => $severity,
                'last_status' => (string) ( $state['last_status'] ?? 'never' ),
                'last_finished_at' => (string) ( $state['last_finished_at'] ?? '' ),
                'last_started_at' => (string) ( $state['last_started_at'] ?? '' ),
                'next_due_at' => $next_due > 0 ? gmdate( 'Y-m-d H:i:s', $next_due ) : '',
                'lag_seconds' => $lag_seconds,
                'last_error' => (string) ( $state['last_error'] ?? '' ),
                'last_trigger' => (string) ( $state['last_trigger'] ?? '' ),
            ];
        }

        return [
            'tasks' => $rows,
            'registered_workers' => \function_exists( 'metis_job_queue' ) ? \metis_job_queue()->registeredWorkers() : [],
            'summary' => [
                'task_count' => count( $rows ),
                'issue_count' => $issue_count,
                'running_count' => count( array_filter( $rows, static fn ( array $row ): bool => ( $row['health'] ?? '' ) === 'running' ) ),
                'failed_count' => count( array_filter( $rows, static fn ( array $row ): bool => ( $row['health'] ?? '' ) === 'failed' ) ),
                'lagging_count' => count( array_filter( $rows, static fn ( array $row ): bool => ( $row['health'] ?? '' ) === 'lagging' ) ),
            ],
        ];
    }

    private function reconciliationSnapshot(): array {
        $db = \metis_db();

        $recons_table = \Metis_Tables::get( 'finance_v2_recon_months' );
        if ( ! $this->tableExists( $recons_table ) ) {
            return [
                'summary' => [
                    'anomaly_count' => 0,
                    'open_count' => 0,
                    'variance_count' => 0,
                ],
                'rows' => [],
            ];
        }

        $summary = $db->fetchOne(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status <> 'finalized' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN ABS(COALESCE(difference_amount, 0)) > 0.009 THEN 1 ELSE 0 END) AS variance_count
             FROM {$recons_table}",
        );

        $rows = $db->fetchAll(
            "SELECT
                recon_month,
                status,
                COALESCE(expected_ending_balance, 0) AS book_balance,
                COALESCE(statement_ending_balance, 0) AS statement_balance,
                COALESCE(difference_amount, 0) AS variance,
                0 AS matched_count
             FROM {$recons_table}
             WHERE status <> 'finalized' OR ABS(COALESCE(difference_amount, 0)) > 0.009
             ORDER BY recon_month DESC, id DESC
             LIMIT 6",
        ) ?: [];

        $open_count = (int) ( $summary['open_count'] ?? 0 );
        $variance_count = (int) ( $summary['variance_count'] ?? 0 );

        return [
            'summary' => [
                'total_count' => (int) ( $summary['total_count'] ?? 0 ),
                'open_count' => $open_count,
                'variance_count' => $variance_count,
                'anomaly_count' => $open_count + $variance_count,
            ],
            'rows' => $rows,
        ];
    }

    private function permissionInconsistencies( array $context_packs ): array {
        $modules = \Metis\Core\Application::service( 'modules' )->all();
        $permissions = \Metis\Core\Application::service( 'permissions' );
        $issues = [];

        foreach ( $context_packs as $pack ) {
            if ( ! is_array( $pack ) ) {
                continue;
            }

            $module_slug = \metis_key_clean( (string) ( $pack['module_slug'] ?? '' ) );
            if ( $module_slug === '' ) {
                continue;
            }

            $module = $modules[ $module_slug ] ?? null;
            $declared = [];

            foreach ( (array) ( $module['config']['permission_definitions'] ?? [] ) as $definition ) {
                if ( ! is_array( $definition ) ) {
                    continue;
                }

                $action = \metis_key_clean( (string) ( $definition['action'] ?? '' ) );
                if ( $action === '' ) {
                    continue;
                }

                $roles = array_values( array_filter( array_map( 'strval', (array) ( $definition['roles'] ?? [] ) ) ) );
                sort( $roles );
                $declared[ $action ] = $roles;
            }

            foreach ( (array) ( $pack['permissions'] ?? [] ) as $permission ) {
                if ( ! is_array( $permission ) ) {
                    continue;
                }

                $action = \metis_key_clean( (string) ( $permission['action'] ?? '' ) );
                $expected = array_values( array_filter( array_map( 'strval', (array) ( $permission['allowed_roles'] ?? [] ) ) ) );
                sort( $expected );
                $actual = $declared[ $action ] ?? [];

                if ( $expected !== $actual ) {
                    $issues[] = [
                        'module_slug' => $module_slug,
                        'pack_key' => (string) ( $pack['key'] ?? $module_slug ),
                        'severity' => $action === 'view' ? 'high' : 'medium',
                        'title' => sprintf( '%s permission map diverges for %s', (string) ( $pack['title'] ?? ucfirst( $module_slug ) ), $action ),
                        'summary' => 'Hermes context-pack role coverage does not match the module manifest roles for this capability.',
                        'expected_roles' => $expected,
                        'actual_roles' => $actual,
                        'action' => $action,
                    ];
                }
            }

            if ( ! $permissions->can( $module_slug, 'view' ) ) {
                $issues[] = [
                    'module_slug' => $module_slug,
                    'pack_key' => (string) ( $pack['key'] ?? $module_slug ),
                    'severity' => 'low',
                    'title' => sprintf( '%s is restricted in the current view', (string) ( $pack['title'] ?? ucfirst( $module_slug ) ) ),
                    'summary' => 'This user can see Hermes health status but does not have direct module visibility for deeper inspection.',
                    'expected_roles' => [],
                    'actual_roles' => [],
                    'action' => 'view',
                ];
            }
        }

        usort( $issues, fn ( array $a, array $b ): int => $this->severityRank( (string) ( $b['severity'] ?? 'low' ) ) <=> $this->severityRank( (string) ( $a['severity'] ?? 'low' ) ) );

        return array_slice( $issues, 0, 12 );
    }

    private function integrationFailures( array $cron, array $queue, array $reconciliation, array $permission_issues, array $diagnostics ): array {
        $rows = [];

        if ( (int) ( $queue['failed_count'] ?? 0 ) > 0 ) {
            $rows[] = [
                'severity' => 'high',
                'title' => 'Hermes worker queue contains failed jobs',
                'summary' => 'Scheduled diagnostics or downstream worker tasks need intervention before they can be trusted as current.',
                'surface' => 'worker',
            ];
        }

        foreach ( (array) ( $cron['tasks'] ?? [] ) as $task ) {
            if ( ! in_array( (string) ( $task['health'] ?? '' ), [ 'failed', 'lagging' ], true ) ) {
                continue;
            }

            $rows[] = [
                'severity' => (string) ( $task['severity'] ?? 'medium' ),
                'title' => sprintf( '%s is %s', (string) ( $task['label'] ?? 'Cron task' ), (string) ( $task['health'] ?? 'unhealthy' ) ),
                'summary' => (string) ( $task['last_error'] ?? '' ) !== ''
                    ? (string) $task['last_error']
                    : 'The worker cadence is outside its expected interval and may be stalling dependent integrations.',
                'surface' => 'cron',
            ];
        }

        if ( (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ) > 0 ) {
            $rows[] = [
                'severity' => 'high',
                'title' => 'Finance reconciliation anomalies detected',
                'summary' => 'Deposit, statement, or ledger matching is producing unresolved variance and should be treated as an integration failure until closed.',
                'surface' => 'reconciliation',
            ];
        }

        foreach ( $permission_issues as $issue ) {
            if ( $this->severityRank( (string) ( $issue['severity'] ?? 'low' ) ) < $this->severityRank( 'medium' ) ) {
                continue;
            }

            $rows[] = [
                'severity' => (string) ( $issue['severity'] ?? 'medium' ),
                'title' => (string) ( $issue['title'] ?? 'Permission inconsistency' ),
                'summary' => (string) ( $issue['summary'] ?? '' ),
                'surface' => 'permissions',
            ];
        }

        foreach ( (array) ( $diagnostics['findings'] ?? [] ) as $finding ) {
            if ( (string) ( $finding['key'] ?? '' ) !== 'board_workspace_health' ) {
                continue;
            }

            $missing = (int) ( $finding['evidence']['missing_workspaces'] ?? 0 );
            if ( $missing < 1 ) {
                continue;
            }

            $rows[] = [
                'severity' => (string) ( $finding['severity'] ?? 'high' ),
                'title' => (string) ( $finding['title'] ?? 'Board workspace integrity' ),
                'summary' => (string) ( $finding['summary'] ?? '' ),
                'surface' => 'board',
            ];
        }

        usort( $rows, fn ( array $a, array $b ): int => $this->severityRank( (string) ( $b['severity'] ?? 'low' ) ) <=> $this->severityRank( (string) ( $a['severity'] ?? 'low' ) ) );

        return array_slice( $rows, 0, 10 );
    }

    private function alerts( array $cron, array $queue, array $reconciliation, array $permission_issues, array $diagnostics ): array {
        $alerts = [];

        if ( (int) ( $queue['failed_count'] ?? 0 ) > 0 || (int) ( $queue['processing_count'] ?? 0 ) > 15 ) {
            $alerts[] = [
                'severity' => (int) ( $queue['failed_count'] ?? 0 ) > 0 ? 'high' : 'medium',
                'module_slug' => 'hermes',
                'title' => 'Worker queue pressure',
                'summary' => sprintf(
                    '%d failed, %d queued, %d processing.',
                    (int) ( $queue['failed_count'] ?? 0 ),
                    (int) ( $queue['queued_count'] ?? 0 ),
                    (int) ( $queue['processing_count'] ?? 0 )
                ),
            ];
        }

        foreach ( (array) ( $cron['tasks'] ?? [] ) as $task ) {
            if ( ! in_array( (string) ( $task['health'] ?? '' ), [ 'failed', 'lagging' ], true ) ) {
                continue;
            }

            $alerts[] = [
                'severity' => (string) ( $task['severity'] ?? 'medium' ),
                'module_slug' => (string) ( $task['module'] ?? 'core' ),
                'title' => sprintf( '%s is %s', (string) ( $task['label'] ?? 'Worker' ), (string) ( $task['health'] ?? 'unhealthy' ) ),
                'summary' => (string) ( $task['last_error'] ?? '' ) !== '' ? (string) $task['last_error'] : 'This worker missed its expected cadence.',
            ];
        }

        if ( (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ) > 0 ) {
            $alerts[] = [
                'severity' => 'high',
                'module_slug' => 'finance',
                'title' => 'Reconciliation anomalies open',
                'summary' => sprintf(
                    '%d open reconciliations and %d variance rows need follow-up.',
                    (int) ( $reconciliation['summary']['open_count'] ?? 0 ),
                    (int) ( $reconciliation['summary']['variance_count'] ?? 0 )
                ),
            ];
        }

        foreach ( $permission_issues as $issue ) {
            if ( $this->severityRank( (string) ( $issue['severity'] ?? 'low' ) ) < $this->severityRank( 'medium' ) ) {
                continue;
            }

            $alerts[] = [
                'severity' => (string) ( $issue['severity'] ?? 'medium' ),
                'module_slug' => (string) ( $issue['module_slug'] ?? 'people' ),
                'title' => (string) ( $issue['title'] ?? 'Permission inconsistency' ),
                'summary' => (string) ( $issue['summary'] ?? '' ),
            ];
        }

        foreach ( (array) ( $diagnostics['findings'] ?? [] ) as $finding ) {
            if ( (string) ( $finding['key'] ?? '' ) !== 'board_workspace_health' ) {
                continue;
            }

            if ( (int) ( $finding['evidence']['missing_workspaces'] ?? 0 ) < 1 ) {
                continue;
            }

            $alerts[] = [
                'severity' => (string) ( $finding['severity'] ?? 'high' ),
                'module_slug' => 'board',
                'title' => (string) ( $finding['title'] ?? 'Board workspace integrity' ),
                'summary' => (string) ( $finding['summary'] ?? '' ),
            ];
        }

        usort( $alerts, fn ( array $a, array $b ): int => $this->severityRank( (string) ( $b['severity'] ?? 'low' ) ) <=> $this->severityRank( (string) ( $a['severity'] ?? 'low' ) ) );

        return array_slice( $alerts, 0, 12 );
    }

    private function moduleSummaries( array $context_packs, array $alerts, array $permission_issues, array $reconciliation, array $cron, array $queue, array $diagnostics ): array {
        $permissions = \Metis\Core\Application::service( 'permissions' );
        $rows = [];

        foreach ( $context_packs as $pack ) {
            if ( ! is_array( $pack ) ) {
                continue;
            }

            $module_slug = \metis_key_clean( (string) ( $pack['module_slug'] ?? '' ) );
            if ( $module_slug === '' ) {
                continue;
            }

            $pack_alerts = array_values( array_filter(
                $alerts,
                static fn ( array $alert ): bool => (string) ( $alert['module_slug'] ?? '' ) === $module_slug
            ) );
            $pack_permission_issues = array_values( array_filter(
                $permission_issues,
                static fn ( array $issue ): bool => (string) ( $issue['module_slug'] ?? '' ) === $module_slug
            ) );

            $status = 'healthy';
            $status_severity = 'low';
            $summary = 'No active Hermes health alerts are open for this module.';

            if ( ! $permissions->can( $module_slug, 'view' ) ) {
                $status = 'restricted';
                $status_severity = 'low';
                $summary = 'Hermes can report the surface, but this operator does not have direct module visibility.';
            } elseif ( $pack_alerts !== [] ) {
                $status_severity = (string) ( $pack_alerts[0]['severity'] ?? 'medium' );
                $status = $this->severityRank( $status_severity ) >= $this->severityRank( 'high' ) ? 'at-risk' : 'monitoring';
                $summary = (string) ( $pack_alerts[0]['summary'] ?? $summary );
            } elseif ( $module_slug === 'finance' && (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ) > 0 ) {
                $status = 'at-risk';
                $status_severity = 'high';
                $summary = 'Reconciliation drift is visible in the latest finance snapshot.';
            } elseif ( $module_slug === 'newsletter' && (int) ( $queue['failed_count'] ?? 0 ) > 0 ) {
                $status = 'monitoring';
                $status_severity = 'medium';
                $summary = 'Hermes workers are reporting queue friction that can affect communications delivery.';
            }

            $rows[] = [
                'key' => (string) ( $pack['key'] ?? $module_slug ),
                'module_slug' => $module_slug,
                'title' => (string) ( $pack['title'] ?? ucfirst( $module_slug ) ),
                'description' => (string) ( $pack['description'] ?? '' ),
                'status' => $status,
                'severity' => $status_severity,
                'summary' => $summary,
                'can_view_module' => $permissions->can( $module_slug, 'view' ),
                'can_edit_module' => $permissions->can( $module_slug, 'edit' ),
                'available_actions' => array_values( (array) ( $pack['available_actions'] ?? [] ) ),
                'diagnostics' => array_values( (array) ( $pack['diagnostics'] ?? [] ) ),
                'common_operational_issues' => array_values( (array) ( $pack['common_operational_issues'] ?? [] ) ),
                'alerts' => $pack_alerts,
                'permission_issues' => $pack_permission_issues,
                'source_modules' => array_values( array_filter( array_map( 'strval', (array) ( $pack['source_modules'] ?? [] ) ) ) ),
            ];
        }

        foreach ( $rows as &$row ) {
            if ( (string) ( $row['module_slug'] ?? '' ) !== 'board' ) {
                continue;
            }

            foreach ( (array) ( $diagnostics['findings'] ?? [] ) as $finding ) {
                if ( (string) ( $finding['key'] ?? '' ) !== 'board_workspace_health' ) {
                    continue;
                }

                $row['live_diagnostic'] = $finding;
                break;
            }
        }

        usort( $rows, fn ( array $a, array $b ): int => $this->severityRank( (string) ( $b['severity'] ?? 'low' ) ) <=> $this->severityRank( (string) ( $a['severity'] ?? 'low' ) ) );

        return $rows;
    }

    private function diagnosticTrends( array $reports ): array {
        $points = [];

        $reports = array_reverse( $reports );
        foreach ( $reports as $report ) {
            if ( ! is_array( $report ) ) {
                continue;
            }

            $summary = (array) ( $report['summary'] ?? [] );
            $finding_count = (int) ( $summary['summary']['finding_count'] ?? count( (array) ( $summary['findings'] ?? [] ) ) );
            $high_count = (int) ( $summary['summary']['high_severity'] ?? count( array_filter(
                (array) ( $summary['findings'] ?? [] ),
                static fn ( array $finding ): bool => ( $finding['severity'] ?? '' ) === 'high'
            ) ) );

            $points[] = [
                'report_code' => (string) ( $report['report_code'] ?? '' ),
                'label' => (string) ( $report['updated_at'] ?? $report['report_code'] ?? '' ),
                'report_type' => (string) ( $report['report_type'] ?? 'diagnostic' ),
                'finding_count' => $finding_count,
                'high_severity' => $high_count,
            ];
        }

        return [
            'points' => $points,
            'max_finding_count' => max( 1, ...array_map( static fn ( array $point ): int => (int) ( $point['finding_count'] ?? 0 ), $points ?: [ [ 'finding_count' => 1 ] ] ) ),
        ];
    }

    private function tableExists( string $table ): bool {
        $exists = \metis_db()->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }

    private function timestampFromString( string $value ): int {
        if ( $value === '' ) {
            return 0;
        }

        $timestamp = strtotime( $value . ' UTC' );
        if ( $timestamp !== false ) {
            return (int) $timestamp;
        }

        $timestamp = strtotime( $value );
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    private function severityRank( string $severity ): int {
        return match ( strtolower( $severity ) ) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    private function deriveTitle( string $query ): string {
        $title = trim( preg_replace( '/\s+/', ' ', $query ) ?? '' );
        return $title === '' ? 'Hermes Session' : substr( $title, 0, 80 );
    }
}
