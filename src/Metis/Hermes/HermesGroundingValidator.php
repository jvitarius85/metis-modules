<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesGroundingValidator {
    public function validate( string $answer, array $sources ): array {
        $grounded = [];

        foreach ( $sources as $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }

            $label = (string) ( $source['title'] ?? $source['key'] ?? $source['id'] ?? '' );
            if ( $label === '' ) {
                continue;
            }

            $grounded[] = [
                'label' => $label,
                'type'  => (string) ( $source['type'] ?? $source['source'] ?? 'reference' ),
            ];
        }

        return [
            'grounded' => $grounded,
            'grounding_ok' => $answer !== '' && $grounded !== [],
        ];
    }
}
