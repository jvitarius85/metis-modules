<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Hermes\Conversation\ConversationStateManager;

final class HermesConversationStateEngine {
    public function __construct(
        private readonly ConversationStateManager $manager
    ) {}

    public function openTurn( int $userId, string $query, string $sessionCode = '' ): array {
        return $this->manager->openTurn( $userId, $query, $sessionCode );
    }

    public function hydrateRuntimeContext( array $session, array $runtimeContext = [] ): array {
        return $this->manager->hydrateRuntimeContext( $session, $runtimeContext );
    }

    public function completeTurn( array $session, string $query, array $processed, array $response ): void {
        $this->manager->completeTurn( $session, $query, $processed, $response );
    }
}
