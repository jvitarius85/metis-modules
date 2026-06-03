<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

final class OperationsServiceCatalog {
    /**
     * @param array<int,AbstractOperationsService> $services
     */
    public function __construct(
        private readonly array $services
    ) {}

    public function resolve( string $operationKey ): array {
        foreach ( $this->services as $service ) {
            $metadata = $service->handlerMetadata( $operationKey );
            if ( is_array( $metadata ) ) {
                return $metadata;
            }
        }

        return [];
    }
}
