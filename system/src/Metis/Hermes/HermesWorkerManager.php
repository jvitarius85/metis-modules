<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesWorkerManager {
    private bool $registered = false;

    public function __construct(
        private readonly HermesGateway $gateway
    ) {}

    public function register(): void {
        if ( $this->registered ) {
            return;
        }

        $this->registered = true;
        \metis_job_workers()->register( 'hermes.diagnostics', fn ( array $payload ): array => $this->gateway->runScheduledDiagnostics( (string) ( $payload['scope'] ?? 'system' ) ) );
        \Metis_Cron_Manager::register_task(
            'hermes_scheduled_diagnostics',
            fn (): array => $this->gateway->enqueueScheduledDiagnostics(),
            [
                'label' => 'Hermes Scheduled Diagnostics',
                'interval' => 15 * MINUTE_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module' => 'hermes',
            ]
        );
    }
}
