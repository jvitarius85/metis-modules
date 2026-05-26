<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class PositionService {
    public static function activePositions(): array {
        $positions_table = \Metis_Tables::get( 'people_positions' );

        return \metis_db()->fetchAll(
            "SELECT id, group_key, position_key, position_label, sort_order
             FROM {$positions_table}
             WHERE is_active = 1
             ORDER BY group_key ASC, sort_order ASC, position_label ASC"
        ) ?: [];
    }

    public static function savePosition( string $group_key, string $position_key, string $position_label, int $sort_order ): array {
        $positions_table = \Metis_Tables::get( 'people_positions' );
        $existing = \metis_db()->fetchOne(
            "SELECT id
             FROM {$positions_table}
             WHERE group_key = %s AND position_key = %s
             LIMIT 1",
            [ $group_key, $position_key ]
        );

        if ( $existing ) {
            \metis_db()->update(
                $positions_table,
                [
                    'position_label' => $position_label,
                    'sort_order' => max( 0, $sort_order ),
                    'is_active' => 1,
                ],
                [ 'id' => (int) $existing['id'] ],
                [ '%s', '%d', '%d' ],
                [ '%d' ]
            );
        } else {
            \metis_db()->insert(
                $positions_table,
                [
                    'group_key' => $group_key,
                    'position_key' => $position_key,
                    'position_label' => $position_label,
                    'sort_order' => max( 0, $sort_order ),
                    'is_active' => 1,
                ],
                [ '%s', '%s', '%s', '%d', '%d' ]
            );
        }

        $saved = \metis_db()->fetchOne(
            "SELECT id, group_key, position_key, position_label, sort_order
             FROM {$positions_table}
             WHERE group_key = %s AND position_key = %s
             LIMIT 1",
            [ $group_key, $position_key ]
        );

        return is_array( $saved ) ? $saved : [];
    }

    public static function deactivatePosition( int $position_id ): void {
        $positions_table = \Metis_Tables::get( 'people_positions' );
        \metis_db()->update(
            $positions_table,
            [ 'is_active' => 0 ],
            [ 'id' => $position_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }
}
