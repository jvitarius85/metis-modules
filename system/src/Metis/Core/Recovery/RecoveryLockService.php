<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class RecoveryLockService {
    public function __construct(
        private readonly RecoveryPolicyService $policy = new RecoveryPolicyService(),
        private readonly RecoveryAuditLogger $logger = new RecoveryAuditLogger()
    ) {}

    public function acquire(string $key = 'recovery_global'): bool {
        $key = $this->normalize($key);
        $now = time();
        $expires = $now + $this->policy->lockTtlSeconds();

        try {
            RecoverySchema::ensureSchema();
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                $table = \Metis_Tables::get('recovery_locks');
                $existing = \metis_db()->fetchOne("SELECT * FROM {$table} WHERE lock_key = %s LIMIT 1", [$key]);
                if (is_array($existing) && (string) ($existing['lock_status'] ?? '') === 'active') {
                    $existingExpires = strtotime((string) ($existing['expires_at'] ?? '')) ?: 0;
                    if ($existingExpires > $now) {
                        return false;
                    }
                }
                \metis_db()->replace($table, [
                    'lock_key' => $key,
                    'lock_status' => 'active',
                    'acquired_at' => gmdate('Y-m-d H:i:s', $now),
                    'expires_at' => gmdate('Y-m-d H:i:s', $expires),
                    'created_at' => gmdate('Y-m-d H:i:s', $now),
                ]);
                $this->logger->log('Recovery lock acquired.', ['lock_key' => $key, 'expires_at' => gmdate('c', $expires)]);
                return true;
            }
        } catch (\Throwable $throwable) {
            $this->logger->log('Database recovery lock failed; falling back to file lock.', ['error' => $throwable->getMessage()], 'warning');
        }

        $path = $this->fileLockPath($key);
        if (is_file($path)) {
            $payload = json_decode((string) @file_get_contents($path), true);
            if (is_array($payload) && (int) ($payload['expires_at'] ?? 0) > $now) {
                return false;
            }
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode(['lock_key' => $key, 'acquired_at' => $now, 'expires_at' => $expires], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return is_file($path);
    }

    public function release(string $key = 'recovery_global'): void {
        $key = $this->normalize($key);
        try {
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                \metis_db()->update(\Metis_Tables::get('recovery_locks'), ['lock_status' => 'released'], ['lock_key' => $key]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->log('Database recovery lock release failed.', ['error' => $throwable->getMessage()], 'warning');
        }

        $path = $this->fileLockPath($key);
        if (is_file($path)) {
            @unlink($path);
        }
        $this->logger->log('Recovery lock released.', ['lock_key' => $key]);
    }

    public function clearStale(string $key = 'recovery_global'): bool {
        $key = $this->normalize($key);
        $now = time();
        $cleared = false;
        try {
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                $table = \Metis_Tables::get('recovery_locks');
                $existing = \metis_db()->fetchOne("SELECT * FROM {$table} WHERE lock_key = %s LIMIT 1", [$key]);
                $expires = is_array($existing) ? (strtotime((string) ($existing['expires_at'] ?? '')) ?: 0) : 0;
                if ($expires > 0 && $expires <= $now) {
                    \metis_db()->update($table, ['lock_status' => 'cleared'], ['lock_key' => $key]);
                    $cleared = true;
                }
            }
        } catch (\Throwable) {
        }

        $path = $this->fileLockPath($key);
        if (is_file($path)) {
            $payload = json_decode((string) @file_get_contents($path), true);
            if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) <= $now) {
                @unlink($path);
                $cleared = true;
            }
        }

        if ($cleared) {
            $this->logger->log('Stale recovery lock cleared.', ['lock_key' => $key]);
        }
        return $cleared;
    }

    /** @return array<int,array<string,mixed>> */
    public function activeLocks(): array {
        try {
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                $table = \Metis_Tables::get('recovery_locks');
                return \metis_db()->fetchAll("SELECT * FROM {$table} WHERE lock_status = 'active' ORDER BY acquired_at DESC LIMIT 20");
            }
        } catch (\Throwable) {
        }
        return [];
    }

    private function fileLockPath(string $key): string {
        $root = defined('METIS_PATH') ? rtrim((string) METIS_PATH, '/\\') : dirname(__DIR__, 5);
        return $root . '/storage/runtime/recovery/' . $key . '.lock';
    }

    private function normalize(string $key): string {
        return preg_replace('/[^a-z0-9_]+/i', '_', strtolower(trim($key))) ?: 'recovery_global';
    }
}
