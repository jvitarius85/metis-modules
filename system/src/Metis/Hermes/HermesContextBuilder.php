<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

final class HermesContextBuilder {
    public function __construct(
        private readonly HermesDefinitionLibrary $library,
        private readonly HermesMemoryStore $memory,
        private readonly HermesKnowledgeService $knowledge
    ) {}

    public function build( string $query, array $session = [] ): array {
        $snapshot = $this->library->runtimeSnapshot();
        $context_packs = $this->rankContextPacks( $query, (array) ( $snapshot['context_packs'] ?? [] ) );

        return [
            'session' => $session,
            'query' => $query,
            'context_packs' => $context_packs,
            'dynamic_layer' => (array) ( $snapshot['dynamic_layer']['snapshot'] ?? [] ),
            'knowledge' => $this->knowledge->resolve( $query, 6 ),
            'memory' => $this->memory->recall( (string) ( $session['session_code'] ?? '' ), 4 ),
        ];
    }

    private function rankContextPacks( string $query, array $packs ): array {
        $needle = strtolower( trim( $query ) );
        $tokens = $this->tokens( $needle );
        $scored = [];

        foreach ( $packs as $pack ) {
            if ( ! is_array( $pack ) ) {
                continue;
            }

            $score = 0;
            $haystack = strtolower( implode( "\n", [
                (string) ( $pack['key'] ?? '' ),
                (string) ( $pack['title'] ?? '' ),
                (string) ( $pack['description'] ?? '' ),
                implode( ' ', array_map( 'strval', (array) ( $pack['source_modules'] ?? [] ) ) ),
            ] ) );

            if ( $needle !== '' && str_contains( $haystack, $needle ) ) {
                $score += 5;
            }
            foreach ( $tokens as $token ) {
                if ( str_contains( $haystack, $token ) ) {
                    $score += strlen( $token ) >= 6 ? 3 : 1;
                }
            }

            foreach ( (array) ( $pack['common_operational_issues'] ?? [] ) as $issue ) {
                $issue_text = strtolower( $this->stringify( $issue ) );
                if ( $needle !== '' && str_contains( $issue_text, $needle ) ) {
                    $score += 4;
                }
                foreach ( $tokens as $token ) {
                    if ( str_contains( $issue_text, $token ) ) {
                        $score += strlen( $token ) >= 6 ? 2 : 1;
                    }
                }
            }

            $pack['_score'] = $score;
            $scored[] = $pack;
        }

        usort( $scored, static fn ( array $a, array $b ): int => (int) ( $b['_score'] ?? 0 ) <=> (int) ( $a['_score'] ?? 0 ) );

        return array_slice( $scored, 0, 4 );
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

    private function stringify( mixed $value ): string {
        if ( is_scalar( $value ) || $value === null ) {
            return (string) $value;
        }
        if ( is_array( $value ) ) {
            return implode( ' ', array_map( fn ( mixed $item ): string => $this->stringify( $item ), $value ) );
        }

        return '';
    }
}
