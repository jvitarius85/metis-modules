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
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( 'standalone_bootstrap' );

use Metis\Modules\Website\SchemaManager;
use Metis\Modules\Website\Services\PostService;
use Metis\Modules\Website\Services\StructuredWebsiteBuilderService;

$options = parse_options( $argv );
$apply = (bool) $options['apply'];
$limit = max( 0, (int) $options['limit'] );

try {
    if ( ! function_exists( 'metis_standalone_has_database_config' ) || ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();
    SchemaManager::ensureSchema();

    $posts = PostService::getAll( [
        'fetch_all' => true,
    ] );

    $summary = [
        'scanned' => 0,
        'legacy_candidates' => 0,
        'migrated' => 0,
        'unchanged' => 0,
        'failed' => 0,
    ];

    foreach ( $posts as $post ) {
        if ( ! $post instanceof \Metis\Modules\Website\Entities\Post ) {
            continue;
        }

        $summary['scanned']++;
        $changed = false;
        $payload = [];
        $fields = [
            'content_json' => $post->content_json,
            'draft_content_json' => $post->draft_content_json,
            'published_content_json' => $post->published_content_json,
        ];

        foreach ( $fields as $field => $raw_layout ) {
            if ( ! StructuredWebsiteBuilderService::isLegacySimpleLayout( $raw_layout ) ) {
                continue;
            }

            $summary['legacy_candidates']++;
            $migrated = StructuredWebsiteBuilderService::migrateLegacySimpleLayout( $raw_layout, [
                'is_post' => true,
                'page_type' => 'post',
                'template_key' => (string) ( $post->template_key ?? '' ),
            ] );
            if ( ! is_string( $migrated ) || trim( $migrated ) === '' ) {
                continue;
            }
            if ( $migrated === $raw_layout ) {
                continue;
            }

            $payload[ $field ] = $migrated;
            $changed = true;
        }

        if ( ! $changed ) {
            $summary['unchanged']++;
            continue;
        }

        if ( $limit > 0 && $summary['migrated'] >= $limit ) {
            break;
        }

        echo sprintf(
            "[%s] %s\n",
            (string) ( $post->post_code ?? $post->id ?? 'unknown' ),
            (string) ( $post->slug ?? '' )
        );

        if ( ! $apply ) {
            continue;
        }

        $ok = PostService::update( (int) $post->id, $payload );
        if ( ! $ok ) {
            $summary['failed']++;
            fwrite( STDERR, 'Failed to migrate post ID ' . (int) $post->id . PHP_EOL );
            continue;
        }

        $summary['migrated']++;
    }

    echo 'Mode: ' . ( $apply ? 'APPLY' : 'DRY RUN' ) . PHP_EOL;
    echo 'Scanned: ' . $summary['scanned'] . PHP_EOL;
    echo 'Legacy candidates: ' . $summary['legacy_candidates'] . PHP_EOL;
    echo 'Migrated: ' . $summary['migrated'] . PHP_EOL;
    echo 'Unchanged: ' . $summary['unchanged'] . PHP_EOL;
    echo 'Failed: ' . $summary['failed'] . PHP_EOL;
} catch ( Throwable $e ) {
    fwrite( STDERR, 'migrate_legacy_website_posts failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}

/**
 * @return array{apply:bool,limit:int}
 */
function parse_options( array $argv ): array {
    $options = [
        'apply' => false,
        'limit' => 0,
    ];

    foreach ( array_slice( $argv, 1 ) as $arg ) {
        if ( $arg === '--apply' ) {
            $options['apply'] = true;
            continue;
        }
        if ( str_starts_with( $arg, '--limit=' ) ) {
            $options['limit'] = max( 0, (int) substr( $arg, 8 ) );
        }
    }

    return $options;
}
