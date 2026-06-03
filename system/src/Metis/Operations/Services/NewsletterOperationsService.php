<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

final class NewsletterOperationsService extends AbstractOperationsService {
    public function operationKeys(): array {
        return [
            'newsletter_create',
            'newsletter_send',
            'newsletter_schedule',
            'newsletter_cancel',
            'newsletter_delete',
        ];
    }

    public function family(): string {
        return 'newsletter';
    }
}
