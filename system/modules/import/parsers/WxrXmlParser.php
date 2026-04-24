<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Parsers;

/**
 * WXR XML Parser
 * 
 * Parses WXR export files.
 * Extracts pages, posts, media, and metadata.
 */
final class WxrXmlParser {

    /**
     * Parse WXR XML export file
     */
    public static function parse( string $file_path ): array {
        if ( ! file_exists( $file_path ) ) {
            return [
                'success' => false,
                'error' => 'File not found',
            ];
        }

        $xml_content = file_get_contents( $file_path );
        if ( $xml_content === false ) {
            return [
                'success' => false,
                'error' => 'Failed to read file',
            ];
        }

        // Suppress XML parsing warnings
        libxml_use_internal_errors( true );

        try {
            $xml = simplexml_load_string( $xml_content );
            if ( $xml === false ) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $first_error = ( is_array( $errors ) && isset( $errors[0] ) && is_object( $errors[0] ) && isset( $errors[0]->message ) )
                    ? (string) $errors[0]->message
                    : 'Unknown error';
                return [
                    'success' => false,
                    'error' => 'Invalid XML: ' . $first_error,
                ];
            }

            // Register WXR namespaces
            $namespaces = $xml->getNamespaces( true );
            
            $result = [
                'success' => true,
                'pages' => [],
                'posts' => [],
                'media' => [],
                'menus' => [],
                'site_info' => self::extractSiteInfo( $xml ),
                'stats' => [
                    'total_items' => 0,
                    'pages' => 0,
                    'posts' => 0,
                    'media' => 0,
                    'menus' => 0,
                ],
            ];

            // Parse items (pages, posts, attachments)
            foreach ( $xml->channel->item as $item ) {
                $parsed_item = self::parseItem( $item, $namespaces );
                
                if ( $parsed_item === null ) {
                    continue;
                }

                $result['stats']['total_items']++;

                switch ( $parsed_item['type'] ) {
                    case 'page':
                        $result['pages'][] = $parsed_item;
                        $result['stats']['pages']++;
                        break;
                    case 'post':
                        $result['posts'][] = $parsed_item;
                        $result['stats']['posts']++;
                        break;
                    case 'attachment':
                        $result['media'][] = $parsed_item;
                        $result['stats']['media']++;
                        break;
                    case 'nav_menu_item':
                        $result['menus'][] = $parsed_item;
                        $result['stats']['menus']++;
                        break;
                }
            }

            return $result;

        } catch ( \Exception $e ) {
            return [
                'success' => false,
                'error' => 'Unable to parse XML export.',
            ];
        } finally {
            libxml_use_internal_errors( false );
        }
    }

    /**
     * Extract site information
     */
    private static function extractSiteInfo( \SimpleXMLElement $xml ): array {
        return [
            'title' => (string) $xml->channel->title,
            'link' => (string) $xml->channel->link,
            'description' => (string) $xml->channel->description,
            'language' => (string) $xml->channel->language,
        ];
    }

    /**
     * Parse individual item
     */
    private static function parseItem( \SimpleXMLElement $item, array $namespaces ): ?array {
        $wxr_ns = $namespaces['wp'] ?? 'http://wordpress.org/export/1.2/';
        $content_ns = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerpt_ns = $namespaces['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';

        // Register namespaces
        $item->registerXPathNamespace( 'wp', $wxr_ns );
        $item->registerXPathNamespace( 'content', $content_ns );
        $item->registerXPathNamespace( 'excerpt', $excerpt_ns );

        $post_type = self::xpathText( $item, 'wp:post_type' );
        $status = self::xpathText( $item, 'wp:status' );

        // Skip drafts, auto-drafts, and other non-published content
        if ( in_array( $status, [ 'auto-draft', 'inherit' ], true ) ) {
            return null;
        }

        $parsed = [
            'type' => $post_type,
            'title' => (string) $item->title,
            'slug' => self::xpathText( $item, 'wp:post_name' ),
            'content' => self::xpathText( $item, 'content:encoded' ),
            'excerpt' => self::xpathText( $item, 'excerpt:encoded' ),
            'status' => $status,
            'publish_date' => self::xpathText( $item, 'wp:post_date' ),
            'author' => self::xpathText( $item, 'dc:creator' ),
            'post_id' => self::xpathInt( $item, 'wp:post_id', 0 ),
            'parent_id' => self::xpathInt( $item, 'wp:post_parent', 0 ),
            'menu_order' => self::xpathInt( $item, 'wp:menu_order', 0 ),
            'meta' => [],
            'beaver_builder_data' => null,
        ];

        // Extract post meta
        foreach ( $item->xpath( 'wp:postmeta' ) as $meta ) {
            $meta_key = self::xpathText( $meta, 'wp:meta_key' );
            $meta_value = self::xpathText( $meta, 'wp:meta_value' );

            // Check for Beaver Builder data
            if ( $meta_key === '_fl_builder_data' || $meta_key === '_fl_builder_draft' ) {
                $parsed['beaver_builder_data'] = $meta_value;
            }

            $parsed['meta'][ $meta_key ] = $meta_value;
        }

        // Extract featured image
        $thumbnail_id = $parsed['meta']['_thumbnail_id'] ?? null;
        if ( $thumbnail_id !== null ) {
            $parsed['featured_image_id'] = (int) $thumbnail_id;
        }

        // For attachments, extract URL
        if ( $post_type === 'attachment' ) {
            $parsed['attachment_url'] = self::xpathText( $item, 'wp:attachment_url' );
        }

        return $parsed;
    }

    /**
     * Extract menus from parsed data
     */
    public static function extractMenus( array $parsed_data ): array {
        if ( empty( $parsed_data['menus'] ) ) {
            return [];
        }

        $menu_items = $parsed_data['menus'];
        $menus = [];

        // Group by menu
        foreach ( $menu_items as $item ) {
            $menu_slug = $item['meta']['_menu_item_menu_item_parent'] ?? 'default';
            
            if ( ! isset( $menus[ $menu_slug ] ) ) {
                $menus[ $menu_slug ] = [
                    'name' => ucfirst( $menu_slug ),
                    'items' => [],
                ];
            }

            $menus[ $menu_slug ]['items'][] = [
                'title' => $item['title'],
                'url' => $item['meta']['_menu_item_url'] ?? '',
                'type' => $item['meta']['_menu_item_type'] ?? 'custom',
                'object_id' => (int) ( $item['meta']['_menu_item_object_id'] ?? 0 ),
                'parent_id' => (int) ( $item['meta']['_menu_item_menu_item_parent'] ?? 0 ),
                'order' => $item['menu_order'],
            ];
        }

        return array_values( $menus );
    }

    private static function xpathText( \SimpleXMLElement $node, string $expression, string $default = '' ): string {
        $matches = $node->xpath( $expression );
        if ( ! is_array( $matches ) || ! isset( $matches[0] ) ) {
            return $default;
        }

        return trim( (string) $matches[0] );
    }

    private static function xpathInt( \SimpleXMLElement $node, string $expression, int $default = 0 ): int {
        $value = self::xpathText( $node, $expression, '' );
        if ( $value === '' || ! is_numeric( $value ) ) {
            return $default;
        }

        return (int) $value;
    }
}
