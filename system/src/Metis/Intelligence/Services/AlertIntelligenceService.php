<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Intelligence\Support\SeverityRanker;

final class AlertIntelligenceService {
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
        $alerts = [];

        if ( (int) ( $queue['failed_count'] ?? 0 ) > 0 || (int) ( $queue['processing_count'] ?? 0 ) > 15 ) {
            $alerts[] = [
                'severity' => (int) ( $queue['failed_count'] ?? 0 ) > 0 ? 'high' : 'medium',
                'module_slug' => 'hermes',
                'title' => 'Worker queue pressure',
                'summary' => sprintf(
                    '%d failed, %d queued, %d processing.',
                    (int) ( $queue['failed_count'] ?? 0 ),
                    (int) ( $queue['queued_count'] ?? 0 ),
                    (int) ( $queue['processing_count'] ?? 0 )
                ),
            ];
        }

        foreach ( (array) ( $cron['tasks'] ?? [] ) as $task ) {
            if ( ! in_array( (string) ( $task['health'] ?? '' ), [ 'failed', 'lagging' ], true ) ) {
                continue;
            }

            $alerts[] = [
                'severity' => (string) ( $task['severity'] ?? 'medium' ),
                'module_slug' => (string) ( $task['module'] ?? 'core' ),
                'title' => sprintf( '%s is %s', (string) ( $task['label'] ?? 'Worker' ), (string) ( $task['health'] ?? 'unhealthy' ) ),
                'summary' => (string) ( $task['last_error'] ?? '' ) !== '' ? (string) $task['last_error'] : 'This worker missed its expected cadence.',
            ];
        }

        if ( (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ) > 0 ) {
            $alerts[] = [
                'severity' => 'high',
                'module_slug' => 'finance',
                'title' => 'Reconciliation anomalies open',
                'summary' => sprintf(
                    '%d open reconciliations and %d variance rows need follow-up.',
                    (int) ( $reconciliation['summary']['open_count'] ?? 0 ),
                    (int) ( $reconciliation['summary']['variance_count'] ?? 0 )
                ),
            ];
        }

        foreach ( $permissionIssues as $issue ) {
            if ( ! $this->ranker->atOrAbove( (string) ( $issue['severity'] ?? 'low' ), 'medium' ) ) {
                continue;
            }

            $alerts[] = [
                'severity' => (string) ( $issue['severity'] ?? 'medium' ),
                'module_slug' => (string) ( $issue['module_slug'] ?? 'people' ),
                'title' => (string) ( $issue['title'] ?? 'Permission inconsistency' ),
                'summary' => (string) ( $issue['summary'] ?? '' ),
            ];
        }

        foreach ( (array) ( $diagnostics['findings'] ?? [] ) as $finding ) {
            if ( (string) ( $finding['key'] ?? '' ) !== 'board_workspace_health' ) {
                continue;
            }

            if ( (int) ( $finding['evidence']['missing_workspaces'] ?? 0 ) < 1 ) {
                continue;
            }

            $alerts[] = [
                'severity' => (string) ( $finding['severity'] ?? 'high' ),
                'module_slug' => 'board',
                'title' => (string) ( $finding['title'] ?? 'Board workspace integrity' ),
                'summary' => (string) ( $finding['summary'] ?? '' ),
            ];
        }

        usort( $alerts, $this->ranker->compare( ... ) );

        return array_slice( $alerts, 0, 12 );
    }
}
