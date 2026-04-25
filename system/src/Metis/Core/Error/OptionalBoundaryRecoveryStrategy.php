<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class OptionalBoundaryRecoveryStrategy implements RecoveryStrategyInterface {
    public function __construct(
        private readonly FallbackResolver $fallbacks
    ) {}

    public function supports( ErrorContext $context, array $payload = [] ): bool {
        return (bool) ( $payload['optional'] ?? false )
            && ! $context->isSecuritySensitive();
    }

    public function recover( ErrorContext $context, array $payload = [] ): array {
        return [
            'recovered' => true,
            'state' => 'degraded',
            'value' => $this->fallbacks->resolve( $payload['fallback'] ?? null, $context, $payload['default'] ?? null ),
            'degraded' => true,
        ];
    }
}
