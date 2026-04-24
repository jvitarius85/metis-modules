<?php
declare(strict_types=1);

namespace Metis\Core\Error;

use Metis\Core\Cache\CacheService;

final class CacheRecoveryStrategy implements RecoveryStrategyInterface {
    public function supports( ErrorContext $context, array $payload = [] ): bool {
        return $context->classification() === ErrorClassifier::CACHE_ERROR;
    }

    public function recover( ErrorContext $context, array $payload = [] ): array {
        $group = (string) ( $payload['group'] ?? '' );
        $key = (string) ( $payload['key'] ?? '' );
        $retry = $payload['retry'] ?? null;
        $fallback = $payload['fallback'] ?? null;

        try {
            if ( $key !== '' ) {
                CacheService::forget( $key );
            } elseif ( $group !== '' ) {
                CacheService::clearGroup( $group );
            }
        } catch ( \Throwable ) {
        }

        if ( is_callable( $retry ) ) {
            try {
                return [ 'recovered' => true, 'state' => 'retried', 'value' => $retry(), 'degraded' => false ];
            } catch ( \Throwable ) {
            }
        }

        if ( is_callable( $fallback ) || $fallback !== null ) {
            return [ 'recovered' => true, 'state' => 'no_cache', 'value' => is_callable( $fallback ) ? $fallback( $context ) : $fallback, 'degraded' => true ];
        }

        return [ 'recovered' => true, 'state' => 'no_cache', 'value' => null, 'degraded' => true ];
    }
}
