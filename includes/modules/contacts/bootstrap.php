<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/core/autoload.php';

\Metis\Modules\Contacts\ContactsModule::boot();

function metis_contacts_can_view(): bool {
    return \Metis\Modules\Contacts\ContactsModule::canView();
}

function metis_contacts_can_manage(): bool {
    return \Metis\Modules\Contacts\ContactsModule::canManage();
}

function metis_contacts_base_url(): string {
    return \Metis\Modules\Contacts\ContactsModule::baseUrl();
}

function metis_contacts_detail_url( string $cid ): string {
    return \Metis\Modules\Contacts\ContactsModule::detailUrl( $cid );
}

function metis_contacts_table_exists( string $table ): bool {
    return \Metis\Modules\Contacts\ContactsModule::tableExists( $table );
}

function metis_contacts_column_exists( string $table, string $column ): bool {
    return \Metis\Modules\Contacts\ContactsModule::columnExists( $table, $column );
}

function metis_contacts_add_column_if_missing( string $table, string $column, string $definition ): void {
    \Metis\Modules\Contacts\ContactsModule::addColumnIfMissing( $table, $column, $definition );
}

function metis_contacts_ensure_schema(): void {
    \Metis\Modules\Contacts\ContactsModule::ensureSchema();
}

function metis_contacts_backfill_cid(): int {
    return \Metis\Modules\Contacts\ContactsModule::backfillCid();
}

function metis_contacts_resolved_timezone(): DateTimeZone {
    return \Metis\Modules\Contacts\ContactsModule::resolvedTimezone();
}

function metis_contacts_format_datetime( string $mysql_datetime, string $format = 'm/d/y g:ia' ): string {
    return \Metis\Modules\Contacts\ContactsModule::formatDatetime( $mysql_datetime, $format );
}

function metis_contacts_migrate_notes_to_cid(): array {
    return \Metis\Modules\Contacts\ContactsModule::migrateNotesToCid();
}

function metis_contacts_cleanup_merge_notes(): array {
    return \Metis\Modules\Contacts\ContactsModule::cleanupMergeNotes();
}
