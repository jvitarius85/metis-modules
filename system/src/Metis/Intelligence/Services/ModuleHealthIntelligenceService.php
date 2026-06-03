<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Closure;
use Metis\Intelligence\Support\SeverityRanker;

final class ModuleHealthIntelligenceService {
    /**
     * @param callable(string,string):bool $canAccess
     */
    public function __construct(
        private readonly SeverityRanker $ranker,
        callable $canAccess
    ) {
        $this->canAccess = Closure::fromCallable( $canAccess );
    }

    private readonly Closure $canAccess;

    public function build(
        array $contextPacks,
        array $alerts,
        array $permissionIssues,
        array $reconciliation,
        array $queue,
        array $diagnostics
    ): array {
        $rows = [];

        foreach ( $contextPacks as $pack ) {
            if ( ! is_array( $pack ) ) {
                continue;
            }

            $moduleSlug = \metis_key_clean( (string) ( $pack['module_slug'] ?? '' ) );
            if ( $moduleSlug === '' ) {
                continue;
            }

            $packAlerts = array_values( array_filter(
                $alerts,
                static fn ( array $alert ): bool => (string) ( $alert['module_slug'] ?? '' ) === $moduleSlug
            ) );
            $packPermissionIssues = array_values( array_filter(
                $permissionIssues,
                static fn ( array $issue ): bool => (string) ( $issue['module_slug'] ?? '' ) === $moduleSlug
            ) );

            $canView = ( $this->canAccess )( $moduleSlug, 'view' );
            $canEdit = ( $this->canAccess )( $moduleSlug, 'edit' );
            $status = 'healthy';
            $statusSeverity = 'low';
            $summary = 'No active Hermes health alerts are open for this module.';

            if ( ! $canView ) {
                $status = 'restricted';
                $summary = 'Hermes can report the surface, but this operator does not have direct module visibility.';
            } elseif ( $packAlerts !== [] ) {
                $statusSeverity = (string) ( $packAlerts[0]['severity'] ?? 'medium' );
                $status = $this->ranker->atOrAbove( $statusSeverity, 'high' ) ? 'at-risk' : 'monitoring';
                $summary = (string) ( $packAlerts[0]['summary'] ?? $summary );
            } elseif ( $moduleSlug === 'finance' && (int) ( $reconciliation['summary']['anomaly_count'] ?? 0 ) > 0 ) {
                $status = 'at-risk';
                $statusSeverity = 'high';
                $summary = 'Reconciliation drift is visible in the latest finance snapshot.';
            } elseif ( $moduleSlug === 'newsletter' && (int) ( $queue['failed_count'] ?? 0 ) > 0 ) {
                $status = 'monitoring';
                $statusSeverity = 'medium';
                $summary = 'Hermes workers are reporting queue friction that can affect communications delivery.';
            }

            $rows[] = [
                'key' => (string) ( $pack['key'] ?? $moduleSlug ),
                'module_slug' => $moduleSlug,
                'title' => (string) ( $pack['title'] ?? ucfirst( $moduleSlug ) ),
                'description' => (string) ( $pack['description'] ?? '' ),
                'status' => $status,
                'severity' => $statusSeverity,
                'summary' => $summary,
                'can_view_module' => $canView,
                'can_edit_module' => $canEdit,
                'available_actions' => array_values( (array) ( $pack['available_actions'] ?? [] ) ),
                'diagnostics' => array_values( (array) ( $pack['diagnostics'] ?? [] ) ),
                'common_operational_issues' => array_values( (array) ( $pack['common_operational_issues'] ?? [] ) ),
                'alerts' => $packAlerts,
                'permission_issues' => $packPermissionIssues,
                'source_modules' => array_values( array_filter( array_map( 'strval', (array) ( $pack['source_modules'] ?? [] ) ) ) ),
            ];
        }

        foreach ( $rows as &$row ) {
            if ( (string) ( $row['module_slug'] ?? '' ) !== 'board' ) {
                continue;
            }

            foreach ( (array) ( $diagnostics['findings'] ?? [] ) as $finding ) {
                if ( (string) ( $finding['key'] ?? '' ) !== 'board_workspace_health' ) {
                    continue;
                }

                $row['live_diagnostic'] = $finding;
                break;
            }
        }
        unset( $row );

        usort( $rows, $this->ranker->compare( ... ) );

        return $rows;
    }
}
