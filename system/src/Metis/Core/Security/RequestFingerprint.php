<?php
declare(strict_types=1);

namespace Metis\Core\Security;

final class RequestFingerprint {
    public function generate(SecurityContext $context): string {
        $payload = [
            'ip' => $context->ipAddress(),
            'user_agent' => substr($context->userAgent(), 0, 255),
            'session_id' => $context->sessionId(),
            'auth_method' => $context->authMethod(),
            'velocity' => (int) $context->attribute('request_velocity', 0),
        ];

        return hash('sha256', $this->encode($payload));
    }

    public function sessionIntegrityFingerprint(SecurityContext $context): string {
        $payload = [
            'auth_user_id' => $context->userId(),
            'person_id' => (int) ($context->actor()['person_id'] ?? 0),
            'session' => $context->sessionId(),
            'user_agent' => substr($context->userAgent(), 0, 255),
            'auth_method' => $context->authMethod(),
        ];

        return hash_hmac('sha256', $this->encode($payload), $this->secretKeyBytes());
    }

    public function matchesStored(SecurityContext $context, string $stored): bool {
        if ($stored === '') {
            return false;
        }

        return hash_equals($stored, $this->sessionIntegrityFingerprint($context));
    }

    private function secretKeyBytes(): string {
        return hash('sha256', (string) metis_runtime_config_get('app_key', 'metis-local-key'), true);
    }

    private function encode(array $payload): string {
        return (string) json_encode($payload);
    }
}
