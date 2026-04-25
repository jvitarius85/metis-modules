<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Cache\CacheService;
use Metis\Core\Recovery\BackupRecoveryService;
use Metis\Core\Recovery\GitRecoveryService;
use Metis\Core\Recovery\RecoveryAuditLogger;
use Metis\Core\Recovery\RecoveryLockService;
use Metis\Core\Recovery\RecoveryPlaybookService;
use Metis\Core\Recovery\RecoveryPolicyService;
use Metis\Core\Recovery\RecoverySchema;
use Metis\Core\Recovery\RecoveryVerifier;

final class SelfHealingService {
    public function __construct(
        private readonly IntegrityService $integrity,
        private readonly UpdateService $updates,
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function verifySystem(string $trigger = 'startup'): array {
        return $this->integrity->scan($trigger);
    }

    public function repairSystem(string $trigger = 'manual'): array {
        RecoverySchema::ensureSchema();
        $audit = new RecoveryAuditLogger();
        $locks = new RecoveryLockService();
        $verifier = new RecoveryVerifier();
        $playbooks = new RecoveryPlaybookService();
        $policy = new RecoveryPolicyService();
        $scan = $verifier->scan($trigger, false);
        if (!$policy->runtimeRecoveryEnabled() || !$policy->automaticFileRecoveryEnabled()) {
            return [
                'status' => 'disabled',
                'message' => 'Runtime recovery is disabled.',
                'scan' => $scan,
                'mutation_enabled' => false,
            ];
        }

        $issues = array_values((array) ($scan['issues'] ?? []));
        $firstIssue = is_array($issues[0] ?? null) ? (array) $issues[0] : [];
        $playbook = $playbooks->forIssueType((string) ($firstIssue['type'] ?? 'cache_corruption'));
        $eventId = $audit->createEvent([
            'event_code' => 'runtime_' . gmdate('Ymd_His'),
            'severity' => (string) ($firstIssue['severity'] ?? 'medium'),
            'trigger_source' => $trigger,
            'issue_type' => (string) ($firstIssue['type'] ?? 'runtime_self_heal'),
            'status' => 'started',
            'selected_playbook' => (string) ($playbook['playbook_key'] ?? ''),
            'started_at' => gmdate('Y-m-d H:i:s'),
            'result_summary' => ['scan' => $scan],
        ]);

        if (!$locks->acquire('runtime_self_heal')) {
            $audit->updateEvent($eventId, ['status' => 'locked', 'completed_at' => gmdate('Y-m-d H:i:s')]);
            return [
                'status' => 'locked',
                'message' => 'A recovery action is already running.',
                'scan' => $scan,
            ];
        }

        try {
            $audit->action($eventId, 'detect_classify_validate', 'runtime_self_heal', 'completed', ['playbook' => $playbook, 'issue_count' => count($issues)]);
            $backupResult = ['status' => 'skipped', 'reason' => 'no_integrity_issues'];
            $gitResult = ['status' => 'skipped', 'reason' => 'backup_recovery_not_needed'];
            if ($issues !== []) {
                $backupResult = (new BackupRecoveryService())->recoverIssues($issues, $eventId, $trigger);
                $verify = $verifier->verifyAfterRecovery($issues);
                if ((string) ($backupResult['status'] ?? '') !== 'success' || (string) ($verify['targeted_status'] ?? 'fail') !== 'pass') {
                    $gitResult = (new GitRecoveryService())->recoverIssues($issues, $eventId, $trigger);
                    $verify = $verifier->verifyAfterRecovery($issues);
                }
            } else {
                $verify = $scan;
            }

        $scan = $this->integrity->scan($trigger);
        $result = [
            'status' => (string) ($scan['status'] ?? 'unknown'),
            'scan' => $scan,
            'recovery_scan' => $verify,
            'backup_recovery' => $backupResult,
            'git_recovery' => $gitResult,
            'restored_from_release' => false,
            'caches_rebuilt' => false,
            'configuration_repaired' => false,
        ];

        if (in_array((string) ($scan['status'] ?? ''), ['issues_detected', 'manifest_missing', 'manifest_untrusted'], true)) {
            $update = $this->updates->checkForUpdates(false);
            $result['release'] = $update;
            $result['restored_from_release'] = !empty($update['download_url']) || !empty($scan['restored']);
        }

        $rebuilt = CacheService::rebuildSystemCaches();
        $result['caches_rebuilt'] = true;
        $result['configuration_repaired'] = !empty($rebuilt['configuration']);

        $this->logger->security('self_heal_run', [
            'trigger' => $trigger,
            'status' => $result['status'],
            'restored_count' => count((array) ($scan['restored'] ?? [])),
        ], 'warning', 'restored');
        $audit->updateEvent($eventId, [
            'status' => 'completed',
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'backup_reference' => (string) ($backupResult['backup_reference'] ?? ''),
            'git_reference' => (string) ($gitResult['git_reference'] ?? ''),
            'result_summary' => $result,
        ]);

        return $result;
        } finally {
            $locks->release('runtime_self_heal');
        }
    }
}
