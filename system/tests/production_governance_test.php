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
    return is_string( $contents ) ? $contents : '';
};

$uploads = $read( 'src/Metis/Core/Runtime/UploadsRuntime.php' );
$request = $read( 'src/Metis/Core/Runtime/RequestRuntime.php' );
$kernel = $read( 'src/Metis/Core/Kernel/Bootstrap.php' );
$processRunner = $read( 'src/Metis/Core/Services/ProcessRunner.php' );
$releaseManager = $read( 'src/Metis/Release/ReleaseManager.php' );
$communicationsAttachments = $read( 'src/Metis/Modules/CommunicationsInbound/AttachmentStorageService.php' );
$finance = $read( 'src/Metis/Modules/Finance/FinanceV2Service.php' );
$scanner = $read( 'tools/security_scan.php' );
$governance = require $root . '/config/governance.php';

$superglobalApprovals = (array) ( $governance['approved_layers']['superglobals'] ?? [] );
$assert( $superglobalApprovals === [], 'Raw request superglobals must not require production allowlist entries.' );
$requestBoundaryApprovals = (array) ( $governance['approved_layers']['request_boundary'] ?? [] );
$assert( $requestBoundaryApprovals === [ 'system/src/Metis/Core/Runtime/RequestRuntime.php' ], 'SAPI request boundary must be approved only in RequestRuntime.' );

$rawSqlApprovals = (array) ( $governance['approved_layers']['raw_sql'] ?? [] );
foreach ( $rawSqlApprovals as $approvedPath ) {
    $approvedPath = (string) $approvedPath;
    $assert(
        str_starts_with( $approvedPath, 'system/src/Metis/Core/' )
        || $approvedPath === 'system/src/Metis/Services/DatabaseService.php'
        || str_starts_with( $approvedPath, 'system/tools/' ),
        'Raw SQL approval must stay limited to core DB/runtime layers or explicit CLI tools: ' . $approvedPath
    );
}

foreach ( [ 'storage/public-media', 'storage/protected-media', 'storage/private-records' ] as $mediaRoot ) {
    $assert( in_array( $mediaRoot, (array) ( $governance['required_media_roots'] ?? [] ), true ), 'Governance config must require ' . $mediaRoot . '.' );
    $assert( is_dir( dirname( $root ) . '/' . $mediaRoot ), 'Required media root must exist: ' . $mediaRoot . '.' );
}

$assert( str_contains( $uploads, 'function metis_store_public_media' ), 'Canonical public-media helper is missing.' );
$assert( str_contains( $uploads, 'function metis_store_protected_media' ), 'Canonical protected-media helper is missing.' );
$assert( str_contains( $uploads, 'function metis_store_private_record' ), 'Canonical private-record helper is missing.' );
$assert( str_contains( $uploads, 'missing_access_ttl' ), 'Protected/private media storage must reject missing expiration metadata.' );
$assert( str_contains( $uploads, 'metis_media_audit_storage_event' ), 'Media storage must be auditable.' );
$assert( str_contains( $kernel, "private, no-store, max-age=0" ), 'Protected/private media responses must not be publicly cacheable.' );
$assert( str_contains( $communicationsAttachments, 'metis_store_protected_media' ), 'Inbound communications attachments must use protected-media.' );
$assert( str_contains( $finance, "'storage_class' => 'private'" ), 'Finance reconciliation statements must use private-records.' );

foreach ( [ 'metis_request_id', 'metis_request_object_code', 'metis_request_enum', 'metis_request_json', 'metis_request_date', 'metis_request_bool', 'metis_request_file' ] as $helper ) {
    $assert( str_contains( $request, 'function ' . $helper ), 'Request helper missing: ' . $helper . '.' );
}

foreach ( [ 'security_context', 'audit_context', 'permission_context' ] as $contextKey ) {
    $assert( str_contains( $processRunner, $contextKey ), 'ProcessRunner must require ' . $contextKey . '.' );
    $assert( str_contains( $releaseManager, $contextKey ), 'Release process execution must pass ' . $contextKey . '.' );
}
$assert( str_contains( $processRunner, 'contextAllowsExecution' ), 'ProcessRunner must validate authority before execution.' );
$assert( str_contains( $processRunner, 'redactContext' ), 'ProcessRunner audit context must redact sensitive values.' );

foreach ( [ 'sensitive-media-storage', 'process-context', 'route-middleware-governance', 'ajax-security-contracts', 'hermes-governance' ] as $scanRule ) {
    $assert( str_contains( $scanner, $scanRule ), 'Security scanner missing production governance rule: ' . $scanRule . '.' );
}
foreach ( [ 'request-boundary', 'native-db-access', 'serialization-boundary' ] as $scanRule ) {
    $assert( str_contains( $scanner, $scanRule ), 'Security scanner missing hardening rule: ' . $scanRule . '.' );
}

$deploymentDocs = [
    'docs/deployment/deployment-verification-checklist.md',
    'docs/deployment/web-server-deny-rules.md',
    'docs/governance/production-governance.md',
    'docs/security/media-isolation.md',
];
foreach ( $deploymentDocs as $doc ) {
    $assert( is_file( $root . '/' . $doc ), 'Production documentation missing: ' . $doc . '.' );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Production governance checks passed.\n" );
