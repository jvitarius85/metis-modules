<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Metis Database Installer & Legacy Newsletter Migration
 */

Metis_Logger::info( 'DB module loaded' );

function metis_core_db(): \Metis\Services\DatabaseService {
    return function_exists( 'metis_db' ) ? metis_db() : new \Metis\Services\DatabaseService();
}

function metis_core_db_charset_collate(): string {
    return metis_core_db()->get_charset_collate();
}

function metis_core_db_prefix(): string {
    return metis_core_db()->prefix();
}

// -------------------------------------------------------------------------
// DB Install
// -------------------------------------------------------------------------

function metis_install_db(): void {
    $charset_collate = metis_core_db_charset_collate();

    $contacts          = Metis_Tables::get( 'contacts' );
    $contact_dav_tokens = Metis_Tables::get( 'contact_dav_tokens' );
    $contact_dav_sync   = Metis_Tables::get( 'contact_dav_sync' );
    $newsletter_lists  = Metis_Tables::get( 'newsletter_lists' );
    $newsletter_subs   = Metis_Tables::get( 'newsletter_subs' );
    $settings          = Metis_Tables::get( 'settings' );
    $auth_users        = Metis_Tables::get( 'auth_users' );
    $job_queue         = Metis_Tables::get( 'job_queue' );
    $sync_state        = Metis_Tables::get( 'sync_state' );
    $media_files       = Metis_Tables::get( 'media_files' );
    $navigation_items  = Metis_Tables::get( 'navigation_items' );

    $sql_contacts = "
        CREATE TABLE IF NOT EXISTS {$contacts} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            did        VARCHAR(16)     DEFAULT NULL,
            email      VARCHAR(180)    NOT NULL,
            first_name VARCHAR(120)    DEFAULT '',
            last_name  VARCHAR(120)    DEFAULT '',
            created_at DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            UNIQUE KEY did   (did)
        ) {$charset_collate};
    ";

    $sql_newsletter_lists = "
        CREATE TABLE IF NOT EXISTS {$newsletter_lists} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_lid VARCHAR(50)     NULL,
            name       VARCHAR(255)    NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY legacy_lid (legacy_lid)
        ) {$charset_collate};
    ";

    $sql_newsletter_subs = "
        CREATE TABLE IF NOT EXISTS {$newsletter_subs} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            list_id    BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY combo (contact_id, list_id)
        ) {$charset_collate};
    ";

    $sql_settings = "
        CREATE TABLE IF NOT EXISTS {$settings} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key   VARCHAR(191)    NOT NULL,
            setting_value LONGTEXT        DEFAULT NULL,
            autoload      TINYINT(1)      DEFAULT 1,
            updated_at    DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) {$charset_collate};
    ";

    $sql_auth_users = "
        CREATE TABLE IF NOT EXISTS {$auth_users} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id     BIGINT UNSIGNED DEFAULT NULL,
            user_login    VARCHAR(120)    NOT NULL,
            user_email    VARCHAR(191)    NOT NULL,
            password_hash VARCHAR(255)    NOT NULL,
            display_name  VARCHAR(191)    NOT NULL,
            first_name    VARCHAR(120)    DEFAULT '',
            last_name     VARCHAR(120)    DEFAULT '',
            roles_json    LONGTEXT        DEFAULT NULL,
            is_active     TINYINT(1)      DEFAULT 1,
            last_login_at DATETIME        DEFAULT NULL,
            created_at    DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_login (user_login),
            UNIQUE KEY user_email (user_email),
            KEY person_id (person_id),
            KEY is_active (is_active)
        ) {$charset_collate};
    ";

    $sql_sync_state = "
        CREATE TABLE IF NOT EXISTS {$sync_state} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service     VARCHAR(191)    NOT NULL,
            last_sync   DATETIME        DEFAULT NULL,
            sync_token  LONGTEXT        DEFAULT NULL,
            updated_at  DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY service (service),
            KEY last_sync (last_sync)
        ) {$charset_collate};
    ";

    $sql_job_queue = "
        CREATE TABLE IF NOT EXISTS {$job_queue} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_code       VARCHAR(16)     NOT NULL,
            queue_name     VARCHAR(64)     NOT NULL DEFAULT 'default',
            job_type       VARCHAR(191)    NOT NULL,
            status         VARCHAR(24)     NOT NULL DEFAULT 'queued',
            dedupe_key     VARCHAR(191)    DEFAULT NULL,
            priority       SMALLINT UNSIGNED NOT NULL DEFAULT 50,
            attempts       INT UNSIGNED    NOT NULL DEFAULT 0,
            max_attempts   INT UNSIGNED    NOT NULL DEFAULT 3,
            available_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reserved_at    DATETIME        DEFAULT NULL,
            reserved_until DATETIME        DEFAULT NULL,
            started_at     DATETIME        DEFAULT NULL,
            completed_at   DATETIME        DEFAULT NULL,
            failed_at      DATETIME        DEFAULT NULL,
            last_error     TEXT            DEFAULT NULL,
            payload_json   LONGTEXT        DEFAULT NULL,
            result_json    LONGTEXT        DEFAULT NULL,
            created_by     BIGINT UNSIGNED DEFAULT NULL,
            created_at     DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY job_code (job_code),
            KEY queue_status_available (queue_name, status, available_at),
            KEY job_type (job_type),
            KEY dedupe_key (dedupe_key),
            KEY reserved_until (reserved_until)
        ) {$charset_collate};
    ";

    $sql_media_files = "
        CREATE TABLE IF NOT EXISTS {$media_files} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_token VARCHAR(64) NOT NULL,
            storage_class VARCHAR(32) NOT NULL DEFAULT 'legacy',
            storage_path VARCHAR(512) NOT NULL,
            access_expires_at DATETIME DEFAULT NULL,
            file_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(191) NOT NULL,
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            folder_path VARCHAR(255) NOT NULL DEFAULT '',
            category_key VARCHAR(80) NOT NULL DEFAULT '',
            uploaded_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY public_token (public_token),
            KEY storage_class (storage_class),
            KEY created_at (created_at),
            KEY mime_type (mime_type),
            KEY folder_path (folder_path),
            KEY category_key (category_key),
            KEY uploaded_by (uploaded_by)
        ) {$charset_collate};
    ";

    $sql_navigation_items = "
        CREATE TABLE IF NOT EXISTS {$navigation_items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(191) NOT NULL,
            route VARCHAR(255) NOT NULL DEFAULT '',
            icon TEXT NULL,
            parent_id BIGINT UNSIGNED NULL,
            position INT NOT NULL DEFAULT 0,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            permissions_required VARCHAR(191) NOT NULL DEFAULT '',
            module_key VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_module_key (module_key),
            KEY idx_parent_position (parent_id, position),
            KEY idx_visible (is_visible),
            KEY idx_module_key (module_key)
        ) {$charset_collate};
    ";

    $sql_contact_dav_tokens = "
        CREATE TABLE IF NOT EXISTS {$contact_dav_tokens} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            label        VARCHAR(191)    NOT NULL DEFAULT '',
            token_prefix VARCHAR(32)     NOT NULL DEFAULT '',
            token_hash   CHAR(64)        NOT NULL,
            last_used_at DATETIME        DEFAULT NULL,
            created_at   DATETIME        DEFAULT CURRENT_TIMESTAMP,
            revoked_at   DATETIME        DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id),
            KEY token_prefix (token_prefix)
        ) {$charset_collate};
    ";

    $sql_contact_dav_sync = "
        CREATE TABLE IF NOT EXISTS {$contact_dav_sync} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_slug     VARCHAR(191)    NOT NULL,
            contact_cid   VARCHAR(64)     NOT NULL,
            operation     VARCHAR(20)     NOT NULL DEFAULT 'upsert',
            contact_etag  CHAR(40)        DEFAULT NULL,
            changed_at    DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY book_slug (book_slug),
            KEY contact_cid (contact_cid),
            KEY changed_at (changed_at)
        ) {$charset_collate};
    ";

    metis_db_delta( $sql_contacts );
    metis_db_delta( $sql_contact_dav_tokens );
    metis_db_delta( $sql_contact_dav_sync );
    metis_db_delta( $sql_newsletter_lists );
    metis_db_delta( $sql_newsletter_subs );
    metis_db_delta( $sql_settings );
    metis_db_delta( $sql_auth_users );
    metis_db_delta( $sql_job_queue );
    metis_db_delta( $sql_media_files );
    metis_db_delta( $sql_sync_state );
    metis_db_delta( $sql_navigation_items );

    if ( function_exists( 'metis_entity_id_service' ) ) {
        metis_entity_id_service()->ensureSchema();
    }

    Metis_Logger::info( 'Core tables ensured' );
}

// -------------------------------------------------------------------------
// Legacy Newsletter Migration
// -------------------------------------------------------------------------

function metis_migrate_legacy_newsletter(): void {

    if ( ! metis_current_user_can( 'manage_options' ) ) {
        Metis_Logger::warn( 'Newsletter migration blocked — insufficient permissions' );
        return;
    }

    $db = metis_core_db();
    $legacy_contacts       = metis_core_db_prefix() . 'newsletter';
    $legacy_lists_singular = metis_core_db_prefix() . 'newsletter_clist';
    $legacy_lists_plural   = metis_core_db_prefix() . 'newsletter_clists';
    $legacy_lists          = null;

    // Require legacy contacts table
    if ( $db->scalar( 'SHOW TABLES LIKE %s', [ $legacy_contacts ] ) !== $legacy_contacts ) {
        Metis_Logger::error( 'Newsletter migration: legacy contacts table not found', [ 'table' => $legacy_contacts ] );
        return;
    }

    // Detect which legacy lists table exists
    if ( $db->scalar( 'SHOW TABLES LIKE %s', [ $legacy_lists_singular ] ) === $legacy_lists_singular ) {
        $legacy_lists = $legacy_lists_singular;
        Metis_Logger::info( 'Newsletter migration: using lists table', [ 'table' => $legacy_lists_singular ] );
    } elseif ( $db->scalar( 'SHOW TABLES LIKE %s', [ $legacy_lists_plural ] ) === $legacy_lists_plural ) {
        $legacy_lists = $legacy_lists_plural;
        Metis_Logger::info( 'Newsletter migration: using lists table', [ 'table' => $legacy_lists_plural ] );
    } else {
        Metis_Logger::error( 'Newsletter migration: no legacy lists table found' );
        return;
    }

    $contacts_table = Metis_Tables::get( 'contacts' );
    $lists_table    = Metis_Tables::get( 'newsletter_lists' );
    $subs_table     = Metis_Tables::get( 'newsletter_subs' );

    // Ensure destination tables exist
    metis_install_db();

    // 1) Import lists
    $lists    = $db->get_results( "SELECT * FROM {$legacy_lists}" );
    $list_map = [];

    foreach ( $lists as $l ) {
        $db->insert(
            $lists_table,
            [ 'legacy_lid' => $l->lid, 'name' => $l->name ],
            [ '%s', '%s' ]
        );
        $list_map[ $l->lid ] = $db->lastInsertId();
    }

    Metis_Logger::info( 'Newsletter migration: lists imported', [ 'count' => count( $list_map ) ] );

    // 2) Import contacts
    $legacy_rows  = $db->get_results( "SELECT * FROM {$legacy_contacts}" );
    $contact_map  = [];
    $contact_count = 0;

    foreach ( $legacy_rows as $r ) {
        $db->replace(
            $contacts_table,
            [
                'email'      => strtolower( trim( $r->email ) ),
                'first_name' => $r->name    ?? '',
                'last_name'  => $r->surname ?? '',
            ],
            [ '%s', '%s', '%s' ]
        );

        $new_id = $db->scalar( "SELECT id FROM {$contacts_table} WHERE email = %s", [ $r->email ] );

        if ( $new_id ) {
            $contact_map[ $r->id ] = $new_id;
            $contact_count++;
        }
    }

    Metis_Logger::info( 'Newsletter migration: contacts imported', [ 'count' => $contact_count ] );

    // 3) Import subscriptions
    $subs_count = 0;

    foreach ( $legacy_rows as $r ) {
        foreach ( $list_map as $legacy_lid => $new_list_id ) {
            if ( ! empty( $r->{$legacy_lid} ) ) {
                $db->replace(
                    $subs_table,
                    [
                        'contact_id' => $contact_map[ $r->id ],
                        'list_id'    => $new_list_id,
                    ],
                    [ '%d', '%d' ]
                );
                $subs_count++;
            }
        }
    }

    Metis_Logger::info( 'Newsletter migration: subscriptions imported', [ 'count' => $subs_count ] );
}

// -------------------------------------------------------------------------
// DID Backfill
// -------------------------------------------------------------------------

function metis_backfill_did_from_newsletter(): void {

    if ( ! metis_current_user_can( 'manage_options' ) ) {
        Metis_Logger::warn( 'DID backfill blocked — insufficient permissions' );
        return;
    }

    $db = metis_core_db();
    $legacy_contacts = metis_core_db_prefix() . 'newsletter';
    $contacts_table  = Metis_Tables::get( 'contacts' );

    if ( $db->scalar( 'SHOW TABLES LIKE %s', [ $legacy_contacts ] ) !== $legacy_contacts ) {
        Metis_Logger::error( 'DID backfill: legacy newsletter table missing' );
        return;
    }

    if ( $db->scalar( 'SHOW TABLES LIKE %s', [ $contacts_table ] ) !== $contacts_table ) {
        Metis_Logger::error( 'DID backfill: contacts table missing', [ 'table' => $contacts_table ] );
        return;
    }

    $rows    = $db->get_results( "
        SELECT email, profile_3
        FROM {$legacy_contacts}
        WHERE profile_3 IS NOT NULL
          AND profile_3 <> ''
    " );
    $updated = 0;

    foreach ( $rows as $r ) {
        $email = strtolower( trim( $r->email ) );
        $did   = trim( $r->profile_3 );

        if ( ! $email || ! $did ) continue;

        $res = $db->update(
            $contacts_table,
            [ 'did'   => $did   ],
            [ 'email' => $email ],
            [ '%s' ],
            [ '%s' ]
        );

        if ( $res !== false && $res > 0 ) {
            $updated++;
        }
    }

    Metis_Logger::info( 'DID backfill complete', [ 'updated' => $updated ] );
}

// -------------------------------------------------------------------------
// Admin URL triggers (manage_options only)
// -------------------------------------------------------------------------

function metis_db_maintenance_service(): \Metis\Core\Services\MaintenanceService {
    if ( \Metis\Core\Application::has_service( 'maintenance' ) ) {
        return \Metis\Core\Application::service( 'maintenance' );
    }

    return new \Metis\Core\Services\MaintenanceService();
}

function metis_db_maintenance_request_matches( string $query_arg ): bool {
    if ( isset( metis_request_post()['maintenance_action'] ) && metis_key_clean( (string) metis_request_post()['maintenance_action'] ) === metis_key_clean( $query_arg ) ) {
        return true;
    }

    return isset( metis_request_get()[ $query_arg ] );
}

function metis_db_run_maintenance_action( string $query_arg, string $nonce_action, string $title, string $message, callable $callback, string $audit_action ): void {
    if ( ! metis_db_maintenance_request_matches( $query_arg ) ) {
        return;
    }

    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        metis_db_maintenance_service()->renderPostConfirmation(
            $title,
            $message,
            $nonce_action,
            [ 'maintenance_action' => $query_arg ]
        );
    }

    metis_db_maintenance_service()->assertAuthorizedMutation( $nonce_action );
    $callback();
    metis_db_maintenance_service()->auditMutation( $audit_action, [ 'query_arg' => $query_arg ] );
}

metis_on( 'init', function () {

    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( metis_db_maintenance_request_matches( 'metis_backfill_did' ) ) {
        metis_db_run_maintenance_action(
            'metis_backfill_did',
            'metis_db_backfill_did',
            'Confirm DID Backfill',
            'Run the legacy DID backfill migration.',
            'metis_backfill_did_from_newsletter',
            'db_backfill_did'
        );
        metis_runtime_die( 'Metis: DID backfill complete. Check logs.' );
    }

    if ( metis_db_maintenance_request_matches( 'metis_migrate_newsletter' ) ) {
        metis_db_run_maintenance_action(
            'metis_migrate_newsletter',
            'metis_db_migrate_newsletter',
            'Confirm Newsletter Migration',
            'Run the legacy newsletter migration.',
            'metis_migrate_legacy_newsletter',
            'db_migrate_newsletter'
        );
        metis_runtime_die( 'Metis: Newsletter migration complete. Check logs.' );
    }

    if ( metis_db_maintenance_request_matches( 'metis_add_tx_timestamps' ) ) {
        metis_db_run_maintenance_action(
            'metis_add_tx_timestamps',
            'metis_db_add_tx_timestamps',
            'Confirm Transaction Timestamp Migration',
            'Add created_at and updated_at to transactions if they are missing.',
            'metis_add_tx_timestamps',
            'db_add_tx_timestamps'
        );
        metis_runtime_die( 'Metis: TX timestamp columns migration complete. Check logs.' );
    }

    if ( metis_db_maintenance_request_matches( 'metis_fix_tx_did_nullable' ) ) {
        metis_db_run_maintenance_action(
            'metis_fix_tx_did_nullable',
            'metis_db_fix_tx_did_nullable',
            'Confirm DID Nullable Migration',
            'Update the transaction DID column nullability.',
            'metis_fix_tx_did_nullable',
            'db_fix_tx_did_nullable'
        );
        metis_runtime_die( 'Metis: TX did nullable migration complete. Check logs.' );
    }
} );

function metis_fix_tx_did_nullable(): void {
    $db = metis_core_db();
    $tx_table = Metis_Tables::get( 'transactions' );
    $db->execute( "ALTER TABLE {$tx_table} MODIFY COLUMN did VARCHAR(16) DEFAULT NULL" );
    Metis_Logger::info( 'TX migration: did column set to nullable' );
}

// -------------------------------------------------------------------------
// One-time migration: add stripe_refund_id to metis_transactions
// Trigger via: ?metis_add_tx_refund_id=1
// -------------------------------------------------------------------------

metis_on( 'init', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) return;
    if ( ! metis_db_maintenance_request_matches( 'metis_add_tx_refund_id' ) ) return;
    metis_db_run_maintenance_action(
        'metis_add_tx_refund_id',
        'metis_db_add_tx_refund_id',
        'Confirm Transaction Refund ID Migration',
        'Add stripe_refund_id to transactions if it is missing.',
        'metis_add_tx_refund_id',
        'db_add_tx_refund_id'
    );
    metis_runtime_die( 'Metis: stripe_refund_id migration complete. Check logs.' );
} );

// -------------------------------------------------------------------------
// One-time migration: add stripe_refund_id to metis_transaction_refunds
// Trigger via: ?metis_add_refund_stripe_id=1
// -------------------------------------------------------------------------

metis_on( 'init', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) return;
    if ( ! metis_db_maintenance_request_matches( 'metis_add_refund_stripe_id' ) ) return;
    metis_db_run_maintenance_action(
        'metis_add_refund_stripe_id',
        'metis_db_add_refund_stripe_id',
        'Confirm Refund Stripe ID Migration',
        'Add stripe_refund_id to transaction_refunds if it is missing.',
        'metis_add_refund_stripe_id',
        'db_add_refund_stripe_id'
    );
    metis_runtime_die( 'Metis: transaction_refunds stripe_refund_id migration complete.' );
} );

function metis_add_refund_stripe_id(): void {
    $db = metis_core_db();
    $table   = Metis_Tables::get( 'transaction_refunds' );
    $columns = $db->column( "SHOW COLUMNS FROM {$table}" );
    if ( ! in_array( 'stripe_refund_id', $columns, true ) ) {
        $db->execute( "ALTER TABLE {$table} ADD COLUMN stripe_refund_id VARCHAR(64) DEFAULT NULL AFTER tid" );
        Metis_Logger::info( 'Refunds migration: stripe_refund_id column added' );
    } else {
        Metis_Logger::info( 'Refunds migration: stripe_refund_id already exists, skipped' );
    }
}

function metis_add_tx_refund_id(): void {
    $db = metis_core_db();
    $tx_table = Metis_Tables::get( 'transactions' );
    $columns  = $db->column( "SHOW COLUMNS FROM {$tx_table}" );
    if ( ! in_array( 'stripe_refund_id', $columns, true ) ) {
        $db->execute( "ALTER TABLE {$tx_table} ADD COLUMN stripe_refund_id VARCHAR(64) DEFAULT NULL AFTER stripe_payout_id" );
        Metis_Logger::info( 'TX migration: stripe_refund_id column added' );
    } else {
        Metis_Logger::info( 'TX migration: stripe_refund_id already exists, skipped' );
    }
}

// -------------------------------------------------------------------------
// Create metis_transaction_refunds table
// Trigger via: ?metis_create_refunds_table=1
// -------------------------------------------------------------------------

metis_on( 'init', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) return;
    if ( ! metis_db_maintenance_request_matches( 'metis_create_refunds_table' ) ) return;
    metis_db_run_maintenance_action(
        'metis_create_refunds_table',
        'metis_db_create_refunds_table',
        'Confirm Refund Table Creation',
        'Create the transaction_refunds table if it is missing.',
        'metis_create_refunds_table',
        'db_create_refunds_table'
    );
    metis_runtime_die( 'Metis: transaction_refunds table created. Check logs.' );
} );

function metis_create_refunds_table(): void {
    $charset_collate = metis_core_db_charset_collate();
    $table = Metis_Tables::get( 'transaction_refunds' );

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tid              VARCHAR(16)     NOT NULL,
        refund_date      DATE            NOT NULL,
        amount           DECIMAL(10,2)   NOT NULL,
        reason           VARCHAR(255)    DEFAULT NULL,
        notes            TEXT            DEFAULT NULL,
        source           VARCHAR(32)     NOT NULL DEFAULT 'manual',
        stripe_refund_id VARCHAR(64)     DEFAULT NULL,
        refunded_by      BIGINT UNSIGNED DEFAULT NULL,
        created_at       DATETIME        DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY tid (tid),
        KEY stripe_refund_id (stripe_refund_id)
    ) {$charset_collate};";

    metis_db_delta( $sql );
    Metis_Logger::info( 'Transaction refunds table ensured' );
}

// -------------------------------------------------------------------------
// One-time migration: add created_at / updated_at to metis_transactions
// Trigger via: ?metis_add_tx_timestamps=1
// -------------------------------------------------------------------------

function metis_add_tx_timestamps(): void {
    $db = metis_core_db();
    $tx_table = Metis_Tables::get( 'transactions' );

    $columns = $db->column( "SHOW COLUMNS FROM {$tx_table}" );

    if ( ! in_array( 'created_at', $columns, true ) ) {
        $db->execute( "ALTER TABLE {$tx_table} ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP" );
        Metis_Logger::info( 'TX migration: created_at column added' );
    } else {
        Metis_Logger::info( 'TX migration: created_at already exists, skipped' );
    }

    if ( ! in_array( 'updated_at', $columns, true ) ) {
        $db->execute( "ALTER TABLE {$tx_table} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" );
        Metis_Logger::info( 'TX migration: updated_at column added' );
    } else {
        Metis_Logger::info( 'TX migration: updated_at already exists, skipped' );
    }
}
