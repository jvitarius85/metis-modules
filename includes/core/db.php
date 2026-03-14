<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Metis Database Installer & Legacy Newsletter Migration
 */

Metis_Logger::info( 'DB module loaded' );

// -------------------------------------------------------------------------
// DB Install
// -------------------------------------------------------------------------

function metis_install_db(): void {

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $contacts          = Metis_Tables::get( 'contacts' );
    $contact_dav_tokens = Metis_Tables::get( 'contact_dav_tokens' );
    $contact_dav_sync   = Metis_Tables::get( 'contact_dav_sync' );
    $newsletter_lists  = Metis_Tables::get( 'newsletter_lists' );
    $newsletter_subs   = Metis_Tables::get( 'newsletter_subs' );
    $settings          = Metis_Tables::get( 'settings' );
    $auth_users        = Metis_Tables::get( 'auth_users' );
    $sync_state        = Metis_Tables::get( 'sync_state' );

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

    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta( $sql_contacts );
    dbDelta( $sql_contact_dav_tokens );
    dbDelta( $sql_contact_dav_sync );
    dbDelta( $sql_newsletter_lists );
    dbDelta( $sql_newsletter_subs );
    dbDelta( $sql_settings );
    dbDelta( $sql_auth_users );
    dbDelta( $sql_sync_state );

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

    global $wpdb;

    $legacy_contacts       = $wpdb->prefix . 'newsletter';
    $legacy_lists_singular = $wpdb->prefix . 'newsletter_clist';
    $legacy_lists_plural   = $wpdb->prefix . 'newsletter_clists';
    $legacy_lists          = null;

    // Require legacy contacts table
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$legacy_contacts}'" ) !== $legacy_contacts ) {
        Metis_Logger::error( 'Newsletter migration: legacy contacts table not found', [ 'table' => $legacy_contacts ] );
        return;
    }

    // Detect which legacy lists table exists
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$legacy_lists_singular}'" ) === $legacy_lists_singular ) {
        $legacy_lists = $legacy_lists_singular;
        Metis_Logger::info( 'Newsletter migration: using lists table', [ 'table' => $legacy_lists_singular ] );
    } elseif ( $wpdb->get_var( "SHOW TABLES LIKE '{$legacy_lists_plural}'" ) === $legacy_lists_plural ) {
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
    $lists    = $wpdb->get_results( "SELECT * FROM {$legacy_lists}" );
    $list_map = [];

    foreach ( $lists as $l ) {
        $wpdb->insert(
            $lists_table,
            [ 'legacy_lid' => $l->lid, 'name' => $l->name ],
            [ '%s', '%s' ]
        );
        $list_map[ $l->lid ] = $wpdb->insert_id;
    }

    Metis_Logger::info( 'Newsletter migration: lists imported', [ 'count' => count( $list_map ) ] );

    // 2) Import contacts
    $legacy_rows  = $wpdb->get_results( "SELECT * FROM {$legacy_contacts}" );
    $contact_map  = [];
    $contact_count = 0;

    foreach ( $legacy_rows as $r ) {
        $wpdb->replace(
            $contacts_table,
            [
                'email'      => strtolower( trim( $r->email ) ),
                'first_name' => $r->name    ?? '',
                'last_name'  => $r->surname ?? '',
            ],
            [ '%s', '%s', '%s' ]
        );

        $new_id = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$contacts_table} WHERE email = %s", $r->email )
        );

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
                $wpdb->replace(
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

    global $wpdb;

    $legacy_contacts = $wpdb->prefix . 'newsletter';
    $contacts_table  = Metis_Tables::get( 'contacts' );

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$legacy_contacts}'" ) !== $legacy_contacts ) {
        Metis_Logger::error( 'DID backfill: legacy newsletter table missing' );
        return;
    }

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$contacts_table}'" ) !== $contacts_table ) {
        Metis_Logger::error( 'DID backfill: contacts table missing', [ 'table' => $contacts_table ] );
        return;
    }

    $rows    = $wpdb->get_results( "
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

        $res = $wpdb->update(
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

metis_add_action( 'init', function () {

    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_GET['metis_backfill_did'] ) ) {
        metis_backfill_did_from_newsletter();
        metis_die( 'Metis: DID backfill complete. Check logs.' );
    }

    if ( isset( $_GET['metis_migrate_newsletter'] ) ) {
        metis_migrate_legacy_newsletter();
        metis_die( 'Metis: Newsletter migration complete. Check logs.' );
    }

    if ( isset( $_GET['metis_add_tx_timestamps'] ) ) {
        metis_add_tx_timestamps();
        metis_die( 'Metis: TX timestamp columns migration complete. Check logs.' );
    }

    if ( isset( $_GET['metis_fix_tx_did_nullable'] ) ) {
        metis_fix_tx_did_nullable();
        metis_die( 'Metis: TX did nullable migration complete. Check logs.' );
    }
} );

function metis_fix_tx_did_nullable(): void {
    global $wpdb;
    $tx_table = Metis_Tables::get( 'transactions' );
    $wpdb->query( "ALTER TABLE {$tx_table} MODIFY COLUMN did VARCHAR(16) DEFAULT NULL" );
    Metis_Logger::info( 'TX migration: did column set to nullable' );
}

// -------------------------------------------------------------------------
// One-time migration: add stripe_refund_id to metis_transactions
// Trigger via: ?metis_add_tx_refund_id=1
// -------------------------------------------------------------------------

metis_add_action( 'init', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) return;
    if ( isset( $_GET['metis_add_tx_refund_id'] ) ) {
        metis_add_tx_refund_id();
        metis_die( 'Metis: stripe_refund_id migration complete. Check logs.' );
    }
} );

// -------------------------------------------------------------------------
// One-time migration: add stripe_refund_id to metis_transaction_refunds
// Trigger via: ?metis_add_refund_stripe_id=1
// -------------------------------------------------------------------------

metis_add_action( 'init', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) return;
    if ( isset( $_GET['metis_add_refund_stripe_id'] ) ) {
        metis_add_refund_stripe_id();
        metis_die( 'Metis: transaction_refunds stripe_refund_id migration complete.' );
    }
} );

function metis_add_refund_stripe_id(): void {
    global $wpdb;
    $table   = Metis_Tables::get( 'transaction_refunds' );
    $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
    if ( ! in_array( 'stripe_refund_id', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN stripe_refund_id VARCHAR(64) DEFAULT NULL AFTER tid" );
        Metis_Logger::info( 'Refunds migration: stripe_refund_id column added' );
    } else {
        Metis_Logger::info( 'Refunds migration: stripe_refund_id already exists, skipped' );
    }
}

function metis_add_tx_refund_id(): void {
    global $wpdb;
    $tx_table = Metis_Tables::get( 'transactions' );
    $columns  = $wpdb->get_col( "SHOW COLUMNS FROM {$tx_table}" );
    if ( ! in_array( 'stripe_refund_id', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$tx_table} ADD COLUMN stripe_refund_id VARCHAR(64) DEFAULT NULL AFTER stripe_payout_id" );
        Metis_Logger::info( 'TX migration: stripe_refund_id column added' );
    } else {
        Metis_Logger::info( 'TX migration: stripe_refund_id already exists, skipped' );
    }
}

// -------------------------------------------------------------------------
// Create metis_transaction_refunds table
// Trigger via: ?metis_create_refunds_table=1
// -------------------------------------------------------------------------

metis_add_action( 'init', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) return;
    if ( isset( $_GET['metis_create_refunds_table'] ) ) {
        metis_create_refunds_table();
        metis_die( 'Metis: transaction_refunds table created. Check logs.' );
    }
} );

function metis_create_refunds_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
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

    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    dbDelta( $sql );
    Metis_Logger::info( 'Transaction refunds table ensured' );
}

// -------------------------------------------------------------------------
// One-time migration: add created_at / updated_at to metis_transactions
// Trigger via: ?metis_add_tx_timestamps=1
// -------------------------------------------------------------------------

function metis_add_tx_timestamps(): void {

    global $wpdb;
    $tx_table = Metis_Tables::get( 'transactions' );

    $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$tx_table}" );

    if ( ! in_array( 'created_at', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$tx_table} ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP" );
        Metis_Logger::info( 'TX migration: created_at column added' );
    } else {
        Metis_Logger::info( 'TX migration: created_at already exists, skipped' );
    }

    if ( ! in_array( 'updated_at', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$tx_table} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" );
        Metis_Logger::info( 'TX migration: updated_at column added' );
    } else {
        Metis_Logger::info( 'TX migration: updated_at already exists, skipped' );
    }
}
