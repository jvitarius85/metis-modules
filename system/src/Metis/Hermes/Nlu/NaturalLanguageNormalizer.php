<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class NaturalLanguageNormalizer {
    private const FILLER_PATTERNS = [
        '/\b(hey|hi|hello)\b/i',
        '/\b(can you|could you|would you|please|for me)\b/i',
        '/\b(i need you to|i want you to|help me)\b/i',
    ];

    public function __construct(
        private readonly LanguagePackLoader $packs
    ) {}

    public function normalize( string $input, string $locale = 'en-US' ): string {
        $normalized = strtolower( trim( $input ) );
        foreach ( self::FILLER_PATTERNS as $pattern ) {
            $normalized = preg_replace( $pattern, ' ', $normalized ) ?? $normalized;
        }

        $normalized = str_replace(
            [ '?', '!', ';', ':', "\n", "\r", "\t" ],
            ' ',
            $normalized
        );

        $pack = $this->packs->load( $locale );
        foreach ( (array) ( $pack['misspellings'] ?? [] ) as $wrong => $correct ) {
            $wrong = strtolower( trim( (string) $wrong ) );
            $correct = strtolower( trim( (string) $correct ) );
            if ( $wrong === '' || $correct === '' ) {
                continue;
            }

            $normalized = preg_replace(
                '/\b' . preg_quote( $wrong, '/' ) . '\b/',
                $correct,
                $normalized
            ) ?? $normalized;
        }

        $normalized = str_replace(
            [ 'look-up', 'look  up', 'year-to-date', 'month-to-date', 'quarter-to-date' ],
            [ 'look up', 'look up', 'year to date', 'month to date', 'quarter to date' ],
            $normalized
        );

        return trim( preg_replace( '/\s+/', ' ', $normalized ) ?? $normalized );
    }
}
