<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class RecoveryPolicyService {
    /** @var array<string,mixed> */
    private array $policy;

    public function __construct(?array $policy = null) {
        $this->policy = $policy ?? $this->loadPolicy();
    }

    /** @return array<string,mixed> */
    public function all(): array {
        return $this->policy;
    }

    public function get(string $key, mixed $default = null): mixed {
        return array_key_exists($key, $this->policy) ? $this->policy[$key] : $default;
    }

    public function backupRoot(): string {
        $root = (string) $this->get('backup_root', '/Volumes/NAS/backups 2');
        return rtrim($root, '/\\');
    }

    public function allowGitRecovery(): bool {
        return (bool) $this->get('allow_git_recovery', false);
    }

    public function allowLatestFallback(): bool {
        return (bool) $this->get('allow_latest_fallback', false);
    }

    public function fallbackBranch(): string {
        return trim((string) $this->get('allowed_fallback_branch', 'stable'));
    }

    public function allowedRemote(): string {
        return trim((string) $this->get('allowed_git_remote', ''));
    }

    public function lockTtlSeconds(): int {
        return max(60, (int) $this->get('lock_ttl_seconds', 900));
    }

    public function maxAttempts(): int {
        return max(1, (int) $this->get('max_recovery_attempts', 2));
    }

    public function backupRequiredBeforeGit(): bool {
        return (bool) $this->get('backup_required_before_git_restore', true);
    }

    public function maintenanceOnFailure(): bool {
        return (bool) $this->get('maintenance_mode_on_failure', true);
    }

    /** @return array<int,string> */
    public function criticalFiles(): array {
        return array_values(array_filter(array_map('strval', (array) $this->get('critical_files', []))));
    }

    /** @return array<int,string> */
    public function preservePaths(): array {
        return array_values(array_filter(array_map('strval', (array) $this->get('preserve_paths', []))));
    }

    private function loadPolicy(): array {
        $path = $this->configPath();
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                return $loaded;
            }
        }

        return [
            'allow_git_recovery' => false,
            'allowed_fallback_branch' => 'stable',
            'allow_latest_fallback' => false,
            'max_recovery_attempts' => 2,
            'backup_required_before_git_restore' => true,
            'maintenance_mode_on_failure' => true,
            'lock_ttl_seconds' => 900,
            'backup_root' => '/Volumes/NAS/backups 2',
            'critical_files' => [],
            'preserve_paths' => [],
        ];
    }

    private function configPath(): string {
        $root = defined('METIS_PATH') ? (string) METIS_PATH : dirname(__DIR__, 5);
        return rtrim($root, '/\\') . '/system/config/recovery/recovery_policy.php';
    }
}
