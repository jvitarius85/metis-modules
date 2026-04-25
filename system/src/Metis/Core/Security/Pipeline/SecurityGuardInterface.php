<?php
declare(strict_types=1);

namespace Metis\Core\Security\Pipeline;

use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

interface SecurityGuardInterface {
    public function evaluate(SecurityContext $context): EnclaveDecision;
}
