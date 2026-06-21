<?php
declare(strict_types=1);

require_once __DIR__ . '/StandaloneBootstrap.php';

if ( ! function_exists( 'metis_portal_slug' ) ) {
    function metis_portal_slug(): string {
        $slug = class_exists( 'Core_Settings_Service' ) ? Core_Settings_Service::get( 'portal_slug', 'admin' ) : 'admin';
        if ( is_array( $slug ) ) {
            $slug = reset( $slug );
        }
        if ( ! is_string( $slug ) || trim( $slug ) === '' ) {
            $slug = 'admin';
        }
        return trim( $slug, '/' );
    }
}

if ( ! function_exists( 'metis_portal_name' ) ) {
    function metis_portal_name(): string {
        $name = class_exists( 'Core_Settings_Service' ) ? Core_Settings_Service::get( 'portal_name', 'Metis Portal' ) : 'Metis Portal';
        if ( is_array( $name ) ) {
            $name = reset( $name );
        }
        if ( ! is_string( $name ) || trim( $name ) === '' ) {
            $name = 'Metis Portal';
        }
        return $name;
    }
}

if ( ! function_exists( 'metis_portal_base_url' ) ) {
    function metis_portal_base_url(): string {
        $slug = metis_portal_slug();
        if ( $slug === '' ) {
            return metis_trailingslashit( metis_site_url( '/' ) );
        }

        return metis_trailingslashit( metis_site_url( '/' . $slug ) );
    }
}

if ( ! function_exists( 'metis_portal_url' ) ) {
    function metis_portal_url( string $domain = '', string $view = '' ): string {
        $parts = [ rtrim( metis_portal_base_url(), '/' ) ];
        if ( $domain !== '' ) {
            $parts[] = trim( $domain, '/' );
        }
        if ( $view !== '' ) {
            $parts[] = trim( $view, '/' );
        }
        return implode( '/', $parts ) . '/';
    }
}

function metis_standalone_boot_log( string $message, array $context = [] ): void {
    if ( ! metis_standalone_boot_trace_should_write( $message ) ) {
        return;
    }

    metis_standalone_raw_boot_log( $message, $context );

    if (
        class_exists( 'Metis_Logger' )
        && class_exists( 'Core_Settings_Service' )
        && isset( $GLOBALS['metis_db_connection'] )
        && $GLOBALS['metis_db_connection'] instanceof MetisRuntimeDbConnection
    ) {
        Metis_Logger::info( 'standalone_' . $message, $context );
    }
}

function metis_standalone_boot_trace_enabled(): bool {
    if ( defined( 'METIS_BOOT_TRACE' ) ) {
        return (bool) METIS_BOOT_TRACE;
    }

    $env = getenv( 'METIS_BOOT_TRACE' );
    if ( is_string( $env ) && trim( $env ) !== '' ) {
        return in_array( strtolower( trim( $env ) ), [ '1', 'true', 'yes', 'on' ], true );
    }

    return defined( 'APP_DEBUG' ) && (bool) APP_DEBUG;
}

function metis_standalone_boot_trace_should_write( string $message ): bool {
    if ( metis_standalone_boot_trace_enabled() ) {
        return true;
    }

    $message = strtolower( trim( $message ) );
    return str_contains( $message, 'failed' )
        || str_contains( $message, 'exception' )
        || str_contains( $message, 'error' );
}

function metis_standalone_boot_trace_path(): string {
    $logs_dir = rtrim( (string) METIS_PATH, '/\\' ) . '/storage/logs';
    if ( ! is_dir( $logs_dir ) ) {
        @mkdir( $logs_dir, 0775, true );
    }
    if ( is_dir( $logs_dir ) ) {
        @chmod( $logs_dir, 02775 );
    }

    $dir = $logs_dir . '/bootstrap';
    if ( ! is_dir( $dir ) ) {
        @mkdir( $dir, 0775, true );
    }
    if ( is_dir( $dir ) ) {
        @chmod( $dir, 02775 );
    }

    return $dir . '/standalone-' . gmdate( 'Y-m-d' ) . '.log';
}

function metis_standalone_raw_boot_log( string $message, array $context = [] ): void {
    $entry = [
        'timestamp' => gmdate( 'c' ),
        'message' => $message,
        'request_uri' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
        'method' => (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ),
        'sapi' => PHP_SAPI,
        'pid' => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
        'context' => $context,
    ];

    $encoded = json_encode( $entry, JSON_UNESCAPED_SLASHES );
    if ( $encoded === false ) {
        $entry['context'] = [ 'json_encode_failed' => true ];
        $encoded = json_encode( $entry, JSON_UNESCAPED_SLASHES ) ?: print_r( $entry, true );
    }

    $path = metis_standalone_boot_trace_path();
    if ( ! file_exists( $path ) ) {
        @touch( $path );
    }
    if ( file_exists( $path ) ) {
        @chmod( $path, 0664 );
    }

    $written = @file_put_contents( $path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX );
    if ( $written === false ) {
        @error_log( '[metis.standalone_boot.write_failed] ' . $encoded );
    }
}

function metis_standalone_database_config_path(): string {
    return METIS_CONFIG_PATH . 'database.php';
}

function metis_standalone_config_directory(): string {
    return rtrim( METIS_CONFIG_PATH, '/\\' );
}

function metis_standalone_config_cache_path(): string {
    return metis_runtime_storage_path( 'cache/config.php' );
}

function metis_standalone_config_sources(): array {
    $paths = glob( metis_standalone_config_directory() . '/*.php' );
    if ( ! is_array( $paths ) ) {
        return [];
    }

    sort( $paths );

    $sources = [];
    foreach ( $paths as $path ) {
        if ( ! is_file( $path ) ) {
            continue;
        }

        if ( basename( $path ) === 'index.php' ) {
            continue;
        }

        $sources[ basename( $path, '.php' ) ] = $path;
    }

    return $sources;
}

function metis_standalone_config_source_manifest(): array {
    $manifest = [];
    foreach ( metis_standalone_config_sources() as $name => $path ) {
        $mtime = @filemtime( $path );
        $manifest[ $name ] = [
            'path' => $path,
            'mtime' => $mtime === false ? 0 : (int) $mtime,
        ];
    }

    return $manifest;
}

function metis_standalone_forget_compiled_config(): void {
    $GLOBALS['metis_compiled_config_cache'] = null;
}

function metis_standalone_invalidate_config_cache(): void {
    $path = metis_standalone_config_cache_path();
    if ( is_file( $path ) ) {
        @unlink( $path );
    }

    metis_standalone_forget_compiled_config();
}

function metis_standalone_config_cache_is_fresh( array $payload ): bool {
    if ( empty( $payload['manifest'] ) || ! is_array( $payload['manifest'] ) ) {
        return false;
    }

    return $payload['manifest'] === metis_standalone_config_source_manifest();
}

function metis_standalone_build_config_cache(): array {
    $manifest = metis_standalone_config_source_manifest();
    $compiled = [];

    foreach ( $manifest as $name => $entry ) {
        $loaded = require $entry['path'];
        $compiled[ $name ] = is_array( $loaded ) ? $loaded : [];
    }

    $payload = [
        'generated_at' => time(),
        'manifest' => $manifest,
        'config' => $compiled,
    ];

    $path = metis_standalone_config_cache_path();
    $temp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
    $body = "<?php\nreturn " . var_export( $payload, true ) . ";\n";

    file_put_contents( $temp, $body, LOCK_EX );
    @chmod( $temp, 0664 );
    rename( $temp, $path );

    return $payload;
}

function metis_standalone_compiled_config( bool $force_rebuild = false ): array {
    $compiled = $GLOBALS['metis_compiled_config_cache'] ?? null;

    if ( ! $force_rebuild && is_array( $compiled ) ) {
        return $compiled;
    }

    $payload = null;
    $path = metis_standalone_config_cache_path();
    if ( ! $force_rebuild && is_file( $path ) ) {
        $loaded = require $path;
        if ( is_array( $loaded ) ) {
            $payload = $loaded;
        }
    }

    if ( ! is_array( $payload ) || ! metis_standalone_config_cache_is_fresh( $payload ) ) {
        $payload = metis_standalone_build_config_cache();
    }

    $GLOBALS['metis_compiled_config_cache'] = $payload;
    return $payload;
}

function metis_standalone_read_config( string $name, array $default = [] ): array {
    $config = metis_standalone_compiled_config();
    $compiled = is_array( $config['config'] ?? null ) ? $config['config'] : [];
    $value = $compiled[ $name ] ?? null;

    if ( ! is_array( $value ) && $name === 'github' ) {
        $update = is_array( $compiled['update'] ?? null ) ? $compiled['update'] : [];
        $value = is_array( $update['github'] ?? null ) ? $update['github'] : null;
    }

    if ( ! is_array( $value ) && $name === 'release' ) {
        $update = is_array( $compiled['update'] ?? null ) ? $compiled['update'] : [];
        $github = is_array( $update['github'] ?? null ) ? $update['github'] : [];
        $owner = trim( (string) ( $github['owner'] ?? '' ) );
        $repo = trim( (string) ( $github['repo'] ?? '' ) );

        $value = [
            'remote_enabled' => true,
            'remote' => $owner !== '' && $repo !== '' ? sprintf( 'https://github.com/%s/%s.git', $owner, $repo ) : 'origin',
            'git_binary' => 'git',
        ];
    }

    if ( ! is_array( $value ) ) {
        $value = $default;
    }

    return is_array( $value ) ? $value : $default;
}

function metis_standalone_database_config(): array {
    return metis_standalone_read_config( 'database', [] );
}

function metis_standalone_installer_recovery_enabled(): bool {
    $raw = '';
    if ( defined( 'METIS_INSTALLER_RECOVERY' ) ) {
        $raw = (string) METIS_INSTALLER_RECOVERY;
    } elseif ( getenv( 'METIS_INSTALLER_RECOVERY' ) !== false ) {
        $raw = (string) getenv( 'METIS_INSTALLER_RECOVERY' );
    }

    return in_array( strtolower( $raw ), [ '1', 'true', 'yes', 'on' ], true );
}

function metis_standalone_configuration_exists(): bool {
    return is_file( metis_standalone_database_config_path() ) || is_file( rtrim( (string) METIS_PATH, '/\\' ) . '/storage/install.lock' );
}

function metis_standalone_has_database_config(): bool {
    $config = metis_standalone_database_config();
    foreach ( [ 'host', 'database', 'username' ] as $required ) {
        if ( trim( (string) ( $config[ $required ] ?? '' ) ) === '' ) {
            return false;
        }
    }

    return true;
}

function metis_standalone_mark_installed(): void {
    file_put_contents( rtrim( (string) METIS_PATH, '/\\' ) . '/storage/install.lock', "installed\n", LOCK_EX );
}

function metis_standalone_write_database_config( array $config, bool $write_install_lock = true ): void {
    $dir = dirname( metis_standalone_database_config_path() );
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0775, true );
    }

    $payload = "<?php\nreturn " . var_export( $config, true ) . ";\n";
    file_put_contents( metis_standalone_database_config_path(), $payload, LOCK_EX );
    if ( $write_install_lock ) {
        metis_standalone_mark_installed();
    }
    metis_standalone_invalidate_config_cache();
    metis_standalone_compiled_config( true );
}

function metis_standalone_install_chmod( string $path, int $mode ): void {
    if ( file_exists( $path ) || is_dir( $path ) ) {
        @chmod( $path, $mode );
    }
}

function metis_standalone_install_ensure_directory( string $path ): void {
    if ( ! is_dir( $path ) ) {
        @mkdir( $path, 0775, true );
    }

    metis_standalone_install_chmod( $path, 0775 );
}

function metis_standalone_install_ensure_permissions(): void {
    foreach (
        [
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/backups',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/cache',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/logs',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/media',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/public-media',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/protected-media',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/private-records',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/runtime',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/tmp',
            rtrim( (string) METIS_PATH, '/\\' ) . '/storage/uploads',
            METIS_CONFIG_PATH,
        ] as $directory
    ) {
        metis_standalone_install_ensure_directory( $directory );
    }

    foreach ( [ metis_standalone_database_config_path(), rtrim( (string) METIS_PATH, '/\\' ) . '/storage/install.lock' ] as $file ) {
        if ( is_file( $file ) ) {
            metis_standalone_install_chmod( $file, 0664 );
        }
    }
}

function metis_standalone_install_required_directories(): array {
    return [
        'Storage' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage',
        'Backups' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/backups',
        'Cache' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/cache',
        'Logs' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/logs',
        'Media' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/media',
        'Public Media' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/public-media',
        'Protected Media' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/protected-media',
        'Private Records' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/private-records',
        'Runtime' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/runtime',
        'Temporary Files' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/tmp',
        'Uploads' => rtrim( (string) METIS_PATH, '/\\' ) . '/storage/uploads',
        'Configuration' => METIS_CONFIG_PATH,
    ];
}

function metis_standalone_install_precheck_rows(): array {
    $rows = [];
    $add = static function ( string $key, string $label, string $status, string $message, bool $repairable = false ) use ( &$rows ): void {
        $rows[] = [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'repairable' => $repairable,
        ];
    };

    $add(
        'php_version',
        'PHP Version',
        version_compare( PHP_VERSION, '8.1.0', '>=' ) ? 'pass' : 'fail',
        'Running PHP ' . PHP_VERSION . '. Metis requires PHP 8.1 or newer.'
    );

    foreach ( [ 'mysqli', 'json', 'mbstring', 'openssl', 'pdo', 'curl', 'zip' ] as $extension ) {
        $loaded = extension_loaded( $extension );
        $add(
            'ext_' . $extension,
            strtoupper( $extension ) . ' Extension',
            $loaded ? 'pass' : 'fail',
            $loaded ? 'Available.' : 'Required PHP extension is not loaded.'
        );
    }

    foreach ( [ 'proc_open', 'proc_close', 'proc_get_status', 'proc_terminate' ] as $function ) {
        $available = function_exists( $function );
        $add(
            'fn_' . $function,
            $function . ' Function',
            $available ? 'pass' : 'fail',
            $available
                ? 'Available.'
                : 'Required for Metis release operations, recovery, integrity checks, and advanced diagnostics.'
        );
    }

    foreach ( metis_standalone_install_required_directories() as $label => $path ) {
        $exists = is_dir( $path );
        $writable = $exists && is_writable( $path );
        $add(
            'dir_' . metis_key_clean( $label ),
            $label . ' Directory',
            $exists && $writable ? 'pass' : 'fail',
            $exists
                ? ( $writable ? 'Writable.' : 'Exists but is not writable by PHP.' )
                : 'Directory is missing.',
            true
        );
    }

    $external_backup = '/Volumes/NAS/backups 2';
    $external_exists = is_dir( $external_backup );
    $external_writable = $external_exists && is_writable( $external_backup );
    $add(
        'external_backup',
        'External Backup Location',
        $external_exists && $external_writable ? 'pass' : 'warn',
        $external_exists
            ? ( $external_writable ? 'Writable.' : 'Exists but is not writable by PHP.' )
            : 'Not detected on this server. Configure external backups after install if this path is mounted later.',
        $external_exists
    );

    return $rows;
}

function metis_standalone_install_precheck_summary( array $rows ): array {
    $blocking = 0;
    $warnings = 0;
    $repairable = false;
    foreach ( $rows as $row ) {
        $status = (string) ( $row['status'] ?? '' );
        if ( $status === 'fail' ) {
            $blocking++;
        } elseif ( $status === 'warn' ) {
            $warnings++;
        }
        if ( ! empty( $row['repairable'] ) && $status === 'fail' ) {
            $repairable = true;
        }
    }

    return [
        'ok' => $blocking === 0,
        'blocking' => $blocking,
        'warnings' => $warnings,
        'repairable' => $repairable,
    ];
}

function metis_standalone_install_json( array $payload, int $status = 200 ): never {
    if ( function_exists( 'metis_send_status' ) ) {
        metis_send_status( $status );
    } else {
        header( sprintf( 'HTTP/1.1 %d', $status ), true, $status );
    }
    header( 'Content-Type: application/json; charset=UTF-8' );
    echo metis_json_encode( $payload, JSON_UNESCAPED_SLASHES ) ?: '{}';
    exit;
}

function metis_standalone_install_remove_path( string $relative_path ): bool {
    $root = rtrim( (string) METIS_PATH, '/\\' );
    $target = $root . '/' . trim( $relative_path, '/\\' );
    if ( ! file_exists( $target ) && ! is_link( $target ) ) {
        return false;
    }

    $root_real = realpath( $root );
    $target_real = realpath( $target );
    if ( ! is_string( $root_real ) || ( ! is_string( $target_real ) && ! is_link( $target ) ) ) {
        return false;
    }

    if ( is_string( $target_real ) && ! str_starts_with( $target_real, rtrim( $root_real, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
        return false;
    }

    if ( is_file( $target ) || is_link( $target ) ) {
        return @unlink( $target );
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $items as $item ) {
        $path = $item->getPathname();
        if ( $item->isDir() && ! $item->isLink() ) {
            @rmdir( $path );
        } else {
            @unlink( $path );
        }
    }

    return @rmdir( $target );
}

function metis_standalone_install_cleanup_download_artifacts(): array {
    $removed = [];
    $paths = [
        [
            '.DS_Store',
            '.deploymentignore',
            '.distignore',
            '.editorconfig',
            '.gitattributes',
            '.github',
            '.gitignore',
            '.metis-integrity',
            'AGENTS.md',
            'README.md',
            'columns',
            'config',
            'database',
            'docs',
            'enclave',
            'src',
            'tests',
            'tools',
        ],
        glob( rtrim( (string) METIS_PATH, '/\\' ) . '/*.md' ) ?: [],
    ];

    foreach ( array_merge( ...$paths ) as $relative_path ) {
        $relative_path = is_string( $relative_path ) && str_starts_with( $relative_path, rtrim( (string) METIS_PATH, '/\\' ) . DIRECTORY_SEPARATOR )
            ? basename( $relative_path )
            : (string) $relative_path;
        if ( metis_standalone_install_remove_path( $relative_path ) ) {
            $removed[] = $relative_path;
        }
    }

    return $removed;
}

function metis_standalone_install_set_default( string $key, mixed $value, bool $autoload = true, bool $overwrite = false ): bool {
    if ( ! class_exists( 'Core_Settings_Service' ) ) {
        return false;
    }

    if ( ! $overwrite && Core_Settings_Service::has( $key ) ) {
        return false;
    }

    return Core_Settings_Service::set( $key, $value, $autoload );
}

function metis_standalone_install_complete_defaults(): void {
    if ( ! class_exists( 'Core_Settings_Service' ) ) {
        return;
    }

    $already_completed = (bool) Core_Settings_Service::get( 'metis_install_completed', false );
    if ( $already_completed ) {
        return;
    }

    $site_name = 'Metis Portal';
    $tagline = '';
    $timezone = 'UTC';
    $base_url = '';
    if ( function_exists( 'metis_standalone_database_config' ) ) {
        $config = metis_standalone_database_config();
        $base_url = trim( (string) ( $config['base_url'] ?? '' ) );
        $configured_site_name = trim( (string) ( $config['install_site_name'] ?? '' ) );
        if ( $configured_site_name !== '' ) {
            $site_name = $configured_site_name;
        }
        $tagline = trim( (string) ( $config['install_tagline'] ?? '' ) );
        $configured_timezone = trim( (string) ( $config['install_timezone'] ?? '' ) );
        if ( $configured_timezone !== '' && in_array( $configured_timezone, timezone_identifiers_list(), true ) ) {
            $timezone = $configured_timezone;
        }
    }

    metis_standalone_install_set_default( 'portal_name', $site_name );
    metis_standalone_install_set_default( 'org_name', $site_name );
    metis_standalone_install_set_default( 'login_organization_name', $site_name );
    metis_standalone_install_set_default( 'org_tagline', $tagline );
    metis_standalone_install_set_default( 'portal_slug', 'admin' );
    metis_standalone_install_set_default( 'timezone', $timezone );
    metis_standalone_install_set_default( 'site_timezone', $timezone );
    metis_standalone_install_set_default( 'date_format', 'm/d/y' );
    metis_standalone_install_set_default( 'date_format_mode', 'preset' );
    metis_standalone_install_set_default( 'time_format', 'g:i:s a' );
    metis_standalone_install_set_default( 'time_format_mode', 'preset' );
    metis_standalone_install_set_default( 'backup_retention_runs', 14, false );
    metis_standalone_install_set_default( 'backup_environment', 'production', false );
    metis_standalone_install_set_default( 'auth_ip_rate_limit_per_minute', 20, false );
    metis_standalone_install_set_default( 'auth_login_lock_threshold_subject', 10, false );
    metis_standalone_install_set_default( 'auth_login_lock_threshold_ip', 30, false );
    metis_standalone_install_set_default( 'webhook_rate_limit_per_minute', 120, false );
    metis_standalone_install_set_default( 'release_manager_enabled', true, false );
    metis_standalone_install_set_default( 'release_auto_update_enabled', true, false );
    metis_standalone_install_set_default( 'release_auto_update_max_level', 'patch', false );
    metis_standalone_install_set_default( 'release_cache_retention_items', 2, false );
    metis_standalone_install_set_default( 'release_backup_retention_items', 1, false );
    metis_standalone_install_set_default( 'job_queue_completed_retention_days', 7, false );
    metis_standalone_install_set_default( 'job_queue_failed_retention_days', 14, false );
    metis_standalone_install_set_default( 'job_queue_payload_compact_after_days', 3, false );
    metis_standalone_install_set_default( 'audit_verbose_operational_events', false, false );
    metis_standalone_install_set_default( 'audit_log_successful_ajax_authorizations', false, false );
    metis_standalone_install_set_default( 'data_retention_enabled', true, false );
    metis_standalone_install_set_default( 'integrity_auto_heal_enabled', true, false );
    metis_standalone_install_set_default( 'integrity_quarantine_enabled', true, false );
    metis_standalone_install_set_default( 'integrity_git_restore_enabled', true, false );
    metis_standalone_install_set_default( 'recovery_preboot_enabled', true, false );
    metis_standalone_install_set_default( 'recovery_runtime_enabled', true, false );
    metis_standalone_install_set_default( 'recovery_file_mutation_enabled', true, false );
    metis_standalone_install_set_default( 'payment_statuses', [ 'pending', 'completed', 'refunded', 'failed', 'voided' ] );
    if ( $base_url !== '' ) {
        metis_standalone_install_set_default( 'site_url', $base_url );
    }

    Core_Settings_Service::set( 'system_cron_disabled_tasks', [], false );
    if ( trim( (string) Core_Settings_Service::get( 'system_cron_secret', '' ) ) === '' ) {
        Core_Settings_Service::set( 'system_cron_secret', bin2hex( random_bytes( 24 ) ), false );
    }

    if ( function_exists( 'metis_backup_service' ) ) {
        metis_backup_service()->ensureSchema();
    }

    if ( function_exists( 'metis_release_manager' ) ) {
        metis_release_manager()->ensureRuntime();
    }

    $removed = metis_standalone_install_cleanup_download_artifacts();
    Core_Settings_Service::set( 'metis_install_cleanup_removed', $removed, false );
    Core_Settings_Service::set( 'metis_installed_at', gmdate( 'c' ), true );
    Core_Settings_Service::set( 'metis_install_completed', true, true );
}

function metis_standalone_enable_recovery_defaults(): void {
    if ( ! class_exists( 'Core_Settings_Service' ) ) {
        return;
    }

    $version = defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : 'unknown';
    $activation_key = 'metis_recovery_defaults_enabled_' . preg_replace( '/[^a-z0-9_]+/i', '_', $version );
    if ( (bool) Core_Settings_Service::get( $activation_key, false ) ) {
        return;
    }

    foreach ( [
        'integrity_auto_heal_enabled',
        'integrity_quarantine_enabled',
        'integrity_git_restore_enabled',
        'release_manager_enabled',
        'release_auto_update_enabled',
        'data_retention_enabled',
        'recovery_preboot_enabled',
        'recovery_runtime_enabled',
        'recovery_file_mutation_enabled',
    ] as $setting ) {
        Core_Settings_Service::set( $setting, true, false );
    }

    if ( trim( (string) Core_Settings_Service::get( 'release_auto_update_max_level', '' ) ) === '' ) {
        Core_Settings_Service::set( 'release_auto_update_max_level', 'patch', false );
    }

    Core_Settings_Service::set( $activation_key, true, false );
}

function metis_standalone_core_schema_signature(): string {
    $source = dirname( __DIR__ ) . '/DatabaseRuntime.php';
    $mtime = is_file( $source ) ? (int) ( @filemtime( $source ) ?: 0 ) : 0;
    $version = defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : 'unknown';
    return hash( 'sha256', $version . ':' . $mtime );
}

function metis_standalone_core_schema_needs_install(): bool {
    if ( ! class_exists( 'Core_Settings_Service' ) ) {
        return true;
    }

    return (string) Core_Settings_Service::get( 'metis_core_schema_signature', '' ) !== metis_standalone_core_schema_signature();
}

function metis_standalone_mark_core_schema_installed(): void {
    if ( class_exists( 'Core_Settings_Service' ) ) {
        Core_Settings_Service::set( 'metis_core_schema_signature', metis_standalone_core_schema_signature(), false );
    }
}

function metis_standalone_install_timezone_label( string $timezone ): string {
    try {
        $now = new DateTimeImmutable( 'now', new DateTimeZone( $timezone ) );
        $offset = $now->format( 'P' );
        $name = str_replace( [ '_', '/' ], [ ' ', ' / ' ], $timezone );
        $abbr = $now->format( 'T' );
        return $name . ' (' . $abbr . ', UTC' . $offset . ')';
    } catch ( Throwable ) {
        return $timezone;
    }
}

function metis_standalone_install_timezone_options(): array {
    $priority = [
        'UTC',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Phoenix',
        'America/Los_Angeles',
        'America/Anchorage',
        'Pacific/Honolulu',
    ];

    $zones = array_values( array_unique( array_merge( $priority, timezone_identifiers_list() ) ) );
    $options = [];
    foreach ( $zones as $zone ) {
        $options[ $zone ] = metis_standalone_install_timezone_label( $zone );
    }

    return $options;
}

function metis_standalone_install_validate_database_config( array $config ): void {
    foreach ( [ 'host', 'database', 'username' ] as $required ) {
        if ( trim( (string) ( $config[ $required ] ?? '' ) ) === '' ) {
            throw new InvalidArgumentException( 'Database host, database name, and username are required.' );
        }
    }

    mysqli_report( MYSQLI_REPORT_OFF );
    $mysqli = mysqli_init();
    if ( ! $mysqli instanceof mysqli ) {
        throw new RuntimeException( 'Database driver could not be initialized.' );
    }

    $host = trim( (string) ( $config['host'] ?? '' ) );
    $connected = @$mysqli->real_connect(
        $host,
        (string) ( $config['username'] ?? '' ),
        (string) ( $config['password'] ?? '' ),
        (string) ( $config['database'] ?? '' ),
        (int) ( $config['port'] ?? 3306 )
    );

    if ( ! $connected ) {
        $message = trim( (string) ( $mysqli->connect_error ?: mysqli_connect_error() ) );
        throw new RuntimeException( $message !== '' ? $message : 'Database connection failed.' );
    }

    if ( ! $mysqli->set_charset( (string) ( $config['charset'] ?? 'utf8mb4' ) ) ) {
        $error = trim( (string) $mysqli->error );
        $mysqli->close();
        throw new RuntimeException( $error !== '' ? $error : 'Database charset could not be selected.' );
    }

    if ( ! $mysqli->query( 'SELECT 1' ) ) {
        $error = trim( (string) $mysqli->error );
        $mysqli->close();
        throw new RuntimeException( $error !== '' ? $error : 'Database verification query failed.' );
    }

    $mysqli->close();
}

function metis_standalone_install_boot_database_context( array $db ): void {
    $basePath = rtrim( dirname( $_SERVER['SCRIPT_NAME'] ?? '' ), '/' );
    $app_key = trim( (string) ( $db['app_key'] ?? '' ) );
    if ( $app_key === '' || in_array( strtolower( $app_key ), metis_runtime_insecure_app_key_values(), true ) || strlen( $app_key ) < 32 ) {
        $app_key = bin2hex( random_bytes( 32 ) );
        $db['app_key'] = $app_key;
    }
    $GLOBALS['metis_runtime_config'] = [
        'db_charset' => (string) ( $db['charset'] ?? 'utf8mb4' ),
        'db_collation' => (string) ( $db['collation'] ?? 'utf8mb4_unicode_ci' ),
        'db_socket' => (string) ( $db['socket'] ?? '' ),
        'db_host' => (string) ( $db['host'] ?? '' ),
        'db_port' => (int) ( $db['port'] ?? 3306 ),
        'db_name' => (string) ( $db['database'] ?? '' ),
        'base_path' => $basePath === '/' ? '' : $basePath,
        'app_key' => $app_key,
        'base_url' => trim( (string) ( $db['base_url'] ?? '' ) ),
    ];

    $GLOBALS['metis_db_connection'] = new MetisRuntimeDbConnection(
        (string) $db['username'],
        (string) ( $db['password'] ?? '' ),
        (string) $db['database'],
        (string) $db['host'] . ':' . (int) ( $db['port'] ?? 3306 ),
        (string) ( $db['prefix'] ?? '' )
    );

    require_once dirname( __DIR__ ) . '/SettingsService.php';
    require_once dirname( __DIR__ ) . '/TablesRegistry.php';
    require_once dirname( __DIR__ ) . '/CoreHelpers.php';
    require_once dirname( __DIR__ ) . '/AuditRuntime.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Webhooks/WebhookRuntime.php';
    require_once dirname( __DIR__ ) . '/BackupRuntime.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Cron/CronRuntime.php';
    require_once dirname( __DIR__ ) . '/DatabaseRuntime.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Auth/AuthRuntime.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Runtime/UploadsRuntime.php';
    metis_standalone_require_recovery_runtime();

    Metis_Tables::init();
    metis_register_core_services();
    Metis::service( 'settings' )->init();
}

function metis_standalone_install_require_module_file( string $module, string $relative_path ): void {
    $module_root = \Metis\Core\ModulePathRegistry::modulePath( $module );
    $path = is_string( $module_root ) ? $module_root . '/' . ltrim( $relative_path, '/\\' ) : '';
    if ( is_file( $path ) ) {
        require_once $path;
    }
}

function metis_standalone_require_recovery_runtime(): void {
    foreach ( [
        'RecoveryPolicyService.php',
        'RecoverySchema.php',
        'RecoveryAuditLogger.php',
        'RecoveryPlaybookService.php',
        'RecoveryVerifier.php',
        'BackupRecoveryService.php',
        'GitRecoveryService.php',
        'RecoveryLockService.php',
        'PrebootIntegrityService.php',
    ] as $file ) {
        $path = METIS_SRC_PATH . 'Metis/Core/Recovery/' . $file;
        if ( is_file( $path ) ) {
            require_once $path;
        }
    }
}

function metis_standalone_install_call_schema( string $label, callable $callback, array &$created ): void {
    $callback();
    $created[] = $label;
}

function metis_standalone_install_ensure_all_schema(): array {
    $created = [];
    metis_standalone_require_recovery_runtime();

    metis_standalone_install_call_schema( 'metis_install_db', static function (): void {
        if ( function_exists( 'metis_install_db' ) ) {
            metis_install_db();
        }
    }, $created );

    metis_standalone_install_call_schema( 'metis_audit_ensure_schema', static function (): void {
        if ( function_exists( 'metis_audit_ensure_schema' ) ) {
            metis_audit_ensure_schema();
        }
    }, $created );

    metis_standalone_install_call_schema( 'metis_webhook_ensure_schema', static function (): void {
        if ( function_exists( 'metis_webhook_ensure_schema' ) ) {
            metis_webhook_ensure_schema();
        }
    }, $created );

    metis_standalone_install_call_schema( 'metis_media_ensure_schema', static function (): void {
        if ( function_exists( 'metis_media_ensure_schema' ) ) {
            metis_media_ensure_schema();
        }
    }, $created );

    metis_standalone_install_require_module_file( 'drive', 'includes/schema.php' );

    $schema_installers = [
        'contacts' => static function (): void { \Metis\Modules\Contacts\SchemaManager::ensureSchema(); },
        'people' => static function (): void { \Metis\Modules\People\SchemaManager::ensureSchema(); },
        'forms' => static function (): void { \Metis\Modules\Forms\SchemaManager::ensureSchema(); },
        'forms_import' => static function (): void { \Metis\Modules\FormsImport\SchemaManager::ensureSchema(); },
        'newsletter' => static function (): void { \Metis\Modules\Newsletter\SchemaManager::ensureSchema(); },
        'board' => static function (): void { \Metis\Modules\Board\SchemaManager::ensureSchema(); },
        'calendar' => static function (): void { \Metis\Modules\Calendar\SyncStore::ensureSchema(); },
        'finance' => static function (): void { \Metis\Modules\Finance\SchemaManager::ensureSchema(); },
        'hermes' => static function (): void { \Metis\Modules\Hermes\SchemaManager::ensureSchema(); },
        'website' => static function (): void { \Metis\Modules\Website\SchemaManager::ensureSchema(); },
        'import' => static function (): void { \Metis\Modules\Import\SchemaManager::ensureSchema(); },
        'communications_inbound' => static function (): void { \Metis\Modules\CommunicationsInbound\SchemaManager::ensureSchema(); },
        'grandy_stash' => static function (): void { \Metis\Modules\GrandyStash\GrandyStashSchemaManager::ensureSchema(); },
        'drive' => static function (): void {
            if ( function_exists( 'metis_drive_ensure_schema' ) ) {
                metis_drive_ensure_schema();
            }
        },
        'recovery' => static function (): void { \Metis\Core\Recovery\RecoverySchema::ensureSchema(); },
    ];

    foreach ( $schema_installers as $label => $installer ) {
        metis_standalone_install_call_schema( $label, $installer, $created );
    }

    if ( function_exists( 'metis_backup_service' ) ) {
        metis_standalone_install_call_schema( 'backup_service', static function (): void {
            metis_backup_service()->ensureSchema();
        }, $created );
    }

    if ( function_exists( 'metis_entity_id_service' ) ) {
        metis_standalone_install_call_schema( 'entity_id_service', static function (): void {
            metis_entity_id_service()->ensureSchema();
        }, $created );
    }

    if ( class_exists( '\Metis\Core\HelpSearchStore' ) ) {
        metis_standalone_install_call_schema( 'help_search_store', static function (): void {
            ( new \Metis\Core\HelpSearchStore() )->ensureSchema();
        }, $created );
    }

    return $created;
}

function metis_standalone_install_config_from_request( array $source ): array {
    $app_key = metis_text_clean( (string) ( $source['app_key'] ?? '' ) );
    if ( $app_key === '' || in_array( strtolower( $app_key ), metis_runtime_insecure_app_key_values(), true ) || strlen( $app_key ) < 32 ) {
        $app_key = bin2hex( random_bytes( 32 ) );
    }

    return [
        'host' => metis_text_clean( (string) ( $source['db_host'] ?? '' ) ),
        'port' => (int) metis_text_clean( (string) ( $source['db_port'] ?? '3306' ) ),
        'database' => metis_text_clean( (string) ( $source['db_name'] ?? '' ) ),
        'username' => metis_text_clean( (string) ( $source['db_user'] ?? '' ) ),
        'password' => (string) ( $source['db_password'] ?? '' ),
        'socket' => '',
        'prefix' => metis_key_clean( (string) ( $source['db_prefix'] ?? '' ) ),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'app_key' => $app_key,
        'base_url' => trim( filter_var( (string) ( $source['base_url'] ?? '' ), FILTER_SANITIZE_URL ) ?: '' ),
        'install_site_name' => metis_text_clean( (string) ( $source['site_name'] ?? 'Metis Portal' ) ),
        'install_tagline' => metis_text_clean( (string) ( $source['site_tagline'] ?? '' ) ),
        'install_timezone' => metis_text_clean( (string) ( $source['site_timezone'] ?? 'UTC' ) ),
    ];
}

function metis_standalone_install_admin_from_request( array $source ): array {
    $email = metis_email_clean( (string) ( $source['admin_email'] ?? '' ) );
    $display = metis_text_clean( (string) ( $source['admin_display_name'] ?? '' ) );
    $name_parts = preg_split( '/\s+/', trim( $display ), 2 ) ?: [];
    $login_source = $email !== '' ? strstr( $email, '@', true ) : '';
    if ( $login_source === false || $login_source === '' ) {
        $login_source = $display !== '' ? $display : 'admin';
    }

    $admin = [
        'first_name' => metis_text_clean( (string) ( $name_parts[0] ?? '' ) ),
        'last_name' => metis_text_clean( (string) ( $name_parts[1] ?? '' ) ),
        'user_email' => $email,
        'user_login' => metis_key_clean( (string) $login_source ),
        'display_name' => $display,
        'password' => (string) ( $source['admin_password'] ?? '' ),
        'password_confirm' => (string) ( $source['admin_password_confirm'] ?? '' ),
    ];
    return $admin;
}

function metis_standalone_install_validate_config_and_admin( array $config, array $admin, bool $validate_database = true ): void {
    if ( $config['install_site_name'] === '' ) {
        throw new InvalidArgumentException( 'Site name is required.' );
    }
    if ( ! in_array( $config['install_timezone'], timezone_identifiers_list(), true ) ) {
        throw new InvalidArgumentException( 'Timezone must be valid.' );
    }
    if ( $admin['display_name'] === '' || $admin['user_email'] === '' || ! metis_email_is_valid( $admin['user_email'] ) ) {
        throw new InvalidArgumentException( 'First administrator display name and a valid email are required.' );
    }
    if ( $admin['user_login'] === '' ) {
        throw new InvalidArgumentException( 'First administrator email must include a usable local part.' );
    }
    if ( strlen( (string) $admin['password'] ) < 12 ) {
        throw new InvalidArgumentException( 'First administrator password must be at least 12 characters.' );
    }
    if ( ! hash_equals( (string) $admin['password'], (string) $admin['password_confirm'] ) ) {
        throw new InvalidArgumentException( 'First administrator passwords do not match.' );
    }
    if ( $validate_database ) {
        metis_standalone_install_validate_database_config( $config );
    }
}

function metis_standalone_install_first_admin_id(): int {
    if ( ! function_exists( 'metis_auth_table' ) || ! function_exists( 'metis_auth_find_user' ) ) {
        return 0;
    }

    try {
        $configured_id = (int) Core_Settings_Service::get( 'metis_install_first_admin_user_id', 0 );
        if ( $configured_id > 0 && is_array( metis_auth_find_user( 'id', $configured_id ) ) ) {
            return $configured_id;
        }
    } catch ( Throwable ) {
        // Continue to the database lookup below.
    }

    try {
        if ( function_exists( 'metis_auth_has_users' ) && ! metis_auth_has_users() ) {
            return 0;
        }

        $first_id = (int) metis_db()->scalar( 'SELECT id FROM ' . metis_auth_table() . ' ORDER BY id ASC LIMIT 1' );
        if ( $first_id > 0 && is_array( metis_auth_find_user( 'id', $first_id ) ) ) {
            return $first_id;
        }
    } catch ( Throwable ) {
        return 0;
    }

    return 0;
}

function metis_standalone_install_ensure_first_admin( array $admin ): array {
    if ( function_exists( 'metis_install_db' ) ) {
        metis_install_db();
    }

    $existing_id = metis_standalone_install_first_admin_id();
    if ( $existing_id > 0 ) {
        Core_Settings_Service::set( 'metis_install_first_admin_user_id', $existing_id, false );
        return [ 'id' => $existing_id, 'existing' => true ];
    }

    try {
        $admin_user = metis_auth_register_first_user( $admin );
    } catch ( RuntimeException $e ) {
        if ( $e->getMessage() !== 'Initial account already exists.' ) {
            throw $e;
        }

        $existing_id = metis_standalone_install_first_admin_id();
        if ( $existing_id > 0 ) {
            Core_Settings_Service::set( 'metis_install_first_admin_user_id', $existing_id, false );
            return [ 'id' => $existing_id, 'existing' => true ];
        }

        throw $e;
    }

    $admin_id = (int) ( $admin_user['id'] ?? 0 );
    if ( $admin_id < 1 || ! is_array( metis_auth_find_user( 'id', $admin_id ) ) ) {
        throw new RuntimeException( 'First administrator account was not created.' );
    }

    Core_Settings_Service::set( 'metis_install_first_admin_user_id', $admin_id, false );
    return [ 'id' => $admin_id, 'existing' => false ];
}

function metis_standalone_install_schema_steps(): array {
    return [
        'metis_install_db' => 'Core tables',
        'metis_audit_ensure_schema' => 'Audit tables',
        'metis_webhook_ensure_schema' => 'Webhook tables',
        'metis_media_ensure_schema' => 'Media tables',
        'contacts' => 'Contacts module',
        'people' => 'People module',
        'forms' => 'Forms module',
        'forms_import' => 'Forms import tables',
        'newsletter' => 'Newsletter module',
        'board' => 'Board module',
        'calendar' => 'Calendar module',
        'finance' => 'Finance module',
        'hermes' => 'Hermes module',
        'website' => 'Website module',
        'cms' => 'CMS module',
        'import' => 'Import module',
        'communications_inbound' => 'Communications inbound tables',
        'grandy_stash' => 'Grandy Stash module',
        'drive' => 'Drive module',
        'recovery' => 'Recovery tables',
        'backup_service' => 'Backup service tables',
        'entity_id_service' => 'Entity ID tables',
        'help_search_store' => 'Help search tables',
    ];
}

function metis_standalone_install_run_schema_step( string $step ): void {
    metis_standalone_install_require_module_file( 'drive', 'includes/schema.php' );
    metis_standalone_require_recovery_runtime();

    $callbacks = [
        'metis_install_db' => static function (): void { if ( function_exists( 'metis_install_db' ) ) { metis_install_db(); } },
        'metis_audit_ensure_schema' => static function (): void { if ( function_exists( 'metis_audit_ensure_schema' ) ) { metis_audit_ensure_schema(); } },
        'metis_webhook_ensure_schema' => static function (): void { if ( function_exists( 'metis_webhook_ensure_schema' ) ) { metis_webhook_ensure_schema(); } },
        'metis_media_ensure_schema' => static function (): void { if ( function_exists( 'metis_media_ensure_schema' ) ) { metis_media_ensure_schema(); } },
        'contacts' => static function (): void { \Metis\Modules\Contacts\SchemaManager::ensureSchema(); },
        'people' => static function (): void { \Metis\Modules\People\SchemaManager::ensureSchema(); },
        'forms' => static function (): void { \Metis\Modules\Forms\SchemaManager::ensureSchema(); },
        'forms_import' => static function (): void { \Metis\Modules\FormsImport\SchemaManager::ensureSchema(); },
        'newsletter' => static function (): void { \Metis\Modules\Newsletter\SchemaManager::ensureSchema(); },
        'board' => static function (): void { \Metis\Modules\Board\SchemaManager::ensureSchema(); },
        'calendar' => static function (): void { \Metis\Modules\Calendar\SyncStore::ensureSchema(); },
        'finance' => static function (): void { \Metis\Modules\Finance\SchemaManager::ensureSchema(); },
        'hermes' => static function (): void { \Metis\Modules\Hermes\SchemaManager::ensureSchema(); },
        'website' => static function (): void { \Metis\Modules\Website\SchemaManager::ensureSchema(); },
        'import' => static function (): void { \Metis\Modules\Import\SchemaManager::ensureSchema(); },
        'communications_inbound' => static function (): void { \Metis\Modules\CommunicationsInbound\SchemaManager::ensureSchema(); },
        'grandy_stash' => static function (): void { \Metis\Modules\GrandyStash\GrandyStashSchemaManager::ensureSchema(); },
        'drive' => static function (): void { if ( function_exists( 'metis_drive_ensure_schema' ) ) { metis_drive_ensure_schema(); } },
        'recovery' => static function (): void { \Metis\Core\Recovery\RecoverySchema::ensureSchema(); },
        'backup_service' => static function (): void { if ( function_exists( 'metis_backup_service' ) ) { metis_backup_service()->ensureSchema(); } },
        'entity_id_service' => static function (): void { if ( function_exists( 'metis_entity_id_service' ) ) { metis_entity_id_service()->ensureSchema(); } },
        'help_search_store' => static function (): void { if ( class_exists( '\Metis\Core\HelpSearchStore' ) ) { ( new \Metis\Core\HelpSearchStore() )->ensureSchema(); } },
    ];

    if ( ! isset( $callbacks[ $step ] ) ) {
        throw new InvalidArgumentException( 'Unknown installer schema step.' );
    }

    $callbacks[ $step ]();
}

function metis_standalone_render_database_setup( string $error = '', array $old = [] ): never {
    $request_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
    $status_code = $request_method === 'POST' && $error !== '' ? 422 : 200;
    $prechecks = metis_standalone_install_precheck_rows();
    $precheck_summary = metis_standalone_install_precheck_summary( $prechecks );
    $schema_steps = metis_standalone_install_schema_steps();
    if ( function_exists( 'metis_send_status' ) ) {
        metis_send_status( $status_code );
    } else {
        header( sprintf( 'HTTP/1.1 %d', $status_code ), true, $status_code );
    }
    header( 'Content-Type: text/html; charset=UTF-8' );
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metis Setup</title>
    <style>
        :root { --ink:#182033; --muted:#647089; --line:#d9e1ee; --panel:#fff; --page:#f5f7fb; --primary:#4358c9; --primary-dark:#314199; --ok:#166534; --warn:#8a5b00; --bad:#9f1d1d; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page); color: var(--ink); font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .shell { width: min(1180px, calc(100% - 40px)); margin: 34px auto; display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 18px; }
        .rail, .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; }
        .rail { padding: 24px; align-self: start; position: sticky; top: 24px; }
        .brand { letter-spacing: .18em; text-transform: uppercase; font-size: 12px; color: var(--primary); font-weight: 800; }
        h1 { margin: 12px 0 8px; font-size: 34px; line-height: 1.05; letter-spacing: 0; }
        h2 { margin: 0 0 12px; font-size: 20px; letter-spacing: 0; }
        h3 { margin: 0; font-size: 15px; letter-spacing: 0; }
        p { line-height: 1.5; color: var(--muted); }
        .steps { display: grid; gap: 10px; margin-top: 28px; }
        .step { display: grid; grid-template-columns: 28px 1fr; gap: 10px; align-items: center; color: var(--muted); font-weight: 700; }
        .dot { width: 28px; height: 28px; border: 1px solid var(--line); border-radius: 50%; display: grid; place-items: center; font-size: 12px; background: #fff; color: var(--muted); }
        .step.is-active { color: var(--primary-dark); }
        .step.is-active .dot { border-color: var(--primary); background: #eef1ff; color: var(--primary-dark); }
        .panel { overflow: hidden; }
	        .section { padding: 26px; border-top: 1px solid var(--line); }
	        .section:first-child { border-top: 0; }
	        .installer-page { display: none; }
	        .installer-page.is-active { display: block; }
	        .section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        .section-copy { margin: 5px 0 0; max-width: 680px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-size: 13px; font-weight: 800; margin-bottom: 7px; color: #313b52; }
        input, select { width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: var(--ink); font: inherit; }
        input:focus, select:focus { outline: 2px solid #c8d1ff; border-color: var(--primary); }
        .help { margin-top: 6px; color: var(--muted); font-size: 13px; line-height: 1.45; }
        .checks { display: grid; gap: 8px; }
        .check { display: grid; grid-template-columns: 88px minmax(180px, .8fr) 1fr; gap: 14px; align-items: center; padding: 11px 12px; border: 1px solid var(--line); border-radius: 6px; background: #fff; }
        .badge { display: inline-flex; justify-content: center; align-items: center; min-height: 26px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 900; text-transform: uppercase; }
        .badge.pass { background: #eaf7ef; color: var(--ok); }
        .badge.warn { background: #fff7df; color: var(--warn); }
        .badge.fail { background: #fff0f0; color: var(--bad); }
        .check-title { font-weight: 800; }
        .check-msg { color: var(--muted); font-size: 13px; }
	        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
	        .page-nav { display: flex; justify-content: space-between; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--line); }
	        .page-nav .right { margin-left: auto; }
        button { min-height: 42px; padding: 10px 16px; border: 1px solid var(--primary); border-radius: 6px; background: var(--primary); color: #fff; cursor: pointer; font-weight: 900; font: inherit; }
        button.secondary { background: #fff; color: var(--primary-dark); border-color: #bfc8f8; }
        button:disabled { opacity: .55; cursor: not-allowed; }
        .error, .notice { margin-bottom: 16px; padding: 12px 14px; border-radius: 6px; border: 1px solid; }
        .error { background: #fff0f0; color: var(--bad); border-color: #f0b9b9; }
        .notice { background: #eef6ff; color: #1d4b83; border-color: #bdd7f5; }
        .progress-wrap { display: none; margin-top: 20px; border: 1px solid var(--line); border-radius: 8px; padding: 16px; background: #fbfcff; }
        .progress-wrap.is-visible { display: block; }
        .progress-bar { height: 10px; border-radius: 999px; background: #e6ebf4; overflow: hidden; }
        .progress-fill { height: 100%; width: 0; background: var(--primary); transition: width .22s ease; }
        .progress-meta { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 10px; font-weight: 800; }
        .progress-detail { color: var(--muted); font-size: 13px; margin-top: 6px; }
        @media (max-width: 900px) { .shell { grid-template-columns: 1fr; } .rail { position: static; } .check { grid-template-columns: 1fr; } }
        @media (max-width: 680px) { .shell { width: calc(100% - 24px); margin: 18px auto; } .grid { grid-template-columns: 1fr; } .section { padding: 20px; } h1 { font-size: 28px; } }
    </style>
</head>
<body>
    <main class="shell">
        <aside class="rail">
            <div class="brand">Metis Setup</div>
            <h1>Install with confidence.</h1>
            <p>Metis verifies the server, prepares storage, creates the database schema, and enables the first administrator before opening the portal.</p>
            <div class="steps" aria-label="Install steps">
                <div class="step is-active" data-step-indicator="precheck"><span class="dot">1</span><span>System Check</span></div>
                <div class="step" data-step-indicator="database"><span class="dot">2</span><span>Database</span></div>
                <div class="step" data-step-indicator="branding"><span class="dot">3</span><span>Branding</span></div>
                <div class="step" data-step-indicator="admin"><span class="dot">4</span><span>Administrator</span></div>
                <div class="step" data-step-indicator="install"><span class="dot">5</span><span>Install</span></div>
            </div>
        </aside>
        <section class="panel">
            <?php if ( $error !== '' ) : ?>
                <div class="section"><div class="error"><?php echo metis_esc_html( $error ); ?></div></div>
            <?php endif; ?>
            <div id="metis-install-alert" class="section" hidden></div>
            <form id="metis-install-form" method="post">
                <input type="hidden" name="metis_setup" value="1">
                <?php echo \Metis\Core\Application::has_service( 'csrf' )
                    ? \Metis\Core\Application::service( 'csrf' )->hiddenFields( 'metis_installer_setup' )
                    : ''; ?>
	                <section class="section installer-page is-active" data-installer-page="precheck">
                    <div class="section-head">
                        <div>
                            <h2>System Requirements</h2>
                            <p class="section-copy">The installer checks required PHP capabilities and writable locations before any database work begins.</p>
                        </div>
                        <span id="metis-precheck-summary" class="badge <?php echo ! empty( $precheck_summary['ok'] ) ? 'pass' : 'fail'; ?>">
                            <?php echo ! empty( $precheck_summary['ok'] ) ? 'Ready' : 'Needs attention'; ?>
                        </span>
                    </div>
                    <div id="metis-precheck-list" class="checks">
                        <?php foreach ( $prechecks as $check ) : ?>
                            <div class="check">
                                <span class="badge <?php echo metis_esc_attr( (string) $check['status'] ); ?>"><?php echo metis_esc_html( (string) $check['status'] ); ?></span>
                                <span class="check-title"><?php echo metis_esc_html( (string) $check['label'] ); ?></span>
                                <span class="check-msg"><?php echo metis_esc_html( (string) $check['message'] ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
	                    <div class="actions">
	                        <button type="button" class="secondary" id="metis-run-precheck">Run Check Again</button>
	                        <button type="button" class="secondary" id="metis-fix-permissions" <?php echo empty( $precheck_summary['repairable'] ) ? 'hidden' : ''; ?>>Correct Permissions</button>
	                    </div>
	                    <div class="page-nav">
	                        <span></span>
	                        <button type="button" class="right" data-next-page="database">Continue</button>
	                    </div>
	                </section>
	                <section class="section installer-page" data-installer-page="database">
                    <div class="section-head">
                        <div>
                            <h2>Database Connection</h2>
                            <p class="section-copy">Credentials are verified before config is written. The installer uses the server’s normal PHP database configuration.</p>
                        </div>
                    </div>
                    <div class="grid">
                        <div>
                            <label for="db_host">Host</label>
                            <input id="db_host" name="db_host" value="<?php echo metis_esc_attr( (string) ( $old['db_host'] ?? '127.0.0.1' ) ); ?>" required>
                        </div>
                        <div>
                            <label for="db_port">Port</label>
                            <input id="db_port" name="db_port" value="<?php echo metis_esc_attr( (string) ( $old['db_port'] ?? '3306' ) ); ?>" inputmode="numeric" required>
                        </div>
                        <div>
                            <label for="db_name">Database</label>
                            <input id="db_name" name="db_name" value="<?php echo metis_esc_attr( (string) ( $old['db_name'] ?? '' ) ); ?>" required>
                        </div>
                        <div>
                            <label for="db_prefix">Table Prefix</label>
                            <input id="db_prefix" name="db_prefix" value="<?php echo metis_esc_attr( (string) ( $old['db_prefix'] ?? '' ) ); ?>" placeholder="Leave blank for metis_*">
                        </div>
                        <div>
                            <label for="db_user">Username</label>
                            <input id="db_user" name="db_user" value="<?php echo metis_esc_attr( (string) ( $old['db_user'] ?? '' ) ); ?>" required>
                        </div>
                        <div>
                            <label for="db_password">Password</label>
                            <input id="db_password" name="db_password" type="password" value="">
	                        </div>
	                    </div>
	                    <div class="page-nav">
	                        <button type="button" class="secondary" data-prev-page="precheck">Back</button>
	                        <button type="button" class="right" data-next-page="branding">Verify Database</button>
	                    </div>
	                </section>
	                <section class="section installer-page" data-installer-page="branding">
                    <div class="section-head">
                        <div>
                            <h2>Branding</h2>
                            <p class="section-copy">These values seed the portal identity and public website defaults.</p>
                        </div>
                    </div>
                    <div class="grid">
                        <div>
                            <label for="site_name">Site Name</label>
                            <input id="site_name" name="site_name" value="<?php echo metis_esc_attr( (string) ( $old['site_name'] ?? 'Metis Portal' ) ); ?>" required>
                        </div>
                        <div>
                            <label for="site_tagline">Tagline</label>
                            <input id="site_tagline" name="site_tagline" value="<?php echo metis_esc_attr( (string) ( $old['site_tagline'] ?? '' ) ); ?>" placeholder="Access. Visibility. Leadership.">
                        </div>
                        <div class="full">
                            <label for="base_url">Base URL</label>
                            <input id="base_url" name="base_url" value="<?php echo metis_esc_attr( (string) ( $old['base_url'] ?? '' ) ); ?>" placeholder="https://app.example.com/metis">
                        </div>
                        <div class="full">
                            <label for="app_key">Application Key</label>
                            <input id="app_key" name="app_key" value="<?php echo metis_esc_attr( (string) ( $old['app_key'] ?? bin2hex( random_bytes( 24 ) ) ) ); ?>" required>
                            <div class="help">Used for signed platform tokens and protected runtime operations.</div>
                        </div>
                        <div class="full">
                            <label for="site_timezone">Timezone</label>
                            <?php $selected_timezone = (string) ( $old['site_timezone'] ?? 'UTC' ); ?>
                            <select id="site_timezone" name="site_timezone" required>
                                <?php foreach ( metis_standalone_install_timezone_options() as $timezone => $label ) : ?>
                                    <option value="<?php echo metis_esc_attr( $timezone ); ?>" <?php echo $selected_timezone === $timezone ? 'selected' : ''; ?>>
                                        <?php echo metis_esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
	                        </div>
	                    </div>
	                    <div class="page-nav">
	                        <button type="button" class="secondary" data-prev-page="database">Back</button>
	                        <button type="button" class="right" data-next-page="admin">Continue</button>
	                    </div>
	                </section>
	                <section class="section installer-page" data-installer-page="admin">
                    <div class="section-head">
                        <div>
                            <h2>First Administrator</h2>
                            <p class="section-copy">This account receives the initial administrator permissions and can finish security configuration after login.</p>
                        </div>
                    </div>
                    <div class="grid">
	                        <div>
	                            <label for="admin_display_name">Display Name</label>
	                            <input id="admin_display_name" name="admin_display_name" value="<?php echo metis_esc_attr( (string) ( $old['admin_display_name'] ?? '' ) ); ?>" autocomplete="name" required>
	                        </div>
	                        <div>
	                            <label for="admin_email">Email</label>
	                            <input id="admin_email" name="admin_email" type="email" value="<?php echo metis_esc_attr( (string) ( $old['admin_email'] ?? '' ) ); ?>" required>
	                        </div>
	                        <div>
	                            <label for="admin_password">Password</label>
	                            <input id="admin_password" name="admin_password" type="password" minlength="12" value="" required>
                        </div>
                        <div>
                            <label for="admin_password_confirm">Confirm Password</label>
                            <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="12" value="" required>
	                        </div>
	                    </div>
	                    <div class="page-nav">
	                        <button type="button" class="secondary" data-prev-page="branding">Back</button>
	                        <button type="button" class="right" data-next-page="install">Continue</button>
	                    </div>
	                </section>
	                <section class="section installer-page" data-installer-page="install">
                    <div class="section-head">
                        <div>
                            <h2>Install Metis</h2>
                            <p class="section-copy">The installer will create schema one module at a time, seed platform settings, enable protections, and open the admin portal.</p>
                        </div>
                    </div>
	                    <div class="actions">
	                        <button type="button" class="secondary" data-prev-page="admin">Back</button>
	                        <button type="submit" id="metis-install-submit">Begin Installation</button>
	                    </div>
                    <div id="metis-install-progress" class="progress-wrap" aria-live="polite">
                        <div class="progress-bar"><div id="metis-progress-fill" class="progress-fill"></div></div>
                        <div class="progress-meta"><span id="metis-progress-title">Preparing</span><span id="metis-progress-percent">0%</span></div>
                        <div id="metis-progress-detail" class="progress-detail">Waiting to begin.</div>
                    </div>
                </section>
            </form>
        </section>
    </main>
    <script>
    (function () {
        const schemaSteps = <?php echo metis_json_encode( $schema_steps, JSON_UNESCAPED_SLASHES ) ?: '{}'; ?>;
        const form = document.getElementById('metis-install-form');
        const alertBox = document.getElementById('metis-install-alert');
        const precheckList = document.getElementById('metis-precheck-list');
        const precheckSummary = document.getElementById('metis-precheck-summary');
        const repairButton = document.getElementById('metis-fix-permissions');
        const submitButton = document.getElementById('metis-install-submit');
        const progress = document.getElementById('metis-install-progress');
        const fill = document.getElementById('metis-progress-fill');
        const pct = document.getElementById('metis-progress-percent');
	        const title = document.getElementById('metis-progress-title');
	        const detail = document.getElementById('metis-progress-detail');
	        const indicators = Array.from(document.querySelectorAll('[data-step-indicator]'));
	        const pages = Array.from(document.querySelectorAll('[data-installer-page]'));

        function setAlert(message, type) {
            if (!message) {
                alertBox.hidden = true;
                alertBox.className = 'section';
                alertBox.textContent = '';
                return;
            }
            alertBox.hidden = false;
            alertBox.className = 'section ' + (type === 'error' ? 'error' : 'notice');
            alertBox.textContent = message;
        }

	        function activeStep(key) {
	            indicators.forEach(function (node) {
	                node.classList.toggle('is-active', node.getAttribute('data-step-indicator') === key);
	            });
	        }

	        function showPage(key) {
	            pages.forEach(function (node) {
	                node.classList.toggle('is-active', node.getAttribute('data-installer-page') === key);
	            });
	            activeStep(key);
	            setAlert('', '');
	        }

	        function validatePage(key) {
	            const page = document.querySelector('[data-installer-page="' + key + '"]');
	            if (!page) {
	                return true;
	            }
	            const fields = Array.from(page.querySelectorAll('input, select'));
	            for (const field of fields) {
	                if (typeof field.reportValidity === 'function' && !field.reportValidity()) {
	                    return false;
	                }
	            }
	            return true;
	        }

        function formData(action, extra) {
            const data = new FormData(form);
            data.set('metis_setup_action', action);
            Object.keys(extra || {}).forEach(function (key) {
                data.set(key, extra[key]);
            });
            return data;
        }

        async function post(action, extra) {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData(action, extra || {}),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json().catch(function () { return {}; });
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Installer request failed.');
            }
            return data;
        }

        function renderChecks(rows, summary) {
            precheckList.innerHTML = '';
            rows.forEach(function (row) {
                const item = document.createElement('div');
                item.className = 'check';
                item.innerHTML = '<span class="badge ' + row.status + '">' + row.status + '</span><span class="check-title"></span><span class="check-msg"></span>';
                item.querySelector('.check-title').textContent = row.label || '';
                item.querySelector('.check-msg').textContent = row.message || '';
                precheckList.appendChild(item);
            });
            const ok = summary && summary.ok;
            precheckSummary.className = 'badge ' + (ok ? 'pass' : 'fail');
            precheckSummary.textContent = ok ? 'Ready' : 'Needs attention';
            repairButton.hidden = !(summary && summary.repairable);
        }

        async function runPrecheck() {
            activeStep('precheck');
            const data = await post('precheck');
            renderChecks(data.checks || [], data.summary || {});
            return data.summary && data.summary.ok;
        }

        function setProgress(index, total, heading, message) {
            const value = Math.max(0, Math.min(100, Math.round((index / total) * 100)));
            progress.classList.add('is-visible');
            fill.style.width = value + '%';
            pct.textContent = value + '%';
            title.textContent = heading;
            detail.textContent = message;
        }

        document.getElementById('metis-run-precheck')?.addEventListener('click', function () {
            setAlert('', '');
            runPrecheck().catch(function (error) { setAlert(error.message, 'error'); });
        });

	        repairButton?.addEventListener('click', async function () {
            setAlert('', '');
            repairButton.disabled = true;
            try {
                const data = await post('repair_permissions');
                renderChecks(data.checks || [], data.summary || {});
                setAlert(data.summary && data.summary.ok ? 'Permissions corrected.' : 'Permissions were updated where PHP had access. Remaining items need server-level attention.', data.summary && data.summary.ok ? 'notice' : 'error');
            } catch (error) {
                setAlert(error.message, 'error');
            } finally {
                repairButton.disabled = false;
            }
	        });

	        document.querySelectorAll('[data-prev-page]').forEach(function (button) {
	            button.addEventListener('click', function () {
	                showPage(button.getAttribute('data-prev-page') || 'precheck');
	            });
	        });

	        document.querySelectorAll('[data-next-page]').forEach(function (button) {
	            button.addEventListener('click', async function () {
	                const next = button.getAttribute('data-next-page') || 'precheck';
	                const currentPage = pages.find(function (page) { return page.classList.contains('is-active'); });
	                const current = currentPage ? currentPage.getAttribute('data-installer-page') : 'precheck';
	                if (!validatePage(current || 'precheck')) {
	                    return;
	                }
	                setAlert('', '');
	                button.disabled = true;
	                try {
	                    if (current === 'precheck') {
	                        const ready = await runPrecheck();
	                        if (!ready) {
	                            throw new Error('System requirements need attention before continuing.');
	                        }
	                    }
	                    if (current === 'database') {
	                        await post('validate_database');
	                        setAlert('Database credentials verified.', 'notice');
	                    }
	                    showPage(next);
	                } catch (error) {
	                    setAlert(error.message, 'error');
	                } finally {
	                    button.disabled = false;
	                }
	            });
	        });

        form?.addEventListener('submit', async function (event) {
            event.preventDefault();
            setAlert('', '');
            submitButton.disabled = true;
            activeStep('install');
            const schemaKeys = Object.keys(schemaSteps);
            const total = 5 + schemaKeys.length;
            let done = 0;
            const completed = [];

            try {
                setProgress(done, total, 'Checking system', 'Verifying required folders and PHP capabilities.');
                const ready = await runPrecheck();
                if (!ready) {
                    throw new Error('System requirements need attention before installation can continue.');
                }
                done++;

                activeStep('database');
                setProgress(done, total, 'Verifying database', 'Testing database credentials before writing configuration.');
                await post('validate_database');
                done++;

                setProgress(done, total, 'Creating configuration', 'Writing the secure runtime database configuration.');
                await post('write_config');
                done++;

                for (const key of schemaKeys) {
                    setProgress(done, total, 'Setting up database', 'Creating ' + schemaSteps[key] + '.');
                    await post('schema', { schema_step: key });
                    completed.push(key);
                    done++;
                }

                activeStep('admin');
                setProgress(done, total, 'Creating administrator', 'Creating the first Metis administrator account.');
                await post('create_admin');
                done++;

                setProgress(done, total, 'Finalizing Metis', 'Enabling protections, cleanup, and install lock.');
                const result = await post('finalize', { schema_created: JSON.stringify(completed) });
                done++;
                setProgress(done, total, 'Complete', 'Opening the admin portal.');
                window.location.href = result.redirect || '<?php echo metis_escape_js( metis_home_url( '/admin/' ) ); ?>';
            } catch (error) {
                setAlert(error.message, 'error');
                submitButton.disabled = false;
            }
        });
    })();
    </script>
</body>
</html>
<?php
    exit;
}

function metis_standalone_handle_database_setup(): void {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || empty( metis_request_post()['metis_setup'] ) ) {
        return;
    }

    if ( is_file( rtrim( (string) METIS_PATH, '/\\' ) . '/storage/install.lock' ) && ! metis_standalone_installer_recovery_enabled() ) {
        metis_standalone_render_database_setup( 'Installer is disabled after configuration has been written. Enable METIS_INSTALLER_RECOVERY to run recovery setup.' );
    }

    \Metis\Core\Application::service( 'csrf' )->requireValidToken( metis_request_post(), 'metis_installer_setup', 'Invalid installer request.' );

    $action = metis_key_clean( (string) ( metis_request_post()['metis_setup_action'] ?? '' ) );
    if ( $action !== '' ) {
        try {
            if ( $action === 'precheck' ) {
                $checks = metis_standalone_install_precheck_rows();
                metis_standalone_install_json( [ 'ok' => true, 'checks' => $checks, 'summary' => metis_standalone_install_precheck_summary( $checks ) ] );
            }

            if ( $action === 'repair_permissions' ) {
                metis_standalone_install_ensure_permissions();
                $checks = metis_standalone_install_precheck_rows();
                metis_standalone_install_json( [ 'ok' => true, 'checks' => $checks, 'summary' => metis_standalone_install_precheck_summary( $checks ) ] );
            }

	            $config = metis_standalone_install_config_from_request( metis_request_post() );
	            $admin = metis_standalone_install_admin_from_request( metis_request_post() );

	            if ( $action === 'validate_database' ) {
	                metis_standalone_install_validate_database_config( $config );
	                metis_standalone_install_json( [ 'ok' => true, 'message' => 'Database verified.' ] );
	            }

            if ( $action === 'write_config' ) {
                metis_standalone_install_validate_config_and_admin( $config, $admin, true );
                metis_standalone_install_ensure_permissions();
                metis_standalone_write_database_config( $config, false );
                metis_standalone_install_json( [ 'ok' => true, 'message' => 'Configuration written.' ] );
            }

            if ( $action === 'schema' ) {
                metis_standalone_install_validate_config_and_admin( $config, $admin, false );
                metis_standalone_install_boot_database_context( $config );
                $schema_step = metis_key_clean( (string) ( metis_request_post()['schema_step'] ?? '' ) );
                metis_standalone_install_run_schema_step( $schema_step );
                metis_standalone_install_json( [ 'ok' => true, 'step' => $schema_step, 'label' => metis_standalone_install_schema_steps()[ $schema_step ] ?? $schema_step ] );
            }

            if ( $action === 'create_admin' ) {
                metis_standalone_install_validate_config_and_admin( $config, $admin, false );
                metis_standalone_install_boot_database_context( $config );
                $admin_result = metis_standalone_install_ensure_first_admin( $admin );
                metis_standalone_install_json( [
                    'ok' => true,
                    'user_id' => (int) $admin_result['id'],
                    'existing' => (bool) $admin_result['existing'],
                ] );
            }

            if ( $action === 'finalize' ) {
                metis_standalone_install_validate_config_and_admin( $config, $admin, false );
                metis_standalone_install_boot_database_context( $config );
                $admin_result = metis_standalone_install_ensure_first_admin( $admin );
                metis_standalone_install_complete_defaults();
                $schema_created = json_decode( (string) ( metis_request_post()['schema_created'] ?? '[]' ), true );
                Core_Settings_Service::set( 'metis_install_schema_installers', is_array( $schema_created ) ? array_values( array_map( 'strval', $schema_created ) ) : [], false );
                Core_Settings_Service::set( 'metis_install_first_admin_user_id', (int) $admin_result['id'], false );
                metis_standalone_mark_installed();
                metis_standalone_install_ensure_permissions();
                metis_standalone_install_json( [ 'ok' => true, 'redirect' => metis_home_url( '/admin/' ) ] );
            }

            metis_standalone_install_json( [ 'ok' => false, 'error' => 'Unknown installer action.' ], 400 );
	        } catch ( Throwable $e ) {
	            metis_standalone_boot_log( 'setup_ajax_failed', [
	                'action' => $action,
	                'schema_step' => metis_key_clean( (string) ( metis_request_post()['schema_step'] ?? '' ) ),
	                'exception' => $e::class,
	                'error' => $e->getMessage(),
	                'file' => $e->getFile(),
	                'line' => $e->getLine(),
	            ] );
	            metis_standalone_install_json( [
	                'ok' => false,
	                'action' => $action,
	                'schema_step' => metis_key_clean( (string) ( metis_request_post()['schema_step'] ?? '' ) ),
	                'error' => $e->getMessage(),
	            ], 422 );
	        }
    }

    $config = metis_standalone_install_config_from_request( metis_request_post() );
    $admin = metis_standalone_install_admin_from_request( metis_request_post() );

    try {
        metis_standalone_install_validate_config_and_admin( $config, $admin, true );
    } catch ( Throwable $e ) {
        metis_standalone_boot_log( 'setup_connection_failed', [ 'error' => $e->getMessage(), 'config' => [ 'host' => $config['host'], 'port' => $config['port'], 'socket' => $config['socket'], 'database' => $config['database'], 'username' => $config['username'] ] ] );
        metis_standalone_render_database_setup( $e->getMessage(), metis_request_post() );
    }

    try {
        metis_standalone_install_ensure_permissions();
        metis_standalone_write_database_config( $config, false );
        metis_standalone_install_boot_database_context( $config );
        $schema_created = metis_standalone_install_ensure_all_schema();
        $admin_result = metis_standalone_install_ensure_first_admin( $admin );
        metis_standalone_install_complete_defaults();
        Core_Settings_Service::set( 'metis_install_schema_installers', $schema_created, false );
        Core_Settings_Service::set( 'metis_install_first_admin_user_id', (int) $admin_result['id'], false );
        metis_standalone_mark_installed();
        metis_standalone_install_ensure_permissions();
    } catch ( Throwable $e ) {
        metis_standalone_boot_log( 'setup_install_failed', [ 'error' => $e->getMessage() ] );
        metis_standalone_render_database_setup( 'Install could not complete. Review the server logs, then verify database permissions and administrator details.', metis_request_post() );
    }

    metis_runtime_redirect( metis_home_url( '/admin/' ) );
}

function metis_standalone_boot(): void {
    try {
        metis_register_core_services();
        metis_error_kernel()->install();

        $install_lock = rtrim( (string) METIS_PATH, '/\\' ) . '/storage/install.lock';
        $request_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?? '/' );
        $script_dir   = rtrim( dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ) ), '/' );
        $install_path = ( $script_dir === '' || $script_dir === '/' ? '' : $script_dir ) . '/install';
        $has_database_config = metis_standalone_has_database_config();
        $install_complete = is_file( $install_lock );
        $request_is_install = rtrim( $request_path, '/' ) === rtrim( $install_path, '/' );

        if ( PHP_SAPI !== 'cli' && ! $install_complete && ! $request_is_install ) {
            metis_runtime_redirect( $install_path );
        }

        if ( $install_complete && metis_standalone_configuration_exists() && ! $has_database_config && ! metis_standalone_installer_recovery_enabled() ) {
            metis_standalone_render_database_setup( 'Installer is disabled because configuration already exists. Enable METIS_INSTALLER_RECOVERY for recovery mode.' );
        }

        if ( ! $install_complete && $request_is_install ) {
            $existing = metis_standalone_database_config();
            $defaults = [
                'db_host' => (string) ( $existing['host'] ?? '127.0.0.1' ),
                'db_port' => (string) ( $existing['port'] ?? '3306' ),
                'db_name' => (string) ( $existing['database'] ?? '' ),
                'db_prefix' => (string) ( $existing['prefix'] ?? '' ),
                'db_user' => (string) ( $existing['username'] ?? '' ),
                'db_socket' => (string) ( $existing['socket'] ?? '' ),
                'app_key' => (string) ( $existing['app_key'] ?? '' ),
                'base_url' => (string) ( $existing['base_url'] ?? '' ),
                'site_name' => (string) ( $existing['install_site_name'] ?? 'Metis Portal' ),
	                'site_timezone' => (string) ( $existing['install_timezone'] ?? 'UTC' ),
	                'admin_display_name' => '',
	                'admin_email' => '',
	            ];

            metis_standalone_handle_database_setup();
            metis_standalone_render_database_setup(
                metis_standalone_has_database_config()
                    ? 'Metis could not finish bootstrap because the configured database is unavailable. Start the database service or enable installer recovery to update credentials.'
                    : '',
                $defaults
            );
        }

        if ( ! $has_database_config ) {
            metis_standalone_handle_database_setup();
            metis_standalone_render_database_setup();
        }

        $db = metis_standalone_database_config();
        $app_key = trim( (string) ( $db['app_key'] ?? '' ) );
        if ( in_array( strtolower( $app_key ), metis_runtime_insecure_app_key_values(), true ) || strlen( $app_key ) < 32 ) {
            throw new RuntimeException( 'Metis security configuration is missing a strong app_key after installation.' );
        }
        $basePath = rtrim( dirname( $_SERVER['SCRIPT_NAME'] ?? '' ), '/' );
        $GLOBALS['metis_runtime_config'] = [
            'db_charset' => (string) ( $db['charset'] ?? 'utf8mb4' ),
            'db_collation' => (string) ( $db['collation'] ?? 'utf8mb4_unicode_ci' ),
            'db_socket' => (string) ( $db['socket'] ?? '' ),
            'db_host' => (string) ( $db['host'] ?? '' ),
            'db_port' => (int) ( $db['port'] ?? 3306 ),
            'db_name' => (string) ( $db['database'] ?? '' ),
            'base_path' => $basePath === '/' ? '' : $basePath,
            'app_key' => $app_key,
            'base_url' => trim( (string) ( $db['base_url'] ?? '' ) ),
        ];
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'CONFIG_LOADED' );
        }

    metis_standalone_boot_log( 'boot_start', [
        'host' => (string) ( $db['host'] ?? '' ),
        'port' => (int) ( $db['port'] ?? 3306 ),
        'socket' => (string) ( $db['socket'] ?? '' ),
        'database' => (string) ( $db['database'] ?? '' ),
        'prefix' => (string) ( $db['prefix'] ?? '' ),
    ] );

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'db_connect_start' ] );
    $GLOBALS['metis_db_connection'] = new MetisRuntimeDbConnection(
        (string) $db['username'],
        (string) ( $db['password'] ?? '' ),
        (string) $db['database'],
        (string) $db['host'] . ':' . (int) ( $db['port'] ?? 3306 ),
        (string) ( $db['prefix'] ?? '' )
    );
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'DB_CONNECTED' );
    }
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'db_connect_ok' ] );

    if ( isset( metis_request_get()['metis_action'] ) && metis_request_get()['metis_action'] === 'logout' ) {
        unset( $_SESSION['metis_user'], $_SESSION['metis_session_token'] );
        \Metis\Core\Application::service( 'session_security' )->regenerateId();
        $redirect = isset( metis_request_get()['redirect_to'] ) ? (string) metis_request_get()['redirect_to'] : metis_home_url( '/' );
        metis_runtime_redirect( $redirect );
    }

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_settings_service' ] );
    require_once dirname( __DIR__ ) . '/SettingsService.php';
    metis_register_core_services();
    Metis::service( 'settings' )->init();

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_logger' ] );
    Metis_Logger::init();
    Metis_Logger::boot_start();

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_tables' ] );
    require_once dirname( __DIR__ ) . '/TablesRegistry.php';
    Metis_Tables::init();
    require_once METIS_SRC_PATH . 'Metis/Core/Auth/AuthRuntime.php';
    metis_register_core_services();
    Metis::service( 'auth' )->ensure_schema();
    Metis::service( 'auth' )->refresh_session();

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_core' ] );
    require_once dirname( __DIR__ ) . '/CoreHelpers.php';
    require_once dirname( __DIR__ ) . '/AccessibilityRuntime.php';
    require_once dirname( __DIR__ ) . '/AuditRuntime.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Webhooks/WebhookRuntime.php';
    require_once dirname( __DIR__ ) . '/IntegrityRuntime.php';
    require_once dirname( __DIR__ ) . '/Security/SecurityEnclave.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Cron/CronRuntime.php';
    require_once dirname( __DIR__ ) . '/BackupRuntime.php';
    if ( ! class_exists( 'Metis_Code_Registry', false ) ) {
        require_once dirname( __DIR__ ) . '/CodeRegistry.php';
    }
    require_once dirname( __DIR__ ) . '/Security/SecurityRuntimeBridge.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Ajax/AjaxRuntime.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Routing/RouterRuntime.php';
    require_once dirname( __DIR__ ) . '/AssetsRuntime.php';
    require_once dirname( __DIR__ ) . '/PdfService.php';
    require_once dirname( __DIR__ ) . '/ReportsService.php';
    require_once dirname( __DIR__ ) . '/ManagerRuntime.php';
    require_once dirname( __DIR__ ) . '/DatabaseRuntime.php';
    require_once dirname( __DIR__ ) . '/RenameTablesRuntime.php';
    metis_standalone_require_recovery_runtime();
    metis_register_core_services();

    Metis_Integrity_Manager::init();
    metis_standalone_enable_recovery_defaults();
    \Metis\Core\Recovery\RecoverySchema::ensureSchema();
    $preboot_recovery = ( new \Metis\Core\Recovery\PrebootIntegrityService() )->checkAndRecover( 'standalone_preboot' );
    if ( (string) ( $preboot_recovery['status'] ?? '' ) === 'maintenance' ) {
        \Metis\Core\Recovery\metis_recovery_render_maintenance_page( $preboot_recovery );
        exit;
    }
    Metis_Logger::core_loaded();
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'core_loaded' ] );

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_apis' ] );
    require_once dirname( __DIR__ ) . '/Integrations/StripeRuntimeBootstrap.php';
    require_once dirname( __DIR__ ) . '/Integrations/StripeImportHandler.php';
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'apis_loaded' ] );

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_modules' ] );
    require_once METIS_SRC_PATH . 'Metis/Core/Modules/ModulesRuntime.php';
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'MODULE_LOADED' );
    }
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'modules_loaded' ] );

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_COMM_INBOUND_START' );
    }
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_communications_inbound_core' ] );
    require_once METIS_SRC_PATH . 'Metis/Core/CommunicationsInboundRuntime.php';
    if ( function_exists( 'metis_communications_inbound_boot_required_for_request' ) && metis_communications_inbound_boot_required_for_request() ) {
        try {
            metis_communications_inbound_boot();
            metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'communications_inbound_core_loaded' ] );
        } catch ( \Throwable $e ) {
            metis_standalone_boot_log(
                'boot_phase',
                [
                    'phase' => 'communications_inbound_core_skipped',
                    'error' => $e->getMessage(),
                ]
            );
            if ( class_exists( 'Metis_Logger', false ) ) {
                \Metis_Logger::warn(
                    'Communications inbound bootstrap skipped during standalone boot',
                    [
                        'module'  => 'communications_inbound',
                        'service' => 'standalone_bootstrap',
                        'error'   => $e->getMessage(),
                    ]
                );
            }
        }
    } else {
        metis_standalone_boot_log(
            'boot_phase',
            [
                'phase' => 'communications_inbound_core_deferred',
                'request_uri' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
            ]
        );
    }
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_COMM_INBOUND_DONE' );
    }

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_REGISTER_RUNTIME_HOOKS' );
    }
    metis_on( 'metis_runtime_loaded', static function (): void {
        Metis::service( 'settings' )->set( 'payment_statuses', [ 'pending', 'completed', 'refunded', 'failed', 'voided' ] );
    }, 5 );
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_REGISTER_RUNTIME_HOOKS_DONE' );
    }

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'run_hooks' ] );
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_HOOK_INIT' );
    }
    metis_do_action( 'init' );
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_HOOK_INIT_DONE' );
        Profiler::mark( 'BOOT_HOOK_ADMIN_INIT' );
    }
    metis_do_action( 'metis_admin_init' );
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_HOOK_ADMIN_INIT_DONE' );
        Profiler::mark( 'BOOT_HOOK_RUNTIME_LOADED' );
    }
    metis_do_action( 'metis_runtime_loaded' );
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'BOOT_HOOK_RUNTIME_LOADED_DONE' );
    }
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'hooks_complete' ] );

        if ( function_exists( 'metis_install_db' ) && metis_standalone_core_schema_needs_install() ) {
            if ( class_exists( 'Profiler', false ) ) {
                Profiler::mark( 'BOOT_INSTALL_DB' );
            }
            metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'install_db_start' ] );
            metis_install_db();
            metis_standalone_mark_core_schema_installed();
            metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'install_db_complete' ] );
            if ( class_exists( 'Profiler', false ) ) {
                Profiler::mark( 'BOOT_INSTALL_DB_DONE' );
            }
        } elseif ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'BOOT_INSTALL_DB_SKIPPED' );
        }

        metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'install_defaults_start' ] );
        $install_completed_before_defaults = class_exists( 'Core_Settings_Service' )
            ? (bool) Core_Settings_Service::get( 'metis_install_completed', false )
            : false;
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'BOOT_INSTALL_PERMISSIONS' );
        }
        if ( ! $install_completed_before_defaults ) {
            metis_standalone_install_ensure_permissions();
        }
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'BOOT_INSTALL_PERMISSIONS_DONE' );
            Profiler::mark( 'BOOT_INSTALL_DEFAULTS' );
        }
        metis_standalone_install_complete_defaults();
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'BOOT_INSTALL_DEFAULTS_DONE' );
        }
        if ( ! $install_completed_before_defaults ) {
            try {
                if ( class_exists( 'Profiler', false ) ) {
                    Profiler::mark( 'BOOT_RECOVERY_SCHEMA' );
                }
                \Metis\Core\Recovery\RecoverySchema::ensureSchema();
                if ( class_exists( 'Profiler', false ) ) {
                    Profiler::mark( 'BOOT_RECOVERY_SCHEMA_DONE' );
                    Profiler::mark( 'BOOT_RECOVERY_MANIFEST' );
                }
                ( new \Metis\Core\Recovery\RecoveryVerifier() )->rebuildManifest( 'install_defaults' );
                if ( class_exists( 'Profiler', false ) ) {
                    Profiler::mark( 'BOOT_RECOVERY_MANIFEST_DONE' );
                }
            } catch ( \Throwable $recovery_manifest_error ) {
                metis_standalone_boot_log( 'recovery_manifest_build_failed', [
                    'error' => $recovery_manifest_error->getMessage(),
                ] );
            }
        } elseif ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'BOOT_RECOVERY_SCHEMA_SKIPPED' );
            Profiler::mark( 'BOOT_RECOVERY_MANIFEST_SKIPPED' );
        }
        metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'install_defaults_complete' ] );

        Metis_Logger::info( 'Standalone bootstrap completed' );
        metis_standalone_boot_log( 'boot_complete' );
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'BOOT_COMPLETE' );
        }
    } catch ( \Throwable $e ) {
        metis_standalone_raw_boot_log(
            'boot_exception',
            [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]
        );
        throw $e;
    }
}

function metis_standalone_dispatch(): void {
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'DISPATCH_START' );
    }
    $request = metis_router_build_request();
    metis_error_kernel()->captureRequest( $request );
    metis_standalone_boot_log( 'dispatch_start', [
        'path' => $request->path(),
        'method' => $request->method(),
        'request_uri' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
        'script_name' => (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ),
        'path_info' => (string) ( $_SERVER['PATH_INFO'] ?? '' ),
        'orig_path_info' => (string) ( $_SERVER['ORIG_PATH_INFO'] ?? '' ),
        'redirect_url' => (string) ( $_SERVER['REDIRECT_URL'] ?? '' ),
    ] );

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'DISPATCH_ROUTE_REQUEST' );
    }
    metis_kernel_route_request( 'web', $request );
}
