<?php
declare(strict_types=1);

namespace Metis\Core\Security\SecureEnclave;

use Metis\Core\Security\RequestFingerprint;
use Metis\Core\Security\SecurityContext;
use Metis\Services\PermissionsService;

final class AuthorizationGate {
    public function __construct(
        private readonly PermissionsService $permissions,
        private readonly RequestFingerprint $fingerprints
    ) {}

    public function requiresAuthentication(SecurityContext $context): bool {
        return (bool) ($context->policy()?->requireAuthentication ?? false);
    }

    public function requiresSession(SecurityContext $context): bool {
        return (bool) ($context->policy()?->requireSession ?? false);
    }

    public function isAuthenticated(SecurityContext $context): bool {
        return $context->userId() > 0 || (function_exists('metis_user_logged_in') && metis_user_logged_in());
    }

    public function hasValidSession(SecurityContext $context): bool {
        $sessionId = $context->sessionId();
        if ($sessionId === '') {
            return false;
        }

        $stored = (string) ($_SESSION['metis_session_integrity'] ?? '');
        if ($stored === '') {
            $_SESSION['metis_session_integrity'] = $this->fingerprints->sessionIntegrityFingerprint($context);
            return true;
        }

        return $this->fingerprints->matchesStored($context, $stored);
    }

    public function hasPermission(SecurityContext $context): bool {
        $policy = $context->policy();
        if (! $policy instanceof SecurityPolicy || $policy->module === null || $policy->module === '') {
            return true;
        }

        return $this->permissions->can($policy->module, $policy->permission, $context->actor());
    }
}
