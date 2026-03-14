<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class FinanceModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Finance bootstrap loaded' );

        \metis_add_action( 'init', [ self::class, 'handleMigrateEventsRequest' ] );
        \metis_add_action( 'init', [ self::class, 'ensureSchema' ], 5 );

        \Metis_Logger::info( 'Finance bootstrap ready' );
    }

    public static function canView(): bool { return Access::canView(); }
    public static function canManage(): bool { return Access::canManage(); }
    public static function baseUrl(): string { return Support::baseUrl(); }
    public static function tableExists( string $table ): bool { return Support::tableExists( $table ); }
    public static function tableHasColumn( string $table, string $column ): bool { return Support::tableHasColumn( $table, $column ); }
    public static function currency( float $amount ): string { return Support::currency( $amount ); }
    public static function shortDate( ?string $date ): string { return Support::shortDate( $date ); }
    public static function signedAmount( string $direction, float $amount ): float { return Support::signedAmount( $direction, $amount ); }
    public static function eventTypes(): array { return Support::eventTypes(); }
    public static function systemAccountSeed(): array { return Support::systemAccountSeed(); }
    public static function ensureSchema(): void { SchemaManager::ensureSchema(); }
    public static function seedAccounts(): void { SchemaManager::seedAccounts(); }
    public static function seedFunds(): void { SchemaManager::seedFunds(); }
    public static function defaultFundId(): int { return SchemaManager::defaultFundId(); }
    public static function findFundId( array $data ): int { return SchemaManager::findFundId( $data ); }
    public static function resolveCampaignId( array $data ): ?int { return SchemaManager::resolveCampaignId( $data ); }
    public static function accountMap(): array { return SchemaManager::accountMap(); }
    public static function decodeJson( mixed $value ): array { return Support::decodeJson( $value ); }
    public static function ledgerDirection( string $account_key, string $entry_side ): string { return LedgerService::ledgerDirection( $account_key, $entry_side ); }
    public static function sourceRefForEvent( object $event ): string { return LedgerService::sourceRefForEvent( $event ); }
    public static function upsertLedgerEntry(string $account_key, string $source_type, string $source_ref, string $direction, float $amount, string $entry_date, string $status, string $memo, array $meta = [], array $extra = []): void { LedgerService::upsertLedgerEntry( $account_key, $source_type, $source_ref, $direction, $amount, $entry_date, $status, $memo, $meta, $extra ); }
    public static function eventLedgerLines( object $event ): array { return LedgerService::eventLedgerLines( $event ); }
    public static function generateLedger( array $args = [] ): int { return LedgerService::generateLedger( $args ); }
    public static function eventCreate( array $data, bool $auto_post = true ): int|\WP_Error { return FinanceService::eventCreate( $data, $auto_post ); }
    public static function eventPost( int $event_id ): int|\WP_Error { return FinanceService::eventPost( $event_id ); }
    public static function activityTypeOptions(): array { return Support::activityTypeOptions(); }
    public static function activityLabel( string $type ): string { return Support::activityLabel( $type ); }
    public static function createManualActivity( array $data ): int|\WP_Error { return FinanceService::createManualActivity( $data ); }
    public static function createManualLedgerEntry( array $data ): int|\WP_Error { return FinanceService::createManualLedgerEntry( $data ); }
    public static function backfillEventsFromLegacy( bool $regenerate_ledger = true ): array { return FinanceService::backfillEventsFromLegacy( $regenerate_ledger ); }
    public static function syncLedgerFromDeposits(): void { LedgerService::syncLedgerFromDeposits(); }
    public static function syncReconciliations(): void { LedgerService::syncReconciliations(); }

    public static function handleMigrateEventsRequest(): void {
        if ( ! \metis_user_logged_in() || ! \metis_current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['metis_finance_migrate_events'] ) ) {
            $result = FinanceService::backfillEventsFromLegacy( true );
            \metis_die( 'Metis: finance events migration complete. Events: ' . (int) $result['event_count'] . '.' );
        }
    }
}
