<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

final class HermesPlaybookEngine {
    public function __construct(
        private readonly HermesDefinitionLibrary $library
    ) {}

    public function match( string $query, array $context_pack_keys = [] ): array {
        $needle = strtolower( trim( $query ) );
        $matches = [];

        foreach ( $this->library->playbooks() as $playbook ) {
            if ( ! is_array( $playbook ) ) {
                continue;
            }

            $score = 0;
            foreach ( (array) ( $playbook['intent_signals'] ?? [] ) as $signal ) {
                if ( $needle !== '' && str_contains( $needle, strtolower( (string) $signal ) ) ) {
                    $score += 6;
                }
            }

            foreach ( (array) ( $playbook['required_context_packs'] ?? [] ) as $required_pack ) {
                if ( in_array( (string) $required_pack, $context_pack_keys, true ) ) {
                    $score += 2;
                }
            }

            if ( $score > 0 ) {
                $playbook['_score'] = $score;
                $matches[] = $playbook;
            }
        }

        usort( $matches, static fn ( array $a, array $b ): int => (int) ( $b['_score'] ?? 0 ) <=> (int) ( $a['_score'] ?? 0 ) );
        return array_slice( $matches, 0, 3 );
    }
}
