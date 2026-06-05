<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

if ( ! function_exists( 'metis_key_clean' ) ) {
    function metis_key_clean( string $value ): string {
        $clean = strtolower( trim( preg_replace( '/[^a-z0-9_]+/i', '_', $value ) ?? '' ) );
        return trim( preg_replace( '/_+/', '_', $clean ) ?? '', '_' );
    }
}

require_once $root . '/src/Metis/Services/DatabaseService.php';
require_once $root . '/src/Metis/Core/Cache/RuntimeCache.php';
require_once $root . '/src/Metis/Core/Cache/FileCache.php';
require_once $root . '/src/Metis/Core/Cache/CacheService.php';
require_once $root . '/src/Metis/Core/Services/FileService.php';
require_once $root . '/src/Metis/Core/Services/EntityResolutionResult.php';
require_once $root . '/src/Metis/Core/Services/EntityResolverService.php';
require_once $root . '/src/Metis/Services/HermesDirectoryService.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$resolver = new \Metis\Core\Services\EntityResolverService( null );
$resolver->registerEntityType( 'user', static function (): \Metis\Core\Services\EntityResolutionResult {
    return new \Metis\Core\Services\EntityResolutionResult(
        'not_found',
        'none',
        'user',
        [],
        [],
        'No person matched.'
    );
} );
$resolver->registerEntityType( 'contact', static function (): \Metis\Core\Services\EntityResolutionResult {
    return new \Metis\Core\Services\EntityResolutionResult(
        'ambiguous',
        'low',
        'contact',
        [
            'id' => 10,
            'name' => 'Brittany Attwood',
            'email' => 'brittany@example.org',
            'metadata' => [ 'record' => [ 'email' => 'brittany@example.org' ] ],
        ],
        [
            [
                'id' => 10,
                'name' => 'Brittany Attwood',
                'email' => 'brittany@example.org',
                'metadata' => [ 'record' => [ 'email' => 'brittany@example.org' ] ],
            ],
            [
                'id' => 11,
                'name' => 'Brittany Wallace',
                'email' => 'brittany.wallace@example.org',
                'metadata' => [ 'record' => [ 'email' => 'brittany.wallace@example.org' ] ],
            ],
        ],
        'Multiple contacts matched.'
    );
} );
$resolver->registerEntityType( 'donor', static function (): \Metis\Core\Services\EntityResolutionResult {
    return new \Metis\Core\Services\EntityResolutionResult(
        'resolved',
        'high',
        'donor',
        [
            'id' => 22,
            'name' => 'Brittany Donor',
            'email' => 'donor@example.org',
            'metadata' => [ 'record' => [ 'email' => 'donor@example.org' ] ],
        ],
        [],
        'Resolved Brittany Donor.'
    );
} );

$service = new \Metis\Services\HermesDirectoryService( new \Metis\Services\DatabaseService( new stdClass() ), $resolver );
$result = $service->lookupProfile( [ 'subject' => 'Brittany', 'entity_hint' => 'contact' ] );

$assert( (string) ( $result['status'] ?? '' ) === 'disambiguation_required', 'Ambiguous Brittany lookup should require disambiguation.' );
$assert( count( (array) ( $result['candidates'] ?? [] ) ) === 2, 'Disambiguation should include the matching Brittany contact candidates.' );
$assert( str_contains( (string) ( $result['message'] ?? '' ), 'Which person would you like?' ), 'Disambiguation prompt should ask the user to choose a person.' );

$resolverMerged = new \Metis\Core\Services\EntityResolverService( null );
$resolverMerged->registerEntityType( 'user', static function (): \Metis\Core\Services\EntityResolutionResult {
    return new \Metis\Core\Services\EntityResolutionResult( 'not_found', 'none', 'user', [], [], 'No person matched.' );
} );
$resolverMerged->registerEntityType( 'contact', static function (): \Metis\Core\Services\EntityResolutionResult {
    return new \Metis\Core\Services\EntityResolutionResult(
        'ambiguous',
        'low',
        'contact',
        [],
        [
            [
                'id' => 31,
                'name' => 'Brittany Attwood',
                'email' => 'brittany@example.org',
                'metadata' => [ 'record' => [ 'email' => 'brittany@example.org' ] ],
            ],
            [
                'id' => 32,
                'name' => 'Brittany Dubois',
                'email' => 'brittany.dubois@example.org',
                'metadata' => [ 'record' => [ 'email' => 'brittany.dubois@example.org' ] ],
            ],
        ],
        'Multiple contacts matched.'
    );
} );
$resolverMerged->registerEntityType( 'donor', static function (): \Metis\Core\Services\EntityResolutionResult {
    return new \Metis\Core\Services\EntityResolutionResult(
        'resolved',
        'high',
        'donor',
        [
            'id' => 41,
            'name' => 'Brittany Attwood',
            'email' => 'brittany@example.org',
            'metadata' => [ 'record' => [ 'email' => 'brittany@example.org' ] ],
        ],
        [],
        'Resolved Brittany Attwood.'
    );
} );

$mergedService = new \Metis\Services\HermesDirectoryService( new \Metis\Services\DatabaseService( new stdClass() ), $resolverMerged );
$mergedResult = $mergedService->lookupProfile( [ 'subject' => 'Brittany', 'entity_hint' => 'auto' ] );

$assert( (string) ( $mergedResult['status'] ?? '' ) === 'disambiguation_required', 'Merged Brittany lookup should still require disambiguation.' );
$assert( count( (array) ( $mergedResult['candidates'] ?? [] ) ) === 2, 'Exact same Brittany contact/donor matches should collapse to one candidate.' );
$assert( (string) ( $mergedResult['candidates'][0]['entity_type'] ?? '' ) === 'contact/donor', 'Merged Brittany candidate should preserve both contact and donor types.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes directory disambiguation checks passed.\n" );
