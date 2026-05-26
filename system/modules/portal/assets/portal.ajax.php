<?php
if (!defined('METIS_ROOT')) exit;

function metis_portal_ajax_verify(): void {
    $nonce = '';
    foreach (['metis_action_nonce', 'nonce'] as $field) {
        $value = metis_request_post()[$field] ?? '';
        if (is_scalar($value)) {
            $nonce = trim((string) metis_runtime_unslash($value));
            if ($nonce !== '') {
                break;
            }
        }
    }

    $nonce_action = function_exists('metis_ajax_nonce_action')
        ? metis_ajax_nonce_action('metis_portal_fetch_board_actions')
        : 'metis_portal_fetch_board_actions';

    if ($nonce === '' || !function_exists('metis_runtime_verify_nonce') || !metis_runtime_verify_nonce($nonce, $nonce_action)) {
        metis_runtime_send_json_error([ 'message' => 'Invalid nonce.' ], 403);
    }

    if (!function_exists('metis_security_user_can') || !metis_security_user_can('portal.view')) {
        metis_runtime_send_json_error([ 'message' => 'Unauthorized.' ], 403);
    }
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

    $filter = metis_key_clean(metis_runtime_unslash(metis_request_post()['filter'] ?? 'mine'));

    $result = \Metis\Modules\Portal\BoardActionService::fetchForPerson($current_person_id, $filter);
    $counts = \Metis\Modules\Portal\BoardActionService::counts($current_person_id);
    $html = metis_portal_render_board_actions_html($result['actions']);

    metis_runtime_send_json_success([
        'active_filter' => (string) $result['filter'],
        'counts' => $counts,
        'html' => $html,
    ]);
});
