<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class ContactsModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Contacts bootstrap loaded' );

        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );
        \metis_on( 'init', [ self::class, 'handleBackfillCidRequest' ], 20 );
        \metis_on( 'init', [ self::class, 'handleMigrateNotesRequest' ], 20 );
        \metis_on( 'init', [ self::class, 'handleCleanupMergeNotesRequest' ], 20 );
    }

    public static function canView(): bool {
        return Support::canView();
    }

    public static function canManage(): bool {
        return Support::canManage();
    }

    public static function baseUrl(): string {
        return Support::baseUrl();
    }

    public static function detailUrl( string $cid ): string {
        return Support::detailUrl( $cid );
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'contacts_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }

    public static function backfillCid(): int {
        return MaintenanceManager::backfillCid();
    }

    public static function resolvedTimezone(): \DateTimeZone {
        return Support::resolvedTimezone();
    }

    public static function formatDatetime( string $mysql_datetime, string $format = 'm/d/y g:ia' ): string {
        return Support::formatDatetime( $mysql_datetime, $format );
    }

    public static function migrateNotesToCid(): array {
        return MaintenanceManager::migrateNotesToCid();
    }

    public static function cleanupMergeNotes(): array {
        return MaintenanceManager::cleanupMergeNotes();
    }

    public static function tableExists( string $table ): bool {
        return SchemaManager::tableExists( $table );
    }

    public static function columnExists( string $table, string $column ): bool {
        return SchemaManager::columnExists( $table, $column );
    }

    public static function addColumnIfMissing( string $table, string $column, string $definition ): void {
        SchemaManager::addColumnIfMissing( $table, $column, $definition );
    }

    public static function handleBackfillCidRequest(): void {
        if ( ! self::adminActionRequested( 'metis_backfill_contact_cid' ) ) {
            return;
        }

        self::ensureSchema();
        $count = self::backfillCid();
        \metis_runtime_die( 'Metis: contact CID backfill complete. Updated ' . intval( $count ) . ' contacts.' );
    }

    public static function handleMigrateNotesRequest(): void {
        if ( ! self::adminActionRequested( 'metis_migrate_contact_notes_to_cid' ) ) {
            return;
        }

        self::ensureSchema();
        $result  = self::migrateNotesToCid();
        $updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
        \metis_runtime_die( 'Metis: contact notes migration to CID complete. Updated ' . $updated . ' notes.' );
    }

    public static function handleCleanupMergeNotesRequest(): void {
        if ( ! self::adminActionRequested( 'metis_cleanup_contact_merge_notes' ) ) {
            return;
        }

        self::ensureSchema();
        $result = self::cleanupMergeNotes();
        \metis_runtime_die(
            sprintf(
                'Metis: merge notes cleanup complete. Consolidated %d groups, created %d notes, deleted %d notes.',
                (int) ( $result['groups_consolidated'] ?? 0 ),
                (int) ( $result['notes_created'] ?? 0 ),
                (int) ( $result['notes_deleted'] ?? 0 )
            )
        );
    }

    private static function adminActionRequested( string $param ): bool {
        if ( ! \metis_user_logged_in() || ! \metis_current_user_can( 'manage_options' ) ) {
            return false;
        }

        return isset( metis_request_get()[ $param ] );
    }
}
