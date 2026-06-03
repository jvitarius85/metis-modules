<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Modules\Finance\FinanceV2Service;

final class FinancialIntelligenceProvider extends AbstractSnapshotIntelligenceProvider {
    public function key(): string {
        return 'financial_intelligence';
    }

    public function definition(): array {
        return [
            'key' => 'financial_intelligence',
            'label' => 'Financial Intelligence',
            'type' => 'analytics',
            'default_limit' => 6,
        ];
    }

    protected function keywords(): array {
        return [ 'finance', 'financial', 'reconciliation', 'ledger', 'gl', 'bank', 'payout', 'expenses', 'revenue' ];
    }

    protected function snapshot(): array {
        return $this->safeSnapshot( static fn (): array => FinanceV2Service::summary() );
    }

    protected function buildMetrics( array $snapshot, int $limit ): array {
        return $this->limitRows( [
            [ 'key' => 'total_entries', 'label' => 'Ledger entries', 'value' => (int) ( $snapshot['total_entries'] ?? 0 ) ],
            [ 'key' => 'unmatched_entries', 'label' => 'Unmatched entries', 'value' => (int) ( $snapshot['unmatched_entries'] ?? 0 ) ],
            [ 'key' => 'unmatched_bank_lines', 'label' => 'Unmatched bank lines', 'value' => (int) ( $snapshot['unmatched_bank_lines'] ?? 0 ) ],
            [ 'key' => 'review_count', 'label' => 'Review reconciliation rows', 'value' => (int) ( $snapshot['review_count'] ?? 0 ) ],
        ], $limit );
    }

    protected function buildInsights( array $snapshot, int $limit ): array {
        return $this->limitRows( [
            [
                'type' => 'finance_summary',
                'title' => 'Finance summary',
                'summary' => sprintf(
                    '%d ledger entries, %d unmatched entries, %d unmatched bank lines.',
                    (int) ( $snapshot['total_entries'] ?? 0 ),
                    (int) ( $snapshot['unmatched_entries'] ?? 0 ),
                    (int) ( $snapshot['unmatched_bank_lines'] ?? 0 )
                ),
            ],
        ], $limit );
    }

    protected function buildAlerts( array $snapshot, int $limit ): array {
        if ( (int) ( $snapshot['unmatched_entries'] ?? 0 ) < 1 && (int) ( $snapshot['unmatched_bank_lines'] ?? 0 ) < 1 ) {
            return [];
        }

        return $this->limitRows( [
            [
                'severity' => ( (int) ( $snapshot['unmatched_bank_lines'] ?? 0 ) > 0 || (int) ( $snapshot['manual_count'] ?? 0 ) > 0 ) ? 'high' : 'medium',
                'title' => 'Financial reconciliation needs review',
                'summary' => sprintf(
                    '%d unmatched ledger entries and %d unmatched bank lines remain open.',
                    (int) ( $snapshot['unmatched_entries'] ?? 0 ),
                    (int) ( $snapshot['unmatched_bank_lines'] ?? 0 )
                ),
            ],
        ], $limit );
    }

    protected function buildRecommendations( array $snapshot, int $limit ): array {
        $rows = [];
        if ( (int) ( $snapshot['review_count'] ?? 0 ) > 0 ) {
            $rows[] = [
                'title' => 'Work the reconciliation review queue',
                'summary' => sprintf( '%d finance reconciliation rows are still in review.', (int) ( $snapshot['review_count'] ?? 0 ) ),
            ];
        }

        if ( (int) ( $snapshot['manual_count'] ?? 0 ) > 0 ) {
            $rows[] = [
                'title' => 'Reduce manual reconciliation load',
                'summary' => sprintf( '%d finance rows required manual handling in the latest summary.', (int) ( $snapshot['manual_count'] ?? 0 ) ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }
}
