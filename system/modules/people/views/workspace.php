<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_workspace_manage()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Workspace Management.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();
$can_manage = metis_people_can_workspace_manage();

$workspace_users_table = Metis_Tables::get('people_workspace_users');
$workspace_user_roles_table = Metis_Tables::get('people_workspace_user_roles');
$workspace_groups_table = Metis_Tables::get('people_workspace_groups');
$workspace_group_members_table = Metis_Tables::get('people_workspace_group_members');
$workspace_security_actions_table = Metis_Tables::get('people_workspace_security_actions');
$workspace_sync_jobs_table = Metis_Tables::get('people_workspace_sync_jobs');
$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');

$workspace_users = $db->fetchAll(
    "SELECT wu.*, p.pid AS linked_pid, p.display_name AS linked_name
     FROM {$workspace_users_table} wu
     LEFT JOIN {$people_table} p ON p.id = wu.person_id
     ORDER BY wu.display_name ASC, wu.primary_email ASC
     LIMIT 500",
) ?: [];

$role_rows = $db->fetchAll( "SELECT role_key, role_name FROM {$roles_table} WHERE role_domain = 'workspace' ORDER BY role_name ASC" ) ?: [];
$workspace_roles = ['' => 'No Admin Role'];
foreach ($role_rows as $r) {
    $rk = (string) ($r['role_key'] ?? '');
    if ($rk === '') continue;
    $label = trim((string) ($r['role_name'] ?? $rk));
    if ($label === '') $label = $rk;
    $workspace_roles[$rk] = $label;
}

$roles_by_user = [];
$role_assign_rows = $db->fetchAll(
    "SELECT workspace_user_id, role_key FROM {$workspace_user_roles_table}",
) ?: [];
foreach ($role_assign_rows as $row) {
    $uid = (int) ($row['workspace_user_id'] ?? 0);
    $role_key = (string) ($row['role_key'] ?? '');
    if ($uid < 1 || $role_key === '') continue;
    if (!isset($roles_by_user[$uid])) $roles_by_user[$uid] = [];
    $roles_by_user[$uid][] = $role_key;
}

$workspace_groups = $db->fetchAll(
    "SELECT wg.*,
            (SELECT COUNT(*) FROM {$workspace_group_members_table} gm WHERE gm.group_id = wg.id) AS member_count
     FROM {$workspace_groups_table} wg
     ORDER BY wg.group_name ASC
     LIMIT 500",
) ?: [];

$sync_page_size = 12;
$security_page_size = 12;
$sync_page = isset($_GET['sync_page']) ? (int) metis_runtime_unslash($_GET['sync_page']) : 1;
$security_page = isset($_GET['security_page']) ? (int) metis_runtime_unslash($_GET['security_page']) : 1;
if ($sync_page < 1) $sync_page = 1;
if ($security_page < 1) $security_page = 1;

$sync_total = (int) $db->scalar("SELECT COUNT(*) FROM {$workspace_sync_jobs_table}");
$security_total = (int) $db->scalar("SELECT COUNT(*) FROM {$workspace_security_actions_table}");
$sync_total_pages = max(1, (int) ceil($sync_total / $sync_page_size));
$security_total_pages = max(1, (int) ceil($security_total / $security_page_size));
if ($sync_page > $sync_total_pages) $sync_page = $sync_total_pages;
if ($security_page > $security_total_pages) $security_page = $security_total_pages;
$sync_offset = ($sync_page - 1) * $sync_page_size;
$security_offset = ($security_page - 1) * $security_page_size;

$group_members = $db->fetchAll(
    "SELECT gm.group_id, gm.workspace_user_id, gm.member_role, wu.primary_email, wu.display_name
     FROM {$workspace_group_members_table} gm
     INNER JOIN {$workspace_users_table} wu ON wu.id = gm.workspace_user_id
     ORDER BY gm.group_id ASC, wu.display_name ASC",
) ?: [];
$members_by_group = [];
$groups_by_user = [];
foreach ($group_members as $gm) {
    $gid = (int) ($gm['group_id'] ?? 0);
    $uid = (int) ($gm['workspace_user_id'] ?? 0);
    if ($gid < 1) continue;
    if (!isset($members_by_group[$gid])) $members_by_group[$gid] = [];
    $members_by_group[$gid][] = $gm;
    if ($uid > 0) {
        if (!isset($groups_by_user[$uid])) $groups_by_user[$uid] = [];
        $groups_by_user[$uid][] = $gid;
    }
}

$security_actions = $db->fetchAll(
    "SELECT sa.*, wu.primary_email, wu.display_name, wu.person_id,
            p.pid AS person_pid, p.display_name AS person_display_name
     FROM {$workspace_security_actions_table} sa
     INNER JOIN {$workspace_users_table} wu ON wu.id = sa.workspace_user_id
     LEFT JOIN {$people_table} p ON p.id = wu.person_id
     ORDER BY sa.created_at DESC
     LIMIT {$security_page_size} OFFSET {$security_offset}",
) ?: [];

$sync_jobs = $db->fetchAll(
    "SELECT *
     FROM {$workspace_sync_jobs_table}
     ORDER BY created_at DESC
     LIMIT {$sync_page_size} OFFSET {$sync_offset}",
) ?: [];

$sync_workspace_user_names = [];
$sync_workspace_group_names = [];
$sync_person_names = [];
$sync_workspace_user_urls = [];
$sync_person_urls = [];
$sync_workspace_user_ids = [];
$sync_workspace_group_ids = [];
$sync_person_ids = [];
$normalize_person_name = static function (string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '') return '';
    return trim((string) preg_replace('/\s*\([A-Z]{2,6}-\d+\)\s*$/', '', $trimmed));
};
foreach ($sync_jobs as $sync_job_row) {
    $entity_type = strtolower(trim((string) ($sync_job_row['entity_type'] ?? '')));
    $entity_id = (int) ($sync_job_row['entity_id'] ?? 0);
    if ($entity_id < 1) continue;
    if ($entity_type === 'workspace_user') $sync_workspace_user_ids[$entity_id] = true;
    if ($entity_type === 'workspace_group') $sync_workspace_group_ids[$entity_id] = true;
    if ($entity_type === 'person') $sync_person_ids[$entity_id] = true;
}
if (!empty($sync_workspace_user_ids)) {
    $ids = implode(',', array_map('intval', array_keys($sync_workspace_user_ids)));
    $rows = $db->fetchAll(
        "SELECT wu.id, wu.primary_email, wu.display_name, wu.first_name, wu.last_name,
                p.pid AS person_pid, p.display_name AS person_display_name,
                p.first_name AS person_first_name, p.last_name AS person_last_name
         FROM {$workspace_users_table} wu
         LEFT JOIN {$people_table} p ON p.id = wu.person_id
         WHERE wu.id IN ({$ids})"
    ) ?: [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) continue;
        $person_pid = trim((string) ($row['person_pid'] ?? ''));
        $person_display = $normalize_person_name(trim((string) ($row['person_display_name'] ?? '')));
        $person_name = trim((string) ($row['person_first_name'] ?? '') . ' ' . (string) ($row['person_last_name'] ?? ''));
        if ($person_name === '') $person_name = $person_display;
        if ($person_display !== '') {
            $sync_workspace_user_names[$id] = $person_name;
            if ($person_pid !== '') {
                $sync_workspace_user_urls[$id] = metis_people_person_url($person_pid);
            }
            continue;
        }
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name === '') $name = trim((string) ($row['display_name'] ?? ''));
        if ($name === '') $name = 'Workspace user';
        $sync_workspace_user_names[$id] = $name;
    }
}
if (!empty($sync_workspace_group_ids)) {
    $ids = implode(',', array_map('intval', array_keys($sync_workspace_group_ids)));
    $rows = $db->fetchAll(
        "SELECT id, group_name, group_email
         FROM {$workspace_groups_table}
         WHERE id IN ({$ids})"
    ) ?: [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) continue;
        $group_name = trim((string) ($row['group_name'] ?? ''));
        $group_email = trim((string) ($row['group_email'] ?? ''));
        if ($group_name === '') $group_name = $group_email;
        $sync_workspace_group_names[$id] = $group_name !== '' ? $group_name : 'Workspace group';
    }
}
if (!empty($sync_person_ids)) {
    $ids = implode(',', array_map('intval', array_keys($sync_person_ids)));
    $rows = $db->fetchAll(
        "SELECT id, pid, display_name, first_name, last_name
         FROM {$people_table}
         WHERE id IN ({$ids})"
    ) ?: [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) continue;
        $display_name = $normalize_person_name(trim((string) ($row['display_name'] ?? '')));
        $person_name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($person_name === '') $person_name = $display_name;
        $pid = trim((string) ($row['pid'] ?? ''));
        if ($person_name === '') $person_name = 'Person';
        $sync_person_names[$id] = $person_name;
        if ($pid !== '') {
            $sync_person_urls[$id] = metis_people_person_url($pid);
        }
    }
}

$kpi_total_users = 0;
foreach ($workspace_users as $workspace_user_row) {
    $workspace_meta = json_decode((string) ($workspace_user_row['metadata_json'] ?? ''), true);
    if (is_array($workspace_meta) && !empty($workspace_meta['ui_hidden'])) {
        continue;
    }
    $kpi_total_users++;
}
$kpi_suspended = (int) $db->scalar( "SELECT COUNT(*) FROM {$workspace_users_table} WHERE is_suspended = 1" );
$kpi_groups = (int) count($workspace_groups);
$kpi_pending_jobs = (int) $db->scalar( "SELECT COUNT(*) FROM {$workspace_sync_jobs_table} WHERE status IN ('queued', 'processing')" );
$job_type_labels = [
    'workspace_user_create' => 'Create Workspace user',
    'workspace_user_upsert' => 'Update Workspace user',
    'workspace_security_action' => 'Run security action',
    'workspace_group_create' => 'Create Workspace group',
    'workspace_group_upsert' => 'Update Workspace group',
    'workspace_group_member_upsert' => 'Update group member',
    'workspace_group_members_sync' => 'Sync group members',
    'workspace_group_members_bulk_sync' => 'Sync group members',
    'workspace_group_permissions_sync' => 'Sync group permissions',
    'workspace_directory_import' => 'Import directory users',
    'stripe_user_upsert' => 'Apply Stripe access',
    'stripe_user_disable' => 'Remove Stripe access',
];
$security_action_labels = [
    'reset_password' => 'Force password reset',
    'revoke_sessions' => 'Revoke sessions',
    'force_2fa_reenroll' => 'Reset 2FA enrollment',
    'suspend_account' => 'Suspend account',
    'unsuspend_account' => 'Unsuspend account',
];
$status_labels = [
    'queued' => 'Queued',
    'processing' => 'In Progress',
    'pending' => 'Pending',
    'completed' => 'Completed',
    'synced' => 'Completed',
    'failed' => 'Failed',
];
$format_timestamp = static function (?string $value): string {
    $raw = trim((string) $value);
    if ($raw === '') return 'Unknown time';
    $ts = strtotime($raw);
    if (!$ts) return $raw;
    return date('M j, Y g:i a', $ts);
};
$format_status = static function (?string $status) use ($status_labels): string {
    $key = strtolower(trim((string) $status));
    if ($key === '') return 'Unknown';
    return $status_labels[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
};
$workspace_action_icon = static function (string $icon, string $label): string {
    $src = metis_home_url('assets/Images/icons/' . rawurlencode($icon) . '.svg');

    return '<img src="' . metis_escape_url($src) . '" alt="" aria-hidden="true"><span class="metis-sr-only">' . metis_escape_html($label) . '</span>';
};
$format_entity = static function (?string $entity_type, $entity_id) use ($sync_workspace_user_names, $sync_workspace_group_names, $sync_person_names): string {
    $type = strtolower(trim((string) $entity_type));
    $id = (int) $entity_id;
    if ($type === 'workspace_user') return $id > 0 ? ($sync_workspace_user_names[$id] ?? 'Workspace user') : 'Workspace user';
    if ($type === 'workspace_group') return $id > 0 ? ($sync_workspace_group_names[$id] ?? 'Workspace group') : 'Workspace group';
    if ($type === 'person') return $id > 0 ? ($sync_person_names[$id] ?? 'Person') : 'Person';
    return $type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Entity';
};
$format_entity_url = static function (?string $entity_type, $entity_id) use ($sync_workspace_user_urls, $sync_person_urls): string {
    $type = strtolower(trim((string) $entity_type));
    $id = (int) $entity_id;
    if ($id < 1) return '';
    if ($type === 'person') return (string) ($sync_person_urls[$id] ?? '');
    if ($type === 'workspace_user') return (string) ($sync_workspace_user_urls[$id] ?? '');
    return '';
};
$build_workspace_page_url = static function (int $next_sync_page, int $next_security_page): string {
    $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($request_uri === '') {
        $request_uri = (string) metis_people_module_url('/workspace/');
    }
    $parts = parse_url($request_uri);
    $path = (string) ($parts['path'] ?? '/metis/admin/people/workspace/');
    $query_params = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query_params);
        if (!is_array($query_params)) $query_params = [];
    }
    $sync_value = max(1, $next_sync_page);
    $security_value = max(1, $next_security_page);
    $query_params['sync_page'] = $sync_value;
    $query_params['security_page'] = $security_value;
    $query_string = http_build_query($query_params);
    return $query_string !== '' ? ($path . '?' . $query_string) : $path;
};
?>

<div class="metis-people-workspace" data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Workspace Management' ) ); ?></h1>
    <p class="metis-subtitle">Manage users, groups, and security operations without leaving Metis.</p>
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-people-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Workspace Users</div><div class="metis-people-stat-value" id="metis-workspace-kpi-users"><?php echo metis_escape_html((string) $kpi_total_users); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Suspended</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $kpi_suspended); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Groups</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $kpi_groups); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Pending Sync Jobs</div><div class="metis-people-stat-value"><?php echo metis_escape_html((string) $kpi_pending_jobs); ?></div></article>
    </div>

    <div class="metis-list-layout">
        <!-- Sidebar -->
        <aside class="metis-list-sidebar">
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <input id="metis-workspace-user-search" class="metis-input" type="text" placeholder="Name, email, PID, org unit">
                <label class="metis-workspace-show-hidden-toggle">
                    <input id="metis-workspace-show-hidden-users" type="checkbox">
                    Show hidden email users
                </label>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <div class="metis-list-sidebar-actions">
                    <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-secondary">Dashboard</a>
                </div>
            </div>
            <?php if ($can_manage) : ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Sync</div>
                <div class="metis-list-sidebar-actions">
                    <button type="button" id="metis-workspace-import-users" class="metis-btn metis-btn-secondary">Import Existing Users</button>
                    <button type="button" id="metis-workspace-full-sync" class="metis-btn metis-btn-secondary">Full Sync Users + Groups</button>
                    <button type="button" id="metis-workspace-sync-run" class="metis-btn metis-btn-secondary">Run Sync Now</button>
                </div>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Actions</div>
                <div class="metis-list-sidebar-actions">
                    <button type="button" id="metis-workspace-add-user-open" class="metis-btn">Add User</button>
                    <button type="button" id="metis-workspace-add-group-open" class="metis-btn">Add Group</button>
                    <button type="button" id="metis-workspace-role-map-open" class="metis-btn metis-btn-secondary">Workspace Role Map</button>
                    <button type="button" id="metis-workspace-inspect-user-open" class="metis-btn metis-btn-secondary">Inspect User Attributes</button>
                </div>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Main Content -->
        <div class="metis-list-content">

    <table class="metis-premium-table metis-people-table metis-workspace-user-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Name</th>
                <th class="metis-premium-cell" scope="col">Primary Email</th>
                <th class="metis-premium-cell" scope="col">Org Unit</th>
                <th class="metis-premium-cell" scope="col">Roles</th>
                <th class="metis-premium-cell" scope="col">Status</th>
                <th class="metis-premium-cell" scope="col">Linked Person</th>
                <th class="metis-premium-cell metis-workspace-actions-head" scope="col" aria-label="Actions"></th>
            </tr>
        </thead>
        <tbody id="metis-workspace-user-rows">
            <?php foreach ($workspace_users as $u) :
                $uid = (int) ($u['id'] ?? 0);
                $name = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                if ($name === '') $name = (string) ($u['display_name'] ?? '');
                if ($name === '') $name = (string) ($u['primary_email'] ?? '');
                $user_metadata = json_decode((string) ($u['metadata_json'] ?? ''), true);
                if (!is_array($user_metadata)) $user_metadata = [];
                $is_hidden = !empty($user_metadata['ui_hidden']) ? 1 : 0;
                $is_protected = !empty($u['is_protected']) ? 1 : 0;
                $is_suspended = !empty($u['is_suspended']) ? 1 : 0;
                $secondary_email = strtolower(trim((string) ($user_metadata['secondary_email'] ?? $u['recovery_email'] ?? '')));
                $role_keys = (array) ($roles_by_user[$uid] ?? []);
                $role_labels = [];
                foreach ($role_keys as $rk) $role_labels[] = (string) ($workspace_roles[$rk] ?? $rk);
                $group_ids = array_values(array_unique(array_map('intval', (array) ($groups_by_user[$uid] ?? []))));
                $linked_pid = (string) ($u['linked_pid'] ?? '');
                $linked_name = (string) ($u['linked_name'] ?? '');
                $person_url = $linked_pid !== '' ? metis_people_person_url($linked_pid) : '';
                $search_blob = strtolower(trim(implode(' ', [
                    $name,
                    (string) ($u['primary_email'] ?? ''),
                    $secondary_email,
                    (string) ($u['org_unit_path'] ?? ''),
                    $linked_pid,
                ])));
            ?>
                <tr
                    class="metis-premium-row metis-workspace-user-row<?php echo $person_url !== '' ? ' is-clickable' : ''; ?>"
                    data-workspace-user-id="<?php echo metis_escape_attr((string) $uid); ?>"
                    data-search="<?php echo metis_escape_attr($search_blob); ?>"
                    data-hidden="<?php echo metis_escape_attr((string) $is_hidden); ?>"
                    data-protected="<?php echo metis_escape_attr((string) $is_protected); ?>"
                    data-suspended="<?php echo metis_escape_attr((string) $is_suspended); ?>"
                    <?php echo $is_hidden ? ' style="display:none;"' : ''; ?>
                    <?php echo $person_url !== '' ? ' data-person-url="' . metis_escape_attr($person_url) . '"' : ''; ?>
                >
                    <td class="metis-premium-cell">
                        <strong><?php echo metis_escape_html($name); ?></strong>
                        <?php if ($secondary_email !== '') : ?><div class="metis-muted"><?php echo metis_escape_html($secondary_email); ?></div><?php endif; ?>
                    </td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($u['primary_email'] ?? '')); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($u['org_unit_path'] ?? '/')); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html(!empty($role_labels) ? implode(', ', $role_labels) : '—'); ?></td>
                    <td class="metis-premium-cell metis-workspace-status-cell">
                        <?php if ($is_suspended) : ?>
                            <span class="metis-chip">Suspended</span>
                        <?php else : ?>
                            <span class="metis-chip metis-chip-success">Active</span>
                        <?php endif; ?>
                        <?php if ($is_protected) : ?>
                            <span class="metis-chip">Protected</span>
                        <?php endif; ?>
                        <?php if ($is_hidden) : ?>
                            <span class="metis-chip">Hidden</span>
                        <?php endif; ?>
                    </td>
                    <td class="metis-premium-cell">
                        <?php
                        if ($linked_pid !== '') {
                            if ($linked_name !== '') {
                                echo '<div>' . metis_escape_html($linked_name) . '</div><div class="metis-muted">' . metis_escape_html($linked_pid) . '</div>';
                            } else {
                                echo '<div class="metis-muted">' . metis_escape_html($linked_pid) . '</div>';
                            }
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td class="metis-premium-cell metis-workspace-user-actions">
                        <?php if ($can_manage) : ?>
                            <button
                                type="button"
                                class="metis-btn-xs metis-workspace-actions-open"
                                data-workspace-user-id="<?php echo metis_escape_attr((string) $uid); ?>"
                                aria-label="Manage workspace user"
                            >&#8942;</button>
                        <?php else : ?>
                            <span class="metis-muted">—</span>
                        <?php endif; ?>
                        <?php if ($can_manage) : ?>
                            <div class="metis-workspace-actions-menu" data-workspace-user-id="<?php echo metis_escape_attr((string) $uid); ?>" aria-hidden="true">
                            <?php if ($linked_pid === '') : ?>
                                <?php $hidden_action_label = $is_hidden ? 'Unhide' : 'Hide'; ?>
                                <?php $protected_action_label = $is_protected ? 'Unprotect' : 'Protect'; ?>
                                <button type="button" class="metis-btn-xs metis-btn-secondary metis-workspace-flag-btn" data-workspace-user-id="<?php echo metis_escape_attr((string) $uid); ?>" data-flag="is_hidden" data-value="<?php echo metis_escape_attr($is_hidden ? '0' : '1'); ?>" title="<?php echo metis_escape_attr($hidden_action_label); ?>" aria-label="<?php echo metis_escape_attr($hidden_action_label); ?>"><?php echo $workspace_action_icon('user-settings', $hidden_action_label); ?></button>
                                <button type="button" class="metis-btn-xs metis-btn-secondary metis-workspace-flag-btn" data-workspace-user-id="<?php echo metis_escape_attr((string) $uid); ?>" data-flag="is_protected" data-value="<?php echo metis_escape_attr($is_protected ? '0' : '1'); ?>" title="<?php echo metis_escape_attr($protected_action_label); ?>" aria-label="<?php echo metis_escape_attr($protected_action_label); ?>"><?php echo $workspace_action_icon('shield', $protected_action_label); ?></button>
                            <?php endif; ?>
                            <button
                                type="button"
                                class="metis-btn-xs metis-btn-secondary metis-workspace-edit-user-open"
                                title="Edit Account"
                                aria-label="Edit Account"
                                data-id="<?php echo metis_escape_attr((string) $uid); ?>"
                                data-primary-email="<?php echo metis_escape_attr((string) ($u['primary_email'] ?? '')); ?>"
                                data-linked-pid="<?php echo metis_escape_attr($linked_pid); ?>"
                                data-first-name="<?php echo metis_escape_attr((string) ($u['first_name'] ?? '')); ?>"
                                data-last-name="<?php echo metis_escape_attr((string) ($u['last_name'] ?? '')); ?>"
                                data-display-name="<?php echo metis_escape_attr((string) ($u['display_name'] ?? '')); ?>"
                                data-org-unit="<?php echo metis_escape_attr((string) ($u['org_unit_path'] ?? '/')); ?>"
                                data-secondary-email="<?php echo metis_escape_attr($secondary_email); ?>"
                                data-hidden="<?php echo metis_escape_attr($is_hidden ? '1' : '0'); ?>"
                                data-suspended="<?php echo metis_escape_attr($is_suspended ? '1' : '0'); ?>"
                                data-protected="<?php echo metis_escape_attr($is_protected ? '1' : '0'); ?>"
                                data-role-keys="<?php echo metis_escape_attr(metis_json_encode($role_keys)); ?>"
                                data-group-ids="<?php echo metis_escape_attr(metis_json_encode($group_ids)); ?>"
                            ><?php echo $workspace_action_icon('edit', 'Edit Account'); ?></button>
                            <?php if ($linked_pid !== '') : ?>
                                <button
                                    type="button"
                                    class="metis-btn-xs metis-btn-secondary metis-workspace-create-drive-folder-btn"
                                    title="Create Drive Folder"
                                    aria-label="Create Drive Folder"
                                    data-person-pid="<?php echo metis_escape_attr($linked_pid); ?>"
                                ><?php echo $workspace_action_icon('folder-add', 'Create Drive Folder'); ?></button>
                            <?php endif; ?>
                            <button type="button" class="metis-btn-xs metis-btn-secondary metis-workspace-security-open" data-user-id="<?php echo metis_escape_attr((string) $uid); ?>" data-user-email="<?php echo metis_escape_attr((string) ($u['primary_email'] ?? '')); ?>" data-action-type="reset_password" title="Reset Password" aria-label="Reset Password"><?php echo $workspace_action_icon('passkey', 'Reset Password'); ?></button>
                            <button type="button" class="metis-btn-xs metis-btn-secondary metis-workspace-security-open" data-user-id="<?php echo metis_escape_attr((string) $uid); ?>" data-user-email="<?php echo metis_escape_attr((string) ($u['primary_email'] ?? '')); ?>" data-action-type="revoke_sessions" title="Revoke Sessions" aria-label="Revoke Sessions"><?php echo $workspace_action_icon('shield-cross', 'Revoke Sessions'); ?></button>
                            <?php $suspend_action_label = $is_suspended ? 'Unsuspend' : 'Suspend'; ?>
                            <button type="button" class="metis-btn-xs metis-btn-secondary metis-workspace-security-open" data-user-id="<?php echo metis_escape_attr((string) $uid); ?>" data-user-email="<?php echo metis_escape_attr((string) ($u['primary_email'] ?? '')); ?>" data-action-type="<?php echo metis_escape_attr($is_suspended ? 'unsuspend_account' : 'suspend_account'); ?>" title="<?php echo metis_escape_attr($suspend_action_label); ?>" aria-label="<?php echo metis_escape_attr($suspend_action_label); ?>"><?php echo $workspace_action_icon('padlock', $suspend_action_label); ?></button>
                            <?php if ($linked_pid === '') : ?>
                                <button type="button" class="metis-btn-xs metis-workspace-create-person-btn" data-workspace-user-id="<?php echo metis_escape_attr((string) $uid); ?>" title="Create Metis User" aria-label="Create Metis User"><?php echo $workspace_action_icon('user-follow', 'Create Metis User'); ?></button>
                                <button type="button" class="metis-btn-xs metis-btn-danger metis-workspace-delete-user" data-workspace-user-id="<?php echo metis_escape_attr((string) $uid); ?>" data-user-email="<?php echo metis_escape_attr((string) ($u['primary_email'] ?? '')); ?>" title="Delete Account" aria-label="Delete Account"<?php echo $is_protected ? ' hidden' : ''; ?>><?php echo $workspace_action_icon('trash-can', 'Delete Account'); ?></button>
                            <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="metis-people-role-grid" style="margin-top:14px;">
        <section class="metis-premium-wrap">
            <h3 class="metis-people-section-title">Groups</h3>
            <table class="metis-premium-table metis-people-table metis-workspace-group-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Group</th>
                        <th class="metis-premium-cell" scope="col">Group Email</th>
                        <th class="metis-premium-cell" scope="col">Description</th>
                        <th class="metis-premium-cell" scope="col">Members</th>
                        <th class="metis-premium-cell" scope="col">Sync</th>
                    </tr>
                </thead>
                <tbody id="metis-workspace-group-rows">
                    <?php foreach ($workspace_groups as $g) :
                        $gid = (int) ($g['id'] ?? 0);
                        $sync_status = (string) ($g['sync_status'] ?? 'synced');
                    ?>
                        <tr class="metis-premium-row metis-workspace-group-row<?php echo $can_manage ? ' is-clickable' : ''; ?>"<?php echo $can_manage ? ' data-group-id="' . metis_escape_attr((string) $gid) . '"' : ''; ?>>
                            <td class="metis-premium-cell"><strong><?php echo metis_escape_html((string) ($g['group_name'] ?? '')); ?></strong></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($g['group_email'] ?? '')); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($g['description'] ?? '')); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html((string) (int) ($g['member_count'] ?? 0)); ?></td>
                            <td class="metis-premium-cell">
                                <span class="metis-chip<?php echo $sync_status === 'synced' ? ' metis-chip-success' : ''; ?>"><?php echo metis_escape_html($sync_status); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($workspace_groups)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No groups recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="metis-premium-wrap">
            <h3 class="metis-people-section-title">Sync Queue + Security Actions</h3>
            <div class="metis-workspace-log-grid" data-sync-page="<?php echo metis_escape_attr((string) $sync_page); ?>" data-security-page="<?php echo metis_escape_attr((string) $security_page); ?>">
                <table class="metis-premium-table metis-people-table metis-workspace-log-table">
                    <thead>
                        <tr class="metis-premium-row metis-premium-header">
                            <th class="metis-premium-cell" scope="col">Sync Job</th>
                            <th class="metis-premium-cell" scope="col">Entity</th>
                            <th class="metis-premium-cell" scope="col">Status</th>
                            <th class="metis-premium-cell" scope="col">Time</th>
                        </tr>
                    </thead>
                    <tbody id="metis-workspace-sync-log-rows">
                    <?php foreach ($sync_jobs as $j) : ?>
                        <?php
                        $job_type = strtolower(trim((string) ($j['job_type'] ?? '')));
                        $job_status = strtolower(trim((string) ($j['status'] ?? 'queued')));
                        $job_title = $job_type_labels[$job_type] ?? ucwords(str_replace('_', ' ', $job_type));
                        $job_time = $format_timestamp((string) ($j['created_at'] ?? ''));
                        $job_error = trim((string) ($j['last_error'] ?? ''));
                        $is_stripe_skip = stripos($job_error, 'stripe sync skipped: workspace email not set') !== false
                            || stripos($job_error, 'stripe sync skipped: role is empty') !== false;
                        if ($is_stripe_skip) {
                            $job_error = '';
                        }
                        $effective_job_status = $is_stripe_skip && $job_status === 'failed' ? 'completed' : $job_status;
                        $job_status_label = $format_status($effective_job_status);
                        $job_status_class = in_array($effective_job_status, ['completed', 'synced'], true) ? ' metis-chip-success' : ($effective_job_status === 'failed' ? ' metis-chip-danger' : '');
                        $entity_label = $format_entity((string) ($j['entity_type'] ?? ''), (int) ($j['entity_id'] ?? 0));
                        $entity_url = $format_entity_url((string) ($j['entity_type'] ?? ''), (int) ($j['entity_id'] ?? 0));
                        ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell">
                                <strong><?php echo metis_escape_html($job_title); ?></strong>
                                <?php if ($job_error !== '') : ?>
                                    <div class="metis-workspace-mini-error"><?php echo metis_escape_html('Failed: ' . substr($job_error, 0, 160)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="metis-premium-cell">
                                <?php if ($entity_url !== '') : ?>
                                    <a class="metis-workspace-entity-link" href="<?php echo metis_escape_url($entity_url); ?>"><?php echo metis_escape_html($entity_label); ?></a>
                                <?php else : ?>
                                    <?php echo metis_escape_html($entity_label); ?>
                                <?php endif; ?>
                            </td>
                            <td class="metis-premium-cell"><span class="metis-chip<?php echo metis_escape_attr($job_status_class); ?>"><?php echo metis_escape_html($job_status_label); ?></span></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html($job_time); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sync_jobs)) : ?>
                        <tr class="metis-premium-row metis-workspace-empty-row">
                            <td class="metis-premium-cell" colspan="4">No sync jobs.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                    <div class="metis-workspace-log-pagination">
                        <span class="metis-muted" id="metis-workspace-sync-page-label">Page <?php echo metis_escape_html((string) $sync_page); ?> of <?php echo metis_escape_html((string) $sync_total_pages); ?></span>
                        <div class="metis-workspace-log-pagination-actions">
                            <?php if ($sync_page > 1) : ?>
                                <button type="button" class="metis-workspace-page-link" data-sync-page="<?php echo metis_escape_attr((string) ($sync_page - 1)); ?>" data-security-page="<?php echo metis_escape_attr((string) $security_page); ?>">&larr; Previous</button>
                            <?php endif; ?>
                            <?php if ($sync_page < $sync_total_pages) : ?>
                                <button type="button" class="metis-workspace-page-link" data-sync-page="<?php echo metis_escape_attr((string) ($sync_page + 1)); ?>" data-security-page="<?php echo metis_escape_attr((string) $security_page); ?>">Next &rarr;</button>
                            <?php endif; ?>
                        </div>
                    </div>

                <table class="metis-premium-table metis-people-table metis-workspace-log-table">
                    <thead>
                        <tr class="metis-premium-row metis-premium-header">
                            <th class="metis-premium-cell" scope="col">Security Action</th>
                            <th class="metis-premium-cell" scope="col">User</th>
                            <th class="metis-premium-cell" scope="col">Status</th>
                            <th class="metis-premium-cell" scope="col">Time</th>
                        </tr>
                    </thead>
                    <tbody id="metis-workspace-security-log-rows">
                    <?php foreach ($security_actions as $s) : ?>
                        <?php
                        $action_type = strtolower(trim((string) ($s['action_type'] ?? '')));
                        $action_status = strtolower(trim((string) ($s['status'] ?? 'pending')));
                        $action_title = $security_action_labels[$action_type] ?? ucwords(str_replace('_', ' ', $action_type));
                        $action_time = $format_timestamp((string) ($s['created_at'] ?? ''));
                        $action_status_label = $format_status($action_status);
                        $action_status_class = $action_status === 'completed' ? ' metis-chip-success' : ($action_status === 'failed' ? ' metis-chip-danger' : '');
                        ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell">
                                <strong><?php echo metis_escape_html($action_title); ?></strong>
                                <?php
                                $security_reason = trim((string) ($s['reason'] ?? ''));
                                if ($security_reason === 'bulk_workspace_action') $security_reason = 'Bulk update';
                                if ($security_reason === 'person_offboarded') $security_reason = 'Offboarding';
                                if ($security_reason === 'workspace_or_status_ineligible') $security_reason = '';
                                ?>
                                <?php if ($security_reason !== '') : ?>
                                    <div class="metis-muted"><?php echo metis_escape_html('Reason: ' . $security_reason); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="metis-premium-cell">
                                <?php
                                $security_name = trim((string) ($s['person_display_name'] ?? ''));
                                if ($security_name === '') $security_name = trim((string) ($s['display_name'] ?? ''));
                                $security_name = $normalize_person_name($security_name);
                                if ($security_name === '') $security_name = 'Workspace user';
                                $security_pid = trim((string) ($s['person_pid'] ?? ''));
                                $security_url = $security_pid !== '' ? metis_people_person_url($security_pid) : '';
                                if ($security_url !== '') {
                                    echo '<a class="metis-workspace-entity-link" href="' . metis_escape_url($security_url) . '">' . metis_escape_html($security_name) . '</a>';
                                } else {
                                    echo metis_escape_html($security_name);
                                }
                                ?>
                            </td>
                            <td class="metis-premium-cell"><span class="metis-chip<?php echo metis_escape_attr($action_status_class); ?>"><?php echo metis_escape_html($action_status_label); ?></span></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html($action_time); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($security_actions)) : ?>
                        <tr class="metis-premium-row metis-workspace-empty-row">
                            <td class="metis-premium-cell" colspan="4">No security actions.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                    <div class="metis-workspace-log-pagination">
                        <span class="metis-muted" id="metis-workspace-security-page-label">Page <?php echo metis_escape_html((string) $security_page); ?> of <?php echo metis_escape_html((string) $security_total_pages); ?></span>
                        <div class="metis-workspace-log-pagination-actions">
                            <?php if ($security_page > 1) : ?>
                                <button type="button" class="metis-workspace-page-link" data-sync-page="<?php echo metis_escape_attr((string) $sync_page); ?>" data-security-page="<?php echo metis_escape_attr((string) ($security_page - 1)); ?>">&larr; Previous</button>
                            <?php endif; ?>
                            <?php if ($security_page < $security_total_pages) : ?>
                                <button type="button" class="metis-workspace-page-link" data-sync-page="<?php echo metis_escape_attr((string) $sync_page); ?>" data-security-page="<?php echo metis_escape_attr((string) ($security_page + 1)); ?>">Next &rarr;</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </div><!-- /.metis-people-role-grid -->

        </div><!-- /.metis-list-content -->
    </div><!-- /.metis-list-layout -->
</div><!-- /.metis-people-workspace -->

<?php if ($can_manage) : ?>
    <div id="metis-workspace-user-modal" class="metis-modal-backdrop" aria-hidden="true">
        <div class="metis-modal metis-people-modal-inner">
            <h3 class="metis-modal-title" id="metis-workspace-user-modal-title">Add Workspace User</h3>
            <form id="metis-workspace-user-form" class="metis-form-grid">
                <input type="hidden" id="metis-workspace-user-id" value="0">
                <div class="metis-people-form-subtitle">Identity</div>
                <div class="metis-field metis-field-half"><label for="metis-workspace-user-primary-email">Primary Email</label><input id="metis-workspace-user-primary-email" class="metis-input" type="email" required></div>
                <div class="metis-field metis-field-half"><label for="metis-workspace-user-first-name">First Name</label><input id="metis-workspace-user-first-name" class="metis-input" type="text"></div>
                <div class="metis-field metis-field-half"><label for="metis-workspace-user-last-name">Last Name</label><input id="metis-workspace-user-last-name" class="metis-input" type="text"></div>
                <div class="metis-field metis-field-half"><label for="metis-workspace-user-display-name">Display Name</label><input id="metis-workspace-user-display-name" class="metis-input" type="text"></div>
                <div class="metis-people-form-subtitle">Routing</div>
                <div class="metis-field metis-field-half"><label for="metis-workspace-user-org-unit">Org Unit</label><input id="metis-workspace-user-org-unit" class="metis-input" type="text" placeholder="/"></div>
                <div class="metis-field metis-field-half"><label for="metis-workspace-user-secondary-email">Secondary Email</label><input id="metis-workspace-user-secondary-email" class="metis-input" type="email"></div>
                <div class="metis-field metis-field-half"><label for="metis-workspace-user-linked-pid">Linked Person PID</label><input id="metis-workspace-user-linked-pid" class="metis-input" type="text" placeholder="PE..." autocomplete="off"></div>
                <div class="metis-people-form-subtitle">Controls</div>
                <div class="metis-field metis-field-half"><label><input type="checkbox" id="metis-workspace-user-hidden"> Hidden (service/internal account)</label></div>
                <div class="metis-field metis-field-half"><label><input type="checkbox" id="metis-workspace-user-suspended"> Suspended</label></div>
                <div class="metis-field metis-field-half"><label><input type="checkbox" id="metis-workspace-user-protected"> Protected (non-removable)</label></div>
                <div class="metis-field metis-field-full">
                    <label>Workspace Admin Roles</label>
                    <div class="metis-people-check-grid metis-workspace-role-check-grid">
                        <?php foreach ($workspace_roles as $role_key => $role_label) : if ($role_key === '') continue; ?>
                            <label class="metis-people-check"><input type="checkbox" class="metis-workspace-role-toggle" value="<?php echo metis_escape_attr((string) $role_key); ?>"> <?php echo metis_escape_html((string) $role_label); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="metis-field metis-field-full">
                    <label>Workspace Groups</label>
                    <div class="metis-people-check-grid metis-workspace-user-group-check-grid">
                        <?php if (empty($workspace_groups)) : ?>
                            <div class="metis-muted">No workspace groups are available yet.</div>
                        <?php else : ?>
                            <?php foreach ($workspace_groups as $group_row) :
                                $group_id = (int) ($group_row['id'] ?? 0);
                                if ($group_id < 1) continue;
                                $group_label = trim((string) ($group_row['group_name'] ?? ''));
                                $group_email = strtolower(trim((string) ($group_row['group_email'] ?? '')));
                                if ($group_label === '') $group_label = $group_email;
                            ?>
                                <label class="metis-people-check">
                                    <input type="checkbox" class="metis-workspace-user-group-toggle" value="<?php echo metis_escape_attr((string) $group_id); ?>">
                                    <?php echo metis_escape_html($group_label); ?>
                                    <?php if ($group_email !== '') : ?><span class="metis-muted"><?php echo metis_escape_html($group_email); ?></span><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="metis-form-actions">
                    <button type="button" id="metis-workspace-user-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                    <button type="submit" class="metis-btn">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-workspace-group-modal" class="metis-modal-backdrop" aria-hidden="true">
        <div class="metis-modal metis-people-modal-inner">
            <h3 class="metis-modal-title" id="metis-workspace-group-modal-title">Workspace Group Editor</h3>
            <form id="metis-workspace-group-form" class="metis-form-grid">
                <input type="hidden" id="metis-workspace-group-id" value="0">
                <div class="metis-people-tabs-nav" data-tabs-root>
                    <button type="button" class="metis-btn-xs metis-tab-btn is-active" data-tab-target="group-general">General</button>
                    <button type="button" class="metis-btn-xs metis-tab-btn" data-tab-target="group-users">Workspace Users</button>
                    <button type="button" class="metis-btn-xs metis-tab-btn" data-tab-target="group-external" id="metis-workspace-group-tab-external">External Users</button>
                    <button type="button" class="metis-btn-xs metis-tab-btn" data-tab-target="group-permissions">Permissions</button>
                    <button type="button" class="metis-btn-xs metis-tab-btn" data-tab-target="group-advanced">Advanced Settings</button>
                </div>
                <div class="metis-tab-panel is-active" data-tab-panel="group-general">
                    <div class="metis-field metis-field-half"><label for="metis-workspace-group-name">Group Name</label><input id="metis-workspace-group-name" class="metis-input" type="text" required></div>
                    <div class="metis-field metis-field-half"><label for="metis-workspace-group-email">Group Email</label><input id="metis-workspace-group-email" class="metis-input" type="email" required></div>
                    <div class="metis-field metis-field-full"><label for="metis-workspace-group-description">Description</label><textarea id="metis-workspace-group-description" class="metis-input" rows="3"></textarea></div>
                </div>
                <div class="metis-tab-panel" data-tab-panel="group-users">
                    <div class="metis-field metis-field-full">
                        <div id="metis-workspace-members-grid" class="metis-workspace-members-grid"></div>
                    </div>
                </div>
                <div class="metis-tab-panel" data-tab-panel="group-external">
                    <div class="metis-field metis-field-full">
                        <label>Add External Member</label>
                        <div class="metis-workspace-external-add">
                            <input id="metis-workspace-external-email" class="metis-input" type="email" placeholder="person@external.org">
                            <select id="metis-workspace-external-role" class="metis-select">
                                <option value="member">Member</option>
                                <option value="manager">Manager</option>
                                <option value="owner">Owner</option>
                            </select>
                            <button type="button" id="metis-workspace-external-add-btn" class="metis-btn-xs">Add</button>
                        </div>
                    </div>
                    <div class="metis-field metis-field-full">
                        <div id="metis-workspace-external-grid" class="metis-workspace-members-grid"></div>
                    </div>
                </div>
                <div class="metis-tab-panel" data-tab-panel="group-permissions">
                    <div class="metis-field metis-field-full">
                        <label for="metis-workspace-group-perm-template">Email Group Template</label>
                        <div class="metis-workspace-template-row">
                            <select id="metis-workspace-group-perm-template" class="metis-select">
                                <option value="">Custom</option>
                                <option value="board">Board</option>
                                <option value="supplies">Supplies</option>
                            </select>
                            <button type="button" id="metis-workspace-group-perm-template-apply" class="metis-btn-xs">Apply Template</button>
                            <button type="button" id="metis-workspace-group-perm-template-capture" class="metis-btn-xs metis-btn-secondary">Capture Board + Supplies</button>
                        </div>
                        <div id="metis-workspace-group-perm-template-status" class="metis-muted"></div>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-workspace-group-perm-join">Who Can Join</label>
                        <select id="metis-workspace-group-perm-join" class="metis-select">
                            <option value="INVITED_CAN_JOIN">Invited Only</option>
                            <option value="CAN_REQUEST_TO_JOIN">Can Request</option>
                            <option value="ANYONE_CAN_JOIN">Anyone</option>
                        </select>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-workspace-group-perm-view">Who Can View Membership</label>
                        <select id="metis-workspace-group-perm-view" class="metis-select">
                            <option value="ALL_MANAGERS_CAN_VIEW">Managers</option>
                            <option value="ALL_MEMBERS_CAN_VIEW">Members</option>
                            <option value="ALL_IN_DOMAIN_CAN_VIEW">Domain</option>
                            <option value="ANYONE_CAN_VIEW">Anyone</option>
                        </select>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-workspace-group-perm-post">Who Can Post</label>
                        <select id="metis-workspace-group-perm-post" class="metis-select">
                            <option value="NONE_CAN_POST">No One</option>
                            <option value="ALL_MANAGERS_CAN_POST">Managers</option>
                            <option value="ALL_MEMBERS_CAN_POST">Members</option>
                            <option value="ALL_IN_DOMAIN_CAN_POST">Domain</option>
                            <option value="ANYONE_CAN_POST">Anyone</option>
                        </select>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label><input type="checkbox" id="metis-workspace-group-perm-external"> Allow External Members</label>
                    </div>
                </div>
                <div class="metis-tab-panel" data-tab-panel="group-advanced">
                    <div class="metis-workspace-adv-tabs-nav">
                        <button type="button" class="metis-btn-xs metis-workspace-adv-tab-btn is-active" data-adv-tab-target="adv-general">General</button>
                        <button type="button" class="metis-btn-xs metis-workspace-adv-tab-btn" data-adv-tab-target="adv-moderation">Member Moderation</button>
                        <button type="button" class="metis-btn-xs metis-workspace-adv-tab-btn" data-adv-tab-target="adv-privacy">Member Privacy</button>
                        <button type="button" class="metis-btn-xs metis-workspace-adv-tab-btn" data-adv-tab-target="adv-posting">Posting Policies</button>
                        <button type="button" class="metis-btn-xs metis-workspace-adv-tab-btn" data-adv-tab-target="adv-email">Email Options</button>
                    </div>

                    <div class="metis-workspace-adv-tab-panel is-active" data-adv-tab-panel="adv-general">
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-primary-language">Group Email Language</label>
                            <select id="metis-workspace-adv-primary-language" class="metis-select">
                                <option value="">Default</option>
                                <option value="en">English</option>
                                <option value="es">Spanish</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-reply-to">Post Replies To</label>
                            <select id="metis-workspace-adv-reply-to" class="metis-select">
                                <option value="REPLY_TO_IGNORE">All Group Members</option>
                                <option value="REPLY_TO_LIST">Group</option>
                                <option value="REPLY_TO_SENDER">Sender</option>
                                <option value="REPLY_TO_OWNER">Owner</option>
                                <option value="REPLY_TO_MANAGERS">Managers</option>
                                <option value="REPLY_TO_CUSTOM">Custom</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-custom-reply-to">Custom Reply To</label>
                            <input id="metis-workspace-adv-custom-reply-to" class="metis-input" type="email" placeholder="group-owner@domain.org">
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-is-archived"> Conversation Mode (thread by subject)</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-enable-collaborative-inbox"> Enable Collaborative Inbox</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-allow-google-communication"> Allow Google Communication</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-include-in-global-address-list"> Include In Global Address List</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-message-display-font">Message Display Font</label>
                            <select id="metis-workspace-adv-message-display-font" class="metis-select">
                                <option value="DEFAULT_FONT">Default Font</option>
                                <option value="FIXED_WIDTH_FONT">Fixed Width</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-default-sender">Default Sender</label>
                            <select id="metis-workspace-adv-default-sender" class="metis-select">
                                <option value="DEFAULT_SELF">Default Self</option>
                                <option value="GROUP_OWNER">Group Owner</option>
                                <option value="CUSTOM">Custom</option>
                            </select>
                        </div>
                    </div>

                    <div class="metis-workspace-adv-tab-panel" data-adv-tab-panel="adv-moderation">
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-join">Who Can Join</label>
                            <select id="metis-workspace-adv-who-can-join" class="metis-select">
                                <option value="INVITED_CAN_JOIN">Invited Only</option>
                                <option value="CAN_REQUEST_TO_JOIN">Can Request</option>
                                <option value="ANYONE_CAN_JOIN">Anyone</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-leave-group">Who Can Leave Group</label>
                            <select id="metis-workspace-adv-who-can-leave-group" class="metis-select">
                                <option value="ALL_MEMBERS_CAN_LEAVE">All Members</option>
                                <option value="ALL_MANAGERS_CAN_LEAVE">Managers</option>
                                <option value="NONE_CAN_LEAVE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-add">Who Can Add Members</label>
                            <select id="metis-workspace-adv-who-can-add" class="metis-select">
                                <option value="ALL_MANAGERS_CAN_ADD">Managers</option>
                                <option value="ALL_MEMBERS_CAN_ADD">Members</option>
                                <option value="NONE_CAN_ADD">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-invite">Who Can Invite</label>
                            <select id="metis-workspace-adv-who-can-invite" class="metis-select">
                                <option value="ALL_MANAGERS_CAN_INVITE">Managers</option>
                                <option value="ALL_MEMBERS_CAN_INVITE">Members</option>
                                <option value="NONE_CAN_INVITE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-approve-members">Who Can Approve Members</label>
                            <select id="metis-workspace-adv-who-can-approve-members" class="metis-select">
                                <option value="ALL_MANAGERS_CAN_APPROVE">Managers</option>
                                <option value="ALL_MEMBERS_CAN_APPROVE">Members</option>
                                <option value="NONE_CAN_APPROVE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-ban-users">Who Can Ban Users</label>
                            <select id="metis-workspace-adv-who-can-ban-users" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-moderate-members">Who Can Moderate Members</label>
                            <select id="metis-workspace-adv-who-can-moderate-members" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-message-moderation-level">Message Moderation</label>
                            <select id="metis-workspace-adv-message-moderation-level" class="metis-select">
                                <option value="MODERATE_NONE">No moderation</option>
                                <option value="MODERATE_NON_MEMBERS">Moderate non-members</option>
                                <option value="MODERATE_ALL_MESSAGES">Moderate all messages</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-spam-moderation-level">Spam Moderation</label>
                            <select id="metis-workspace-adv-spam-moderation-level" class="metis-select">
                                <option value="MODERATE">Moderate</option>
                                <option value="ALLOW">Allow</option>
                                <option value="SILENTLY_MODERATE">Silently moderate</option>
                                <option value="REJECT">Reject</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-modify-members">Who Can Modify Members</label>
                            <select id="metis-workspace-adv-who-can-modify-members" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                    </div>

                    <div class="metis-workspace-adv-tab-panel" data-adv-tab-panel="adv-privacy">
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-view-group">Who Can View Group</label>
                            <select id="metis-workspace-adv-who-can-view-group" class="metis-select">
                                <option value="ALL_MEMBERS_CAN_VIEW">Members</option>
                                <option value="ALL_IN_DOMAIN_CAN_VIEW">Domain</option>
                                <option value="ANYONE_CAN_VIEW">Anyone</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-view-membership">Who Can View Membership</label>
                            <select id="metis-workspace-adv-who-can-view-membership" class="metis-select">
                                <option value="ALL_MANAGERS_CAN_VIEW">Managers</option>
                                <option value="ALL_MEMBERS_CAN_VIEW">Members</option>
                                <option value="ALL_IN_DOMAIN_CAN_VIEW">Domain</option>
                                <option value="ANYONE_CAN_VIEW">Anyone</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-discover-group">Who Can Discover Group</label>
                            <select id="metis-workspace-adv-who-can-discover-group" class="metis-select">
                                <option value="ALL_IN_DOMAIN_CAN_DISCOVER">Domain</option>
                                <option value="ANYONE_CAN_DISCOVER">Anyone</option>
                                <option value="ALL_MEMBERS_CAN_DISCOVER">Members</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-contact-owner">Who Can Contact Owner</label>
                            <select id="metis-workspace-adv-who-can-contact-owner" class="metis-select">
                                <option value="ALL_IN_DOMAIN_CAN_CONTACT">Domain</option>
                                <option value="ALL_MEMBERS_CAN_CONTACT">Members</option>
                                <option value="ANYONE_CAN_CONTACT">Anyone</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-show-in-group-directory"> Show in Group Directory</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-allow-external-members"> Allow External Members</label>
                        </div>
                    </div>

                    <div class="metis-workspace-adv-tab-panel" data-adv-tab-panel="adv-posting">
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-post-message">Who Can Post</label>
                            <select id="metis-workspace-adv-who-can-post-message" class="metis-select">
                                <option value="NONE_CAN_POST">No One</option>
                                <option value="ALL_MANAGERS_CAN_POST">Managers</option>
                                <option value="ALL_MEMBERS_CAN_POST">Members</option>
                                <option value="ALL_IN_DOMAIN_CAN_POST">Domain</option>
                                <option value="ANYONE_CAN_POST">Anyone</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-approve-messages">Who Can Approve Messages</label>
                            <select id="metis-workspace-adv-who-can-approve-messages" class="metis-select">
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="ALL_MANAGERS_CAN_APPROVE">Managers</option>
                                <option value="ALL_MEMBERS_CAN_APPROVE">Members</option>
                                <option value="NONE_CAN_APPROVE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-moderate-content">Who Can Moderate Content</label>
                            <select id="metis-workspace-adv-who-can-moderate-content" class="metis-select">
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-assist-content">Who Can Assist Content</label>
                            <select id="metis-workspace-adv-who-can-assist-content" class="metis-select">
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-allow-web-posting"> Allow Web Posting</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-members-can-post-as-group"> Members Can Post As Group</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-archive-only"> Archive Only</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-delete-any-post">Who Can Delete Any Post</label>
                            <select id="metis-workspace-adv-who-can-delete-any-post" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-delete-topics">Who Can Delete Topics</label>
                            <select id="metis-workspace-adv-who-can-delete-topics" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-lock-topics">Who Can Lock Topics</label>
                            <select id="metis-workspace-adv-who-can-lock-topics" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-hide-abuse">Who Can Hide Abuse</label>
                            <select id="metis-workspace-adv-who-can-hide-abuse" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-workspace-adv-who-can-make-topics-sticky">Who Can Make Topics Sticky</label>
                            <select id="metis-workspace-adv-who-can-make-topics-sticky" class="metis-select">
                                <option value="OWNERS_AND_MANAGERS">Owners + Managers</option>
                                <option value="OWNERS_ONLY">Owners Only</option>
                                <option value="ALL_MEMBERS">All Members</option>
                                <option value="NONE">No One</option>
                            </select>
                        </div>
                    </div>

                    <div class="metis-workspace-adv-tab-panel" data-adv-tab-panel="adv-email">
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-auto-reply-group-members"> Auto Reply: members inside org</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-auto-reply-nonmembers-inside"> Auto Reply: non-members inside org</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-auto-reply-members-outside"> Auto Reply: members outside org</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-auto-reply-nonmembers-outside"> Auto Reply: non-members outside org</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-send-message-deny-notification"> Send Message Deny Notification</label>
                        </div>
                        <div class="metis-field metis-field-full">
                            <label for="metis-workspace-adv-default-message-deny-text">Default Message Deny Notification Text</label>
                            <textarea id="metis-workspace-adv-default-message-deny-text" class="metis-input" rows="3"></textarea>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-workspace-adv-include-custom-footer"> Include Custom Footer</label>
                        </div>
                        <div class="metis-field metis-field-full">
                            <label for="metis-workspace-adv-custom-footer-text">Custom Footer Text</label>
                            <textarea id="metis-workspace-adv-custom-footer-text" class="metis-input" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="metis-form-actions">
                    <button type="button" id="metis-workspace-group-delete" class="metis-btn metis-btn-danger" style="margin-right:auto;display:none;">Delete Group</button>
                    <button type="button" id="metis-workspace-group-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                    <button type="submit" class="metis-btn">Save Group</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-workspace-security-modal" class="metis-modal-backdrop" aria-hidden="true">
        <div class="metis-modal metis-people-modal-inner">
            <h3 class="metis-modal-title">Workspace Security Action</h3>
            <form id="metis-workspace-security-form" class="metis-form-grid">
                <input type="hidden" id="metis-workspace-security-user-id" value="0">
                <div class="metis-field metis-field-full"><label for="metis-workspace-security-user-email">User</label><input id="metis-workspace-security-user-email" class="metis-input" type="text" readonly></div>
                <div class="metis-field metis-field-half">
                    <label for="metis-workspace-security-action">Action</label>
                    <select id="metis-workspace-security-action" class="metis-select">
                        <option value="reset_password">Reset Password</option>
                        <option value="revoke_sessions">Revoke Sessions</option>
                        <option value="force_2fa_reenroll">Force 2FA Re-enroll</option>
                        <option value="suspend_account">Suspend Account</option>
                        <option value="unsuspend_account">Unsuspend Account</option>
                    </select>
                </div>
                <div class="metis-field metis-field-full"><label for="metis-workspace-security-reason">Reason</label><textarea id="metis-workspace-security-reason" class="metis-input" rows="3" required></textarea></div>
                <div class="metis-form-actions">
                    <button type="button" id="metis-workspace-security-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                    <button type="submit" class="metis-btn metis-btn-danger">Run Action</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-workspace-role-map-modal" class="metis-modal-backdrop" aria-hidden="true">
        <div class="metis-modal metis-people-modal-inner metis-workspace-role-map-modal-inner">
            <h3 class="metis-modal-title">Workspace Role Map</h3>
            <p class="metis-muted">Live Google role data with internal key mapping used by Metis.</p>
            <div id="metis-workspace-role-map-alert" class="metis-alert" style="display:none;"></div>
            <div class="metis-workspace-role-map-table-wrap">
                <table class="metis-premium-table metis-people-table metis-workspace-role-map-table">
                    <thead>
                        <tr class="metis-premium-row metis-premium-header">
                            <th class="metis-premium-cell" scope="col">Role</th>
                            <th class="metis-premium-cell" scope="col">Metis Key</th>
                            <th class="metis-premium-cell" scope="col">Assigned</th>
                        </tr>
                    </thead>
                    <tbody id="metis-workspace-role-map-rows">
                        <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="3">Click Refresh to load live role mapping.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="metis-form-actions">
                <button type="button" id="metis-workspace-role-map-refresh" class="metis-btn">Refresh</button>
                <button type="button" id="metis-workspace-role-map-close" class="metis-btn metis-btn-ghost">Close</button>
            </div>
        </div>
    </div>

    <div id="metis-workspace-inspect-user-modal" class="metis-modal-backdrop" aria-hidden="true">
        <div class="metis-modal metis-people-modal-inner metis-workspace-role-map-modal-inner">
            <h3 class="metis-modal-title">Inspect Workspace User Attributes</h3>
            <p class="metis-muted">Enter a Workspace email to view live custom schema values and available schema fields.</p>
            <form id="metis-workspace-inspect-user-form" class="metis-form-grid">
                <div class="metis-field metis-field-full">
                    <label for="metis-workspace-inspect-user-email">Workspace Email</label>
                    <input id="metis-workspace-inspect-user-email" class="metis-input" type="email" placeholder="user@mobilizewaco.org" required>
                </div>
                <div class="metis-field metis-field-full">
                    <label for="metis-workspace-inspect-user-output">Result</label>
                    <textarea id="metis-workspace-inspect-user-output" class="metis-input" rows="14" readonly></textarea>
                </div>
                <div class="metis-form-actions">
                    <button type="button" id="metis-workspace-inspect-user-close" class="metis-btn metis-btn-ghost">Close</button>
                    <button type="submit" id="metis-workspace-inspect-user-run" class="metis-btn">Query User</button>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>
