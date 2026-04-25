<?php
declare(strict_types=1);

namespace Metis\Core\Auth;

use Metis\Core\Services\AuditLogService;
use Metis\Core\Services\ConfigService;
use Metis\Core\Services\HttpClient;

final class PasswordSecurityService {
    private const DEFAULT_MIN_LENGTH = 12;
    private const DEFAULT_BREACH_API = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private readonly ConfigService $config = new ConfigService(),
        private readonly HttpClient $http = new HttpClient(),
        private readonly AuditLogService $audit = new AuditLogService()
    ) {}

    public function hash(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool {
        return $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool {
        return $hash !== '' && password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    public function rehashIfNeeded(string $password, string $hash): ?string {
        if (!$this->verify($password, $hash) || !$this->needsRehash($hash)) {
            return null;
        }

        return $this->hash($password);
    }

    public function assertPasswordAllowed(string $password): void {
        $minLength = max(8, (int) $this->setting('auth_password_min_length', self::DEFAULT_MIN_LENGTH));
        if (strlen($password) < $minLength) {
            throw new \RuntimeException(sprintf('Password must be at least %d characters.', $minLength));
        }

        if ($this->breachDetectionEnabled() && $this->passwordAppearsBreached($password)) {
            throw new \RuntimeException('This password has appeared in known data breaches. Please choose a different password.');
        }
    }

    private function breachDetectionEnabled(): bool {
        $file = $this->config->loadFile('config/auth/security.php', []);
        $enabled = $file['breach_detection']['enabled'] ?? false;
        return (bool) $this->setting('auth_breach_detection_enabled', $enabled);
    }

    private function passwordAppearsBreached(string $password): bool {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        if ($prefix === '' || $suffix === '') {
            return false;
        }

        $file = $this->config->loadFile('config/auth/security.php', []);
        $baseUrl = (string) ($file['breach_detection']['api_base_url'] ?? self::DEFAULT_BREACH_API);
        $baseUrl = rtrim($baseUrl, '/') . '/';

        try {
            $response = $this->http->get($baseUrl . rawurlencode($prefix), [
                'Add-Padding' => 'true',
                'User-Agent' => 'Metis-PasswordSecurity/1.0',
            ]);
        } catch (\Throwable $throwable) {
            $this->audit->security('auth_password_breach_check_failed', [
                'error' => $throwable->getMessage(),
            ], 'warning', 'degraded');
            return false;
        }

        if ((int) ($response['status'] ?? 0) !== 200) {
            $this->audit->security('auth_password_breach_check_failed', [
                'status' => (int) ($response['status'] ?? 0),
            ], 'warning', 'degraded');
            return false;
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) ($response['body'] ?? ''));
        if (!is_array($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            if (!is_string($line) || $line === '' || !str_contains($line, ':')) {
                continue;
            }

            [ $candidateSuffix ] = array_pad(explode(':', trim($line), 2), 2, '');
            if (!is_string($candidateSuffix) || $candidateSuffix === '') {
                continue;
            }

            if (hash_equals($suffix, strtoupper(trim($candidateSuffix)))) {
                $this->audit->security('auth_password_breach_detected', [], 'warning', 'rejected');
                return true;
            }
        }

        return false;
    }

    private function setting(string $key, mixed $default = null): mixed {
        return $this->config->get($key, $default);
    }
}
