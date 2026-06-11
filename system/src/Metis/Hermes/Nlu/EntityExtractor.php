<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class EntityExtractor {
    public function __construct(
        private readonly LanguagePackLoader $packs,
        private readonly AmountParser $amounts,
        private readonly DateParser $dates
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function extract( string $raw, string $normalized, string $locale = 'en-US' ): array {
        preg_match_all( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $raw, $emails );
        preg_match_all( '/(?:\+?1[\s.-]*)?(?:\(?\d{3}\)?[\s.-]*)\d{3}[\s.-]*\d{4}/', $raw, $phones );
        preg_match_all( '/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+|[A-Z]{2,}(?:\s+[A-Z][a-z]+)*)\b/', $raw, $names );

        $statuses = [];
        foreach ( [ 'active', 'inactive', 'pending', 'completed', 'cancelled', 'failed', 'open', 'closed' ] as $status ) {
            if ( preg_match( '/\b' . preg_quote( $status, '/' ) . '\b/', $normalized ) === 1 ) {
                $statuses[] = $status;
            }
        }

        $pack = $this->packs->load( $locale );
        $entityTerms = [];
        foreach ( (array) ( $pack['entities'] ?? [] ) as $canonical => $aliases ) {
            foreach ( (array) $aliases as $alias ) {
                $alias = strtolower( trim( (string) $alias ) );
                if ( $alias !== '' && preg_match( '/\b' . preg_quote( $alias, '/' ) . '\b/', $normalized ) === 1 ) {
                    $entityTerms[ $canonical ] = $canonical;
                }
            }
        }

        return [
            'emails' => array_values( array_unique( (array) ( $emails[0] ?? [] ) ) ),
            'phones' => array_values( array_unique( array_map( 'trim', (array) ( $phones[0] ?? [] ) ) ) ),
            'names' => array_values( array_unique( array_map( 'trim', (array) ( $names[1] ?? [] ) ) ) ),
            'statuses' => array_values( array_unique( $statuses ) ),
            'entity_terms' => array_values( $entityTerms ),
            'amounts' => $this->amounts->parseAll( $normalized, $locale ),
            'date_range' => $this->dates->parse( $normalized, $locale ),
        ];
    }
}
