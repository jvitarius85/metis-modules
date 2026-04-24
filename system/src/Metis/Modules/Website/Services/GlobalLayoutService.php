<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;
use Metis\Modules\Website\Entities\GlobalLayout;

/**
 * Global Layout Service — manages header and footer layouts.
 */
final class GlobalLayoutService {

    public static function getDefault( string $type ): ?GlobalLayout {
        $db    = self::db();
        $table = \Metis_Tables::get( 'website_global_layouts' );
        $row   = $db->fetchOne(
            "SELECT * FROM {$table} WHERE type = %s AND status = 'published' AND is_default = 1 ORDER BY id DESC LIMIT 1",
            [ $type ]
        );
        if ( is_array( $row ) ) {
            return GlobalLayout::fromRow( $row );
        }
        // Fall back to any published layout of this type
        $row = $db->fetchOne(
            "SELECT * FROM {$table} WHERE type = %s AND status = 'published' ORDER BY id DESC LIMIT 1",
            [ $type ]
        );
        return is_array( $row ) ? GlobalLayout::fromRow( $row ) : null;
    }

    public static function getAll( string $type = '' ): array {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'website_global_layouts' );
        $where  = $type !== '' ? ' WHERE type = %s' : '';
        $params = $type !== '' ? [ $type ] : [];
        $rows   = $db->fetchAll( "SELECT * FROM {$table}{$where} ORDER BY type ASC, name ASC", $params );
        return is_array( $rows )
            ? array_map( [ GlobalLayout::class, 'fromRow' ], $rows )
            : [];
    }

    public static function getById( int $id ): ?GlobalLayout {
        $row = self::db()->fetchOne( "SELECT * FROM " . \Metis_Tables::get( 'website_global_layouts' ) . " WHERE id = %d", [ $id ] );
        return is_array( $row ) ? GlobalLayout::fromRow( $row ) : null;
    }

    public static function create( array $data ): int|false {
        $db = self::db();
        $result = $db->insert( \Metis_Tables::get( 'website_global_layouts' ), [
            'type'       => $data['type'] ?? 'header',
            'name'       => $data['name'] ?? 'Untitled Layout',
            'layout_json'=> $data['layout_json'] ?? null,
            'status'     => $data['status'] ?? 'draft',
            'is_default' => ! empty( $data['is_default'] ) ? 1 : 0,
            'created_by' => $data['created_by'] ?? self::currentUserId(),
            'updated_by' => $data['updated_by'] ?? self::currentUserId(),
        ] );
        return $result ? (int) $db->lastInsertId() : false;
    }

    public static function update( int $id, array $data ): bool {
        $update = [];
        foreach ( [ 'name', 'layout_json', 'status', 'is_default' ] as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $update[ $f ] = $data[ $f ];
            }
        }
        $update['updated_by'] = $data['updated_by'] ?? self::currentUserId();
        return (bool) self::db()->update( \Metis_Tables::get( 'website_global_layouts' ), $update, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        return (bool) self::db()->delete( \Metis_Tables::get( 'website_global_layouts' ), [ 'id' => $id ] );
    }

    private static function currentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) return null;
        $uid = metis_current_user_id();
        return $uid > 0 ? $uid : null;
    }

    private static function db(): object {
        return Application::service( 'db' );
    }
}
