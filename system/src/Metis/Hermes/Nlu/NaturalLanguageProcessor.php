<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

use Metis\Hermes\HermesMemoryStore;

final class NaturalLanguageProcessor {
    private readonly ContextStore $contextStore;

    public function __construct(
        private readonly NaturalLanguageNormalizer $normalizer,
        private readonly IntentMatcher $matcher,
        private readonly ClarificationManager $clarifications,
        HermesMemoryStore $memory
    ) {
        $this->contextStore = new ContextStore( $memory );
    }

    public function normalizeInput( string $input, string $locale = 'en-US' ): string {
        return $this->normalizer->normalize( $input, $locale );
    }

    /**
     * @param array<string,array<string,mixed>> $commandDefinitions
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function analyzeCommandFragment( string $raw, string $normalized, array $commandDefinitions, array $context, string $sessionCode = '', string $locale = 'en-US' ): array {
        $analysis = $this->matcher->analyzeCommandFragment( $raw, $normalized, $commandDefinitions, $context, $locale );
        $clarification = $this->clarifications->forCommandCandidates(
            $normalized,
            array_values( (array) ( $analysis['candidates'] ?? [] ) ),
            $analysis
        );

        if ( $sessionCode !== '' && ! empty( $clarification['requires_clarification'] ) && ! empty( $clarification['pending_context'] ) ) {
            $this->contextStore->remember( $sessionCode, (array) $clarification['pending_context'] );
        }

        return $analysis + [ 'clarification' => $clarification ];
    }

    /**
     * @param array<string,mixed> $dataIntent
     * @return array<string,mixed>
     */
    public function dataClarification( array $dataIntent, string $sessionCode = '' ): array {
        $clarification = $this->clarifications->forDataIntent( $dataIntent );
        if ( $sessionCode !== '' && ! empty( $clarification['requires_clarification'] ) && ! empty( $clarification['pending_context'] ) ) {
            $this->contextStore->remember( $sessionCode, (array) $clarification['pending_context'] );
        }

        return $clarification;
    }

    public function mergePendingContextQuery( string $query, string $sessionCode, string $locale = 'en-US' ): string {
        if ( $sessionCode === '' ) {
            return $query;
        }

        $context = $this->contextStore->recall( $sessionCode );
        if ( $context === [] ) {
            return $query;
        }

        $normalized = $this->normalizer->normalize( $query, $locale );
        $type = (string) ( $context['type'] ?? '' );
        $baseQuery = trim( (string) ( $context['base_query'] ?? '' ) );
        if ( $baseQuery === '' ) {
            return $query;
        }

        if ( $type === 'date_range' && $normalized !== '' ) {
            $this->contextStore->clear( $sessionCode );
            return trim( $baseQuery . ' ' . $normalized );
        }

        if ( $type === 'entity_type' && preg_match( '/^(donors?|donations?|contacts?|people)$/', $normalized ) === 1 ) {
            $this->contextStore->clear( $sessionCode );
            return trim( $normalized . ' ' . $baseQuery );
        }

        return $query;
    }
}
