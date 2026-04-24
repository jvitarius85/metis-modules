<?php
declare(strict_types=1);

namespace Metis\Core\Security\Pipeline;

use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class SecurityPipeline {
    /**
     * @param SecurityGuardInterface[] $guards
     */
    public function __construct(
        private readonly array $guards = []
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        $decision = EnclaveDecision::allow();

        foreach ($this->guards as $guard) {
            $decision = $guard->evaluate($context);
            if (! $decision->allowed()) {
                return $decision;
            }
        }

        return $decision;
    }
}
