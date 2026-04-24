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
use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;

$options = parseOptions( $argv );

try {
    $dbReady = false;
    if ( function_exists( 'metis_standalone_has_database_config' ) && metis_standalone_has_database_config() ) {
        try {
            metis_standalone_boot();
            SchemaManager::ensureSchema();
            $dbReady = true;
        } catch ( Throwable $bootError ) {
            if ( $options['apply'] ) {
                throw $bootError;
            }
            fwrite( STDERR, 'Notice: database unavailable for dry-run; continuing in fetch-only mode.' . PHP_EOL );
        }
    }

    $source = rtrim( (string) $options['source'], '/' );
    $apply = (bool) $options['apply'];
    $importPages = (bool) $options['pages'];
    $importPosts = (bool) $options['posts'];

    if ( $apply && ! $dbReady ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    echo "Source: {$source}\n";
    echo 'Mode: ' . ( $apply ? 'APPLY (writes)' : 'DRY RUN (no writes)' ) . "\n";
    echo 'Import: ' . ( $importPages ? 'pages ' : '' ) . ( $importPosts ? 'posts' : '' ) . "\n\n";

    $summary = [
        'pages' => [ 'fetched' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0 ],
        'posts' => [ 'fetched' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0 ],
        'errors' => [],
    ];

    $pageIdMap = [];

    if ( $importPages ) {
        $remotePages = fetchWpCollection(
            $source,
            '/wp-json/wp/v2/pages',
            [ 'per_page' => 100, '_fields' => 'id,parent,slug,link,title.rendered,content.rendered,modified_gmt' ]
        );

        $summary['pages']['fetched'] = count( $remotePages );
        echo 'Fetched pages: ' . $summary['pages']['fetched'] . "\n";

        foreach ( $remotePages as $row ) {
            if ( ! is_array( $row ) ) {
                $summary['pages']['skipped']++;
                continue;
            }

            $remoteId = (int) ( $row['id'] ?? 0 );
            $slug = metis_slug_clean( (string) ( $row['slug'] ?? '' ) );
            $title = decodeText( (string) ( $row['title']['rendered'] ?? '' ) );
            $html = normalizeImportedHtml( (string) ( $row['content']['rendered'] ?? '' ) );

            if ( $slug === '' || $title === '' ) {
                $summary['pages']['skipped']++;
                continue;
            }

            if ( $slug === 'calendar' || str_contains( (string) ( $row['link'] ?? '' ), '/join/calendar/' ) ) {
                if ( ! str_contains( $html, '[metis:calendar.upcoming]' ) ) {
                    $html .= "\n<p>[metis:calendar.upcoming]</p>\n";
                }
            }

            $layout = buildLayoutJson( $html, [
                'source' => 'mobilizewaco',
                'source_id' => (string) $remoteId,
                'source_url' => (string) ( $row['link'] ?? '' ),
                'imported_at' => gmdate( 'c' ),
            ] );

            $existing = $dbReady ? PageService::getBySlug( $slug ) : null;
            $payload = [
                'title' => $title,
                'slug' => $slug,
                'status' => 'draft',
                'layout_json' => $layout,
                'draft_layout_json' => $layout,
                'seo_meta_json' => metis_json_encode( [
                    'title' => $title,
                    'description' => extractDescription( $html ),
                ] ),
            ];

            try {
                if ( $existing !== null ) {
                    if ( $apply ) {
                        $ok = PageService::update( (int) $existing->id, $payload );
                        if ( ! $ok ) {
                            throw new RuntimeException( 'Page update returned false for slug ' . $slug );
                        }
                    }
                    $summary['pages']['updated']++;
                    $pageIdMap[ $remoteId ] = (int) $existing->id;
                } else {
                    if ( $apply ) {
                        $created = PageService::create( $payload );
                        if ( $created === null ) {
                            throw new RuntimeException( 'Page create returned null for slug ' . $slug );
                        }
                        $pageIdMap[ $remoteId ] = (int) $created->id;
                    }
                    $summary['pages']['created']++;
                }
            } catch ( Throwable $e ) {
                $summary['errors'][] = 'page/' . $slug . ': ' . $e->getMessage();
            }
        }

        if ( $apply && $pageIdMap !== [] ) {
            foreach ( $remotePages as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $remoteId = (int) ( $row['id'] ?? 0 );
                $remoteParent = (int) ( $row['parent'] ?? 0 );
                if ( $remoteId < 1 || $remoteParent < 1 ) {
                    continue;
                }
                $localId = $pageIdMap[ $remoteId ] ?? 0;
                $localParentId = $pageIdMap[ $remoteParent ] ?? 0;
                if ( $localId < 1 || $localParentId < 1 || $localId === $localParentId ) {
                    continue;
                }
                PageService::update( $localId, [ 'parent_id' => $localParentId ] );
            }
        }

        echo 'Pages imported (created/updated/skipped): '
            . $summary['pages']['created'] . '/'
            . $summary['pages']['updated'] . '/'
            . $summary['pages']['skipped'] . "\n\n";
    }

    if ( $importPosts ) {
        $remotePosts = fetchWpCollection(
            $source,
            '/wp-json/wp/v2/posts',
            [ 'per_page' => 100, '_fields' => 'id,slug,link,date_gmt,title.rendered,excerpt.rendered,content.rendered,modified_gmt' ]
        );

        $summary['posts']['fetched'] = count( $remotePosts );
        echo 'Fetched posts: ' . $summary['posts']['fetched'] . "\n";

        foreach ( $remotePosts as $row ) {
            if ( ! is_array( $row ) ) {
                $summary['posts']['skipped']++;
                continue;
            }

            $remoteId = (int) ( $row['id'] ?? 0 );
            $slug = metis_slug_clean( (string) ( $row['slug'] ?? '' ) );
            $title = decodeText( (string) ( $row['title']['rendered'] ?? '' ) );
            $html = normalizeImportedHtml( (string) ( $row['content']['rendered'] ?? '' ) );
            $excerptText = trim( strip_tags( decodeText( (string) ( $row['excerpt']['rendered'] ?? '' ) ) ) );

            if ( $slug === '' || $title === '' ) {
                $summary['posts']['skipped']++;
                continue;
            }

            $layout = buildLayoutJson( $html, [
                'source' => 'mobilizewaco',
                'source_id' => (string) $remoteId,
                'source_url' => (string) ( $row['link'] ?? '' ),
                'imported_at' => gmdate( 'c' ),
            ] );

            $existing = $dbReady ? PostService::getBySlug( $slug ) : null;
            $publishDate = normalizeDateTime( (string) ( $row['date_gmt'] ?? '' ) );
            $payload = [
                'title' => $title,
                'slug' => $slug,
                'status' => 'draft',
                'excerpt' => $excerptText,
                'content_json' => $layout,
                'draft_content_json' => $layout,
                'publish_date' => $publishDate,
                'seo_meta_json' => metis_json_encode( [
                    'title' => $title,
                    'description' => $excerptText !== '' ? $excerptText : extractDescription( $html ),
                ] ),
            ];

            try {
                if ( $existing !== null ) {
                    if ( $apply ) {
                        $ok = PostService::update( (int) $existing->id, $payload );
                        if ( ! $ok ) {
                            throw new RuntimeException( 'Post update returned false for slug ' . $slug );
                        }
                    }
                    $summary['posts']['updated']++;
                } else {
                    if ( $apply ) {
                        $created = PostService::create( $payload );
                        if ( $created === null ) {
                            throw new RuntimeException( 'Post create returned null for slug ' . $slug );
                        }
                    }
                    $summary['posts']['created']++;
                }
            } catch ( Throwable $e ) {
                $summary['errors'][] = 'post/' . $slug . ': ' . $e->getMessage();
            }
        }

        echo 'Posts imported (created/updated/skipped): '
            . $summary['posts']['created'] . '/'
            . $summary['posts']['updated'] . '/'
            . $summary['posts']['skipped'] . "\n\n";
    }

    if ( $summary['errors'] !== [] ) {
        echo "Errors:\n";
        foreach ( $summary['errors'] as $err ) {
            echo '- ' . $err . "\n";
        }
        echo "\n";
    }

    echo "Done.\n";
    exit( 0 );
} catch ( Throwable $e ) {
    fwrite( STDERR, 'import_mobilizewaco_content failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}

function parseOptions( array $argv ): array {
    $opts = [
        'source' => 'https://mobilizewaco.org',
        'apply' => false,
        'pages' => true,
        'posts' => true,
    ];

    foreach ( array_slice( $argv, 1 ) as $arg ) {
        $arg = (string) $arg;
        if ( $arg === '--apply' ) {
            $opts['apply'] = true;
            continue;
        }
        if ( $arg === '--pages-only' ) {
            $opts['pages'] = true;
            $opts['posts'] = false;
            continue;
        }
        if ( $arg === '--posts-only' ) {
            $opts['pages'] = false;
            $opts['posts'] = true;
            continue;
        }
        if ( str_starts_with( $arg, '--source=' ) ) {
            $opts['source'] = rtrim( substr( $arg, 9 ), '/' );
            continue;
        }
    }

    return $opts;
}

/**
 * @return array<int,mixed>
 */
function fetchWpCollection( string $source, string $path, array $query ): array {
    $items = [];
    $page = 1;

    while ( true ) {
        $query['page'] = $page;
        $url = $source . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        [ $status, $headers, $body ] = httpGet( $url );

        if ( $status < 200 || $status >= 300 ) {
            throw new RuntimeException( 'HTTP ' . $status . ' for ' . $url );
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new RuntimeException( 'Invalid JSON payload for ' . $url );
        }

        $items = array_merge( $items, $decoded );

        $totalPages = 1;
        foreach ( $headers as $headerLine ) {
            if ( stripos( $headerLine, 'X-WP-TotalPages:' ) === 0 ) {
                $totalPages = max( 1, (int) trim( substr( $headerLine, strlen( 'X-WP-TotalPages:' ) ) ) );
                break;
            }
        }

        if ( $page >= $totalPages ) {
            break;
        }
        $page++;
    }

    return $items;
}

/**
 * @return array{0:int,1:array<int,string>,2:string}
 */
function httpGet( string $url ): array {
    if ( function_exists( 'curl_init' ) ) {
        $ch = curl_init( $url );
        if ( $ch !== false ) {
            $headers = [];
            curl_setopt_array( $ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'Metis-MobileSync/1.0',
                CURLOPT_HTTPHEADER => [ 'Accept: application/json,text/html,application/xml,*/*' ],
                CURLOPT_HEADERFUNCTION => static function ( $curl, string $headerLine ) use ( &$headers ): int {
                    $trimmed = trim( $headerLine );
                    if ( $trimmed !== '' ) {
                        $headers[] = $trimmed;
                    }
                    return strlen( $headerLine );
                },
            ] );
            $body = curl_exec( $ch );
            $status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            return [ $status, $headers, is_string( $body ) ? $body : '' ];
        }
    }

    $context = stream_context_create( [
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => [
                'User-Agent: Metis-MobileSync/1.0',
                'Accept: application/json,text/html,application/xml,*/*',
            ],
        ],
    ] );

    $body = @file_get_contents( $url, false, $context );
    if ( function_exists( 'http_get_last_response_headers' ) ) {
        $headers = (array) http_get_last_response_headers();
    } else {
        $headers = [];
    }

    $status = 0;
    if ( isset( $headers[0] ) && preg_match( '#\s(\d{3})\s#', (string) $headers[0], $m ) ) {
        $status = (int) $m[1];
    }

    return [ $status, $headers, is_string( $body ) ? $body : '' ];
}

function decodeText( string $raw ): string {
    $text = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = trim( $text );
    return preg_replace( '/\s+/u', ' ', $text ) ?? $text;
}

function normalizeImportedHtml( string $html ): string {
    $content = trim( $html );
    if ( $content === '' ) {
        return '<p></p>';
    }

    $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    $patterns = [
        '#<script\b[^>]*>.*?</script>#is',
        '#<style\b[^>]*>.*?</style>#is',
        '#<noscript\b[^>]*>.*?</noscript>#is',
        '#<object\b[^>]*>.*?</object>#is',
        '#<embed\b[^>]*>.*?</embed>#is',
        '#<!--.*?-->#s',
    ];

    foreach ( $patterns as $pattern ) {
        $next = preg_replace( $pattern, '', $content );
        if ( is_string( $next ) ) {
            $content = $next;
        }
    }

    if ( function_exists( 'metis_runtime_kses_post' ) ) {
        $content = (string) metis_runtime_kses_post( $content );
    }

    $content = trim( $content );
    return $content !== '' ? $content : '<p></p>';
}

function extractDescription( string $html ): string {
    $text = trim( strip_tags( $html ) );
    if ( $text === '' ) {
        return '';
    }
    $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
    if ( strlen( $text ) > 220 ) {
        return trim( substr( $text, 0, 217 ) ) . '...';
    }
    return $text;
}

function normalizeDateTime( string $raw ): ?string {
    $raw = trim( $raw );
    if ( $raw === '' ) {
        return null;
    }
    $ts = strtotime( $raw );
    if ( ! is_int( $ts ) || $ts <= 0 ) {
        return null;
    }
    return gmdate( 'Y-m-d H:i:s', $ts );
}

function buildLayoutJson( string $html, array $meta = [] ): string {
    $payload = [
        'version' => 2,
        'editor_meta' => array_merge(
            [
                'builder' => 'simple',
                'simple_html' => $html,
            ],
            $meta
        ),
        'sections' => [
            [
                'id' => 'section_main',
                'columns' => [
                    [
                        'id' => 'section_main_col_0',
                        'width' => 1,
                        'modules' => [
                            [
                                'id' => 'module_main_text',
                                'type' => 'text',
                                'data' => [
                                    'tag' => 'div',
                                    'content' => $html,
                                ],
                                'style' => [],
                            ],
                        ],
                        'settings' => [],
                    ],
                ],
                'sections' => [],
                'settings' => [],
            ],
        ],
    ];

    $encoded = metis_json_encode( $payload );
    return is_string( $encoded ) ? $encoded : json_encode( $payload, JSON_UNESCAPED_SLASHES );
}
