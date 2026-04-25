<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class DatabaseRecoveryStrategy implements RecoveryStrategyInterface {
    public function supports( ErrorContext $context, array $payload = [] ): bool {
        return $context->classification() === ErrorClassifier::DATABASE_ERROR
            && ( is_callable( $payload['reconnect'] ?? null ) || is_callable( $payload['retry'] ?? null ) );
    }

    public function recover( ErrorContext $context, array $payload = [] ): array {
        $reconnect = $payload['reconnect'] ?? null;
        $retry = $payload['retry'] ?? null;

        if ( is_callable( $reconnect ) ) {
            $reconnect();
        }

        if ( is_callable( $retry ) ) {
            try {
                return [ 'recovered' => true, 'state' => 'retried', 'value' => $retry(), 'degraded' => false ];
            } catch ( \Throwable ) {
            }
        }

        return [ 'recovered' => false, 'state' => 'retry_failed' ];
    }
}
