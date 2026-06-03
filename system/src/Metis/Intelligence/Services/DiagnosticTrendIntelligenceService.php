<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

final class DiagnosticTrendIntelligenceService {
    public function __construct(
        private readonly TrendIntelligenceService $trends
    ) {}

    public function build( array $reports ): array {
        $points = [];

        $reports = array_reverse( $reports );
        foreach ( $reports as $report ) {
            if ( ! is_array( $report ) ) {
                continue;
            }

            $summary = (array) ( $report['summary'] ?? [] );
            $findingCount = (int) ( $summary['summary']['finding_count'] ?? count( (array) ( $summary['findings'] ?? [] ) ) );
            $highCount = (int) ( $summary['summary']['high_severity'] ?? count( array_filter(
                (array) ( $summary['findings'] ?? [] ),
                static fn ( array $finding ): bool => ( $finding['severity'] ?? '' ) === 'high'
            ) ) );

            $points[] = [
                'report_code' => (string) ( $report['report_code'] ?? '' ),
                'label' => (string) ( $report['updated_at'] ?? $report['report_code'] ?? '' ),
                'report_type' => (string) ( $report['report_type'] ?? 'diagnostic' ),
                'finding_count' => $findingCount,
                'high_severity' => $highCount,
            ];
        }

        return [
            'points' => $points,
            'max_finding_count' => max( 1, ...array_map( static fn ( array $point ): int => (int) ( $point['finding_count'] ?? 0 ), $points ?: [ [ 'finding_count' => 1 ] ] ) ),
            'comparisons' => [
                'finding_count' => $this->trends->compareSeries( $points, 'finding_count', 'week_over_week' ),
                'high_severity' => $this->trends->compareSeries( $points, 'high_severity', 'week_over_week' ),
            ],
        ];
    }
}
