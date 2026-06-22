<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Finance\FinanceModule::boot();

function metis_finance_can_view(): bool { return \Metis\Modules\Finance\FinanceModule::canView(); }
function metis_finance_can_manage(): bool { return \Metis\Modules\Finance\FinanceModule::canManage(); }
function metis_finance_can( string $action ): bool { return \Metis\Modules\Finance\Access::can( $action ); }
function metis_finance_can_export(): bool { return \Metis\Modules\Finance\Access::canExport(); }
function metis_finance_base_url(): string { return \Metis\Modules\Finance\FinanceModule::baseUrl(); }
function metis_finance_ensure_schema(): void { \Metis\Modules\Finance\FinanceModule::ensureSchema(); }
function metis_finance_current_mode(): string { return \Metis\Modules\Finance\FinanceModule::currentMode(); }
function metis_finance_mode_switch_status(): array { return \Metis\Modules\Finance\FinanceModule::modeSwitchStatus(); }
function metis_finance_schedule_mode_switch( string $target_mode, string $effective_at, int $requested_by = 0 ): array {
    return \Metis\Modules\Finance\FinanceModule::scheduleModeSwitch( $target_mode, $effective_at, $requested_by );
}
function metis_finance_currency( float $amount ): string { return \Metis\Modules\Finance\Support::currency( $amount ); }
function metis_finance_record_offline_donation_receipt( array $payload, int $requested_by = 0 ): void {
    \Metis\Modules\Finance\FinanceV2Service::recordOfflineDonationReceipt( $payload, $requested_by );
}
