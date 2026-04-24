<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Security\SecureEnclave\SecurityPolicy;

final class SecurityContext {
    public function __construct(
        private readonly string $operation,
        private readonly array $actor = [],
        private readonly array $meta = [],
        private readonly array $input = [],
        private readonly ?SecurityPolicy $policy = null,
        private readonly array $attributes = []
    ) {}

    public function operation(): string {
        return $this->operation;
    }

    public function actor(): array {
        return $this->actor;
    }

    public function meta(): array {
        return $this->meta;
    }

    public function input(): array {
        return $this->input;
    }

    public function policy(): ?SecurityPolicy {
        return $this->policy;
    }

    public function attributes(): array {
        return $this->attributes;
    }

    public function identifier(): string {
        return trim((string) ($this->input['identifier'] ?? $this->meta['identifier'] ?? ''));
    }

    public function ipAddress(): string {
        return trim((string) ($this->meta['ip'] ?? ''));
    }

    public function userAgent(): string {
        return trim((string) ($this->meta['user_agent'] ?? ''));
    }

    public function sessionId(): string {
        return trim((string) ($this->actor['session_id'] ?? ''));
    }

    public function traceId(): string {
        return trim((string) ($this->meta['trace_id'] ?? $this->meta['request_id'] ?? ''));
    }

    public function authMethod(): string {
        return metis_key_clean((string) ($this->meta['auth_method'] ?? $this->input['auth_method'] ?? ''));
    }

    public function userId(): int {
        return (int) ($this->actor['id'] ?? 0);
    }

    public function withPolicy(SecurityPolicy $policy): self {
        return new self($this->operation, $this->actor, $this->meta, $this->input, $policy, $this->attributes);
    }

    public function withMeta(array $meta): self {
        return new self($this->operation, $this->actor, array_replace($this->meta, $meta), $this->input, $this->policy, $this->attributes);
    }

    public function withInput(array $input): self {
        return new self($this->operation, $this->actor, $this->meta, array_replace($this->input, $input), $this->policy, $this->attributes);
    }

    public function withAttribute(string $key, mixed $value): self {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($this->operation, $this->actor, $this->meta, $this->input, $this->policy, $attributes);
    }

    public function attribute(string $key, mixed $default = null): mixed {
        return $this->attributes[$key] ?? $default;
    }
}
