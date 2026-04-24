<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Cache\CacheService;

final class ThreatScoreStore {
    public function increment(string $scope, string $subject, int $weight, int $ttl = 86400): int {
        $record = $this->record($scope, $subject);
        $record = $this->decayRecord($record);
        $record['score'] += max(0, $weight);
        $record['updated_at'] = time();
        CacheService::set($this->key($scope, $subject), $record, $ttl);

        return (int) round($record['score']);
    }

    public function score(string $scope, string $subject): int {
        $record = $this->decayRecord($this->record($scope, $subject));
        CacheService::set($this->key($scope, $subject), $record, 86400);

        return (int) round($record['score']);
    }

    public function clear(string $scope, string $subject): void {
        CacheService::forget($this->key($scope, $subject));
    }

    private function record(string $scope, string $subject): array {
        $record = CacheService::get($this->key($scope, $subject));

        return is_array($record) ? $record : [
            'score' => 0,
            'updated_at' => time(),
        ];
    }

    private function decayRecord(array $record): array {
        $score = (float) ($record['score'] ?? 0);
        $updatedAt = (int) ($record['updated_at'] ?? time());
        $elapsedMinutes = max(0, (int) floor((time() - $updatedAt) / 60));
        if ($elapsedMinutes > 0) {
            $score = max(0, $score - $elapsedMinutes);
        }

        return [
            'score' => $score,
            'updated_at' => time(),
        ];
    }

    private function key(string $scope, string $subject): string {
        return 'security.threat.' . metis_key_clean($scope) . '.' . sha1($subject);
    }
}
