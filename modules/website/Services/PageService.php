<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;
use Metis\Modules\Website\Entities\Page;

/**
 * Page Service — CRUD for website pages.
 */
final class PageService {
    /** @var array<string,bool>|null */
    private static ?array $columnMap = null;
    /** @var array<int,string> */
    private static array $publishedPathCache = [];

    public static function getById( int $id ): ?Page {
        $db    = self::db();
        $table = \Metis_Tables::get( 'website_pages' );
        $row   = $db->fetchOne( "SELECT * FROM {$table} WHERE id = %d", [ $id ] );
        return is_array( $row ) ? Page::fromRow( $row ) : null;
    }

    public static function getBySlug( string $slug ): ?Page {
        $db    = self::db();
        $table = \Metis_Tables::get( 'website_pages' );
        $row   = $db->fetchOne( "SELECT * FROM {$table} WHERE slug = %s", [ $slug ] );
        return is_array( $row ) ? Page::fromRow( $row ) : null;
    }

    public static function getByCode( string $code ): ?Page {
        $db    = self::db();
        $table = \Metis_Tables::get( 'website_pages' );
        $row   = $db->fetchOne( "SELECT * FROM {$table} WHERE page_code = %s", [ $code ] );
        return is_array( $row ) ? Page::fromRow( $row ) : null;
    }

    public static function getPublishedByPath( string $path ): ?Page {
        $normalized = '/' . trim( trim( $path ), '/' );
        if ( $normalized === '//' ) {
            $normalized = '/';
        }
        if ( $normalized === '/' ) {
            return null;
        }

        $segments = array_values( array_filter( explode( '/', trim( $normalized, '/' ) ), static fn ( string $segment ): bool => $segment !== '' ) );
        if ( $segments === [] ) {
            return null;
        }

        $leaf_slug = metis_slug_clean( (string) end( $segments ) );
        if ( $leaf_slug === '' ) {
            return null;
        }

        $candidate = self::getBySlug( $leaf_slug );
        if ( $candidate === null || $candidate->status !== 'published' ) {
            return null;
        }

        $candidate_path = self::publishedPathById( (int) $candidate->id );
        return $candidate_path !== '' && trim( $candidate_path, '/' ) === trim( $normalized, '/' ) ? $candidate : null;
    }

    public static function getAll( array $filters = [] ): array {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'website_pages' );
        $where  = [];
        $params = [];
        $limit  = isset( $filters['limit'] ) ? (int) $filters['limit'] : 200;
        $offset = isset( $filters['offset'] ) ? (int) $filters['offset'] : 0;
        $fetch_all = ! empty( $filters['fetch_all'] );

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['parent_id'] ) ) {
            $where[]  = 'parent_id = %d';
            $params[] = $filters['parent_id'];
        }

        $where_clause = $where !== [] ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $sql = "SELECT * FROM {$table}{$where_clause} ORDER BY menu_order ASC, title ASC";

        if ( ! $fetch_all ) {
            $limit = max( 1, min( 1000, $limit ) );
            $offset = max( 0, $offset );
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = $offset;
        }

        $rows = $db->fetchAll( $sql, $params );
        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map( [ Page::class, 'fromRow' ], $rows );
    }

    public static function countAll( array $filters = [] ): int {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'website_pages' );
        $where  = [];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['parent_id'] ) ) {
            $where[]  = 'parent_id = %d';
            $params[] = $filters['parent_id'];
        }

        $where_clause = $where !== [] ? ' WHERE ' . implode( ' AND ', $where ) : '';
        return (int) $db->scalar( "SELECT COUNT(*) FROM {$table}{$where_clause}", $params );
    }

    public static function getPublished( array $options = [] ): array {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'website_pages' );
        $limit  = max( 1, (int) ( $options['limit'] ?? 10 ) );
        $offset = max( 0, (int) ( $options['offset'] ?? 0 ) );

        $rows = $db->fetchAll(
            "SELECT * FROM {$table} WHERE status = 'published' ORDER BY menu_order ASC, title ASC LIMIT %d OFFSET %d",
            [ $limit, $offset ]
        );

        return is_array( $rows ) ? array_map( [ Page::class, 'fromRow' ], $rows ) : [];
    }

    public static function create( array $data ): ?Page {
        $db        = self::db();
        $table     = \Metis_Tables::get( 'website_pages' );

        $insert = [
            'title'             => $data['title'] ?? 'Untitled Page',
            'slug'              => self::generateSlug( $data['slug'] ?? $data['title'] ?? 'untitled-page' ),
            'status'            => $data['status'] ?? 'draft',
            'layout_json'       => $data['layout_json'] ?? null,
            'draft_layout_json' => $data['draft_layout_json'] ?? null,
            'published_at'      => $data['published_at'] ?? null,
            'seo_meta_json'     => $data['seo_meta_json'] ?? null,
            'template_key'      => $data['template_key'] ?? null,
            'parent_id'         => $data['parent_id'] ?? null,
            'menu_order'        => $data['menu_order'] ?? 0,
            'created_by'        => $data['created_by'] ?? self::getCurrentUserId(),
            'updated_by'        => $data['updated_by'] ?? self::getCurrentUserId(),
        ];
        if ( self::hasColumn( 'page_type' ) ) {
            $insert['page_type'] = $data['page_type'] ?? 'page';
        }
        if ( function_exists( 'metis_entity_id_service' ) ) {
            $insert = \metis_entity_id_service()->assignForInsert( 'website_page', $insert );
        } else {
            $insert['page_code'] = \metis_generate_code( 'WPG', $table, 'page_code' );
        }

        $result = $db->insert( $table, $insert );
        if ( ! $result ) {
            return null;
        }

        $new_id = (int) $db->lastInsertId();
        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'website_page', $new_id, (string) ( $insert['page_code'] ?? '' ) );
        }
        self::$publishedPathCache = [];

        return self::getById( $new_id );
    }

    public static function update( int $id, array $data ): bool {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'website_pages' );
        $update = [];
        $existing = self::getById( $id );
        $allow_status_downgrade = ! empty( $data['allow_status_downgrade'] );
        $track_slug_redirect = array_key_exists( 'slug', $data )
            && $existing !== null
            && (string) ( $existing->status ?? '' ) === 'published';
        $old_published_path = $track_slug_redirect && $existing !== null
            ? self::publishedPathForPage( $existing )
            : '';

        $fields = [ 'title', 'status', 'layout_json', 'draft_layout_json',
                    'published_layout_json', 'seo_meta_json', 'parent_id',
                    'menu_order', 'published_at', 'template_key' ];
        if ( self::hasColumn( 'page_type' ) ) {
            $fields[] = 'page_type';
        }

        foreach ( $fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $update[ $field ] = $data[ $field ];
            }
        }

        if (
            ! $allow_status_downgrade
            && isset( $update['status'] )
            && (string) $update['status'] === 'draft'
            && $existing !== null
            && (
                trim( (string) ( $existing->published_layout_json ?? '' ) ) !== ''
                || trim( (string) ( $existing->published_at ?? '' ) ) !== ''
            )
        ) {
            $update['status'] = 'published';
        }

        if ( array_key_exists( 'slug', $data ) ) {
            $update['slug'] = self::generateSlug( $data['slug'], $id );
        }
        if ( array_key_exists( 'parent_id', $update ) && (int) $update['parent_id'] === $id ) {
            $update['parent_id'] = null;
        }

        $update['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();

        if ( empty( $update ) ) {
            return false;
        }

        $result = $db->update( $table, $update, [ 'id' => $id ] );
        self::$publishedPathCache = [];
        if ( $result === false ) {
            return false;
        }

        if ( $track_slug_redirect && $old_published_path !== '' ) {
            $updated = self::getById( $id );
            if ( $updated !== null && (string) ( $updated->status ?? '' ) === 'published' ) {
                $new_published_path = self::publishedPathForPage( $updated );
                if ( $new_published_path !== '' && $new_published_path !== $old_published_path ) {
                    RedirectService::createSlugChangeRedirect( $old_published_path, $new_published_path, 'page' );
                }
            }
        }

        return true;
    }

    public static function publish( int $id ): bool {
        $page = self::getById( $id );
        if ( $page === null ) {
            return false;
        }

        return self::update( $id, [
            'status'                => 'published',
            'published_layout_json' => $page->draft_layout_json ?? $page->layout_json,
            'published_at'          => gmdate( 'Y-m-d H:i:s' ),
        ] );
    }

    public static function unpublish( int $id ): bool {
        return self::update( $id, [
            'status' => 'draft',
            'allow_status_downgrade' => true,
        ] );
    }

    public static function delete( int $id ): bool {
        $deleted = (bool) self::db()->delete( \Metis_Tables::get( 'website_pages' ), [ 'id' => $id ] );
        if ( $deleted ) {
            HomepageService::clearHomepageIfMatches( $id );
            unset( self::$publishedPathCache[ $id ] );
        }

        return $deleted;
    }

    public static function publishedPathById( int $id ): string {
        if ( $id < 1 ) {
            return '';
        }
        if ( isset( self::$publishedPathCache[ $id ] ) ) {
            return self::$publishedPathCache[ $id ];
        }

        $page = self::getById( $id );
        if ( $page === null || $page->status !== 'published' ) {
            self::$publishedPathCache[ $id ] = '';
            return '';
        }

        $path = self::publishedPathForPage( $page );
        self::$publishedPathCache[ $id ] = $path;
        return $path;
    }

    public static function publishedPathForPage( Page $page ): string {
        if ( $page->status !== 'published' ) {
            return '';
        }

        if ( metis_key_clean( (string) ( $page->page_type ?? '' ) ) === 'homepage' ) {
            return '/';
        }

        $slug = metis_slug_clean( (string) ( $page->slug ?? '' ) );
        if ( $slug === '' ) {
            return '';
        }

        $segments = [ $slug ];
        $seen = [ (int) ( $page->id ?? 0 ) => true ];
        $parent_id = (int) ( $page->parent_id ?? 0 );
        $depth = 0;
        while ( $parent_id > 0 && $depth < 16 ) {
            if ( isset( $seen[ $parent_id ] ) ) {
                return '';
            }
            $parent = self::getById( $parent_id );
            if ( $parent === null || $parent->status !== 'published' ) {
                return '';
            }
            $parent_slug = metis_slug_clean( (string) ( $parent->slug ?? '' ) );
            if ( $parent_slug === '' ) {
                return '';
            }
            $segments[] = $parent_slug;
            $seen[ $parent_id ] = true;
            $parent_id = (int) ( $parent->parent_id ?? 0 );
            $depth++;
        }

        $segments = array_reverse( $segments );
        return '/' . implode( '/', $segments );
    }

    private static function generateSlug( string $slug, ?int $exclude_id = null ): string {
        $slug          = metis_slug_clean( $slug );
        $original_slug = $slug;
        $counter       = 1;
        $db            = self::db();
        $table         = \Metis_Tables::get( 'website_pages' );

        while ( true ) {
            $where  = 'slug = %s';
            $params = [ $slug ];

            if ( $exclude_id !== null ) {
                $where   .= ' AND id != %d';
                $params[] = $exclude_id;
            }

            $exists = $db->scalar( "SELECT id FROM {$table} WHERE {$where}", $params );
            if ( $exists === null ) {
                break;
            }

            $slug = $original_slug . '-' . $counter++;
        }

        return $slug;
    }

    private static function getCurrentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }
        $uid = metis_current_user_id();
        return $uid > 0 ? $uid : null;
    }

    private static function db(): object {
        return function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : Application::service( 'db' );
    }

    private static function hasColumn( string $column ): bool {
        if ( self::$columnMap === null ) {
            $rows = self::db()->fetchAll( 'SHOW COLUMNS FROM ' . \Metis_Tables::get( 'website_pages' ) );
            self::$columnMap = [];
            foreach ( (array) $rows as $row ) {
                $field = isset( $row['Field'] ) ? (string) $row['Field'] : '';
                if ( $field !== '' ) {
                    self::$columnMap[ $field ] = true;
                }
            }
        }

        return isset( self::$columnMap[ $column ] );
    }
}
