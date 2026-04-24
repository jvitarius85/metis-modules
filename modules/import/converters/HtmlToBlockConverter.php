<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Converters;

/**
 * HTML to Block Converter
 * 
 * Converts raw HTML content to structured Metis blocks.
 * Smart pattern recognition for common HTML structures.
 */
final class HtmlToBlockConverter {

    /**
     * Convert HTML to blocks
     */
    public static function convert( string $html ): array {
        if ( trim( $html ) === '' ) {
            return [ 'blocks' => [], 'warnings' => [] ];
        }

        $warnings = [];
        $blocks = [];

        // Clean and parse HTML
        $dom = self::parseHtml( $html );
        if ( $dom === null ) {
            return [
                'blocks' => [ self::createHtmlFallbackBlock( $html ) ],
                'warnings' => [ 'Failed to parse HTML, created fallback block' ],
            ];
        }

        // Convert DOM nodes to blocks
        $body = $dom->getElementsByTagName( 'body' )->item( 0 );
        if ( $body === null ) {
            return [
                'blocks' => [ self::createHtmlFallbackBlock( $html ) ],
                'warnings' => [ 'No body element found' ],
            ];
        }

        foreach ( $body->childNodes as $node ) {
            $converted = self::convertNode( $node, $warnings );
            if ( $converted !== null ) {
                $blocks[] = $converted;
            }
        }

        return [
            'blocks' => $blocks,
            'warnings' => $warnings,
        ];
    }

    /**
     * Parse HTML string to DOMDocument
     */
    private static function parseHtml( string $html ): ?\DOMDocument {
        $dom = new \DOMDocument();
        
        // Suppress HTML5 warnings
        libxml_use_internal_errors( true );
        
        $success = $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        
        libxml_clear_errors();
        libxml_use_internal_errors( false );

        return $success ? $dom : null;
    }

    /**
     * Convert DOM node to block
     */
    private static function convertNode( \DOMNode $node, array &$warnings ): ?array {
        // Skip text nodes with only whitespace
        if ( $node->nodeType === XML_TEXT_NODE ) {
            if ( trim( $node->textContent ?? '' ) === '' ) {
                return null;
            }
            // Wrap text in paragraph
            return self::createTextBlock( '<p>' . htmlspecialchars( $node->textContent ?? '' ) . '</p>' );
        }

        if ( $node->nodeType !== XML_ELEMENT_NODE ) {
            return null;
        }

        /** @var \DOMElement $node */
        $tag_name = strtolower( $node->nodeName );

        // Heading tags
        if ( in_array( $tag_name, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
            return self::createHeadingBlock( $node, $tag_name );
        }

        // Paragraph and div
        if ( in_array( $tag_name, [ 'p', 'div' ], true ) ) {
            return self::createTextBlock( self::getInnerHtml( $node ) );
        }

        // Rich text containers/lists/semantic wrappers -> keep as text HTML block content.
        if ( in_array( $tag_name, [ 'ul', 'ol', 'li', 'figure', 'figcaption', 'blockquote', 'pre', 'code', 'table', 'thead', 'tbody', 'tr', 'th', 'td' ], true ) ) {
            return self::createTextBlock( self::getOuterHtml( $node ) );
        }

        // Common inline text tags should be preserved as rich text, not flagged as unsupported.
        if ( in_array( $tag_name, [ 'a', 'strong', 'b', 'em', 'i', 'u', 'span', 'small', 'mark', 'sup', 'sub', 'br' ], true ) ) {
            return self::createTextBlock( self::getOuterHtml( $node ) );
        }

        // Image
        if ( $tag_name === 'img' ) {
            return self::createImageBlock( $node );
        }

        // Link that looks like a button
        if ( $tag_name === 'a' && self::looksLikeButton( $node ) ) {
            return self::createButtonBlock( $node );
        }

        // Horizontal rule
        if ( $tag_name === 'hr' ) {
            return self::createDividerBlock();
        }

        // Container divs
        if ( $tag_name === 'section' || $tag_name === 'article' ) {
            return self::createContainerBlock( $node, $warnings );
        }

        // Unsupported - keep content as text block HTML (safe fallback for validators).
        $warnings[] = "Unsupported HTML tag: {$tag_name}";
        return self::createHtmlFallbackBlock( self::getOuterHtml( $node ) );
    }

    /**
     * Create heading block
     */
    private static function createHeadingBlock( \DOMElement $node, string $level ): array {
        return [
            'id' => self::generateBlockId(),
            'type' => 'heading',
            'data' => [
                'content' => '<' . $level . '>' . htmlspecialchars( $node->textContent ?? '' ) . '</' . $level . '>',
                'level' => $level,
                'align' => 'left',
            ],
            'style' => [],
        ];
    }

    /**
     * Create text block
     */
    private static function createTextBlock( string $content ): array {
        return [
            'id' => self::generateBlockId(),
            'type' => 'text',
            'data' => [
                'content' => $content,
                'tag' => 'div',
                'align' => 'left',
            ],
            'style' => [],
        ];
    }

    /**
     * Create image block
     */
    private static function createImageBlock( \DOMElement $node ): array {
        return [
            'id' => self::generateBlockId(),
            'type' => 'image',
            'data' => [
                'src' => $node->getAttribute( 'src' ),
                'alt' => $node->getAttribute( 'alt' ),
                'width' => $node->getAttribute( 'width' ) ?: '100%',
                'link' => '',
            ],
            'style' => [],
        ];
    }

    /**
     * Create button block
     */
    private static function createButtonBlock( \DOMElement $node ): array {
        return [
            'id' => self::generateBlockId(),
            'type' => 'button',
            'data' => [
                'label' => $node->textContent ?? 'Click Here',
                'url' => $node->getAttribute( 'href' ) ?: '#',
                'bgcolor' => '#0d6efd',
                'color' => '#ffffff',
                'size' => 'medium',
            ],
            'style' => [],
        ];
    }

    /**
     * Create divider block
     */
    private static function createDividerBlock(): array {
        return [
            'id' => self::generateBlockId(),
            'type' => 'divider',
            'data' => [
                'color' => '#e2e6ea',
                'height' => 1,
                'style' => 'solid',
            ],
            'style' => [],
        ];
    }

    /**
     * Create container block
     */
    private static function createContainerBlock( \DOMElement $node, array &$warnings ): array {
        $inner_blocks = [];

        foreach ( $node->childNodes as $child ) {
            $converted = self::convertNode( $child, $warnings );
            if ( $converted !== null ) {
                $inner_blocks[] = $converted;
            }
        }

        return [
            'id' => self::generateBlockId(),
            'type' => 'container',
            'data' => [
                'blocks' => $inner_blocks,
                'max_width' => '1200px',
                'align' => 'center',
            ],
            'style' => [],
        ];
    }

    /**
     * Create HTML fallback block
     */
    private static function createHtmlFallbackBlock( string $html ): array {
        // Sanitize HTML
        $html = self::sanitizeHtml( $html );

        return [
            'id' => self::generateBlockId(),
            'type' => 'text',
            'data' => [
                'content' => $html,
                'tag' => 'div',
                'align' => 'left',
            ],
            'style' => [],
        ];
    }

    /**
     * Check if link looks like a button
     */
    private static function looksLikeButton( \DOMElement $node ): bool {
        $class = $node->getAttribute( 'class' );
        $style = $node->getAttribute( 'style' );

        // Check for button-like classes
        if ( preg_match( '/\b(btn|button|cta)\b/i', $class ) ) {
            return true;
        }

        // Check for button-like inline styles
        if ( preg_match( '/background|padding|border-radius/i', $style ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get inner HTML of node
     */
    private static function getInnerHtml( \DOMElement $node ): string {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            if ( $node->ownerDocument === null ) {
                continue;
            }
            $html .= $node->ownerDocument->saveHTML( $child );
        }
        return $html;
    }

    /**
     * Get outer HTML of node
     */
    private static function getOuterHtml( \DOMElement $node ): string {
        if ( $node->ownerDocument === null ) {
            return '';
        }
        return $node->ownerDocument->saveHTML( $node ) ?: '';
    }

    /**
     * Sanitize HTML
     */
    private static function sanitizeHtml( string $html ): string {
        // Strip script tags
        $html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html ) ?? $html;
        
        // Strip on* event attributes
        $html = preg_replace( '/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html ) ?? $html;
        
        return $html;
    }

    /**
     * Generate unique block ID
     */
    private static function generateBlockId(): string {
        return 'block_' . bin2hex( random_bytes( 8 ) );
    }
}
