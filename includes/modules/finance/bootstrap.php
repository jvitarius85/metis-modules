<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/core/autoload.php';

\Metis\Modules\Finance\FinanceModule::boot();

function metis_finance_can_view(): bool { return \Metis\Modules\Finance\FinanceModule::canView(); }
function metis_finance_can_manage(): bool { return \Metis\Modules\Finance\FinanceModule::canManage(); }
function metis_finance_base_url(): string { return \Metis\Modules\Finance\FinanceModule::baseUrl(); }
function metis_finance_table_exists( string $table ): bool { return \Metis\Modules\Finance\FinanceModule::tableExists( $table ); }
function metis_finance_table_has_column( string $table, string $column ): bool { return \Metis\Modules\Finance\FinanceModule::tableHasColumn( $table, $column ); }
function metis_finance_currency( float $amount ): string { return \Metis\Modules\Finance\FinanceModule::currency( $amount ); }
function metis_finance_short_date( ?string $date ): string { return \Metis\Modules\Finance\FinanceModule::shortDate( $date ); }
function metis_finance_signed_amount( string $direction, float $amount ): float { return \Metis\Modules\Finance\FinanceModule::signedAmount( $direction, $amount ); }
function metis_finance_event_types(): array { return \Metis\Modules\Finance\FinanceModule::eventTypes(); }
function metis_finance_system_account_seed(): array { return \Metis\Modules\Finance\FinanceModule::systemAccountSeed(); }
function metis_finance_ensure_schema(): void { \Metis\Modules\Finance\FinanceModule::ensureSchema(); }
function metis_finance_seed_accounts(): void { \Metis\Modules\Finance\FinanceModule::seedAccounts(); }
function metis_finance_seed_funds(): void { \Metis\Modules\Finance\FinanceModule::seedFunds(); }
function metis_finance_default_fund_id(): int { return \Metis\Modules\Finance\FinanceModule::defaultFundId(); }
function metis_finance_find_fund_id( array $data ): int { return \Metis\Modules\Finance\FinanceModule::findFundId( $data ); }
function metis_finance_resolve_campaign_id( array $data ): ?int { return \Metis\Modules\Finance\FinanceModule::resolveCampaignId( $data ); }
function metis_finance_account_map(): array { return \Metis\Modules\Finance\FinanceModule::accountMap(); }
function metis_finance_decode_json( mixed $value ): array { return \Metis\Modules\Finance\FinanceModule::decodeJson( $value ); }
function metis_finance_ledger_direction( string $account_key, string $entry_side ): string { return \Metis\Modules\Finance\FinanceModule::ledgerDirection( $account_key, $entry_side ); }
function metis_finance_source_ref_for_event( object $event ): string { return \Metis\Modules\Finance\FinanceModule::sourceRefForEvent( $event ); }
function metis_finance_upsert_ledger_entry( string $account_key, string $source_type, string $source_ref, string $direction, float $amount, string $entry_date, string $status, string $memo, array $meta = [], array $extra = [] ): void { \Metis\Modules\Finance\FinanceModule::upsertLedgerEntry( $account_key, $source_type, $source_ref, $direction, $amount, $entry_date, $status, $memo, $meta, $extra ); }
function metis_finance_event_ledger_lines( object $event ): array { return \Metis\Modules\Finance\FinanceModule::eventLedgerLines( $event ); }
function metis_finance_generate_ledger( array $args = [] ): int { return \Metis\Modules\Finance\FinanceModule::generateLedger( $args ); }
function metis_finance_event_create( array $data, bool $auto_post = true ): int|\WP_Error { return \Metis\Modules\Finance\FinanceModule::eventCreate( $data, $auto_post ); }
function metis_finance_event_post( int $event_id ): int|\WP_Error { return \Metis\Modules\Finance\FinanceModule::eventPost( $event_id ); }
function finance_event_create( array $data, bool $auto_post = true ): int|\WP_Error { return \Metis\Modules\Finance\FinanceModule::eventCreate( $data, $auto_post ); }
function finance_event_post( int $event_id ): int|\WP_Error { return \Metis\Modules\Finance\FinanceModule::eventPost( $event_id ); }
function finance_generate_ledger( array $args = [] ): int { return \Metis\Modules\Finance\FinanceModule::generateLedger( $args ); }
function metis_finance_activity_type_options(): array { return \Metis\Modules\Finance\FinanceModule::activityTypeOptions(); }
function metis_finance_activity_label( string $type ): string { return \Metis\Modules\Finance\FinanceModule::activityLabel( $type ); }
function metis_finance_create_manual_activity( array $data ): int|\WP_Error { return \Metis\Modules\Finance\FinanceModule::createManualActivity( $data ); }
function metis_finance_create_manual_ledger_entry( array $data ): int|\WP_Error { return \Metis\Modules\Finance\FinanceModule::createManualLedgerEntry( $data ); }
function metis_finance_backfill_events_from_legacy( bool $regenerate_ledger = true ): array { return \Metis\Modules\Finance\FinanceModule::backfillEventsFromLegacy( $regenerate_ledger ); }
function metis_finance_sync_ledger_from_deposits(): void { \Metis\Modules\Finance\FinanceModule::syncLedgerFromDeposits(); }
function metis_finance_sync_reconciliations(): void { \Metis\Modules\Finance\FinanceModule::syncReconciliations(); }
