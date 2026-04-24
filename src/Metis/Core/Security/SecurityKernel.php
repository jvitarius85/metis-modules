<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Services\ConfigService;

final class SecurityKernel {
    public function __construct(
        private readonly NonceManager $nonce,
        private readonly CsrfManager $csrf,
        private readonly RateLimiter $rateLimiter,
        private readonly RequestFingerprint $fingerprint,
        private readonly ThreatScoreEngine $threatScores,
        private readonly ThreatScoreStore $threatStore,
        private readonly AuditLogger $audit,
        private readonly BehaviorProfiler $behavior,
        private readonly ConfigService $config = new ConfigService()
    ) {}

    public function nonce(): NonceManager {
        return $this->nonce;
    }

    public function csrf(): CsrfManager {
        return $this->csrf;
    }

    public function rateLimiter(): RateLimiter {
        return $this->rateLimiter;
    }

    public function fingerprints(): RequestFingerprint {
        return $this->fingerprint;
    }

    public function threatScores(): ThreatScoreEngine {
        return $this->threatScores;
    }

    public function threatStore(): ThreatScoreStore {
        return $this->threatStore;
    }

    public function audit(): AuditLogger {
        return $this->audit;
    }

    public function behavior(): BehaviorProfiler {
        return $this->behavior;
    }

    public function config(string $key, mixed $default = null): mixed {
        return $this->config->get($key, $default);
    }

    public function buildContext(string $operation, array $input = [], array $meta = [], array $actor = []): SecurityContext {
        $actor = array_replace($this->defaultActor(), $actor);
        $meta = array_replace($this->defaultMeta(), $meta);
        $context = new SecurityContext($operation, $actor, $meta, $input);
        $velocityBucket = 'security.velocity.' . sha1($operation . '|' . $context->ipAddress() . '|' . $context->sessionId());
        $velocity = $this->rateLimiter->hitCount($velocityBucket, 60);
        $context = $context->withAttribute('request_velocity', $velocity);
        $context = $context->withAttribute('request_fingerprint', $this->fingerprint->generate($context));

        return $context;
    }

    private function defaultActor(): array {
        $user = function_exists('metis_runtime_current_user') ? metis_runtime_current_user() : null;
        $sessionId = (string) ($_SESSION['metis_session_token'] ?? '');
        if ($sessionId === '' && function_exists('metis_runtime_session_token')) {
            $sessionId = (string) metis_runtime_session_token();
        }

        return [
            'id' => function_exists('metis_current_user_id') ? (int) metis_current_user_id() : 0,
            'person_id' => (int) ($_SESSION['metis_person_id'] ?? 0),
            'roles' => $user instanceof \MetisUser ? array_map('strval', (array) $user->roles) : [],
            'session_id' => $sessionId,
        ];
    }

    private function defaultMeta(): array {
        return [
            'ip' => function_exists('metis_audit_ip_address') ? (string) metis_audit_ip_address() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => function_exists('metis_audit_user_agent') ? (string) metis_audit_user_agent() : (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'trace_id' => function_exists('metis_audit_request_id') ? (string) metis_audit_request_id() : '',
        ];
    }
}
