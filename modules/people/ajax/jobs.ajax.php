<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_people_add_lifecycle_task', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_add_lifecycle_task' ),
    ] );
    metis_ajax_register_controller( 'metis_people_complete_lifecycle_task', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_complete_lifecycle_task' ),
    ] );
}

metis_ajax_register_handler( 'metis_people_add_lifecycle_task', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $tasks_table = Metis_Tables::get('people_lifecycle_tasks');
    $pid = isset($_POST['pid']) ? metis_text_clean(metis_runtime_unslash($_POST['pid'])) : '';
    $phase = isset($_POST['phase']) ? metis_key_clean(metis_runtime_unslash($_POST['phase'])) : 'onboarding';
    $task_label = isset($_POST['task_label']) ? metis_text_clean(metis_runtime_unslash($_POST['task_label'])) : '';
    $due_at = isset($_POST['due_at']) ? metis_text_clean(metis_runtime_unslash($_POST['due_at'])) : '';
    if ($pid === '' || $task_label === '') {
        metis_runtime_send_json_error('PID and task label are required.', 400);
    }
    if (!in_array($phase, ['onboarding', 'offboarding'], true)) {
        $phase = 'onboarding';
    }
    if ($due_at !== '' && strlen($due_at) === 16) $due_at .= ':00';
    $due_at = str_replace('T', ' ', $due_at);
    if ($due_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $due_at)) {
        $due_at = '';
    }
    $person_id = (int) $db->scalar("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
    if ($person_id < 1) {
        metis_runtime_send_json_error('Person not found.', 404);
    }
    $db->insert($tasks_table, [
        'person_id' => $person_id,
        'phase' => $phase,
        'task_label' => $task_label,
        'status' => 'pending',
        'due_at' => $due_at !== '' ? $due_at : null,
    ], ['%d', '%s', '%s', '%s', '%s']);
    $task_id = (int) $db->lastInsertId();
    metis_people_log_activity($person_id, 'lifecycle_task_added', 'Added lifecycle task', ['task_id' => $task_id, 'phase' => $phase]);
    metis_runtime_send_json_success([
        'task' => [
            'id' => $task_id,
            'phase' => $phase,
            'task_label' => $task_label,
            'status' => 'pending',
            'due_at' => $due_at,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_people_complete_lifecycle_task', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $tasks_table = Metis_Tables::get('people_lifecycle_tasks');
    $task_id = isset($_POST['task_id']) ? (int) metis_runtime_unslash($_POST['task_id']) : 0;
    if ($task_id < 1) {
        metis_runtime_send_json_error('Invalid task id.', 400);
    }
    $task = $db->fetchOne("SELECT id, person_id, status FROM {$tasks_table} WHERE id = %d LIMIT 1", [ $task_id ]);
    if (!$task) {
        metis_runtime_send_json_error('Task not found.', 404);
    }
    if ((string) ($task['status'] ?? '') === 'completed') {
        metis_runtime_send_json_success(['already_completed' => 1]);
    }
    $db->update($tasks_table, [
        'status' => 'completed',
        'completed_at' => metis_current_time('mysql'),
    ], ['id' => $task_id], ['%s', '%s'], ['%d']);
    metis_people_log_activity((int) $task['person_id'], 'lifecycle_task_completed', 'Completed lifecycle task', ['task_id' => $task_id]);
    metis_runtime_send_json_success(['task_id' => $task_id, 'status' => 'completed']);
});
