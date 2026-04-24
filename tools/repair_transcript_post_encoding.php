<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', $root . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( $root . '/' );
metis_core_bootstrap( 'standalone_bootstrap' );

use Metis\Modules\Website\SchemaManager;
use Metis\Modules\Website\Services\PostService;

$apply = in_array( '--apply', $argv, true );

try {
    if ( ! function_exists( 'metis_standalone_has_database_config' ) || ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();
    SchemaManager::ensureSchema();

    $posts = PostService::getAll( [ 'status' => 'published', 'fetch_all' => true ] );
    $summary = [
        'scanned' => 0,
        'candidates' => 0,
        'repaired' => 0,
        'failed' => 0,
    ];

    foreach ( $posts as $post ) {
        if ( ! $post instanceof \Metis\Modules\Website\Entities\Post ) {
            continue;
        }
        $summary['scanned']++;

        $published = (string) ( $post->published_content_json ?? '' );
        if ( $published === '' || stripos( $published, 'wp-block-table' ) === false ) {
            continue;
        }

        $payload = [];
        $changed = false;

        foreach ( [ 'content_json', 'draft_content_json', 'published_content_json' ] as $field ) {
            $value = $post->{$field};
            if ( ! is_string( $value ) || trim( $value ) === '' ) {
                continue;
            }
            $fixed = repair_layout_json( $value );
            if ( $fixed !== $value ) {
                $payload[ $field ] = $fixed;
                $changed = true;
            }
        }

        $excerpt = is_string( $post->excerpt ) ? $post->excerpt : '';
        $fixed_excerpt = repair_plain_text( $excerpt );
        if ( $fixed_excerpt !== $excerpt ) {
            $payload['excerpt'] = $fixed_excerpt;
            $changed = true;
        }

        $seo_raw = is_string( $post->seo_meta_json ) ? $post->seo_meta_json : '';
        $fixed_seo = repair_seo_meta_json( $seo_raw );
        if ( $fixed_seo !== $seo_raw ) {
            $payload['seo_meta_json'] = $fixed_seo;
            $changed = true;
        }

        if ( property_exists( $post, 'content_format' ) && (string) ( $post->content_format ?? 'standard' ) !== 'transcript' ) {
            $payload['content_format'] = 'transcript';
            $changed = true;
        }

        if ( ! $changed ) {
            continue;
        }

        $summary['candidates']++;
        echo sprintf( "[%s] %s\n", (string) ( $post->post_code ?? $post->id ?? 'unknown' ), (string) $post->slug );

        if ( ! $apply ) {
            continue;
        }

        if ( ! PostService::update( (int) $post->id, $payload ) ) {
            $summary['failed']++;
            fwrite( STDERR, 'Failed to update post ' . (string) $post->slug . PHP_EOL );
            continue;
        }

        $summary['repaired']++;
    }

    echo 'Mode: ' . ( $apply ? 'APPLY' : 'DRY RUN' ) . PHP_EOL;
    echo 'Scanned: ' . $summary['scanned'] . PHP_EOL;
    echo 'Candidates: ' . $summary['candidates'] . PHP_EOL;
    echo 'Repaired: ' . $summary['repaired'] . PHP_EOL;
    echo 'Failed: ' . $summary['failed'] . PHP_EOL;
} catch ( Throwable $e ) {
    fwrite( STDERR, 'repair_transcript_post_encoding failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}

function repair_layout_json( string $raw ): string {
    $decoded = json_decode( $raw, true );
    if ( ! is_array( $decoded ) ) {
        return $raw;
    }

    $repaired = repair_layout_value( $decoded, '' );
    $encoded = function_exists( 'metis_json_encode' )
        ? (string) metis_json_encode( $repaired )
        : json_encode( $repaired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    return is_string( $encoded ) && $encoded !== '' ? $encoded : $raw;
}

function repair_layout_value( mixed $value, string $key ): mixed {
    if ( is_array( $value ) ) {
        $out = [];
        foreach ( $value as $child_key => $child_value ) {
            $out[ $child_key ] = repair_layout_value( $child_value, is_string( $child_key ) ? $child_key : '' );
        }
        return $out;
    }

    if ( ! is_string( $value ) || $value === '' ) {
        return $value;
    }

    if ( in_array( $key, [ 'body', 'content', 'content_html', 'html' ], true ) && str_contains( $value, '<' ) ) {
        return repair_html_fragment( $value );
    }

    if ( in_array( $key, [ 'title', 'description', 'excerpt', 'header', 'subtext', 'headline' ], true ) ) {
        return repair_plain_text( $value );
    }

    return $value;
}

function repair_seo_meta_json( string $raw ): string {
    if ( trim( $raw ) === '' ) {
        return $raw;
    }
    $decoded = json_decode( $raw, true );
    if ( ! is_array( $decoded ) ) {
        return $raw;
    }

    foreach ( [ 'title', 'description', 'og_title', 'og_description' ] as $field ) {
        if ( isset( $decoded[ $field ] ) && is_string( $decoded[ $field ] ) ) {
            $decoded[ $field ] = repair_plain_text( $decoded[ $field ] );
        }
    }

    $encoded = function_exists( 'metis_json_encode' )
        ? (string) metis_json_encode( $decoded )
        : json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    return is_string( $encoded ) && $encoded !== '' ? $encoded : $raw;
}

function repair_html_fragment( string $html ): string {
    if ( trim( $html ) === '' || ! class_exists( DOMDocument::class ) ) {
        return $html;
    }

    $doc = new DOMDocument( '1.0', 'UTF-8' );
    $wrapped = '<div id="metis-repair-root">' . $html . '</div>';
    $previous = libxml_use_internal_errors( true );
    $loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );
    if ( ! $loaded ) {
        return $html;
    }

    $xpath = new DOMXPath( $doc );
    foreach ( $xpath->query( '//text()' ) as $node ) {
        if ( $node instanceof DOMText ) {
            $node->nodeValue = repair_plain_text( (string) $node->nodeValue );
        }
    }

    $root = $doc->getElementById( 'metis-repair-root' );
    if ( ! $root instanceof DOMElement ) {
        return $html;
    }

    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= (string) $doc->saveHTML( $child );
    }
    return $out !== '' ? $out : $html;
}

function repair_plain_text( string $text ): string {
    $current = replace_common_mojibake_sequences( $text );
    $current_score = mojibake_score( $current );
    if ( $current_score < 1 ) {
        return $text;
    }

    for ( $i = 0; $i < 4; $i++ ) {
        $candidate = function_exists( 'mb_convert_encoding' )
            ? @mb_convert_encoding( $current, 'ISO-8859-1', 'UTF-8' )
            : @iconv( 'UTF-8', 'ISO-8859-1//IGNORE', $current );
        if ( ! is_string( $candidate ) || $candidate === '' || $candidate === $current ) {
            break;
        }

        $candidate_score = mojibake_score( $candidate );
        if ( $candidate_score > $current_score ) {
            break;
        }

        $current = $candidate;
        $current_score = $candidate_score;

        if ( $current_score === 0 ) {
            break;
        }
    }

    return str_replace( "\xC2\xA0", ' ', $current );
}

function replace_common_mojibake_sequences( string $text ): string {
    return str_replace(
        [
            'Ã¢ÂÂ',
            'Ã¢ÂÂ',
            'Ã¢ÂÂ',
            'Ã¢ÂÂ',
            'Ã¢ÂÂ¦',
            'Ã¢ÂÂ',
            'Ã¢ÂÂ',
            'ÃÂ ',
            'Â ',
        ],
        [
            '’',
            '‘',
            '“',
            '”',
            '…',
            '–',
            '—',
            ' ',
            ' ',
        ],
        $text
    );
}

function mojibake_score( string $text ): int {
    $markers = [ 'Ã', 'Â', 'â', '�' ];
    $score = 0;
    foreach ( $markers as $marker ) {
        $score += substr_count( $text, $marker );
    }
    return $score;
}
