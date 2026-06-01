<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesConversationStateEngine {
    public function __construct(
        private readonly HermesRepository $repository,
        private readonly HermesMemoryStore $memory
    ) {}

    public function openTurn( int $userId, string $query, string $sessionCode = '' ): array {
        $session = $this->repository->ensureSession( $userId, $sessionCode, $this->deriveTitle( $query ) );
        $userMessage = $this->repository->saveMessage( (int) ( $session['id'] ?? 0 ), 'user', $query );

        return [
            'session' => $session,
            'user_message' => $userMessage,
        ];
    }

    public function hydrateRuntimeContext( array $session, array $runtimeContext = [] ): array {
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        $runtimeContext['session_code'] = $sessionCode;
        $runtimeContext['last_intent'] = trim( (string) ( $session['last_intent'] ?? '' ) );

        if ( $sessionCode !== '' ) {
            $conversation = $this->memory->recallConversation( $sessionCode );
            if ( $conversation !== [] ) {
                $runtimeContext['conversation_state'] = $conversation;
            }
        }

        return $runtimeContext;
    }

    public function completeTurn( array $session, string $query, array $processed, array $response ): void {
        $sessionId = (int) ( $session['id'] ?? 0 );
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        $intent = trim( (string) ( $processed['intent']['action'] ?? 'conversation' ) );

        if ( $sessionId > 0 ) {
            $this->repository->touchSession( $sessionId, $intent );
        }

        if ( $sessionCode === '' ) {
            return;
        }

        $this->memory->rememberConversation( $sessionCode, [
            'query' => $query,
            'answer' => (string) ( $response['message'] ?? '' ),
            'intent' => $intent,
            'top_level_intent' => (string) ( $processed['intent']['top_level_intent'] ?? '' ),
            'response_type' => (string) ( $response['response_type'] ?? '' ),
            'status' => (string) ( $response['status'] ?? '' ),
        ] );
    }

    private function deriveTitle( string $query ): string {
        $clean = trim( preg_replace( '/\s+/', ' ', $query ) ?? $query );
        if ( $clean === '' ) {
            return 'Hermes Session';
        }

        return mb_substr( $clean, 0, 60 );
    }
}
