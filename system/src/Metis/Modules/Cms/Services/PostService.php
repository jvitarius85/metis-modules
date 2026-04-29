<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Services;

use Metis\Core\Application;
use Metis\Modules\Cms\Entities\Post;

/**
 * Post Service — CRUD for blog posts.
 */
final class PostService {
    /** @var array<string,bool>|null */
    private static ?array $columnMap = null;

    public static function getById( int $id ): ?Post {
        $db    = self::db();
        $table = \Metis_Tables::get( 'cms_posts' );
        $row   = $db->fetchOne( "SELECT * FROM {$table} WHERE id = %d", [ $id ] );
        return is_array( $row ) ? self::hydrateCategories( Post::fromRow( $row ) ) : null;
    }

    public static function getBySlug( string $slug ): ?Post {
        $db    = self::db();
        $table = \Metis_Tables::get( 'cms_posts' );
        $row   = $db->fetchOne( "SELECT * FROM {$table} WHERE slug = %s", [ $slug ] );
        return is_array( $row ) ? self::hydrateCategories( Post::fromRow( $row ) ) : null;
    }

    public static function getByCode( string $code ): ?Post {
        $db    = self::db();
        $table = \Metis_Tables::get( 'cms_posts' );
        $row   = $db->fetchOne( "SELECT * FROM {$table} WHERE post_code = %s", [ $code ] );
        return is_array( $row ) ? self::hydrateCategories( Post::fromRow( $row ) ) : null;
    }

    public static function getPublished( array $options = [] ): array {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'cms_posts' );
        $limit  = max( 1, min( 200, (int) ( $options['limit'] ?? 10 ) ) );
        $offset = max( 0, (int) ( $options['offset'] ?? 0 ) );
        $where = [ 'status = %s' ];
        $params = [ 'published' ];

        if ( self::hasColumn( 'parent_page_id' ) && array_key_exists( 'parent_page_id', $options ) ) {
            $parent_page_id = (int) $options['parent_page_id'];
            if ( $parent_page_id > 0 ) {
                $where[] = 'parent_page_id = %d';
                $params[] = $parent_page_id;
            } else {
                $where[] = '(parent_page_id IS NULL OR parent_page_id = 0)';
            }
        }
        if ( self::hasColumn( 'post_category_id' ) && array_key_exists( 'post_category_id', $options ) ) {
            $post_category_id = (int) $options['post_category_id'];
            if ( $post_category_id > 0 ) {
                $where[] = 'post_category_id = %d';
                $params[] = $post_category_id;
            } else {
                $where[] = '(post_category_id IS NULL OR post_category_id = 0)';
            }
        }
        $post_category_ids = self::normalizeCategoryIds( $options['post_category_ids'] ?? [] );
        if ( $post_category_ids !== [] ) {
            $map_table = self::mapTable();
            $placeholders = implode( ',', array_fill( 0, count( $post_category_ids ), '%d' ) );
            $where[] = "EXISTS (SELECT 1 FROM {$map_table} pcm WHERE pcm.post_id = {$table}.id AND pcm.category_id IN ({$placeholders}))";
            $params = array_merge( $params, $post_category_ids );
        }

        $rows = $db->fetchAll(
            "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY publish_date DESC, created_at DESC LIMIT %d OFFSET %d',
            array_merge( $params, [ $limit, $offset ] )
        );

        return is_array( $rows ) ? array_map( [ self::class, 'postFromRow' ], $rows ) : [];
    }

    public static function getPublishedBySlug( string $slug, ?int $parent_page_id = null ): ?Post {
        $db    = self::db();
        $table = \Metis_Tables::get( 'cms_posts' );
        $where = [ 'slug = %s', "status = 'published'" ];
        $params = [ $slug ];

        if ( self::hasColumn( 'parent_page_id' ) ) {
            if ( $parent_page_id !== null && $parent_page_id > 0 ) {
                $where[] = 'parent_page_id = %d';
                $params[] = $parent_page_id;
            } elseif ( $parent_page_id !== null && $parent_page_id <= 0 ) {
                $where[] = '(parent_page_id IS NULL OR parent_page_id = 0)';
            }
        }

        $row = $db->fetchOne(
            'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1',
            $params
        );

        return is_array( $row ) ? self::hydrateCategories( Post::fromRow( $row ) ) : null;
    }

    public static function getAll( array $filters = [] ): array {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'cms_posts' );
        $where  = [];
        $params = [];
        $limit  = isset( $filters['limit'] ) ? (int) $filters['limit'] : 200;
        $offset = isset( $filters['offset'] ) ? (int) $filters['offset'] : 0;
        $fetch_all = ! empty( $filters['fetch_all'] );

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }
        if ( self::hasColumn( 'parent_page_id' ) && array_key_exists( 'parent_page_id', $filters ) ) {
            $parent_page_id = (int) $filters['parent_page_id'];
            if ( $parent_page_id > 0 ) {
                $where[] = 'parent_page_id = %d';
                $params[] = $parent_page_id;
            } else {
                $where[] = '(parent_page_id IS NULL OR parent_page_id = 0)';
            }
        }
        if ( self::hasColumn( 'post_category_id' ) && array_key_exists( 'post_category_id', $filters ) ) {
            $post_category_id = (int) $filters['post_category_id'];
            if ( $post_category_id > 0 ) {
                $where[] = 'post_category_id = %d';
                $params[] = $post_category_id;
            } else {
                $where[] = '(post_category_id IS NULL OR post_category_id = 0)';
            }
        }
        $post_category_ids = self::normalizeCategoryIds( $filters['post_category_ids'] ?? [] );
        if ( $post_category_ids !== [] ) {
            $map_table = self::mapTable();
            $placeholders = implode( ',', array_fill( 0, count( $post_category_ids ), '%d' ) );
            $where[] = "EXISTS (SELECT 1 FROM {$map_table} pcm WHERE pcm.post_id = {$table}.id AND pcm.category_id IN ({$placeholders}))";
            $params = array_merge( $params, $post_category_ids );
        }

        $where_clause = $where !== [] ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $sql          = "SELECT * FROM {$table}{$where_clause} ORDER BY publish_date DESC, created_at DESC";

        if ( ! $fetch_all ) {
            $limit = max( 1, min( 1000, $limit ) );
            $offset = max( 0, $offset );
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = $offset;
        }

        $rows = $db->fetchAll( $sql, $params );

        return is_array( $rows ) ? array_map( [ self::class, 'postFromRow' ], $rows ) : [];
    }

    public static function countAll( array $filters = [] ): int {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'cms_posts' );
        $where  = [];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }
        if ( self::hasColumn( 'parent_page_id' ) && array_key_exists( 'parent_page_id', $filters ) ) {
            $parent_page_id = (int) $filters['parent_page_id'];
            if ( $parent_page_id > 0 ) {
                $where[] = 'parent_page_id = %d';
                $params[] = $parent_page_id;
            } else {
                $where[] = '(parent_page_id IS NULL OR parent_page_id = 0)';
            }
        }
        if ( self::hasColumn( 'post_category_id' ) && array_key_exists( 'post_category_id', $filters ) ) {
            $post_category_id = (int) $filters['post_category_id'];
            if ( $post_category_id > 0 ) {
                $where[] = 'post_category_id = %d';
                $params[] = $post_category_id;
            } else {
                $where[] = '(post_category_id IS NULL OR post_category_id = 0)';
            }
        }
        $post_category_ids = self::normalizeCategoryIds( $filters['post_category_ids'] ?? [] );
        if ( $post_category_ids !== [] ) {
            $map_table = self::mapTable();
            $placeholders = implode( ',', array_fill( 0, count( $post_category_ids ), '%d' ) );
            $where[] = "EXISTS (SELECT 1 FROM {$map_table} pcm WHERE pcm.post_id = {$table}.id AND pcm.category_id IN ({$placeholders}))";
            $params = array_merge( $params, $post_category_ids );
        }

        $where_clause = $where !== [] ? ' WHERE ' . implode( ' AND ', $where ) : '';
        return (int) $db->scalar( "SELECT COUNT(*) FROM {$table}{$where_clause}", $params );
    }

    public static function create( array $data ): ?Post {
        $db        = self::db();
        $table     = \Metis_Tables::get( 'cms_posts' );

        $insert = [
            'title'              => $data['title'] ?? 'Untitled Post',
            'slug'               => self::generateSlug( $data['slug'] ?? $data['title'] ?? 'untitled-post' ),
            'excerpt'            => $data['excerpt'] ?? null,
            'content_json'       => $data['content_json'] ?? null,
            'draft_content_json' => $data['draft_content_json'] ?? null,
            'status'             => $data['status'] ?? 'draft',
            'publish_date'       => $data['publish_date'] ?? null,
            'seo_meta_json'      => $data['seo_meta_json'] ?? null,
            'author_id'          => $data['author_id'] ?? self::getCurrentUserId(),
            'created_by'         => $data['created_by'] ?? self::getCurrentUserId(),
            'updated_by'         => $data['updated_by'] ?? self::getCurrentUserId(),
        ];
        if ( self::hasColumn( 'template_key' ) ) {
            $insert['template_key'] = $data['template_key'] ?? null;
        }
        if ( self::hasColumn( 'page_type' ) ) {
            $insert['page_type'] = $data['page_type'] ?? 'post';
        }
        if ( self::hasColumn( 'content_format' ) ) {
            $content_format = metis_key_clean( (string) ( $data['content_format'] ?? 'standard' ) );
            $insert['content_format'] = in_array( $content_format, [ 'standard', 'transcript' ], true ) ? $content_format : 'standard';
        }
        if ( self::hasColumn( 'post_category_id' ) ) {
            $category_ids = self::normalizeCategoryIds( $data['post_category_ids'] ?? [] );
            $post_category_id = $category_ids !== []
                ? (int) $category_ids[0]
                : ( isset( $data['post_category_id'] ) ? (int) $data['post_category_id'] : 0 );
            $insert['post_category_id'] = $post_category_id > 0 ? $post_category_id : null;
        }
        if ( self::hasColumn( 'parent_page_id' ) ) {
            $parent_page_id = isset( $data['parent_page_id'] ) ? (int) $data['parent_page_id'] : 0;
            $insert['parent_page_id'] = $parent_page_id > 0 ? $parent_page_id : null;
        }
        if ( self::hasColumn( 'featured_image_id' ) ) {
            $featured_image_id = isset( $data['featured_image_id'] ) ? (int) $data['featured_image_id'] : 0;
            $insert['featured_image_id'] = $featured_image_id > 0 ? $featured_image_id : null;
        }
        if ( self::hasColumn( 'featured_image_caption' ) ) {
            $featured_image_caption = isset( $data['featured_image_caption'] ) ? trim( (string) $data['featured_image_caption'] ) : '';
            $insert['featured_image_caption'] = $featured_image_caption !== '' ? $featured_image_caption : null;
        }
        if ( function_exists( 'metis_entity_id_service' ) ) {
            $insert = \metis_entity_id_service()->assignForInsert( 'cms_post', $insert );
        } else {
            $insert['post_code'] = \metis_generate_code( 'CMSP', $table, 'post_code' );
        }

        $result = $db->insert( $table, $insert );
        if ( ! $result ) {
            return null;
        }

        $new_id = (int) $db->lastInsertId();
        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'cms_post', $new_id, (string) ( $insert['post_code'] ?? '' ) );
        }

        if ( array_key_exists( 'post_category_ids', $data ) ) {
            PostCategoryService::syncPostCategories( $new_id, self::normalizeCategoryIds( $data['post_category_ids'] ?? [] ) );
        } elseif ( ! empty( $insert['post_category_id'] ) ) {
            PostCategoryService::syncPostCategories( $new_id, [ (int) $insert['post_category_id'] ] );
        }

        return self::getById( $new_id );
    }

    public static function update( int $id, array $data ): bool {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'cms_posts' );
        $update = [];
        $existing = self::getById( $id );
        $track_public_path_redirect = $existing !== null
            && (string) ( $existing->status ?? '' ) === 'published';
        $old_public_path = $track_public_path_redirect && $existing !== null
            ? self::publicPath( $existing )
            : '';

        $fields = [ 'title', 'excerpt', 'content_json', 'draft_content_json',
                    'published_content_json', 'status', 'publish_date', 'seo_meta_json' ];
        if ( self::hasColumn( 'template_key' ) ) {
            $fields[] = 'template_key';
        }
        if ( self::hasColumn( 'page_type' ) ) {
            $fields[] = 'page_type';
        }
        if ( self::hasColumn( 'content_format' ) ) {
            $fields[] = 'content_format';
        }
        if ( self::hasColumn( 'post_category_id' ) ) {
            $fields[] = 'post_category_id';
        }
        if ( self::hasColumn( 'parent_page_id' ) ) {
            $fields[] = 'parent_page_id';
        }
        if ( self::hasColumn( 'featured_image_id' ) ) {
            $fields[] = 'featured_image_id';
        }
        if ( self::hasColumn( 'featured_image_caption' ) ) {
            $fields[] = 'featured_image_caption';
        }

        foreach ( $fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $update[ $field ] = $data[ $field ];
            }
        }

        if ( array_key_exists( 'slug', $data ) ) {
            $update['slug'] = self::generateSlug( $data['slug'], $id );
        }

        if ( array_key_exists( 'post_category_ids', $data ) && self::hasColumn( 'post_category_id' ) ) {
            $category_ids = self::normalizeCategoryIds( $data['post_category_ids'] ?? [] );
            $update['post_category_id'] = $category_ids !== [] ? (int) $category_ids[0] : null;
        }

        $update['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();

        if ( empty( $update ) ) {
            return false;
        }

        $result = $db->update( $table, $update, [ 'id' => $id ] );
        if ( $result === false ) {
            return false;
        }

        if ( array_key_exists( 'post_category_ids', $data ) ) {
            PostCategoryService::syncPostCategories( $id, self::normalizeCategoryIds( $data['post_category_ids'] ?? [] ) );
        }

        if ( $track_public_path_redirect && $old_public_path !== '' ) {
            $updated = self::getById( $id );
            if ( $updated !== null && (string) ( $updated->status ?? '' ) === 'published' ) {
                $new_public_path = self::publicPath( $updated );
                if ( $new_public_path !== '' && $new_public_path !== $old_public_path ) {
                    RedirectService::createSlugChangeRedirect( $old_public_path, $new_public_path, 'post' );
                }
            }
        }

        return true;
    }

    public static function publicPath( Post $post ): string {
        return self::publicPathForRoute( $post );
    }

    /**
     * @return array<int,string>
     */
    public static function publicPaths( Post $post ): array {
        $slug = ltrim( (string) ( $post->slug ?? '' ), '/' );
        if ( $slug === '' ) {
            return [];
        }

        $year = self::routeYearForPost( $post );
        if ( $year === '' ) {
            return [];
        }

        $category_ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $post->post_category_ids ?? null ) ? $post->post_category_ids : [] ) ) ) );
        if ( $category_ids === [] && isset( $post->post_category_id ) && (int) $post->post_category_id > 0 ) {
            $category_ids = [ (int) $post->post_category_id ];
        }

        $paths = [];
        foreach ( $category_ids as $category_id ) {
            $segments = self::routeCategorySegmentsForCategoryId( $category_id );
            if ( $segments === [] ) {
                continue;
            }
            $paths[] = '/' . implode( '/', array_merge( $segments, [ $year, $slug ] ) );
        }

        return array_values( array_unique( array_filter( $paths ) ) );
    }

    public static function publicPathForRoute( Post $post, ?string $category_slug_override = null, ?string $year_override = null ): string {
        $slug = ltrim( (string) ( $post->slug ?? '' ), '/' );
        if ( $slug === '' ) {
            return '';
        }

        $category_segments = $category_slug_override !== null
            ? [ metis_slug_clean( $category_slug_override ) ]
            : self::routeCategorySegmentsForPost( $post );
        $category_segments = array_values( array_filter( array_map( 'metis_slug_clean', $category_segments ), static fn( string $segment ): bool => $segment !== '' ) );
        if ( $category_segments === [] ) {
            return '';
        }

        $year = $year_override !== null
            ? self::normalizeRouteYear( $year_override )
            : self::routeYearForPost( $post );
        if ( $year === '' ) {
            return '';
        }

        return '/' . implode( '/', array_merge( $category_segments, [ $year, $slug ] ) );
    }

    public static function routeYearForPost( Post $post ): string {
        $timestamp = self::routeTimestampForPost( $post );
        if ( $timestamp < 1 ) {
            return '';
        }

        return gmdate( 'Y', $timestamp );
    }

    public static function legacyParentPagePath( Post $post ): string {
        $slug = ltrim( (string) ( $post->slug ?? '' ), '/' );
        if ( $slug === '' ) {
            return '';
        }

        $parent_page_id = isset( $post->parent_page_id ) ? (int) $post->parent_page_id : 0;
        if ( $parent_page_id > 0 && class_exists( PageService::class ) && method_exists( PageService::class, 'publishedPathById' ) ) {
            $parent_path = (string) PageService::publishedPathById( $parent_page_id );
            if ( $parent_path !== '' ) {
                return rtrim( $parent_path, '/' ) . '/' . $slug;
            }
        }

        return '';
    }

    public static function isPubliclyRoutable( Post $post ): bool {
        return self::publicPath( $post ) !== '';
    }

    public static function publish( int $id ): bool {
        $post = self::getById( $id );
        if ( $post === null ) {
            return false;
        }
        if ( ! self::isReadyForPublicRoute( $post ) ) {
            return false;
        }

        return self::update( $id, [
            'status'                 => 'published',
            'published_content_json' => $post->draft_content_json ?? $post->content_json,
            'publish_date'           => trim( (string) ( $post->publish_date ?? '' ) ) !== '' ? $post->publish_date : gmdate( 'Y-m-d H:i:s' ),
        ] );
    }

    public static function delete( int $id ): bool {
        return (bool) self::db()->delete( \Metis_Tables::get( 'cms_posts' ), [ 'id' => $id ] );
    }

    private static function generateSlug( string $slug, ?int $exclude_id = null ): string {
        $slug          = metis_slug_clean( $slug );
        $original_slug = $slug;
        $counter       = 1;
        $db            = self::db();
        $table         = \Metis_Tables::get( 'cms_posts' );

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

    public static function isReadyForPublicRoute( Post $post ): bool {
        return self::publicPath( $post ) !== '';
    }

    /**
     * @return array<int,string>
     */
    private static function routeCategorySegmentsForPost( Post $post ): array {
        $category_id = isset( $post->post_category_id ) ? (int) $post->post_category_id : 0;
        return self::routeCategorySegmentsForCategoryId( $category_id );
    }

    /**
     * @return array<int,string>
     */
    private static function routeCategorySegmentsForCategoryId( int $category_id ): array {
        if ( $category_id < 1 || ! class_exists( PostCategoryService::class ) ) {
            return [];
        }

        $category = PostCategoryService::getById( $category_id );
        if ( ! is_array( $category ) ) {
            return [];
        }

        $child_slug = metis_slug_clean( (string) ( $category['slug'] ?? '' ) );
        if ( $child_slug === '' ) {
            $child_slug = metis_slug_clean( (string) ( $category['name'] ?? '' ) );
        }
        if ( $child_slug === '' ) {
            return [];
        }

        $parent_id = (int) ( $category['parent_id'] ?? 0 );
        if ( $parent_id < 1 ) {
            return [ $child_slug ];
        }

        $parent = PostCategoryService::getById( $parent_id );
        if ( ! is_array( $parent ) ) {
            return [ $child_slug ];
        }

        $parent_slug = metis_slug_clean( (string) ( $parent['slug'] ?? '' ) );
        if ( $parent_slug === '' ) {
            $parent_slug = metis_slug_clean( (string) ( $parent['name'] ?? '' ) );
        }
        if ( $parent_slug === '' ) {
            return [ $child_slug ];
        }

        return [ $parent_slug, $child_slug ];
    }

    private static function routeTimestampForPost( Post $post ): int {
        foreach ( [ 'publish_date', 'created_at', 'updated_at' ] as $field ) {
            $raw = trim( (string) ( $post->{$field} ?? '' ) );
            if ( $raw === '' ) {
                continue;
            }

            $timestamp = strtotime( $raw );
            if ( $timestamp !== false && $timestamp > 0 ) {
                return (int) $timestamp;
            }
        }

        return 0;
    }

    private static function normalizeRouteYear( string $year ): string {
        $year = trim( $year );
        return preg_match( '/^\d{4}$/', $year ) === 1 ? $year : '';
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

    private static function postFromRow( array $row ): Post {
        return self::hydrateCategories( Post::fromRow( $row ) );
    }

    private static function hydrateCategories( Post $post ): Post {
        $ids = PostCategoryService::categoryIdsForPost( (int) ( $post->id ?? 0 ) );
        if ( $ids === [] && isset( $post->post_category_id ) && (int) $post->post_category_id > 0 ) {
            $ids = [ (int) $post->post_category_id ];
        }
        $post->post_category_ids = $ids;
        if ( $ids !== [] ) {
            $post->post_category_id = (int) $ids[0];
        }
        return $post;
    }

    /**
     * @param mixed $raw_ids
     * @return array<int,int>
     */
    private static function normalizeCategoryIds( mixed $raw_ids ): array {
        if ( is_string( $raw_ids ) ) {
            $decoded = json_decode( $raw_ids, true );
            if ( is_array( $decoded ) ) {
                $raw_ids = $decoded;
            } else {
                $raw_ids = array_filter( array_map( 'trim', explode( ',', $raw_ids ) ), static fn( string $value ): bool => $value !== '' );
            }
        }
        if ( ! is_array( $raw_ids ) ) {
            return [];
        }
        $ids = [];
        foreach ( $raw_ids as $raw_id ) {
            $id = (int) $raw_id;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    private static function mapTable(): string {
        return \Metis_Tables::get( 'cms_post_category_map' );
    }

    private static function hasColumn( string $column ): bool {
        if ( self::$columnMap === null ) {
            $rows = self::db()->fetchAll( 'SHOW COLUMNS FROM ' . \Metis_Tables::get( 'cms_posts' ) );
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
