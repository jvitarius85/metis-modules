<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class IntegrityService {
    public function ensureRuntime(): void {
        if (\class_exists('Metis_Integrity_Manager')) {
            \Metis_Integrity_Manager::ensure_runtime();
        }
    }

    public function verifyBaseline(): array {
        if (\class_exists('Metis_Integrity_Manager')) {
            return (array) \Metis_Integrity_Manager::verify_baseline();
        }

        return ['ok' => false, 'status' => 'unavailable'];
    }

    public function scan(string $trigger = 'manual'): array {
        if (\class_exists('Metis_Integrity_Manager')) {
            return (array) \Metis_Integrity_Manager::scan_and_heal($trigger);
        }

        return ['status' => 'unavailable', 'issues' => []];
    }
}
