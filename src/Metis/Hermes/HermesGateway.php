<?php
declare(strict_types=1);

namespace Metis\Hermes;

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
        private readonly HermesOperationalEngine $operations
    ) {}

    public function dashboardPayload(): array {
        $user_id       = \metis_current_user_id();
        $can_manage    = \Metis\Modules\Hermes\Access::canManage();
        $library       = \Metis\Core\Application::service( 'hermes_library' );
        $context_packs = array_values( $library->contextPacks() );
        $reports       = $this->repository->recentReports( 10 );
        $pending       = $user_id > 0 ? $this->repository->pendingActionsForUser( $user_id, 8 ) : [];
        $sessions      = $user_id > 0 ? $this->repository->recentSessions( $user_id, 6 ) : [];
        $queue         = $this->repository->queueSummary();
        $diagnostics   = $this->diagnostics->run( [ 'context_packs' => $context_packs ] );
        $cron          = $this->cronSnapshot();
        $reconciliation = $this->reconciliationSnapshot();
        $permission_issues = $this->permissionInconsistencies( $context_packs );
        $integration_failures = $this->integrationFailures( $cron, $queue, $reconciliation, $permission_issues, $diagnostics );
        $alerts = $this->alerts( $cron, $queue, $reconciliation, $permission_issues, $diagnostics );
        $module_summaries = $this->moduleSummaries( $context_packs, $alerts, $permission_issues, $reconciliation, $cron, $queue, $diagnostics );
        $trends = $this->diagnosticTrends( $reports );

        return [
            'generated_at' => \current_time( 'mysql' ),
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
        $processed = $this->operations->process( $query );
        $response = (array) ( $processed['response'] ?? [] );
        $actions = [];
        if ( (string) ( $response['status'] ?? '' ) === 'awaiting_approval' && is_array( $processed['command'] ?? null ) ) {
            $payload = [
                'intent' => (array) ( $processed['intent'] ?? [] ),
                'query' => $query,
                'operation' => (string) ( $processed['action_plan']['operation'] ?? '' ),
                'command_payload' => (array) ( $processed['intent']['payload'] ?? [] ),
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
        $this->audit->conversation( 'query', [
            'session_code' => (string) ( $session['session_code'] ?? '' ),
            'intent' => (string) ( $processed['intent']['action'] ?? '' ),
        ] );

        return array_merge( $response, [
            'session' => $session,
            'user_message' => $user_message,
            'assistant_message' => $assistant_message,
            'reasoning' => $reasoning,
            'actions' => $actions,
        ] );
    }

    public function diagnostics( string $query, string $session_code = '' ): array {
        $session = $this->repository->ensureSession( \metis_current_user_id(), $session_code, 'Hermes Diagnostics' );
        $reasoning = $this->reasoner->reason( $query !== '' ? $query : 'system health diagnostic', $session );
        $report = $this->repository->saveReport( 'diagnostic', (string) ( $reasoning['intent'] ?? 'diagnostic' ), (array) ( $reasoning['diagnostics'] ?? [] ), (int) ( $session['id'] ?? 0 ) );
        $this->memory->rememberReport( (string) ( $report['report_code'] ?? '' ), (array) ( $reasoning['diagnostics'] ?? [] ) );

        return [
            'session' => $session,
            'diagnostics' => $reasoning['diagnostics'],
            'report' => $report,
        ];
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

        $request = \metis_security_runtime_request_context( [
            'action_code' => $action_code,
            'action_type' => (string) ( $action['action_type'] ?? '' ),
            'metis_action_nonce' => $this->requestNonce(),
            'nonce' => $this->requestNonce(),
        ] );

        $result = \metis_security_enclave()->handle(
            'hermes.action.execute',
            $request,
            function () use ( $action ): array {
                $payload = (array) ( $action['payload'] ?? [] );
                $type = (string) ( $action['action_type'] ?? '' );

                if ( $type === 'hermes_command' || ! empty( $payload['operation'] ) ) {
                    return $this->operations->executePreparedAction( $payload );
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

        $saved = $this->repository->markActionExecuted( $action_code, $result );
        $this->audit->approval( 'action_executed', $action_code, [ 'status' => 'executed' ] );

        return [
            'action' => $saved,
            'result' => $result,
        ];
    }

    private function requestNonce(): string {
        foreach ( [ 'metis_action_nonce', '_wpnonce', 'security', '_ajax_nonce', 'nonce' ] as $field ) {
            $value = $_REQUEST[ $field ] ?? '';
            if ( is_string( $value ) ) {
                $value = \trim( \metis_unslash( $value ) );
                if ( $value !== '' ) {
                    return $value;
                }
            }
        }

        return '';
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
            'executed_at' => \current_time( 'mysql' ),
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
        $now   = \current_time( 'timestamp' );
        $rows  = [];
        $issue_count = 0;

        foreach ( $tasks as $slug => $task ) {
            $state = \get_option( 'metis_cron_task_state_' . $slug, [] );
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
        global $wpdb;

        $recons_table = \Metis_Tables::get( 'finance_reconciliations' );
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

        $summary = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status <> 'matched' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN ABS(COALESCE(variance, 0)) > 0.009 THEN 1 ELSE 0 END) AS variance_count
             FROM {$recons_table}",
            ARRAY_A
        );

        $rows = $wpdb->get_results(
            "SELECT
                account_key,
                period_start,
                period_end,
                status,
                COALESCE(book_balance, 0) AS book_balance,
                COALESCE(statement_balance, 0) AS statement_balance,
                COALESCE(variance, 0) AS variance,
                COALESCE(matched_count, 0) AS matched_count
             FROM {$recons_table}
             WHERE status <> 'matched' OR ABS(COALESCE(variance, 0)) > 0.009
             ORDER BY period_end DESC, id DESC
             LIMIT 6",
            ARRAY_A
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

            $module_slug = \sanitize_key( (string) ( $pack['module_slug'] ?? '' ) );
            if ( $module_slug === '' ) {
                continue;
            }

            $module = $modules[ $module_slug ] ?? null;
            $declared = [];

            foreach ( (array) ( $module['config']['permission_definitions'] ?? [] ) as $definition ) {
                if ( ! is_array( $definition ) ) {
                    continue;
                }

                $action = \sanitize_key( (string) ( $definition['action'] ?? '' ) );
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

                $action = \sanitize_key( (string) ( $permission['action'] ?? '' ) );
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

            $module_slug = \sanitize_key( (string) ( $pack['module_slug'] ?? '' ) );
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
        global $wpdb;

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
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
