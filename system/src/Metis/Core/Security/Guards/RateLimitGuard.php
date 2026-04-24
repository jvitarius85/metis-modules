<?php
declare(strict_types=1);

namespace Metis\Core\Security\Guards;

use Metis\Core\Security\Pipeline\SecurityGuardInterface;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecurityKernel;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class RateLimitGuard implements SecurityGuardInterface {
    public function __construct(
        private readonly SecurityKernel $kernel
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        $policy = $context->policy();
        $limit = (int) ($policy?->rateLimit ?? 0);
        if ($limit < 1) {
            return EnclaveDecision::allow();
        }

        $bucket = implode('|', [
            $context->operation(),
            $context->ipAddress(),
            $context->userId(),
            (string) $context->attribute('request_fingerprint', ''),
        ]);

        if ($this->kernel->rateLimiter()->consume($bucket, $limit, (int) ($policy?->rateWindowSeconds ?? 60))) {
            return EnclaveDecision::allow();
        }

        $score = $this->kernel->threatScores()->recordEvent('rate_limit_violation', $context);
        $this->kernel->audit()->security('security_rate_limit_triggered', $context, [ 'score' => $score ]);

        return EnclaveDecision::deny('Too many requests.', 'rate_limited', 429);
    }
}
