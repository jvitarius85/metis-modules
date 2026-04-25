<?php
declare(strict_types=1);

namespace Metis\Core\Security\SecureEnclave;

final class SecurityPolicy {
    public function __construct(
        public readonly string $operation,
        public readonly ?string $module = null,
        public readonly string $permission = 'view',
        public readonly bool $requireAuthentication = false,
        public readonly bool $requireSession = false,
        public readonly bool $requireNonce = false,
        public readonly ?string $nonceKey = null,
        public readonly int $rateLimit = 0,
        public readonly int $rateWindowSeconds = 60,
        public readonly bool $progressiveDelay = false,
        public readonly bool $behaviorProfiling = true,
        public readonly bool $adaptiveThreatScoring = true
    ) {}
}
