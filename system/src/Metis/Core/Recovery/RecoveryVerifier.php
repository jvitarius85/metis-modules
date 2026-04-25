<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class RecoveryVerifier {
    public function __construct(
        private readonly RecoveryPolicyService $policy = new RecoveryPolicyService(),
        private readonly RecoveryAuditLogger $logger = new RecoveryAuditLogger()
    ) {}

    /** @return array<string,mixed> */
    public function scan(string $trigger = 'manual', bool $preboot = false): array {
        $issues = [];
        foreach ($this->criticalFiles() as $relative) {
            $absolute = $this->absolutePath($relative);
            if (!is_file($absolute)) {
                $issues[] = $this->issue('missing_core_file', 'critical', $relative, 'Critical file is missing.');
                continue;
            }

            $expected = $this->expectedHash($relative);
            if ($expected !== '' && hash_file('sha256', $absolute) !== $expected) {
                $issues[] = $this->issue('corrupted_core_file', 'critical', $relative, 'Critical file hash does not match manifest.');
            }
        }

        foreach ($this->moduleDirectories() as $moduleDir) {
            $manifest = $moduleDir . '/module.json';
            $relative = $this->relativePath($manifest);
            if (!is_file($manifest)) {
                $issues[] = $this->issue('missing_module_manifest', 'high', $relative, 'Module manifest is missing.');
                continue;
            }
            $decoded = json_decode((string) @file_get_contents($manifest), true);
            if (!is_array($decoded) || empty($decoded['slug']) || empty($decoded['name'])) {
                $issues[] = $this->issue('corrupted_module_manifest', 'high', $relative, 'Module manifest is invalid.');
            }
        }

        $backupRoot = $this->policy->backupRoot();
        if ($backupRoot === '' || (!is_dir($backupRoot) && !@mkdir($backupRoot, 0775, true)) || !is_writable($backupRoot)) {
            $issues[] = $this->issue('backup_directory_unavailable', 'warning', $backupRoot, 'Backup directory is not writable.');
        }

        if (!$preboot && function_exists('\metis_db')) {
            try {
                \metis_db()->fetchOne('SELECT 1 AS ok');
            } catch (\Throwable $throwable) {
                $issues[] = $this->issue('database_connection_failure', 'critical', 'database', 'Database health check failed.');
            }
        }

        $critical = array_values(array_filter($issues, static fn(array $issue): bool => (string) ($issue['severity'] ?? '') === 'critical'));
        $status = $critical !== [] ? 'critical' : ($issues !== [] ? 'warning' : 'pass');
        $result = [
            'status' => $status,
            'trigger' => $trigger,
            'preboot' => $preboot,
            'checked_at' => gmdate('c'),
            'issues' => $issues,
            'critical_count' => count($critical),
            'issue_count' => count($issues),
        ];
        $this->logger->log('Recovery integrity scan completed.', $result, $status === 'critical' ? 'error' : ($status === 'warning' ? 'warning' : 'info'), $preboot ? 'preboot' : 'self_heal');
        return $result;
    }

    /** @param array<int,array<string,mixed>> $issues */
    public function verifyAfterRecovery(array $issues = []): array {
        $scan = $this->scan('recovery_verify', true);
        $paths = array_values(array_filter(array_map(static fn(array $issue): string => (string) ($issue['path'] ?? ''), $issues)));
        if ($paths === []) {
            return $scan;
        }

        $remaining = [];
        foreach ((array) ($scan['issues'] ?? []) as $issue) {
            if (in_array((string) ($issue['path'] ?? ''), $paths, true)) {
                $remaining[] = $issue;
            }
        }

        $scan['targeted_remaining'] = $remaining;
        $scan['targeted_status'] = $remaining === [] ? 'pass' : 'fail';
        return $scan;
    }

    /** @return array<string,mixed> */
    public function rebuildManifest(string $trigger = 'manual'): array {
        RecoverySchema::ensureSchema();
        $version = $this->version();
        $rows = [];
        $hashSource = '';
        foreach ($this->manifestFiles() as $relative) {
            $absolute = $this->absolutePath($relative);
            if (!is_file($absolute)) {
                continue;
            }
            $hash = hash_file('sha256', $absolute);
            $size = filesize($absolute);
            $rows[] = [
                'version' => $version,
                'file_path' => $relative,
                'file_hash' => $hash,
                'file_size' => $size === false ? 0 : (int) $size,
            ];
            $hashSource .= $relative . ':' . $hash . ';';
        }
        $manifestHash = hash('sha256', $hashSource);
        $now = gmdate('Y-m-d H:i:s');
        $written = 0;

        if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
            $table = \Metis_Tables::get('recovery_integrity_manifest');
            foreach ($rows as $row) {
                \metis_db()->replace($table, $row + [
                    'manifest_hash' => $manifestHash,
                    'last_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $written++;
            }
        }

        $result = [
            'status' => 'success',
            'trigger' => $trigger,
            'version' => $version,
            'manifest_hash' => $manifestHash,
            'file_count' => $written,
        ];
        $this->logger->log('Recovery integrity manifest rebuilt.', $result);
        return $result;
    }

    public function expectedHash(string $relative): string {
        if (!function_exists('\metis_db') || !class_exists('\Metis_Tables')) {
            return '';
        }
        try {
            $table = \Metis_Tables::get('recovery_integrity_manifest');
            $row = \metis_db()->fetchOne(
                "SELECT file_hash FROM {$table} WHERE version = %s AND file_path = %s LIMIT 1",
                [$this->version(), $relative]
            );
            return is_array($row) ? (string) ($row['file_hash'] ?? '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array<int,string> */
    public function criticalFiles(): array {
        $files = $this->policy->criticalFiles();
        $vendorAutoload = 'system/vendor/autoload.php';
        if (is_dir($this->absolutePath('system/vendor')) && !in_array($vendorAutoload, $files, true)) {
            $files[] = $vendorAutoload;
        }
        return array_values(array_unique($files));
    }

    public function version(): string {
        if (defined('METIS_VERSION')) {
            return (string) METIS_VERSION;
        }
        if (function_exists('\metis_read_system_version')) {
            return (string) \metis_read_system_version();
        }
        return 'unknown';
    }

    public function absolutePath(string $relative): string {
        $root = defined('METIS_PATH') ? rtrim((string) METIS_PATH, '/\\') : dirname(__DIR__, 5);
        return $root . '/' . ltrim($relative, '/\\');
    }

    public function relativePath(string $absolute): string {
        $root = defined('METIS_PATH') ? rtrim((string) METIS_PATH, '/\\') : dirname(__DIR__, 5);
        $absolute = str_replace('\\', '/', $absolute);
        $root = str_replace('\\', '/', $root);
        if (str_starts_with($absolute, $root . '/')) {
            return substr($absolute, strlen($root) + 1);
        }
        return ltrim($absolute, '/');
    }

    /** @return array<int,string> */
    private function moduleDirectories(): array {
        $base = $this->absolutePath('system/modules');
        $dirs = glob($base . '/*', GLOB_ONLYDIR);
        return is_array($dirs) ? $dirs : [];
    }

    /** @return array<int,string> */
    private function manifestFiles(): array {
        $files = $this->criticalFiles();
        foreach ($this->moduleDirectories() as $moduleDir) {
            foreach (['module.json', 'Module.php'] as $file) {
                $path = $moduleDir . '/' . $file;
                if (is_file($path)) {
                    $files[] = $this->relativePath($path);
                }
            }
        }
        return array_values(array_unique($files));
    }

    /** @return array<string,mixed> */
    private function issue(string $type, string $severity, string $path, string $message): array {
        $playbook = (new RecoveryPlaybookService())->forIssueType($type, $severity === 'critical');
        return [
            'type' => $type,
            'severity' => $severity,
            'path' => $path,
            'message' => $message,
            'selected_playbook' => (string) ($playbook['playbook_key'] ?? ''),
        ];
    }
}
