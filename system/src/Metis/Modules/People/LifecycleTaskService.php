<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class LifecycleTaskService {
    public static function findPersonIdByPid( string $pid ): int {
        $people_table = \Metis_Tables::get( 'people' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1",
            [ $pid ]
        );
    }

    public static function addTask( int $person_id, string $phase, string $task_label, ?string $due_at = null ): array {
        $tasks_table = \Metis_Tables::get( 'people_lifecycle_tasks' );
        $payload = [
            'person_id' => $person_id,
            'phase' => $phase,
            'task_label' => $task_label,
            'status' => 'pending',
            'due_at' => $due_at,
        ];

        \metis_db()->insert( $tasks_table, $payload, [ '%d', '%s', '%s', '%s', '%s' ] );

        return [
            'id' => (int) \metis_db()->lastInsertId(),
            'phase' => $phase,
            'task_label' => $task_label,
            'status' => 'pending',
            'due_at' => $due_at ?? '',
        ];
    }

    public static function getTask( int $task_id ): ?array {
        $tasks_table = \Metis_Tables::get( 'people_lifecycle_tasks' );
        $task = \metis_db()->fetchOne(
            "SELECT id, person_id, status FROM {$tasks_table} WHERE id = %d LIMIT 1",
            [ $task_id ]
        );

        return is_array( $task ) ? $task : null;
    }

    public static function completeTask( int $task_id ): void {
        $tasks_table = \Metis_Tables::get( 'people_lifecycle_tasks' );

        \metis_db()->update(
            $tasks_table,
            [
                'status' => 'completed',
                'completed_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $task_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }
}
