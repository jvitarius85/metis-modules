<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class PrebootIntegrityService {
    public function __construct(
        private readonly RecoveryPolicyService $policy = new RecoveryPolicyService(),
        private readonly RecoveryPlaybookService $playbooks = new RecoveryPlaybookService(),
        private readonly RecoveryAuditLogger $logger = new RecoveryAuditLogger(),
        private readonly RecoveryLockService $locks = new RecoveryLockService(),
        private readonly RecoveryVerifier $verifier = new RecoveryVerifier(),
        private readonly BackupRecoveryService $backupRecovery = new BackupRecoveryService(),
        private readonly GitRecoveryService $gitRecovery = new GitRecoveryService()
    ) {}

    /** @return array<string,mixed> */
    public function checkAndRecover(string $trigger = 'preboot'): array {
        RecoverySchema::ensureSchema();
        $scan = $this->verifier->scan($trigger, true);
        if (!$this->policy->prebootRecoveryEnabled() || !$this->policy->automaticFileRecoveryEnabled()) {
            return ['status' => 'disabled', 'scan' => $scan, 'mutation_enabled' => false];
        }

        $critical = array_values(array_filter((array) ($scan['issues'] ?? []), static fn(array $issue): bool => (string) ($issue['severity'] ?? '') === 'critical'));
        if ($critical === []) {
            return ['status' => 'pass', 'scan' => $scan];
        }

        $firstIssue = (array) ($critical[0] ?? []);
        $playbook = $this->playbooks->forIssueType((string) ($firstIssue['type'] ?? 'preboot_critical_corruption'), true);
        $eventId = $this->logger->createEvent([
            'event_code' => 'preboot_' . gmdate('Ymd_His'),
            'severity' => 'critical',
            'trigger_source' => $trigger,
            'issue_type' => (string) ($firstIssue['type'] ?? 'preboot_critical_corruption'),
            'status' => 'started',
            'selected_playbook' => (string) ($playbook['playbook_key'] ?? 'preboot_backup_restore'),
            'started_at' => gmdate('Y-m-d H:i:s'),
            'result_summary' => ['critical_issues' => $critical],
        ]);

        if (!$this->locks->acquire('preboot_recovery')) {
            $this->logger->updateEvent($eventId, ['status' => 'locked', 'completed_at' => gmdate('Y-m-d H:i:s'), 'result_summary' => ['reason' => 'active_recovery_lock']]);
            return $this->maintenance('Recovery lock is active.', $scan, $eventId);
        }

        try {
            $this->logger->action($eventId, 'detect_classify_validate', 'preboot', 'completed', ['scan' => $scan, 'playbook' => $playbook]);
            $backupResult = $this->backupRecovery->recoverIssues($critical, $eventId, $trigger);
            $verify = $this->verifier->verifyAfterRecovery($critical);
            if ((string) ($backupResult['status'] ?? '') === 'success' && (string) ($verify['targeted_status'] ?? 'fail') === 'pass') {
                $this->logger->updateEvent($eventId, [
                    'status' => 'recovered',
                    'backup_reference' => (string) ($backupResult['backup_reference'] ?? ''),
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                    'result_summary' => ['backup_recovery' => $backupResult, 'verification' => $verify],
                ]);
                $this->verifier->rebuildManifest('preboot_backup_recovery');
                return ['status' => 'recovered', 'method' => 'backup', 'scan' => $scan, 'verification' => $verify];
            }

            $gitPlaybook = $this->playbooks->forIssueType('backup_recovery_failed', true);
            $this->logger->updateEvent($eventId, ['selected_playbook' => (string) ($gitPlaybook['playbook_key'] ?? 'preboot_git_restore')]);
            $gitResult = $this->gitRecovery->recoverIssues($critical, $eventId, $trigger);
            $verify = $this->verifier->verifyAfterRecovery($critical);
            if ((string) ($gitResult['status'] ?? '') === 'success' && (string) ($verify['targeted_status'] ?? 'fail') === 'pass') {
                $this->logger->updateEvent($eventId, [
                    'status' => 'recovered',
                    'git_reference' => (string) ($gitResult['git_reference'] ?? ''),
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                    'result_summary' => ['backup_recovery' => $backupResult, 'git_recovery' => $gitResult, 'verification' => $verify],
                ]);
                $this->verifier->rebuildManifest('preboot_git_recovery');
                return ['status' => 'recovered', 'method' => 'git', 'scan' => $scan, 'verification' => $verify];
            }

            $this->logger->updateEvent($eventId, [
                'status' => 'failed',
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'result_summary' => ['backup_recovery' => $backupResult, 'git_recovery' => $gitResult, 'verification' => $verify],
            ]);
            return $this->maintenance('Automatic recovery failed.', $scan, $eventId);
        } finally {
            $this->locks->release('preboot_recovery');
        }
    }

    /** @return array<string,mixed> */
    public function dashboardSnapshot(): array {
        RecoverySchema::ensureSchema();
        $scan = $this->verifier->scan('dashboard', false);
        $manifestCount = 0;
        $lastEvent = null;
        $events = [];
        try {
            if (function_exists('\metis_db') && class_exists('\Metis_Tables')) {
                $manifestTable = \Metis_Tables::get('recovery_integrity_manifest');
                $eventsTable = \Metis_Tables::get('recovery_events');
                $manifestCount = (int) \metis_db()->scalar("SELECT COUNT(*) FROM {$manifestTable}");
                $events = \metis_db()->fetchAll("SELECT * FROM {$eventsTable} ORDER BY detected_at DESC, id DESC LIMIT 20");
                $lastEvent = $events[0] ?? null;
            }
        } catch (\Throwable $throwable) {
            $this->logger->log('Recovery dashboard query failed.', ['error' => $throwable->getMessage()], 'warning');
        }

        $backupRoot = $this->policy->backupRoot();
        return [
            'status' => (string) ($scan['status'] ?? 'unknown'),
            'version' => $this->verifier->version(),
            'last_scan' => $scan,
            'last_event' => $lastEvent,
            'recent_events' => $events,
            'locks' => $this->locks->activeLocks(),
            'backup' => [
                'path' => $backupRoot,
                'exists' => is_dir($backupRoot),
                'writable' => is_dir($backupRoot) && is_writable($backupRoot),
            ],
            'git' => $this->gitRecovery->dryRun(),
            'manifest' => [
                'version' => $this->verifier->version(),
                'file_count' => $manifestCount,
                'status' => $manifestCount > 0 ? 'present' : 'missing',
            ],
            'playbooks' => array_values($this->playbooks->all()),
        ];
    }

    /** @param array<string,mixed> $scan @return array<string,mixed> */
    private function maintenance(string $reason, array $scan, int $eventId): array {
        $this->logger->log('Recovery entered maintenance mode.', ['reason' => $reason, 'event_id' => $eventId, 'scan_status' => $scan['status'] ?? 'unknown'], 'error', 'preboot');
        return [
            'status' => $this->policy->maintenanceOnFailure() ? 'maintenance' : 'failed',
            'reason' => $reason,
            'event_id' => $eventId,
            'scan' => $scan,
        ];
    }
}

if (!function_exists('metis_recovery_service')) {
    function metis_recovery_service(): PrebootIntegrityService {
        return new PrebootIntegrityService();
    }
}

if (!function_exists('metis_recovery_render_maintenance_page')) {
    /** @param array<string,mixed> $result */
    function metis_recovery_render_maintenance_page(array $result): void {
        http_response_code(503);
        $eventId = (int) ($result['event_id'] ?? 0);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Metis Maintenance</title><style>';
        echo 'body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(135deg,#18243d,#25395d 52%,#f7f9ff 52%);color:#172033;min-height:100vh;display:grid;place-items:center;padding:32px}';
        echo '.card{width:min(920px,100%);display:grid;grid-template-columns:minmax(260px,1fr) minmax(280px,1fr);background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 24px 70px rgba(20,31,54,.18)}';
        echo '.side{background:rgba(23,36,61,.96);color:#dbe6ff;padding:44px}.main{padding:44px}.eyebrow{letter-spacing:.18em;text-transform:uppercase;font-size:12px;color:#9eb5e8}.badge{display:inline-block;margin-top:24px;padding:9px 14px;border:1px solid rgba(255,255,255,.22);border-radius:999px}.main h1{font-size:54px;line-height:.95;margin:0 0 18px}.main p{font-size:18px;line-height:1.55;color:#60708f}.label{margin-top:30px;letter-spacing:.16em;text-transform:uppercase;font-size:12px;color:#9eb5e8}.value{margin-top:8px;font-weight:700}@media(max-width:760px){.card{grid-template-columns:1fr}.main h1{font-size:40px}}';
        echo '</style></head><body><main class="card"><section class="side"><div class="eyebrow">Metis Recovery Notice</div><div class="badge">Protected Failure</div><p style="margin-top:28px;line-height:1.6">The system detected a startup integrity issue and could not complete automatic recovery.</p></section>';
        echo '<section class="main"><h1>503</h1><h2>The request could not be completed safely.</h2><p>Metis stopped normal boot before returning the expected page. Review recovery logs and the latest recovery event for details.</p>';
        echo '<div class="label">Recovery Event</div><div class="value">' . htmlspecialchars($eventId > 0 ? (string) $eventId : 'Not recorded', ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="label">Recommended Action</div><div class="value">Review storage/logs/recovery and backup recovery reports.</div></section></main></body></html>';
    }
}
