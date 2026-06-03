<?php
declare(strict_types=1);

namespace Metis\Intelligence\Services;

use Metis\Modules\Newsletter\ReadService;

final class NewsletterIntelligenceProvider extends AbstractSnapshotIntelligenceProvider {
    public function key(): string {
        return 'newsletter_intelligence';
    }

    public function definition(): array {
        return [
            'key' => 'newsletter_intelligence',
            'label' => 'Newsletter Intelligence',
            'type' => 'analytics',
            'default_limit' => 6,
        ];
    }

    protected function keywords(): array {
        return [ 'newsletter', 'email', 'emails', 'subscriber', 'subscribers', 'campaign send', 'delivery' ];
    }

    protected function snapshot(): array {
        return $this->safeSnapshot( static fn (): array => ReadService::dashboardSnapshot() );
    }

    protected function buildMetrics( array $snapshot, int $limit ): array {
        return $this->limitRows( [
            [ 'key' => 'kpi_campaigns', 'label' => 'Campaigns', 'value' => (int) ( $snapshot['kpi_campaigns'] ?? 0 ) ],
            [ 'key' => 'kpi_subscribers', 'label' => 'Subscribers', 'value' => (int) ( $snapshot['kpi_subscribers'] ?? 0 ) ],
            [ 'key' => 'kpi_queued', 'label' => 'Queued messages', 'value' => (int) ( $snapshot['kpi_queued'] ?? 0 ) ],
            [ 'key' => 'kpi_30d', 'label' => 'Sent 30d', 'value' => (int) ( $snapshot['kpi_30d'] ?? 0 ) ],
        ], $limit );
    }

    protected function buildInsights( array $snapshot, int $limit ): array {
        $rows = [];
        foreach ( array_slice( (array) ( $snapshot['recent_campaigns'] ?? [] ), 0, max( 1, $limit ) ) as $campaign ) {
            $rows[] = [
                'type' => 'newsletter_campaign',
                'title' => (string) ( $campaign['name'] ?? 'Newsletter campaign' ),
                'summary' => sprintf(
                    'Status %s, %d recipients, %d sent.',
                    (string) ( $campaign['status'] ?? 'unknown' ),
                    (int) ( $campaign['total_recipients'] ?? 0 ),
                    (int) ( $campaign['sent_count'] ?? 0 )
                ),
            ];
        }

        return $this->limitRows( $rows, $limit );
    }

    protected function buildAlerts( array $snapshot, int $limit ): array {
        $queued = (int) ( $snapshot['kpi_queued'] ?? 0 );
        if ( $queued < 1 ) {
            return [];
        }

        return $this->limitRows( [
            [
                'severity' => $queued > 100 ? 'high' : 'medium',
                'title' => 'Newsletter queue is building',
                'summary' => sprintf( '%d newsletter messages are still queued for delivery.', $queued ),
            ],
        ], $limit );
    }

    protected function buildRecommendations( array $snapshot, int $limit ): array {
        if ( (int) ( $snapshot['kpi_queued'] ?? 0 ) < 1 ) {
            return [];
        }

        return $this->limitRows( [
            [
                'title' => 'Inspect newsletter queue health',
                'summary' => 'Newsletter messages are queued. Review delivery dependencies before the backlog affects fresh sends.',
            ],
        ], $limit );
    }
}
