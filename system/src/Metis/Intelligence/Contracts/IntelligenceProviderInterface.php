<?php
declare(strict_types=1);

namespace Metis\Intelligence\Contracts;

interface IntelligenceProviderInterface {
    public function key(): string;

    public function definition(): array;

    public function supports( string $query ): bool;

    public function getMetrics( string $query, int $limit = 6 ): array;

    public function getInsights( string $query, int $limit = 6 ): array;

    public function getAlerts( string $query, int $limit = 6 ): array;

    public function getRecommendations( string $query, int $limit = 6 ): array;
}
