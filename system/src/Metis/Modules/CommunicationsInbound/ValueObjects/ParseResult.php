<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\ValueObjects;

final class ParseResult {
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly bool $matched,
        private readonly string $classification,
        private readonly string $parser_key,
        private readonly string $handler_key,
        private readonly float $confidence,
        private readonly array $metadata = []
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function matched(
        string $classification,
        string $parser_key,
        string $handler_key,
        float $confidence,
        array $metadata = []
    ): self {
        return new self( true, $classification, $parser_key, $handler_key, $confidence, $metadata );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function unknown( array $metadata = [] ): self {
        return new self( false, 'unknown', 'unknown', '', 0.0, $metadata );
    }

    public function matchedMessage(): bool {
        return $this->matched;
    }

    public function classification(): string {
        return $this->classification;
    }

    public function parserKey(): string {
        return $this->parser_key;
    }

    public function handlerKey(): string {
        return $this->handler_key;
    }

    public function confidence(): float {
        return $this->confidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'matched'        => $this->matched,
            'classification' => $this->classification,
            'parser_key'     => $this->parser_key,
            'handler_key'    => $this->handler_key,
            'confidence'     => $this->confidence,
            'metadata'       => $this->metadata,
        ];
    }
}
