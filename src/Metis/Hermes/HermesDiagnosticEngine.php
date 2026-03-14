<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesDiagnosticEngine {
    public function __construct(
        private readonly HermesRepository $repository
    ) {}

    public function run( array $context ): array {
        $packs = (array) ( $context['context_packs'] ?? [] );
        $findings = [];
        $queue = $this->repository->queueSummary();

        $findings[] = [
            'key' => 'job_queue_health',
            'severity' => (int) ( $queue['failed_count'] ?? 0 ) > 0 ? 'high' : 'medium',
            'title' => 'Background queue status',
            'evidence' => [
                'queued' => (int) ( $queue['queued_count'] ?? 0 ),
                'processing' => (int) ( $queue['processing_count'] ?? 0 ),
                'failed' => (int) ( $queue['failed_count'] ?? 0 ),
            ],
            'summary' => (int) ( $queue['failed_count'] ?? 0 ) > 0
                ? 'Queued work includes failed jobs that should be reviewed before attempting recovery actions.'
                : 'Background job processing is operating without recorded failures.',
        ];

        foreach ( $packs as $pack ) {
            if ( ! is_array( $pack ) ) {
                continue;
            }

            foreach ( (array) ( $pack['diagnostics'] ?? [] ) as $diagnostic ) {
                if ( ! is_array( $diagnostic ) ) {
                    continue;
                }

                $findings[] = [
                    'key' => (string) ( $diagnostic['key'] ?? 'diagnostic' ),
                    'severity' => (string) ( $diagnostic['severity'] ?? 'medium' ),
                    'title' => (string) ( $diagnostic['purpose'] ?? $diagnostic['key'] ?? 'Diagnostic' ),
                    'evidence' => (array) ( $diagnostic['evidence'] ?? [] ),
                    'summary' => 'Hermes loaded the module diagnostic definition from the context pack and scoped it into the current reasoning pass.',
                ];
            }

            if ( (string) ( $pack['key'] ?? '' ) === 'board' ) {
                $findings[] = $this->boardMeetingHealth();
            }
        }

        return [
            'summary' => [
                'finding_count' => count( $findings ),
                'high_severity' => count( array_filter( $findings, static fn ( array $finding ): bool => ( $finding['severity'] ?? '' ) === 'high' ) ),
            ],
            'findings' => $findings,
        ];
    }

    private function boardMeetingHealth(): array {
        global $wpdb;

        try {
            $meetings_table = \Metis_Tables::get( 'board_meetings' );
            $documents_table = \Metis_Tables::get( 'board_documents' );

            $row = $wpdb->get_row(
                "SELECT
                    SUM(CASE WHEN COALESCE(google_drive_folder_id, '') = '' THEN 1 ELSE 0 END) AS missing_workspace_count,
                    COUNT(*) AS meeting_count,
                    (
                        SELECT COUNT(*)
                        FROM {$documents_table}
                    ) AS document_count
                 FROM {$meetings_table}",
                ARRAY_A
            );
        } catch ( \Throwable ) {
            $row = [ 'missing_workspace_count' => 0, 'meeting_count' => 0, 'document_count' => 0 ];
        }

        $missing = (int) ( $row['missing_workspace_count'] ?? 0 );

        return [
            'key' => 'board_workspace_health',
            'severity' => $missing > 0 ? 'high' : 'medium',
            'title' => 'Board meeting workspace integrity',
            'evidence' => [
                'meetings' => (int) ( $row['meeting_count'] ?? 0 ),
                'linked_documents' => (int) ( $row['document_count'] ?? 0 ),
                'missing_workspaces' => $missing,
            ],
            'summary' => $missing > 0
                ? 'Some meetings are missing linked workspace folders, which can block packet readiness and document visibility.'
                : 'Board meetings with linked workspaces do not show an obvious readiness gap.',
        ];
    }
}
