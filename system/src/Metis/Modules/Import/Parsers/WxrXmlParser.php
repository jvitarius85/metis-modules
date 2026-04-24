<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Parsers;

/**
 * WXR XML Parser
 *
 * Parses WXR export files.
 * Extracts pages, posts, media, and metadata.
 * Autoloaded via PSR-4 from src/Metis/.
 */
final class WxrXmlParser {

    public static function parse( string $file_path ): array {
        if ( ! file_exists( $file_path ) ) {
            return [ 'success' => false, 'error' => 'File not found' ];
        }

        $xml_content = file_get_contents( $file_path );
        if ( $xml_content === false ) {
            return [ 'success' => false, 'error' => 'Failed to read file' ];
        }

        libxml_use_internal_errors( true );

        try {
            $xml = simplexml_load_string( $xml_content );
            if ( $xml === false ) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                return [ 'success' => false, 'error' => 'Invalid XML: ' . ( $errors[0]->message ?? 'Unknown error' ) ];
            }

            $namespaces = $xml->getNamespaces( true );

            $result = [
                'success'   => true,
                'pages'     => [],
                'posts'     => [],
                'media'     => [],
                'menus'     => [],
                'site_info' => self::extractSiteInfo( $xml ),
                'stats'     => [ 'total_items' => 0, 'pages' => 0, 'posts' => 0, 'media' => 0, 'menus' => 0 ],
            ];

            foreach ( $xml->channel->item as $item ) {
                $parsed_item = self::parseItem( $item, $namespaces );
                if ( $parsed_item === null ) continue;

                $result['stats']['total_items']++;
                switch ( $parsed_item['type'] ) {
                    case 'page':         $result['pages'][] = $parsed_item; $result['stats']['pages']++;  break;
                    case 'post':         $result['posts'][] = $parsed_item; $result['stats']['posts']++;  break;
                    case 'attachment':   $result['media'][] = $parsed_item; $result['stats']['media']++;  break;
                    case 'nav_menu_item':$result['menus'][] = $parsed_item; $result['stats']['menus']++;  break;
                }
            }

            return $result;

        } catch ( \Exception $e ) {
            return [ 'success' => false, 'error' => 'Unable to parse XML export.' ];
        } finally {
            libxml_use_internal_errors( false );
        }
    }

    private static function extractSiteInfo( \SimpleXMLElement $xml ): array {
        return [
            'title'       => (string) $xml->channel->title,
            'link'        => (string) $xml->channel->link,
            'description' => (string) $xml->channel->description,
            'language'    => (string) $xml->channel->language,
        ];
    }

    private static function parseItem( \SimpleXMLElement $item, array $namespaces ): ?array {
        $wxr_ns = $namespaces['wp'] ?? 'http://wordpress.org/export/1.2/';
        $content_ns = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerpt_ns = $namespaces['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';

        $item->registerXPathNamespace( 'wp', $wxr_ns );
        $item->registerXPathNamespace( 'content', $content_ns );
        $item->registerXPathNamespace( 'excerpt', $excerpt_ns );

        $post_type = (string) ( $item->xpath( 'wp:post_type' )[0] ?? '' );
        $status    = (string) ( $item->xpath( 'wp:status' )[0] ?? '' );

        if ( in_array( $status, [ 'auto-draft', 'inherit' ], true ) ) {
            return null;
        }

        $parsed = [
            'type'                => $post_type,
            'title'               => (string) $item->title,
            'slug'                => (string) ( $item->xpath( 'wp:post_name' )[0] ?? '' ),
            'content'             => (string) ( $item->xpath( 'content:encoded' )[0] ?? '' ),
            'excerpt'             => (string) ( $item->xpath( 'excerpt:encoded' )[0] ?? '' ),
            'status'              => $status,
            'publish_date'        => (string) ( $item->xpath( 'wp:post_date' )[0] ?? '' ),
            'post_id'             => (int) ( $item->xpath( 'wp:post_id' )[0] ?? 0 ),
            'parent_id'           => (int) ( $item->xpath( 'wp:post_parent' )[0] ?? 0 ),
            'menu_order'          => (int) ( $item->xpath( 'wp:menu_order' )[0] ?? 0 ),
            'meta'                => [],
            'beaver_builder_data' => null,
        ];

        foreach ( $item->xpath( 'wp:postmeta' ) as $meta ) {
            $meta_key   = (string) ( $meta->xpath( 'wp:meta_key' )[0] ?? '' );
            $meta_value = (string) ( $meta->xpath( 'wp:meta_value' )[0] ?? '' );
            if ( $meta_key === '_fl_builder_data' || $meta_key === '_fl_builder_draft' ) {
                $parsed['beaver_builder_data'] = $meta_value;
            }
            $parsed['meta'][ $meta_key ] = $meta_value;
        }

        if ( isset( $parsed['meta']['_thumbnail_id'] ) ) {
            $parsed['featured_image_id'] = (int) $parsed['meta']['_thumbnail_id'];
        }
        if ( $post_type === 'attachment' ) {
            $parsed['attachment_url'] = (string) ( $item->xpath( 'wp:attachment_url' )[0] ?? '' );
        }

        return $parsed;
    }

    public static function extractMenus( array $parsed_data ): array {
        if ( empty( $parsed_data['menus'] ) ) return [];

        $menus = [];
        foreach ( $parsed_data['menus'] as $item ) {
            $slug = $item['meta']['_menu_item_menu_item_parent'] ?? 'default';
            if ( ! isset( $menus[ $slug ] ) ) {
                $menus[ $slug ] = [ 'name' => ucfirst( (string) $slug ), 'items' => [] ];
            }
            $menus[ $slug ]['items'][] = [
                'title'     => $item['title'],
                'url'       => $item['meta']['_menu_item_url'] ?? '',
                'type'      => $item['meta']['_menu_item_type'] ?? 'custom',
                'object_id' => (int) ( $item['meta']['_menu_item_object_id'] ?? 0 ),
                'parent_id' => (int) ( $item['meta']['_menu_item_menu_item_parent'] ?? 0 ),
                'order'     => $item['menu_order'],
            ];
        }

        return array_values( $menus );
    }
}
