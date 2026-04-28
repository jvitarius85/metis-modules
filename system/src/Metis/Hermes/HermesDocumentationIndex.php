<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

final class HermesDocumentationIndex {
    private ?array $cached = null;

    public function __construct(
        private readonly HermesDefinitionLibrary $library
    ) {}

    public function search( string $query, int $limit = 6 ): array {
        $needle = strtolower( trim( $query ) );
        if ( $needle === '' ) {
            return [];
        }

        $matches = [];
        foreach ( $this->documents() as $document ) {
            $haystack = strtolower( implode( "\n", array_filter( [
                (string) ( $document['title'] ?? '' ),
                (string) ( $document['description'] ?? '' ),
                implode( ' ', array_map( 'strval', (array) ( $document['keywords'] ?? [] ) ) ),
            ] ) ) );

            $score = $this->scoreText( $needle, $haystack );
            if ( $score <= 0 ) {
                continue;
            }

            $document['_score'] = $score;
            $matches[] = $document;
        }

        usort( $matches, static fn ( array $a, array $b ): int => (int) ( $b['_score'] ?? 0 ) <=> (int) ( $a['_score'] ?? 0 ) );
        return array_slice( $matches, 0, max( 1, min( 20, $limit ) ) );
    }

    public function documents(): array {
        if ( $this->cached !== null ) {
            return $this->cached;
        }

        $snapshot = $this->library->runtimeSnapshot();
        $documents = [];

        foreach ( [ 'context_packs', 'playbooks', 'missions' ] as $group ) {
            foreach ( (array) ( $snapshot[ $group ] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $documents[] = [
                    'type'        => rtrim( $group, 's' ),
                    'key'         => (string) ( $item['key'] ?? '' ),
                    'title'       => (string) ( $item['title'] ?? $item['key'] ?? 'Hermes Item' ),
                    'description' => (string) ( $item['description'] ?? $item['objective'] ?? '' ),
                    'keywords'    => array_values( array_filter( array_map( 'strval', array_merge(
                        (array) ( $item['intent_signals'] ?? [] ),
                        (array) ( $item['required_context_packs'] ?? [] ),
                        (array) ( $item['source_modules'] ?? [] )
                    ) ) ) ),
                    'source'      => 'hermes_library',
                ];
            }
        }

        $this->cached = $documents;
        return $this->cached;
    }

    private function scoreText( string $needle, string $haystack ): int {
        if ( $needle !== '' && str_contains( $haystack, $needle ) ) {
            return 100;
        }

        $tokens = $this->tokens( $needle );
        if ( $tokens === [] ) {
            return 0;
        }

        $score = 0;
        foreach ( $tokens as $token ) {
            if ( str_contains( $haystack, $token ) ) {
                $score += strlen( $token ) >= 6 ? 8 : 4;
            }
        }

        return $score;
    }

    /**
     * @return array<int,string>
     */
    private function tokens( string $value ): array {
        $parts = preg_split( '/[^a-z0-9]+/', strtolower( $value ) ) ?: [];
        $stop = array_fill_keys( [ 'the', 'and', 'for', 'with', 'that', 'this', 'how', 'can', 'cant', 'cannot', 'could', 'would', 'please', 'what', 'where', 'when', 'why', 'into', 'from', 'have', 'does', 'doesn', 'dont' ], true );

        return array_values( array_unique( array_filter(
            $parts,
            static fn ( string $part ): bool => strlen( $part ) >= 3 && ! isset( $stop[ $part ] )
        ) ) );
    }
}
