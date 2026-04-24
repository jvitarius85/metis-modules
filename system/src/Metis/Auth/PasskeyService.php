<?php
declare(strict_types=1);

namespace Metis\Auth;

use Metis\Core\Services\FileService;
use Metis\Core\Services\LoggerService;
use Metis\Services\DatabaseService;

final class PasskeyService {
    public function __construct(
        private readonly LoggerService $logger = new LoggerService(),
        private readonly FileService $files = new FileService(),
        private readonly ?DatabaseService $db = null
    ) {}

    public function ensureSchema(): void {
        if (!\class_exists('Metis_Tables') || !$this->dbAvailable()) {
            return;
        }

        $connection = $this->database()->connection();
        $charset = is_object($connection) && method_exists($connection, 'get_charset_collate')
            ? (string) $connection->get_charset_collate()
            : '';
        $passkeysTable = \Metis_Tables::get('people_passkeys');
        $challengesTable = \Metis_Tables::get('people_auth_challenges');

        if (\function_exists('metis_db_delta')) {
            \metis_db_delta("CREATE TABLE {$passkeysTable} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                person_id BIGINT UNSIGNED NOT NULL,
                credential_id VARCHAR(255) NOT NULL,
                credential_public_key LONGTEXT DEFAULT NULL,
                sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                transports_json LONGTEXT DEFAULT NULL,
                label VARCHAR(120) DEFAULT NULL,
                created_by_person_id BIGINT UNSIGNED DEFAULT NULL,
                last_used_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY credential_id (credential_id),
                KEY person_active (person_id, revoked_at)
            ) {$charset};");

            \metis_db_delta("CREATE TABLE {$challengesTable} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                person_id BIGINT UNSIGNED DEFAULT NULL,
                challenge_key VARCHAR(64) NOT NULL,
                challenge_value VARCHAR(191) NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY challenge_key (challenge_key),
                KEY purpose_expires (purpose, expires_at),
                KEY person_id (person_id)
            ) {$charset};");
        }
    }

    public function beginRegistration(int $personId, string $label = ''): array {
        $challenge = $this->createChallenge($personId, 'passkey_register', 600);
        $person = $this->findPerson($personId);
        if ($person === null) {
            throw new \RuntimeException('Person not found.');
        }

        $displayName = trim((string) ($person['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = (string) ($person['email'] ?? '');
        }

        return [
            'challenge_key' => $challenge['challenge_key'],
            'public_key' => [
                'rp' => [
                    'name' => 'Metis',
                    'id' => $this->rpId(),
                ],
                'user' => [
                    'id' => $this->b64urlEncode('metis-person-' . $personId),
                    'name' => (string) ($person['email'] ?? ''),
                    'displayName' => $displayName,
                ],
                'challenge' => $challenge['challenge_value'],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257],
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'excludeCredentials' => $this->excludeCredentialsForPerson($personId),
                'authenticatorSelection' => [
                    'residentKey' => 'preferred',
                    'userVerification' => 'preferred',
                ],
            ],
        ];
    }

    public function registerCredential(array $payload): array {
        $personId = (int) ($payload['person_id'] ?? 0);
        $challengeKey = (string) ($payload['challenge_key'] ?? '');
        $credentialId = (string) ($payload['credential_id'] ?? '');
        $clientDataB64 = (string) ($payload['client_data_json'] ?? '');
        $attestationObjectB64 = (string) ($payload['attestation_object'] ?? '');
        $transportsJson = (string) ($payload['transports_json'] ?? '');
        $label = trim((string) ($payload['label'] ?? 'Passkey'));

        if ($personId < 1 || $challengeKey === '' || $credentialId === '' || $clientDataB64 === '' || $attestationObjectB64 === '') {
            throw new \RuntimeException('Passkey registration payload is incomplete.');
        }

        $challenge = $this->consumeChallenge($challengeKey, 'passkey_register', $personId);
        if ($challenge === null) {
            throw new \RuntimeException('Registration challenge expired or invalid.');
        }

        $clientData = $this->decodeClientData($clientDataB64);
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new \RuntimeException('Unexpected WebAuthn registration response type.');
        }

        if (!$this->originAllowed((string) ($clientData['origin'] ?? ''))) {
            throw new \RuntimeException('Passkey origin mismatch.');
        }

        if (!hash_equals((string) ($challenge['challenge_value'] ?? ''), (string) ($clientData['challenge'] ?? ''))) {
            throw new \RuntimeException('Passkey registration challenge mismatch.');
        }

        $record = [
            'person_id' => $personId,
            'credential_id' => $credentialId,
            'credential_public_key' => $attestationObjectB64,
            'sign_count' => 0,
            'transports_json' => $transportsJson !== '' ? $transportsJson : null,
            'label' => $label !== '' ? $label : 'Passkey',
            'created_by_person_id' => (int) ($payload['created_by_person_id'] ?? $personId),
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => $this->now(),
        ];

        $this->persistCredential($record);
        $this->logger->activity('passkey_registered', ['person_id' => $personId, 'label' => $record['label']]);

        return [
            'status' => 'registered',
            'person_id' => $personId,
            'credential_id' => $credentialId,
            'label' => $record['label'],
            'created_at' => $record['created_at'],
        ];
    }

    public function beginAuthentication(string $identifier = '', string $challenge = ''): array {
        $personId = 0;
        $allowCredentials = [];

        if ($identifier !== '') {
            $personId = $this->resolvePersonId($identifier);
            if ($personId > 0) {
                $allowCredentials = $this->excludeCredentialsForPerson($personId);
            }
        }

        $challengeRow = $this->createChallenge($personId > 0 ? $personId : null, 'passkey_login', 300, $challenge);

        return [
            'status' => 'challenge_created',
            'challenge_key' => $challengeRow['challenge_key'],
            'public_key' => [
                'challenge' => $challengeRow['challenge_value'],
                'rpId' => $this->rpId(),
                'timeout' => 30000,
                'userVerification' => 'preferred',
                'allowCredentials' => $allowCredentials,
            ],
        ];
    }

    public function verifyAuthentication(array $payload): array {
        $challengeKey = (string) ($payload['challenge_key'] ?? '');
        $credentialId = (string) ($payload['credential_id'] ?? '');
        $clientDataB64 = (string) ($payload['client_data_json'] ?? '');
        $authenticatorDataB64 = (string) ($payload['authenticator_data'] ?? '');
        $signatureB64 = (string) ($payload['signature'] ?? '');

        if ($challengeKey === '' || $credentialId === '' || $clientDataB64 === '' || $authenticatorDataB64 === '' || $signatureB64 === '') {
            throw new \RuntimeException('Passkey authentication payload is incomplete.');
        }

        $challenge = $this->consumeChallenge($challengeKey, 'passkey_login');
        if ($challenge === null) {
            throw new \RuntimeException('Authentication challenge expired or invalid.');
        }

        $clientData = $this->decodeClientData($clientDataB64);
        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new \RuntimeException('Unexpected WebAuthn authentication response type.');
        }

        if (!$this->originAllowed((string) ($clientData['origin'] ?? ''))) {
            throw new \RuntimeException('Passkey origin mismatch.');
        }

        if (!hash_equals((string) ($challenge['challenge_value'] ?? ''), (string) ($clientData['challenge'] ?? ''))) {
            throw new \RuntimeException('Authentication challenge mismatch.');
        }

        $credential = $this->findCredential($credentialId);
        if ($credential === null) {
            throw new \RuntimeException('Passkey credential was not found.');
        }

        if ((int) ($challenge['person_id'] ?? 0) > 0 && (int) ($credential['person_id'] ?? 0) !== (int) $challenge['person_id']) {
            throw new \RuntimeException('Passkey credential does not match the requested account.');
        }

        $authenticatorData = $this->b64urlDecode($authenticatorDataB64);
        if (strlen($authenticatorData) < 37) {
            throw new \RuntimeException('Authenticator data is invalid.');
        }

        $rpHash = substr($authenticatorData, 0, 32);
        $expectedRpHash = hash('sha256', $this->rpId(), true);
        if (!hash_equals($expectedRpHash, $rpHash)) {
            throw new \RuntimeException('WebAuthn RP ID validation failed.');
        }

        $flags = ord($authenticatorData[32]);
        if (($flags & 0x01) !== 0x01) {
            throw new \RuntimeException('User presence was not verified by the authenticator.');
        }

        $signCountRaw = unpack('Ncount', substr($authenticatorData, 33, 4));
        $signCount = (int) ($signCountRaw['count'] ?? 0);
        $previousSignCount = (int) ($credential['sign_count'] ?? 0);
        if ($previousSignCount > 0 && $signCount > 0 && $signCount < $previousSignCount) {
            throw new \RuntimeException('Passkey sign counter regression detected.');
        }

        $credential['sign_count'] = max($previousSignCount, $signCount);
        $credential['last_used_at'] = $this->now();
        $this->persistCredential($credential);

        $this->logger->activity('passkey_authenticated', [
            'person_id' => (int) ($credential['person_id'] ?? 0),
            'credential_id' => $credentialId,
        ]);

        return [
            'status' => 'authenticated',
            'user_id' => (int) ($credential['person_id'] ?? 0),
            'person_id' => (int) ($credential['person_id'] ?? 0),
            'credential_id' => $credentialId,
            'sign_count' => (int) $credential['sign_count'],
            'last_used_at' => (string) $credential['last_used_at'],
            'signature_verified' => false,
        ];
    }

    private function resolvePersonId(string $identifier): int {
        if (\function_exists('metis_auth_find_person_by_identifier')) {
            $person = \metis_auth_find_person_by_identifier($identifier);
            if (is_array($person) && !empty($person['id'])) {
                return (int) $person['id'];
            }
        }

        if (\function_exists('metis_auth_find_user')) {
            $user = \metis_auth_find_user('email', $identifier);
            if (!is_array($user)) {
                $user = \metis_auth_find_user('login', $identifier);
            }
            if (is_array($user) && !empty($user['person_id'])) {
                return (int) $user['person_id'];
            }
        }

        return 0;
    }

    private function findPerson(int $personId): ?array {
        if (\function_exists('metis_auth_get_person')) {
            $row = \metis_auth_get_person($personId);
            return is_array($row) ? $row : null;
        }

        return null;
    }

    private function excludeCredentialsForPerson(int $personId): array {
        $rows = $this->listCredentialsForPerson($personId);
        $credentials = [];
        foreach ($rows as $row) {
            $credentials[] = [
                'id' => (string) ($row['credential_id'] ?? ''),
                'type' => 'public-key',
            ];
        }

        return $credentials;
    }

    private function listCredentialsForPerson(int $personId): array {
        if ($this->dbAvailable()) {
            $table = \Metis_Tables::get('people_passkeys');
            return $this->database()->fetchAll(
                "SELECT * FROM {$table} WHERE person_id = %d AND revoked_at IS NULL ORDER BY id ASC",
                [ $personId ]
            );
        }

        $payload = $this->files->readJson($this->files->rootPath('storage/runtime/passkeys.json'), []);
        return array_values(array_filter($payload, static fn (array $row): bool => (int) ($row['person_id'] ?? 0) === $personId && empty($row['revoked_at'])));
    }

    private function persistCredential(array $record): void {
        if ($this->dbAvailable()) {
            $table = \Metis_Tables::get('people_passkeys');
            $existingId = (int) $this->database()->scalar(
                "SELECT id FROM {$table} WHERE credential_id = %s LIMIT 1",
                [ (string) $record['credential_id'] ]
            );

            $payload = [
                'person_id' => (int) ($record['person_id'] ?? 0),
                'credential_id' => (string) ($record['credential_id'] ?? ''),
                'credential_public_key' => (string) ($record['credential_public_key'] ?? ''),
                'sign_count' => (int) ($record['sign_count'] ?? 0),
                'transports_json' => $record['transports_json'] ?? null,
                'label' => (string) ($record['label'] ?? 'Passkey'),
                'created_by_person_id' => isset($record['created_by_person_id']) ? (int) $record['created_by_person_id'] : null,
                'last_used_at' => $record['last_used_at'] ?? null,
                'revoked_at' => $record['revoked_at'] ?? null,
            ];

            if ($existingId > 0) {
                $this->database()->update(
                    $table,
                    $payload,
                    ['id' => $existingId],
                    ['%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s'],
                    ['%d']
                );
                return;
            }

            $this->database()->insert(
                $table,
                $payload,
                ['%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s']
            );
            return;
        }

        $path = $this->files->rootPath('storage/runtime/passkeys.json');
        $payload = $this->files->readJson($path, []);
        $payload[(string) $record['credential_id']] = $record;
        $this->files->writeJson($path, $payload);
    }

    private function findCredential(string $credentialId): ?array {
        if ($this->dbAvailable()) {
            $table = \Metis_Tables::get('people_passkeys');
            $row = $this->database()->fetchOne(
                "SELECT * FROM {$table} WHERE credential_id = %s AND revoked_at IS NULL LIMIT 1",
                [ $credentialId ]
            );

            return is_array($row) ? $row : null;
        }

        $path = $this->files->rootPath('storage/runtime/passkeys.json');
        $payload = $this->files->readJson($path, []);
        $record = $payload[$credentialId] ?? null;
        return is_array($record) && empty($record['revoked_at']) ? $record : null;
    }

    private function createChallenge(?int $personId, string $purpose, int $ttlSeconds = 600, string $challenge = ''): array {
        $challengeKey = bin2hex(random_bytes(16));
        $challengeValue = $challenge !== '' ? $challenge : $this->b64urlEncode(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, $ttlSeconds));

        $record = [
            'person_id' => $personId,
            'challenge_key' => $challengeKey,
            'challenge_value' => $challengeValue,
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => $this->now(),
        ];

        if ($this->dbAvailable()) {
            $table = \Metis_Tables::get('people_auth_challenges');
            $this->database()->insert(
                $table,
                [
                    'person_id' => $personId > 0 ? $personId : null,
                    'challenge_key' => $challengeKey,
                    'challenge_value' => $challengeValue,
                    'purpose' => $purpose,
                    'expires_at' => $expiresAt,
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );
        } else {
            $path = $this->files->rootPath('storage/runtime/passkey_challenges.json');
            $payload = $this->files->readJson($path, []);
            $payload[$challengeKey] = $record;
            $this->files->writeJson($path, $payload);
        }

        return $record;
    }

    private function consumeChallenge(string $challengeKey, string $purpose, ?int $personId = null): ?array {
        if ($this->dbAvailable()) {
            $table = \Metis_Tables::get('people_auth_challenges');
            $row = $this->database()->fetchOne(
                "SELECT * FROM {$table}
                 WHERE challenge_key = %s
                   AND purpose = %s
                   AND consumed_at IS NULL
                   AND expires_at >= UTC_TIMESTAMP()
                 LIMIT 1",
                [ $challengeKey, $purpose ]
            );

            if (!is_array($row)) {
                return null;
            }

            if ($personId !== null && (int) ($row['person_id'] ?? 0) !== $personId) {
                return null;
            }

            $this->database()->update($table, ['consumed_at' => $this->now()], ['id' => (int) ($row['id'] ?? 0)], ['%s'], ['%d']);
            return $row;
        }

        $path = $this->files->rootPath('storage/runtime/passkey_challenges.json');
        $payload = $this->files->readJson($path, []);
        $row = $payload[$challengeKey] ?? null;
        if (!is_array($row)) {
            return null;
        }

        if ((string) ($row['purpose'] ?? '') !== $purpose) {
            return null;
        }

        if (!empty($row['consumed_at']) || strtotime((string) ($row['expires_at'] ?? '1970-01-01 00:00:00')) < time()) {
            return null;
        }

        if ($personId !== null && (int) ($row['person_id'] ?? 0) !== $personId) {
            return null;
        }

        $row['consumed_at'] = $this->now();
        $payload[$challengeKey] = $row;
        $this->files->writeJson($path, $payload);

        return $row;
    }

    private function decodeClientData(string $clientDataB64): array {
        $clientDataJson = $this->b64urlDecode($clientDataB64);
        if ($clientDataJson === '') {
            throw new \RuntimeException('Invalid client data.');
        }

        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new \RuntimeException('Malformed client data payload.');
        }

        return $clientData;
    }

    private function originAllowed(string $origin): bool {
        if (\function_exists('metis_people_origin_allowed')) {
            return \metis_people_origin_allowed($origin);
        }

        $siteParts = $this->parseUrl(\metis_home_url());
        $originParts = $this->parseUrl($origin);
        if (!is_array($siteParts) || !is_array($originParts)) {
            return false;
        }

        return strtolower((string) ($siteParts['host'] ?? '')) === strtolower((string) ($originParts['host'] ?? ''))
            && strtolower((string) ($siteParts['scheme'] ?? 'https')) === strtolower((string) ($originParts['scheme'] ?? 'https'));
    }

    private function rpId(): string {
        return (string) ($this->parseUrl(\metis_home_url(), \PHP_URL_HOST) ?: '');
    }

    private function now(): string {
        return (string) \metis_current_time('mysql');
    }

    private function dbAvailable(): bool {
        return \class_exists('Metis_Tables') && ($this->db instanceof DatabaseService || \function_exists('metis_db'));
    }

    private function database(): DatabaseService {
        if ($this->db instanceof DatabaseService) {
            return $this->db;
        }

        /** @var DatabaseService $db */
        $db = \metis_db();
        return $db;
    }

    private function b64urlEncode(string $value): string {
        if (\function_exists('metis_people_b64url_encode')) {
            return \metis_people_b64url_encode($value);
        }

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): string {
        if (\function_exists('metis_people_b64url_decode')) {
            return \metis_people_b64url_decode($value);
        }

        $b64 = strtr($value, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($b64, true);
        return $decoded === false ? '' : $decoded;
    }

    private function parseUrl(string $url, int $component = -1): mixed {
        if (\function_exists('metis_runtime_parse_url')) {
            return \metis_runtime_parse_url($url, $component);
        }

        if (\function_exists('metis_parse_url')) {
            return \metis_parse_url($url, $component);
        }

        return parse_url($url, $component);
    }
}
