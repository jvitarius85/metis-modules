<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

use Metis\Hermes\HermesCommandRegistry;
use Metis\Hermes\HermesIntentRegistry;
use Metis\Hermes\HermesToolRegistry;
use Metis\Operations\DTOs\OperationDefinition;

final class OperationDefinitionBuilder {
    public function __construct(
        private readonly HermesCommandRegistry $commands,
        private readonly HermesToolRegistry $tools,
        private readonly HermesIntentRegistry $intents,
        private readonly OperationsServiceCatalog $catalog
    ) {}

    public function definitions(): array {
        $operations = [];
        foreach ( $this->commands->definitions() as $commandKey => $command ) {
            $toolKey = (string) ( $command['tool_key'] ?? '' );
            $tool = $toolKey !== '' ? (array) $this->tools->definition( $toolKey ) : [];
            $handlerMetadata = $this->catalog->resolve( $commandKey );
            $definition = new OperationDefinition(
                $commandKey,
                $commandKey,
                $toolKey,
                (string) ( $command['title'] ?? $commandKey ),
                (string) ( $command['description'] ?? '' ),
                (string) ( $command['module'] ?? '' ),
                (string) ( $command['domain'] ?? '' ),
                $this->intents->classifyCommand( $commandKey ),
                (string) ( $command['permission'] ?? '' ),
                array_values( (array) ( $tool['required_permissions'] ?? array_filter( [ (string) ( $command['permission'] ?? '' ) ] ) ) ),
                ! empty( $command['requires_approval'] ),
                ! empty( $command['read_only'] ),
                ! empty( $command['worker_supported'] ) || ! empty( $tool['worker_supported'] ),
                (string) ( $tool['risk_level'] ?? ( ! empty( $command['read_only'] ) ? 'low' : 'medium' ) ),
                (string) ( $tool['enclave_action'] ?? '' ),
                (array) ( $command['input_schema'] ?? [] ),
                (array) ( $tool['output_schema'] ?? $command['output_schema'] ?? [] ),
                (array) ( $tool['dispatch'] ?? [] ),
                $handlerMetadata
            );

            $operations[ $commandKey ] = $definition->toArray();
        }

        return $operations;
    }
}
