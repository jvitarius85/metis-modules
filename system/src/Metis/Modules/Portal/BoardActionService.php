<?php
declare(strict_types=1);

namespace Metis\Modules\Portal;

final class BoardActionService {
    public static function counts( int $person_id ): array {
        $db = \metis_db();
        $board_actions_table = \Metis_Tables::get( 'board_action_items' );
        $today_date = date( 'Y-m-d' );
        $due7_date = ( new \DateTimeImmutable( 'today' ) )->modify( '+7 days' )->format( 'Y-m-d' );

        return [
            'mine' => (int) $db->scalar(
                "SELECT COUNT(*) FROM {$board_actions_table}
                 WHERE owner_person_id = %d
                   AND status NOT IN ('done','completed','closed')",
                [ $person_id ]
            ),
            'overdue' => (int) $db->scalar(
                "SELECT COUNT(*) FROM {$board_actions_table}
                 WHERE owner_person_id = %d
                   AND status NOT IN ('done','completed','closed')
                   AND due_date IS NOT NULL
                   AND due_date < %s",
                [ $person_id, $today_date ]
            ),
            'due7' => (int) $db->scalar(
                "SELECT COUNT(*) FROM {$board_actions_table}
                 WHERE owner_person_id = %d
                   AND status NOT IN ('done','completed','closed')
                   AND due_date IS NOT NULL
                   AND due_date >= %s
                   AND due_date <= %s",
                [ $person_id, $today_date, $due7_date ]
            ),
            'done' => (int) $db->scalar(
                "SELECT COUNT(*) FROM {$board_actions_table}
                 WHERE owner_person_id = %d
                   AND status IN ('done','completed','closed')",
                [ $person_id ]
            ),
        ];
    }

    public static function dashboardCounts( int $person_id ): array {
        if ( $person_id < 1 ) {
            return [
                'mine' => 0,
                'overdue' => 0,
                'due7' => 0,
                'done' => 0,
                'today' => 0,
                'blocked' => 0,
            ];
        }

        $db = \metis_db();
        $board_actions_table = \Metis_Tables::get( 'board_action_items' );
        $today_date = date( 'Y-m-d' );
        $due7_date = ( new \DateTimeImmutable( 'today' ) )->modify( '+7 days' )->format( 'Y-m-d' );
        $counts = (array) ( $db->fetchOne(
            "SELECT
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') THEN 1 ELSE 0 END) AS mine,
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') AND a.due_date IS NOT NULL AND a.due_date < %s THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') AND a.due_date IS NOT NULL AND a.due_date >= %s AND a.due_date <= %s THEN 1 ELSE 0 END) AS due7,
                SUM(CASE WHEN a.status IN ('done','completed','closed') THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') AND a.due_date = %s THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN LOWER(COALESCE(a.status, '')) IN ('blocked','on_hold','stalled') THEN 1 ELSE 0 END) AS blocked
             FROM {$board_actions_table} a
             WHERE a.owner_person_id = %d",
            [ $today_date, $today_date, $due7_date, $today_date, $person_id ]
        ) ?? [] );

        return [
            'mine' => (int) ( $counts['mine'] ?? 0 ),
            'overdue' => (int) ( $counts['overdue'] ?? 0 ),
            'due7' => (int) ( $counts['due7'] ?? 0 ),
            'done' => (int) ( $counts['done'] ?? 0 ),
            'today' => (int) ( $counts['today'] ?? 0 ),
            'blocked' => (int) ( $counts['blocked'] ?? 0 ),
        ];
    }

    public static function fetchForPerson( int $person_id, string $filter, int $limit = 8 ): array {
        $db = \metis_db();
        $board_actions_table = \Metis_Tables::get( 'board_action_items' );
        $board_meetings_table = \Metis_Tables::get( 'board_meetings' );
        $today_date = date( 'Y-m-d' );
        $due7_date = ( new \DateTimeImmutable( 'today' ) )->modify( '+7 days' )->format( 'Y-m-d' );

        $where = [
            'a.owner_person_id = %d',
        ];
        $params = [ $person_id ];

        switch ( $filter ) {
            case 'overdue':
                $where[] = "a.status NOT IN ('done','completed','closed')";
                $where[] = 'a.due_date IS NOT NULL';
                $where[] = 'a.due_date < %s';
                $params[] = $today_date;
                break;
            case 'today':
                $where[] = "a.status NOT IN ('done','completed','closed')";
                $where[] = 'a.due_date = %s';
                $params[] = $today_date;
                break;
            case 'blocked':
                $where[] = "LOWER(COALESCE(a.status, '')) IN ('blocked','on_hold','stalled')";
                break;
            case 'due7':
                $where[] = "a.status NOT IN ('done','completed','closed')";
                $where[] = 'a.due_date IS NOT NULL';
                $where[] = 'a.due_date >= %s';
                $where[] = 'a.due_date <= %s';
                $params[] = $today_date;
                $params[] = $due7_date;
                break;
            case 'done':
                $where[] = "a.status IN ('done','completed','closed')";
                break;
            case 'mine':
            default:
                $where[] = "a.status NOT IN ('done','completed','closed')";
                $filter = 'mine';
                break;
        }

        $params[] = max( 1, min( 50, $limit ) );
        $actions = $db->fetchAll(
            "SELECT a.id, a.title, a.status, a.priority, a.due_date, a.meeting_id,
                    m.title AS meeting_title, m.meeting_date
             FROM {$board_actions_table} a
             LEFT JOIN {$board_meetings_table} m ON m.id = a.meeting_id
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY
               CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END ASC,
               a.due_date ASC,
               a.id DESC
             LIMIT %d",
            $params
        ) ?: [];

        return [
            'filter' => $filter,
            'actions' => $actions,
        ];
    }
}
