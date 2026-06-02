<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesWorkflowContinuationEngine {
    private const WORKFLOW_TTL_SECONDS = 900;

    public function __construct(
        private readonly HermesRepository $repository,
        private readonly HermesApprovalEngine $approvals,
        private readonly HermesActionExecutor $executor,
        private readonly HermesAuditLogger $audit
    ) {}

    public function continueIfApplicable( string $query, array $session ): ?array {
        $decision = $this->decisionForQuery( $query );
        if ( $decision === '' ) {
            return null;
        }

        $sessionId = (int) ( $session['id'] ?? 0 );
        if ( $sessionId <= 0 ) {
            return null;
        }

        $action = $this->repository->latestPendingActionForSession( $sessionId );
        if ( ! is_array( $action ) ) {
            return null;
        }

        $actionCode = (string) ( $action['action_code'] ?? '' );
        if ( $this->isExpired( $action ) ) {
            $expired = $this->repository->expireAction( $actionCode, 'Workflow expired before approval confirmation.' );
            $this->audit->approval( 'action_expired', $actionCode, [ 'status' => 'expired' ] );

            return [
                'status' => 'workflow_expired',
                'message' => 'The previous workflow has expired. Would you like to start again?',
                'response_type' => 'WorkflowExpiredPrompt',
                'action' => is_array( $expired ) ? $expired : $action,
            ];
        }

        if ( $decision === 'reject' ) {
            $cancelled = $this->repository->cancelAction( $actionCode, \metis_current_user_id(), 'Cancelled via conversation continuation.' );
            $this->audit->approval( 'action_cancelled', $actionCode, [ 'status' => 'cancelled' ] );

            return [
                'status' => 'cancelled',
                'message' => 'Cancelled the pending Hermes action.',
                'response_type' => 'WorkflowCancellation',
                'action' => is_array( $cancelled ) ? $cancelled : $action,
            ];
        }

        $approved = $this->approvals->approve( $actionCode, \metis_current_user_id(), 'Approved via conversation continuation.' );
        $executed = $this->executor->executeApprovedAction( $approved, $actionCode );
        $result = (array) ( $executed['result'] ?? [] );
        $finalAction = (array) ( $executed['action'] ?? $approved );
        $message = trim( (string) ( $result['message'] ?? '' ) );
        if ( $message === '' ) {
            $message = 'Executed the approved Hermes action.';
        }

        return [
            'status' => (string) ( $result['status'] ?? 'success' ),
            'message' => $message,
            'response_type' => 'WorkflowContinuationResult',
            'result' => $result,
            'action' => $finalAction,
            'continued_action_code' => $actionCode,
        ];
    }

    private function decisionForQuery( string $query ): string {
        $normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $query ) ?? $query ) );

        if ( in_array( $normalized, [ 'yes', 'y', 'approve', 'approved', 'confirm', 'go ahead', 'do it', 'run it' ], true ) ) {
            return 'approve';
        }

        if ( in_array( $normalized, [ 'no', 'n', 'cancel', 'stop', 'never mind', 'do not', 'don\'t' ], true ) ) {
            return 'reject';
        }

        return '';
    }

    private function isExpired( array $action ): bool {
        $createdAt = trim( (string) ( $action['created_at'] ?? '' ) );
        if ( $createdAt === '' ) {
            return false;
        }

        $timestamp = strtotime( $createdAt );
        if ( $timestamp === false ) {
            return false;
        }

        return $timestamp < ( time() - self::WORKFLOW_TTL_SECONDS );
    }
}
