<?php
if (!defined('METIS_ROOT')) exit;

function metis_portal_ajax_verify(): void {
}

function metis_portal_board_action_counts(object $db, string $board_actions_table, int $person_id): array {
    $today_date = date('Y-m-d');
    $due7_date = (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');

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

function metis_portal_fetch_board_actions(object $db, string $board_actions_table, string $board_meetings_table, int $person_id, string $filter): array {
    $today_date = date('Y-m-d');
    $due7_date = (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');

    $where = [
        'a.owner_person_id = %d',
    ];
    $params = [ $person_id ];

    switch ($filter) {
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

    $params[] = 8;

    $actions = $db->fetchAll(
        "SELECT a.id, a.title, a.status, a.priority, a.due_date, a.meeting_id,
                m.title AS meeting_title, m.meeting_date
         FROM {$board_actions_table} a
         LEFT JOIN {$board_meetings_table} m ON m.id = a.meeting_id
         WHERE " . implode(' AND ', $where) . "
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

function metis_portal_render_board_actions_html(array $actions): string {
    ob_start();
    if ($actions === []) {
        ?>
        <div class="metis-portal-hub-empty">No board actions for this filter. <a href="<?php echo metis_escape_url(metis_portal_url('board', 'meeting')); ?>">Create action item</a>.</div>
        <?php
        return (string) ob_get_clean();
    }
    ?>
    <div class="metis-portal-my-actions">
        <?php foreach ($actions as $action) : ?>
            <?php
            $due_raw = (string) ($action['due_date'] ?? '');
            $due_display = $due_raw !== '' ? date('M j, Y', strtotime($due_raw)) : 'No due date';
            $is_overdue = $due_raw !== '' && $due_raw < date('Y-m-d');
            $meeting_title = trim((string) ($action['meeting_title'] ?? ''));
            $meeting_label = $meeting_title !== '' ? $meeting_title : 'Board meeting';
            $meeting_id = (int) ($action['meeting_id'] ?? 0);
            $action_url = $meeting_id > 0 ? metis_portal_url('board', 'meeting', [ 'meeting' => $meeting_id ]) : metis_portal_url('board', 'dashboard');
            ?>
            <article class="metis-portal-my-action-item<?php echo $is_overdue ? ' is-overdue' : ''; ?>">
                <div class="metis-portal-my-action-main">
                    <h3><?php echo metis_escape_html((string) ($action['title'] ?? 'Action item')); ?></h3>
                    <p><?php echo metis_escape_html($meeting_label); ?></p>
                </div>
                <div class="metis-portal-my-action-meta">
                    <span class="metis-portal-my-action-status"><?php echo metis_escape_html(ucfirst((string) ($action['status'] ?? 'open'))); ?></span>
                    <span class="metis-portal-my-action-due"><?php echo metis_escape_html($due_display); ?></span>
                    <a class="metis-chip" href="<?php echo metis_escape_url($action_url); ?>">Open</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

metis_ajax_register_controller('metis_portal_fetch_board_actions', [
    'module' => 'portal',
    'permission' => 'view',
    'nonce_action' => metis_ajax_nonce_action('metis_portal_fetch_board_actions'),
]);

metis_ajax_register_handler('metis_portal_fetch_board_actions', function (): void {
    metis_portal_ajax_verify();

    $current_person_id = function_exists('metis_auth_current_person_id') ? (int) metis_auth_current_person_id() : 0;
    if ($current_person_id <= 0) {
        metis_runtime_send_json_error([ 'message' => 'No profile linked to current user.' ], 422);
    }

    $db = metis_db();
    $board_actions_table = Metis_Tables::get('board_action_items');
    $board_meetings_table = Metis_Tables::get('board_meetings');
    $filter = metis_key_clean(metis_runtime_unslash($_POST['filter'] ?? 'mine'));

    $result = metis_portal_fetch_board_actions($db, $board_actions_table, $board_meetings_table, $current_person_id, $filter);
    $counts = metis_portal_board_action_counts($db, $board_actions_table, $current_person_id);
    $html = metis_portal_render_board_actions_html($result['actions']);

    metis_runtime_send_json_success([
        'active_filter' => (string) $result['filter'],
        'counts' => $counts,
        'html' => $html,
    ]);
});
