<?php
declare(strict_types=1);

namespace Metis\Intelligence\DTOs;

final class IntelligenceSnapshot {
    public function __construct(
        private readonly array $metrics,
        private readonly array $insights,
        private readonly array $alerts,
        private readonly array $recommendations,
        private readonly string $generatedAt,
        private readonly string $source
    ) {}

    public function toArray(): array {
        return [
            'metrics' => $this->metrics,
            'insights' => $this->insights,
            'alerts' => $this->alerts,
            'recommendations' => $this->recommendations,
            'generated_at' => $this->generatedAt,
            'source' => $this->source,
        ];
    }
}
