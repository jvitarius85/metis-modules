<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class FailureIsolation {
    public function __construct(
        private readonly ErrorKernel $kernel,
        private readonly RecoveryManager $recovery,
        private readonly ErrorLogger $logger
    ) {}

    public function isolate( string $boundary, callable $callback, array $options = [] ): mixed {
        try {
            return $callback();
        } catch ( \Throwable $throwable ) {
            $context = $this->kernel->contextForThrowable(
                $throwable,
                $options + [ 'boundary' => $boundary ]
            );

            if ( $context->isSecuritySensitive() || ! (bool) ( $options['optional'] ?? false ) ) {
                throw $throwable;
            }

            $result = $this->recovery->attempt( $context, $options );
            if ( (bool) ( $result['recovered'] ?? false ) ) {
                $context->set( 'degraded', true )->set( 'final_response_type', 'degraded' );
                $this->logger->log( $context );
                return $result['value'] ?? null;
            }

            $this->logger->log( $context );
            if ( array_key_exists( 'fallback', $options ) ) {
                return is_callable( $options['fallback'] ) ? $options['fallback']( $context ) : $options['fallback'];
            }

            return $options['default'] ?? null;
        }
    }
}
