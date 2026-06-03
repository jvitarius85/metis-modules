<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Intelligence\Contracts\IntelligenceProviderInterface;

abstract class AbstractSnapshotIntelligenceProvider implements IntelligenceProviderInterface {
    public function supports( string $query ): bool {
        $normalized = strtolower( trim( $query ) );
        if ( $normalized === '' ) {
            return false;
        }

        foreach ( $this->keywords() as $keyword ) {
            if ( $keyword !== '' && str_contains( $normalized, strtolower( $keyword ) ) ) {
                return true;
            }
        }

        return false;
    }

    public function getMetrics( string $query, int $limit = 6 ): array {
        return $this->buildMetrics( $this->snapshot(), $limit );
    }

    public function getInsights( string $query, int $limit = 6 ): array {
        return $this->buildInsights( $this->snapshot(), $limit );
    }

    public function getAlerts( string $query, int $limit = 6 ): array {
        return $this->buildAlerts( $this->snapshot(), $limit );
    }

    public function getRecommendations( string $query, int $limit = 6 ): array {
        return $this->buildRecommendations( $this->snapshot(), $limit );
    }

    /**
     * @return array<int,string>
     */
    abstract protected function keywords(): array;

    /**
     * @return array<string,mixed>
     */
    abstract protected function snapshot(): array;

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array<string,mixed>>
     */
    protected function buildMetrics( array $snapshot, int $limit ): array {
        return [];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array<string,mixed>>
     */
    protected function buildInsights( array $snapshot, int $limit ): array {
        return [];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array<string,mixed>>
     */
    protected function buildAlerts( array $snapshot, int $limit ): array {
        return [];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array<string,mixed>>
     */
    protected function buildRecommendations( array $snapshot, int $limit ): array {
        return [];
    }

    /**
     * @param callable():array<string,mixed> $resolver
     * @return array<string,mixed>
     */
    protected function safeSnapshot( callable $resolver ): array {
        try {
            $snapshot = $resolver();
            return is_array( $snapshot ) ? $snapshot : [];
        } catch ( \Throwable ) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    protected function limitRows( array $rows, int $limit ): array {
        return array_slice( array_values( $rows ), 0, max( 1, min( 25, $limit ) ) );
    }
}
