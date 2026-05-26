<?php
declare(strict_types=1);

namespace Metis\Modules\Media;

final class MediaLibraryService {
    public static function listItems( string $search, string $type, string $folder, string $category, string $sort, int $limit ): array {
        if ( function_exists( 'metis_media_ensure_schema' ) ) {
            \metis_media_ensure_schema();
        }

        $db = \metis_db();
        $table = function_exists( 'metis_media_table_name' ) ? \metis_media_table_name() : 'metis_media_files';
        $limit = max( 1, min( 200, $limit ) );
        $sort_sql = match ( $sort ) {
            'created_asc' => 'created_at ASC, id ASC',
            'name_asc' => 'file_name ASC, id DESC',
            'name_desc' => 'file_name DESC, id DESC',
            'size_asc' => 'size ASC, id DESC',
            'size_desc' => 'size DESC, id DESC',
            default => 'id DESC',
        };

        $where = [];
        $params = [];
        if ( $search !== '' ) {
            $where[] = 'file_name LIKE %s';
            $params[] = '%' . $db->escapeLike( $search ) . '%';
        }
        if ( $type !== '' ) {
            $where[] = 'mime_type LIKE %s';
            $params[] = $type . '/%';
        }
        if ( $folder !== '' ) {
            $where[] = 'folder_path = %s';
            $params[] = $folder;
        }
        if ( $category !== '' ) {
            $where[] = 'category_key = %s';
            $params[] = $category;
        }

        $where_sql = $where !== [] ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
        $params[] = $limit;
        $rows = $db->fetchAll(
            "SELECT id, public_token, file_name, mime_type, size, folder_path, category_key, created_at
             FROM {$table}
             {$where_sql}
             ORDER BY {$sort_sql}
             LIMIT %d",
            $params
        ) ?: [];

        return array_map( static function ( array $row ): array {
            $token = (string) ( $row['public_token'] ?? '' );
            return [
                'id' => (int) ( $row['id'] ?? 0 ),
                'token' => $token,
                'url' => $token !== '' ? \metis_home_url( '/media/' . $token ) : '',
                'file_name' => (string) ( $row['file_name'] ?? '' ),
                'mime_type' => (string) ( $row['mime_type'] ?? '' ),
                'size' => (int) ( $row['size'] ?? 0 ),
                'folder_path' => (string) ( $row['folder_path'] ?? '' ),
                'category_key' => (string) ( $row['category_key'] ?? '' ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ];
        }, $rows );
    }

    public static function facets(): array {
        if ( function_exists( 'metis_media_ensure_schema' ) ) {
            \metis_media_ensure_schema();
        }

        $db = \metis_db();
        $table = function_exists( 'metis_media_table_name' ) ? \metis_media_table_name() : 'metis_media_files';
        $folder_rows = $db->fetchAll(
            "SELECT folder_path, COUNT(*) AS item_count
             FROM {$table}
             WHERE folder_path <> ''
             GROUP BY folder_path
             ORDER BY folder_path ASC
             LIMIT 300"
        ) ?: [];
        $category_rows = $db->fetchAll(
            "SELECT category_key, COUNT(*) AS item_count
             FROM {$table}
             WHERE category_key <> ''
             GROUP BY category_key
             ORDER BY category_key ASC
             LIMIT 300"
        ) ?: [];

        $folders = [];
        $folder_counts = [];
        foreach ( $folder_rows as $row ) {
            $value = (string) ( $row['folder_path'] ?? '' );
            if ( $value === '' ) {
                continue;
            }
            $folders[] = $value;
            $folder_counts[] = [
                'value' => $value,
                'count' => (int) ( $row['item_count'] ?? 0 ),
            ];
        }

        $categories = [];
        $category_counts = [];
        foreach ( $category_rows as $row ) {
            $value = (string) ( $row['category_key'] ?? '' );
            if ( $value === '' ) {
                continue;
            }
            $categories[] = $value;
            $category_counts[] = [
                'value' => $value,
                'count' => (int) ( $row['item_count'] ?? 0 ),
            ];
        }

        return [
            'folders' => array_values( array_unique( $folders ) ),
            'categories' => array_values( array_unique( $categories ) ),
            'folder_counts' => $folder_counts,
            'category_counts' => $category_counts,
        ];
    }

    public static function findByToken( string $token ): ?array {
        if ( function_exists( 'metis_media_ensure_schema' ) ) {
            \metis_media_ensure_schema();
        }

        $table = function_exists( 'metis_media_table_name' ) ? \metis_media_table_name() : 'metis_media_files';
        $row = \metis_db()->fetchOne(
            "SELECT id, storage_class, storage_path FROM {$table} WHERE public_token = %s LIMIT 1",
            [ $token ]
        );

        return is_array( $row ) ? $row : null;
    }
}
