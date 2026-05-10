<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_people_simulate_permission', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_simulate_permission' ),
    ] );
}

metis_ajax_register_handler( 'metis_people_simulate_permission', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $role_perms_table = Metis_Tables::get('people_role_perms');
    $perms_table = Metis_Tables::get('people_permissions');
    $pid = isset(metis_request_post()['pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['pid'])) : '';
    $module = isset(metis_request_post()['module']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['module'])) : '';
    $action = isset(metis_request_post()['action']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['action'])) : '';
    if ($pid === '' || $module === '' || $action === '') {
        metis_runtime_send_json_error('PID, module, and action are required.', 400);
    }
    $person_id = (int) $db->scalar("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
    if ($person_id < 1) {
        metis_runtime_send_json_error('Person not found.', 404);
    }
    $permission_key = $module . '.' . $action;
    $permission_id = (int) $db->scalar(
        "SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1",
        [ $permission_key ]
    );
    if ($permission_id < 1) {
        metis_runtime_send_json_error('Permission key not found.', 404);
    }
    $source_roles = $db->fetchAll(
        "SELECT DISTINCT r.role_key, r.role_name, ur.start_at, ur.end_at
         FROM {$user_roles_table} ur
         INNER JOIN {$roles_table} r ON r.id = ur.role_id
         INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.permission_id = %d AND rp.allow_access = 1
         WHERE ur.person_id = %d
           AND (ur.start_at IS NULL OR ur.start_at <= NOW())
           AND (ur.end_at IS NULL OR ur.end_at >= NOW())
         ORDER BY r.role_name ASC",
        [ $permission_id, $person_id ]
    ) ?: [];
    metis_runtime_send_json_success([
        'permission_key' => $permission_key,
        'allowed' => !empty($source_roles),
        'source_roles' => $source_roles,
    ]);
});
