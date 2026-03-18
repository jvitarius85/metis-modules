<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/CoreBootstrap.php';

metis_core_bootstrap( [ 'standalone_runtime', 'http', 'log', 'service_registry', 'release' ] );

if ( ! function_exists( 'metis_portal_slug' ) ) {
    function metis_portal_slug(): string {
        if ( defined( 'METIS_STANDALONE' ) && METIS_STANDALONE ) {
            return '';
        }

        $slug = class_exists( 'Core_Settings_Service' ) ? Core_Settings_Service::get( 'portal_slug', 'metis' ) : 'metis';
        if ( is_array( $slug ) ) {
            $slug = reset( $slug );
        }
        if ( ! is_string( $slug ) || trim( $slug ) === '' ) {
            $slug = 'metis';
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
    if (
        class_exists( 'Metis_Logger' )
        && isset( $GLOBALS['metis_db_connection'] )
        && $GLOBALS['metis_db_connection'] instanceof MetisRuntimeDbConnection
    ) {
        Metis_Logger::info( 'standalone_' . $message, $context );
    }
}

function metis_standalone_database_config_path(): string {
    return dirname( __DIR__, 4 ) . '/config/database.php';
}

function metis_standalone_config_directory(): string {
    return dirname( __DIR__, 4 ) . '/config';
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
    $value = $config['config'][ $name ] ?? $default;
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
    return is_file( metis_standalone_database_config_path() ) || is_file( dirname( __DIR__, 4 ) . '/storage/install.lock' );
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

function metis_standalone_write_database_config( array $config ): void {
    $dir = dirname( metis_standalone_database_config_path() );
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0775, true );
    }

    $payload = "<?php\nreturn " . var_export( $config, true ) . ";\n";
    file_put_contents( metis_standalone_database_config_path(), $payload, LOCK_EX );
    file_put_contents( dirname( __DIR__, 4 ) . '/storage/install.lock', "installed\n", LOCK_EX );
    metis_standalone_invalidate_config_cache();
    metis_standalone_compiled_config( true );
}

function metis_standalone_render_database_setup( string $error = '', array $old = [] ): never {
    status_header( $error === '' ? 200 : 422 );
    header( 'Content-Type: text/html; charset=UTF-8' );
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metis Setup</title>
    <style>
        body { font-family: Georgia, serif; margin: 0; background: linear-gradient(160deg, #f7f3ea, #e5edf6); color: #1f2330; }
        .wrap { max-width: 760px; margin: 48px auto; padding: 32px; background: rgba(255,255,255,.92); border: 1px solid #d8dfe8; box-shadow: 0 20px 60px rgba(34,42,58,.08); }
        h1 { margin-top: 0; font-size: 2rem; }
        p { line-height: 1.5; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #bcc8d6; background: #fff; }
        .full { grid-column: 1 / -1; }
        .error { margin-bottom: 16px; padding: 12px; background: #fff2f0; color: #8a2a1f; border: 1px solid #e7b2ab; }
        button { margin-top: 20px; padding: 12px 18px; border: 0; background: #24324a; color: #fff; cursor: pointer; }
        @media (max-width: 720px) { .wrap { margin: 20px; padding: 24px; } .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Connect Metis to a database</h1>
        <p>`index.php` is now the application entrypoint. Before Metis can boot, it needs direct database credentials for the native Metis runtime.</p>
        <?php if ( $error !== '' ) : ?>
            <div class="error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="metis_setup" value="1">
            <?php echo \Metis\Core\Application::has_service( 'csrf' )
                ? \Metis\Core\Application::service( 'csrf' )->hiddenFields( 'metis_installer_setup' )
                : ''; ?>
            <div class="grid">
                <div>
                    <label for="db_host">Host</label>
                    <input id="db_host" name="db_host" value="<?php echo esc_attr( (string) ( $old['db_host'] ?? '127.0.0.1' ) ); ?>" required>
                </div>
                <div>
                    <label for="db_port">Port</label>
                    <input id="db_port" name="db_port" value="<?php echo esc_attr( (string) ( $old['db_port'] ?? '3306' ) ); ?>" required>
                </div>
                <div>
                    <label for="db_name">Database</label>
                    <input id="db_name" name="db_name" value="<?php echo esc_attr( (string) ( $old['db_name'] ?? '' ) ); ?>" required>
                </div>
                <div>
                    <label for="db_prefix">Table Prefix</label>
                    <input id="db_prefix" name="db_prefix" value="<?php echo esc_attr( (string) ( $old['db_prefix'] ?? '' ) ); ?>" placeholder="Leave blank for metis_*">
                </div>
                <div>
                    <label for="db_user">Username</label>
                    <input id="db_user" name="db_user" value="<?php echo esc_attr( (string) ( $old['db_user'] ?? '' ) ); ?>" required>
                </div>
                <div>
                    <label for="db_password">Password</label>
                    <input id="db_password" name="db_password" type="password" value="<?php echo esc_attr( (string) ( $old['db_password'] ?? '' ) ); ?>">
                </div>
                <div class="full">
                    <label for="db_socket">Socket Path (optional)</label>
                    <input id="db_socket" name="db_socket" value="<?php echo esc_attr( (string) ( $old['db_socket'] ?? '' ) ); ?>" placeholder="/tmp/mysql.sock">
                </div>
                <div class="full">
                    <label for="app_key">Application Key</label>
                    <input id="app_key" name="app_key" value="<?php echo esc_attr( (string) ( $old['app_key'] ?? bin2hex( random_bytes( 24 ) ) ) ); ?>" required>
                </div>
                <div class="full">
                    <label for="base_url">Base URL (optional)</label>
                    <input id="base_url" name="base_url" value="<?php echo esc_attr( (string) ( $old['base_url'] ?? '' ) ); ?>" placeholder="https://app.example.com/metis">
                </div>
            </div>
            <button type="submit">Save and boot Metis</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

function metis_standalone_handle_database_setup(): void {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || empty( $_POST['metis_setup'] ) ) {
        return;
    }

    if ( metis_standalone_configuration_exists() && ! metis_standalone_installer_recovery_enabled() ) {
        metis_standalone_render_database_setup( 'Installer is disabled after configuration has been written. Enable METIS_INSTALLER_RECOVERY to run recovery setup.' );
    }

    \Metis\Core\Application::service( 'csrf' )->requireValidToken( $_POST, 'metis_installer_setup', 'Invalid installer request.' );

    $config = [
        'host' => sanitize_text_field( (string) ( $_POST['db_host'] ?? '' ) ),
        'port' => (int) sanitize_text_field( (string) ( $_POST['db_port'] ?? '3306' ) ),
        'database' => sanitize_text_field( (string) ( $_POST['db_name'] ?? '' ) ),
        'username' => sanitize_text_field( (string) ( $_POST['db_user'] ?? '' ) ),
        'password' => (string) ( $_POST['db_password'] ?? '' ),
        'socket' => sanitize_text_field( (string) ( $_POST['db_socket'] ?? '' ) ),
        'prefix' => sanitize_key( (string) ( $_POST['db_prefix'] ?? '' ) ),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'app_key' => sanitize_text_field( (string) ( $_POST['app_key'] ?? '' ) ),
        'base_url' => trim( filter_var( (string) ( $_POST['base_url'] ?? '' ), FILTER_SANITIZE_URL ) ?: '' ),
    ];

    $probe = @new mysqli( $config['host'], $config['username'], $config['password'], $config['database'], $config['port'] );
    if ( $probe->connect_errno ) {
        try {
            $conn = metis_runtime_connect_mysqli(
                $config['host'],
                $config['username'],
                $config['password'],
                $config['database'],
                (int) $config['port'],
                (string) $config['socket']
            );
            $conn->close();
        } catch ( Throwable $e ) {
            metis_standalone_boot_log( 'setup_connection_failed', [ 'error' => $e->getMessage(), 'config' => [ 'host' => $config['host'], 'port' => $config['port'], 'socket' => $config['socket'], 'database' => $config['database'], 'username' => $config['username'] ] ] );
            metis_standalone_render_database_setup( 'Connection failed: ' . $e->getMessage(), $_POST );
        }
    } else {
        $probe->close();
    }

    metis_standalone_write_database_config( $config );
    metis_runtime_redirect( metis_home_url( '/' ) );
}

function metis_standalone_boot(): void {
    metis_register_core_services();
    metis_error_kernel()->install();

    if ( metis_standalone_configuration_exists() && ! metis_standalone_has_database_config() && ! metis_standalone_installer_recovery_enabled() ) {
        metis_standalone_render_database_setup( 'Installer is disabled because configuration already exists. Enable METIS_INSTALLER_RECOVERY for recovery mode.' );
    }

    if ( ! metis_standalone_has_database_config() ) {
        metis_standalone_handle_database_setup();
        metis_standalone_render_database_setup();
    }

    $db = metis_standalone_database_config();
    $basePath = rtrim( dirname( $_SERVER['SCRIPT_NAME'] ?? '' ), '/' );
    $GLOBALS['metis_runtime_config'] = [
        'db_charset' => (string) ( $db['charset'] ?? 'utf8mb4' ),
        'db_collation' => (string) ( $db['collation'] ?? 'utf8mb4_unicode_ci' ),
        'db_socket' => (string) ( $db['socket'] ?? '' ),
        'db_host' => (string) ( $db['host'] ?? '' ),
        'db_port' => (int) ( $db['port'] ?? 3306 ),
        'db_name' => (string) ( $db['database'] ?? '' ),
        'base_path' => $basePath === '/' ? '' : $basePath,
        'app_key' => (string) ( $db['app_key'] ?? 'metis-local-key' ),
        'base_url' => trim( (string) ( $db['base_url'] ?? '' ) ),
    ];

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
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'db_connect_ok' ] );

    if ( isset( $_GET['metis_action'] ) && $_GET['metis_action'] === 'logout' ) {
        unset( $_SESSION['metis_user'], $_SESSION['metis_session_token'] );
        \Metis\Core\Application::service( 'session_security' )->regenerateId();
        $redirect = isset( $_GET['redirect_to'] ) ? (string) $_GET['redirect_to'] : metis_home_url( '/' );
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
    require_once dirname( __DIR__, 4 ) . '/src/Metis/Core/Auth/AuthRuntime.php';
    metis_register_core_services();
    Metis::service( 'auth' )->ensure_schema();
    Metis::service( 'auth' )->refresh_session();

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_core' ] );
    require_once dirname( __DIR__ ) . '/CoreHelpers.php';
    require_once dirname( __DIR__ ) . '/AccessibilityRuntime.php';
    require_once dirname( __DIR__ ) . '/AuditRuntime.php';
    require_once dirname( __DIR__, 4 ) . '/src/Metis/Core/Webhooks/WebhookRuntime.php';
    require_once dirname( __DIR__ ) . '/IntegrityRuntime.php';
    require_once dirname( __DIR__ ) . '/Security/SecurityEnclave.php';
    require_once dirname( __DIR__, 4 ) . '/src/Metis/Core/Cron/CronRuntime.php';
    require_once dirname( __DIR__ ) . '/BackupRuntime.php';
    require_once dirname( __DIR__ ) . '/CodeRegistry.php';
    require_once dirname( __DIR__ ) . '/Security/SecurityRuntimeBridge.php';
    require_once dirname( __DIR__, 4 ) . '/src/Metis/Core/Ajax/AjaxRuntime.php';
    require_once dirname( __DIR__, 4 ) . '/src/Metis/Core/Routing/RouterRuntime.php';
    require_once dirname( __DIR__ ) . '/AssetsRuntime.php';
    require_once dirname( __DIR__ ) . '/PdfService.php';
    require_once dirname( __DIR__ ) . '/ReportsService.php';
    require_once dirname( __DIR__ ) . '/ManagerRuntime.php';
    require_once dirname( __DIR__ ) . '/DatabaseRuntime.php';
    require_once dirname( __DIR__ ) . '/RenameTablesRuntime.php';
    metis_register_core_services();

    Metis_Integrity_Manager::init();
    Metis_Logger::core_loaded();
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'core_loaded' ] );

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_apis' ] );
    require_once dirname( __DIR__ ) . '/Integrations/StripeRuntimeBootstrap.php';
    require_once dirname( __DIR__ ) . '/Integrations/StripeImportHandler.php';
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'apis_loaded' ] );

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'load_modules' ] );
    require_once dirname( __DIR__, 4 ) . '/src/Metis/Core/Modules/ModulesRuntime.php';
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'modules_loaded' ] );

    metis_on( 'metis_runtime_loaded', static function (): void {
        Metis::service( 'settings' )->set( 'payment_statuses', [ 'pending', 'completed', 'refunded', 'failed', 'voided' ] );
    }, 5 );

    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'run_hooks' ] );
    metis_do_action( 'init' );
    metis_do_action( 'metis_admin_init' );
    metis_do_action( 'metis_runtime_loaded' );
    metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'hooks_complete' ] );

    if ( function_exists( 'metis_install_db' ) ) {
        metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'install_db_start' ] );
        metis_install_db();
        metis_standalone_boot_log( 'boot_phase', [ 'phase' => 'install_db_complete' ] );
    }

    Metis_Logger::info( 'Standalone bootstrap completed' );
    metis_standalone_boot_log( 'boot_complete' );
}

function metis_standalone_dispatch(): void {
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

    metis_kernel_route_request( 'web', $request );
}
