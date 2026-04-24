<?php
declare(strict_types=1);

namespace Metis\Auth;

use Metis\Core\Services\LoggerService;
use Metis\Core\Security\AuthProtectionService;
use Metis\Core\Security\SecurityKernel;

final class AuthSessionManager {
    public function __construct(
        private readonly AuthProtectionService $protection,
        private readonly LoggerService $logger = new LoggerService(),
        private readonly ?SecurityKernel $security = null
    ) {}

    public function createSession(array $user, string $authMethod = 'session'): void {
        if ( \Metis\Core\Application::has_service( 'session_security' ) ) {
            \Metis\Core\Application::service( 'session_security' )->regenerateId();
        }

        $_SESSION['metis_auth_user_id'] = (int) ($user['id'] ?? 0);
        $_SESSION['metis_person_id'] = (int) ($user['person_id'] ?? 0);
        $_SESSION['metis_auth_issued_at'] = time();
        $_SESSION['metis_session_token'] = bin2hex(random_bytes(16));
        $_SESSION['metis_user'] = \metis_auth_user_row_to_session($user);
        $_SESSION['metis_auth_method'] = \metis_key_clean($authMethod);
        if ($this->security instanceof SecurityKernel) {
            $context = $this->security->buildContext(
                'auth.session.create',
                [ 'auth_method' => $authMethod ],
                [ 'auth_method' => $authMethod ],
                [
                    'id' => (int) ($user['id'] ?? 0),
                    'person_id' => (int) ($user['person_id'] ?? 0),
                    'session_id' => (string) ($_SESSION['metis_session_token'] ?? ''),
                ]
            );
            $_SESSION['metis_session_integrity'] = $this->security->fingerprints()->sessionIntegrityFingerprint($context);
        } else {
            \metis_auth_refresh_session_integrity();
        }
        unset($_SESSION['metis_pending_auth'], $_SESSION['metis_auth_password_verified_at']);

        \metis_auth_db()->update(
            \metis_auth_table(),
            [ 'last_login_at' => \metis_current_time('mysql') ],
            [ 'id' => (int) ($user['id'] ?? 0) ],
            [ '%s' ],
            [ '%d' ]
        );

        $this->logger->activity('auth_session_created', [
            'auth_user_id' => (int) ($user['id'] ?? 0),
            'person_id' => (int) ($user['person_id'] ?? 0),
            'login' => (string) ($user['user_login'] ?? ''),
            'request_id' => \metis_audit_request_id(),
        ]);
        if ($this->security instanceof SecurityKernel) {
            $this->security->audit()->activity(
                'login_success',
                $this->security->buildContext(
                    'auth.session.create',
                    [ 'auth_method' => $authMethod ],
                    [ 'auth_method' => $authMethod ],
                    [
                        'id' => (int) ($user['id'] ?? 0),
                        'person_id' => (int) ($user['person_id'] ?? 0),
                        'session_id' => (string) ($_SESSION['metis_session_token'] ?? ''),
                    ]
                ),
                [ 'login' => (string) ($user['user_login'] ?? '') ]
            );
        }

        \metis_do_action('metis_login', (string) ($user['user_login'] ?? ''), \metis_runtime_current_user());
    }
}
