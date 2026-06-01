<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_key_clean' ) ) {
    function metis_key_clean( string $key ): string {
        if ( $key === '' ) {
            return '';
        }

        static $cache = [];
        static $cache_order = [];

        // Keys are identifiers, not payloads. Cap hostile/accidental oversized input.
        $cache_key = strlen( $key ) > 2048 ? substr( $key, 0, 2048 ) : $key;
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $lower = strtolower( $cache_key );
        $safe_chars = 'abcdefghijklmnopqrstuvwxyz0123456789_-';

        if ( strspn( $lower, $safe_chars ) === strlen( $lower ) ) {
            $sanitized = $lower;
        } else {
            $sanitized = preg_replace( '/[^a-z0-9_-]+/', '', $lower ) ?? '';
        }

        $cache[ $cache_key ] = $sanitized;
        $cache_order[] = $cache_key;
        if ( count( $cache_order ) > 256 ) {
            $evict = array_shift( $cache_order );
            if ( is_string( $evict ) ) {
                unset( $cache[ $evict ] );
            }
        }

        return $sanitized;
    }
}

if ( ! function_exists( 'metis_text_clean' ) ) {
    function metis_text_clean( mixed $value ): string {
        $value = is_scalar( $value ) ? metis_runtime_normalize_text_encoding( (string) $value ) : '';
        $value = strip_tags( $value );
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '' );
    }
}

if ( ! function_exists( 'metis_textarea_clean' ) ) {
    function metis_textarea_clean( mixed $value ): string {
        $value = is_scalar( $value ) ? metis_runtime_normalize_text_encoding( (string) $value ) : '';
        return trim( strip_tags( $value ) );
    }
}

if ( ! function_exists( 'metis_text_raw_clean' ) ) {
    function metis_text_raw_clean( mixed $value ): string {
        return is_scalar( $value ) ? trim( metis_runtime_normalize_text_encoding( (string) $value ) ) : '';
    }
}

if ( ! function_exists( 'metis_email_clean' ) ) {
    function metis_email_clean( mixed $value ): string {
        return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ) ?: '';
    }
}

if ( ! function_exists( 'metis_email_is_valid' ) ) {
    function metis_email_is_valid( string $email ): string|false {
        $validated = filter_var( $email, FILTER_VALIDATE_EMAIL );
        return $validated !== false ? (string) $validated : false;
    }
}

if ( ! function_exists( 'metis_slug_clean' ) ) {
    function metis_slug_clean( mixed $value ): string {
        $value = strtolower( trim( (string) $value ) );
        $value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';
        return trim( $value, '-' );
    }
}

if ( ! function_exists( 'metis_filename_clean' ) ) {
    function metis_filename_clean( string $filename ): string {
        $filename = preg_replace( '/[^A-Za-z0-9\.\-_]/', '-', $filename ) ?? 'file';
        return trim( $filename, '-.' ) ?: 'file';
    }
}

if ( ! function_exists( 'metis_hex_color_clean' ) ) {
    function metis_hex_color_clean( string $color ): string {
        return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? $color : '';
    }
}

function metis_runtime_unslash( mixed $value ): mixed {
    if ( is_array( $value ) ) {
        return array_map( 'metis_runtime_unslash', $value );
    }

    return is_string( $value ) ? stripslashes( $value ) : $value;
}

function metis_runtime_replace_common_mojibake_sequences( string $text ): string {
    if ( $text === '' ) {
        return '';
    }

    $replacements = [
        'Ã¢ÂÂ' => '’',
        'Ã¢ÂÂ' => '‘',
        'Ã¢ÂÂ' => '“',
        'Ã¢ÂÂ' => '”',
        'Ã¢ÂÂ¦' => '…',
        'Ã¢ÂÂ' => '–',
        'Ã¢ÂÂ' => '—',
        'â€™' => '’',
        'â€˜' => '‘',
        'â€œ' => '“',
        'â€' => '”',
        'â€¦' => '…',
        'â€“' => '–',
        'â€”' => '—',
        'â' => '’',
        'â' => '‘',
        'â' => '“',
        'â' => '”',
        'â¦' => '…',
        'â' => '–',
        'â' => '—',
        'ÃÂ ' => ' ',
        'Â ' => ' ',
    ];

    $current = strtr( $text, $replacements );
    $current = preg_replace( '/&Acirc;(&nbsp;|&#160;|&#xA0;|&#xa0;)/i', ' ', $current ) ?? $current;
    $current = preg_replace( '/Â(&nbsp;|&#160;|&#xA0;|&#xa0;)/i', ' ', $current ) ?? $current;
    $current = preg_replace( '/Ã+(?=\s|$)/', '', $current ) ?? $current;
    $current = preg_replace( '/Ã(?=[A-Za-z0-9])/', '', $current ) ?? $current;
    $current = preg_replace( '/\xC2+(?=\s|$)/u', '', $current ) ?? $current;
    $current = preg_replace( '/\x{00A0}/u', ' ', $current ) ?? $current;
    $current = preg_replace( '/\x{FFFD}+/u', '', $current ) ?? $current;

    return $current;
}

function metis_runtime_mojibake_score( string $text ): int {
    preg_match_all( '/(?:Ã.|â.|Â|�)/u', $text, $matches );
    return count( $matches[0] ?? [] );
}

function metis_runtime_normalize_text_encoding( string $text ): string {
    if ( $text === '' ) {
        return '';
    }

    $current = metis_runtime_replace_common_mojibake_sequences( $text );
    $score = metis_runtime_mojibake_score( $current );
    if ( $score <= 0 ) {
        return $current;
    }

    for ( $attempt = 0; $attempt < 2; $attempt++ ) {
        if ( function_exists( 'mb_convert_encoding' ) ) {
            $candidate = @mb_convert_encoding( $current, 'UTF-8', 'Windows-1252' );
        } else {
            $candidate = @iconv( 'Windows-1252', 'UTF-8//IGNORE', $current );
        }
        if ( ! is_string( $candidate ) || $candidate === '' ) {
            break;
        }

        $candidate = metis_runtime_replace_common_mojibake_sequences( $candidate );
        $candidate_score = metis_runtime_mojibake_score( $candidate );
        if ( $candidate_score >= $score ) {
            break;
        }

        $current = $candidate;
        $score = $candidate_score;
        if ( $score <= 0 ) {
            break;
        }
    }

    return $current;
}

function metis_runtime_utf8_html_fragment( string $html ): string {
    return '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>';
}

/**
 * @return array<string,array<int,string>>
 */
function metis_runtime_allowed_html_map(): array {
    return [
        'a' => [ 'href', 'title', 'target', 'rel', 'class', 'id', 'aria-label' ],
        'abbr' => [ 'title', 'class' ],
        'article' => [ 'class', 'id' ],
        'aside' => [ 'class', 'id' ],
        'b' => [ 'class' ],
        'blockquote' => [ 'cite', 'class', 'id' ],
        'br' => [],
        'button' => [ 'type', 'class', 'id', 'name', 'value', 'aria-label', 'disabled' ],
        'caption' => [ 'class' ],
        'code' => [ 'class' ],
        'dd' => [ 'class' ],
        'div' => [ 'class', 'id', 'title', 'aria-label' ],
        'dl' => [ 'class', 'id' ],
        'dt' => [ 'class' ],
        'em' => [ 'class' ],
        'figcaption' => [ 'class', 'id' ],
        'figure' => [ 'class', 'id' ],
        'footer' => [ 'class', 'id' ],
        'form' => [ 'action', 'method', 'class', 'id' ],
        'h1' => [ 'class', 'id' ],
        'h2' => [ 'class', 'id' ],
        'h3' => [ 'class', 'id' ],
        'h4' => [ 'class', 'id' ],
        'h5' => [ 'class', 'id' ],
        'h6' => [ 'class', 'id' ],
        'header' => [ 'class', 'id' ],
        'hr' => [ 'class' ],
        'i' => [ 'class' ],
        'img' => [ 'src', 'alt', 'title', 'width', 'height', 'class', 'id', 'loading' ],
        'input' => [ 'type', 'name', 'value', 'placeholder', 'checked', 'disabled', 'readonly', 'min', 'max', 'step', 'class', 'id' ],
        'label' => [ 'for', 'class', 'id' ],
        'li' => [ 'class', 'id' ],
        'main' => [ 'class', 'id' ],
        'nav' => [ 'class', 'id' ],
        'ol' => [ 'class', 'id' ],
        'option' => [ 'value', 'selected', 'disabled', 'label' ],
        'p' => [ 'class', 'id', 'title' ],
        'pre' => [ 'class' ],
        'section' => [ 'class', 'id' ],
        'select' => [ 'name', 'class', 'id', 'multiple' ],
        'small' => [ 'class' ],
        'span' => [ 'class', 'id', 'title', 'aria-label' ],
        'strong' => [ 'class' ],
        'sub' => [ 'class' ],
        'sup' => [ 'class' ],
        'table' => [ 'class', 'id' ],
        'tbody' => [ 'class' ],
        'td' => [ 'class', 'colspan', 'rowspan', 'scope' ],
        'textarea' => [ 'name', 'rows', 'cols', 'placeholder', 'class', 'id' ],
        'tfoot' => [ 'class' ],
        'th' => [ 'class', 'colspan', 'rowspan', 'scope' ],
        'thead' => [ 'class' ],
        'tr' => [ 'class' ],
        'u' => [ 'class' ],
        'ul' => [ 'class', 'id' ],
    ];
}

function metis_runtime_is_safe_url_value( string $value ): bool {
    $trimmed = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    $scheme_probe = preg_replace( '/[\x00-\x20\x7f]+/', '', $trimmed ) ?? $trimmed;
    if ( preg_match( '/^(?:javascript|vbscript|data)\s*:/i', $scheme_probe ) === 1 ) {
        return false;
    }
    if ( $trimmed === '' || str_starts_with( $trimmed, '#' ) || str_starts_with( $trimmed, '/' ) || str_starts_with( $trimmed, '?' ) ) {
        return true;
    }
    if ( str_starts_with( $trimmed, '//' ) ) {
        return true;
    }
    $scheme = parse_url( $scheme_probe, PHP_URL_SCHEME );
    if ( ! is_string( $scheme ) || $scheme === '' ) {
        return true;
    }
    return in_array( strtolower( $scheme ), [ 'http', 'https', 'mailto', 'tel' ], true );
}

function metis_runtime_is_safe_css_value( string $value ): bool {
    if ( $value === '' ) {
        return true;
    }
    $decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    if ( preg_match( '/expression\s*\(|javascript\s*:|data\s*:\s*text\/html|behavior\s*:|@import|-moz-binding|url\s*\(\s*[\'"]?\s*(?:javascript|data\s*:\s*text\/html)/i', $decoded ) ) {
        return false;
    }
    return true;
}

function metis_runtime_strip_unsafe_html_elements( string $html ): string {
    if ( $html === '' ) {
        return '';
    }
    return preg_replace(
        '#<\s*(script|style|object|embed|iframe|link|meta|base|svg|math)\b[^>]*>.*?<\s*/\s*\1\s*>|<\s*(script|style|object|embed|iframe|link|meta|base|svg|math)\b[^>]*\/?>#is',
        '',
        $html
    ) ?? '';
}

/**
 * @param array<string,array<int,string>> $allowed_map
 */
function metis_runtime_sanitize_html_tag_fallback( string $tag, string $attrs, array $allowed_map ): string {
    $tag = strtolower( $tag );
    if ( ! isset( $allowed_map[ $tag ] ) ) {
        return '';
    }
    $allowed_attrs = array_fill_keys( $allowed_map[ $tag ], true );
    $safe_attrs = [];
    if ( preg_match_all( '/([A-Za-z_:][A-Za-z0-9_:\\.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $attrs, $matches, PREG_SET_ORDER ) > 0 ) {
        foreach ( $matches as $match ) {
            $name = strtolower( (string) ( $match[1] ?? '' ) );
            if ( $name === '' || str_starts_with( $name, 'on' ) || ! isset( $allowed_attrs[ $name ] ) ) {
                continue;
            }
            $value = '';
            if ( array_key_exists( 2, $match ) && $match[2] !== '' ) {
                $value = (string) $match[2];
            } elseif ( array_key_exists( 3, $match ) && $match[3] !== '' ) {
                $value = (string) $match[3];
            } elseif ( array_key_exists( 4, $match ) && $match[4] !== '' ) {
                $value = (string) $match[4];
            }
            if ( in_array( $name, [ 'href', 'src', 'action' ], true ) && ! metis_runtime_is_safe_url_value( $value ) ) {
                continue;
            }
            if ( $name === 'style' && ! metis_runtime_is_safe_css_value( $value ) ) {
                continue;
            }
            if ( in_array( $name, [ 'disabled', 'checked', 'readonly', 'selected', 'multiple' ], true ) && $value === '' ) {
                $safe_attrs[] = $name;
                continue;
            }
            $safe_attrs[] = $name . '="' . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
        }
    }

    return '<' . $tag . ( $safe_attrs !== [] ? ' ' . implode( ' ', $safe_attrs ) : '' ) . '>';
}

/**
 * @param array<string,array<int,string>> $allowed_map
 */
function metis_runtime_sanitize_html_attributes_fallback( string $html, array $allowed_map ): string {
    if ( $html === '' ) {
        return '';
    }
    $html = preg_replace( '/<!--.*?-->/s', '', $html ) ?? '';
    return preg_replace_callback(
        '/<\s*(\/?)\s*([A-Za-z][A-Za-z0-9:-]*)([^>]*)>/',
        static function ( array $match ) use ( $allowed_map ): string {
            $closing = (string) ( $match[1] ?? '' );
            $tag = strtolower( (string) ( $match[2] ?? '' ) );
            if ( ! isset( $allowed_map[ $tag ] ) ) {
                return '';
            }
            if ( $closing === '/' ) {
                return '</' . $tag . '>';
            }
            $attrs = (string) ( $match[3] ?? '' );
            $self_closing = str_ends_with( trim( $attrs ), '/' );
            if ( $self_closing ) {
                $attrs = preg_replace( '#/\s*$#', '', $attrs ) ?? $attrs;
            }
            $safe = metis_runtime_sanitize_html_tag_fallback( $tag, $attrs, $allowed_map );
            if ( $safe === '' ) {
                return '';
            }
            return $self_closing ? substr( $safe, 0, -1 ) . ' />' : $safe;
        },
        $html
    ) ?? '';
}

if ( ! function_exists( 'metis_runtime_parse_url' ) ) {
    function metis_runtime_parse_url( string $url, int $component = -1 ): mixed {
        return parse_url( $url, $component );
    }
}

function metis_runtime_kses_post( string $html ): string {
    if ( trim( $html ) === '' ) {
        return '';
    }

    $html = metis_runtime_normalize_text_encoding( $html );
    $allowed_map = metis_runtime_allowed_html_map();
    $allowed_tags = array_keys( $allowed_map );
    $precleaned = metis_runtime_strip_unsafe_html_elements( $html );
    $stripped = strip_tags( $precleaned, '<' . implode( '><', $allowed_tags ) . '>' );
    $stripped = metis_runtime_sanitize_html_attributes_fallback( $stripped, $allowed_map );

    if ( ! class_exists( 'DOMDocument' ) ) {
        return $stripped;
    }

    $internal_errors = libxml_use_internal_errors( true );
    $doc = new DOMDocument( '1.0', 'UTF-8' );
    $wrapper = metis_runtime_utf8_html_fragment( '<metis-root>' . $stripped . '</metis-root>' );
    $loaded = $doc->loadHTML( $wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();
    libxml_use_internal_errors( $internal_errors );
    if ( $loaded === false ) {
        return $stripped;
    }

    $root_nodes = $doc->getElementsByTagName( 'metis-root' );
    $root = $root_nodes->length > 0 ? $root_nodes->item( 0 ) : null;
    if ( ! $root ) {
        return $stripped;
    }

    $walk = static function ( $node ) use ( &$walk, $allowed_map, $doc ): void {
        if ( ! $node ) {
            return;
        }
        for ( $i = $node->childNodes->length - 1; $i >= 0; $i-- ) {
            $walk( $node->childNodes->item( $i ) );
        }

        if ( $node->nodeType !== XML_ELEMENT_NODE ) {
            if ( $node->nodeType === XML_COMMENT_NODE && $node->parentNode ) {
                $node->parentNode->removeChild( $node );
            }
            return;
        }

        $tag = strtolower( (string) $node->nodeName );
        if ( ! isset( $allowed_map[ $tag ] ) ) {
            if ( $node->parentNode ) {
                while ( $node->firstChild ) {
                    $node->parentNode->insertBefore( $node->firstChild, $node );
                }
                $node->parentNode->removeChild( $node );
            }
            return;
        }

        $allowed_attrs = array_fill_keys( $allowed_map[ $tag ], true );
        if ( $node->hasAttributes() ) {
            for ( $idx = $node->attributes->length - 1; $idx >= 0; $idx-- ) {
                $attr = $node->attributes->item( $idx );
                if ( ! $attr ) {
                    continue;
                }
                $name = strtolower( (string) $attr->name );
                $value = (string) $attr->value;

                if ( str_starts_with( $name, 'on' ) ) {
                    $node->removeAttributeNode( $attr );
                    continue;
                }
                if ( ! isset( $allowed_attrs[ $name ] ) ) {
                    $node->removeAttributeNode( $attr );
                    continue;
                }
                if ( in_array( $name, [ 'href', 'src', 'action' ], true ) && ! metis_runtime_is_safe_url_value( $value ) ) {
                    $node->removeAttributeNode( $attr );
                    continue;
                }
                if ( $name === 'style' && ! metis_runtime_is_safe_css_value( $value ) ) {
                    $node->removeAttributeNode( $attr );
                }
            }
        }
    };

    foreach ( iterator_to_array( $root->childNodes ) as $child ) {
        $walk( $child );
    }

    $output = '';
    foreach ( iterator_to_array( $root->childNodes ) as $child ) {
        $output .= $doc->saveHTML( $child );
    }
    return metis_runtime_normalize_text_encoding( (string) $output );
}

if ( ! function_exists( 'metis_runtime_json_encode' ) ) {
    function metis_runtime_json_encode( mixed $value, int $flags = 0 ): string|false {
        return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES );
    }
}

if ( ! function_exists( 'metis_escape_html' ) ) {
    function metis_escape_html( mixed $value ): string {
        return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}

if ( ! function_exists( 'metis_escape_attr' ) ) {
    function metis_escape_attr( mixed $value ): string {
        return metis_escape_html( $value );
    }
}

if ( ! function_exists( 'metis_esc_html' ) ) {
    function metis_esc_html( mixed $value ): string {
        return metis_escape_html( $value );
    }
}

if ( ! function_exists( 'metis_esc_attr' ) ) {
    function metis_esc_attr( mixed $value ): string {
        return metis_escape_attr( $value );
    }
}

if ( ! function_exists( 'metis_escape_url' ) ) {
    function metis_escape_url( mixed $value ): string {
        return filter_var( (string) $value, FILTER_SANITIZE_URL ) ?: '';
    }
}

if ( ! function_exists( 'metis_escape_js' ) ) {
    function metis_escape_js( mixed $value ): string {
        return strtr(
            (string) $value,
            [
                '\\' => '\\\\',
                "'"  => "\\'",
                '"'  => '\\"',
                "\n" => '\\n',
                "\r" => '\\r',
                '</' => '<\/',
            ]
        );
    }
}

function esc_sql( string $value ): string {
    return addslashes( $value );
}

function esc_js( mixed $value ): string {
    return addslashes( (string) $value );
}

function disabled( bool $disabled, bool $current = true, bool $display = true ): string {
    $result = $disabled === $current ? 'disabled' : '';
    if ( $display ) {
        echo $result;
    }
    return $result;
}

function metis_runtime_attr_compare_value( mixed $value ): string {
    if ( is_scalar( $value ) || $value === null ) {
        return (string) $value;
    }

    return '';
}

if ( ! function_exists( 'metis_attr_selected' ) ) {
    function metis_attr_selected( mixed $selected, mixed $current = true, bool $display = true ): string {
        $result = metis_runtime_attr_compare_value( $selected ) === metis_runtime_attr_compare_value( $current ) ? 'selected' : '';
        if ( $display ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'metis_attr_checked' ) ) {
    function metis_attr_checked( mixed $checked, mixed $current = true, bool $display = true ): string {
        $result = metis_runtime_attr_compare_value( $checked ) === metis_runtime_attr_compare_value( $current ) ? 'checked' : '';
        if ( $display ) {
            echo $result;
        }
        return $result;
    }
}
