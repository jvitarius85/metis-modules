<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class SchemaManager {
    private static bool $schema_ready = false;

    public static function tableExists( string $table ): bool {
        $db = self::db();
        $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }

    public static function columnExists( string $table, string $column ): bool {
        $db = self::db();

        if ( ! self::tableExists( $table ) ) {
            return false;
        }

        $exists = $db->scalar( "SHOW COLUMNS FROM {$table} LIKE %s", [ $column ] );
        return ! empty( $exists );
    }

    public static function addColumnIfMissing( string $table, string $column, string $definition ): void {
        $db = self::db();

        if ( ! self::tableExists( $table ) ) {
            \Metis_Logger::warn( 'Contacts schema: table missing, skipped column migration', [ 'table' => $table, 'column' => $column ] );
            return;
        }

        if ( self::columnExists( $table, $column ) ) {
            return;
        }

        $db->execute( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
        \Metis_Logger::info( 'Contacts schema: column added', [ 'table' => $table, 'column' => $column ] );
    }

    public static function ensureSchema(): void {
        if ( self::$schema_ready ) {
            return;
        }

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table  = \Metis_Tables::get( 'contact_details' );
        $notes_table    = \Metis_Tables::get( 'contact_notes' );

        self::addColumnIfMissing( $contacts_table, 'first_name', "VARCHAR(120) DEFAULT ''" );
        self::addColumnIfMissing( $contacts_table, 'last_name', "VARCHAR(120) DEFAULT ''" );
        self::addColumnIfMissing( $contacts_table, 'cid', 'VARCHAR(16) DEFAULT NULL' );
        self::addColumnIfMissing( $contacts_table, 'contact_uid', 'VARCHAR(16) DEFAULT NULL' );
        self::addColumnIfMissing( $contacts_table, 'donor_uid', 'VARCHAR(16) DEFAULT NULL' );

        self::addColumnIfMissing( $details_table, 'contact_cid', 'VARCHAR(16) DEFAULT NULL' );
        self::addColumnIfMissing( $details_table, 'contact_id', 'BIGINT UNSIGNED DEFAULT NULL' );
        self::addColumnIfMissing( $details_table, 'phone', 'VARCHAR(50) DEFAULT NULL' );
        self::addColumnIfMissing( $details_table, 'preferred_name', 'VARCHAR(191) DEFAULT NULL' );
        self::addColumnIfMissing( $details_table, 'preferred_contact_method', 'VARCHAR(50) DEFAULT NULL' );
        self::addColumnIfMissing( $details_table, 'additional_emails_json', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $details_table, 'relationships_json', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $notes_table, 'cid', 'VARCHAR(191) DEFAULT NULL' );

        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$schema_ready = true;
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }
}
