<?php
declare(strict_types=1);

namespace Metis\Hermes\Conversation;

final class ConversationResolver {
    public function __construct(
        private readonly ConversationStore $store
    ) {}

    public function hydrateContext( array $session, array $runtimeContext = [] ): array {
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        $sessionId = (int) ( $session['id'] ?? 0 );

        $context = new ConversationContext(
            $sessionCode,
            trim( (string) ( $session['last_intent'] ?? '' ) ),
            $sessionCode !== '' ? $this->store->conversationState( $sessionCode ) : [],
            $sessionCode !== '' ? $this->store->recentEntity( $sessionCode ) : [],
            $sessionCode !== '' ? $this->store->pendingWorkflow( $sessionCode ) : [],
            $sessionCode !== '' ? $this->store->pendingDisambiguation( $sessionCode ) : [],
            $sessionId > 0 ? $this->pendingActionArray( $this->store->pendingAction( $sessionId ) ) : []
        );

        return $context->toRuntimeContext( $runtimeContext );
    }

    public function extractRecentEntity( array $processed, array $response ): array {
        $payload = (array) ( $processed['intent']['payload'] ?? [] );
        $intent = trim( (string) ( $processed['intent']['action'] ?? '' ) );

        $subject = trim( (string) ( $payload['subject'] ?? $payload['email'] ?? '' ) );
        $entityHint = trim( (string) ( $payload['entity_hint'] ?? '' ) );

        foreach ( [ 'profile_request', 'attribute_request' ] as $requestKey ) {
            $request = (array) ( $payload[ $requestKey ] ?? [] );
            if ( $subject === '' ) {
                $subject = trim( (string) ( $request['subject'] ?? '' ) );
            }
            if ( $entityHint === '' ) {
                $entityHint = trim( (string) ( $request['entity_hint'] ?? '' ) );
            }
        }

        if ( $subject === '' ) {
            return [];
        }

        if ( $entityHint === '' ) {
            $entityHint = (string) ( $response['entity'] ?? '' );
        }

        if ( $entityHint === '' ) {
            $entityHint = match ( $intent ) {
                'lookup_profile', 'get_user', 'disable_user', 'enable_user', 'update_user', 'assign_role', 'remove_role', 'manage_user_roles', 'offboard_user' => 'person',
                'get_entity_attribute' => 'auto',
                default => 'auto',
            };
        }

        return [
            'subject' => $subject,
            'entity_hint' => $entityHint,
            'intent' => $intent,
            'entity' => (string) ( $response['entity'] ?? '' ),
            'id' => (int) ( $response['id'] ?? 0 ),
        ];
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function pendingActionArray( array $action ): array {
        if ( $action === [] ) {
            return [];
        }

        return PendingAction::fromArray( $action )->toArray();
    }
}
