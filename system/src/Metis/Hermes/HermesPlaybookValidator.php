<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

/**
 * HermesPlaybookValidator
 *
 * Validates a playbook definition before execution:
 *   - every step action key must exist in HermesUniversalActionRegistry
 *   - every step action must be flagged safe_for_playbook
 *   - safety governor recursion and step-count limits are enforced
 *   - required context packs are verified to exist in the library
 *
 * Returns a structured result: { ok, errors[], warnings[] }
 */
final class HermesPlaybookValidator {

    private HermesUniversalActionRegistry $actions;
    private HermesSafetyGovernor          $governor;
    private HermesDefinitionLibrary       $library;

    public function __construct(
        HermesUniversalActionRegistry $actions,
        HermesSafetyGovernor          $governor,
        HermesDefinitionLibrary       $library
    ) {
        $this->actions  = $actions;
        $this->governor = $governor;
        $this->library  = $library;
    }

    /**
     * @return array{ ok: bool, errors: string[], warnings: string[] }
     */
    public function validate( array $playbook, int $depth = 0 ): array {
        $errors   = [];
        $warnings = [];

        // Governor: recursion depth + step count
        $govResult = $this->governor->validatePlaybook( $playbook, $depth );
        if ( ! $govResult['ok'] ) {
            $errors[] = (string) ( $govResult['detail'] ?? $govResult['message'] ?? 'Safety policy violation.' );
            return [ 'ok' => false, 'errors' => $errors, 'warnings' => $warnings ];
        }

        // Required fields
        if ( (string) ( $playbook['key'] ?? '' ) === '' ) {
            $errors[] = 'Playbook is missing a key.';
        }

        // Validate each step
        foreach ( (array) ( $playbook['steps'] ?? [] ) as $index => $step ) {
            if ( ! is_array( $step ) ) {
                $errors[] = "Step {$index} is not a valid object.";
                continue;
            }

            $actionKey = strtolower( trim( (string) ( $step['action'] ?? $step['key'] ?? '' ) ) );

            // Steps without an explicit action key are description-only (context steps)
            // — allowed in playbooks as narrative phases, not executable actions.
            if ( $actionKey === '' ) {
                continue;
            }

            // Must exist in universal action registry
            if ( ! $this->actions->has( $actionKey ) ) {
                $errors[] = "Step {$index} references unregistered action '{$actionKey}'.";
                continue;
            }

            // Must be playbook-safe
            $definition = $this->actions->get( $actionKey );
            if ( ! (bool) ( $definition['safe_for_playbook'] ?? false ) ) {
                $errors[] = "Step {$index} action '{$actionKey}' is not permitted inside a playbook.";
            }
        }

        // Verify required context packs exist in the library
        $availablePacks = array_keys( $this->library->contextPacks() );
        foreach ( (array) ( $playbook['required_context_packs'] ?? [] ) as $packKey ) {
            if ( ! in_array( (string) $packKey, $availablePacks, true ) ) {
                $warnings[] = "Required context pack '{$packKey}' is not registered in the library.";
            }
        }

        return [
            'ok'       => $errors === [],
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }
}
