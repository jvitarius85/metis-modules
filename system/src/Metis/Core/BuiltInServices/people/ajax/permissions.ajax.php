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
    $pid = isset(metis_request_post()['pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['pid'])) : '';
    $module = isset(metis_request_post()['module']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['module'])) : '';
    $action = isset(metis_request_post()['action']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['action'])) : '';
    if ($pid === '' || $module === '' || $action === '') {
        metis_runtime_send_json_error('PID, module, and action are required.', 400);
    }
    metis_runtime_send_json_success(\Metis\Modules\People\PermissionSimulationService::simulateForPerson($pid, $module, $action));
});
