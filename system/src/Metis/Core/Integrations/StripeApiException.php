<?php
declare(strict_types=1);

namespace Metis\Core\Integrations;

final class StripeApiException extends \RuntimeException {
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $stripeType = null,
        private readonly ?string $stripeCode = null,
        private readonly ?string $requestId = null,
        private readonly array $details = [],
        private readonly bool $retryable = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function httpStatus(): int {
        return $this->httpStatus;
    }

    public function stripeType(): ?string {
        return $this->stripeType;
    }

    public function stripeCode(): ?string {
        return $this->stripeCode;
    }

    public function requestId(): ?string {
        return $this->requestId;
    }

    public function details(): array {
        return $this->details;
    }

    public function isRetryable(): bool {
        return $this->retryable;
    }
}
