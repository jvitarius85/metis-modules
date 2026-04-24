<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

/**
 * HermesPlaybookEngine
 *
 * Matches incoming queries to registered playbooks and validates
 * playbook definitions against the universal action registry before
 * any step is executed.
 */
final class HermesPlaybookEngine {

    private HermesDefinitionLibrary $library;
    private HermesPlaybookValidator $validator;

    public function __construct(
        HermesDefinitionLibrary $library,
        HermesPlaybookValidator $validator
    ) {
        $this->library   = $library;
        $this->validator = $validator;
    }

    /**
     * Returns the top matching playbooks (max 3) for a query,
     * scored by intent signal overlap and loaded context pack overlap.
     */
    public function match( string $query, array $context_pack_keys = [] ): array {
        $needle  = strtolower( trim( $query ) );
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

            foreach ( (array) ( $playbook['required_context_packs'] ?? [] ) as $required ) {
                if ( in_array( (string) $required, $context_pack_keys, true ) ) {
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

    /**
     * Returns a playbook by key from the library, or null if not found.
     */
    public function get( string $key ): ?array {
        foreach ( $this->library->playbooks() as $playbook ) {
            if ( is_array( $playbook ) && (string) ( $playbook['key'] ?? '' ) === $key ) {
                return $playbook;
            }
        }
        return null;
    }

    /**
     * Validates a playbook definition before execution.
     * Returns { ok, errors[], warnings[] }.
     */
    public function validatePlaybook( array $playbook, int $depth = 0 ): array {
        return $this->validator->validate( $playbook, $depth );
    }

    /**
     * Validates a playbook by key. Returns { ok, errors[], warnings[] }
     * or an error result if the key is not found.
     */
    public function validateByKey( string $key ): array {
        $playbook = $this->get( $key );
        if ( $playbook === null ) {
            return [ 'ok' => false, 'errors' => [ "Playbook '{$key}' not found." ], 'warnings' => [] ];
        }
        return $this->validator->validate( $playbook );
    }
}
