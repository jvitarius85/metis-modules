<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;

/**
 * Menu Service — manages website navigation menus.
 */
final class MenuService {

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
        return is_array( $decoded ) ? $decoded : [];
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
