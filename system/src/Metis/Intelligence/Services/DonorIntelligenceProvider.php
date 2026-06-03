<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Modules\Donations\ReadService;

final class DonorIntelligenceProvider extends AbstractSnapshotIntelligenceProvider {
    public function key(): string {
        return 'donor_intelligence';
    }

    public function definition(): array {
        return [
            'key' => 'donor_intelligence',
            'label' => 'Donor Intelligence',
            'type' => 'analytics',
            'default_limit' => 6,
        ];
    }

    protected function keywords(): array {
        return [ 'donor', 'donors', 'giving', 'gift', 'gifts', 'donation', 'donations', 'fundraising' ];
    }

    protected function snapshot(): array {
        return $this->safeSnapshot( static fn (): array => ReadService::dashboardSnapshot() );
    }

    protected function buildMetrics( array $snapshot, int $limit ): array {
        return $this->limitRows( [
            [ 'key' => 'raised_30d', 'label' => 'Raised 30d', 'value' => (float) ( $snapshot['raised_30d'] ?? 0 ) ],
            [ 'key' => 'current_gifts', 'label' => 'Current gifts', 'value' => (int) ( $snapshot['current_gifts'] ?? 0 ) ],
            [ 'key' => 'avg_gift_30d', 'label' => 'Average gift 30d', 'value' => (float) ( $snapshot['avg_gift_30d'] ?? 0 ) ],
            [ 'key' => 'donors_ytd', 'label' => 'Donors YTD', 'value' => (int) ( $snapshot['donors_ytd'] ?? 0 ) ],
        ], $limit );
    }

    protected function buildInsights( array $snapshot, int $limit ): array {
        $rows = [];
        foreach ( array_slice( (array) ( $snapshot['top_donors'] ?? [] ), 0, max( 1, $limit ) ) as $donor ) {
            $rows[] = [
                'type' => 'donor',
                'title' => (string) ( $donor->donor_name ?? $donor['donor_name'] ?? 'Top donor' ),
                'summary' => sprintf(
                    'Raised %s across %d gifts.',
                    number_format( (float) ( $donor->total_raised ?? $donor['total_raised'] ?? 0 ), 2 ),
                    (int) ( $donor->gift_count ?? $donor['gift_count'] ?? 0 )
                ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }

    protected function buildAlerts( array $snapshot, int $limit ): array {
        $queueGifts = (int) ( $snapshot['queue_gifts'] ?? 0 );
        if ( $queueGifts < 1 ) {
            return [];
        }

        return $this->limitRows( [
            [
                'severity' => $queueGifts > 10 ? 'high' : 'medium',
                'title' => 'Open deposit queue needs follow-up',
                'summary' => sprintf( '%d completed gifts are still waiting on deposit batching.', $queueGifts ),
            ],
        ], $limit );
    }

    protected function buildRecommendations( array $snapshot, int $limit ): array {
        $rows = [];
        if ( (int) ( $snapshot['queue_gifts'] ?? 0 ) > 0 ) {
            $rows[] = [
                'title' => 'Review open deposit queue',
                'summary' => 'Deposit queue pressure is visible in the donor snapshot. Reconcile completed gifts before the queue grows.',
            ];
        }

        if ( (string) ( $snapshot['raised_delta_class'] ?? '' ) === 'negative' ) {
            $rows[] = [
                'title' => 'Investigate fundraising downturn',
                'summary' => (string) ( $snapshot['raised_delta_label'] ?? 'Giving is down versus the previous period.' ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }
}
