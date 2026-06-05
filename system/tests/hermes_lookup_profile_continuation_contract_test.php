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

require_once $root . '/src/Metis/Hermes/HermesExecutionEngine.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$reflection = new ReflectionClass( \Metis\Hermes\HermesExecutionEngine::class );
$engine = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod( 'assertPayloadMatchesSchema' );
$method->setAccessible( true );

$schema = [
    'type' => 'object',
    'properties' => [
        'profile_request' => [
            'type' => 'object',
            'properties' => [
                'subject' => [ 'type' => 'string' ],
                'entity_hint' => [ 'type' => 'string' ],
            ],
            'required' => [ 'subject' ],
        ],
    ],
    'required' => [ 'profile_request' ],
];

try {
    $method->invoke( $engine, [ 'profile_request' => [ 'subject' => 'Brittany Dubois', 'entity_hint' => 'contact' ] ], $schema, 'payload' );
    $assert( true, 'Wrapped profile continuation payload should satisfy schema validation.' );
} catch ( Throwable $exception ) {
    $assert( false, 'Wrapped profile continuation payload should not fail schema validation: ' . $exception->getMessage() );
}

try {
    $method->invoke( $engine, [ 'subject' => 'Brittany Dubois', 'entity_hint' => 'contact' ], $schema, 'payload' );
    $assert( false, 'Unwrapped profile continuation payload should fail schema validation.' );
} catch ( Throwable $exception ) {
    $assert(
        str_contains( $exception->getMessage(), 'payload.profile_request' ),
        'Unwrapped profile continuation payload should fail on missing profile_request field.'
    );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes lookup profile continuation checks passed.\n" );
