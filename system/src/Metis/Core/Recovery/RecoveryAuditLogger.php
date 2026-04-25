<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class RecoveryAuditLogger {
    public function __construct(private readonly RecoveryPolicyService $policy = new RecoveryPolicyService()) {}

    /** @param array<string,mixed> $context */
    public function log(string $message, array $context = [], string $severity = 'info', string $channel = 'recovery_audit'): void {
        $payload = [
            'timestamp' => gmdate('c'),
            'severity' => $severity,
            'message' => $message,
            'context' => $this->sanitize($context),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode(['timestamp' => gmdate('c'), 'severity' => $severity, 'message' => $message], JSON_UNESCAPED_SLASHES) ?: $message;
        }

        foreach ($this->logPaths($channel) as $path) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir)) {
                @chmod($dir, 02775);
            }
            @file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
            if (is_file($path)) {
                @chmod($path, 0664);
            }
        }
    }

    /** @param array<string,mixed> $data */
    public function createEvent(array $data): int {
        $now = gmdate('Y-m-d H:i:s');
        $event = [
            'event_code' => (string) ($data['event_code'] ?? 'recovery_' . bin2hex(random_bytes(4))),
            'severity' => (string) ($data['severity'] ?? 'warning'),
            'trigger_source' => (string) ($data['trigger_source'] ?? 'unknown'),
            'issue_type' => (string) ($data['issue_type'] ?? ''),
            'detected_at' => (string) ($data['detected_at'] ?? $now),
            'status' => (string) ($data['status'] ?? 'detected'),
            'selected_playbook' => (string) ($data['selected_playbook'] ?? ''),
            'backup_reference' => (string) ($data['backup_reference'] ?? ''),
            'git_reference' => (string) ($data['git_reference'] ?? ''),
            'started_at' => $data['started_at'] ?? null,
            'completed_at' => $data['completed_at'] ?? null,
            'result_summary' => json_encode($this->sanitize((array) ($data['result_summary'] ?? [])), JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_by' => (int) ($data['created_by'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            RecoverySchema::ensureSchema();
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                \metis_db()->insert(\Metis_Tables::get('recovery_events'), $event);
                return (int) \metis_db()->lastInsertId();
            }
        } catch (\Throwable $throwable) {
            $this->log('Failed to write recovery event to database.', ['error' => $throwable->getMessage(), 'event' => $event], 'warning');
        }

        return 0;
    }

    /** @param array<string,mixed> $data */
    public function updateEvent(int $eventId, array $data): void {
        if ($eventId < 1 || !function_exists('\metis_db') || !class_exists('\Metis_Tables')) {
            return;
        }

        $allowed = [
            'status', 'selected_playbook', 'backup_reference', 'git_reference',
            'started_at', 'completed_at', 'result_summary',
        ];
        $update = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            $update[$key] = $key === 'result_summary'
                ? (json_encode($this->sanitize((array) $value), JSON_UNESCAPED_SLASHES) ?: '{}')
                : $value;
        }
        $update['updated_at'] = gmdate('Y-m-d H:i:s');

        try {
            \metis_db()->update(\Metis_Tables::get('recovery_events'), $update, ['id' => $eventId]);
        } catch (\Throwable $throwable) {
            $this->log('Failed to update recovery event.', ['error' => $throwable->getMessage(), 'event_id' => $eventId], 'warning');
        }
    }

    /** @param array<string,mixed> $details */
    public function action(int $eventId, string $key, string $type, string $status, array $details = []): void {
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'recovery_event_id' => $eventId,
            'action_key' => $key,
            'action_type' => $type,
            'action_status' => $status,
            'action_started_at' => $now,
            'action_completed_at' => $status === 'running' ? null : $now,
            'details_json' => json_encode($this->sanitize($details), JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => $now,
        ];

        try {
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                \metis_db()->insert(\Metis_Tables::get('recovery_actions'), $row);
            }
        } catch (\Throwable $throwable) {
            $this->log('Failed to write recovery action.', ['error' => $throwable->getMessage(), 'action' => $row], 'warning');
        }

        $this->log('Recovery action ' . $status . ': ' . $key, ['event_id' => $eventId, 'type' => $type, 'details' => $details], $status === 'failed' ? 'error' : 'info');
    }

    /** @return array<int,string> */
    private function logPaths(string $channel): array {
        $name = preg_replace('/[^a-z0-9_]+/i', '_', $channel) ?: 'recovery_audit';
        if (!str_ends_with($name, '.log')) {
            $name .= '.log';
        }
        $root = defined('METIS_PATH') ? rtrim((string) METIS_PATH, '/\\') : dirname(__DIR__, 5);
        $paths = [$root . '/storage/logs/recovery/' . $name];
        $backupRoot = $this->policy->backupRoot();
        if ($backupRoot !== '' && (is_dir($backupRoot) || @mkdir($backupRoot, 0775, true))) {
            $paths[] = $backupRoot . '/logs/recovery/' . $name;
        }
        return $paths;
    }

    private function sanitize(mixed $value): mixed {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $entry) {
                $keyString = (string) $key;
                if (preg_match('/password|secret|token|private|credential|key/i', $keyString)) {
                    $clean[$key] = '[redacted]';
                    continue;
                }
                $clean[$key] = $this->sanitize($entry);
            }
            return $clean;
        }
        if (is_object($value)) {
            return '[object:' . $value::class . ']';
        }
        if (is_string($value) && strlen($value) > 1000) {
            return substr($value, 0, 1000) . '...';
        }
        return $value;
    }
}
