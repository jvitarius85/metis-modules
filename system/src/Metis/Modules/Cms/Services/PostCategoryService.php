<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Services;

use Metis\Core\Application;
use Metis\Modules\Cms\SchemaManager;

final class PostCategoryService {
    private static bool $legacy_seed_checked = false;
    /** @var array<string,array<int,array<string,mixed>>> */
    private static array $all_cache = [];

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all( bool $include_inactive = true ): array {
        SchemaManager::ensureSchema();
        self::seedFromLegacyPostMeta();

        $cache_key = $include_inactive ? 'all' : 'active';
        if ( isset( self::$all_cache[ $cache_key ] ) ) {
            return self::$all_cache[ $cache_key ];
        }

        $db = self::db();
        $categories_table = \Metis_Tables::get( 'cms_post_categories' );
        $map_table = self::mapTable();
        if ( $categories_table === '' || $map_table === '' ) {
            return [];
        }

        $where = $include_inactive ? '' : "WHERE c.status = 'active'";
        $rows = $db->fetchAll(
            "SELECT c.id, c.category_code, c.name, c.slug, c.parent_id, c.status, c.sort_order, c.created_by, c.updated_by, c.created_at, c.updated_at,
                    COUNT(DISTINCT pcm.post_id) AS post_count
             FROM {$categories_table} c
             LEFT JOIN {$map_table} pcm ON pcm.category_id = c.id
             {$where}
             GROUP BY c.id, c.category_code, c.name, c.slug, c.parent_id, c.status, c.sort_order, c.created_by, c.updated_by, c.created_at, c.updated_at
             ORDER BY c.sort_order ASC, c.name ASC",
            []
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        $flat = array_values( array_filter( array_map(
            static function ( $row ): ?array {
                if ( ! is_array( $row ) ) {
                    return null;
                }

                return [
                    'id' => (int) ( $row['id'] ?? 0 ),
                    'category_code' => (string) ( $row['category_code'] ?? '' ),
                    'name' => (string) ( $row['name'] ?? '' ),
                    'slug' => (string) ( $row['slug'] ?? '' ),
                    'parent_id' => isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
                    'status' => (string) ( $row['status'] ?? 'active' ),
                    'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
                    'created_by' => isset( $row['created_by'] ) ? (int) $row['created_by'] : null,
                    'updated_by' => isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null,
                    'created_at' => (string) ( $row['created_at'] ?? '' ),
                    'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                    'post_count' => (int) ( $row['post_count'] ?? 0 ),
                ];
            },
            $rows
        ) ) );

        self::$all_cache[ $cache_key ] = self::decorateHierarchy( $flat );

        return self::$all_cache[ $cache_key ];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public static function options( bool $include_inactive = false ): array {
        $rows = self::all( $include_inactive );
        $options = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = (int) ( $row['id'] ?? 0 );
            $name = trim( (string) ( $row['name'] ?? '' ) );
            if ( $id < 1 || $name === '' ) {
                continue;
            }
            $options[] = [
                'value' => (string) $id,
                'label' => trim( (string) ( $row['indented_name'] ?? $name ) ),
            ];
        }
        return $options;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById( int $id ): ?array {
        if ( $id < 1 ) {
            return null;
        }

        foreach ( self::all( true ) as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $id ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getBySlug( string $slug ): ?array {
        $slug = metis_slug_clean( $slug );
        if ( $slug === '' ) {
            return null;
        }

        $db = self::db();
        $table = \Metis_Tables::get( 'cms_post_categories' );
        if ( $table === '' ) {
            return null;
        }

        $row = $db->fetchOne(
            "SELECT id, category_code, name, slug, parent_id, status, sort_order, created_by, updated_by, created_at, updated_at
             FROM {$table}
             WHERE slug = %s
             LIMIT 1",
            [ $slug ]
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $result = self::getById( (int) ( $row['id'] ?? 0 ) );
        return is_array( $result ) ? $result : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getByName( string $name ): ?array {
        $name = trim( metis_text_clean( $name ) );
        if ( $name === '' ) {
            return null;
        }

        $slug = metis_slug_clean( $name );
        if ( $slug === '' ) {
            return null;
        }

        return self::getBySlug( $slug );
    }

    public static function defaultCategoryId(): ?int {
        $existing = self::getBySlug( 'uncategorized' );
        if ( is_array( $existing ) ) {
            $id = (int) ( $existing['id'] ?? 0 );
            return $id > 0 ? $id : null;
        }

        $created = self::save( 0, 'Uncategorized', 'uncategorized', 'active', 9999, 0 );
        if ( ! $created ) {
            return null;
        }

        $row = self::getBySlug( 'uncategorized' );
        if ( ! is_array( $row ) ) {
            return null;
        }

        $id = (int) ( $row['id'] ?? 0 );
        return $id > 0 ? $id : null;
    }

    public static function save( int $id, string $name, string $slug = '', string $status = 'active', int $sort_order = 0, int $parent_id = 0 ): bool {
        SchemaManager::ensureSchema();

        $clean_name = trim( metis_text_clean( $name ) );
        $clean_slug = metis_slug_clean( $slug !== '' ? $slug : $name );
        $clean_status = in_array( metis_key_clean( $status ), [ 'active', 'inactive' ], true ) ? metis_key_clean( $status ) : 'active';
        if ( $clean_name === '' || $clean_slug === '' ) {
            return false;
        }

        $db = self::db();
        $table = \Metis_Tables::get( 'cms_post_categories' );
        if ( $table === '' ) {
            return false;
        }

        $clean_parent_id = $parent_id > 0 ? $parent_id : 0;

        $existing_row = $id > 0 ? self::getById( $id ) : null;
        $old_slug = is_array( $existing_row ) ? metis_slug_clean( (string) ( $existing_row['slug'] ?? '' ) ) : '';
        $affected_posts = [];
        if ( $id > 0 && $old_slug !== '' && $old_slug !== $clean_slug && class_exists( PostService::class ) ) {
            $affected_posts = PostService::getAll(
                [
                    'status' => 'published',
                    'post_category_id' => $id,
                    'fetch_all' => true,
                ]
            );
        }

        $existing = $db->fetchOne(
            "SELECT id
             FROM {$table}
             WHERE slug = %s
               AND (
                    ( parent_id IS NULL AND %d = 0 )
                    OR parent_id = %d
               )
             LIMIT 1",
            [ $clean_slug, $clean_parent_id, $clean_parent_id ]
        );
        $existing_id = is_array( $existing ) ? (int) ( $existing['id'] ?? 0 ) : 0;
        if ( $existing_id > 0 && $existing_id !== $id ) {
            return false;
        }
        if ( $clean_parent_id === $id ) {
            return false;
        }
        if ( $clean_parent_id > 0 && self::getById( $clean_parent_id ) === null ) {
            $clean_parent_id = 0;
        }
        if ( $clean_parent_id > 0 ) {
            $parent_row = self::getById( $clean_parent_id );
            if ( is_array( $parent_row ) && (int) ( $parent_row['parent_id'] ?? 0 ) > 0 ) {
                return false;
            }
        }
        if ( $id > 0 && $clean_parent_id > 0 && self::wouldCreateCycle( $id, $clean_parent_id ) ) {
            return false;
        }

        $payload = [
            'name' => $clean_name,
            'slug' => $clean_slug,
            'parent_id' => $clean_parent_id > 0 ? $clean_parent_id : null,
            'status' => $clean_status,
            'sort_order' => max( 0, $sort_order ),
            'updated_by' => self::currentUserId(),
        ];

        if ( $id > 0 ) {
            $result = $db->update( $table, $payload, [ 'id' => $id ] );
            self::$all_cache = [];
            if ( $result === false ) {
                return false;
            }

            if ( $old_slug !== '' && $old_slug !== $clean_slug ) {
                self::createPublishedPostCategorySlugRedirects( $affected_posts, $old_slug, $clean_slug );
            }

            return true;
        }

        $payload['category_code'] = \metis_generate_code( 'WPC', $table, 'category_code' );
        $payload['created_by'] = self::currentUserId();
        $result = $db->insert( $table, $payload );
        self::$all_cache = [];
        return $result !== false;
    }

    public static function delete( int $id ): bool {
        if ( $id < 1 ) {
            return false;
        }

        SchemaManager::ensureSchema();

        $db = self::db();
        $categories_table = \Metis_Tables::get( 'cms_post_categories' );
        $map_table = self::mapTable();
        if ( $categories_table === '' || $map_table === '' ) {
            return false;
        }

        $in_use = (int) $db->scalar(
            "SELECT COUNT(id) FROM {$map_table} WHERE category_id = %d",
            [ $id ]
        );
        if ( $in_use > 0 ) {
            return false;
        }
        $has_children = (int) $db->scalar(
            "SELECT COUNT(id) FROM {$categories_table} WHERE parent_id = %d",
            [ $id ]
        );
        if ( $has_children > 0 ) {
            return false;
        }

        $result = $db->delete( $categories_table, [ 'id' => $id ] );
        self::$all_cache = [];
        return $result !== false;
    }

    public static function categoryIdForPostMeta( ?string $seo_meta_json ): ?int {
        $name = self::legacyCategoryNameFromSeoMeta( $seo_meta_json );
        if ( $name === '' ) {
            return null;
        }

        $row = self::getByName( $name );
        return is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : null;
    }

    public static function categoryNameById( ?int $id ): string {
        $id = (int) $id;
        if ( $id < 1 ) {
            return '';
        }
        $row = self::getById( $id );
        return is_array( $row ) ? trim( (string) ( $row['name'] ?? '' ) ) : '';
    }

    /**
     * @return array<int,int>
     */
    public static function categoryIdsForPost( int $post_id ): array {
        if ( $post_id < 1 ) {
            return [];
        }
        $table = self::mapTable();
        if ( $table === '' ) {
            return [];
        }
        $rows = self::db()->fetchAll(
            "SELECT category_id FROM {$table} WHERE post_id = %d ORDER BY is_primary DESC, id ASC",
            [ $post_id ]
        );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $ids = [];
        foreach ( $rows as $row ) {
            $id = is_array( $row ) ? (int) ( $row['category_id'] ?? 0 ) : 0;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function categoriesForPost( int $post_id ): array {
        $categories = [];
        foreach ( self::categoryIdsForPost( $post_id ) as $category_id ) {
            $row = self::getById( $category_id );
            if ( is_array( $row ) ) {
                $categories[] = $row;
            }
        }
        return $categories;
    }

    public static function primaryCategoryIdForPost( int $post_id ): ?int {
        $ids = self::categoryIdsForPost( $post_id );
        return $ids !== [] ? (int) $ids[0] : null;
    }

    /**
     * @param array<int,mixed> $category_ids
     * @return array<int,int>
     */
    public static function syncPostCategories( int $post_id, array $category_ids ): void {
        if ( $post_id < 1 ) {
            return;
        }

        SchemaManager::ensureSchema();
        $map_table = self::mapTable();
        $posts_table = \Metis_Tables::get( 'cms_posts' );
        if ( $map_table === '' || $posts_table === '' ) {
            return;
        }

        $ids = self::normalizeCategoryIds( $category_ids );
        $db = self::db();
        $db->delete( $map_table, [ 'post_id' => $post_id ] );
        foreach ( $ids as $index => $category_id ) {
            $db->insert(
                $map_table,
                [
                    'post_id' => $post_id,
                    'category_id' => $category_id,
                    'is_primary' => $index === 0 ? 1 : 0,
                ]
            );
        }
        $db->update(
            $posts_table,
            [ 'post_category_id' => $ids !== [] ? (int) $ids[0] : null ],
            [ 'id' => $post_id ]
        );
        self::$all_cache = [];
    }

    private static function seedFromLegacyPostMeta(): void {
        if ( self::$legacy_seed_checked ) {
            return;
        }
        self::$legacy_seed_checked = true;

        $db = self::db();
        $categories_table = \Metis_Tables::get( 'cms_post_categories' );
        $posts_table = \Metis_Tables::get( 'cms_posts' );
        if ( $categories_table === '' || $posts_table === '' ) {
            return;
        }

        $existing_count = (int) $db->scalar( "SELECT COUNT(id) FROM {$categories_table}", [] );
        if ( $existing_count > 0 ) {
            return;
        }

        $rows = $db->fetchAll(
            "SELECT seo_meta_json FROM {$posts_table} WHERE seo_meta_json IS NOT NULL AND seo_meta_json <> '' LIMIT 5000",
            []
        );
        if ( ! is_array( $rows ) || $rows === [] ) {
            return;
        }

        $seen = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name = self::legacyCategoryNameFromSeoMeta( isset( $row['seo_meta_json'] ) ? (string) $row['seo_meta_json'] : null );
            if ( $name === '' ) {
                continue;
            }
            $slug = metis_slug_clean( $name );
            if ( $slug === '' || isset( $seen[ $slug ] ) ) {
                continue;
            }
            $seen[ $slug ] = $name;
        }

        foreach ( $seen as $slug => $name ) {
            self::save( 0, (string) $name, (string) $slug, 'active', 0 );
        }

        $post_rows = $db->fetchAll(
            "SELECT id, seo_meta_json FROM {$posts_table} WHERE (post_category_id IS NULL OR post_category_id = 0) AND seo_meta_json IS NOT NULL AND seo_meta_json <> '' LIMIT 5000",
            []
        );
        if ( ! is_array( $post_rows ) ) {
            return;
        }

        foreach ( $post_rows as $post_row ) {
            if ( ! is_array( $post_row ) ) {
                continue;
            }
            $post_id = (int) ( $post_row['id'] ?? 0 );
            $name = self::legacyCategoryNameFromSeoMeta( isset( $post_row['seo_meta_json'] ) ? (string) $post_row['seo_meta_json'] : null );
            if ( $post_id < 1 || $name === '' ) {
                continue;
            }
            $category = self::getByName( $name );
            $category_id = is_array( $category ) ? (int) ( $category['id'] ?? 0 ) : 0;
            if ( $category_id < 1 ) {
                continue;
            }
            $db->update( $posts_table, [ 'post_category_id' => $category_id ], [ 'id' => $post_id ] );
            self::syncPostCategories( $post_id, [ $category_id ] );
        }
    }

    /**
     * @param array<int,mixed> $posts
     */
    private static function createPublishedPostCategorySlugRedirects( array $posts, string $old_slug, string $new_slug ): void {
        if ( $old_slug === '' || $new_slug === '' || $old_slug === $new_slug || ! class_exists( RedirectService::class ) ) {
            return;
        }

        foreach ( $posts as $post ) {
            if ( ! $post instanceof \Metis\Modules\Cms\Entities\Post ) {
                continue;
            }

            $old_path = PostService::publicPathForRoute( $post, $old_slug );
            $new_path = PostService::publicPathForRoute( $post, $new_slug );
            if ( $old_path === '' || $new_path === '' || $old_path === $new_path ) {
                continue;
            }

            RedirectService::createSlugChangeRedirect( $old_path, $new_path, 'post_category' );
        }
    }

    private static function legacyCategoryNameFromSeoMeta( ?string $seo_meta_json ): string {
        $raw = trim( (string) $seo_meta_json );
        if ( $raw === '' ) {
            return '';
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return '';
        }

        $editor = isset( $decoded['_editor'] ) && is_array( $decoded['_editor'] ) ? $decoded['_editor'] : [];
        return trim( metis_text_clean( (string) ( $editor['category'] ?? '' ) ) );
    }

    private static function currentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }
        $uid = (int) metis_current_user_id();
        return $uid > 0 ? $uid : null;
    }

    private static function db(): object {
        return Application::service( 'db' );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function decorateHierarchy( array $rows ): array {
        $by_parent = [];
        $by_id = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            $parent_id = (int) ( $row['parent_id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $by_id[ $id ] = $row;
            $by_parent[ $parent_id ][] = $id;
        }

        $ordered = [];
        $visited = [];
        $walk = static function ( int $parent_id, int $depth ) use ( &$walk, &$ordered, &$visited, $by_parent, $by_id ): void {
            foreach ( $by_parent[ $parent_id ] ?? [] as $id ) {
                if ( isset( $visited[ $id ] ) || ! isset( $by_id[ $id ] ) ) {
                    continue;
                }
                $visited[ $id ] = true;
                $row = $by_id[ $id ];
                $row['depth'] = $depth;
                $row['indented_name'] = str_repeat( '— ', max( 0, $depth ) ) . (string) ( $row['name'] ?? '' );
                $parent = (int) ( $row['parent_id'] ?? 0 );
                $parent_name = $parent > 0 && isset( $by_id[ $parent ] ) ? (string) ( $by_id[ $parent ]['name'] ?? '' ) : '';
                $row['parent_name'] = $parent_name;
                $ordered[] = $row;
                $walk( $id, $depth + 1 );
            }
        };

        $walk( 0, 0 );
        foreach ( $by_id as $id => $row ) {
            if ( isset( $visited[ $id ] ) ) {
                continue;
            }
            $row['depth'] = 0;
            $row['indented_name'] = (string) ( $row['name'] ?? '' );
            $row['parent_name'] = '';
            $ordered[] = $row;
        }

        return $ordered;
    }

    private static function wouldCreateCycle( int $category_id, int $parent_id ): bool {
        $current = $parent_id;
        $guard = 0;
        while ( $current > 0 && $guard < 100 ) {
            if ( $current === $category_id ) {
                return true;
            }
            $row = self::getById( $current );
            if ( ! is_array( $row ) ) {
                return false;
            }
            $current = (int) ( $row['parent_id'] ?? 0 );
            $guard++;
        }
        return false;
    }

    private static function mapTable(): string {
        return \Metis_Tables::get( 'cms_post_category_map' );
    }

    /**
     * @param array<int,mixed> $category_ids
     * @return array<int,int>
     */
    private static function normalizeCategoryIds( array $category_ids ): array {
        $normalized = [];
        foreach ( $category_ids as $category_id ) {
            $id = (int) $category_id;
            if ( $id < 1 || self::getById( $id ) === null ) {
                continue;
            }
            $normalized[] = $id;
        }
        return array_values( array_unique( $normalized ) );
    }
}
