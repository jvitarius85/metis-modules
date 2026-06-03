<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Modules\Donations\ReadService;

final class CampaignIntelligenceProvider extends AbstractSnapshotIntelligenceProvider {
    public function key(): string {
        return 'campaign_intelligence';
    }

    public function definition(): array {
        return [
            'key' => 'campaign_intelligence',
            'label' => 'Campaign Intelligence',
            'type' => 'analytics',
            'default_limit' => 6,
        ];
    }

    protected function keywords(): array {
        return [ 'campaign', 'campaigns', 'appeal', 'appeals', 'fund', 'fundraiser' ];
    }

    protected function snapshot(): array {
        return $this->safeSnapshot( static fn (): array => ReadService::campaignsSnapshot() );
    }

    protected function buildMetrics( array $snapshot, int $limit ): array {
        $campaigns = (array) ( $snapshot['campaigns'] ?? [] );
        $activeCount = count( array_filter( $campaigns, static fn ( object $campaign ): bool => ! empty( $campaign->active ) ) );

        return $this->limitRows( [
            [ 'key' => 'campaign_count', 'label' => 'Campaign count', 'value' => count( $campaigns ) ],
            [ 'key' => 'active_campaigns', 'label' => 'Active campaigns', 'value' => $activeCount ],
            [ 'key' => 'current_year', 'label' => 'Current year', 'value' => (int) ( $snapshot['current_year'] ?? 0 ) ],
        ], $limit );
    }

    protected function buildInsights( array $snapshot, int $limit ): array {
        $rows = [];
        foreach ( array_slice( (array) ( $snapshot['campaigns'] ?? [] ), 0, max( 1, $limit ) ) as $campaign ) {
            $rows[] = [
                'type' => 'campaign',
                'title' => (string) ( $campaign->cname ?? 'Campaign' ),
                'summary' => sprintf(
                    '%d gifts, %s raised, last gift %s.',
                    (int) ( $campaign->gift_count ?? 0 ),
                    number_format( (float) ( $campaign->total_raised ?? 0 ), 2 ),
                    (string) ( $campaign->last_gift_date ?? 'n/a' )
                ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }

    protected function buildRecommendations( array $snapshot, int $limit ): array {
        $campaigns = (array) ( $snapshot['campaigns'] ?? [] );
        $inactiveCount = count( array_filter( $campaigns, static fn ( object $campaign ): bool => empty( $campaign->active ) ) );
        if ( $inactiveCount < 1 ) {
            return [];
        }

        return $this->limitRows( [
            [
                'title' => 'Review inactive campaigns',
                'summary' => sprintf( '%d campaigns are inactive in the current snapshot. Confirm whether they should stay archived or be reactivated.', $inactiveCount ),
            ],
        ], $limit );
    }
}
