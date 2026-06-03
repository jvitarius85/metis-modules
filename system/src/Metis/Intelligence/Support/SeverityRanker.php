<?php
declare(strict_types=1);

namespace Metis\Intelligence\Support;

final class SeverityRanker {
    public function rank( string $severity ): int {
        return match ( strtolower( trim( $severity ) ) ) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    public function compare( array $left, array $right ): int {
        return $this->rank( (string) ( $right['severity'] ?? 'low' ) ) <=> $this->rank( (string) ( $left['severity'] ?? 'low' ) );
    }

    public function atOrAbove( string $severity, string $baseline ): bool {
        return $this->rank( $severity ) >= $this->rank( $baseline );
    }
}
