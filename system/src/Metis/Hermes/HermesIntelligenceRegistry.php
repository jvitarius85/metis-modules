<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Intelligence\Registry\IntelligenceProviderRegistry;

final class HermesIntelligenceRegistry {
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $resolvedCache = [];

    public function __construct(
        private readonly IntelligenceProviderRegistry $providers,
        private readonly HermesGroundingValidator $grounding
    ) {}

    public function definitions(): array {
        return $this->providers->definitions();
    }

    public function definition( string $key ): ?array {
        $key = strtolower( trim( $key ) );
        return $this->definitions()[ $key ] ?? null;
    }

    public function resolve( string $query, int $limit = 6 ): array {
        $cacheKey = strtolower( trim( $query ) ) . ':' . max( 1, $limit );
        if ( isset( $this->resolvedCache[ $cacheKey ] ) ) {
            return $this->resolvedCache[ $cacheKey ];
        }

        $sources = $this->providers->resolve( $query, $limit );
        $docs = array_values( (array) ( $sources['documentation']['results'] ?? [] ) );
        $helpTopics = array_values( (array) ( $sources['help_topics']['results'] ?? [] ) );
        $walkthroughs = array_values( (array) ( $sources['walkthroughs']['results'] ?? [] ) );
        $groundingSources = array_merge( $docs, $helpTopics, $walkthroughs );

        $resolved = [
            'docs' => $docs,
            'help_topics' => $helpTopics,
            'walkthroughs' => $walkthroughs,
            'sources' => $sources,
            'grounding' => $this->grounding->validate( 'knowledge', $groundingSources ),
        ];

        $this->resolvedCache[ $cacheKey ] = $resolved;

        return $resolved;
    }
}
