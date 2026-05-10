<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $relative, '/\\' ) );
    return $contents === false ? '' : $contents;
};

$bootstrap = $read( 'src/Metis/Core/Runtime/StandaloneBootstrap.php' );
$runtimeBootstrap = $read( 'src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php' );
$kernel = $read( 'src/Metis/Core/Kernel/Bootstrap.php' );
$uploads = $read( 'src/Metis/Core/Runtime/UploadsRuntime.php' );
$avatar = $read( 'src/Metis/Core/AvatarService.php' );
$processRunner = $read( 'src/Metis/Core/Services/ProcessRunner.php' );
$releaseManager = $read( 'src/Metis/Release/ReleaseManager.php' );
$integrityRuntime = $read( 'src/Metis/Core/IntegrityRuntime.php' );
$recoveryService = $read( 'src/Metis/Core/Recovery/GitRecoveryService.php' );
$financeService = $read( 'src/Metis/Modules/Finance/FinanceV2Service.php' );
$scanner = $read( 'tools/security_scan.php' );
$hermesRegistry = $read( 'src/Metis/Hermes/HermesToolRegistry.php' );
$hermesExecutor = $read( 'src/Metis/Hermes/HermesToolExecutor.php' );
$hermesGateway = $read( 'src/Metis/Hermes/HermesGateway.php' );
$governance = require $root . '/config/governance.php';

$assert( str_contains( $bootstrap, 'function metis_runtime_require_app_key' ), 'Runtime must expose a central app-key requirement helper.' );
$assert( str_contains( $bootstrap, "metis_runtime_require_app_key( 'nonce generation' )" ), 'Nonce generation must use the central app-key helper.' );
$assert( str_contains( $bootstrap, "metis_runtime_require_app_key( 'nonce verification' )" ), 'Nonce verification must use the central app-key helper.' );
$assert( str_contains( $runtimeBootstrap, 'missing a strong app_key after installation' ), 'Installed runtime must fail closed when app_key is missing or insecure.' );
$assert( str_contains( $runtimeBootstrap, 'bin2hex( random_bytes( 32 ) )' ), 'Installer path must still generate an explicit app key.' );

$assert( str_contains( $kernel, "storage/public-media" ), 'Kernel must know the public media storage root.' );
$assert( str_contains( $kernel, "storage/protected-media" ), 'Kernel must know the protected media storage root.' );
$assert( str_contains( $kernel, "storage/private-records" ), 'Kernel must know the private records storage root.' );
$assert( str_contains( $kernel, "'public' => [ 'public' => \$all_roots['public'] ]" ), 'Raw media serving must be restricted to public media.' );
$assert( str_contains( $kernel, '$normalized_base = rtrim' ), 'Media resolution must compare normalized storage roots with a directory boundary.' );
$assert( str_contains( $kernel, '! is_link( $target_path )' ), 'Media resolution must reject symlinks.' );
$assert( str_contains( $kernel, 'access_expires_at' ), 'Protected media must enforce token expiration metadata.' );
$assert( str_contains( $kernel, "metis_security_user_can( 'media.view' )" ), 'Protected media must require media.view permission.' );
$assert( str_contains( $kernel, 'metis_audit_log_security' ), 'Protected media denials must be security-audited.' );
$assert( str_contains( $kernel, 'metis_audit_log_activity' ), 'Protected media grants must be activity-audited.' );

$assert( str_contains( $uploads, 'storage_class' ), 'Media records must store a media storage class.' );
$assert( str_contains( $uploads, 'access_expires_at' ), 'Media records must store an optional access expiration.' );
$assert( str_contains( $uploads, 'metis_media_storage_class_for_path' ), 'Media registration must classify file storage paths centrally.' );
$assert( str_contains( $uploads, 'function metis_store_public_media' ), 'Media runtime must expose a canonical public-media helper.' );
$assert( str_contains( $uploads, 'function metis_store_protected_media' ), 'Media runtime must expose a canonical protected-media helper.' );
$assert( str_contains( $uploads, 'function metis_store_private_record' ), 'Media runtime must expose a canonical private-record helper.' );
$assert( str_contains( $uploads, 'Protected media requires an explicit access expiration.' ), 'Protected/private media writes must fail without expiration metadata.' );
$assert( str_contains( $uploads, '/storage/public-media' ), 'Default uploads must write public media to the public media root.' );
$assert( str_contains( $avatar, '/storage/public-media/avatars/' ), 'Avatar storage must use public media rather than legacy uploads.' );

foreach ( [
    'ReleaseManager' => $releaseManager,
    'IntegrityRuntime' => $integrityRuntime,
    'GitRecoveryService' => $recoveryService,
    'FinanceV2Service' => $financeService,
] as $name => $source ) {
    $assert( str_contains( $source, 'ProcessRunner' ), $name . ' must route process execution through ProcessRunner.' );
}
$assert( str_contains( $processRunner, 'proc_open' ), 'ProcessRunner must be the central process execution gateway.' );
$assert( str_contains( $processRunner, 'metis_audit_log_activity' ), 'ProcessRunner must audit process execution.' );
$assert( str_contains( $processRunner, 'validateCommand' ), 'ProcessRunner must validate command vectors before execution.' );
$assert( str_contains( $processRunner, 'validateExecutionContext' ), 'ProcessRunner must require explicit execution context.' );
$assert( str_contains( $processRunner, 'security_context' ), 'ProcessRunner must require security context.' );
$assert( str_contains( $processRunner, 'audit_context' ), 'ProcessRunner must require audit context.' );
$assert( str_contains( $processRunner, 'permission_context' ), 'ProcessRunner must require permission context.' );

$assert( is_array( $governance['approved_layers']['superglobals'] ?? null ), 'Governance config must define approved superglobal layers.' );
$assert( ( $governance['approved_layers']['superglobals'] ?? null ) === [], 'Raw superglobal approvals must remain empty.' );
$assert( ( $governance['approved_layers']['request_boundary'] ?? [] ) === [ 'system/src/Metis/Core/Runtime/RequestRuntime.php' ], 'Request SAPI bridge must remain isolated to RequestRuntime.' );
$assert( is_array( $governance['approved_layers']['raw_sql'] ?? null ), 'Governance config must define approved raw SQL layers.' );
$assert( is_array( $governance['approved_layers']['native_db'] ?? null ), 'Governance config must define approved native DB layers.' );
$assert( is_array( $governance['approved_layers']['serialization'] ?? null ), 'Governance config must define approved serialization layers.' );
$assert( is_array( $governance['approved_layers']['process'] ?? null ), 'Governance config must define approved process layers.' );
$broadApprovalPrefixes = [
    'system/enclave/',
    'system/modules/',
    'system/src/Metis/Core/',
    'system/src/Metis/Hermes/',
    'system/src/Metis/Modules/',
    'system/tools/',
];
foreach ( (array) ( $governance['approved_layers'] ?? [] ) as $bucket => $entries ) {
    foreach ( (array) $entries as $entry ) {
        $assert(
            ! in_array( (string) $entry, $broadApprovalPrefixes, true ),
            sprintf( 'Governance bucket [%s] must not use broad allowlist prefix [%s].', (string) $bucket, (string) $entry )
        );
    }
}
$assert( str_contains( $scanner, '/config/governance.php' ), 'Security scan must consume the central governance config.' );
$assert( str_contains( $scanner, 'no-request-superglobal' ), 'Security scan must reject mixed request superglobal usage.' );
$assert( ! str_contains( $scanner, '$' . '_REQUEST\\b' ), 'Security scan must not match itself while scanning mixed request superglobal usage.' );

$assert( str_contains( $hermesRegistry, 'required_permissions' ), 'Hermes tools must declare required permissions.' );
$assert( str_contains( $hermesRegistry, 'enclave_action' ), 'Hermes tools must declare enclave actions.' );
$assert( str_contains( $hermesRegistry, 'requires_approval' ), 'Hermes tools must declare approval requirements.' );
$assert( str_contains( $hermesExecutor, 'HermesPermissionValidator' ), 'Hermes executor must validate permissions.' );
$assert( str_contains( $hermesExecutor, 'EnclaveToolRuntime.php' ), 'Hermes executor must route through Secure Enclave runtime.' );
$assert( str_contains( $hermesGateway, 'approval_status' ), 'Hermes gateway must track approval state for actions.' );
$assert( str_contains( $hermesGateway, 'requires approval before execution' ), 'Hermes gateway must deny execution before approval.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Security governance checks passed.\n" );
