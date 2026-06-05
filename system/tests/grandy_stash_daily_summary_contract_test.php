<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Metis/Modules/GrandyStash/GrandyStashDailySummary.php' );
if ( ! is_string( $source ) || $source === '' ) {
    fwrite( STDERR, "Unable to read Grandy's Stash daily summary source.\n" );
    exit( 1 );
}

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert( str_contains( $source, "'module' => 'grandys_stash'" ), 'Daily summary should identify itself as the grandys_stash module for email tracking.' );
$assert( str_contains( $source, "'from_name' => 'Metis'" ), 'Daily summary should continue setting a stable from_name.' );
$assert( ! str_contains( $source, "'from_email'" ), 'Daily summary should not force a from_email that overrides the Workspace sender identity.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Grandy's Stash daily summary contract checks passed.\n" );
