<?php
declare(strict_types=1);

namespace Metis\Operations\Contracts;

interface OperationsRegistryInterface {
    public function definitions(): array;

    public function definition( string $operationKey ): array;
}
