<?php
declare(strict_types=1);

namespace Metis\Intelligence\Registry;

use Metis\Intelligence\Contracts\IntelligenceProviderInterface;
use Metis\Intelligence\Support\IntelligenceResponseFactory;

final class IntelligenceProviderRegistry {
    /**
     * @param array<int,IntelligenceProviderInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly IntelligenceResponseFactory $factory
    ) {}

    public function definitions(): array {
        $definitions = [];
        foreach ( $this->providers as $provider ) {
            $definitions[ $provider->key() ] = $provider->definition();
        }

        return $definitions;
    }

    public function provider( string $key ): ?IntelligenceProviderInterface {
        $key = strtolower( trim( $key ) );
        foreach ( $this->providers as $provider ) {
            if ( $provider->key() === $key ) {
                return $provider;
            }
        }

        return null;
    }

    public function resolve( string $query, int $limit = 6 ): array {
        $sources = [];
        foreach ( $this->providers as $provider ) {
            if ( ! $provider->supports( $query ) ) {
                continue;
            }

            $snapshot = $this->factory->make(
                $provider->getMetrics( $query, $limit ),
                $provider->getInsights( $query, $limit ),
                $provider->getAlerts( $query, $limit ),
                $provider->getRecommendations( $query, $limit ),
                $provider->key()
            )->toArray();

            $sources[ $provider->key() ] = [
                'definition' => $provider->definition(),
                'snapshot' => $snapshot,
                'results' => array_values( array_merge(
                    (array) ( $snapshot['insights'] ?? [] ),
                    (array) ( $snapshot['alerts'] ?? [] ),
                    (array) ( $snapshot['recommendations'] ?? [] )
                ) ),
            ];
        }

        return $sources;
    }
}
