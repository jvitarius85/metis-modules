<?php
declare(strict_types=1);

namespace Metis\Hermes\Conversation;

use Metis\Hermes\HermesMemoryStore;
use Metis\Hermes\HermesRepository;

final class ConversationStore {
    public function __construct(
        private readonly HermesRepository $repository,
        private readonly HermesMemoryStore $memory
    ) {}

    public function openSession( int $userId, string $sessionCode, string $title ): array {
        return $this->repository->ensureSession( $userId, $sessionCode, $title );
    }

    public function saveUserMessage( int $sessionId, string $query ): array {
        return $this->repository->saveMessage( $sessionId, 'user', $query );
    }

    public function touchSession( int $sessionId, string $intent ): void {
        $this->repository->touchSession( $sessionId, $intent );
    }

    public function rememberConversation( string $sessionCode, array $summary ): void {
        $this->memory->rememberConversation( $sessionCode, $summary );
    }

    public function rememberRecentEntity( string $sessionCode, array $recentEntity ): void {
        $this->memory->rememberRecentEntity( $sessionCode, $recentEntity );
    }

    public function conversationState( string $sessionCode ): array {
        return $this->memory->recallConversation( $sessionCode );
    }

    public function recentEntity( string $sessionCode ): array {
        return $this->memory->recallRecentEntity( $sessionCode );
    }

    public function pendingWorkflow( string $sessionCode ): array {
        return (array) ( $this->memory->recallPendingWorkflow( $sessionCode )['contents'] ?? [] );
    }

    public function pendingDisambiguation( string $sessionCode ): array {
        return (array) ( $this->memory->recallPendingDisambiguation( $sessionCode )['contents'] ?? [] );
    }

    public function pendingAction( int $sessionId ): array {
        return (array) ( $this->repository->latestPendingActionForSession( $sessionId ) ?? [] );
    }
}
