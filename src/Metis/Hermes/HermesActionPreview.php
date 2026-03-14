<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesActionPreview {
    public function preview( string $action_type, array $payload, array $tool = [] ): array {
        $summary = (string) ( $tool['summary'] ?? 'Hermes action.' );
        $title = (string) ( $tool['title'] ?? ucwords( str_replace( '_', ' ', $action_type ) ) );

        if ( ! empty( $payload['action_plan'] ) && is_array( $payload['action_plan'] ) ) {
            $plan = (array) $payload['action_plan'];
            $summary = sprintf(
                '%s. Permission required: %s.',
                implode( ', ', array_map( static fn ( string $step ): string => str_replace( '_', ' ', $step ), (array) ( $plan['steps'] ?? [] ) ) ),
                (string) ( $plan['required_permission'] ?? 'approval' )
            );
            $title = (string) ( $plan['title'] ?? $title );
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'requires_approval' => true,
            'effects' => array_values( array_filter( [
                isset( $payload['operation'] ) ? 'Operation: ' . (string) $payload['operation'] : '',
                isset( $payload['mission_key'] ) ? 'Mission: ' . (string) $payload['mission_key'] : '',
                isset( $payload['topic_id'] ) ? 'Help topic: ' . (string) $payload['topic_id'] : '',
                isset( $payload['walkthrough_id'] ) ? 'Walkthrough: ' . (string) $payload['walkthrough_id'] : '',
                isset( $payload['query'] ) ? 'Scoped query: ' . substr( (string) $payload['query'], 0, 80 ) : '',
            ] ) ),
            'approval_copy' => 'Approval is required before Hermes can execute this action through the Secure Enclave.',
        ];
    }
}
