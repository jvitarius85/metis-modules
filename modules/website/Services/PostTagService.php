<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;
use Metis\Modules\Website\SchemaManager;

final class PostTagService {
    /** @var array<int,array<string,mixed>>|null */
    private static ?array $all_cache = null;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all( bool $include_inactive = true ): array {
        SchemaManager::ensureSchema();

        if ( self::$all_cache !== null && $include_inactive ) {
            return self::$all_cache;
        }

        $db = self::db();
        $tags_table = \Metis_Tables::get( 'website_post_tags' );
        $map_table = self::mapTable();
        if ( $tags_table === '' || $map_table === '' ) {
            return [];
        }

        $where = $include_inactive ? '' : "WHERE t.status = 'active'";
        $rows = $db->fetchAll(
            "SELECT t.id, t.tag_code, t.name, t.slug, t.status, t.sort_order, t.created_by, t.updated_by, t.created_at, t.updated_at,
                    COUNT(DISTINCT ptm.post_id) AS post_count
             FROM {$tags_table} t
             LEFT JOIN {$map_table} ptm ON ptm.tag_id = t.id
             {$where}
             GROUP BY t.id, t.tag_code, t.name, t.slug, t.status, t.sort_order, t.created_by, t.updated_by, t.created_at, t.updated_at
             ORDER BY t.sort_order ASC, t.name ASC",
            []
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        $normalized = array_values(
            array_filter(
                array_map(
                    static function ( mixed $row ): ?array {
                        if ( ! is_array( $row ) ) {
                            return null;
                        }

                        return [
                            'id' => (int) ( $row['id'] ?? 0 ),
                            'tag_code' => (string) ( $row['tag_code'] ?? '' ),
                            'name' => (string) ( $row['name'] ?? '' ),
                            'slug' => (string) ( $row['slug'] ?? '' ),
                            'status' => (string) ( $row['status'] ?? 'active' ),
                            'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
                            'created_by' => isset( $row['created_by'] ) ? (int) ( $row['created_by'] ?? 0 ) : null,
                            'updated_by' => isset( $row['updated_by'] ) ? (int) ( $row['updated_by'] ?? 0 ) : null,
                            'created_at' => (string) ( $row['created_at'] ?? '' ),
                            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                            'post_count' => (int) ( $row['post_count'] ?? 0 ),
                        ];
                    },
                    $rows
                )
            )
        );

        if ( $include_inactive ) {
            self::$all_cache = $normalized;
        }

        return $normalized;
    }

    /**
     * @return array<int,array{value:string,label:string,name:string,slug:string}>
     */
    public static function options( bool $include_inactive = false ): array {
        $options = [];
        foreach ( self::all( $include_inactive ) as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            $name = trim( (string) ( $row['name'] ?? '' ) );
            if ( $id < 1 || $name === '' ) {
                continue;
            }

            $options[] = [
                'value' => (string) $id,
                'label' => $name,
                'name' => $name,
                'slug' => (string) ( $row['slug'] ?? '' ),
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
        $clean_slug = metis_slug_clean( $slug );
        if ( $clean_slug === '' ) {
            return null;
        }

        foreach ( self::all( true ) as $row ) {
            if ( metis_slug_clean( (string) ( $row['slug'] ?? '' ) ) === $clean_slug ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed>|string $raw_tags
     * @return array<int,string>
     */
    public static function normalizeTagNames( array|string $raw_tags ): array {
        if ( is_string( $raw_tags ) ) {
            $decoded = json_decode( $raw_tags, true );
            if ( is_array( $decoded ) ) {
                $raw_tags = $decoded;
            } else {
                $raw_tags = preg_split( '/\s*,\s*/', $raw_tags ) ?: [];
            }
        }

        $seen = [];
        $normalized = [];
        foreach ( $raw_tags as $raw_tag ) {
            $name = trim( metis_text_clean( (string) $raw_tag ) );
            if ( $name === '' ) {
                continue;
            }

            $slug = metis_slug_clean( $name );
            if ( $slug === '' || isset( $seen[ $slug ] ) ) {
                continue;
            }

            $seen[ $slug ] = true;
            $normalized[] = $name;
        }

        return $normalized;
    }

    /**
     * @param array<int,mixed>|string $raw_tags
     * @return array<int,int>
     */
    public static function ensureTagIds( array|string $raw_tags ): array {
        SchemaManager::ensureSchema();

        $tag_ids = [];
        foreach ( self::normalizeTagNames( $raw_tags ) as $name ) {
            $slug = metis_slug_clean( $name );
            if ( $slug === '' ) {
                continue;
            }

            $existing = self::getBySlug( $slug );
            if ( is_array( $existing ) ) {
                $id = (int) ( $existing['id'] ?? 0 );
                if ( $id > 0 ) {
                    $tag_ids[] = $id;
                    continue;
                }
            }

            $saved_id = self::save( 0, $name, $slug, 'active', 0 );
            if ( $saved_id > 0 ) {
                $tag_ids[] = $saved_id;
            }
        }

        return array_values( array_unique( array_filter( $tag_ids, static fn ( int $id ): bool => $id > 0 ) ) );
    }

    public static function save( int $id, string $name, string $slug = '', string $status = 'active', int $sort_order = 0 ): int {
        SchemaManager::ensureSchema();

        $clean_name = trim( metis_text_clean( $name ) );
        $clean_slug = metis_slug_clean( $slug !== '' ? $slug : $name );
        $clean_status = in_array( metis_key_clean( $status ), [ 'active', 'inactive' ], true ) ? metis_key_clean( $status ) : 'active';
        if ( $clean_name === '' || $clean_slug === '' ) {
            return 0;
        }

        $db = self::db();
        $table = \Metis_Tables::get( 'website_post_tags' );
        if ( $table === '' ) {
            return 0;
        }

        $existing = $db->fetchOne(
            "SELECT id FROM {$table} WHERE slug = %s LIMIT 1",
            [ $clean_slug ]
        );
        $existing_id = is_array( $existing ) ? (int) ( $existing['id'] ?? 0 ) : 0;
        if ( $existing_id > 0 && $existing_id !== $id ) {
            return $existing_id;
        }

        $payload = [
            'name' => $clean_name,
            'slug' => $clean_slug,
            'status' => $clean_status,
            'sort_order' => max( 0, $sort_order ),
            'updated_by' => self::currentUserId(),
        ];

        if ( $id > 0 ) {
            $result = $db->update( $table, $payload, [ 'id' => $id ] );
            self::$all_cache = null;
            return $result === false ? 0 : $id;
        }

        $payload['tag_code'] = \metis_generate_code( 'WPT', $table, 'tag_code' );
        $payload['created_by'] = self::currentUserId();
        $result = $db->insert( $table, $payload );
        self::$all_cache = null;

        return $result === false ? 0 : (int) $db->lastInsertId();
    }

    /**
     * @return array<int,int>
     */
    public static function tagIdsForPost( int $post_id ): array {
        if ( $post_id < 1 ) {
            return [];
        }

        $table = self::mapTable();
        if ( $table === '' ) {
            return [];
        }

        $rows = self::db()->fetchAll(
            "SELECT tag_id FROM {$table} WHERE post_id = %d ORDER BY id ASC",
            [ $post_id ]
        );
        if ( ! is_array( $rows ) ) {
            return [];
        }

        $ids = [];
        foreach ( $rows as $row ) {
            $id = is_array( $row ) ? (int) ( $row['tag_id'] ?? 0 ) : 0;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function tagsForPost( int $post_id ): array {
        $tags = [];
        foreach ( self::tagIdsForPost( $post_id ) as $tag_id ) {
            $row = self::getById( $tag_id );
            if ( is_array( $row ) ) {
                $tags[] = $row;
            }
        }

        return $tags;
    }

    /**
     * @param array<int,mixed> $tag_ids
     */
    public static function syncPostTags( int $post_id, array $tag_ids ): void {
        if ( $post_id < 1 ) {
            return;
        }

        SchemaManager::ensureSchema();
        $map_table = self::mapTable();
        if ( $map_table === '' ) {
            return;
        }

        $ids = self::normalizeTagIds( $tag_ids );
        $db = self::db();
        $db->delete( $map_table, [ 'post_id' => $post_id ] );
        foreach ( $ids as $tag_id ) {
            $db->insert(
                $map_table,
                [
                    'post_id' => $post_id,
                    'tag_id' => $tag_id,
                ]
            );
        }

        self::$all_cache = null;
    }

    /**
     * @param mixed $raw_ids
     * @return array<int,int>
     */
    private static function normalizeTagIds( mixed $raw_ids ): array {
        if ( is_string( $raw_ids ) ) {
            $decoded = json_decode( $raw_ids, true );
            if ( is_array( $decoded ) ) {
                $raw_ids = $decoded;
            } else {
                $raw_ids = preg_split( '/\s*,\s*/', $raw_ids ) ?: [];
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
        return \Metis_Tables::get( 'website_post_tag_map' );
    }

    private static function currentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }

        $user_id = metis_current_user_id();
        return $user_id > 0 ? $user_id : null;
    }

    private static function db(): object {
        return function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : Application::service( 'db' );
    }
}
