<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class BackupRecoveryService {
    public function __construct(
        private readonly RecoveryPolicyService $policy = new RecoveryPolicyService(),
        private readonly RecoveryAuditLogger $logger = new RecoveryAuditLogger(),
        private readonly RecoveryVerifier $verifier = new RecoveryVerifier()
    ) {}

    /** @param array<int,array<string,mixed>> $issues @return array<string,mixed> */
    public function recoverIssues(array $issues, int $eventId = 0, string $trigger = 'manual'): array {
        $files = $this->affectedFiles($issues);
        $backup = $this->backupCurrentFiles($files, $trigger);
        $restored = [];
        $failed = [];

        foreach ($files as $relative) {
            $source = $this->locateBackupFile($relative);
            if ($source === '') {
                $failed[] = ['path' => $relative, 'reason' => 'backup_not_found'];
                continue;
            }

            $destination = $this->verifier->absolutePath($relative);
            $quarantine = $this->quarantineFile($relative);
            $dir = dirname($destination);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@copy($source, $destination)) {
                @chmod($destination, 0664);
                $restored[] = ['path' => $relative, 'source' => $source, 'quarantine' => $quarantine];
                $this->logger->action($eventId, 'restore_from_backup', 'file_restore', 'completed', ['path' => $relative, 'source' => $source]);
            } else {
                $failed[] = ['path' => $relative, 'reason' => 'copy_failed', 'source' => $source];
                $this->logger->action($eventId, 'restore_from_backup', 'file_restore', 'failed', ['path' => $relative, 'source' => $source]);
            }
        }

        $status = $failed === [] && $restored !== [] ? 'success' : ($restored !== [] ? 'partial' : 'failed');
        $result = [
            'status' => $status,
            'backup_reference' => (string) ($backup['backup_reference'] ?? ''),
            'backup_path' => (string) ($backup['backup_path'] ?? ''),
            'restored' => $restored,
            'failed' => $failed,
        ];
        $this->logger->log('Backup recovery completed.', $result, $status === 'failed' ? 'error' : 'info');
        return $result;
    }

    /** @param array<int,string> $files @return array<string,mixed> */
    public function backupCurrentFiles(array $files, string $context): array {
        $root = $this->policy->backupRoot();
        $reference = 'recovery-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $target = $root . '/recovery/emergency/' . $reference;
        $copied = [];
        if (!is_dir($target)) {
            @mkdir($target, 0775, true);
        }

        foreach (array_values(array_unique($files)) as $relative) {
            $source = $this->verifier->absolutePath($relative);
            if (!is_file($source)) {
                continue;
            }
            $dest = $target . '/' . ltrim($relative, '/\\');
            $dir = dirname($dest);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@copy($source, $dest)) {
                $copied[] = $relative;
            }
        }

        $manifest = [
            'backup_reference' => $reference,
            'created_at' => gmdate('c'),
            'source_context' => $context,
            'files' => $copied,
        ];
        @file_put_contents($target . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                \metis_db()->replace(\Metis_Tables::get('recovery_backups'), [
                    'backup_reference' => $reference,
                    'backup_type' => 'emergency_files',
                    'backup_path' => $target,
                    'includes_files' => 1,
                    'includes_database' => 0,
                    'source_context' => $context,
                    'verification_status' => $copied !== [] ? 'verified' : 'empty',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->log('Failed to record emergency backup.', ['error' => $throwable->getMessage()], 'warning');
        }

        $this->logger->log('Emergency recovery backup created.', $manifest);
        return ['backup_reference' => $reference, 'backup_path' => $target, 'files' => $copied];
    }

    /** @param array<int,array<string,mixed>> $issues @return array<int,string> */
    public function affectedFiles(array $issues): array {
        $files = [];
        foreach ($issues as $issue) {
            $path = trim((string) ($issue['path'] ?? ''));
            if ($path === '' || $path === 'database' || str_starts_with($path, '/Volumes/')) {
                continue;
            }
            if ($this->isPreserved($path)) {
                continue;
            }
            $files[] = ltrim($path, '/\\');
        }
        return array_values(array_unique($files));
    }

    private function locateBackupFile(string $relative): string {
        $root = $this->policy->backupRoot();
        if (!is_dir($root)) {
            return '';
        }

        $candidates = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        $candidates = array_filter($candidates, static function (string $path): bool {
            $base = basename($path);
            return !in_array($base, ['quarantine', 'logs'], true);
        });
        usort($candidates, static fn(string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));

        foreach (array_slice($candidates, 0, 25) as $dir) {
            foreach ([
                $dir . '/' . $relative,
                $dir . '/system/' . $relative,
                $dir . '/files/' . $relative,
                $dir . '/metis/' . $relative,
            ] as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    return $candidate;
                }
            }
        }

        $local = $this->verifier->absolutePath('storage/runtime/integrity/recovery/' . $relative);
        return is_file($local) ? $local : '';
    }

    private function quarantineFile(string $relative): string {
        $source = $this->verifier->absolutePath($relative);
        if (!is_file($source)) {
            return '';
        }
        $target = $this->policy->backupRoot() . '/quarantine/' . gmdate('Ymd-His') . '/' . ltrim($relative, '/\\');
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@copy($source, $target)) {
            return $target;
        }
        return '';
    }

    private function isPreserved(string $relative): bool {
        $relative = trim($relative, '/\\');
        foreach ($this->policy->preservePaths() as $preserve) {
            $preserve = trim($preserve, '/\\');
            if ($relative === $preserve || str_starts_with($relative, $preserve . '/')) {
                return true;
            }
        }
        return false;
    }
}
