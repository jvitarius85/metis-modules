<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesOperationalEngine {
    public function __construct(
        private readonly HermesIntentParser $parser,
        private readonly HermesContextPackLoader $contextLoader,
        private readonly HermesCommandRegistry $commands,
        private readonly HermesPermissionValidator $permissions,
        private readonly HermesExecutionEngine $execution,
        private readonly HermesResponseRenderer $responses
    ) {}

    public function process( string $query ): array {
        $intent = $this->parser->parse( $query );
        $command = (array) ( $intent['command'] ?? [] );

        if ( $command === [] ) {
            return [
                'intent' => $intent,
                'command' => null,
                'context_packs' => [],
                'action_plan' => [],
                'permission' => [ 'status' => 'not_applicable', 'required_permission' => '', 'reason' => '' ],
                'response' => $this->responses->error( $intent, 'Request could not be mapped to a registered Hermes operation.' ),
            ];
        }

        $contextPacks = $this->contextLoader->loadForCommand( $command );
        $plan = [
            'operation' => (string) ( $command['key'] ?? '' ),
            'title' => (string) ( $command['title'] ?? '' ),
            'steps' => array_values( (array) ( $command['steps'] ?? [] ) ),
            'required_permission' => (string) ( $command['permission'] ?? '' ),
            'context_loaded' => array_values( array_filter( array_map(
                static fn ( array $pack ): string => (string) ( $pack['title'] ?? $pack['key'] ?? '' ),
                $contextPacks
            ) ) ),
            'approval_required' => true,
            'service' => (array) ( $command['service'] ?? [] ),
        ];
        $permission = $this->permissions->validate( $command );

        $response = (string) ( $permission['status'] ?? '' ) === 'granted'
            ? $this->responses->awaitingApproval( $intent, $plan, $contextPacks )
            : $this->responses->denied( $intent, $plan, $contextPacks, (string) ( $permission['reason'] ?? 'Permission denied.' ) );

        return [
            'intent' => $intent,
            'command' => $command,
            'context_packs' => $contextPacks,
            'action_plan' => $plan,
            'permission' => $permission,
            'response' => $response,
        ];
    }

    public function executePreparedAction( array $payload ): array {
        $operation = \sanitize_key( (string) ( $payload['operation'] ?? '' ) );
        $command   = $this->commands->definition( $operation );

        if ( ! is_array( $command ) ) {
            throw new \RuntimeException( 'Hermes command is not registered.' );
        }

        $plan        = (array) ( $payload['action_plan'] ?? [] );
        $contextPacks = (array) ( $payload['context_packs'] ?? [] );
        $result      = $this->execution->execute( $command, (array) ( $payload['command_payload'] ?? [] ) );

        return $this->responses->executionResult( $command, $contextPacks, $plan, $result );
    }
}
