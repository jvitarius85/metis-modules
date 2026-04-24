<?php
declare(strict_types=1);

namespace Metis\Auth;

use Metis\Core\Services\LoggerService;
use Metis\Core\Security\AuthProtectionService;
use Metis\Core\Security\SecurityKernel;
use Metis\Core\Security\SecureEnclave\SecureEnclave;
use Metis\Core\Security\SecureEnclave\SecurityPolicy;

final class AuthService {
    public function __construct(
        private readonly AuthResolver $resolver,
        private readonly MfaService $mfa,
        private readonly PasskeyService $passkeys,
        private readonly SsoService $sso,
        private readonly AuthSessionManager $sessions,
        private readonly AuthProtectionService $protection,
        private readonly LoggerService $logger = new LoggerService(),
        private readonly ?SecureEnclave $enclave = null,
        private readonly ?SecurityKernel $security = null
    ) {}

    public function resolve(string $identifier, string $redirect = ''): array {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \InvalidArgumentException('Username or email is required.');
        }

        return [
            'method' => 'passkey',
            'identifier' => $identifier,
            'password_fallback' => true,
            'passkey' => $this->passkeys->beginAuthentication($identifier),
        ];
    }

    public function beginGoogleWorkspaceLogin(string $redirect = ''): string {
        return $this->sso->beginGenericLogin(\metis_auth_normalize_redirect($redirect, \metis_portal_url()));
    }

    public function authenticatePassword(string $identifier, string $password, string $redirect = ''): array {
        $redirect = \metis_auth_normalize_redirect($redirect, \metis_portal_url());
        $context = $this->passwordContext($identifier, $redirect);
        if ($this->enclave instanceof SecureEnclave) {
            $decision = $this->enclave->evaluate($context);
            if (! $decision->allowed()) {
                throw new \RuntimeException($decision->message());
            }
        } else {
            $this->protection->assertRequestRateLimit('password_login');
            $this->protection->assertLoginAllowed($identifier);
        }

        $result = \metis_auth_authenticate_primary($identifier, $password);
        if (!is_array($result) || !is_array($result['user'] ?? null)) {
            $this->protection->recordFailedLogin($identifier);
            if ($this->security instanceof SecurityKernel) {
                $this->security->audit()->security('login_failure', $context, []);
            }
            \metis_do_action('metis_login_failed', $identifier);
            throw new \RuntimeException($this->protection->genericFailureMessage());
        }

        $user = (array) $result['user'];
        $person = is_array($result['person'] ?? null) ? (array) $result['person'] : null;
        $this->protection->clearFailedLogins($identifier, $user);

        if ($this->mfa->requiresPasswordMfa($person)) {
            $this->mfa->beginPasswordChallenge($user, $person, $redirect);
            return [
                'method' => 'password_mfa',
                'redirect_url' => \metis_auth_mfa_url($redirect),
            ];
        }

        $this->sessions->createSession($user, 'password');
        if (\metis_auth_should_nudge_passkey($person)) {
            \metis_auth_set_flash_notice('For improved security, consider adding a passkey to your account.', 'info');
        }
        return [
            'method' => 'password',
            'redirect_url' => $redirect,
        ];
    }

    public function verifyPasswordMfa(string $code): array {
        $result = $this->mfa->verifyPendingTotp($code);
        $this->sessions->createSession((array) $result['user'], 'password_mfa');

        return [
            'redirect_url' => (string) ($result['redirect_to'] ?? \metis_portal_url()),
        ];
    }

    public function completePasskeyLogin(array $payload, string $redirect = ''): array {
        $redirect = \metis_auth_normalize_redirect($redirect, \metis_portal_url());
        $result = $this->passkeys->verifyAuthentication($payload);
        $personId = (int) ($result['person_id'] ?? $result['user_id'] ?? 0);
        $user = $personId > 0 ? \metis_auth_find_user('person_id', $personId) : null;

        if (!is_array($user) && $personId > 0) {
            $person = \metis_auth_get_person($personId);
            if (is_array($person)) {
                $user = \metis_auth_upsert_user_from_person($person);
            }
        }

        if (!is_array($user)) {
            throw new \RuntimeException('No Metis login is linked to this passkey.');
        }

        $this->sessions->createSession($user, 'passkey');
        $this->logger->activity('auth_passkey_completed', [
            'person_id' => $personId,
            'auth_user_id' => (int) ($user['id'] ?? 0),
        ]);

        return [
            'person_id' => $personId,
            'redirect_url' => $redirect,
        ];
    }

    public function finishGoogleWorkspaceLogin(string $code, string $state, string $redirect = ''): array {
        $redirect = \metis_auth_normalize_redirect($redirect, \metis_portal_url());
        $result = $this->sso->completeLogin($code, $state, $redirect);
        $user = (array) ($result['user'] ?? []);
        if ($user === []) {
            throw new \RuntimeException('No Metis account is linked to this Google user.');
        }

        $this->sessions->createSession($user, 'google_workspace');

        return [
            'redirect_url' => \metis_auth_normalize_redirect((string) ($result['redirect_to'] ?? $redirect), \metis_portal_url()),
            'user' => $user,
            'person' => $result['person'] ?? null,
        ];
    }

    private function passwordContext(string $identifier, string $redirect): \Metis\Core\Security\SecurityContext {
        if ($this->security instanceof SecurityKernel) {
            return $this->security->buildContext(
                'auth.password_login',
                [
                    'identifier' => $identifier,
                    'redirect_to' => $redirect,
                    'auth_method' => 'password',
                ],
                [
                    'identifier' => $identifier,
                    'auth_method' => 'password',
                ]
            )->withPolicy(
                new SecurityPolicy(
                    'auth.password_login',
                    null,
                    'view',
                    false,
                    false,
                    false,
                    null,
                    max(1, (int) $this->security->config('auth_ip_rate_limit_per_minute', 20)),
                    60,
                    true,
                    true,
                    true
                )
            );
        }

        return new \Metis\Core\Security\SecurityContext('auth.password_login', [], [], [ 'identifier' => $identifier ]);
    }
}
