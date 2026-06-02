<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Hermes\HermesDocumentationIndex;
use Metis\Intelligence\Contracts\IntelligenceProviderInterface;

final class DocumentationIntelligenceProvider implements IntelligenceProviderInterface {
    public function __construct(
        private readonly HermesDocumentationIndex $documentation
    ) {}

    public function key(): string {
        return 'documentation';
    }

    public function definition(): array {
        return [
            'key' => 'documentation',
            'label' => 'Documentation Index',
            'type' => 'knowledge',
            'default_limit' => 6,
        ];
    }

    public function supports( string $query ): bool {
        return trim( $query ) !== '';
    }

    public function getMetrics( string $query, int $limit = 6 ): array {
        return [];
    }

    public function getInsights( string $query, int $limit = 6 ): array {
        return $this->documentation->search( $query, max( 1, min( 25, $limit ) ) );
    }

    public function getAlerts( string $query, int $limit = 6 ): array {
        return [];
    }

    public function getRecommendations( string $query, int $limit = 6 ): array {
        return [];
    }
}
