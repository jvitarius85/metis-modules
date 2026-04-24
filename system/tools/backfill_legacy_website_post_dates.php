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

$apply = in_array( '--apply', $argv, true );

$dates = [
    'april-18-2025-dr-greg-garrett' => '2025-04-18 11:30:00',
    'march-21-2025-malcolm-foster' => '2025-03-21 11:30:00',
    'february-21-2025-bruce-huff' => '2025-02-21 11:30:00',
    'january-17-2025-meg-wallace-donna-dill' => '2025-01-17 11:30:00',
    'december-20-2024-barbara-bridgewater' => '2024-12-20 11:30:00',
    'november-15-2024-jimmy-moreno' => '2024-11-15 21:07:00',
    'mobilizing-behind-the-scenes' => '2024-11-01 12:46:00',
    'october-18-2024-jared-goldsmith' => '2024-10-18 11:30:00',
    'september-20-2024-kyle-kiper' => '2024-09-20 11:30:00',
    'august-16-2024-curtis-graves' => '2024-08-16 11:30:00',
    'july-19-2024-ross-burns' => '2024-07-19 11:30:00',
    'june-21-2024-josh-carney' => '2024-06-21 11:30:00',
    'may-17-2024-cleotha-kelley' => '2024-05-17 11:30:00',
    'promote-parking-access-with-parking-mobility' => '2024-05-02 11:50:00',
    'april-19-2024-mack-marsh' => '2024-04-19 11:30:00',
    'march-15-2024-suzette-may' => '2024-03-15 11:30:00',
    'february-16-2024-elaine-white' => '2024-02-16 11:30:00',
    'donna-dill-on-living-it-the-mobilize-waco-radio-show' => '2024-01-19 11:30:00',
    'the-great-2023-media-flurry' => '2023-12-28 17:57:00',
    'active-stakeholders-in-25th-street-renewal' => '2023-12-24 14:41:00',
    'a-victory-for-the-action-team' => '2023-12-19 21:30:00',
    'living-it-coming-to-kwbu-in-january-2024' => '2023-12-02 10:45:00',
    'crafting-a-vision-mobilize-waco-and-the-waco-metropolitan-planning-organization' => '2023-11-11 14:07:00',
    'the-mobilize-waco-story-on-kwbus-central-texas-leadership-series' => '2023-11-09 22:00:00',
    'texas-partners-in-policymaking-2023-cohort' => '2023-10-20 21:02:00',
    'under-i-35-mobilize-waco-completes-its-first-infrastructure-assessment' => '2023-10-03 14:58:00',
];

try {
    if ( ! function_exists( 'metis_standalone_has_database_config' ) || ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();
    SchemaManager::ensureSchema();

    $summary = [ 'matched' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0 ];

    foreach ( $dates as $slug => $publish_date ) {
        $post = PostService::getBySlug( $slug );
        if ( ! $post ) {
            $summary['missing']++;
            echo "[missing] {$slug}\n";
            continue;
        }
        $summary['matched']++;
        $current = trim( (string) ( $post->publish_date ?? '' ) );
        if ( $current === $publish_date ) {
            $summary['unchanged']++;
            echo "[unchanged] {$slug} => {$publish_date}\n";
            continue;
        }
        echo "[update] {$slug}: {$current} => {$publish_date}\n";
        if ( ! $apply ) {
            continue;
        }
        $ok = PostService::update( (int) $post->id, [ 'publish_date' => $publish_date ] );
        if ( ! $ok ) {
            $summary['failed']++;
            fwrite( STDERR, "Failed to update {$slug}\n" );
            continue;
        }
        $summary['updated']++;
    }

    echo 'Mode: ' . ( $apply ? 'APPLY' : 'DRY RUN' ) . PHP_EOL;
    foreach ( $summary as $key => $value ) {
        echo ucfirst( $key ) . ': ' . $value . PHP_EOL;
    }
} catch ( Throwable $e ) {
    fwrite( STDERR, 'backfill_legacy_website_post_dates failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}
