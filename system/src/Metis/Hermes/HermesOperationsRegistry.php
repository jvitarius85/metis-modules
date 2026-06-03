<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Operations\Contracts\OperationsRegistryInterface;
use Metis\Operations\Registry\OperationsRegistry;
use Metis\Operations\Services\CampaignOperationsService;
use Metis\Operations\Services\NewsletterOperationsService;
use Metis\Operations\Services\OperationDefinitionBuilder;
use Metis\Operations\Services\OperationsServiceCatalog;
use Metis\Operations\Services\SystemOperationsService;
use Metis\Operations\Services\UserOperationsService;
use Metis\Operations\Services\WorkspaceUserOperationsService;

final class HermesOperationsRegistry {
    private readonly OperationsRegistryInterface $registry;

    public function __construct( HermesCommandRegistry|OperationsRegistryInterface $commands, ?HermesToolRegistry $tools = null, ?HermesIntentRegistry $intents = null ) {
        if ( $commands instanceof OperationsRegistryInterface ) {
            $this->registry = $commands;
            return;
        }

        if ( $tools === null || $intents === null ) {
            throw new \InvalidArgumentException( 'Hermes tool and intent registries are required when constructing from Hermes command metadata.' );
        }

        $this->registry = new OperationsRegistry(
            new OperationDefinitionBuilder(
                $commands,
                $tools,
                $intents,
                new OperationsServiceCatalog( [
                    new UserOperationsService(),
                    new WorkspaceUserOperationsService(),
                    new CampaignOperationsService(),
                    new NewsletterOperationsService(),
                    new SystemOperationsService(),
                ] )
            )
        );
    }

    public function definitions(): array {
        return $this->registry->definitions();
    }

    public function definition( string $operationKey ): array {
        return $this->registry->definition( $operationKey );
    }
}
