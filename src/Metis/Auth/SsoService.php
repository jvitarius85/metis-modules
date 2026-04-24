<?php
declare(strict_types=1);

namespace Metis\Auth;

use Metis\Core\Services\LoggerService;

final class SsoService {
    public function __construct(
        private readonly GoogleWorkspaceProvider $googleWorkspace,
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function isEligibleAccount(?array $person, ?array $user): bool {
        if (!$this->googleWorkspace->isConfigured()) {
            return false;
        }

        $email = $this->accountEmail($person, $user);
        if ($email === '') {
            return false;
        }

        $hostedDomain = $this->googleWorkspace->hostedDomain();
        if ($hostedDomain === '') {
            return true;
        }

        return str_ends_with(strtolower($email), '@' . strtolower($hostedDomain));
    }

    public function beginLogin(array $resolution, string $redirect = ''): string {
        if (!$this->isEligibleAccount($resolution['person'] ?? null, $resolution['user'] ?? null)) {
            throw new \RuntimeException('Google sign-in is not available for this account.');
        }

        return $this->beginGenericLogin($redirect, (int) (($resolution['person']['id'] ?? 0)));
    }

    public function beginGenericLogin(string $redirect = '', int $personId = 0): string {
        if (!$this->googleWorkspace->isConfigured()) {
            throw new \RuntimeException('Google sign-in is not available.');
        }
        $redirect = \metis_auth_normalize_redirect($redirect, \metis_portal_url());

        $state = bin2hex(random_bytes(16));
        $_SESSION['metis_auth_google_oauth'] = [
            'state' => $state,
            'redirect_to' => $redirect,
            'person_id' => $personId,
        ];

        $callback = \metis_auth_google_callback_url();
        $url = $this->googleWorkspace->authorizationUrl($callback, $state);

        $this->logger->activity('auth_google_workspace_started', [
            'person_id' => $personId,
            'redirect_uri' => $callback,
        ]);

        return $url;
    }

    public function completeLogin(string $code, string $state, string $redirect = ''): array {
        $pending = $_SESSION['metis_auth_google_oauth'] ?? null;
        if (!is_array($pending) || !hash_equals((string) ($pending['state'] ?? ''), $state)) {
            unset($_SESSION['metis_auth_google_oauth']);
            throw new \RuntimeException('Google sign-in state validation failed.');
        }

        $redirect = \metis_auth_normalize_redirect(
            $redirect !== '' ? $redirect : (string) ($pending['redirect_to'] ?? ''),
            \metis_portal_url()
        );
        $callback = \metis_auth_google_callback_url();
        $profile = $this->googleWorkspace->authenticate($code, $callback);
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        if ($email === '') {
            unset($_SESSION['metis_auth_google_oauth']);
            throw new \RuntimeException('Google sign-in did not return an email address.');
        }

        $expectedPersonId = (int) ($pending['person_id'] ?? 0);
        $person = \metis_auth_find_person_by_identifier($email);
        if ($expectedPersonId > 0) {
            $expectedPerson = \metis_auth_get_person($expectedPersonId);
            $expectedEmails = array_filter([
                strtolower(trim((string) ($expectedPerson['email'] ?? ''))),
                strtolower(trim((string) ($expectedPerson['workspace_email'] ?? ''))),
            ]);

            if ($expectedEmails !== [] && !in_array($email, $expectedEmails, true)) {
                unset($_SESSION['metis_auth_google_oauth']);
                throw new \RuntimeException('Google account did not match the requested Metis user.');
            }

            if (!is_array($person) && is_array($expectedPerson)) {
                $person = $expectedPerson;
            }
        }

        if (!is_array($person)) {
            unset($_SESSION['metis_auth_google_oauth']);
            throw new \RuntimeException('No Metis account is linked to this Google user.');
        }

        $user = \metis_auth_find_user('person_id', (int) ($person['id'] ?? 0));
        if (!is_array($user)) {
            $user = \metis_auth_upsert_user_from_person($person);
        }

        unset($_SESSION['metis_auth_google_oauth']);
        $this->logger->activity('auth_google_workspace_completed', [
            'person_id' => (int) ($person['id'] ?? 0),
            'email' => $email,
        ]);

        return [
            'user' => $user,
            'person' => $person,
            'profile' => $profile,
            'redirect_to' => \metis_auth_normalize_redirect($redirect, \metis_portal_url()),
        ];
    }

    private function accountEmail(?array $person, ?array $user): string {
        $email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
        if ($email !== '') {
            return $email;
        }

        $email = strtolower(trim((string) ($person['email'] ?? '')));
        if ($email !== '') {
            return $email;
        }

        return strtolower(trim((string) ($user['user_email'] ?? '')));
    }
}
