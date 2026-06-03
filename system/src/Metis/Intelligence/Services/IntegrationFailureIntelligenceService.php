<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Intelligence\Support\SeverityRanker;

final class IntegrationFailureIntelligenceService {
    public function __construct(
        private readonly SeverityRanker $ranker
    ) {}

    public function build(
        array $cron,
        array $queue,
        array $reconciliation,
        array $permissionIssues,
        array $diagnostics
    ): array {
        $rows = [];

        if ( (int) ( $queue['failed_count'] ?? 0 ) > 0 ) {
            $rows[] = [
                'severity' => 'high',
                'title' => 'Hermes worker queue contains failed jobs',
                'summary' => 'Scheduled diagnostics or downstream worker tasks need intervention before they can be trusted as current.',
                'surface' => 'worker',
            ];
        }

        foreach ( (array) ( $cron['tasks'] ?? [] ) as $task ) {
            if ( ! in_array( (string) ( $task['health'] ?? '' ), [ 'failed', 'lagging' ], true ) ) {
                continue;
            }

            $rows[] = [
                'severity' => (string) ( $task['severity'] ?? 'medium' ),
                'title' => sprintf( '%s is %s', (string) ( $task['label'] ?? 'Cron task' ), (string) ( $task['health'] ?? 'unhealthy' ) ),
                'summary' => (string) ( $task['last_error'] ?? '' ) !== ''
                    ? (string) $task['last_error']
                    : 'The worker cadence is outside its expected interval and may be stalling dependent integrations.',
                'surface' => 'cron',
            ];
        }

        if ( (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ) > 0 ) {
            $rows[] = [
                'severity' => 'high',
                'title' => 'Finance reconciliation anomalies detected',
                'summary' => 'Deposit, statement, or ledger matching is producing unresolved variance and should be treated as an integration failure until closed.',
                'surface' => 'reconciliation',
            ];
        }

        foreach ( $permissionIssues as $issue ) {
            if ( ! $this->ranker->atOrAbove( (string) ( $issue['severity'] ?? 'low' ), 'medium' ) ) {
                continue;
            }

            $rows[] = [
                'severity' => (string) ( $issue['severity'] ?? 'medium' ),
                'title' => (string) ( $issue['title'] ?? 'Permission inconsistency' ),
                'summary' => (string) ( $issue['summary'] ?? '' ),
                'surface' => 'permissions',
            ];
        }

        foreach ( (array) ( $diagnostics['findings'] ?? [] ) as $finding ) {
            if ( (string) ( $finding['key'] ?? '' ) !== 'board_workspace_health' ) {
                continue;
            }

            $missing = (int) ( $finding['evidence']['missing_workspaces'] ?? 0 );
            if ( $missing < 1 ) {
                continue;
            }

            $rows[] = [
                'severity' => (string) ( $finding['severity'] ?? 'high' ),
                'title' => (string) ( $finding['title'] ?? 'Board workspace integrity' ),
                'summary' => (string) ( $finding['summary'] ?? '' ),
                'surface' => 'board',
            ];
        }

        usort( $rows, $this->ranker->compare( ... ) );

        return array_slice( $rows, 0, 10 );
    }
}
