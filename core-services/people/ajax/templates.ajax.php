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

    $template_key = isset(metis_request_post()['template_key']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['template_key'])) : '';
    $template_name = isset(metis_request_post()['template_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['template_name'])) : '';
    $description = isset(metis_request_post()['description']) ? metis_textarea_clean(metis_runtime_unslash(metis_request_post()['description'])) : '';
    $checklist_json = null;
    if (isset(metis_request_post()['checklist_json'])) {
        $decoded_checklist = json_decode((string) metis_runtime_unslash(metis_request_post()['checklist_json']), true);
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
    if ($checklist_json === null && isset(metis_request_post()['checklist_text'])) {
        $checklist_json = metis_people_parse_lines_to_json((string) metis_runtime_unslash(metis_request_post()['checklist_text']));
    }
    $role_keys = [];
    if (isset(metis_request_post()['role_keys'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['role_keys']), true);
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
    $actor_id = metis_people_get_current_person_id();
    $template_id = \Metis\Modules\People\RoleTemplateService::saveTemplate($template_key, $template_name, $description, $checklist_json, $actor_id > 0 ? $actor_id : null);
    \Metis\Modules\People\RoleTemplateService::syncTemplateRoles($template_id, $role_keys);
    metis_people_log_activity(null, 'template_saved', 'Saved role template', ['template_key' => $template_key]);
    metis_runtime_send_json_success(['template_key' => $template_key]);
});

metis_ajax_register_handler( 'metis_people_apply_template', function () {
    metis_people_ajax_verify();

    $pid = isset(metis_request_post()['pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['pid'])) : '';
    $template_key = isset(metis_request_post()['template_key']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['template_key'])) : '';
    if ($pid === '' || $template_key === '') {
        metis_runtime_send_json_error('PID and template are required.', 400);
    }
    $person_id = \Metis\Modules\People\LifecycleTaskService::findPersonIdByPid($pid);
    $template_row = \Metis\Modules\People\RoleTemplateService::getTemplateByKey($template_key);
    $template_id = (int) ($template_row['id'] ?? 0);
    if ($person_id < 1 || $template_id < 1) {
        metis_runtime_send_json_error('Invalid PID or template.', 400);
    }
    $role_ids = \Metis\Modules\People\RoleTemplateService::getRoleIdsForTemplate($template_id);
    $added = \Metis\Modules\People\RoleTemplateService::assignMissingRolesToPerson($person_id, $role_ids);
    $checklist = json_decode((string) ($template_row['checklist_json'] ?? ''), true);
    if (!is_array($checklist)) $checklist = [];
    $tasks_added = \Metis\Modules\People\RoleTemplateService::addMissingOnboardingTasks($person_id, $checklist);
    metis_people_log_activity($person_id, 'template_applied', 'Applied role template to person', [
        'template_key' => $template_key,
        'roles_added' => $added,
        'tasks_added' => $tasks_added,
    ]);
    metis_runtime_send_json_success(['added' => $added, 'tasks_added' => $tasks_added]);
});
