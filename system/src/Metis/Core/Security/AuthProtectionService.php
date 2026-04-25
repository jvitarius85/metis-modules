<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Cache\CacheService;
use Metis\Core\Services\ConfigService;

final class AuthProtectionService {
    private const GENERIC_FAILURE = 'Unable to sign in with those credentials.';
    private const GENERIC_RETRY = 'Too many sign-in attempts. Please wait a few minutes and try again.';

    public function __construct(
        private readonly SecurityKernel $kernel,
        private readonly ConfigService $config = new ConfigService()
    ) {}

    public function assertRequestRateLimit(string $bucket = 'password_login', int $limit = 0, int $window = 0): void {
        $context = $this->kernel->buildContext(
            'auth.rate_limit',
            [ 'identifier' => '' ],
            [ 'auth_method' => 'password' ]
        );

        $resolvedLimit = $limit > 0 ? $limit : max(1, (int) $this->config->get('auth_ip_rate_limit_per_minute', 20));
        $resolvedWindow = $window > 0 ? $window : 60;
        if ($this->kernel->rateLimiter()->consume('auth.rate.' . metis_key_clean($bucket) . '.' . $context->ipAddress(), $resolvedLimit, $resolvedWindow)) {
            return;
        }

        $this->kernel->threatScores()->recordEvent('rate_limit_violation', $context, 15);
        $this->kernel->audit()->security('auth_login_throttled', $context, [
            'bucket' => $bucket,
            'limit' => $resolvedLimit,
            'window' => $resolvedWindow,
        ]);

        throw new \RuntimeException(self::GENERIC_RETRY);
    }

    public function assertLoginAllowed(string $identifier, ?array $user = null): void {
        $context = $this->loginContext($identifier, $user);
        $subject = $this->subjectKey($identifier, $user);
        $subjectFailures = $this->failureCount('subject', $subject);
        $ip = $context->ipAddress();
        $ipFailures = $ip !== '' ? $this->failureCount('ip', $ip) : 0;

        $subjectLockThreshold = max(1, (int) $this->config->get('auth_login_lock_threshold_subject', 10));
        $ipLockThreshold = max(1, (int) $this->config->get('auth_login_lock_threshold_ip', 30));

        if ($subjectFailures >= $subjectLockThreshold || $ipFailures >= $ipLockThreshold) {
            $this->kernel->audit()->security('auth_login_throttled', $context, [
                'reason' => 'failure_threshold',
                'subject_failures' => $subjectFailures,
                'subject_threshold' => $subjectLockThreshold,
                'ip_failures' => $ipFailures,
                'ip_threshold' => $ipLockThreshold,
            ]);
            throw new \RuntimeException(self::GENERIC_RETRY);
        }

        $this->applyProgressiveDelay($subjectFailures, $ipFailures);

        $score = $this->kernel->threatScores()->currentScore($context);
        $level = $this->kernel->threatScores()->responseLevel($score);

        if (in_array($level, [ 'lockout', 'block' ], true)) {
            $this->kernel->audit()->security('auth_login_throttled', $context, [ 'score' => $score ]);
            throw new \RuntimeException(self::GENERIC_RETRY);
        }
    }

    public function recordFailedLogin(string $identifier, ?array $user = null): void {
        $context = $this->loginContext($identifier, $user);
        $this->incrementFailureCount('subject', $this->subjectKey($identifier, $user));

        $ip = $context->ipAddress();
        if ($ip !== '') {
            $this->incrementFailureCount('ip', $ip);
        }

        $subjectFailures = $this->failureCount('subject', $this->subjectKey($identifier, $user));
        $ipFailures = $ip !== '' ? $this->failureCount('ip', $ip) : 0;

        $score = $this->kernel->threatScores()->recordEvent('failed_login', $context);
        $this->kernel->audit()->security('auth_failed_login', $context, [
            'score' => $score,
            'subject_failures' => $subjectFailures,
            'ip_failures' => $ipFailures,
        ], 'warning', 'rejected');
    }

    public function clearFailedLogins(string $identifier, ?array $user = null): void {
        $context = $this->loginContext($identifier, $user);
        CacheService::forget($this->failureKey('subject', $this->subjectKey($identifier, $user)));

        $ip = $context->ipAddress();
        if ($ip !== '') {
            CacheService::forget($this->failureKey('ip', $ip));
        }
    }

    public function invalidateUserSessions(int $authUserId): void {
        if ($authUserId < 1) {
            return;
        }

        CacheService::set('auth.sessions.invalid_after.user.' . $authUserId, time(), 31536000);
    }

    public function sessionInvalidAfter(int $authUserId): int {
        if ($authUserId < 1) {
            return 0;
        }

        return (int) CacheService::get('auth.sessions.invalid_after.user.' . $authUserId);
    }

    public function genericFailureMessage(): string {
        return self::GENERIC_FAILURE;
    }

    private function loginContext(string $identifier, ?array $user): SecurityContext {
        return $this->kernel->buildContext(
            'auth.password_login',
            [ 'identifier' => $identifier, 'auth_method' => 'password' ],
            [ 'identifier' => $identifier, 'auth_method' => 'password' ],
            [ 'id' => (int) ($user['id'] ?? 0) ]
        );
    }

    private function incrementFailureCount(string $scope, string $subject): void {
        if ($subject === '') {
            return;
        }

        $key = $this->failureKey($scope, $subject);
        $count = (int) CacheService::get($key);
        $count++;
        CacheService::set($key, $count, max(60, (int) $this->config->get('auth_login_lock_seconds', 900)));
    }

    private function subjectKey(string $identifier, ?array $user): string {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            return 'user_' . $userId;
        }

        $normalized = trim(strtolower((string) metis_email_clean($identifier)));
        if ($normalized !== '' && metis_email_is_valid($normalized)) {
            return 'email:' . $normalized;
        }

        return 'login:' . metis_key_clean($identifier);
    }

    private function failureKey(string $scope, string $subject): string {
        return 'security.auth.failures.' . metis_key_clean($scope) . '.' . sha1($subject);
    }

    private function failureCount(string $scope, string $subject): int {
        if ($subject === '') {
            return 0;
        }

        return max(0, (int) CacheService::get($this->failureKey($scope, $subject)));
    }

    private function applyProgressiveDelay(int $subjectFailures, int $ipFailures): void {
        $failureCount = max($subjectFailures, $ipFailures);
        $startAfter = max(1, (int) $this->config->get('auth_login_delay_start_after', 2));

        if ($failureCount <= $startAfter) {
            return;
        }

        $baseMs = max(10, (int) $this->config->get('auth_login_delay_base_ms', 250));
        $maxMs = max($baseMs, (int) $this->config->get('auth_login_delay_max_ms', 2000));
        $steps = min(8, $failureCount - $startAfter);
        $multiplier = 1 << max(0, $steps - 1);
        $delayMs = min($maxMs, $baseMs * $multiplier);
        if ($delayMs <= 0) {
            return;
        }

        usleep($delayMs * 1000);
    }
}
