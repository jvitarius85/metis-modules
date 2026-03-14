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

    public function rememberReport( string $report_code, array $summary ): void {
        $this->repository->upsertMemory( 'report:' . $report_code, 'diagnostic_report', 'reports', $summary );
    }

    public function recall( string $scope_key = '', int $limit = 6 ): array {
        return $this->repository->recentMemory( $scope_key, $limit );
    }
}
