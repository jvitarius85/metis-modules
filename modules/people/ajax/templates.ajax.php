<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_people_save_template', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_save_template' ),
    ] );
    metis_ajax_register_controller( 'metis_people_apply_template', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_apply_template' ),
    ] );
}

metis_ajax_register_handler( 'metis_people_save_template', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $templates_table = Metis_Tables::get('people_role_templates');
    $template_roles_table = Metis_Tables::get('people_template_roles');
    $roles_table = Metis_Tables::get('people_roles');

    $template_key = isset($_POST['template_key']) ? metis_key_clean(metis_runtime_unslash($_POST['template_key'])) : '';
    $template_name = isset($_POST['template_name']) ? metis_text_clean(metis_runtime_unslash($_POST['template_name'])) : '';
    $description = isset($_POST['description']) ? metis_text_clean(metis_runtime_unslash($_POST['description'])) : '';
    $checklist_json = null;
    if (isset($_POST['checklist_json'])) {
        $decoded_checklist = json_decode((string) metis_runtime_unslash($_POST['checklist_json']), true);
        if (is_array($decoded_checklist)) {
            $items = [];
            foreach ($decoded_checklist as $item) {
                $label = metis_text_clean((string) $item);
                if ($label === '') continue;
                if (!in_array($label, $items, true)) $items[] = $label;
            }
            $checklist_json = metis_json_encode($items);
        }
    }
    if ($checklist_json === null && isset($_POST['checklist_text'])) {
        $checklist_json = metis_people_parse_lines_to_json((string) metis_runtime_unslash($_POST['checklist_text']));
    }
    $role_keys = [];
    if (isset($_POST['role_keys'])) {
        $decoded = json_decode((string) metis_runtime_unslash($_POST['role_keys']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $k) {
                $rk = metis_key_clean((string) $k);
                if ($rk !== '') $role_keys[] = $rk;
            }
        }
    }
    $role_keys = array_values(array_unique($role_keys));
    if ($template_key === '' || $template_name === '') {
        metis_runtime_send_json_error('Template key and name are required.', 400);
    }
    $template_id = (int) $db->scalar("SELECT id FROM {$templates_table} WHERE template_key = %s LIMIT 1", [ $template_key ]);
    $actor_id = metis_people_get_current_person_id();
    if ($template_id > 0) {
        $db->update($templates_table, [
            'template_name' => $template_name,
            'description' => $description,
            'checklist_json' => $checklist_json,
        ], ['id' => $template_id], ['%s', '%s', '%s'], ['%d']);
    } else {
        $db->insert($templates_table, [
            'template_key' => $template_key,
            'template_name' => $template_name,
            'description' => $description,
            'checklist_json' => $checklist_json,
            'created_by_person_id' => $actor_id > 0 ? $actor_id : null,
        ], ['%s', '%s', '%s', '%s', '%d']);
        $template_id = (int) $db->lastInsertId();
    }
    $db->delete($template_roles_table, ['template_id' => $template_id], ['%d']);
    foreach ($role_keys as $role_key) {
        $role_id = (int) $db->scalar("SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1", [ $role_key ]);
        if ($role_id < 1) continue;
        $db->insert($template_roles_table, ['template_id' => $template_id, 'role_id' => $role_id], ['%d', '%d']);
    }
    metis_people_log_activity(null, 'template_saved', 'Saved role template', ['template_key' => $template_key]);
    metis_runtime_send_json_success(['template_key' => $template_key]);
});

metis_ajax_register_handler( 'metis_people_apply_template', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $templates_table = Metis_Tables::get('people_role_templates');
    $template_roles_table = Metis_Tables::get('people_template_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $tasks_table = Metis_Tables::get('people_lifecycle_tasks');

    $pid = isset($_POST['pid']) ? metis_text_clean(metis_runtime_unslash($_POST['pid'])) : '';
    $template_key = isset($_POST['template_key']) ? metis_key_clean(metis_runtime_unslash($_POST['template_key'])) : '';
    if ($pid === '' || $template_key === '') {
        metis_runtime_send_json_error('PID and template are required.', 400);
    }
    $person_id = (int) $db->scalar("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
    $template_row = $db->fetchOne("SELECT id, checklist_json FROM {$templates_table} WHERE template_key = %s LIMIT 1", [ $template_key ]);
    $template_id = (int) ($template_row['id'] ?? 0);
    if ($person_id < 1 || $template_id < 1) {
        metis_runtime_send_json_error('Invalid PID or template.', 400);
    }
    $role_ids = $db->column("SELECT role_id FROM {$template_roles_table} WHERE template_id = %d", [ $template_id ]) ?: [];
    $added = 0;
    foreach ($role_ids as $rid) {
        $role_id = (int) $rid;
        if ($role_id < 1) continue;
        $exists = (int) $db->scalar("SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", [ $person_id, $role_id ]);
        if ($exists > 0) continue;
        $ok = $db->insert($user_roles_table, ['person_id' => $person_id, 'role_id' => $role_id], ['%d', '%d']);
        if ($ok) $added++;
    }
    $checklist = json_decode((string) ($template_row['checklist_json'] ?? ''), true);
    if (!is_array($checklist)) $checklist = [];
    $tasks_added = 0;
    foreach ($checklist as $task_label_raw) {
        $task_label = metis_text_clean((string) $task_label_raw);
        if ($task_label === '') continue;
        $exists = (int) $db->scalar(
            "SELECT id FROM {$tasks_table}
             WHERE person_id = %d
               AND phase = 'onboarding'
               AND task_label = %s
               AND status IN ('pending','in_progress')
             LIMIT 1",
            [ $person_id, $task_label ]
        );
        if ($exists > 0) continue;
        $ok = $db->insert($tasks_table, [
            'person_id' => $person_id,
            'phase' => 'onboarding',
            'task_label' => $task_label,
            'status' => 'pending',
        ], ['%d', '%s', '%s', '%s']);
        if ($ok) $tasks_added++;
    }
    metis_people_log_activity($person_id, 'template_applied', 'Applied role template to person', [
        'template_key' => $template_key,
        'roles_added' => $added,
        'tasks_added' => $tasks_added,
    ]);
    metis_runtime_send_json_success(['added' => $added, 'tasks_added' => $tasks_added]);
});
