<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Hermes\HermesDiagnosticEngine;
use Metis\Hermes\HermesRepository;

final class SystemIntelligenceProvider extends AbstractSnapshotIntelligenceProvider {
    public function __construct(
        private readonly HermesDiagnosticEngine $diagnostics,
        private readonly HermesRepository $repository
    ) {}

    public function key(): string {
        return 'system_intelligence';
    }

    public function definition(): array {
        return [
            'key' => 'system_intelligence',
            'label' => 'System Intelligence',
            'type' => 'system_health',
            'default_limit' => 6,
        ];
    }

    protected function keywords(): array {
        return [ 'system', 'health', 'worker', 'workers', 'queue', 'diagnostic', 'diagnostics', 'integrity', 'status' ];
    }

    protected function snapshot(): array {
        return $this->safeSnapshot( function (): array {
            $library = \Metis\Core\Application::service( 'hermes_library' );
            $contextPacks = array_values( $library->contextPacks() );
            $diagnostics = $this->diagnostics->run( [ 'context_packs' => $contextPacks ] );
            $queue = $this->repository->queueSummary();

            return [
                'queue' => $queue,
                'diagnostics' => $diagnostics,
            ];
        } );
    }

    protected function buildMetrics( array $snapshot, int $limit ): array {
        $summary = (array) ( $snapshot['diagnostics']['summary'] ?? [] );
        $queue = (array) ( $snapshot['queue'] ?? [] );

        return $this->limitRows( [
            [ 'key' => 'finding_count', 'label' => 'Diagnostic findings', 'value' => (int) ( $summary['finding_count'] ?? 0 ) ],
            [ 'key' => 'high_severity', 'label' => 'High severity findings', 'value' => (int) ( $summary['high_severity'] ?? 0 ) ],
            [ 'key' => 'queued_count', 'label' => 'Queued jobs', 'value' => (int) ( $queue['queued_count'] ?? 0 ) ],
            [ 'key' => 'failed_count', 'label' => 'Failed jobs', 'value' => (int) ( $queue['failed_count'] ?? 0 ) ],
        ], $limit );
    }

    protected function buildInsights( array $snapshot, int $limit ): array {
        $rows = [];
        foreach ( array_slice( (array) ( $snapshot['diagnostics']['findings'] ?? [] ), 0, max( 1, $limit ) ) as $finding ) {
            $rows[] = [
                'type' => 'system_finding',
                'title' => (string) ( $finding['title'] ?? 'System finding' ),
                'summary' => (string) ( $finding['summary'] ?? '' ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }

    protected function buildAlerts( array $snapshot, int $limit ): array {
        $rows = [];
        $queue = (array) ( $snapshot['queue'] ?? [] );
        if ( (int) ( $queue['failed_count'] ?? 0 ) > 0 ) {
            $rows[] = [
                'severity' => 'high',
                'title' => 'System queue contains failed jobs',
                'summary' => sprintf( '%d failed jobs are present in the Hermes queue.', (int) ( $queue['failed_count'] ?? 0 ) ),
            ];
        }

        foreach ( (array) ( $snapshot['diagnostics']['findings'] ?? [] ) as $finding ) {
            if ( ! is_array( $finding ) ) {
                continue;
            }

            $rows[] = [
                'severity' => (string) ( $finding['severity'] ?? 'medium' ),
                'title' => (string) ( $finding['title'] ?? 'System finding' ),
                'summary' => (string) ( $finding['summary'] ?? '' ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }

    protected function buildRecommendations( array $snapshot, int $limit ): array {
        $rows = [];
        foreach ( (array) ( $snapshot['diagnostics']['findings'] ?? [] ) as $finding ) {
            if ( ! is_array( $finding ) ) {
                continue;
            }

            $action = (array) ( $finding['proposed_action'] ?? [] );
            if ( $action === [] ) {
                continue;
            }

            $rows[] = [
                'title' => (string) ( $action['summary'] ?? 'Review proposed system action' ),
                'summary' => (string) ( $action['command'] ?? '' ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }
}
