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
        $value = is_scalar( $value ) ? (string) $value : '';
        $value = strip_tags( $value );
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '' );
    }
}

if ( ! function_exists( 'metis_textarea_clean' ) ) {
    function metis_textarea_clean( mixed $value ): string {
        $value = is_scalar( $value ) ? (string) $value : '';
        return trim( strip_tags( $value ) );
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
    $trimmed = trim( $value );
    if ( $trimmed === '' || str_starts_with( $trimmed, '#' ) || str_starts_with( $trimmed, '/' ) || str_starts_with( $trimmed, '?' ) ) {
        return true;
    }
    if ( str_starts_with( $trimmed, '//' ) ) {
        return true;
    }
    $scheme = parse_url( $trimmed, PHP_URL_SCHEME );
    if ( ! is_string( $scheme ) || $scheme === '' ) {
        return true;
    }
    return in_array( strtolower( $scheme ), [ 'http', 'https', 'mailto', 'tel' ], true );
}

function metis_runtime_is_safe_css_value( string $value ): bool {
    if ( $value === '' ) {
        return true;
    }
    if ( preg_match( '/expression\s*\(|javascript\s*:|data\s*:\s*text\/html|behavior\s*:|@import/i', $value ) ) {
        return false;
    }
    return true;
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

    $allowed_map = metis_runtime_allowed_html_map();
    $allowed_tags = array_keys( $allowed_map );
    $stripped = strip_tags( $html, '<' . implode( '><', $allowed_tags ) . '>' );

    if ( ! class_exists( 'DOMDocument' ) ) {
        return $stripped;
    }

    $internal_errors = libxml_use_internal_errors( true );
    $doc = new DOMDocument( '1.0', 'UTF-8' );
    $wrapper = '<!DOCTYPE html><html><body><metis-root>' . $stripped . '</metis-root></body></html>';
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
    return (string) $output;
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

function esc_textarea( mixed $value ): string {
    return metis_escape_html( $value );
}

if ( ! function_exists( 'metis_escape_url' ) ) {
    function metis_escape_url( mixed $value ): string {
        return filter_var( (string) $value, FILTER_SANITIZE_URL ) ?: '';
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
