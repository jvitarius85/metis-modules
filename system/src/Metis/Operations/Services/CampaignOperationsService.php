<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

final class CampaignOperationsService extends AbstractOperationsService {
    public function operationKeys(): array {
        return [
            'campaign_create',
            'campaign_update',
            'campaign_publish',
            'campaign_archive',
            'campaign_delete',
        ];
    }

    public function family(): string {
        return 'campaign';
    }
}
