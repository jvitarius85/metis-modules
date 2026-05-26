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
    $pid = isset(metis_request_post()['pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['pid'])) : '';
    $phase = isset(metis_request_post()['phase']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['phase'])) : 'onboarding';
    $task_label = isset(metis_request_post()['task_label']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['task_label'])) : '';
    $due_at = isset(metis_request_post()['due_at']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['due_at'])) : '';
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
    $person_id = \Metis\Modules\People\LifecycleTaskService::findPersonIdByPid($pid);
    if ($person_id < 1) {
        metis_runtime_send_json_error('Person not found.', 404);
    }
    $task = \Metis\Modules\People\LifecycleTaskService::addTask($person_id, $phase, $task_label, $due_at !== '' ? $due_at : null);
    $task_id = (int) ($task['id'] ?? 0);
    metis_people_log_activity($person_id, 'lifecycle_task_added', 'Added lifecycle task', ['task_id' => $task_id, 'phase' => $phase]);
    metis_runtime_send_json_success([
        'task' => $task,
    ]);
});

metis_ajax_register_handler( 'metis_people_complete_lifecycle_task', function () {
    metis_people_ajax_verify();
    $task_id = isset(metis_request_post()['task_id']) ? (int) metis_runtime_unslash(metis_request_post()['task_id']) : 0;
    if ($task_id < 1) {
        metis_runtime_send_json_error('Invalid task id.', 400);
    }
    $task = \Metis\Modules\People\LifecycleTaskService::getTask($task_id);
    if (!$task) {
        metis_runtime_send_json_error('Task not found.', 404);
    }
    if ((string) ($task['status'] ?? '') === 'completed') {
        metis_runtime_send_json_success(['already_completed' => 1]);
    }
    \Metis\Modules\People\LifecycleTaskService::completeTask($task_id);
    metis_people_log_activity((int) $task['person_id'], 'lifecycle_task_completed', 'Completed lifecycle task', ['task_id' => $task_id]);
    metis_runtime_send_json_success(['task_id' => $task_id, 'status' => 'completed']);
});
