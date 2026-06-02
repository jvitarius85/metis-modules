<?php
declare(strict_types=1);

namespace Metis\Intelligence\Support;

use Metis\Intelligence\DTOs\IntelligenceSnapshot;

final class IntelligenceResponseFactory {
    public function make(
        array $metrics,
        array $insights,
        array $alerts,
        array $recommendations,
        string $source
    ): IntelligenceSnapshot {
        return new IntelligenceSnapshot(
            $metrics,
            $insights,
            $alerts,
            $recommendations,
            \metis_current_time( 'mysql' ),
            $source
        );
    }
}
