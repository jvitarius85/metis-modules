<?php
declare(strict_types=1);

namespace Metis\Core\Security;

final class ThreatScoreEngine {
    private const WEIGHTS = [
        'failed_login' => 10,
        'rate_limit_violation' => 25,
        'invalid_nonce' => 15,
        'permission_violation' => 20,
        'behavior_anomaly' => 20,
    ];

    public function __construct(
        private readonly ThreatScoreStore $store = new ThreatScoreStore()
    ) {}

    public function recordEvent(string $event, SecurityContext $context, ?int $weight = null): int {
        $weight = $weight ?? (self::WEIGHTS[$event] ?? 0);
        $scores = [];

        $ip = $context->ipAddress();
        if ($ip !== '') {
            $scores[] = $this->store->increment('ip', $ip, $weight);
        }

        $userId = $context->userId();
        if ($userId > 0) {
            $scores[] = $this->store->increment('user', (string) $userId, $weight);
        }

        $fingerprint = (string) $context->attribute('request_fingerprint', '');
        if ($fingerprint !== '') {
            $scores[] = $this->store->increment('fingerprint', $fingerprint, $weight);
        }

        $sessionId = $context->sessionId();
        if ($sessionId !== '') {
            $scores[] = $this->store->increment('session', $sessionId, $weight);
        }

        return $scores === [] ? 0 : max($scores);
    }

    public function currentScore(SecurityContext $context): int {
        $scores = [];

        $ip = $context->ipAddress();
        if ($ip !== '') {
            $scores[] = $this->store->score('ip', $ip);
        }

        $userId = $context->userId();
        if ($userId > 0) {
            $scores[] = $this->store->score('user', (string) $userId);
        }

        $fingerprint = (string) $context->attribute('request_fingerprint', '');
        if ($fingerprint !== '') {
            $scores[] = $this->store->score('fingerprint', $fingerprint);
        }

        $sessionId = $context->sessionId();
        if ($sessionId !== '') {
            $scores[] = $this->store->score('session', $sessionId);
        }

        return $scores === [] ? 0 : max($scores);
    }

    public function responseLevel(int $score): string {
        return match (true) {
            $score > 100 => 'block',
            $score >= 75 => 'lockout',
            $score >= 50 => 'throttle',
            $score >= 25 => 'increased_logging',
            default => 'normal',
        };
    }

    public function clearAuthenticationRisk(SecurityContext $context): void {
        $ip = $context->ipAddress();
        if ($ip !== '') {
            $this->store->clear('ip', $ip);
        }

        $identifier = $context->identifier();
        if ($identifier !== '') {
            $this->store->clear('fingerprint', $identifier);
        }

        $userId = $context->userId();
        if ($userId > 0) {
            $this->store->clear('user', (string) $userId);
        }

        $sessionId = $context->sessionId();
        if ($sessionId !== '') {
            $this->store->clear('session', $sessionId);
        }
    }
}
