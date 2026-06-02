<?php
declare(strict_types=1);

namespace Metis\Hermes\Conversation;

final class ConversationContext {
    public function __construct(
        private readonly string $sessionCode,
        private readonly string $lastIntent,
        private readonly array $conversationState = [],
        private readonly array $recentEntity = [],
        private readonly array $pendingWorkflow = [],
        private readonly array $pendingDisambiguation = [],
        private readonly array $pendingAction = []
    ) {}

    public function toRuntimeContext( array $runtimeContext = [] ): array {
        $runtimeContext['session_code'] = $this->sessionCode;
        $runtimeContext['last_intent'] = $this->lastIntent;

        if ( $this->conversationState !== [] ) {
            $runtimeContext['conversation_state'] = $this->conversationState;
        }
        if ( $this->recentEntity !== [] ) {
            $runtimeContext['recent_entity'] = $this->recentEntity;
        }
        if ( $this->pendingWorkflow !== [] ) {
            $runtimeContext['pending_workflow'] = $this->pendingWorkflow;
        }
        if ( $this->pendingDisambiguation !== [] ) {
            $runtimeContext['pending_disambiguation'] = $this->pendingDisambiguation;
        }
        if ( $this->pendingAction !== [] ) {
            $runtimeContext['pending_action'] = $this->pendingAction;
        }

        return $runtimeContext;
    }
}
