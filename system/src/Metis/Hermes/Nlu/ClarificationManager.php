<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class ClarificationManager {
    /**
     * @param array<int,array<string,mixed>> $candidates
     * @param array<string,mixed> $analysis
     * @return array<string,mixed>
     */
    public function forCommandCandidates( string $fragment, array $candidates, array $analysis ): array {
        $top = (array) ( $candidates[0] ?? [] );
        $second = (array) ( $candidates[1] ?? [] );
        $topIntent = (string) ( $top['intent'] ?? '' );
        $secondIntent = (string) ( $second['intent'] ?? '' );
        $topConfidence = (float) ( $top['confidence'] ?? 0.0 );
        $secondConfidence = (float) ( $second['confidence'] ?? 0.0 );

        if ( $topIntent !== '' && $secondIntent !== '' && abs( $topConfidence - $secondConfidence ) < 0.08 ) {
            if ( $this->isPeopleLookupPair( $topIntent, $secondIntent ) ) {
                return [
                    'requires_clarification' => true,
                    'clarification_prompt' => 'Do you want donors, donations, or contacts?',
                    'pending_context' => [
                        'type' => 'entity_type',
                        'base_query' => $fragment,
                    ],
                ];
            }

            return [
                'requires_clarification' => true,
                'clarification_prompt' => 'I found multiple likely actions. Which one would you like to run?',
                'pending_context' => [],
            ];
        }

        if ( ! empty( $analysis['context_ambiguous'] ) ) {
            return [
                'requires_clarification' => true,
                'clarification_prompt' => 'I found a reference to an earlier result, but I need you to specify the record.',
                'pending_context' => [],
            ];
        }

        return [ 'requires_clarification' => false, 'clarification_prompt' => '', 'pending_context' => [] ];
    }

    /**
     * @param array<string,mixed> $dataIntent
     * @return array<string,mixed>
     */
    public function forDataIntent( array $dataIntent ): array {
        $entity = (string) ( $dataIntent['entity'] ?? '' );
        $filters = (array) ( $dataIntent['filters'] ?? [] );
        $dateRange = (array) ( $dataIntent['date_range'] ?? [] );
        $normalized = (string) ( $dataIntent['normalized_input'] ?? '' );

        if ( in_array( $entity, [ 'donor', 'donation_transaction' ], true ) && $this->hasAmountFilter( $filters ) && $dateRange === [] ) {
            return [
                'requires_clarification' => true,
                'clarification_prompt' => 'I found a giving search but no date range. Should I use this month, last month, this year, or all time?',
                'pending_context' => [
                    'type' => 'date_range',
                    'base_query' => $normalized,
                ],
            ];
        }

        if ( $entity === '' && preg_match( '/\b(gave|donated|supporters?|givers?)\b/', $normalized ) === 1 ) {
            return [
                'requires_clarification' => true,
                'clarification_prompt' => 'Do you want donors, donations, or contacts?',
                'pending_context' => [
                    'type' => 'entity_type',
                    'base_query' => $normalized,
                ],
            ];
        }

        return [ 'requires_clarification' => false, 'clarification_prompt' => '', 'pending_context' => [] ];
    }

    /**
     * @param array<int,array<string,mixed>> $filters
     */
    private function hasAmountFilter( array $filters ): bool {
        foreach ( $filters as $filter ) {
            $field = strtolower( trim( (string) ( $filter['field'] ?? '' ) ) );
            if ( str_contains( $field, 'amount' ) || str_contains( $field, 'total' ) ) {
                return true;
            }
        }

        return false;
    }

    private function isPeopleLookupPair( string $left, string $right ): bool {
        $pair = [ $left, $right ];
        sort( $pair );
        return $pair === [ 'get_entity_attribute', 'lookup_profile' ];
    }
}
