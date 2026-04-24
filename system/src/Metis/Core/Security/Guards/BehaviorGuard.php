<?php
declare(strict_types=1);

namespace Metis\Core\Security\Guards;

use Metis\Core\Security\Pipeline\SecurityGuardInterface;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecurityKernel;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class BehaviorGuard implements SecurityGuardInterface {
    public function __construct(
        private readonly SecurityKernel $kernel
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        if (! ($context->policy()?->behaviorProfiling ?? true)) {
            return EnclaveDecision::allow();
        }

        $profile = $this->kernel->behavior()->profile($context);
        if ((int) ($profile['score'] ?? 0) > 0) {
            $score = $this->kernel->threatScores()->recordEvent('behavior_anomaly', $context, (int) ($profile['score'] ?? 0));
            $this->kernel->audit()->security('security_behavior_anomaly', $context, [
                'anomalies' => (array) ($profile['anomalies'] ?? []),
                'score' => $score,
            ], 'warning', 'observed');
        }

        return EnclaveDecision::allow($profile);
    }
}
