<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class IntegrationRecoveryStrategy implements RecoveryStrategyInterface {
    public function __construct(
        private readonly FallbackResolver $fallbacks
    ) {}

    public function supports( ErrorContext $context, array $payload = [] ): bool {
        return $context->classification() === ErrorClassifier::INTEGRATION_ERROR;
    }

    public function recover( ErrorContext $context, array $payload = [] ): array {
        if ( ! empty( $payload['repair_job_type'] ) && (bool) ( $payload['schedule_repair'] ?? true ) ) {
            if ( function_exists( 'metis_job_queue' ) ) {
                try {
                    \metis_job_queue()->enqueue(
                        (string) $payload['repair_job_type'],
                        (array) ( $payload['repair_payload'] ?? [] ),
                        (array) ( $payload['repair_options'] ?? [] )
                    );
                } catch ( \Throwable ) {
                }
            }
        }

        if ( array_key_exists( 'stale_data', $payload ) ) {
            return [ 'recovered' => true, 'state' => 'stale_data', 'value' => $payload['stale_data'], 'degraded' => true ];
        }

        if ( array_key_exists( 'db_data', $payload ) ) {
            return [ 'recovered' => true, 'state' => 'db_fallback', 'value' => $payload['db_data'], 'degraded' => true ];
        }

        if ( array_key_exists( 'fallback', $payload ) ) {
            return [ 'recovered' => true, 'state' => 'fallback', 'value' => $this->fallbacks->resolve( $payload['fallback'], $context ), 'degraded' => true ];
        }

        return [ 'recovered' => false, 'state' => 'no_fallback' ];
    }
}
