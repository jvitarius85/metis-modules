<?php
declare(strict_types=1);

namespace Metis\Core\Security\Guards;

use Metis\Core\Cache\CacheService;
use Metis\Core\Security\Pipeline\SecurityGuardInterface;
use Metis\Core\Security\SecurityContext;
use Metis\Core\Security\SecureEnclave\EnclaveDecision;

final class ProgressiveDelayGuard implements SecurityGuardInterface {
    public function evaluate(SecurityContext $context): EnclaveDecision {
        $policy = $context->policy();
        if (! ($policy?->progressiveDelay ?? false)) {
            return EnclaveDecision::allow();
        }

        $failures = max(
            $this->failureCount('ip', $context->ipAddress()),
            $this->failureCount('subject', $this->subject($context))
        );

        $delay = $failures < 2 ? 0 : min((int) (2 ** ($failures - 2)), 15);
        if ($delay > 0) {
            usleep($delay * 1000000);
        }

        return EnclaveDecision::allow([ 'progressive_delay_seconds' => $delay ]);
    }

    private function failureCount(string $scope, string $subject): int {
        if ($subject === '') {
            return 0;
        }

        return (int) CacheService::get($this->key($scope, $subject));
    }

    private function subject(SecurityContext $context): string {
        if ($context->userId() > 0) {
            return 'user_' . $context->userId();
        }

        $identifier = trim((string) $context->identifier());
        if ($identifier === '') {
            return '';
        }

        $email = trim(strtolower((string) metis_email_clean($identifier)));
        if ($email !== '' && metis_email_is_valid($email)) {
            return 'email:' . $email;
        }

        return 'login:' . metis_key_clean($identifier);
    }

    private function key(string $scope, string $subject): string {
        return 'security.auth.failures.' . metis_key_clean($scope) . '.' . sha1($subject);
    }
}
