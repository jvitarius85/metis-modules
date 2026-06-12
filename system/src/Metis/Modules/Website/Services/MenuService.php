<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;

/**
 * Menu Service — manages website navigation menus.
 */
final class MenuService {
    /** @var array<string,string> */
    private static array $canonicalUrlCache = [];

    public static function getAll(): array {
        $rows = self::db()->fetchAll( "SELECT * FROM " . \Metis_Tables::get( 'website_menus' ) . " ORDER BY name ASC" );
        return is_array( $rows ) ? $rows : [];
    }

    public static function getById( int $id ): ?array {
        $row = self::db()->fetchOne( "SELECT * FROM " . \Metis_Tables::get( 'website_menus' ) . " WHERE id = %d", [ $id ] );
        return is_array( $row ) ? $row : null;
    }

    public static function getByLocation( string $location ): ?array {
        $row = self::db()->fetchOne(
            "SELECT * FROM " . \Metis_Tables::get( 'website_menus' ) . " WHERE location = %s AND status = 'active' LIMIT 1",
            [ $location ]
        );
        return is_array( $row ) ? $row : null;
    }

    public static function getItems( array $menu ): array {
        if ( empty( $menu['items_json'] ) ) {
            return [];
        }
        $decoded = json_decode( $menu['items_json'], true );
        return is_array( $decoded ) ? self::normalizeItemsForPersistence( $decoded ) : [];
    }

    /**
     * @param string|array<int,mixed> $items
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeItemsForPersistence( string|array $items ): array {
        if ( is_string( $items ) ) {
            $decoded = json_decode( $items, true );
            $items = is_array( $decoded ) ? $decoded : [];
        }

        $normalized = [];
        $id_map = [];
        $index = 0;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $label = \metis_text_clean( (string) ( $item['label'] ?? $item['title'] ?? '' ) );
            $link_type = \metis_key_clean( (string) ( $item['link_type'] ?? ( ! empty( $item['page_id'] ) ? 'page' : 'custom' ) ) );
            if ( ! in_array( $link_type, [ 'custom', 'page' ], true ) ) {
                $link_type = 'custom';
            }

            $page_id = isset( $item['page_id'] ) ? (int) $item['page_id'] : 0;
            $url = '';
            if ( $link_type === 'page' && $page_id > 0 ) {
                $page = PageService::getById( $page_id );
                if ( $page instanceof \Metis\Modules\Website\Entities\Page && (string) ( $page->status ?? '' ) === 'published' ) {
                    $url = PageService::publishedPathForPage( $page );
                    if ( $label === '' ) {
                        $label = \metis_text_clean( (string) ( $page->title ?? '' ) );
                    }
                }
            }

            if ( $url === '' ) {
                $link_type = 'custom';
                $page_id = 0;
                $url = self::normalizePublicItemUrl( (string) ( $item['url'] ?? '' ) );
            }

            if ( $label === '' || $url === '' ) {
                continue;
            }

            $candidate_id = \metis_key_clean( (string) ( $item['id'] ?? '' ) );
            if ( $candidate_id === '' || isset( $id_map[ $candidate_id ] ) ) {
                $candidate_id = 'menu_item_' . (string) $index;
            }
            $id_map[ $candidate_id ] = true;

            $normalized[] = [
                'id' => $candidate_id,
                'parent_id' => \metis_key_clean( (string) ( $item['parent_id'] ?? '' ) ),
                'label' => $label,
                'url' => $url,
                'target' => (string) ( $item['target'] ?? '' ) === '_blank' ? '_blank' : '',
                'external' => (string) ( $item['target'] ?? '' ) === '_blank' || ! empty( $item['external'] ),
                'as_button' => ! empty( $item['as_button'] ),
                'button_color_key' => self::normalizeButtonColorKey( (string) ( $item['button_color_key'] ?? 'metis_primary' ) ),
                'link_type' => $link_type,
                'page_id' => $page_id,
            ];
            $index++;
        }

        foreach ( $normalized as &$item ) {
            $parent_id = (string) ( $item['parent_id'] ?? '' );
            if ( $parent_id === '' || ! isset( $id_map[ $parent_id ] ) || $parent_id === (string) $item['id'] ) {
                $item['parent_id'] = '';
            }
        }
        unset( $item );

        return $normalized;
    }

    public static function normalizePublicItemUrl( string $url ): string {
        $url = trim( $url );
        if ( $url === '' ) {
            return '';
        }

        if ( isset( self::$canonicalUrlCache[ $url ] ) ) {
            return self::$canonicalUrlCache[ $url ];
        }

        if ( $url === '#' ) {
            self::$canonicalUrlCache[ $url ] = '#';
            return '#';
        }

        if ( function_exists( 'metis_runtime_is_safe_url_value' ) && ! \metis_runtime_is_safe_url_value( $url ) ) {
            self::$canonicalUrlCache[ $url ] = '';
            return '';
        }

        if ( preg_match( '#^(mailto|tel):#i', $url ) === 1 ) {
            self::$canonicalUrlCache[ $url ] = $url;
            return $url;
        }

        if ( preg_match( '#^(https?:)?//#i', $url ) === 1 ) {
            self::$canonicalUrlCache[ $url ] = $url;
            return $url;
        }

        $normalized_path = '/' . ltrim( trim( $url ), '/' );
        if ( $normalized_path === '//' ) {
            $normalized_path = '/';
        }

        $canonical = self::canonicalPublishedPathFromInternalUrl( $normalized_path );
        if ( $canonical !== '' ) {
            self::$canonicalUrlCache[ $url ] = $canonical;
            return $canonical;
        }

        self::$canonicalUrlCache[ $url ] = $normalized_path;
        return $normalized_path;
    }

    private static function normalizeButtonColorKey( string $key ): string {
        $key = \metis_key_clean( $key );
        if ( ! in_array( $key, [ 'metis_primary', 'metis_accent', 'metis_text', 'metis_surface' ], true ) ) {
            return 'metis_primary';
        }
        return $key;
    }

    private static function canonicalPublishedPathFromInternalUrl( string $path ): string {
        $path = trim( $path );
        if ( $path === '' || $path === '/' ) {
            return $path === '/' ? '/' : '';
        }

        $page = PageService::getPublishedByPath( $path );
        if ( $page instanceof \Metis\Modules\Website\Entities\Page ) {
            return PageService::publishedPathForPage( $page );
        }

        $slug = \metis_slug_clean( (string) basename( $path ) );
        if ( $slug === '' ) {
            return '';
        }

        $page = PageService::getBySlug( $slug );
        if ( $page instanceof \Metis\Modules\Website\Entities\Page && (string) ( $page->status ?? '' ) === 'published' ) {
            return PageService::publishedPathForPage( $page );
        }

        $post = PostService::getBySlug( $slug );
        if ( $post instanceof \Metis\Modules\Website\Entities\Post && (string) ( $post->status ?? '' ) === 'published' && PostService::isPubliclyRoutable( $post ) ) {
            return PostService::publicPath( $post );
        }

        return '';
    }

    public static function create( array $data ): int|false {
        $result = self::db()->insert( \Metis_Tables::get( 'website_menus' ), [
            'name'       => $data['name'] ?? 'Untitled Menu',
            'location'   => $data['location'] ?? null,
            'items_json' => $data['items_json'] ?? '[]',
            'status'     => $data['status'] ?? 'active',
            'created_by' => $data['created_by'] ?? self::getCurrentUserId(),
            'updated_by' => $data['updated_by'] ?? self::getCurrentUserId(),
        ] );
        return $result ? (int) self::db()->lastInsertId() : false;
    }

    public static function update( int $id, array $data ): bool {
        $update = [];
        foreach ( [ 'name', 'location', 'items_json', 'status' ] as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $update[ $field ] = $data[ $field ];
            }
        }
        $update['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();
        return (bool) self::db()->update( \Metis_Tables::get( 'website_menus' ), $update, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        return (bool) self::db()->delete( \Metis_Tables::get( 'website_menus' ), [ 'id' => $id ] );
    }

    private static function getCurrentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }
        $uid = metis_current_user_id();
        return $uid > 0 ? $uid : null;
    }

    private static function db(): object {
        return Application::service( 'db' );
    }
}
