<?php
declare(strict_types=1);

namespace Metis\Core\Security\SecureEnclave;

use Metis\Core\Security\Pipeline\SecurityPipeline;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecurityKernel;

final class SecureEnclave {
    public function __construct(
        private readonly SecurityKernel $kernel,
        private readonly SecurityPipeline $pipeline,
        private readonly AuthorizationGate $gate
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        $decision = $this->pipeline->evaluate($context);
        if (! $decision->allowed()) {
            $this->kernel->audit()->security('security_enclave_denied', $context, [
                'reason' => $decision->code(),
                'message' => $decision->message(),
            ]);

            return $decision;
        }

        $this->kernel->audit()->activity('security_enclave_allowed', $context);

        return $decision;
    }

    public function protect(SecurityContext $context, callable $callback): mixed {
        $decision = $this->evaluate($context);
        if (! $decision->allowed()) {
            throw new \RuntimeException($decision->message());
        }

        return $callback($context, $decision);
    }

    public function gate(): AuthorizationGate {
        return $this->gate;
    }
}
