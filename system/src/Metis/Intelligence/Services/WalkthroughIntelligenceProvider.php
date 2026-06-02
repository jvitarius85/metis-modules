<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Hermes\HermesWalkthroughResolver;
use Metis\Intelligence\Contracts\IntelligenceProviderInterface;

final class WalkthroughIntelligenceProvider implements IntelligenceProviderInterface {
    public function __construct(
        private readonly HermesWalkthroughResolver $walkthroughs
    ) {}

    public function key(): string {
        return 'walkthroughs';
    }

    public function definition(): array {
        return [
            'key' => 'walkthroughs',
            'label' => 'Walkthroughs',
            'type' => 'workflow_guidance',
            'default_limit' => 3,
        ];
    }

    public function supports( string $query ): bool {
        return trim( $query ) !== '';
    }

    public function getMetrics( string $query, int $limit = 6 ): array {
        return [];
    }

    public function getInsights( string $query, int $limit = 6 ): array {
        return $this->walkthroughs->search( $query, max( 1, min( 25, $limit ) ) );
    }

    public function getAlerts( string $query, int $limit = 6 ): array {
        return [];
    }

    public function getRecommendations( string $query, int $limit = 6 ): array {
        return [];
    }
}
