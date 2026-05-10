<?php
declare(strict_types=1);

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Core/Runtime/CliToolGuard.php';
metis_require_cli_tool();
require_once $root . '/src/Metis/Core/TablesRegistry.php';
require_once $root . '/src/Metis/Core/Runtime/StandaloneBootstrap.php';
require_once $root . '/src/Metis/Services/DatabaseService.php';

$args = $argv;
array_shift( $args );

$apply = in_array( '--apply', $args, true );
$host = '127.0.0.1';
$port = null;
$database = null;
$username = null;
$password = null;

foreach ( $args as $arg ) {
    if ( ! str_contains( $arg, '=' ) ) {
        continue;
    }
    [ $name, $value ] = array_pad( explode( '=', $arg, 2 ), 2, '' );
    switch ( $name ) {
        case '--host':
            $host = $value !== '' ? $value : $host;
            break;
        case '--port':
            $port = (int) $value;
            break;
        case '--database':
            $database = $value;
            break;
        case '--username':
            $username = $value;
            break;
        case '--password':
            $password = $value;
            break;
    }
}

$config = require $root . '/config/database.php';
if ( ! is_array( $config ) ) {
    fwrite( STDERR, "Database configuration is invalid.\n" );
    exit( 1 );
}

$port = $port ?: (int) ( $config['port'] ?? 3306 );
$database = $database ?: (string) ( $config['database'] ?? '' );
$username = $username ?: (string) ( $config['username'] ?? '' );
$password = $password ?? (string) ( $config['password'] ?? '' );

if ( $database === '' || $username === '' ) {
    fwrite( STDERR, "Database connection parameters are incomplete.\n" );
    exit( 1 );
}

Metis_Tables::init( (string) ( $config['prefix'] ?? '' ) );

$GLOBALS['metis_runtime_config'] = array_merge( (array) ( $GLOBALS['metis_runtime_config'] ?? [] ), [
    'db_charset' => 'utf8mb4',
] );
$db = new \Metis\Services\DatabaseService(
    new \MetisRuntimeDbConnection(
        $username,
        $password,
        $database,
        $host . ':' . $port,
        (string) ( $config['prefix'] ?? '' )
    )
);

$tableExists = static function ( string $table ) use ( $db ): bool {
    return (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
        [ $table ]
    ) > 0;
};

$fetchCount = static function ( string $table ) use ( $db ): int {
    return (int) $db->scalar( "SELECT COUNT(*) FROM `{$table}`" );
};

$summary = [
    'apply' => $apply,
    'host' => $host,
    'database' => $database,
    'migrations' => [],
];

$runSql = static function ( string $sql ) use ( $apply, $db ): void {
    if ( ! $apply ) {
        return;
    }
    $db->execute( $sql );
};

$ensureCanonicalTable = static function ( string $table, string $sql ) use ( $apply, $db, $tableExists ): void {
    if ( $tableExists( $table ) ) {
        return;
    }
    if ( ! $apply ) {
        return;
    }
    $db->execute( $sql );
};

$emailUsageCanonical = Metis_Tables::get( 'email_usage_daily' );
$emailEventsCanonical = Metis_Tables::get( 'email_send_events' );

$ensureCanonicalTable(
    $emailUsageCanonical,
    "CREATE TABLE IF NOT EXISTS `{$emailUsageCanonical}` (
        usage_date DATE NOT NULL,
        module_slug VARCHAR(64) NOT NULL,
        sent_count INT UNSIGNED NOT NULL DEFAULT 0,
        failed_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_provider VARCHAR(64) DEFAULT NULL,
        last_sent_at DATETIME DEFAULT NULL,
        last_failed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (usage_date, module_slug),
        KEY module_date (module_slug, usage_date)
    )"
);

$ensureCanonicalTable(
    $emailEventsCanonical,
    "CREATE TABLE IF NOT EXISTS `{$emailEventsCanonical}` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_at DATETIME NOT NULL,
        module_slug VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL,
        provider VARCHAR(64) DEFAULT NULL,
        to_email VARCHAR(191) DEFAULT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        meta_json LONGTEXT DEFAULT NULL,
        PRIMARY KEY (id),
        KEY module_event_at (module_slug, event_at),
        KEY status_event_at (status, event_at)
    )"
);

$migrations = [
    [
        'label' => 'newsletter_subscriptions',
        'canonical' => Metis_Tables::get( 'newsletter_subs' ),
        'legacy' => 'metis_newsletter_subscriptions',
        'merge_sql' => static function ( string $canonical, string $legacy ): array {
            return [
                "INSERT INTO `{$canonical}` (
                    contact_id, list_id, status, source, subscribed_at, unsubscribed_at,
                    bounce_count, last_event_at, created_at, updated_at
                )
                SELECT
                    l.contact_id, l.list_id, l.status, l.source, l.subscribed_at, l.unsubscribed_at,
                    l.bounce_count, l.last_event_at, l.created_at, l.updated_at
                FROM `{$legacy}` l
                LEFT JOIN `{$canonical}` c
                    ON c.contact_id = l.contact_id
                   AND c.list_id = l.list_id
                WHERE c.id IS NULL",
                "UPDATE `{$canonical}` c
                JOIN `{$legacy}` l
                    ON c.contact_id = l.contact_id
                   AND c.list_id = l.list_id
                SET
                    c.status = CASE
                        WHEN l.status = 'unsubscribed' THEN l.status
                        ELSE c.status
                    END,
                    c.source = CASE
                        WHEN (c.source IS NULL OR c.source = '') AND l.source IS NOT NULL AND l.source <> '' THEN l.source
                        ELSE c.source
                    END,
                    c.subscribed_at = CASE
                        WHEN l.subscribed_at IS NULL THEN c.subscribed_at
                        WHEN c.subscribed_at IS NULL THEN l.subscribed_at
                        ELSE LEAST(c.subscribed_at, l.subscribed_at)
                    END,
                    c.unsubscribed_at = CASE
                        WHEN c.unsubscribed_at IS NULL THEN l.unsubscribed_at
                        WHEN l.unsubscribed_at IS NULL THEN c.unsubscribed_at
                        ELSE GREATEST(c.unsubscribed_at, l.unsubscribed_at)
                    END,
                    c.bounce_count = GREATEST(c.bounce_count, l.bounce_count),
                    c.last_event_at = CASE
                        WHEN c.last_event_at IS NULL THEN l.last_event_at
                        WHEN l.last_event_at IS NULL THEN c.last_event_at
                        ELSE GREATEST(c.last_event_at, l.last_event_at)
                    END,
                    c.created_at = CASE
                        WHEN l.created_at IS NULL THEN c.created_at
                        WHEN c.created_at IS NULL THEN l.created_at
                        ELSE LEAST(c.created_at, l.created_at)
                    END,
                    c.updated_at = CASE
                        WHEN c.updated_at IS NULL THEN l.updated_at
                        WHEN l.updated_at IS NULL THEN c.updated_at
                        ELSE GREATEST(c.updated_at, l.updated_at)
                    END",
            ];
        },
    ],
    [
        'label' => 'people_role_permissions',
        'canonical' => Metis_Tables::get( 'people_role_perms' ),
        'legacy' => 'metis_people_role_permissions',
        'merge_sql' => static function ( string $canonical, string $legacy ): array {
            return [
                "INSERT INTO `{$canonical}` (
                    role_id, permission_id, allow_access, created_at, updated_at
                )
                SELECT
                    l.role_id, l.permission_id, l.allow_access, l.created_at, l.updated_at
                FROM `{$legacy}` l
                LEFT JOIN `{$canonical}` c
                    ON c.role_id = l.role_id
                   AND c.permission_id = l.permission_id
                WHERE c.id IS NULL",
                "UPDATE `{$canonical}` c
                JOIN `{$legacy}` l
                    ON c.role_id = l.role_id
                   AND c.permission_id = l.permission_id
                SET
                    c.allow_access = GREATEST(c.allow_access, l.allow_access),
                    c.created_at = CASE
                        WHEN l.created_at IS NULL THEN c.created_at
                        WHEN c.created_at IS NULL THEN l.created_at
                        ELSE LEAST(c.created_at, l.created_at)
                    END,
                    c.updated_at = CASE
                        WHEN c.updated_at IS NULL THEN l.updated_at
                        WHEN l.updated_at IS NULL THEN c.updated_at
                        ELSE GREATEST(c.updated_at, l.updated_at)
                    END",
            ];
        },
    ],
    [
        'label' => 'email_usage_daily',
        'canonical' => $emailUsageCanonical,
        'legacy' => 'email_usage_daily',
        'merge_sql' => static function ( string $canonical, string $legacy ): array {
            return [
                "INSERT INTO `{$canonical}` (
                    usage_date, module_slug, sent_count, failed_count, last_provider, last_sent_at, last_failed_at
                )
                SELECT
                    l.usage_date, l.module_slug, l.sent_count, l.failed_count, l.last_provider, l.last_sent_at, l.last_failed_at
                FROM `{$legacy}` l
                LEFT JOIN `{$canonical}` c
                    ON c.usage_date = l.usage_date
                   AND c.module_slug = l.module_slug
                WHERE c.usage_date IS NULL",
                "UPDATE `{$canonical}` c
                JOIN `{$legacy}` l
                    ON c.usage_date = l.usage_date
                   AND c.module_slug = l.module_slug
                SET
                    c.sent_count = GREATEST(c.sent_count, l.sent_count),
                    c.failed_count = GREATEST(c.failed_count, l.failed_count),
                    c.last_provider = CASE
                        WHEN (c.last_provider IS NULL OR c.last_provider = '') AND l.last_provider IS NOT NULL AND l.last_provider <> '' THEN l.last_provider
                        ELSE c.last_provider
                    END,
                    c.last_sent_at = CASE
                        WHEN c.last_sent_at IS NULL OR c.last_sent_at = '0000-00-00 00:00:00' THEN l.last_sent_at
                        WHEN l.last_sent_at IS NULL OR l.last_sent_at = '0000-00-00 00:00:00' THEN c.last_sent_at
                        ELSE GREATEST(c.last_sent_at, l.last_sent_at)
                    END,
                    c.last_failed_at = CASE
                        WHEN c.last_failed_at IS NULL OR c.last_failed_at = '0000-00-00 00:00:00' THEN l.last_failed_at
                        WHEN l.last_failed_at IS NULL OR l.last_failed_at = '0000-00-00 00:00:00' THEN c.last_failed_at
                        ELSE GREATEST(c.last_failed_at, l.last_failed_at)
                    END",
            ];
        },
    ],
    [
        'label' => 'email_send_events',
        'canonical' => $emailEventsCanonical,
        'legacy' => 'email_send_events',
        'merge_sql' => static function ( string $canonical, string $legacy ): array {
            return [
                "INSERT INTO `{$canonical}` (
                    id, event_at, module_slug, status, provider, to_email, subject, error_message, meta_json
                )
                SELECT
                    l.id, l.event_at, l.module_slug, l.status, l.provider, l.to_email, l.subject, l.error_message, l.meta_json
                FROM `{$legacy}` l
                LEFT JOIN `{$canonical}` c
                    ON c.id = l.id
                WHERE c.id IS NULL",
            ];
        },
    ],
];

foreach ( $migrations as $migration ) {
    $canonical = (string) $migration['canonical'];
    $legacy = (string) $migration['legacy'];
    $existsCanonical = $tableExists( $canonical );
    $existsLegacy = $tableExists( $legacy );

    $entry = [
        'label' => (string) $migration['label'],
        'canonical' => $canonical,
        'legacy' => $legacy,
        'canonical_exists' => $existsCanonical,
        'legacy_exists' => $existsLegacy,
        'canonical_rows_before' => $existsCanonical ? $fetchCount( $canonical ) : 0,
        'legacy_rows_before' => $existsLegacy ? $fetchCount( $legacy ) : 0,
        'applied' => false,
        'dropped_legacy' => false,
    ];

    if ( $existsLegacy ) {
        foreach ( $migration['merge_sql']( $canonical, $legacy ) as $sql ) {
            $runSql( $sql );
        }

        if ( $apply ) {
            $entry['applied'] = true;
        }

        if ( $apply && $tableExists( $legacy ) ) {
            $db->execute( "DROP TABLE `{$legacy}`" );
            $entry['dropped_legacy'] = true;
            $existsLegacy = false;
        }
    }

    $entry['canonical_rows_after'] = $tableExists( $canonical ) ? $fetchCount( $canonical ) : 0;
    $entry['legacy_exists_after'] = $tableExists( $legacy );
    $summary['migrations'][] = $entry;
}

echo json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
