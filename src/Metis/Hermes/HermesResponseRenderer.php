<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesResponseRenderer {
    public function awaitingApproval( array $intent, array $plan, array $contextPacks ): array {
        $message = sprintf( '%s operation detected. Approval required.', $this->humanizeOperation( (string) ( $plan['operation'] ?? $intent['action'] ?? 'action' ) ) );
        return [
            'intent'             => (string) ( $intent['action'] ?? 'unknown' ),
            'status'             => 'awaiting_approval',
            'context_packs'      => $this->packTitles( $contextPacks ),
            'action_plan'        => array_values( (array) ( $plan['steps'] ?? [] ) ),
            'permission_required'=> (string) ( $plan['required_permission'] ?? '' ),
            'approval_required'  => true,
            'response_type'      => 'ApprovalPrompt',
            'ui_components'      => [
                [ 'type' => 'ActionPlanCard', 'operation' => (string) ( $plan['title'] ?? '' ), 'steps' => array_values( (array) ( $plan['steps'] ?? [] ) ), 'permission_required' => (string) ( $plan['required_permission'] ?? '' ) ],
                [ 'type' => 'ApprovalPrompt', 'buttons' => [ 'Approve', 'Cancel' ] ],
            ],
            'message' => $message,
        ];
    }

    public function denied( array $intent, array $plan, array $contextPacks, string $reason ): array {
        return [
            'intent'             => (string) ( $intent['action'] ?? 'unknown' ),
            'status'             => 'denied',
            'reason'             => $reason,
            'context_packs'      => $this->packTitles( $contextPacks ),
            'action_plan'        => array_values( (array) ( $plan['steps'] ?? [] ) ),
            'permission_required'=> (string) ( $plan['required_permission'] ?? '' ),
            'response_type'      => 'ErrorNotice',
            'ui_components'      => [ [ 'type' => 'ErrorNotice', 'message' => $reason ] ],
            'message'            => $reason,
        ];
    }

    public function error( array $intent, string $message ): array {
        return [
            'intent'        => (string) ( $intent['action'] ?? 'unknown' ),
            'status'        => 'error',
            'response_type' => 'ErrorNotice',
            'ui_components' => [ [ 'type' => 'ErrorNotice', 'message' => $message ] ],
            'message'       => $message,
        ];
    }

    public function executionResult( array $command, array $contextPacks, array $plan, array $result ): array {
        $showPlan  = empty( $command['read_only'] );
        $showCtx   = empty( $command['read_only'] );

        // Propagate inner result status — never claim success when the service returned an error
        $innerStatus = (string) ( $result['status'] ?? '' );
        $topStatus   = ( $innerStatus === 'error' || $innerStatus === 'failed' ) ? 'error' : 'success';

        $innerMessage = (string) ( $result['message'] ?? '' );
        $opTitle      = (string) ( $plan['title'] ?? $command['key'] ?? '' );
        $message      = $innerMessage !== ''
            ? $innerMessage
            : ( $topStatus === 'error'
                ? sprintf( '%s could not complete.', $opTitle ?: 'Operation' )
                : sprintf( '%s completed.', $opTitle ?: $this->humanizeOperation( (string) ( $command['key'] ?? '' ) ) ) );

        return [
            'intent'        => (string) ( $command['key'] ?? $plan['operation'] ?? 'unknown' ),
            'status'        => $topStatus,
            'context_packs' => $showCtx  ? $this->packTitles( $contextPacks ) : [],
            'action_plan'   => $showPlan ? array_values( (array) ( $plan['steps'] ?? [] ) ) : [],
            'result'        => $result,
            'response_type' => 'ExecutionResult',
            'ui_components' => [ [ 'type' => 'ExecutionResult', 'operation' => $opTitle, 'result' => $result ] ],
            'message'       => $message,
        ];
    }

    private function packTitles( array $contextPacks ): array {
        return array_values( array_filter( array_map(
            static fn ( array $pack ): string => (string) ( $pack['title'] ?? $pack['key'] ?? '' ),
            $contextPacks
        ) ) );
    }

    private function humanizeOperation( string $operation ): string {
        return ucwords( str_replace( '_', ' ', $operation ) );
    }
}