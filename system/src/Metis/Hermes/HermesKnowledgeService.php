<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesKnowledgeService {
    public function __construct(
        private readonly HermesIntelligenceRegistry $intelligence
    ) {}

    public function resolve( string $query, int $limit = 6 ): array {
        return $this->intelligence->resolve( $query, $limit );
    }
}
