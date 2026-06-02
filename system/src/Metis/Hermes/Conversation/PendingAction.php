<?php
declare(strict_types=1);

namespace Metis\Hermes\Conversation;

final class PendingAction {
    public function __construct(
        private readonly string $actionCode,
        private readonly string $actionType,
        private readonly string $title,
        private readonly string $approvalStatus
    ) {}

    public static function fromArray( array $action ): self {
        return new self(
            (string) ( $action['action_code'] ?? '' ),
            (string) ( $action['action_type'] ?? '' ),
            (string) ( $action['title'] ?? '' ),
            (string) ( $action['approval_status'] ?? '' )
        );
    }

    public function toArray(): array {
        return [
            'action_code' => $this->actionCode,
            'action_type' => $this->actionType,
            'title' => $this->title,
            'approval_status' => $this->approvalStatus,
        ];
    }
}
