<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class AmountParser {
    /** @var array<string,int> */
    private array $numberWords = [];

    public function __construct(
        private readonly LanguagePackLoader $packs
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function parseAll( string $input, string $locale = 'en-US' ): array {
        $normalized = strtolower( trim( $input ) );
        if ( $normalized === '' ) {
            return [];
        }

        $results = [];
        if ( preg_match_all( '/\bbetween\s+(.+?)\s+and\s+(.+?)(?=\b(?:in|from|during|for|who|that|$))/i', $normalized, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $min = $this->parseNumberValue( (string) ( $match[1] ?? '' ), $locale );
                $max = $this->parseNumberValue( (string) ( $match[2] ?? '' ), $locale );
                if ( $min !== null && $max !== null ) {
                    $results[] = [ 'kind' => 'range', 'min' => $min, 'max' => $max, 'source' => trim( $match[0] ) ];
                }
            }
        }

        $patterns = [
            'min' => '/\b(?:over|more than|greater than|at least|minimum of)\s+([a-z0-9$,\.\-\s]+?)(?=\b(?:in|from|during|for|who|that|$))/i',
            'max' => '/\b(?:under|less than|below|at most|no more than)\s+([a-z0-9$,\.\-\s]+?)(?=\b(?:in|from|during|for|who|that|$))/i',
            'eq' => '/\b(?:exactly)\s+([a-z0-9$,\.\-\s]+?)(?=\b(?:in|from|during|for|who|that|$))/i',
        ];

        foreach ( $patterns as $kind => $pattern ) {
            if ( ! preg_match_all( $pattern, $normalized, $matches, PREG_SET_ORDER ) ) {
                continue;
            }

            foreach ( $matches as $match ) {
                $value = $this->parseNumberValue( (string) ( $match[1] ?? '' ), $locale );
                if ( $value === null ) {
                    continue;
                }

                $results[] = [ 'kind' => $kind, 'value' => $value, 'source' => trim( $match[0] ) ];
            }
        }

        if ( $results === [] ) {
            if ( preg_match( '/(?:^|\s)(\$?\d[\d,]*(?:\.\d+)?(?:k)?|\d+\s+bucks|\d+\s+dollars|\d+\s+grand)\b/i', $normalized, $match ) === 1 ) {
                $value = $this->parseNumberValue( (string) ( $match[1] ?? '' ), $locale );
                if ( $value !== null ) {
                    $results[] = [ 'kind' => 'eq', 'value' => $value, 'source' => trim( $match[1] ) ];
                }
            }
        }

        return $results;
    }

    public function parseNumberValue( string $raw, string $locale = 'en-US' ): ?float {
        $value = strtolower( trim( $raw ) );
        if ( $value === '' ) {
            return null;
        }

        $value = str_replace( [ ',', '$' ], '', $value );
        $value = preg_replace( '/\bdollars?\b|\bbucks?\b/', '', $value ) ?? $value;
        $value = preg_replace( '/\ba\s+hundred\b/', 'one hundred', $value ) ?? $value;
        $value = preg_replace( '/\s+/', ' ', trim( $value ) ) ?? $value;

        if ( preg_match( '/^(\d+(?:\.\d+)?)\s*k$/', $value, $match ) === 1 ) {
            return (float) $match[1] * 1000;
        }
        if ( preg_match( '/^(\d+(?:\.\d+)?)\s*(grand)$/', $value, $match ) === 1 ) {
            return (float) $match[1] * 1000;
        }
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }

        return $this->parseWordNumber( $value, $locale );
    }

    private function parseWordNumber( string $value, string $locale ): ?float {
        $pack = $this->packs->load( $locale );
        $this->numberWords = array_map( 'intval', (array) ( $pack['money']['number_words'] ?? [] ) );
        if ( $this->numberWords === [] ) {
            return null;
        }

        $tokens = preg_split( '/[\s-]+/', $value ) ?: [];
        $current = 0;
        $total = 0;

        foreach ( $tokens as $token ) {
            $token = trim( $token );
            if ( $token === '' || $token === 'and' ) {
                continue;
            }

            if ( $token === 'grand' ) {
                $current = max( 1, $current ) * 1000;
                continue;
            }

            if ( $token === 'k' ) {
                $current = max( 1, $current ) * 1000;
                continue;
            }

            $number = $this->numberWords[ $token ] ?? null;
            if ( $number === null ) {
                return null;
            }

            if ( $number === 100 ) {
                $current = max( 1, $current ) * 100;
                continue;
            }

            if ( $number >= 1000 ) {
                $current = max( 1, $current ) * $number;
                $total += $current;
                $current = 0;
                continue;
            }

            $current += $number;
        }

        $result = $total + $current;
        return $result > 0 ? (float) $result : null;
    }
}
