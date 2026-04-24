<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Cache\CacheService;

final class RateLimiter {
    public function consume(string $bucket, int $limit, int $windowSeconds): bool {
        $limit = max(1, $limit);
        $entries = $this->recentEntries($bucket, $windowSeconds);
        $entries[] = time();
        CacheService::set($this->cacheKey($bucket), $entries, max(60, $windowSeconds));

        return count($entries) <= $limit;
    }

    public function hitCount(string $bucket, int $windowSeconds): int {
        return count($this->recentEntries($bucket, $windowSeconds));
    }

    public function clear(string $bucket): void {
        CacheService::forget($this->cacheKey($bucket));
    }

    private function recentEntries(string $bucket, int $windowSeconds): array {
        $now = time();

        return array_values(array_filter(
            array_map('intval', (array) CacheService::get($this->cacheKey($bucket))),
            static fn (int $timestamp): bool => $timestamp > ($now - max(1, $windowSeconds))
        ));
    }

    private function cacheKey(string $bucket): string {
        return 'security.rate.' . sha1($bucket);
    }
}
