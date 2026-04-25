<?php
declare(strict_types=1);

namespace Metis\Core\Security\Guards;

use Metis\Core\Security\Pipeline\SecurityGuardInterface;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecurityKernel;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class ThreatScoreGuard implements SecurityGuardInterface {
    public function __construct(
        private readonly SecurityKernel $kernel
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        if (! ($context->policy()?->adaptiveThreatScoring ?? true)) {
            return EnclaveDecision::allow();
        }

        $score = $this->kernel->threatScores()->currentScore($context);
        $level = $this->kernel->threatScores()->responseLevel($score);

        return match ($level) {
            'block' => EnclaveDecision::deny('Request blocked.', 'threat_blocked', 403, [ 'score' => $score ]),
            'lockout' => EnclaveDecision::deny('Too many suspicious requests. Please try again later.', 'threat_lockout', 429, [ 'score' => $score ]),
            'throttle' => $this->throttle($score),
            default => EnclaveDecision::allow([ 'threat_score' => $score, 'threat_level' => $level ]),
        };
    }

    private function throttle(int $score): EnclaveDecision {
        usleep(750000);

        return EnclaveDecision::allow([ 'threat_score' => $score, 'threat_level' => 'throttle' ]);
    }
}
