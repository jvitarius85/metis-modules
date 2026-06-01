<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesApprovalEngine {
    public function __construct(
        private readonly HermesRepository $repository,
        private readonly HermesActionPreview $preview,
        private readonly HermesOperationalEngine $operations,
        private readonly HermesAuditLogger $audit
    ) {}

    public function queueApprovalForProcessedResponse( array $session, string $query, array $processed, array $response ): array {
        $actions = [];

        if ( (string) ( $response['status'] ?? '' ) !== 'awaiting_approval' || ! is_array( $processed['command'] ?? null ) ) {
            return $actions;
        }

        $payload = [
            'intent' => (array) ( $processed['intent'] ?? [] ),
            'parser' => (array) ( $processed['parsed'] ?? [] ),
            'query' => $query,
            'operation' => (string) ( $processed['action_plan']['operation'] ?? '' ),
            'command_payload' => (array) ( $processed['intent']['payload'] ?? [] ),
            'execution_plan' => (array) ( $processed['parsed']['execution_plan'] ?? [] ),
            'action_plan' => (array) ( $processed['action_plan'] ?? [] ),
            'context_packs' => (array) ( $processed['context_packs'] ?? [] ),
            'required_permission' => (string) ( $processed['action_plan']['required_permission'] ?? '' ),
        ];
        $preview = $this->preview->preview( 'hermes_command', $payload, [
            'title' => (string) ( $processed['action_plan']['title'] ?? 'Hermes Command' ),
            'summary' => (string) ( $response['message'] ?? 'Approval required.' ),
        ] );

        $actions[] = $this->repository->createAction(
            (int) ( $session['id'] ?? 0 ),
            0,
            'hermes_command',
            (string) ( $processed['action_plan']['title'] ?? 'Hermes Action' ),
            $payload,
            $preview
        );

        return $actions;
    }

    public function attachApprovalPrompts( array $response, array $actions ): array {
        if ( $actions === [] ) {
            return $response;
        }

        foreach ( $actions as $action ) {
            if ( ! is_array( $action ) ) {
                continue;
            }

            $response['ui_components'][] = [
                'type' => 'ApprovalPrompt',
                'action_code' => (string) ( $action['action_code'] ?? '' ),
                'buttons' => [ 'Approve', 'Cancel' ],
            ];
        }

        return $response;
    }

    public function approve( string $actionCode, int $userId, string $note = '' ): array {
        $action = $this->repository->getActionByCode( $actionCode );
        if ( ! is_array( $action ) ) {
            throw new \RuntimeException( 'Hermes action could not be approved.' );
        }

        $payload = (array) ( $action['payload'] ?? [] );
        if ( (string) ( $action['action_type'] ?? '' ) === 'hermes_command' || ! empty( $payload['operation'] ) ) {
            $prepared = $this->operations->validatePreparedAction( $payload );
            $permission = (array) ( $prepared['permission'] ?? [] );
            if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
                throw new \RuntimeException( (string) ( $permission['reason'] ?? 'Permission denied.' ) );
            }
        }

        $approved = $this->repository->approveAction( $actionCode, $userId, $note );
        if ( ! is_array( $approved ) ) {
            throw new \RuntimeException( 'Hermes action could not be approved.' );
        }

        $this->audit->approval( 'action_approved', $actionCode, [ 'status' => 'approved' ] );

        return $approved;
    }
}
