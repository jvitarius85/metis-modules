<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__, 2 );

require_once $root . '/system/src/Metis/Core/Version.php';

function metis_release_manifest_usage(): never {
    $script = 'php system/tools/release_manifest.php';
    $lines = [
        'Metis release manifest generator',
        '',
        'Usage:',
        '  ' . $script . ' add [version-or-tag] [--owner=jvitarius85] [--repo=metis] [--channel=stable] [--php=8.1] [--sha256=<hash>] [--notes-url=<url>]',
        '',
        'Examples:',
        '  ' . $script . ' add',
        '  ' . $script . ' add v1.9.4.1',
    ];

    fwrite( STDERR, implode( PHP_EOL, $lines ) . PHP_EOL );
    exit( 1 );
}

function metis_release_manifest_option( array $argv, string $name, string $default = '' ): string {
    $prefix = '--' . $name . '=';
    foreach ( $argv as $arg ) {
        if ( str_starts_with( (string) $arg, $prefix ) ) {
            return trim( substr( (string) $arg, strlen( $prefix ) ) );
        }
    }

    return $default;
}

function metis_release_manifest_tag( string $value ): string {
    $value = trim( $value );
    if ( $value === '' ) {
        $value = \Metis\Core\Version::current();
    }

    $value = preg_replace( '/\s+/', '', $value ) ?? '';
    if ( $value === '' ) {
        throw new RuntimeException( 'A release version or tag is required.' );
    }

    return str_starts_with( strtolower( $value ), 'v' ) ? $value : 'v' . $value;
}

function metis_release_manifest_version( string $tag ): string {
    $version = preg_replace( '/^v/i', '', trim( $tag ) ) ?? '';
    if ( preg_match( '/^\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/', $version ) !== 1 ) {
        throw new RuntimeException( 'Release tag must use semantic version format, such as v1.9.4 or v1.9.4.1.' );
    }

    return $version;
}

function metis_release_manifest_read( string $path ): array {
    if ( ! is_file( $path ) ) {
        return [];
    }

    $raw = file_get_contents( $path );
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return [];
    }

    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function metis_release_manifest_write( string $path, array $manifest ): void {
    $dir = dirname( $path );
    if ( ! is_dir( $dir ) && ! mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
        throw new RuntimeException( 'Could not create manifest directory.' );
    }

    $json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $json ) || file_put_contents( $path, $json . PHP_EOL, LOCK_EX ) === false ) {
        throw new RuntimeException( 'Could not write release manifest.' );
    }
}

$command = (string) ( $argv[1] ?? '' );
if ( $command === '' || in_array( $command, [ '-h', '--help', 'help' ], true ) ) {
    metis_release_manifest_usage();
}

if ( $command !== 'add' ) {
    fwrite( STDERR, 'Unknown command: ' . $command . PHP_EOL );
    metis_release_manifest_usage();
}

try {
    $tag = metis_release_manifest_tag( (string) ( $argv[2] ?? '' ) );
    $version = metis_release_manifest_version( $tag );
    $owner = metis_release_manifest_option( $argv, 'owner', 'jvitarius85' );
    $repo = metis_release_manifest_option( $argv, 'repo', 'metis' );
    $channel = metis_release_manifest_option( $argv, 'channel', 'stable' );
    $minimumPhp = metis_release_manifest_option( $argv, 'php', '8.1' );
    $sha256 = strtolower( metis_release_manifest_option( $argv, 'sha256', '' ) );
    if ( $sha256 !== '' && preg_match( '/^[a-f0-9]{64}$/', $sha256 ) !== 1 ) {
        throw new RuntimeException( 'The --sha256 value must be a 64-character hex SHA-256 hash.' );
    }

    $notesUrl = metis_release_manifest_option(
        $argv,
        'notes-url',
        sprintf( 'https://github.com/%s/%s/releases/tag/%s', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $tag ) )
    );

    $path = $root . '/meta/releases.json';
    $manifest = metis_release_manifest_read( $path );
    $releases = is_array( $manifest['releases'] ?? null ) ? $manifest['releases'] : [];
    $publishedAt = gmdate( 'c' );
    $entry = [
        'tag' => $tag,
        'version' => $version,
        'published_at' => $publishedAt,
        'zip_url' => sprintf( 'https://codeload.github.com/%s/%s/zip/refs/tags/%s', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $tag ) ),
        'sha256' => $sha256,
        'notes_url' => $notesUrl,
        'minimum_php' => $minimumPhp,
        'requires_backup' => true,
    ];

    $indexed = [];
    foreach ( $releases as $release ) {
        if ( ! is_array( $release ) ) {
            continue;
        }
        $releaseTag = trim( (string) ( $release['tag'] ?? $release['tag_name'] ?? '' ) );
        if ( $releaseTag !== '' ) {
            $indexed[ $releaseTag ] = $release;
        }
    }
    $indexed[ $tag ] = $entry;

    uasort(
        $indexed,
        static fn ( array $left, array $right ): int => version_compare( (string) ( $right['version'] ?? '0.0.0' ), (string) ( $left['version'] ?? '0.0.0' ) )
    );

    $manifest = [
        'schema' => 1,
        'channel' => $channel,
        'latest' => array_key_first( $indexed ) ?: $tag,
        'generated_at' => $publishedAt,
        'releases' => array_values( $indexed ),
    ];

    metis_release_manifest_write( $path, $manifest );

    echo json_encode(
        [
            'ok' => true,
            'path' => $path,
            'latest' => $manifest['latest'],
            'release_count' => count( $manifest['releases'] ),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch ( Throwable $throwable ) {
    fwrite( STDERR, $throwable->getMessage() . PHP_EOL );
    exit( 1 );
}
