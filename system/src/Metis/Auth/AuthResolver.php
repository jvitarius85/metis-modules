<?php
declare(strict_types=1);

namespace Metis\Auth;

use Metis\Core\Services\LoggerService;
use Metis\Services\DatabaseService;

final class AuthResolver {
    public function __construct(
        private readonly SsoService $sso,
        private readonly ?DatabaseService $db = null,
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function resolve(string $identifier): array {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \InvalidArgumentException('Email or username is required.');
        }

        $user = $this->findUser($identifier);
        $person = $this->findPerson($identifier, $user);

        if (!is_array($user) && is_array($person)) {
            $user = \metis_auth_find_user('person_id', (int) ($person['id'] ?? 0));
        }

        if (!is_array($user) && !is_array($person)) {
            return [
                'identifier' => $identifier,
                'user' => null,
                'person' => null,
                'methods' => [ 'password' ],
                'preferred_method' => 'password',
            ];
        }

        $methods = [];
        if ($this->hasPasskey($person)) {
            $methods[] = 'passkey';
        }
        if ($this->sso->isEligibleAccount($person, $user)) {
            $methods[] = 'google_workspace';
        }
        if ($this->hasPassword($user)) {
            $methods[] = 'password';
        }

        if ($methods === []) {
            $methods[] = 'password';
        }

        $preferred = $methods[0];
        try {
            $this->logger->activity('auth_resolved', [
                'identifier' => $identifier,
                'person_id' => (int) ($person['id'] ?? 0),
                'auth_user_id' => (int) ($user['id'] ?? 0),
                'preferred_method' => $preferred,
                'methods' => $methods,
            ]);
        } catch (\Throwable) {
            // Resolution must not fail because audit logging is temporarily unavailable.
        }

        return [
            'identifier' => $identifier,
            'user' => $user,
            'person' => $person,
            'methods' => $methods,
            'preferred_method' => $preferred,
        ];
    }

    private function findUser(string $identifier): ?array {
        return \metis_auth_find_user_by_identifier($identifier);
    }

    private function findPerson(string $identifier, ?array $user): ?array {
        $person = \metis_auth_find_person_by_identifier($identifier);
        if (is_array($person)) {
            return $person;
        }

        $personId = (int) ($user['person_id'] ?? 0);
        return $personId > 0 ? \metis_auth_get_person($personId) : null;
    }

    private function hasPassword(?array $user): bool {
        return is_array($user)
            && !empty($user['is_active'])
            && \metis_auth_password_hash_for_authentication($user) !== '';
    }

    private function hasPasskey(?array $person): bool {
        $personId = (int) ($person['id'] ?? 0);
        if ($personId < 1 || !\class_exists('Metis_Tables') || !\Metis_Tables::has('people_passkeys')) {
            return false;
        }

        $table = \Metis_Tables::get('people_passkeys');
        if (!$this->tableExists($table)) {
            return false;
        }

        try {
            $count = (int) $this->database()->scalar(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE person_id = %d AND revoked_at IS NULL',
                [ $personId ]
            );
        } catch (\Throwable $e) {
            $this->logger->warn('auth_passkey_resolution_unavailable', [
                'person_id' => $personId,
                'table' => $table,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }

        return $count > 0;
    }

    private function tableExists(string $table): bool {
        try {
            $found = $this->database()->scalar('SHOW TABLES LIKE %s', [ $table ]);
        } catch (\Throwable $e) {
            $this->logger->warn('auth_table_check_failed', [
                'table' => $table,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }

        return is_string($found) && $found === $table;
    }

    private function database(): DatabaseService {
        return $this->db ?? \metis_auth_db();
    }
}
