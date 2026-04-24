<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class EntityResolutionResult implements \JsonSerializable {
    private const VALID_STATUS = [ 'resolved', 'ambiguous', 'not_found' ];
    private const VALID_CONFIDENCE = [ 'high', 'medium', 'low', 'none' ];

    /**
     * @param array<string, mixed> $match
     * @param array<int, array<string, mixed>> $candidates
     */
    public function __construct(
        private readonly string $status,
        private readonly string $confidence,
        private readonly string $entityType,
        private readonly array $match,
        private readonly array $candidates,
        private readonly string $message
    ) {
        if ( ! in_array( $this->status, self::VALID_STATUS, true ) ) {
            throw new \InvalidArgumentException( 'Invalid entity resolution status.' );
        }

        if ( ! in_array( $this->confidence, self::VALID_CONFIDENCE, true ) ) {
            throw new \InvalidArgumentException( 'Invalid entity resolution confidence.' );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'status' => $this->status,
            'confidence' => $this->confidence,
            'entity_type' => $this->entityType,
            'match' => $this->match,
            'candidates' => $this->candidates,
            'message' => $this->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return $this->toArray();
    }

    public function status(): string {
        return $this->status;
    }

    public function confidence(): string {
        return $this->confidence;
    }

    public function entityType(): string {
        return $this->entityType;
    }

    /**
     * @return array<string, mixed>
     */
    public function match(): array {
        return $this->match;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidates(): array {
        return $this->candidates;
    }

    public function message(): string {
        return $this->message;
    }
}
