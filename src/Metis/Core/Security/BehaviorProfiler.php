<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Cache\CacheService;

final class BehaviorProfiler {
    public function profile(SecurityContext $context): array {
        $anomalies = [];
        $score = 0;
        $fingerprint = (string) $context->attribute('request_fingerprint', '');
        if ($fingerprint === '') {
            return [ 'score' => 0, 'anomalies' => [] ];
        }

        $burstBucket = 'security.behavior.burst.' . sha1($fingerprint);
        $endpointBucket = 'security.behavior.endpoints.' . sha1($fingerprint);
        $burstCount = $this->recordTimestamp($burstBucket, 60);
        if ($burstCount >= 30) {
            $anomalies[] = 'rapid_request_burst';
            $score += 10;
        }

        $endpointCount = $this->recordEndpoint($endpointBucket, $context->operation(), 300);
        if ($endpointCount >= 12) {
            $anomalies[] = 'unusual_endpoint_access_pattern';
            $score += 10;
        }

        $sessionId = $context->sessionId();
        if ($sessionId !== '') {
            $ipKey = 'security.behavior.session_ip.' . sha1($sessionId);
            $lastIp = (string) CacheService::get($ipKey);
            $currentIp = $context->ipAddress();
            if ($lastIp !== '' && $currentIp !== '' && ! hash_equals($lastIp, $currentIp)) {
                $anomalies[] = 'sudden_ip_change';
                $score += 10;
            }
            CacheService::set($ipKey, $currentIp, 86400);

            $methodKey = 'security.behavior.auth_method.' . sha1($sessionId);
            $lastMethod = (string) CacheService::get($methodKey);
            $currentMethod = $context->authMethod();
            if ($lastMethod !== '' && $currentMethod !== '' && ! hash_equals($lastMethod, $currentMethod)) {
                $anomalies[] = 'authentication_method_changed';
                $score += 10;
            }
            if ($currentMethod !== '') {
                CacheService::set($methodKey, $currentMethod, 86400);
            }
        }

        return [
            'score' => min(20, $score),
            'anomalies' => array_values(array_unique($anomalies)),
        ];
    }

    private function recordTimestamp(string $key, int $window): int {
        $now = time();
        $entries = array_values(array_filter(
            array_map('intval', (array) CacheService::get($key)),
            static fn (int $timestamp): bool => $timestamp > ($now - $window)
        ));
        $entries[] = $now;
        CacheService::set($key, $entries, max(60, $window));

        return count($entries);
    }

    private function recordEndpoint(string $key, string $operation, int $window): int {
        $entries = (array) CacheService::get($key);
        $entries[metis_key_clean($operation)] = time();
        $threshold = time() - $window;
        foreach ($entries as $entry => $timestamp) {
            if ((int) $timestamp <= $threshold) {
                unset($entries[$entry]);
            }
        }
        CacheService::set($key, $entries, $window);

        return count($entries);
    }
}
