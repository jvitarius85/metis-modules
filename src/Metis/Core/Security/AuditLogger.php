<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Services\AuditLogService;
use Metis\Core\Services\LoggerService;

final class AuditLogger {
    public function __construct(
        private readonly AuditLogService $audit = new AuditLogService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function activity(string $event, SecurityContext $context, array $payload = []): void {
        $this->audit->activity($event, $this->normalizePayload($context, $payload), [
            'user_id' => $context->userId() > 0 ? $context->userId() : null,
            'request_id' => $context->traceId(),
        ]);

        $this->logger->info($event, $this->normalizePayload($context, $payload));
    }

    public function security(string $event, SecurityContext $context, array $payload = [], string $severity = 'warning', string $outcome = 'blocked'): void {
        $this->audit->security($event, $this->normalizePayload($context, $payload), $severity, $outcome, [
            'user_id' => $context->userId() > 0 ? $context->userId() : null,
            'request_id' => $context->traceId(),
        ]);

        $this->logger->warn($event, $this->normalizePayload($context, $payload));
    }

    private function normalizePayload(SecurityContext $context, array $payload): array {
        return array_replace([
            'timestamp' => gmdate('c'),
            'operation' => $context->operation(),
            'trace_id' => $context->traceId(),
            'user_id' => $context->userId(),
            'ip_address' => $context->ipAddress(),
            'auth_method' => $context->authMethod(),
        ], $payload);
    }
}
