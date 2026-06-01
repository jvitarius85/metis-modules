<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesOperationsRegistry {
    public function __construct(
        private readonly HermesCommandRegistry $commands,
        private readonly HermesToolRegistry $tools,
        private readonly HermesIntentRegistry $intents
    ) {}

    public function definitions(): array {
        $operations = [];
        foreach ( $this->commands->definitions() as $commandKey => $command ) {
            $toolKey = (string) ( $command['tool_key'] ?? '' );
            $tool = $toolKey !== '' ? (array) $this->tools->definition( $toolKey ) : [];
            $operations[ $commandKey ] = [
                'operation_key' => $commandKey,
                'command_key' => $commandKey,
                'tool_key' => $toolKey,
                'title' => (string) ( $command['title'] ?? $commandKey ),
                'description' => (string) ( $command['description'] ?? '' ),
                'module' => (string) ( $command['module'] ?? '' ),
                'domain' => (string) ( $command['domain'] ?? '' ),
                'top_level_intent' => $this->intents->classifyCommand( $commandKey ),
                'required_permission' => (string) ( $command['permission'] ?? '' ),
                'required_permissions' => array_values( (array) ( $tool['required_permissions'] ?? array_filter( [ (string) ( $command['permission'] ?? '' ) ] ) ) ),
                'requires_approval' => ! empty( $command['requires_approval'] ),
                'read_only' => ! empty( $command['read_only'] ),
                'worker_supported' => ! empty( $command['worker_supported'] ) || ! empty( $tool['worker_supported'] ),
                'risk_level' => (string) ( $tool['risk_level'] ?? ( ! empty( $command['read_only'] ) ? 'low' : 'medium' ) ),
                'enclave_action' => (string) ( $tool['enclave_action'] ?? '' ),
                'input_schema' => (array) ( $command['input_schema'] ?? [] ),
                'output_schema' => (array) ( $tool['output_schema'] ?? $command['output_schema'] ?? [] ),
                'dispatch' => (array) ( $tool['dispatch'] ?? [] ),
            ];
        }

        return $operations;
    }

    public function definition( string $operationKey ): array {
        $definitions = $this->definitions();
        return $definitions[ strtolower( trim( $operationKey ) ) ] ?? [];
    }
}
