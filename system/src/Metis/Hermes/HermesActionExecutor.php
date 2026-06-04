<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\Cache\CacheService;

final class HermesActionExecutor {
    private const SECRET_REVEAL_TTL_SECONDS = 600;

    public function __construct(
        private readonly HermesRepository $repository,
        private readonly HermesOperationalEngine $operations,
        private readonly HermesAuditLogger $audit,
        private readonly HermesReasoner $reasoner,
        private readonly HermesMemoryStore $memory,
        private readonly HermesMissionEngine $missions,
        private readonly HermesWalkthroughResolver $walkthroughs,
        private readonly HermesHelpResolver $help
    ) {}

    public function executeApprovedAction( array $action, string $actionCode ): array {
        try {
            $request = \metis_security_runtime_request_context( [
                'action_code' => $actionCode,
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
                        'run_diagnostic' => $this->diagnosticsAction( (string) ( $payload['query'] ?? 'system health diagnostic' ) ),
                        'open_help_topic' => [ 'help_topic' => $this->help->topic( (string) ( $payload['topic_id'] ?? '' ) ) ],
                        'launch_walkthrough' => $this->launchWalkthrough( (string) ( $payload['walkthrough_id'] ?? '' ) ),
                        'execute_mission' => $this->executeMission( (string) ( $payload['mission_key'] ?? '' ), (string) ( $payload['query'] ?? '' ) ),
                        'queue_scheduled_diagnostics' => $this->enqueueScheduledDiagnostics(),
                        default => throw new \RuntimeException( 'Unsupported Hermes action.' ),
                    };
                }
            );
        } catch ( \Throwable $e ) {
            $errorCode = 'EXECUTION_FAILED';
            $rawCode = '';
            if ( method_exists( $e, 'code_name' ) ) {
                $rawCode = (string) $e->code_name();
            }

            if ( $rawCode === 'operation_not_registered' ) {
                $errorCode = 'TOOL_NOT_FOUND';
            } elseif ( in_array( $rawCode, [ 'authentication_required', 'invalid_session', 'permission_denied', 'invalid_nonce', 'rate_limit_exceeded' ], true ) ) {
                $errorCode = 'PERMISSION_DENIED';
            }

            $result = [
                'status' => 'error',
                'error_code' => $errorCode,
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Action execution failed.',
                'detail' => $e->getMessage(),
            ];
        }

        $redacted = $this->redactSensitiveActionResult( $result, $action );
        $storedResult = (array) ( $redacted['stored'] ?? [] );
        $responseResult = (array) ( $redacted['response'] ?? [] );

        try {
            $saved = $this->repository->markActionExecuted( $actionCode, $storedResult );
        } catch ( \Throwable $e ) {
            $saved = $action;
            $responseResult = [
                'status' => 'error',
                'message' => 'Action executed but result persistence failed.',
                'detail' => $e->getMessage(),
            ];
        }
        try {
            $this->audit->approval( 'action_executed', $actionCode, [ 'status' => 'executed' ] );
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

    public function executeApprovedReleaseInstall( array $action, string $actionCode, string $progressToken ): array {
        $progressToken = $this->normalizeReleaseProgressToken( $progressToken );
        if ( $progressToken === '' ) {
            throw new \RuntimeException( 'A release progress token is required.' );
        }

        $prepared = $this->operations->validatePreparedAction( (array) ( $action['payload'] ?? [] ) );
        $permission = (array) ( $prepared['permission'] ?? [] );
        if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
            throw new \RuntimeException( (string) ( $permission['reason'] ?? 'Permission denied.' ) );
        }

        $tag = $this->resolveReleaseInstallTag( $prepared, $action );
        $this->writeReleaseProgress( $progressToken, [
            'tag' => $tag,
            'stage' => 'start',
            'message' => 'Starting trusted system update.',
            'percent' => 1,
            'done' => false,
            'context' => [],
        ] );

        if ( function_exists( 'session_status' ) && \session_status() === PHP_SESSION_ACTIVE ) {
            \session_write_close();
        }
        @ignore_user_abort( true );
        @set_time_limit( 0 );

        $lastProgress = [];
        $writeProgress = function ( array $progress ) use ( $progressToken, $tag, &$lastProgress ): void {
            $payload = [
                'tag' => $tag,
                'stage' => (string) ( $progress['stage'] ?? 'running' ),
                'message' => (string) ( $progress['message'] ?? 'Running update.' ),
                'percent' => (int) ( $progress['percent'] ?? 0 ),
                'done' => false,
                'context' => is_array( $progress['context'] ?? null ) ? $progress['context'] : [],
            ];
            $lastProgress = $payload;
            $this->writeReleaseProgress( $progressToken, $payload );
        };

        try {
            if ( ! function_exists( 'metis_release_apply_with_progress' ) ) {
                throw new \RuntimeException( 'Release manager is not available.' );
            }

            $releaseResult = \metis_release_apply_with_progress( $tag, 'hermes', $writeProgress );
        } catch ( \Throwable $e ) {
            $releaseResult = [
                'ok' => false,
                'status' => 'exception',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Release update failed.',
                'exception' => get_class( $e ),
            ];
        }

        $ok = ! empty( $releaseResult['ok'] );
        $finalProgress = [
            'tag' => $tag,
            'stage' => $ok ? 'complete' : 'failed',
            'message' => (string) ( $releaseResult['message'] ?? ( $ok ? 'Release update completed.' : 'Release update failed.' ) ),
            'percent' => $ok ? 100 : max( 1, min( 99, (int) ( $lastProgress['percent'] ?? 1 ) ) ),
            'done' => true,
            'context' => is_array( $lastProgress['context'] ?? null ) ? $lastProgress['context'] : [],
            'result' => $releaseResult,
        ];
        $this->writeReleaseProgress( $progressToken, $finalProgress );

        $result = [
            'status' => $ok ? 'success' : 'error',
            'message' => (string) ( $releaseResult['message'] ?? ( $ok ? 'Release update completed.' : 'Release update failed.' ) ),
            'release_result' => $releaseResult,
            'progress_token' => $progressToken,
            'tag' => $tag,
        ];

        $saved = $this->repository->markActionExecuted( $actionCode, $result );
        $this->audit->approval( 'action_executed', $actionCode, [ 'status' => 'executed' ] );

        return [
            'action' => $saved,
            'result' => $result,
            'progress' => $finalProgress,
        ];
    }

    public function revealSecret( string $revealToken ): array {
        $revealToken = \metis_key_clean( trim( $revealToken ) );
        if ( $revealToken === '' ) {
            throw new \RuntimeException( 'Missing reveal token.' );
        }

        $cacheKey = $this->secretRevealCacheKey( $revealToken );
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

    private function diagnosticsAction( string $query ): array {
        $session = $this->repository->ensureSession( \metis_current_user_id(), '', 'Hermes Diagnostics' );

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

    public function launchWalkthrough( string $walkthroughId ): array {
        $walkthrough = $this->walkthroughs->get( $walkthroughId );
        if ( ! is_array( $walkthrough ) ) {
            throw new \RuntimeException( 'Walkthrough not found.' );
        }

        $this->walkthroughs->markStarted( $walkthroughId );

        return [
            'walkthrough' => $walkthrough,
            'launched' => true,
        ];
    }

    public function executeMission( string $missionKey, string $query = '' ): array {
        $plan = $this->missions->plan( $missionKey );
        if ( ! is_array( $plan ) ) {
            throw new \RuntimeException( 'Mission not found.' );
        }

        $report = $this->repository->saveReport( 'mission', $missionKey, [
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

    private function requestNonce(): string {
        $expectedAction = 'metis_ajax:metis_hermes_execute_action';
        foreach ( [ 'metis_action_nonce', 'security', 'nonce' ] as $field ) {
            $value = metis_request_post()[ $field ] ?? metis_request_get()[ $field ] ?? '';
            if ( is_string( $value ) ) {
                $value = \trim( \metis_runtime_unslash( $value ) );
                if (
                    $value !== ''
                    && ( ! function_exists( 'metis_runtime_verify_nonce' ) || \metis_runtime_verify_nonce( $value, $expectedAction ) )
                ) {
                    return $value;
                }
            }
        }

        if ( function_exists( 'metis_runtime_create_nonce' ) ) {
            return (string) \metis_runtime_create_nonce( $expectedAction );
        }

        return '';
    }

    private function resolveReleaseInstallTag( array $prepared, array $action ): string {
        $commandPayload = (array) ( $prepared['command_payload'] ?? [] );
        $executionPlan = array_values( (array) ( $prepared['execution_plan'] ?? [] ) );

        $tag = trim( (string) ( $commandPayload['tag'] ?? '' ) );
        if ( $tag !== '' ) {
            return $tag;
        }

        foreach ( $executionPlan as $step ) {
            if ( ! is_array( $step ) || (string) ( $step['intent'] ?? '' ) !== 'update_install' ) {
                continue;
            }

            $stepPayload = (array) ( $step['payload'] ?? [] );
            $tag = trim( (string) ( $stepPayload['tag'] ?? '' ) );
            if ( $tag !== '' ) {
                return $tag;
            }
        }

        if ( \Metis\Core\Application::has_service( 'release' ) ) {
            $release = \Metis\Core\Application::service( 'release' )->checkForUpdates( true, 'hermes' );
            $latest = is_array( $release['latest'] ?? null ) ? (array) $release['latest'] : [];
            $tag = trim( (string) ( $latest['tag'] ?? '' ) );
            if ( $tag !== '' ) {
                return $tag;
            }
        }

        throw new \RuntimeException( 'No trusted update is currently available to install.' );
    }

    private function normalizeReleaseProgressToken( string $token ): string {
        $token = preg_replace( '/[^a-z0-9_-]/i', '', strtolower( trim( $token ) ) ) ?? '';
        return substr( $token, 0, 64 );
    }

    private function writeReleaseProgress( string $token, array $payload ): void {
        if ( ! function_exists( 'metis_runtime_json_store_write' ) ) {
            return;
        }

        \metis_runtime_json_store_write( 'hermes/release-progress/' . $token . '.json', $payload );
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

        if ( $reveals !== [] ) {
            $stored['secret_reveals'] = array_map(
                static fn ( array $item ): array => [
                    'label' => (string) ( $item['label'] ?? 'Secret' ),
                    'field' => (string) ( $item['field'] ?? '' ),
                    'revealed' => false,
                ],
                $reveals
            );
            $response['secret_reveals'] = $reveals;
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
}
