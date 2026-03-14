<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

final class HermesMissionEngine {
    public function __construct(
        private readonly HermesDefinitionLibrary $library
    ) {}

    public function match( string $query, array $context_pack_keys = [] ): array {
        $needle = strtolower( trim( $query ) );
        $matches = [];

        foreach ( $this->library->missions() as $mission ) {
            if ( ! is_array( $mission ) ) {
                continue;
            }

            $haystack = strtolower( implode( "\n", [
                (string) ( $mission['key'] ?? '' ),
                (string) ( $mission['title'] ?? '' ),
                (string) ( $mission['objective'] ?? '' ),
            ] ) );

            $score = $needle !== '' && str_contains( $haystack, $needle ) ? 5 : 0;
            foreach ( (array) ( $mission['required_context_packs'] ?? [] ) as $required_pack ) {
                if ( in_array( (string) $required_pack, $context_pack_keys, true ) ) {
                    $score++;
                }
            }

            if ( str_contains( $needle, 'mission' ) ) {
                $score++;
            }

            if ( $score > 0 ) {
                $mission['_score'] = $score;
                $matches[] = $mission;
            }
        }

        usort( $matches, static fn ( array $a, array $b ): int => (int) ( $b['_score'] ?? 0 ) <=> (int) ( $a['_score'] ?? 0 ) );
        return array_slice( $matches, 0, 2 );
    }

    public function plan( string $mission_key ): ?array {
        $mission = $this->library->getMission( $mission_key );
        if ( ! is_array( $mission ) ) {
            return null;
        }

        return [
            'key' => (string) ( $mission['key'] ?? '' ),
            'title' => (string) ( $mission['title'] ?? '' ),
            'objective' => (string) ( $mission['objective'] ?? '' ),
            'phases' => (array) ( $mission['phases'] ?? [] ),
            'approval_gates' => (array) ( $mission['approval_gates'] ?? [] ),
            'success_criteria' => (array) ( $mission['success_criteria'] ?? [] ),
        ];
    }
}
