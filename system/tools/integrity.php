<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
require_once $root . '/src/Metis/Core/Services/FileService.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( 'standalone_bootstrap' );

function metis_integrity_cli_usage(): never {
    $script = 'php ' . METIS_TOOLS_PATH . 'integrity.php';
    $lines = [
        'Metis integrity CLI',
        '',
        'Usage:',
        '  ' . $script . ' baseline [reason]',
        '  ' . $script . ' baseline-sign [reason]',
        '  ' . $script . ' scan [trigger]',
        '  ' . $script . ' verify',
        '  ' . $script . ' keygen [private-key-path] [public-key-path]',
        '  ' . $script . ' runtime',
        '',
        'Commands:',
        '  baseline      Rebuild the manifest and recovery snapshot tree.',
        '  baseline-sign Rebuild the baseline and sign the manifest.',
        '  scan          Run a manual integrity scan and auto-heal pass.',
        '  verify        Verify the manifest signature and recovery snapshots.',
        '  keygen        Generate an RSA keypair and write config/integrity.php.',
        '  runtime       Ensure integrity runtime directories and manifest state exist.',
    ];

    fwrite( STDERR, implode( PHP_EOL, $lines ) . PHP_EOL );
    exit( 1 );
}

function metis_integrity_cli_boot(): void {
    if ( ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();
}

function metis_integrity_cli_relative_to_root( string $path ): string {
    $root = rtrim( METIS_PATH, '/' ) . '/';
    return str_starts_with( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
}

function metis_integrity_cli_write_config( string $private_key_path, string $public_key_path ): void {
    $files = new \Metis\Core\Services\FileService();
    $config_path = METIS_CONFIG_PATH . 'integrity.php';
    $config = [
        'require_signature' => true,
        'private_key_path' => metis_integrity_cli_relative_to_root( $private_key_path ),
        'public_key_path' => metis_integrity_cli_relative_to_root( $public_key_path ),
    ];
    $payload = "<?php\nreturn " . var_export( $config, true ) . ";\n";
    $files->write( $config_path, $payload );
    metis_standalone_invalidate_config_cache();
    metis_standalone_compiled_config( true );
}

function metis_integrity_cli_keygen( string $private_key_path, string $public_key_path ): array {
    $files = new \Metis\Core\Services\FileService();
    $private_dir = dirname( $private_key_path );
    $public_dir = dirname( $public_key_path );
    if ( ! is_dir( $private_dir ) ) {
        $files->ensureDirectory( $private_dir );
    }
    if ( ! is_dir( $public_dir ) ) {
        $files->ensureDirectory( $public_dir );
    }

    $resource = openssl_pkey_new( [
        'private_key_bits' => 4096,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ] );
    if ( $resource === false ) {
        throw new RuntimeException( 'Failed to generate OpenSSL keypair.' );
    }

    $private_key = '';
    if ( ! openssl_pkey_export( $resource, $private_key ) || $private_key === '' ) {
        throw new RuntimeException( 'Failed to export private key.' );
    }

    $details = openssl_pkey_get_details( $resource );
    if ( ! is_array( $details ) || empty( $details['key'] ) ) {
        throw new RuntimeException( 'Failed to export public key.' );
    }

    $files->write( $private_key_path, $private_key );
    $files->setPermissions( $private_key_path, 0600 );
    $files->write( $public_key_path, (string) $details['key'] );
    $files->setPermissions( $public_key_path, 0644 );

    metis_integrity_cli_write_config( $private_key_path, $public_key_path );

    return [
        'command' => 'keygen',
        'private_key_path' => $private_key_path,
        'public_key_path' => $public_key_path,
        'config_path' => METIS_CONFIG_PATH . 'integrity.php',
        'require_signature' => true,
    ];
}

$command = $argv[1] ?? '';
if ( $command === '' || in_array( $command, [ '-h', '--help', 'help' ], true ) ) {
    metis_integrity_cli_usage();
}

metis_integrity_cli_boot();

switch ( $command ) {
    case 'baseline':
        $reason = (string) ( $argv[2] ?? 'cli_manual' );
        $ok = Metis_Integrity_Manager::build_baseline( $reason );
        $result = [
            'command' => 'baseline',
            'reason' => $reason,
            'ok' => $ok,
            'storage' => METIS_PATH . 'storage/runtime/integrity',
        ];
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( $ok ? 0 : 1 );

    case 'baseline-sign':
        $reason = (string) ( $argv[2] ?? 'cli_manual_signed' );
        $built = Metis_Integrity_Manager::build_baseline( $reason );
        $signed = $built ? Metis_Integrity_Manager::sign_baseline() : false;
        $result = [
            'command' => 'baseline-sign',
            'reason' => $reason,
            'baseline_built' => $built,
            'manifest_signed' => $signed,
            'storage' => METIS_PATH . 'storage/runtime/integrity',
            'config_path' => METIS_CONFIG_PATH . 'integrity.php',
        ];
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( $built && $signed ? 0 : 1 );

    case 'scan':
        $trigger = (string) ( $argv[2] ?? 'cli_manual' );
        $result = Metis_Integrity_Manager::scan_and_heal( $trigger );
        $result['command'] = 'scan';
        $result['trigger'] = $trigger;
        $result['storage'] = METIS_PATH . 'storage/runtime/integrity';
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( 0 );

    case 'verify':
        $result = Metis_Integrity_Manager::verify_baseline();
        $result['command'] = 'verify';
        $result['storage'] = METIS_PATH . 'storage/runtime/integrity';
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( ! empty( $result['ok'] ) ? 0 : 1 );

    case 'keygen':
        $private_key_path = (string) ( $argv[2] ?? ( METIS_PATH . 'storage/runtime/integrity/keys/integrity-private.pem' ) );
        $public_key_path = (string) ( $argv[3] ?? ( METIS_PATH . 'storage/runtime/integrity/keys/integrity-public.pem' ) );
        $result = metis_integrity_cli_keygen( $private_key_path, $public_key_path );
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( 0 );

    case 'runtime':
        Metis_Integrity_Manager::ensure_runtime();
        $result = [
            'command' => 'runtime',
            'ok' => true,
            'storage' => METIS_PATH . 'storage/runtime/integrity',
        ];
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
        exit( 0 );

    default:
        fwrite( STDERR, 'Unknown command: ' . $command . PHP_EOL );
        metis_integrity_cli_usage();
}
