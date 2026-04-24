<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Cache\CacheService;

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
        $scan = $this->integrity->scan($trigger);
        $result = [
            'status' => (string) ($scan['status'] ?? 'unknown'),
            'scan' => $scan,
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

        return $result;
    }
}
