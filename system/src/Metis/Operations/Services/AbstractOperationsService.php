<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

abstract class AbstractOperationsService {
    /**
     * @return array<int,string>
     */
    abstract public function operationKeys(): array;

    abstract public function family(): string;

    public function handlerMetadata( string $operationKey ): ?array {
        $operationKey = strtolower( trim( $operationKey ) );
        if ( ! in_array( $operationKey, $this->operationKeys(), true ) ) {
            return null;
        }

        return [
            'handler' => static::class,
            'operation_family' => $this->family(),
            'capability_key' => $operationKey,
        ];
    }
}
