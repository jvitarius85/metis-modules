<?php
declare(strict_types=1);

namespace Metis\Core\Security\Guards;

use Metis\Core\Security\Pipeline\SecurityGuardInterface;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecurityKernel;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class NonceGuard implements SecurityGuardInterface {
    public function __construct(
        private readonly SecurityKernel $kernel
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        $policy = $context->policy();
        if (! ($policy?->requireNonce ?? false)) {
            return EnclaveDecision::allow();
        }

        $nonce = $this->kernel->nonce()->extract($context->input());
        $action = (string) ($policy?->nonceKey ?? '');
        if ($nonce !== '' && $action !== '' && $this->kernel->nonce()->verify($nonce, $action)) {
            return EnclaveDecision::allow();
        }

        $this->kernel->threatScores()->recordEvent('invalid_nonce', $context);

        return EnclaveDecision::deny('Invalid request nonce.', 'invalid_nonce', 403);
    }
}
