<?php
declare(strict_types=1);

namespace Metis\Modules\Testimonies;

use Metis\Core\Cache\CacheService;

final class Repository {
    /**
     * @return array<string,mixed>
     */
    public static function listSnapshot( string $search = '' ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $testimonies = \Metis_Tables::get( 'testimonies' );
        $categories  = \Metis_Tables::get( 'testimony_categories' );
        $map         = \Metis_Tables::get( 'testimony_category_map' );

        $search = trim( $search );
        $where = [];
        $params = [];
        if ( $search !== '' ) {
            $like = '%' . $db->escapeLike( $search ) . '%';
            $where[] = '(t.speaker_name LIKE %s OR t.speaker_title LIKE %s OR t.speaker_company LIKE %s OR t.quote_text LIKE %s OR c.name LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like );
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $rows = $db->fetchAll(
            "SELECT
                t.id,
                t.testimony_code,
                t.speaker_name,
                t.speaker_title,
                t.speaker_company,
                t.quote_text,
                t.source_notes,
                t.status,
                t.is_featured,
                t.sort_order,
                t.updated_at,
                GROUP_CONCAT(DISTINCT c.id ORDER BY c.sort_order ASC, c.name ASC SEPARATOR ',') AS category_ids,
                GROUP_CONCAT(DISTINCT c.name ORDER BY c.sort_order ASC, c.name ASC SEPARATOR '||') AS category_names
             FROM {$testimonies} t
             LEFT JOIN {$map} m ON m.testimony_id = t.id
             LEFT JOIN {$categories} c ON c.id = m.category_id
             {$whereSql}
             GROUP BY t.id, t.testimony_code, t.speaker_name, t.speaker_title, t.speaker_company, t.quote_text, t.source_notes, t.status, t.is_featured, t.sort_order, t.updated_at
             ORDER BY t.status = 'published' DESC, t.is_featured DESC, t.sort_order ASC, t.updated_at DESC, t.id DESC
             LIMIT 300",
            $params
        ) ?: [];

        $categoryRows = $db->fetchAll(
            "SELECT
                c.id,
                c.category_code,
                c.name,
                c.slug,
                c.is_active,
                c.sort_order,
                COUNT(m.id) AS testimony_count
             FROM {$categories} c
             LEFT JOIN {$map} m ON m.category_id = c.id
             GROUP BY c.id, c.category_code, c.name, c.slug, c.is_active, c.sort_order
             ORDER BY c.is_active DESC, c.sort_order ASC, c.name ASC"
        ) ?: [];

        return [
            'testimonies' => array_map( static fn( array $row ): array => self::hydrateAdminRow( $row ), $rows ),
            'categories' => array_map( static fn( array $row ): array => self::hydrateCategoryRow( $row ), $categoryRows ),
        ];
    }

    /**
     * @return array<int,array{value:string,label:string,slug:string}>
     */
    public static function categoryOptions( bool $activeOnly = true ): array {
        SchemaManager::ensureSchema();

        return CacheService::remember(
            'testimonies.category_options.' . ( $activeOnly ? 'active' : 'all' ),
            600,
            static function () use ( $activeOnly ): array {
                $table = \Metis_Tables::get( 'testimony_categories' );
                $where = $activeOnly ? 'WHERE is_active = 1' : '';
                $rows = \metis_db()->fetchAll(
                    "SELECT id, name, slug
                     FROM {$table}
                     {$where}
                     ORDER BY sort_order ASC, name ASC"
                ) ?: [];

                $options = [];
                foreach ( $rows as $row ) {
                    if ( ! \is_array( $row ) ) {
                        continue;
                    }
                    $id = (int) ( $row['id'] ?? 0 );
                    $name = trim( (string) ( $row['name'] ?? '' ) );
                    if ( $id < 1 || $name === '' ) {
                        continue;
                    }
                    $options[] = [
                        'value' => (string) $id,
                        'label' => $name,
                        'slug' => trim( (string) ( $row['slug'] ?? '' ) ),
                    ];
                }

                return $options;
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function saveCategory( array $payload, int $userId ): array {
        SchemaManager::ensureSchema();

        $id = max( 0, (int) ( $payload['id'] ?? 0 ) );
        $name = trim( (string) ( $payload['name'] ?? '' ) );
        $slug = \function_exists( 'metis_slug_clean' ) ? \metis_slug_clean( (string) ( $payload['slug'] ?? $name ) ) : self::slugify( (string) ( $payload['slug'] ?? $name ) );
        $sortOrder = (int) ( $payload['sort_order'] ?? 0 );
        $isActive = ! array_key_exists( 'is_active', $payload ) || ! empty( $payload['is_active'] );

        if ( $name === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Category name is required.' ];
        }
        if ( $slug === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Category slug is required.' ];
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'testimony_categories' );
        $existing = $db->fetchOne(
            "SELECT id FROM {$table} WHERE slug = %s" . ( $id > 0 ? ' AND id <> %d' : '' ) . ' LIMIT 1',
            $id > 0 ? [ $slug, $id ] : [ $slug ]
        );
        if ( \is_array( $existing ) ) {
            return [ 'ok' => false, 'status' => 409, 'error' => 'A category with that slug already exists.' ];
        }

        $record = [
            'name' => $name,
            'slug' => $slug,
            'is_active' => $isActive ? 1 : 0,
            'sort_order' => $sortOrder,
            'updated_by' => $userId > 0 ? $userId : null,
        ];
        $formats = [ '%s', '%s', '%d', '%d', '%d' ];

        if ( $id > 0 ) {
            $ok = $db->update( $table, $record, [ 'id' => $id ], $formats, [ '%d' ] );
            if ( $ok === false ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Category update failed.' ];
            }
        } else {
            $record['testimony_category_uid'] = self::newUid();
            $record['category_code'] = self::newCode( 'TSC' );
            $record['created_by'] = $userId > 0 ? $userId : null;
            $insertFormats = [ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ];
            $ok = $db->insert( $table, $record, $insertFormats );
            if ( $ok === false ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Category create failed.' ];
            }
            $id = (int) $db->lastInsertId();
            if ( $id > 0 && \function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'testimony_category', $id, (string) $record['category_code'] );
            }
        }

        self::flushCaches();
        return [ 'ok' => true, 'category_id' => $id ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function deleteCategory( int $categoryId ): array {
        SchemaManager::ensureSchema();
        if ( $categoryId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Category id is required.' ];
        }

        $db = \metis_db();
        $map = \Metis_Tables::get( 'testimony_category_map' );
        $count = (int) $db->scalar( "SELECT COUNT(*) FROM {$map} WHERE category_id = %d", [ $categoryId ] );
        if ( $count > 0 ) {
            return [ 'ok' => false, 'status' => 409, 'error' => 'Remove this category from testimonies before deleting it.' ];
        }

        $ok = $db->delete( \Metis_Tables::get( 'testimony_categories' ), [ 'id' => $categoryId ], [ '%d' ] );
        if ( $ok === false ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Category delete failed.' ];
        }

        self::flushCaches();
        return [ 'ok' => true ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function saveTestimony( array $payload, int $userId ): array {
        SchemaManager::ensureSchema();

        $id = max( 0, (int) ( $payload['id'] ?? 0 ) );
        $speakerName = trim( (string) ( $payload['speaker_name'] ?? '' ) );
        $quoteText = trim( (string) ( $payload['quote_text'] ?? '' ) );
        $status = \function_exists( 'metis_key_clean' )
            ? \metis_key_clean( (string) ( $payload['status'] ?? 'draft' ) )
            : self::keyify( (string) ( $payload['status'] ?? 'draft' ) );
        if ( ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
            $status = 'draft';
        }

        if ( $speakerName === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Speaker name is required.' ];
        }
        if ( $quoteText === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Quote text is required.' ];
        }

        $categoryIds = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', \is_array( $payload['category_ids'] ?? null ) ? $payload['category_ids'] : [] ),
                    static fn( int $value ): bool => $value > 0
                )
            )
        );

        $db = \metis_db();
        $table = \Metis_Tables::get( 'testimonies' );
        $record = [
            'speaker_name' => $speakerName,
            'speaker_title' => trim( (string) ( $payload['speaker_title'] ?? '' ) ),
            'speaker_company' => trim( (string) ( $payload['speaker_company'] ?? '' ) ),
            'quote_text' => $quoteText,
            'source_notes' => trim( (string) ( $payload['source_notes'] ?? '' ) ),
            'status' => $status,
            'is_featured' => ! empty( $payload['is_featured'] ) ? 1 : 0,
            'sort_order' => (int) ( $payload['sort_order'] ?? 0 ),
            'updated_by' => $userId > 0 ? $userId : null,
        ];
        $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ];

        if ( $id > 0 ) {
            $ok = $db->update( $table, $record, [ 'id' => $id ], $formats, [ '%d' ] );
            if ( $ok === false ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Testimony update failed.' ];
            }
        } else {
            $record['testimony_uid'] = self::newUid();
            $record['testimony_code'] = self::newCode( 'TST' );
            $record['created_by'] = $userId > 0 ? $userId : null;
            $insertFormats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ];
            $ok = $db->insert( $table, $record, $insertFormats );
            if ( $ok === false ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Testimony create failed.' ];
            }
            $id = (int) $db->lastInsertId();
            if ( $id > 0 && \function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'testimony', $id, (string) $record['testimony_code'] );
            }
        }

        self::replaceCategoryMap( $id, $categoryIds );
        self::flushCaches();
        return [ 'ok' => true, 'testimony_id' => $id ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function deleteTestimony( int $testimonyId ): array {
        SchemaManager::ensureSchema();
        if ( $testimonyId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Testimony id is required.' ];
        }

        $db = \metis_db();
        $db->delete( \Metis_Tables::get( 'testimony_category_map' ), [ 'testimony_id' => $testimonyId ], [ '%d' ] );
        $ok = $db->delete( \Metis_Tables::get( 'testimonies' ), [ 'id' => $testimonyId ], [ '%d' ] );
        if ( $ok === false ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Testimony delete failed.' ];
        }

        self::flushCaches();
        return [ 'ok' => true ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function publicTestimonials( array $filters = [] ): array {
        SchemaManager::ensureSchema();

        $categoryIds = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', \is_array( $filters['category_ids'] ?? null ) ? $filters['category_ids'] : [] ),
                    static fn( int $value ): bool => $value > 0
                )
            )
        );
        sort( $categoryIds );
        $limit = max( 1, min( 24, (int) ( $filters['limit'] ?? 6 ) ) );
        $featuredOnly = ! empty( $filters['featured_only'] );
        $cacheKey = 'testimonies.public.' . md5( json_encode( [ $categoryIds, $limit, $featuredOnly ] ) ?: '' );

        return CacheService::remember(
            $cacheKey,
            300,
            static function () use ( $categoryIds, $limit, $featuredOnly ): array {
                $db = \metis_db();
                $testimonies = \Metis_Tables::get( 'testimonies' );
                $categories  = \Metis_Tables::get( 'testimony_categories' );
                $map         = \Metis_Tables::get( 'testimony_category_map' );

                $where = [ "t.status = 'published'" ];
                $params = [];
                if ( $featuredOnly ) {
                    $where[] = 't.is_featured = 1';
                }
                if ( $categoryIds !== [] ) {
                    $placeholders = implode( ',', array_fill( 0, count( $categoryIds ), '%d' ) );
                    $where[] = "EXISTS (
                        SELECT 1 FROM {$map} mx
                        WHERE mx.testimony_id = t.id AND mx.category_id IN ({$placeholders})
                    )";
                    array_push( $params, ...$categoryIds );
                }

                $params[] = $limit;
                $rows = $db->fetchAll(
                    "SELECT
                        t.id,
                        t.testimony_code,
                        t.speaker_name,
                        t.speaker_title,
                        t.speaker_company,
                        t.quote_text,
                        t.is_featured,
                        GROUP_CONCAT(DISTINCT c.name ORDER BY c.sort_order ASC, c.name ASC SEPARATOR '||') AS category_names
                     FROM {$testimonies} t
                     LEFT JOIN {$map} m ON m.testimony_id = t.id
                     LEFT JOIN {$categories} c ON c.id = m.category_id AND c.is_active = 1
                     WHERE " . implode( ' AND ', $where ) . "
                     GROUP BY t.id, t.testimony_code, t.speaker_name, t.speaker_title, t.speaker_company, t.quote_text, t.is_featured, t.sort_order, t.updated_at
                     ORDER BY t.is_featured DESC, t.sort_order ASC, t.updated_at DESC, t.id DESC
                     LIMIT %d",
                    $params
                ) ?: [];

                return array_map(
                    static function ( array $row ): array {
                        $names = array_values( array_filter( array_map( 'trim', explode( '||', (string) ( $row['category_names'] ?? '' ) ) ) ) );
                        return [
                            'id' => (int) ( $row['id'] ?? 0 ),
                            'testimony_code' => (string) ( $row['testimony_code'] ?? '' ),
                            'speaker_name' => (string) ( $row['speaker_name'] ?? '' ),
                            'speaker_title' => (string) ( $row['speaker_title'] ?? '' ),
                            'speaker_company' => (string) ( $row['speaker_company'] ?? '' ),
                            'quote_text' => (string) ( $row['quote_text'] ?? '' ),
                            'is_featured' => ! empty( $row['is_featured'] ),
                            'categories' => $names,
                        ];
                    },
                    $rows
                );
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function blankTestimony(): array {
        return [
            'id' => 0,
            'speaker_name' => '',
            'speaker_title' => '',
            'speaker_company' => '',
            'quote_text' => '',
            'source_notes' => '',
            'status' => 'draft',
            'is_featured' => false,
            'sort_order' => 0,
            'category_ids' => [],
        ];
    }

    private static function replaceCategoryMap( int $testimonyId, array $categoryIds ): void {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'testimony_category_map' );
        $db->delete( $table, [ 'testimony_id' => $testimonyId ], [ '%d' ] );
        foreach ( $categoryIds as $index => $categoryId ) {
            $db->insert(
                $table,
                [
                    'testimony_id' => $testimonyId,
                    'category_id' => $categoryId,
                    'is_primary' => $index === 0 ? 1 : 0,
                ],
                [ '%d', '%d', '%d' ]
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrateAdminRow( array $row ): array {
        $ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $row['category_ids'] ?? '' ) ) ) ) );
        $names = array_values( array_filter( array_map( 'trim', explode( '||', (string) ( $row['category_names'] ?? '' ) ) ) ) );
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'testimony_code' => (string) ( $row['testimony_code'] ?? '' ),
            'speaker_name' => (string) ( $row['speaker_name'] ?? '' ),
            'speaker_title' => (string) ( $row['speaker_title'] ?? '' ),
            'speaker_company' => (string) ( $row['speaker_company'] ?? '' ),
            'quote_text' => (string) ( $row['quote_text'] ?? '' ),
            'source_notes' => (string) ( $row['source_notes'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'draft' ),
            'is_featured' => ! empty( $row['is_featured'] ),
            'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
            'category_ids' => $ids,
            'category_names' => $names,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrateCategoryRow( array $row ): array {
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'category_code' => (string) ( $row['category_code'] ?? '' ),
            'name' => (string) ( $row['name'] ?? '' ),
            'slug' => (string) ( $row['slug'] ?? '' ),
            'is_active' => ! empty( $row['is_active'] ),
            'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
            'testimony_count' => (int) ( $row['testimony_count'] ?? 0 ),
        ];
    }

    private static function flushCaches(): void {
        CacheService::clearByPrefix( 'testimonies.' );
    }

    private static function newUid(): string {
        return bin2hex( random_bytes( 16 ) );
    }

    private static function newCode( string $prefix ): string {
        return strtoupper( trim( $prefix ) ) . '-' . strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 12 ) );
    }

    private static function slugify( string $value ): string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';
        return trim( $value, '-' );
    }

    private static function keyify( string $value ): string {
        return self::slugify( str_replace( '_', '-', $value ) );
    }
}
