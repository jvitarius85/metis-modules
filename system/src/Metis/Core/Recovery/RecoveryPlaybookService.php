<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class RecoveryPlaybookService {
    /** @return array<string,array<string,mixed>> */
    public function all(): array {
        $dir = $this->playbookDir();
        $playbooks = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            if (basename($path) === 'index.php') {
                continue;
            }

            $loaded = require $path;
            if (!is_array($loaded)) {
                continue;
            }
            $key = (string) ($loaded['playbook_key'] ?? basename($path, '.php'));
            if ($key !== '') {
                $playbooks[$key] = $loaded;
            }
        }
        ksort($playbooks);
        return $playbooks;
    }

    /** @return array<string,mixed> */
    public function get(string $key): array {
        $key = $this->normalize($key);
        return $this->all()[$key] ?? [];
    }

    /** @return array<string,mixed> */
    public function forIssueType(string $issueType, bool $preboot = false): array {
        $issueType = $this->normalize($issueType);
        if ($preboot) {
            $candidate = $issueType === 'backup_recovery_failed' ? 'preboot_git_restore' : 'preboot_backup_restore';
            $playbook = $this->get($candidate);
            if ($playbook !== []) {
                return $playbook;
            }
        }

        foreach ($this->all() as $playbook) {
            $conditions = array_map([$this, 'normalize'], (array) ($playbook['trigger_conditions'] ?? []));
            if (in_array($issueType, $conditions, true) || (string) ($playbook['playbook_key'] ?? '') === $issueType) {
                return $playbook;
            }
        }

        return [];
    }

    private function playbookDir(): string {
        $root = defined('METIS_PATH') ? (string) METIS_PATH : dirname(__DIR__, 5);
        return rtrim($root, '/\\') . '/system/config/recovery/playbooks';
    }

    private function normalize(string $value): string {
        return strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim($value)) ?? '');
    }
}
