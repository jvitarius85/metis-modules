<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_people_save_role', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_save_role' ),
    ] );
    metis_ajax_register_controller( 'metis_people_bulk_role_action', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_bulk_role_action' ),
    ] );
    metis_ajax_register_controller( 'metis_people_bulk_stripe_role_action', [
        'module' => 'people',
        'permission' => 'workspace_manage',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_bulk_stripe_role_action' ),
    ] );
    metis_ajax_register_controller( 'metis_people_bulk_profile_action', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_bulk_profile_action' ),
    ] );
}

metis_ajax_register_handler( 'metis_people_save_role', function () {
    metis_people_ajax_verify();

    $role_id = isset(metis_request_post()['role_id']) ? (int) metis_runtime_unslash(metis_request_post()['role_id']) : 0;
    $role_key = isset(metis_request_post()['role_key']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['role_key'])) : '';
    $role_domain = isset(metis_request_post()['role_domain']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['role_domain'])) : 'metis';
    $role_name = isset(metis_request_post()['role_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['role_name'])) : '';
    $description = isset(metis_request_post()['description']) ? metis_textarea_clean(metis_runtime_unslash(metis_request_post()['description'])) : '';

    $permissions = [];
    if (isset(metis_request_post()['permissions'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['permissions']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $perm_key) {
                $pk = metis_text_clean((string) $perm_key);
                if ($pk !== '') $permissions[] = $pk;
            }
        }
    }
    $permissions = array_values(array_unique($permissions));

    if ($role_key === '' || $role_name === '') {
        metis_runtime_send_json_error('Role key and role name are required.', 400);
    }

    if (!in_array($role_domain, ['metis', 'stripe', 'workspace'], true)) {
        $role_domain = 'metis';
    }

    $conflict = \Metis\Modules\People\RoleManagementService::roleConflictId($role_key, $role_domain, $role_id);
    if ($conflict > 0) {
        metis_runtime_send_json_error('Role key already exists.', 400);
    }

    $role_id = \Metis\Modules\People\RoleManagementService::saveRole($role_id, $role_key, $role_domain, $role_name, $description, $permissions);
    metis_people_log_activity(null, 'role_saved', 'Saved role definition', [
        'role_key' => $role_key,
        'role_domain' => $role_domain,
        'permission_count' => count($permissions),
    ]);

    metis_runtime_send_json_success([
        'role_id' => $role_id,
        'role_key' => $role_key,
    ]);
});

metis_ajax_register_handler( 'metis_people_bulk_role_action', function () {
    metis_people_ajax_verify();

    $action_type = isset(metis_request_post()['bulk_action']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['bulk_action'])) : '';
    $role_key = isset(metis_request_post()['role_key']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['role_key'])) : '';
    $person_pids = [];
    if (isset(metis_request_post()['person_pids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = metis_text_clean((string) $pid);
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (!in_array($action_type, ['assign', 'remove'], true) || $role_key === '' || empty($person_pids)) {
        metis_runtime_send_json_error('Role, action, and people are required.', 400);
    }

    $role_id = \Metis\Modules\People\RoleManagementService::roleIdByKey($role_key, 'metis');
    if ($role_id < 1) {
        metis_runtime_send_json_error('Invalid Metis role.', 400);
    }

    $updated = 0;
    foreach ($person_pids as $pid) {
        $person_id = \Metis\Modules\People\RoleManagementService::personIdByPid($pid);
        if ($person_id < 1) continue;
        if ($action_type === 'assign') {
            $exists = \Metis\Modules\People\RoleManagementService::hasUserRole($person_id, $role_id);
            if ($exists) continue;
            $ok = \Metis\Modules\People\RoleManagementService::assignUserRole($person_id, $role_id);
            if ($ok) $updated++;
        } else {
            $updated += \Metis\Modules\People\RoleManagementService::removeUserRole($person_id, $role_id);
        }
    }

    metis_people_log_activity(null, 'bulk_role_action', 'Ran bulk role action', [
        'bulk_action' => $action_type,
        'role_key' => $role_key,
        'count' => $updated,
    ]);
    metis_runtime_send_json_success(['updated' => $updated]);
});

metis_ajax_register_handler( 'metis_people_bulk_stripe_role_action', function () {
    metis_people_workspace_ajax_verify();

    $action_type = isset(metis_request_post()['bulk_action']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['bulk_action'])) : '';
    $stripe_role = isset(metis_request_post()['stripe_role']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['stripe_role'])) : '';
    $person_pids = [];
    if (isset(metis_request_post()['person_pids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = metis_text_clean((string) $pid);
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (!in_array($action_type, ['set', 'clear'], true) || empty($person_pids)) {
        metis_runtime_send_json_error('Action and people are required.', 400);
    }
    if ($action_type === 'set') {
        if ($stripe_role === '') metis_runtime_send_json_error('Stripe role is required for set action.', 400);
        $role_exists = \Metis\Modules\People\RoleManagementService::roleIdByKey($stripe_role, 'stripe');
        if ($role_exists < 1) {
            metis_runtime_send_json_error('Invalid Stripe role.', 400);
        }
    }

    $actor = metis_people_get_current_person_id();
    $updated = 0;
    $queued = 0;
    foreach ($person_pids as $pid) {
        $person = \Metis\Modules\People\RoleManagementService::getPersonSummaryByPid($pid);
        if (!$person) continue;
        $person_id = (int) ($person['id'] ?? 0);
        if ($person_id < 1) continue;
        $new_role = $action_type === 'set' ? $stripe_role : null;
        if (!\Metis\Modules\People\RoleManagementService::updateStripeRole($person_id, $new_role)) continue;
        $updated++;

        $workspace_email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
        $is_workspace_user = !empty($person['is_workspace_user']);
        $status = (string) ($person['status'] ?? 'active');
        $lifecycle = (string) ($person['lifecycle_status'] ?? 'active');
        $can_provision = $is_workspace_user && metis_email_is_valid($workspace_email) && $status === 'active' && $lifecycle !== 'alumni';
        $job_type = ($action_type === 'set' && $can_provision) ? 'stripe_user_upsert' : 'stripe_user_disable';
        $job_id = metis_people_workspace_queue_job(
            $job_type,
            'person',
            $person_id,
            $actor > 0 ? $actor : null,
            [
                'person_id' => $person_id,
                'pid' => (string) ($person['pid'] ?? ''),
                'workspace_email' => $workspace_email,
                'stripe_role' => $action_type === 'set' ? $stripe_role : '',
                'reason' => $job_type === 'stripe_user_disable' ? ($action_type === 'clear' ? 'bulk_cleared' : 'workspace_or_status_ineligible') : '',
            ]
        );
        if ($job_id > 0) $queued++;
    }

    metis_people_log_activity(null, 'bulk_stripe_role_action', 'Ran bulk Stripe role action', [
        'bulk_action' => $action_type,
        'stripe_role' => $stripe_role,
        'updated' => $updated,
        'queued' => $queued,
    ]);
    metis_runtime_send_json_success(['updated' => $updated, 'queued' => $queued]);
});

metis_ajax_register_handler( 'metis_people_bulk_profile_action', function () {
    metis_people_ajax_verify();

    $position_type = isset(metis_request_post()['position_type']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['position_type'])) : '';
    $position_value = isset(metis_request_post()['position_value']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['position_value'])) : '';
    $person_pids = [];
    if (isset(metis_request_post()['person_pids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = metis_text_clean((string) $pid);
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (empty($person_pids)) {
        metis_runtime_send_json_error('Select at least one person.', 400);
    }
    if (!in_array($position_type, ['board', 'staff', 'volunteer', 'clear'], true)) {
        metis_runtime_send_json_error('Select a valid position type.', 400);
    }
    if ($position_type !== 'clear' && trim($position_value) === '') {
        metis_runtime_send_json_error('Select a position.', 400);
    }

    $updated = 0;
    foreach ($person_pids as $pid) {
        $person_id = \Metis\Modules\People\RoleManagementService::personIdByPid($pid);
        if ($person_id < 1) continue;
        $ok = \Metis\Modules\People\RoleManagementService::updateBulkProfilePosition($person_id, $position_type, $position_value);
        if ($ok !== false) $updated++;
    }

    metis_people_log_activity(null, 'bulk_profile_action', 'Ran bulk position update', [
        'position_type' => $position_type,
        'position_value' => $position_type === 'clear' ? '' : trim($position_value),
        'updated' => $updated,
    ]);
    metis_runtime_send_json_success(['updated' => $updated]);
});
