<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Core/Version.php';
require_once $root . '/src/Metis/Release/ReleaseManager.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$manager = new \Metis\Release\ReleaseManager();
$reflection = new ReflectionClass( $manager );
$method = $reflection->getMethod( 'isArchiveReleaseAlreadyInstalled' );

$assert(
    (bool) $method->invoke( $manager, 'v26.5.10.1', [
        'installed_tag' => 'v26.5.10.1',
        'installed_version' => '26.5.10.1',
    ] ) === true,
    'Archive release path should short-circuit when the installed tag already matches the requested tag.'
);

$assert(
    (bool) $method->invoke( $manager, 'v26.5.10.1', [
        'installed_tag' => '',
        'installed_version' => '26.5.10.1',
    ] ) === true,
    'Archive release path should short-circuit when the installed version already matches the requested tag version.'
);

$assert(
    (bool) $method->invoke( $manager, 'v26.5.10.1', [
        'installed_tag' => 'v26.5.10',
        'installed_version' => '26.5.10',
    ] ) === false,
    'Archive release path should not short-circuit when the installed release differs from the requested tag.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Release manager archive-current checks passed.\n" );
