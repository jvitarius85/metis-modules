<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class GitRecoveryService {
    public function __construct(
        private readonly RecoveryPolicyService $policy = new RecoveryPolicyService(),
        private readonly RecoveryAuditLogger $logger = new RecoveryAuditLogger(),
        private readonly RecoveryVerifier $verifier = new RecoveryVerifier(),
        private readonly BackupRecoveryService $backups = new BackupRecoveryService()
    ) {}

    /** @param array<int,array<string,mixed>> $issues @return array<string,mixed> */
    public function recoverIssues(array $issues, int $eventId = 0, string $trigger = 'manual'): array {
        if (!$this->policy->allowGitRecovery()) {
            return ['status' => 'skipped', 'reason' => 'git_recovery_disabled'];
        }

        $remote = trim((string) ($this->runGit(['config', '--get', 'remote.origin.url'])['stdout'] ?? ''));
        $allowed = $this->policy->allowedRemote();
        if ($allowed !== '' && $remote !== $allowed && rtrim($remote, '/') !== rtrim($allowed, '/')) {
            $this->logger->log('Git recovery refused because remote is not allowed.', ['remote' => $remote], 'error');
            return ['status' => 'failed', 'reason' => 'remote_not_allowed', 'remote' => $remote];
        }

        $files = $this->backups->affectedFiles($issues);
        if ($files === []) {
            return ['status' => 'skipped', 'reason' => 'no_recoverable_files'];
        }

        if ($this->policy->backupRequiredBeforeGit()) {
            $this->backups->backupCurrentFiles($files, $trigger . ':before_git');
        }

        $ref = $this->installedRef();
        if ($ref === '') {
            return ['status' => 'failed', 'reason' => 'installed_ref_unavailable'];
        }

        $restored = [];
        $failed = [];
        foreach ($files as $relative) {
            $show = $this->runGit(['show', $ref . ':' . $relative]);
            if ((int) ($show['exit_code'] ?? 1) !== 0 || (string) ($show['stdout'] ?? '') === '') {
                $failed[] = ['path' => $relative, 'reason' => 'git_show_failed'];
                continue;
            }

            $destination = $this->verifier->absolutePath($relative);
            $dir = dirname($destination);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@file_put_contents($destination, (string) $show['stdout'], LOCK_EX) !== false) {
                @chmod($destination, 0664);
                $restored[] = ['path' => $relative, 'git_reference' => $ref];
                $this->logger->action($eventId, 'restore_from_git', 'file_restore', 'completed', ['path' => $relative, 'git_reference' => $ref]);
            } else {
                $failed[] = ['path' => $relative, 'reason' => 'write_failed'];
                $this->logger->action($eventId, 'restore_from_git', 'file_restore', 'failed', ['path' => $relative, 'git_reference' => $ref]);
            }
        }

        $status = $failed === [] && $restored !== [] ? 'success' : ($restored !== [] ? 'partial' : 'failed');
        $result = ['status' => $status, 'git_reference' => $ref, 'remote' => $remote, 'restored' => $restored, 'failed' => $failed];
        $this->logger->log('Git recovery completed.', $result, $status === 'failed' ? 'error' : 'info');
        return $result;
    }

    /** @return array<string,mixed> */
    public function dryRun(): array {
        $remote = trim((string) ($this->runGit(['config', '--get', 'remote.origin.url'])['stdout'] ?? ''));
        $ref = $this->installedRef();
        $allowed = $this->policy->allowedRemote();
        return [
            'status' => $this->policy->allowGitRecovery() && $ref !== '' && ($allowed === '' || rtrim($remote, '/') === rtrim($allowed, '/')) ? 'pass' : 'warn',
            'git_recovery_allowed' => $this->policy->allowGitRecovery(),
            'remote' => $remote,
            'allowed_remote' => $allowed,
            'installed_ref' => $ref,
            'latest_fallback_allowed' => $this->policy->allowLatestFallback(),
        ];
    }

    private function installedRef(): string {
        $version = $this->verifier->version();
        foreach (['v' . $version, $version] as $tag) {
            if ($tag === 'vunknown' || $tag === 'unknown') {
                continue;
            }
            $result = $this->runGit(['rev-parse', '--verify', '--quiet', $tag . '^{commit}']);
            if ((int) ($result['exit_code'] ?? 1) === 0 && trim((string) ($result['stdout'] ?? '')) !== '') {
                return $tag;
            }
        }

        $head = trim((string) ($this->runGit(['rev-parse', 'HEAD'])['stdout'] ?? ''));
        if ($head !== '') {
            return $head;
        }

        return $this->policy->allowLatestFallback() ? $this->policy->fallbackBranch() : '';
    }

    /** @param array<int,string> $args @return array<string,mixed> */
    private function runGit(array $args): array {
        $root = defined('METIS_PATH') ? rtrim((string) METIS_PATH, '/\\') : dirname(__DIR__, 5);
        $command = array_merge(['git', '-C', $root], $args);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Unable to start git process.'];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['exit_code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
