<?php
declare(strict_types=1);

namespace Metis\Operations\Registry;

use Metis\Operations\Contracts\OperationsRegistryInterface;
use Metis\Operations\Services\OperationDefinitionBuilder;

final class OperationsRegistry implements OperationsRegistryInterface {
    /**
     * @var array<string,array<string,mixed>>|null
     */
    private ?array $definitionsCache = null;

    public function __construct(
        private readonly OperationDefinitionBuilder $builder
    ) {}

    public function definitions(): array {
        if ( $this->definitionsCache !== null ) {
            return $this->definitionsCache;
        }

        $this->definitionsCache = $this->builder->definitions();
        return $this->definitionsCache;
    }

    public function definition( string $operationKey ): array {
        $definitions = $this->definitions();
        return $definitions[ strtolower( trim( $operationKey ) ) ] ?? [];
    }
}
