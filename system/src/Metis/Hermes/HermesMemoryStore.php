<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesMemoryStore {
    public function __construct(
        private readonly HermesRepository $repository
    ) {}

    public function rememberConversation( string $session_code, array $summary ): void {
        $this->repository->upsertMemory( 'conversation:' . $session_code, 'conversation_summary', $session_code, $summary );
    }

    public function rememberRecentEntity( string $session_code, array $entity ): void {
        if ( $session_code === '' || $entity === [] ) {
            return;
        }

        $this->repository->upsertMemory( 'entity:' . $session_code, 'recent_entity', $session_code, $entity );
    }

    public function rememberPendingWorkflow( string $session_code, array $workflow ): void {
        if ( $session_code === '' || $workflow === [] ) {
            return;
        }

        $this->repository->upsertMemory( 'workflow:' . $session_code, 'pending_workflow', $session_code, $workflow );
    }

    public function rememberPendingDisambiguation( string $session_code, array $disambiguation ): void {
        if ( $session_code === '' || $disambiguation === [] ) {
            return;
        }

        $this->repository->upsertMemory( 'disambiguation:' . $session_code, 'pending_disambiguation', $session_code, $disambiguation );
    }

    public function rememberPendingNluContext( string $session_code, array $context ): void {
        if ( $session_code === '' || $context === [] ) {
            return;
        }

        $this->repository->upsertMemory( 'nlu:' . $session_code, 'pending_nlu_context', $session_code, $context );
    }

    public function rememberReport( string $report_code, array $summary ): void {
        $this->repository->upsertMemory( 'report:' . $report_code, 'diagnostic_report', 'reports', $summary );
    }

    public function recall( string $scope_key = '', int $limit = 6 ): array {
        return $this->repository->recentMemory( $scope_key, $limit );
    }

    public function recallConversation( string $session_code ): array {
        if ( $session_code === '' ) {
            return [];
        }

        $rows = $this->repository->recentMemory( $session_code, 1 );
        $row = (array) ( $rows[0] ?? [] );

        return (array) ( $row['contents'] ?? [] );
    }

    public function recallRecentEntity( string $session_code ): array {
        if ( $session_code === '' ) {
            return [];
        }

        $rows = $this->repository->recentMemory( $session_code, 6 );
        foreach ( $rows as $row ) {
            if ( (string) ( $row['memory_type'] ?? '' ) !== 'recent_entity' ) {
                continue;
            }

            return (array) ( $row['contents'] ?? [] );
        }

        return [];
    }

    public function recallPendingWorkflow( string $session_code ): array {
        if ( $session_code === '' ) {
            return [];
        }

        $rows = $this->repository->recentMemory( $session_code, 6 );
        foreach ( $rows as $row ) {
            if ( (string) ( $row['memory_type'] ?? '' ) !== 'pending_workflow' ) {
                continue;
            }

            $contents = (array) ( $row['contents'] ?? [] );
            if ( $contents === [] ) {
                return [];
            }

            return [
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                'contents' => $contents,
            ];
        }

        return [];
    }

    public function clearPendingWorkflow( string $session_code ): void {
        if ( $session_code === '' ) {
            return;
        }

        $this->repository->upsertMemory( 'workflow:' . $session_code, 'pending_workflow', $session_code, [] );
    }

    public function recallPendingDisambiguation( string $session_code ): array {
        if ( $session_code === '' ) {
            return [];
        }

        $rows = $this->repository->recentMemory( $session_code, 8 );
        foreach ( $rows as $row ) {
            if ( (string) ( $row['memory_type'] ?? '' ) !== 'pending_disambiguation' ) {
                continue;
            }

            $contents = (array) ( $row['contents'] ?? [] );
            if ( $contents === [] ) {
                return [];
            }

            return [
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                'contents' => $contents,
            ];
        }

        return [];
    }

    public function clearPendingDisambiguation( string $session_code ): void {
        if ( $session_code === '' ) {
            return;
        }

        $this->repository->upsertMemory( 'disambiguation:' . $session_code, 'pending_disambiguation', $session_code, [] );
    }

    public function recallPendingNluContext( string $session_code ): array {
        if ( $session_code === '' ) {
            return [];
        }

        $rows = $this->repository->recentMemory( $session_code, 8 );
        foreach ( $rows as $row ) {
            if ( (string) ( $row['memory_type'] ?? '' ) !== 'pending_nlu_context' ) {
                continue;
            }

            $contents = (array) ( $row['contents'] ?? [] );
            if ( $contents === [] ) {
                return [];
            }

            return [
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                'contents' => $contents,
            ];
        }

        return [];
    }

    public function clearPendingNluContext( string $session_code ): void {
        if ( $session_code === '' ) {
            return;
        }

        $this->repository->upsertMemory( 'nlu:' . $session_code, 'pending_nlu_context', $session_code, [] );
    }
}
