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

$apply = in_array( '--apply', $argv, true );

try {
    if ( ! function_exists( 'metis_standalone_has_database_config' ) || ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();
    SchemaManager::ensureSchema();

    $summary = [
        'scanned' => 0,
        'candidates' => 0,
        'migrated' => 0,
        'failed' => 0,
    ];

    $posts = PostService::getAll( [ 'fetch_all' => true ] );
    foreach ( $posts as $post ) {
        if ( ! $post instanceof \Metis\Modules\Website\Entities\Post ) {
            continue;
        }
        $summary['scanned']++;

        $payload = [];
        $changed = false;
        foreach ( [ 'content_json', 'draft_content_json', 'published_content_json' ] as $field ) {
            $raw = $post->{$field};
            if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
                continue;
            }
            $migrated = migrate_post_layout_json( $raw );
            if ( $migrated !== $raw ) {
                $payload[ $field ] = $migrated;
                $changed = true;
            }
        }

        $title = is_string( $post->title ) ? $post->title : '';
        $fixed_title = repair_plain_text( $title );
        if ( $fixed_title !== $title ) {
            $payload['title'] = $fixed_title;
            $changed = true;
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

        if ( property_exists( $post, 'content_format' ) && (string) ( $post->content_format ?? 'standard' ) !== 'standard' ) {
            $payload['content_format'] = 'standard';
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

        $summary['migrated']++;
    }

    echo 'Mode: ' . ( $apply ? 'APPLY' : 'DRY RUN' ) . PHP_EOL;
    echo 'Scanned: ' . $summary['scanned'] . PHP_EOL;
    echo 'Candidates: ' . $summary['candidates'] . PHP_EOL;
    echo 'Migrated: ' . $summary['migrated'] . PHP_EOL;
    echo 'Failed: ' . $summary['failed'] . PHP_EOL;
} catch ( Throwable $e ) {
    fwrite( STDERR, 'migrate_transcript_posts_to_sections failed: ' . $e->getMessage() . PHP_EOL );
    exit( 1 );
}

function migrate_post_layout_json( string $raw ): string {
    $sections = StructuredWebsiteBuilderService::sectionsFromLayout( $raw, true );
    if ( $sections === [] ) {
        return $raw;
    }

    $migrated_sections = [];
    $changed = false;
    foreach ( $sections as $section ) {
        if ( ! is_array( $section ) ) {
            continue;
        }

        $converted = migrate_post_section( $section );
        if ( $converted['changed'] ) {
            $changed = true;
        }
        foreach ( $converted['sections'] as $next_section ) {
            if ( is_array( $next_section ) ) {
                $migrated_sections[] = $next_section;
            }
        }
    }

    if ( ! $changed ) {
        return $raw;
    }

    $normalized = StructuredWebsiteBuilderService::normalizeLayout(
        [
            'editor_meta' => [
                'structured_builder' => [
                    'page_type' => 'post',
                    'sections' => $migrated_sections,
                ],
            ],
        ],
        [
            'is_post' => true,
            'page_type' => 'post',
        ]
    );

    return (string) ( $normalized['layout_json'] ?? $raw );
}

/**
 * @param array<string,mixed> $section
 * @return array{changed:bool,sections:array<int,array<string,mixed>>}
 */
function migrate_post_section( array $section ): array {
    $type = metis_key_clean( (string) ( $section['type'] ?? '' ) );
    if ( $type === 'transcript' ) {
        $content = is_array( $section['content'] ?? null ) ? $section['content'] : [];
        $rows = normalize_transcript_rows( is_array( $content['rows'] ?? null ) ? $content['rows'] : [] );
        $source = transcript_source_from_rows( $rows );
        $existing_source = trim( (string) ( $content['source'] ?? '' ) );
        $changed = $rows !== ( is_array( $content['rows'] ?? null ) ? $content['rows'] : [] )
            || repair_plain_text( $existing_source ) !== $existing_source;

        if ( ! $changed ) {
            return [ 'changed' => false, 'sections' => [ $section ] ];
        }

        $section['content'] = [
            'source' => $source,
            'rows' => $rows,
        ];
        return [ 'changed' => true, 'sections' => [ $section ] ];
    }

    if ( $type !== 'text' ) {
        return [ 'changed' => false, 'sections' => [ $section ] ];
    }

    $content = is_array( $section['content'] ?? null ) ? $section['content'] : [];
    $body = trim( (string) ( $content['body'] ?? '' ) );
    if ( $body === '' || stripos( $body, '<table' ) === false ) {
        $section['content']['body'] = repair_html_fragment( $body );
        return [ 'changed' => false, 'sections' => [ $section ] ];
    }

    $split = split_transcript_html( $body );
    if ( $split['rows'] === [] ) {
        $section['content']['body'] = repair_html_fragment( $body );
        return [ 'changed' => false, 'sections' => [ $section ] ];
    }

    $header = isset( $section['header'] ) ? $section['header'] : null;
    $subtext = isset( $section['subtext'] ) ? $section['subtext'] : null;
    $out = [];

    $intro = trim( (string) $split['intro_html'] );
    $outro = trim( (string) $split['outro_html'] );
    if ( html_has_meaningful_content( $intro ) ) {
        $out[] = [
            'id' => (string) ( $section['id'] ?? 'section_intro' ) . '_intro',
            'type' => 'text',
            'header' => $header,
            'subtext' => $subtext,
            'content' => [ 'body' => repair_html_fragment( $intro ) ],
        ];
        $header = null;
        $subtext = null;
    }

    $out[] = [
        'id' => (string) ( $section['id'] ?? 'section_transcript' ) . '_transcript',
        'type' => 'transcript',
        'header' => $header,
        'subtext' => $subtext,
        'content' => [
            'source' => transcript_source_from_rows( $split['rows'] ),
            'rows' => $split['rows'],
        ],
    ];

    if ( html_has_meaningful_content( $outro ) ) {
        $out[] = [
            'id' => (string) ( $section['id'] ?? 'section_outro' ) . '_outro',
            'type' => 'text',
            'header' => null,
            'subtext' => null,
            'content' => [ 'body' => repair_html_fragment( $outro ) ],
        ];
    }

    return [ 'changed' => true, 'sections' => $out ];
}

/**
 * @return array{intro_html:string,outro_html:string,rows:array<int,array{type:string,speaker?:string,text:string}>}
 */
function split_transcript_html( string $html ): array {
    if ( trim( $html ) === '' || ! class_exists( DOMDocument::class ) ) {
        return [ 'intro_html' => $html, 'outro_html' => '', 'rows' => [] ];
    }

    $doc = new DOMDocument( '1.0', 'UTF-8' );
    $wrapped = '<div id="metis-transcript-migrate-root">' . $html . '</div>';
    $previous = libxml_use_internal_errors( true );
    $loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );
    if ( ! $loaded ) {
        return [ 'intro_html' => $html, 'outro_html' => '', 'rows' => [] ];
    }

    $root = $doc->getElementById( 'metis-transcript-migrate-root' );
    if ( ! $root instanceof DOMElement ) {
        return [ 'intro_html' => $html, 'outro_html' => '', 'rows' => [] ];
    }

    repair_mojibake_text_nodes( $root );

    $children = iterator_to_array( $root->childNodes );
    $intro = '';
    $outro = '';
    $rows = [];
    $seen_transcript = false;
    $index = 0;
    $count = count( $children );

    while ( $index < $count ) {
        $child = $children[ $index ];
        if ( ! $child instanceof DOMNode ) {
            $index++;
            continue;
        }

        $table = extract_transcript_table( $child );
        if ( $table instanceof DOMElement ) {
            $table_rows = transcript_rows_from_table( $table );
            if ( count( $table_rows ) >= 2 ) {
                $seen_transcript = true;
                $rows = array_merge( $rows, $table_rows );
                $index++;
                continue;
            }
        }

        $paragraph_rows = extract_transcript_paragraph_rows( $children, $index );
        if ( $paragraph_rows !== [] ) {
            $seen_transcript = true;
            if ( $rows === [] ) {
                $lead = extract_trailing_intro_paragraph( $intro );
                if ( $lead !== '' ) {
                    array_unshift(
                        $paragraph_rows,
                        [
                            'type' => 'message',
                            'speaker' => 'INTRODUCTION',
                            'text' => $lead,
                        ]
                    );
                    $intro = trim( remove_trailing_intro_paragraph( $intro ) );
                }
            }
            $rows = array_merge( $rows, $paragraph_rows );
            continue;
        }

        $chunk = trim( (string) $doc->saveHTML( $child ) );
        if ( $chunk === '' ) {
            $index++;
            continue;
        }

        if ( ! $seen_transcript ) {
            $intro .= $chunk;
        } else {
            $outro .= $chunk;
        }
        $index++;
    }

    return [
        'intro_html' => $intro,
        'outro_html' => $outro,
        'rows' => normalize_transcript_rows( $rows ),
    ];
}

/**
 * @param array<int,DOMNode> $children
 * @return array<int,array{type:string,speaker?:string,text:string}>
 */
function extract_transcript_paragraph_rows( array $children, int &$index ): array {
    $rows = [];
    $count = count( $children );
    $cursor = $index;

    while ( $cursor < $count ) {
        $row = extract_transcript_paragraph_row( $children[ $cursor ] );
        if ( $row === null ) {
            break;
        }
        $rows[] = $row;
        $cursor++;
    }

    if ( count( $rows ) < 2 ) {
        return [];
    }

    $index = $cursor;
    return normalize_transcript_rows( $rows );
}

/**
 * @return array{type:string,speaker?:string,text:string}|null
 */
function extract_transcript_paragraph_row( DOMNode $node ): ?array {
    if ( ! $node instanceof DOMElement || strtolower( $node->tagName ) !== 'p' ) {
        return null;
    }

    $first_element = null;
    foreach ( $node->childNodes as $child ) {
        if ( $child instanceof DOMText && trim( preg_replace( '/\x{00A0}/u', ' ', $child->nodeValue ?? '' ) ?? '' ) === '' ) {
            continue;
        }
        if ( $child instanceof DOMElement ) {
            $first_element = $child;
        }
        break;
    }

    if ( ! $first_element instanceof DOMElement || strtolower( $first_element->tagName ) !== 'strong' ) {
        return null;
    }

    $speaker = sanitize_transcript_speaker( (string) $first_element->textContent );
    if ( $speaker === '' ) {
        return null;
    }

    $clone = $node->cloneNode( true );
    if ( ! $clone instanceof DOMElement ) {
        return null;
    }

    foreach ( iterator_to_array( $clone->childNodes ) as $child ) {
        if ( $child instanceof DOMText && trim( preg_replace( '/\x{00A0}/u', ' ', $child->nodeValue ?? '' ) ?? '' ) === '' ) {
            $clone->removeChild( $child );
            continue;
        }
        if ( $child instanceof DOMElement && strtolower( $child->tagName ) === 'strong' ) {
            $clone->removeChild( $child );
        }
        break;
    }

    $text = transcript_cell_text( $clone );
    if ( $text === '' ) {
        return null;
    }

    return [
        'type' => 'message',
        'speaker' => $speaker,
        'text' => $text,
    ];
}

function extract_trailing_intro_paragraph( string $html ): string {
    if ( trim( $html ) === '' || ! preg_match_all( '/<p\b[^>]*>.*?<\/p>/is', $html, $matches ) ) {
        return '';
    }

    $paragraphs = $matches[0] ?? [];
    if ( $paragraphs === [] ) {
        return '';
    }

    $candidate = (string) end( $paragraphs );
    $text = transcript_html_fragment_text( $candidate );
    if ( $text === '' ) {
        return '';
    }

    if ( preg_match( '/listen here|kwbu|transcript produced|living it/i', $text ) === 1 && mb_strlen( $text ) < 180 ) {
        return '';
    }

    if ( mb_strlen( $text ) < 120 ) {
        return '';
    }

    return $text;
}

function remove_trailing_intro_paragraph( string $html ): string {
    return preg_replace( '/<p\b[^>]*>.*?<\/p>\s*$/is', '', $html ) ?? $html;
}

function transcript_html_fragment_text( string $html ): string {
    $normalized = preg_replace( '/<br\s*\/?>/i', "\n", $html ) ?? $html;
    $normalized = preg_replace( '/<\/(p|div|li|blockquote|h[1-6])>/i', "\n", $normalized ) ?? $normalized;
    $normalized = strip_tags( $normalized );
    $normalized = html_entity_decode( $normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $normalized = repair_plain_text( $normalized );
    $normalized = preg_replace( "/\n{3,}/", "\n\n", $normalized ) ?? $normalized;
    $normalized = preg_replace( '/[ \t]+/', ' ', $normalized ) ?? $normalized;
    return trim( $normalized );
}

function extract_transcript_table( DOMNode $node ): ?DOMElement {
    if ( $node instanceof DOMElement && strtolower( $node->tagName ) === 'table' ) {
        return $node;
    }
    if ( $node instanceof DOMElement && strtolower( $node->tagName ) === 'figure' && strpos( ' ' . $node->getAttribute( 'class' ) . ' ', ' wp-block-table ' ) !== false ) {
        foreach ( $node->getElementsByTagName( 'table' ) as $table ) {
            if ( $table instanceof DOMElement ) {
                return $table;
            }
        }
    }
    return null;
}

/**
 * @return array<int,array{type:string,speaker?:string,text:string}>
 */
function transcript_rows_from_table( DOMElement $table ): array {
    $rows = [];
    foreach ( $table->getElementsByTagName( 'tr' ) as $row ) {
        if ( ! $row instanceof DOMElement ) {
            continue;
        }
        $cells = [];
        foreach ( $row->childNodes as $cell ) {
            if ( $cell instanceof DOMElement && in_array( strtolower( $cell->tagName ), [ 'td', 'th' ], true ) ) {
                $cells[] = $cell;
            }
        }
        if ( count( $cells ) !== 2 ) {
            return [];
        }

        $speaker = sanitize_transcript_speaker( (string) ( $cells[0]->textContent ?? '' ) );
        if ( $speaker === '' ) {
            return [];
        }

        $text = transcript_cell_text( $cells[1] );
        if ( $text === '' ) {
            continue;
        }

        if ( preg_match( '/^\(([^()]{1,180})\)\s*(.+)$/us', $text, $match ) === 1 ) {
            $cue = repair_plain_text( (string) ( $match[1] ?? '' ) );
            $message = repair_plain_text( (string) ( $match[2] ?? '' ) );
            if ( $cue !== '' ) {
                $rows[] = [ 'type' => 'cue', 'text' => $cue ];
            }
            if ( $message !== '' ) {
                $rows[] = [ 'type' => 'message', 'speaker' => $speaker, 'text' => $message ];
            }
            continue;
        }

        $rows[] = [
            'type' => 'message',
            'speaker' => $speaker,
            'text' => $text,
        ];
    }

    return $rows;
}

function transcript_cell_text( DOMElement $cell ): string {
    $html = '';
    foreach ( $cell->childNodes as $child ) {
        $html .= $cell->ownerDocument instanceof DOMDocument ? (string) $cell->ownerDocument->saveHTML( $child ) : '';
    }
    if ( trim( $html ) === '' ) {
        return '';
    }

    $normalized = preg_replace( '/<br\s*\/?>/i', "\n", $html ) ?? $html;
    $normalized = preg_replace( '/<\/(p|div|li|blockquote|h[1-6])>/i', "\n", $normalized ) ?? $normalized;
    $normalized = strip_tags( $normalized );
    $normalized = html_entity_decode( $normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $normalized = repair_plain_text( $normalized );
    $normalized = preg_replace( "/\n{3,}/", "\n\n", $normalized ) ?? $normalized;
    $normalized = preg_replace( '/[ \t]+/', ' ', $normalized ) ?? $normalized;
    return trim( $normalized );
}

/**
 * @param array<int,array{type:string,speaker?:string,text:string}> $rows
 * @return array<int,array{type:string,speaker?:string,text:string}>
 */
function normalize_transcript_rows( array $rows ): array {
    $out = [];
    foreach ( $rows as $row ) {
        $type = metis_key_clean( (string) ( $row['type'] ?? 'message' ) );
        $text = repair_plain_text( (string) ( $row['text'] ?? '' ) );
        if ( $text === '' ) {
            continue;
        }
        if ( $type === 'cue' ) {
            $out[] = [ 'type' => 'cue', 'text' => $text ];
            continue;
        }
        $speaker = sanitize_transcript_speaker( (string) ( $row['speaker'] ?? '' ) );
        if ( $speaker === '' ) {
            continue;
        }
        $out[] = [ 'type' => 'message', 'speaker' => $speaker, 'text' => $text ];
    }
    return array_slice( $out, 0, 800 );
}

/**
 * @param array<int,array{type:string,speaker?:string,text:string}> $rows
 */
function transcript_source_from_rows( array $rows ): string {
    $lines = [];
    foreach ( $rows as $row ) {
        $type = (string) ( $row['type'] ?? 'message' );
        $text = repair_plain_text( (string) ( $row['text'] ?? '' ) );
        if ( $text === '' ) {
            continue;
        }
        if ( $type === 'cue' ) {
            $lines[] = '(' . $text . ')';
            continue;
        }
        $speaker = sanitize_transcript_speaker( (string) ( $row['speaker'] ?? '' ) );
        if ( $speaker === '' ) {
            continue;
        }
        $parts = preg_split( "/\n/", $text ) ?: [ $text ];
        $first = array_shift( $parts );
        $lines[] = $speaker . ': ' . (string) $first;
        foreach ( $parts as $part ) {
            $lines[] = trim( (string) $part );
        }
    }
    return trim( implode( "\n", $lines ) );
}

function sanitize_transcript_speaker( string $speaker ): string {
    $speaker = repair_plain_text( $speaker );
    $speaker = preg_replace( '/\s+/u', ' ', $speaker ) ?? $speaker;
    $speaker = trim( $speaker );
    if ( $speaker === '' || mb_strlen( $speaker ) > 48 ) {
        return '';
    }
    if ( preg_match( '/^[A-Za-z0-9 .,&()\'"\/-]+:?$/u', $speaker ) !== 1 ) {
        return '';
    }
    return rtrim( $speaker, ':' );
}

function html_has_meaningful_content( string $html ): bool {
    $normalized = preg_replace( '/<br\s*\/?\s*>/i', ' ', $html ) ?? $html;
    $normalized = preg_replace( '/&nbsp;/i', ' ', $normalized ) ?? $normalized;
    $text = trim( strip_tags( html_entity_decode( $normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    return $text !== '';
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

    $root = $doc->getElementById( 'metis-repair-root' );
    if ( ! $root instanceof DOMElement ) {
        return $html;
    }

    repair_mojibake_text_nodes( $root );

    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= (string) $doc->saveHTML( $child );
    }
    return $out !== '' ? $out : $html;
}

function repair_mojibake_text_nodes( DOMNode $node ): void {
    foreach ( $node->childNodes as $child ) {
        if ( $child instanceof DOMText ) {
            $child->nodeValue = repair_plain_text( (string) $child->nodeValue );
            continue;
        }
        if ( $child instanceof DOMNode ) {
            repair_mojibake_text_nodes( $child );
        }
    }
}

function repair_plain_text( string $text ): string {
    $current = replace_common_mojibake_sequences( $text );
    $current = preg_replace( '/\s+�\s+/u', ' — ', $current ) ?? $current;
    $current = preg_replace( '/([A-Za-z0-9])\x{FFFD}\?\?([A-Za-z0-9])/u', '$1’$2', $current ) ?? $current;
    $current = preg_replace( '/(^|[\s(\[{])\x{FFFD}\?\?([A-Za-z0-9])/u', '$1“$2', $current ) ?? $current;
    $current = preg_replace( '/([A-Za-z0-9?!.,])\x{FFFD}\?\?($|[\s)\]}.,;!?])/u', '$1”$2', $current ) ?? $current;
    $current_score = mojibake_score( $current );
    if ( $current_score < 1 ) {
        return str_replace( [ "\xc2\xa0", "\xa0" ], ' ', $current );
    }

    for ( $i = 0; $i < 3; $i++ ) {
        $candidate = function_exists( 'mb_convert_encoding' )
            ? @mb_convert_encoding( $current, 'UTF-8', 'Windows-1252' )
            : @iconv( 'Windows-1252', 'UTF-8//IGNORE', $current );
        if ( ! is_string( $candidate ) || $candidate === '' || $candidate === $current ) {
            break;
        }
        if ( mojibake_score( $candidate ) >= $current_score ) {
            break;
        }
        $current = $candidate;
        $current_score = mojibake_score( $current );
    }

    return str_replace( [ "\xc2\xa0", "\xa0" ], ' ', $current );
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
            'â',
            'â',
            'â',
            'â',
            'â¦',
            'â',
            'â',
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
    $markers = [ 'Ã', 'Â', 'â€', 'â', 'â€™', 'â€œ', 'â€\x9d', '�' ];
    $score = 0;
    foreach ( $markers as $marker ) {
        $score += substr_count( $text, $marker );
    }
    return $score;
}
