<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class DateParser {
    public function __construct(
        private readonly LanguagePackLoader $packs
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function parse( string $input, string $locale = 'en-US' ): array {
        $normalized = strtolower( trim( $input ) );
        if ( $normalized === '' ) {
            return [];
        }

        $tz = $this->timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        $packPresets = (array) ( $this->packs->load( $locale )['dates']['presets'] ?? [] );

        foreach ( $packPresets as $phrase => $preset ) {
            $phrase = strtolower( trim( (string) $phrase ) );
            if ( $phrase !== '' && str_contains( $normalized, $phrase ) ) {
                return $this->rangeForPreset( (string) $preset, $now );
            }
        }

        if ( preg_match( '/\bpast\s+(30|60|90)\s+days\b/', $normalized, $matches ) === 1 ) {
            $days = (int) $matches[1];
            return [
                'preset' => 'past_' . $days . '_days',
                'from' => $now->sub( new DateInterval( 'P' . $days . 'D' ) )->format( 'Y-m-d' ),
                'to' => $now->format( 'Y-m-d' ),
            ];
        }

        if ( str_contains( $normalized, 'last 12 months' ) ) {
            return [
                'preset' => 'last_12_months',
                'from' => $now->sub( new DateInterval( 'P12M' ) )->format( 'Y-m-d' ),
                'to' => $now->format( 'Y-m-d' ),
            ];
        }

        preg_match_all( '/\b(\d{4}-\d{2}-\d{2})\b/', $normalized, $matches );
        if ( ! empty( $matches[1] ) ) {
            if ( count( $matches[1] ) >= 2 ) {
                return [ 'from' => $matches[1][0], 'to' => $matches[1][1] ];
            }

            if ( str_contains( $normalized, 'after' ) || str_contains( $normalized, 'since' ) ) {
                return [ 'from' => $matches[1][0] ];
            }
            if ( str_contains( $normalized, 'before' ) || str_contains( $normalized, 'until' ) ) {
                return [ 'to' => $matches[1][0] ];
            }

            return [ 'from' => $matches[1][0], 'to' => $matches[1][0] ];
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function rangeForPreset( string $preset, DateTimeImmutable $now ): array {
        return match ( $preset ) {
            'today' => [
                'preset' => 'today',
                'from' => $now->format( 'Y-m-d' ),
                'to' => $now->format( 'Y-m-d' ),
            ],
            'yesterday' => [
                'preset' => 'yesterday',
                'from' => $now->sub( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ),
                'to' => $now->sub( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ),
            ],
            'tomorrow' => [
                'preset' => 'tomorrow',
                'from' => $now->add( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ),
                'to' => $now->add( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ),
            ],
            'this_week' => $this->weekRange( $now, 0, 'this_week' ),
            'last_week' => $this->weekRange( $now, -7, 'last_week' ),
            'next_week' => $this->weekRange( $now, 7, 'next_week' ),
            'this_month' => $this->monthRange( $now, 0, 'this_month' ),
            'last_month' => $this->monthRange( $now, -1, 'last_month' ),
            'next_month' => $this->monthRange( $now, 1, 'next_month' ),
            'this_quarter' => $this->quarterRange( $now, 0, 'this_quarter' ),
            'last_quarter' => $this->quarterRange( $now, -1, 'last_quarter' ),
            'this_year' => [
                'preset' => 'this_year',
                'from' => $now->setDate( (int) $now->format( 'Y' ), 1, 1 )->format( 'Y-m-d' ),
                'to' => $now->setDate( (int) $now->format( 'Y' ), 12, 31 )->format( 'Y-m-d' ),
            ],
            'last_year' => [
                'preset' => 'last_year',
                'from' => $now->modify( 'first day of january last year' )->format( 'Y-m-d' ),
                'to' => $now->modify( 'last day of december last year' )->format( 'Y-m-d' ),
            ],
            'mtd' => [
                'preset' => 'mtd',
                'from' => $now->modify( 'first day of this month' )->format( 'Y-m-d' ),
                'to' => $now->format( 'Y-m-d' ),
            ],
            'ytd' => [
                'preset' => 'ytd',
                'from' => $now->setDate( (int) $now->format( 'Y' ), 1, 1 )->format( 'Y-m-d' ),
                'to' => $now->format( 'Y-m-d' ),
            ],
            default => [ 'preset' => $preset ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function weekRange( DateTimeImmutable $now, int $offsetDays, string $preset ): array {
        $base = $offsetDays === 0 ? $now : $now->modify( sprintf( '%+d days', $offsetDays ) );
        $start = $base->modify( 'monday this week' );
        $end = $base->modify( 'sunday this week' );

        return [ 'preset' => $preset, 'from' => $start->format( 'Y-m-d' ), 'to' => $end->format( 'Y-m-d' ) ];
    }

    /**
     * @return array<string,mixed>
     */
    private function monthRange( DateTimeImmutable $now, int $offsetMonths, string $preset ): array {
        $base = $offsetMonths === 0 ? $now : $now->modify( sprintf( '%+d month', $offsetMonths ) );
        return [
            'preset' => $preset,
            'from' => $base->modify( 'first day of this month' )->format( 'Y-m-d' ),
            'to' => $base->modify( 'last day of this month' )->format( 'Y-m-d' ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function quarterRange( DateTimeImmutable $now, int $offsetQuarters, string $preset ): array {
        $month = (int) $now->format( 'n' );
        $quarterStartMonth = ( (int) floor( ( $month - 1 ) / 3 ) * 3 ) + 1;
        $base = $now->setDate( (int) $now->format( 'Y' ), $quarterStartMonth, 1 );
        if ( $offsetQuarters !== 0 ) {
            $base = $base->modify( sprintf( '%+d months', $offsetQuarters * 3 ) );
        }

        $end = $base->modify( '+2 months' )->modify( 'last day of this month' );
        return [ 'preset' => $preset, 'from' => $base->format( 'Y-m-d' ), 'to' => $end->format( 'Y-m-d' ) ];
    }

    private function timezone(): DateTimeZone {
        $timezone = date_default_timezone_get();
        return new DateTimeZone( $timezone !== '' ? $timezone : 'UTC' );
    }
}
