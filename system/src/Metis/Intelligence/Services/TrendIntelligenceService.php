<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

final class TrendIntelligenceService {
    public function supportedComparisons(): array {
        return [
            'day_over_day' => 'Day over day',
            'week_over_week' => 'Week over week',
            'month_over_month' => 'Month over month',
            'quarter_over_quarter' => 'Quarter over quarter',
            'year_over_year' => 'Year over year',
        ];
    }

    public function compareValues( float|int $current, float|int|null $previous, string $comparison = 'month_over_month', string $suffix = '%' ): array {
        $comparison = $this->normalizeComparison( $comparison );
        $previousValue = $previous === null ? null : (float) $previous;
        $currentValue = (float) $current;
        $delta = $previousValue === null ? null : $currentValue - $previousValue;
        $deltaPercent = $this->deltaPercent( $currentValue, $previousValue );
        [ $label, $class ] = $this->formatDeltaLabel( $deltaPercent, $comparison, $suffix );

        return [
            'comparison' => $comparison,
            'comparison_label' => (string) ( $this->supportedComparisons()[ $comparison ] ?? $comparison ),
            'current_value' => $currentValue,
            'previous_value' => $previousValue,
            'delta_value' => $delta,
            'delta_percent' => $deltaPercent,
            'delta_label' => $label,
            'delta_class' => $class,
        ];
    }

    public function compareSeries( array $points, string $valueKey, string $comparison = 'month_over_month', string $suffix = '%' ): array {
        $normalizedPoints = array_values( array_filter( $points, 'is_array' ) );
        $count = count( $normalizedPoints );
        $current = $count > 0 ? (float) ( $normalizedPoints[ $count - 1 ][ $valueKey ] ?? 0.0 ) : 0.0;
        $previous = $count > 1 ? (float) ( $normalizedPoints[ $count - 2 ][ $valueKey ] ?? 0.0 ) : null;

        return array_merge(
            $this->compareValues( $current, $previous, $comparison, $suffix ),
            [
                'point_count' => $count,
                'current_point' => $count > 0 ? $normalizedPoints[ $count - 1 ] : null,
                'previous_point' => $count > 1 ? $normalizedPoints[ $count - 2 ] : null,
            ]
        );
    }

    public function resolveWindows( string $comparison, ?\DateTimeImmutable $anchor = null ): array {
        $comparison = $this->normalizeComparison( $comparison );
        $anchor = $anchor ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

        return match ( $comparison ) {
            'day_over_day' => [
                'comparison' => $comparison,
                'current' => [
                    'from' => $anchor->format( 'Y-m-d 00:00:00' ),
                    'to' => $anchor->format( 'Y-m-d 23:59:59' ),
                ],
                'previous' => [
                    'from' => $anchor->modify( '-1 day' )->format( 'Y-m-d 00:00:00' ),
                    'to' => $anchor->modify( '-1 day' )->format( 'Y-m-d 23:59:59' ),
                ],
            ],
            'week_over_week' => [
                'comparison' => $comparison,
                'current' => [
                    'from' => $anchor->modify( 'monday this week' )->format( 'Y-m-d 00:00:00' ),
                    'to' => $anchor->modify( 'sunday this week' )->format( 'Y-m-d 23:59:59' ),
                ],
                'previous' => [
                    'from' => $anchor->modify( 'monday last week' )->format( 'Y-m-d 00:00:00' ),
                    'to' => $anchor->modify( 'sunday last week' )->format( 'Y-m-d 23:59:59' ),
                ],
            ],
            'month_over_month' => [
                'comparison' => $comparison,
                'current' => [
                    'from' => $anchor->format( 'Y-m-01 00:00:00' ),
                    'to' => $anchor->format( 'Y-m-t 23:59:59' ),
                ],
                'previous' => [
                    'from' => $anchor->modify( 'first day of last month' )->format( 'Y-m-01 00:00:00' ),
                    'to' => $anchor->modify( 'last day of last month' )->format( 'Y-m-t 23:59:59' ),
                ],
            ],
            'quarter_over_quarter' => $this->quarterWindows( $anchor, $comparison ),
            'year_over_year' => [
                'comparison' => $comparison,
                'current' => [
                    'from' => $anchor->format( 'Y-01-01 00:00:00' ),
                    'to' => $anchor->format( 'Y-12-31 23:59:59' ),
                ],
                'previous' => [
                    'from' => $anchor->modify( '-1 year' )->format( 'Y-01-01 00:00:00' ),
                    'to' => $anchor->modify( '-1 year' )->format( 'Y-12-31 23:59:59' ),
                ],
            ],
            default => $this->resolveWindows( 'month_over_month', $anchor ),
        };
    }

    public function normalizeComparison( string $comparison ): string {
        $normalized = strtolower( trim( str_replace( '-', '_', $comparison ) ) );
        return match ( $normalized ) {
            'day_over_day', 'day_overday', 'dod' => 'day_over_day',
            'week_over_week', 'wow' => 'week_over_week',
            'month_over_month', 'mom' => 'month_over_month',
            'quarter_over_quarter', 'qoq' => 'quarter_over_quarter',
            'year_over_year', 'yoy' => 'year_over_year',
            default => 'month_over_month',
        };
    }

    private function deltaPercent( float $current, ?float $previous ): ?float {
        if ( $previous === null ) {
            return null;
        }

        if ( abs( $previous ) < 0.000001 ) {
            if ( abs( $current ) < 0.000001 ) {
                return 0.0;
            }

            return 100.0;
        }

        return ( ( $current - $previous ) / abs( $previous ) ) * 100.0;
    }

    private function formatDeltaLabel( ?float $deltaPercent, string $comparison, string $suffix ): array {
        $baseline = $this->baselineLabel( $comparison );
        if ( $deltaPercent === null ) {
            return [ sprintf( 'No prior %s', $baseline ), 'neutral' ];
        }

        if ( abs( $deltaPercent ) < 0.05 ) {
            return [ sprintf( 'Flat vs previous %s', $baseline ), 'neutral' ];
        }

        $direction = $deltaPercent > 0 ? 'up' : 'down';
        $class = $deltaPercent > 0 ? 'positive' : 'negative';

        return [
            sprintf( '%s %s%s vs previous %s', $direction, number_format( abs( $deltaPercent ), 1 ), $suffix, $baseline ),
            $class,
        ];
    }

    private function baselineLabel( string $comparison ): string {
        return match ( $this->normalizeComparison( $comparison ) ) {
            'day_over_day' => 'day',
            'week_over_week' => 'week',
            'month_over_month' => 'month',
            'quarter_over_quarter' => 'quarter',
            'year_over_year' => 'year',
            default => 'period',
        };
    }

    private function quarterWindows( \DateTimeImmutable $anchor, string $comparison ): array {
        $year = (int) $anchor->format( 'Y' );
        $month = (int) $anchor->format( 'n' );
        $quarter = (int) floor( ( $month - 1 ) / 3 ) + 1;
        $currentStartMonth = ( ( $quarter - 1 ) * 3 ) + 1;
        $previousQuarter = $quarter === 1 ? 4 : $quarter - 1;
        $previousYear = $quarter === 1 ? $year - 1 : $year;
        $previousStartMonth = ( ( $previousQuarter - 1 ) * 3 ) + 1;

        $currentStart = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $currentStartMonth ), new \DateTimeZone( 'UTC' ) );
        $currentEnd = $currentStart->modify( '+2 months' )->modify( 'last day of this month' )->setTime( 23, 59, 59 );
        $previousStart = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $previousYear, $previousStartMonth ), new \DateTimeZone( 'UTC' ) );
        $previousEnd = $previousStart->modify( '+2 months' )->modify( 'last day of this month' )->setTime( 23, 59, 59 );

        return [
            'comparison' => $comparison,
            'current' => [
                'from' => $currentStart->format( 'Y-m-d H:i:s' ),
                'to' => $currentEnd->format( 'Y-m-d H:i:s' ),
            ],
            'previous' => [
                'from' => $previousStart->format( 'Y-m-d H:i:s' ),
                'to' => $previousEnd->format( 'Y-m-d H:i:s' ),
            ],
        ];
    }
}
