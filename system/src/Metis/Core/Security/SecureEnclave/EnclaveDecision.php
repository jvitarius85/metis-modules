<?php
declare(strict_types=1);

namespace Metis\Core\Security\SecureEnclave;

final class EnclaveDecision {
    public function __construct(
        private readonly bool $allowed,
        private readonly string $message = '',
        private readonly string $code = 'ok',
        private readonly int $status = 200,
        private readonly array $context = []
    ) {}

    public static function allow(array $context = []): self {
        return new self(true, '', 'ok', 200, $context);
    }

    public static function deny(string $message, string $code, int $status = 403, array $context = []): self {
        return new self(false, $message, $code, $status, $context);
    }

    public function allowed(): bool {
        return $this->allowed;
    }

    public function message(): string {
        return $this->message;
    }

    public function code(): string {
        return $this->code;
    }

    public function status(): int {
        return $this->status;
    }

    public function context(): array {
        return $this->context;
    }
}
