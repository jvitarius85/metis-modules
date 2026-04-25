<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class RecoveryManager {
    public function __construct(
        private readonly RecoveryRegistry $registry,
        private readonly ErrorLogger $logger
    ) {}

    public function attempt( ErrorContext $context, array $payload = [] ): array {
        foreach ( $this->registry->all() as $strategy ) {
            if ( ! $strategy->supports( $context, $payload ) ) {
                continue;
            }

            $result = $strategy->recover( $context, $payload );
            $context->merge( [
                'recovery_attempted' => true,
                'recovery_result' => (string) ( $result['state'] ?? 'attempted' ),
                'degraded' => (bool) ( $result['degraded'] ?? false ),
            ] );
            $this->logger->log( $context );
            return $result;
        }

        return [ 'recovered' => false, 'state' => 'unsupported' ];
    }

    public function scheduleRepair( string $jobType, array $payload = [], array $options = [] ): array {
        if ( ! function_exists( 'metis_job_queue' ) ) {
            return [ 'queued' => false, 'reason' => 'queue_unavailable' ];
        }

        try {
            return \metis_job_queue()->enqueue( $jobType, $payload, $options );
        } catch ( \Throwable $throwable ) {
            return [ 'queued' => false, 'reason' => 'queue_enqueue_failed' ];
        }
    }
}
