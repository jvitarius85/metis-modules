<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\Cache\CacheService;
use Metis\Core\Application;
use Metis\Intelligence\Services\AlertIntelligenceService;
use Metis\Intelligence\Services\DiagnosticTrendIntelligenceService;
use Metis\Intelligence\Services\IntegrationFailureIntelligenceService;
use Metis\Intelligence\Services\ModuleHealthIntelligenceService;
use Metis\Intelligence\Services\RecommendationIntelligenceService;
use Metis\Intelligence\Support\SeverityRanker;

final class HermesGateway {
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
        private readonly HermesOperationalEngine $operations,
        private readonly HermesConversationStateEngine $state,
        private readonly HermesOperationsRegistry $operationRegistry,
        private readonly HermesApprovalEngine $approvals,
        private readonly HermesActionExecutor $actionExecutor,
        private readonly HermesWorkflowContinuationEngine $continuations,
        private readonly HermesPendingWorkflowEngine $pendingWorkflows,
        private readonly HermesDisambiguationEngine $disambiguations,
        private readonly AlertIntelligenceService $alerts,
        private readonly IntegrationFailureIntelligenceService $integrationFailures,
        private readonly ModuleHealthIntelligenceService $moduleHealth,
        private readonly RecommendationIntelligenceService $recommendations,
        private readonly DiagnosticTrendIntelligenceService $trends,
        private readonly SeverityRanker $severityRanker
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
        $operations = $this->operationRegistry->definitions();
        $dashboard = CacheService::remember( 'dashboard.kpis', 60, function () use ( $context_packs ): array {
            if ( \function_exists( 'metis_job_queue' ) ) {
                \metis_job_queue()->recoverExpiredProcessingJobs();
                \metis_job_queue()->pruneCompletedJobs();
            }

            $reports = $this->repository->recentReports( 10 );
            $queue = $this->repository->queueSummary();
            $diagnostics = $this->diagnostics->run( [ 'context_packs' => $context_packs ] );
            $cron = $this->cronSnapshot();
            $reconciliation = $this->reconciliationSnapshot();
            $permission_issues = $this->permissionInconsistencies( $context_packs );
            $integration_failures = $this->integrationFailures->build( $cron, $queue, $reconciliation, $permission_issues, $diagnostics );
            $alerts = $this->alerts->build( $cron, $queue, $reconciliation, $permission_issues, $diagnostics );
            $module_summaries = $this->moduleHealth->build( $context_packs, $alerts, $permission_issues, $reconciliation, $queue, $diagnostics );

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
        $recommendations = $this->recommendations->build( $alerts, $integration_failures, $module_summaries, $diagnostics, $operations );
        $trends = $this->trends->build( $reports );

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
                'high_alert_count' => count( array_filter( $alerts, fn ( array $alert ): bool => $this->severityRanker->atOrAbove( (string) ( $alert['severity'] ?? 'low' ), 'high' ) ) ),
                'integration_failure_count' => count( $integration_failures ),
                'recommendation_count' => count( $recommendations ),
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
            'operations' => $operations,
            'tools' => $this->tools->definitions(),
            'diagnostics' => $diagnostics,
            'alerts' => $alerts,
            'recommendations' => $recommendations,
            'module_summaries' => $module_summaries,
            'integration_failures' => $integration_failures,
            'workers' => $cron,
            'queue' => $queue,
            'reconciliation' => $reconciliation,
            'permission_inconsistencies' => $permission_issues,
            'diagnostic_trends' => $trends,
        ];
    }

    public function converse( string $query, string $session_code = '', array $runtimeContext = [] ): array {
        $user_id = \metis_current_user_id();
        $turn = $this->state->openTurn( $user_id, $query, $session_code );
        $session = (array) ( $turn['session'] ?? [] );
        $user_message = (array) ( $turn['user_message'] ?? [] );
        $runtimeContext = $this->state->hydrateRuntimeContext( $session, $runtimeContext );
        $query = Application::service( 'hermes_nlu' )->mergePendingContextQuery( $query, (string) ( $session['session_code'] ?? '' ) );

        try {
            $disambiguated = $this->disambiguations->continueIfApplicable( $query, $session );
            if ( is_array( $disambiguated ) ) {
                if ( (string) ( $disambiguated['kind'] ?? '' ) === 'entity_attribute' ) {
                    $processed = $this->operations->processEntityAttributeRequest(
                        (array) ( $disambiguated['attribute_request'] ?? [] ),
                        (string) ( $disambiguated['query'] ?? $query )
                    );

                    return $this->finalizeWorkflowResponse( $session, $user_message, $query, $processed );
                }

                if ( (string) ( $disambiguated['kind'] ?? '' ) === 'lookup_profile' ) {
                    $processed = $this->operations->processLookupProfileRequest(
                        (array) ( $disambiguated['profile_request'] ?? [] )
                    );

                    return $this->finalizeWorkflowResponse( $session, $user_message, $query, $processed );
                }

                return $this->finalizeWorkflowResponse( $session, $user_message, $query, $disambiguated );
            }

            $workflow = $this->pendingWorkflows->continueIfApplicable( $query, $session );
            if ( is_array( $workflow ) ) {
                return $this->finalizeWorkflowResponse( $session, $user_message, $query, $workflow );
            }

            $continued = $this->continuations->continueIfApplicable( $query, $session );
            if ( is_array( $continued ) ) {
                return $this->finalizeWorkflowResponse( $session, $user_message, $query, $continued );
            }

            $processed = $this->operations->process( $query, $runtimeContext );
            $workflowStart = $this->pendingWorkflows->beginIfApplicable( $session, $processed );
            if ( is_array( $workflowStart ) ) {
                $processed = $workflowStart;
            }
            $response = (array) ( $processed['response'] ?? [] );
            if ( $this->shouldUseKnowledgeFallback( $processed, $response ) ) {
                $processed = $this->knowledgeFallback( $query, $session, $processed, $runtimeContext );
                $response = (array) ( $processed['response'] ?? [] );
            }
            $this->disambiguations->rememberIfApplicable( $session, $processed, $response );
            $actions = $this->approvals->queueApprovalForProcessedResponse( $session, $query, $processed, $response );
            $reasoning = [
                'intent' => (string) ( $processed['intent']['action'] ?? 'unknown' ),
                'answer' => (string) ( $response['message'] ?? '' ),
                'structured' => $response,
            ];
            $assistant_message = $this->repository->saveMessage( (int) $session['id'], 'hermes', (string) ( $response['message'] ?? '' ), $reasoning );

            $response = $this->approvals->attachApprovalPrompts( $response, $actions );

            $this->state->completeTurn( $session, $query, $processed, $response );
            $this->audit->commandTrace( [
                'session_code' => (string) ( $session['session_code'] ?? '' ),
                'user_id' => $user_id,
                'raw_input' => $query,
                'normalized_input' => (string) ( $processed['parsed']['normalized_input'] ?? strtolower( trim( $query ) ) ),
                'selected_intent' => (string) ( $processed['parsed']['selected_intent'] ?? $processed['intent']['action'] ?? '' ),
                'top_level_intent' => (string) ( $processed['parsed']['top_level_intent'] ?? $processed['intent']['top_level_intent'] ?? '' ),
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

    private function shouldUseKnowledgeFallback( array $processed, array $response ): bool {
        $status = (string) ( $response['status'] ?? '' );
        $hasCommand = is_array( $processed['command'] ?? null ) && (array) $processed['command'] !== [];
        $intent = (string) ( $processed['intent']['action'] ?? '' );
        $message = strtolower( (string) ( $response['message'] ?? '' ) );

        return ! $hasCommand
            && in_array( $status, [ 'error', 'clarification_required' ], true )
            && in_array( $intent, [ '', 'unknown' ], true )
            && (
                str_contains( $message, 'could not be mapped' )
                || str_contains( $message, 'could not map' )
                || str_contains( $message, 'could not resolve' )
                || str_contains( $message, 'registered hermes operation' )
                || str_contains( $message, 'safe hermes operation' )
            );
    }

    private function knowledgeFallback( string $query, array $session, array $processed, array $runtimeContext ): array {
        $reasoning = $this->reasoner->reason( $query, $session );
        $answer = trim( (string) ( $reasoning['answer'] ?? '' ) );
        if ( $answer === '' ) {
            $answer = 'I found related Metis context, but I need a little more detail to give a precise answer.';
        }

        $knowledge = (array) ( $reasoning['knowledge'] ?? [] );
        $context = (array) ( $reasoning['context'] ?? [] );
        $response = [
            'status' => 'success',
            'message' => $answer,
            'response_type' => 'KnowledgeResponse',
            'result' => [
                'summary' => $answer,
                'context_packs' => array_values( (array) ( $context['context_packs'] ?? [] ) ),
                'related_articles' => array_values( (array) ( $knowledge['help_topics'] ?? [] ) ),
                'documentation' => array_values( (array) ( $knowledge['docs'] ?? [] ) ),
                'walkthroughs' => array_values( (array) ( $knowledge['walkthroughs'] ?? [] ) ),
                'playbooks' => array_values( (array) ( $reasoning['playbooks'] ?? [] ) ),
                'missions' => array_values( (array) ( $reasoning['missions'] ?? [] ) ),
                'runtime_context' => $runtimeContext,
            ],
        ];

        return array_merge( $processed, [
            'intent' => [
                'action' => (string) ( $reasoning['intent'] ?? 'conversation' ),
                'confidence' => 0.62,
                'payload' => [ 'query' => $query ],
                'top_level_intent' => (string) ( $processed['intent']['top_level_intent'] ?? 'HELP' ),
            ],
            'command' => null,
            'context_packs' => array_values( (array) ( $context['context_packs'] ?? [] ) ),
            'action_plan' => [],
            'permission' => [ 'status' => 'not_applicable', 'required_permission' => '', 'reason' => '' ],
            'response' => $response,
            'reasoner' => $reasoning,
        ] );
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
        return $this->approvals->approve( $action_code, \metis_current_user_id(), $note );
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

        return $this->actionExecutor->executeApprovedAction( $action, $action_code );
    }

    public function executeReleaseAction( string $action_code, string $progress_token ): array {
        $action = $this->repository->getActionByCode( $action_code );
        if ( ! is_array( $action ) ) {
            throw new \RuntimeException( 'Hermes action not found.' );
        }

        if ( (string) ( $action['approval_status'] ?? '' ) !== 'approved' ) {
            throw new \RuntimeException( 'Hermes action requires approval before execution.' );
        }

        return $this->actionExecutor->executeApprovedReleaseInstall( $action, $action_code, $progress_token );
    }

    public function revealSecret( string $reveal_token ): array {
        return $this->actionExecutor->revealSecret( $reveal_token );
    }

    public function launchWalkthrough( string $walkthrough_id ): array {
        return $this->actionExecutor->launchWalkthrough( $walkthrough_id );
    }

    public function executeMission( string $mission_key, string $query = '' ): array {
        return $this->actionExecutor->executeMission( $mission_key, $query );
    }

    private function finalizeWorkflowResponse( array $session, array $userMessage, string $query, array $workflow ): array {
        if ( isset( $workflow['response'] ) ) {
            $processed = $workflow;
            $response = (array) ( $workflow['response'] ?? [] );
            $actions = $this->approvals->queueApprovalForProcessedResponse( $session, $query, $processed, $response );
            $response = $this->approvals->attachApprovalPrompts( $response, $actions );
            $intentAction = (string) ( $processed['intent']['action'] ?? 'workflow_continuation' );
        } else {
            $response = $workflow;
            $actions = [];
            $intentAction = (string) ( $workflow['status'] ?? '' ) === 'cancelled'
                ? 'approval_cancellation'
                : 'approval_confirmation';
            $processed = [
                'intent' => [
                    'action' => $intentAction,
                    'top_level_intent' => 'EXECUTE',
                    'payload' => [
                        'action_code' => (string) ( $workflow['continued_action_code'] ?? ( $workflow['action']['action_code'] ?? '' ) ),
                    ],
                ],
                'parsed' => [
                    'selected_intent' => $intentAction,
                    'top_level_intent' => 'EXECUTE',
                ],
            ];
        }

        $assistantText = (string) ( $response['message'] ?? '' );
        $reasoning = [
            'intent' => $intentAction,
            'answer' => $assistantText,
            'structured' => $response,
        ];
        $assistantMessage = $this->repository->saveMessage( (int) $session['id'], 'hermes', $assistantText, $reasoning );
        $this->state->completeTurn( $session, $query, $processed, $response );

        return array_merge( $response, [
            'session' => $session,
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'history' => $this->repository->sessionMessages( (int) $session['id'], 80 ),
            'reasoning' => $reasoning,
            'actions' => $actions,
        ] );
    }

    public function enqueueScheduledDiagnostics(): array {
        return $this->actionExecutor->enqueueScheduledDiagnostics();
    }

    public function runScheduledDiagnostics( string $scope = 'system' ): array {
        $result = $this->diagnostics( $scope . ' health diagnostic' );
        $this->audit->conversation( 'scheduled_diagnostics', [ 'scope' => $scope ] );
        return $result;
    }

    private function cronSnapshot(): array {
        $tasks = \Metis_Cron_Manager::registered_tasks();
        $now   = $this->timestampFromString( \metis_current_time( 'mysql' ) );
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
                'next_due_at' => $next_due > 0 ? date( 'Y-m-d H:i:s', $next_due ) : '',
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
                if ( ! array_key_exists( $action, $declared ) ) {
                    continue;
                }

                $actual = $declared[ $action ];

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

        usort( $issues, $this->severityRanker->compare( ... ) );

        return array_slice( $issues, 0, 12 );
    }

    private function tableExists( string $table ): bool {
        $exists = \metis_db()->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }

    private function timestampFromString( string $value ): int {
        if ( $value === '' ) {
            return 0;
        }

        $timestamp = strtotime( $value );
        if ( $timestamp !== false ) {
            return (int) $timestamp;
        }

        $timestamp = strtotime( $value . ' UTC' );
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    private function deriveTitle( string $query ): string {
        $title = trim( preg_replace( '/\s+/', ' ', $query ) ?? '' );
        return $title === '' ? 'Hermes Session' : substr( $title, 0, 80 );
    }
}
