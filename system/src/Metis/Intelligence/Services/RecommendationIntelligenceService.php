<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Intelligence\Support\SeverityRanker;

final class RecommendationIntelligenceService {
    public function __construct(
        private readonly SeverityRanker $ranker
    ) {}

    public function build(
        array $alerts,
        array $integrationFailures,
        array $moduleSummaries,
        array $diagnostics,
        array $operations
    ): array {
        $rows = [];
        $operationsByKey = [];
        foreach ( $operations as $operation ) {
            if ( is_array( $operation ) && (string) ( $operation['operation_key'] ?? '' ) !== '' ) {
                $operationsByKey[ (string) $operation['operation_key'] ] = $operation;
            }
        }

        if ( $this->hasAlertForModule( $alerts, 'hermes' ) || count( $integrationFailures ) > 0 ) {
            $recommendation = $this->recommendation(
                'high',
                'hermes',
                'Review worker backlog and failed jobs',
                'Queue pressure or failed worker tasks are present. Start with worker diagnostics before attempting deeper remediation.',
                'check_workers',
                $operationsByKey,
                'queue_health'
            );
            if ( is_array( $recommendation ) ) {
                $rows[] = $recommendation;
            }
        }

        if ( $this->hasAlertForModule( $alerts, 'finance' ) ) {
            $recommendation = $this->recommendation(
                'high',
                'finance',
                'Run finance reconciliation follow-up',
                'Finance anomalies are open. Validate reconciliation health before closing related operational incidents.',
                'run_full_diagnostics',
                $operationsByKey,
                'finance_reconciliation'
            );
            if ( is_array( $recommendation ) ) {
                $rows[] = $recommendation;
            }
        }

        if ( $this->hasAlertForModule( $alerts, 'board' ) || $this->hasDiagnostic( $diagnostics, 'board_workspace_health' ) ) {
            $recommendation = $this->recommendation(
                'high',
                'board',
                'Inspect board workspace integrity',
                'Board workspace drift is visible. Run integrity diagnostics before any repair or restore step.',
                'scan_integrity',
                $operationsByKey,
                'board_workspace'
            );
            if ( is_array( $recommendation ) ) {
                $rows[] = $recommendation;
            }
        }

        if ( $this->hasMonitoringModule( $moduleSummaries, 'newsletter' ) ) {
            $recommendation = $this->recommendation(
                'medium',
                'newsletter',
                'Check newsletter delivery dependencies',
                'Newsletter delivery is being affected by worker or integration friction. Review worker and module diagnostics together.',
                'check_modules',
                $operationsByKey,
                'newsletter_delivery'
            );
            if ( is_array( $recommendation ) ) {
                $rows[] = $recommendation;
            }
        }

        if ( $this->hasPermissionPressure( $alerts ) ) {
            $recommendation = $this->recommendation(
                'medium',
                'people',
                'Audit permission mismatches',
                'Permission inconsistencies are showing up in Hermes health. Audit role coverage before making manual access changes.',
                'audit_permissions',
                $operationsByKey,
                'permission_mismatch'
            );
            if ( is_array( $recommendation ) ) {
                $rows[] = $recommendation;
            }
        }

        usort( $rows, $this->ranker->compare( ... ) );

        return array_slice( $this->dedupe( $rows ), 0, 8 );
    }

    private function recommendation(
        string $severity,
        string $moduleSlug,
        string $title,
        string $summary,
        string $operationKey,
        array $operationsByKey,
        string $sourceKey
    ): ?array {
        $operation = (array) ( $operationsByKey[ $operationKey ] ?? [] );
        if ( $operation === [] || ( array_key_exists( 'supported', $operation ) && empty( $operation['supported'] ) ) ) {
            return null;
        }

        return [
            'severity' => $severity,
            'module_slug' => $moduleSlug,
            'title' => $title,
            'summary' => $summary,
            'source_key' => $sourceKey,
            'recommended_operation' => [
                'operation_key' => $operationKey,
                'title' => (string) ( $operation['title'] ?? $operationKey ),
                'requires_approval' => ! empty( $operation['requires_approval'] ),
                'read_only' => ! empty( $operation['read_only'] ),
                'supported' => ! array_key_exists( 'supported', $operation ) || ! empty( $operation['supported'] ),
                'unsupported_message' => (string) ( $operation['unsupported_message'] ?? '' ),
                'domain' => (string) ( $operation['domain'] ?? '' ),
                'module' => (string) ( $operation['module'] ?? '' ),
            ],
        ];
    }

    private function hasAlertForModule( array $alerts, string $moduleSlug ): bool {
        foreach ( $alerts as $alert ) {
            if ( ! is_array( $alert ) ) {
                continue;
            }

            if ( (string) ( $alert['module_slug'] ?? '' ) === $moduleSlug ) {
                return true;
            }
        }

        return false;
    }

    private function hasMonitoringModule( array $moduleSummaries, string $moduleSlug ): bool {
        foreach ( $moduleSummaries as $summary ) {
            if ( ! is_array( $summary ) ) {
                continue;
            }

            if ( (string) ( $summary['module_slug'] ?? '' ) !== $moduleSlug ) {
                continue;
            }

            return in_array( (string) ( $summary['status'] ?? '' ), [ 'monitoring', 'at-risk' ], true );
        }

        return false;
    }

    private function hasPermissionPressure( array $alerts ): bool {
        foreach ( $alerts as $alert ) {
            if ( ! is_array( $alert ) ) {
                continue;
            }

            if ( (string) ( $alert['module_slug'] ?? '' ) === 'people' && $this->ranker->atOrAbove( (string) ( $alert['severity'] ?? 'low' ), 'medium' ) ) {
                return true;
            }
        }

        return false;
    }

    private function hasDiagnostic( array $diagnostics, string $key ): bool {
        foreach ( (array) ( $diagnostics['findings'] ?? [] ) as $finding ) {
            if ( ! is_array( $finding ) ) {
                continue;
            }

            if ( (string) ( $finding['key'] ?? '' ) === $key ) {
                return true;
            }
        }

        return false;
    }

    private function dedupe( array $rows ): array {
        $seen = [];
        $deduped = [];
        foreach ( $rows as $row ) {
            $key = strtolower( trim( (string) ( $row['source_key'] ?? $row['title'] ?? '' ) ) );
            if ( $key === '' || isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }
}
