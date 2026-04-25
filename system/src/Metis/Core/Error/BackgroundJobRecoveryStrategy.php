<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class BackgroundJobRecoveryStrategy implements RecoveryStrategyInterface {
    public function supports( ErrorContext $context, array $payload = [] ): bool {
        return (string) ( $context->get( 'boundary', '' ) ) === 'background_job';
    }

    public function recover( ErrorContext $context, array $payload = [] ): array {
        return [
            'recovered' => true,
            'state' => 'queue_policy',
            'degraded' => true,
        ];
    }
}
