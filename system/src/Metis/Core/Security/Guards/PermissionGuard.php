<?php
declare(strict_types=1);

namespace Metis\Core\Security\Guards;

use Metis\Core\Security\Pipeline\SecurityGuardInterface;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecurityKernel;
use Metis\Core\Security\SecureEnclave\AuthorizationGate;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class PermissionGuard implements SecurityGuardInterface {
    public function __construct(
        private readonly AuthorizationGate $gate,
        private readonly SecurityKernel $kernel
    ) {}

    public function evaluate(SecurityContext $context): EnclaveDecision {
        if ($this->gate->hasPermission($context)) {
            return EnclaveDecision::allow();
        }

        $score = $this->kernel->threatScores()->recordEvent('permission_violation', $context);
        $this->kernel->audit()->security('security_permission_violation', $context, [ 'score' => $score ]);

        return EnclaveDecision::deny('You do not have permission to perform this action.', 'permission_denied', 403);
    }
}
