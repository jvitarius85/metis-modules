<?php
declare(strict_types=1);

namespace Metis\Hermes\Conversation;

final class ConversationStateManager {
    public function __construct(
        private readonly ConversationStore $store,
        private readonly ConversationResolver $resolver
    ) {}

    public function openTurn( int $userId, string $query, string $sessionCode = '' ): array {
        $session = $this->store->openSession( $userId, $sessionCode, $this->deriveTitle( $query ) );
        $userMessage = $this->store->saveUserMessage( (int) ( $session['id'] ?? 0 ), $query );

        return [
            'session' => $session,
            'user_message' => $userMessage,
        ];
    }

    public function hydrateRuntimeContext( array $session, array $runtimeContext = [] ): array {
        return $this->resolver->hydrateContext( $session, $runtimeContext );
    }

    public function completeTurn( array $session, string $query, array $processed, array $response ): void {
        $sessionId = (int) ( $session['id'] ?? 0 );
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        $intent = trim( (string) ( $processed['intent']['action'] ?? 'conversation' ) );

        if ( $sessionId > 0 ) {
            $this->store->touchSession( $sessionId, $intent );
        }

        if ( $sessionCode === '' ) {
            return;
        }

        $this->store->rememberConversation( $sessionCode, [
            'query' => $query,
            'answer' => (string) ( $response['message'] ?? '' ),
            'intent' => $intent,
            'top_level_intent' => (string) ( $processed['intent']['top_level_intent'] ?? '' ),
            'response_type' => (string) ( $response['response_type'] ?? '' ),
            'status' => (string) ( $response['status'] ?? '' ),
        ] );

        $recentEntity = $this->resolver->extractRecentEntity( $processed, $response );
        if ( $recentEntity !== [] ) {
            $this->store->rememberRecentEntity( $sessionCode, $recentEntity );
        }
    }

    private function deriveTitle( string $query ): string {
        $clean = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
        if ( $clean === '' ) {
            return 'Hermes Session';
        }

        return mb_substr( $clean, 0, 60 );
    }
}
