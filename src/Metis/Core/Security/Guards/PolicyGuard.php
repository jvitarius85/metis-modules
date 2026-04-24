<?php
declare(strict_types=1);

namespace Metis\Core\Security\Guards;

use Metis\Core\Security\Pipeline\SecurityGuardInterface;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecureEnclave\AuthorizationGate;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class PolicyGuard implements SecurityGuardInterface {
    public function __construct(
        private readonly AuthorizationGate $gate
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        if ($this->gate->requiresAuthentication($context) && ! $this->gate->isAuthenticated($context)) {
            return EnclaveDecision::deny('Authentication is required.', 'authentication_required', 401);
        }

        if ($this->gate->requiresSession($context) && ! $this->gate->hasValidSession($context)) {
            return EnclaveDecision::deny('Invalid session integrity.', 'invalid_session_integrity', 401);
        }

        return EnclaveDecision::allow();
    }
}
