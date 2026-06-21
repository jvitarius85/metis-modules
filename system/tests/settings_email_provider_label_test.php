<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

$core_service_path = static function ( string $relative ) use ( $root ): string {
    $normalized = ltrim( $relative, '/\\' );

    foreach ( [ 'help', 'people', 'portal', 'profile', 'settings' ] as $slug ) {
        $prefix = 'modules/' . $slug . '/';
        if ( str_starts_with( $normalized, $prefix ) ) {
            return $root . '/src/Metis/Core/BuiltInServices/' . $slug . '/' . substr( $normalized, strlen( $prefix ) );
        }
    }

    return $root . '/' . $normalized;
};

define( 'METIS_ROOT', dirname( $root ) . '/' );

require_once $core_service_path( 'modules/settings/views/_settings_bootstrap.php' );

$failures = [];
$assertSame = static function ( string $expected, string $actual, string $message ) use ( &$failures ): void {
    if ( $expected !== $actual ) {
        $failures[] = $message . ' Expected [' . $expected . '] but received [' . $actual . '].';
    }
};

$assertSame( 'Google Workspace', metis_settings_email_provider_label( 'newslettergmail' ), 'Legacy provider keys should normalize to Google Workspace.' );
$assertSame( 'Google Workspace', metis_settings_email_provider_label( 'newsletter.gmail' ), 'Dotted provider keys should normalize to Google Workspace.' );
$assertSame( 'Google Workspace', metis_settings_email_provider_label( 'gmail' ), 'Generic gmail provider keys should normalize to Google Workspace.' );
$assertSame( 'Custom Mailer', metis_settings_email_provider_label( 'Custom Mailer' ), 'Unknown provider keys should remain unchanged.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Settings email provider label checks passed.\n" );
