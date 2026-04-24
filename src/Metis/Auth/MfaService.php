<?php
declare(strict_types=1);

namespace Metis\Auth;

use Metis\Core\Services\LoggerService;

final class MfaService {
    public function __construct(
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function requiresPasswordMfa(?array $person): bool {
        if (!is_array($person)) {
            return false;
        }

        if (empty($person['requires_2fa'])) {
            return false;
        }

        $method = (string) ($person['mfa_method'] ?? '');
        return $method !== 'none';
    }

    public function canChallengePasswordLogin(?array $person): bool {
        return is_array($person)
            && !empty($person['requires_2fa'])
            && !empty($person['totp_enabled'])
            && (string) ($person['totp_secret_enc'] ?? '') !== '';
    }

    public function beginPasswordChallenge(array $user, ?array $person, string $redirect = ''): void {
        if (!$this->canChallengePasswordLogin($person)) {
            throw new \RuntimeException('Password sign-in requires MFA enrollment. Use a passkey, Google sign-in, or contact an administrator.');
        }

        $_SESSION['metis_pending_auth'] = [
            'auth_user_id' => (int) ($user['id'] ?? 0),
            'person_id' => (int) ($user['person_id'] ?? 0),
            'started_at' => time(),
            'redirect_to' => $redirect,
            'method' => 'password',
        ];

        $this->logger->activity('auth_password_mfa_required', [
            'auth_user_id' => (int) ($user['id'] ?? 0),
            'person_id' => (int) ($user['person_id'] ?? 0),
        ]);
    }

    public function pendingLoginRow(): ?array {
        $pending = $_SESSION['metis_pending_auth'] ?? null;
        if (!is_array($pending)) {
            return null;
        }

        if (time() - (int) ($pending['started_at'] ?? 0) > 600) {
            unset($_SESSION['metis_pending_auth']);
            return null;
        }

        $row = \metis_auth_find_user('id', (int) ($pending['auth_user_id'] ?? 0));
        return is_array($row) ? $row : null;
    }

    public function pendingPerson(): ?array {
        $row = $this->pendingLoginRow();
        if (!is_array($row)) {
            return null;
        }

        $personId = (int) ($row['person_id'] ?? 0);
        return $personId > 0 ? \metis_auth_get_person($personId) : null;
    }

    public function pendingRedirect(): string {
        $pending = $_SESSION['metis_pending_auth'] ?? null;
        return is_array($pending) ? (string) ($pending['redirect_to'] ?? '') : '';
    }

    public function verifyPendingTotp(string $code): array {
        $user = $this->pendingLoginRow();
        $person = $this->pendingPerson();

        if (!is_array($user) || !is_array($person)) {
            throw new \RuntimeException('Your login session expired. Please sign in again.');
        }

        if (!\metis_auth_verify_totp_code($person, $code)) {
            throw new \RuntimeException('Authenticator code was not valid.');
        }

        return [
            'user' => $user,
            'person' => $person,
            'redirect_to' => $this->pendingRedirect(),
        ];
    }
}
