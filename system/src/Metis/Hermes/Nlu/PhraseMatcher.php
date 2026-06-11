<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class PhraseMatcher {
    public function __construct(
        private readonly LanguagePackLoader $packs
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function match( string $normalized, string $locale = 'en-US' ): array {
        $matches = [];
        foreach ( (array) ( $this->packs->load( $locale )['phrases'] ?? [] ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $phrase = strtolower( trim( (string) ( $entry['phrase'] ?? '' ) ) );
            if ( $phrase === '' || ! str_contains( $normalized, $phrase ) ) {
                continue;
            }

            $matches[] = [
                'phrase' => $phrase,
                'action' => (string) ( $entry['action'] ?? '' ),
                'entity' => (string) ( $entry['entity'] ?? '' ),
                'weight' => (float) ( $entry['weight'] ?? 0.9 ),
            ];
        }

        usort(
            $matches,
            static fn ( array $left, array $right ): int => strlen( (string) $right['phrase'] ) <=> strlen( (string) $left['phrase'] )
        );

        return $matches;
    }
}
