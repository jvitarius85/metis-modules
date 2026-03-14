<?php if (!defined('ABSPATH')) exit;

if (!metis_people_can_workspace_manage()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Workspace Management.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

global $wpdb;
$can_manage = metis_people_can_workspace_manage();

$workspace_users_table = Metis_Tables::get('people_workspace_users');
$workspace_user_roles_table = Metis_Tables::get('people_workspace_user_roles');
$workspace_groups_table = Metis_Tables::get('people_workspace_groups');
$workspace_group_members_table = Metis_Tables::get('people_workspace_group_members');
$workspace_security_actions_table = Metis_Tables::get('people_workspace_security_actions');
$workspace_sync_jobs_table = Metis_Tables::get('people_workspace_sync_jobs');
$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');

$workspace_users = $wpdb->get_results(
    "SELECT wu.*, p.pid AS linked_pid, p.display_name AS linked_name
     FROM {$workspace_users_table} wu
     LEFT JOIN {$people_table} p ON p.id = wu.person_id
     ORDER BY wu.display_name ASC, wu.primary_email ASC
     LIMIT 500",
    ARRAY_A
) ?: [];

$role_rows = $wpdb->get_results("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain = 'workspace' ORDER BY role_name ASC", ARRAY_A) ?: [];
$workspace_roles = ['' => 'No Admin Role'];
foreach ($role_rows as $r) {
    $rk = (string) ($r['role_key'] ?? '');
    if ($rk === '') continue;
    $workspace_roles[$rk] = (string) ($r['role_name'] ?? $rk);
}

$roles_by_user = [];
$role_assign_rows = $wpdb->get_results(
    "SELECT workspace_user_id, role_key FROM {$workspace_user_roles_table}",
    ARRAY_A
) ?: [];
foreach ($role_assign_rows as $row) {
    $uid = (int) ($row['workspace_user_id'] ?? 0);
    $role_key = (string) ($row['role_key'] ?? '');
    if ($uid < 1 || $role_key === '') continue;
    if (!isset($roles_by_user[$uid])) $roles_by_user[$uid] = [];
    $roles_by_user[$uid][] = $role_key;
}

$workspace_groups = $wpdb->get_results(
    "SELECT wg.*,
            (SELECT COUNT(*) FROM {$workspace_group_members_table} gm WHERE gm.group_id = wg.id) AS member_count
     FROM {$workspace_groups_table} wg
     ORDER BY wg.group_name ASC
     LIMIT 500",
    ARRAY_A
) ?: [];

$group_members = $wpdb->get_results(
    "SELECT gm.group_id, gm.workspace_user_id, gm.member_role, wu.primary_email, wu.display_name
     FROM {$workspace_group_members_table} gm
     INNER JOIN {$workspace_users_table} wu ON wu.id = gm.workspace_user_id
     ORDER BY gm.group_id ASC, wu.display_name ASC",
    ARRAY_A
) ?: [];
$members_by_group = [];
foreach ($group_members as $gm) {
    $gid = (int) ($gm['group_id'] ?? 0);
    if ($gid < 1) continue;
    if (!isset($members_by_group[$gid])) $members_by_group[$gid] = [];
    $members_by_group[$gid][] = $gm;
}

$security_actions = $wpdb->get_results(
    "SELECT sa.*, wu.primary_email
     FROM {$workspace_security_actions_table} sa
     INNER JOIN {$workspace_users_table} wu ON wu.id = sa.workspace_user_id
     ORDER BY sa.created_at DESC
     LIMIT 50",
    ARRAY_A
) ?: [];

$sync_jobs = $wpdb->get_results(
    "SELECT *
     FROM {$workspace_sync_jobs_table}
     ORDER BY created_at DESC
     LIMIT 100",
    ARRAY_A
) ?: [];

$kpi_total_users = (int) count($workspace_users);
$kpi_suspended = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$workspace_users_table} WHERE is_suspended = 1");
$kpi_groups = (int) count($workspace_groups);
$kpi_pending_jobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$workspace_sync_jobs_table} WHERE status IN ('queued', 'processing')");
?>

<div class="metis-people-workspace" data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>">
    <h1 class="mw-page-title">Workspace Management</h1>
    <p class="mw-subtitle">Manage users, groups, and security operations without leaving Metis.</p>
    <div id="metis-people-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-people-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Workspace Users</div><div class="metis-people-stat-value"><?php echo esc_html((string) $kpi_total_users); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Suspended</div><div class="metis-people-stat-value"><?php echo esc_html((string) $kpi_suspended); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Groups</div><div class="metis-people-stat-value"><?php echo esc_html((string) $kpi_groups); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Pending Sync Jobs</div><div class="metis-people-stat-value"><?php echo esc_html((string) $kpi_pending_jobs); ?></div></article>
    </div>

    <div class="mw-list-layout">
        <!-- Sidebar -->
        <aside class="mw-list-sidebar">
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Search</div>
                <input id="metis-workspace-user-search" class="mw-input" type="text" placeholder="Name, email, PID, org unit">
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Navigation</div>
                <div class="mw-list-sidebar-actions">
                    <a href="<?php echo esc_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-secondary">Dashboard</a>
                </div>
            </div>
            <?php if ($can_manage) : ?>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Sync</div>
                <div class="mw-list-sidebar-actions">
                    <button type="button" id="metis-workspace-import-users" class="mw-btn mw-btn-secondary">Import Existing Users</button>
                    <button type="button" id="metis-workspace-full-sync" class="mw-btn mw-btn-secondary">Full Sync Users + Groups</button>
                    <button type="button" id="metis-workspace-sync-run" class="mw-btn mw-btn-secondary">Run Sync Now</button>
                </div>
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Actions</div>
                <div class="mw-list-sidebar-actions">
                    <button type="button" id="metis-workspace-add-user-open" class="mw-btn">Add User</button>
                    <button type="button" id="metis-workspace-add-group-open" class="mw-btn">Add Group</button>
                    <button type="button" id="metis-workspace-role-map-open" class="mw-btn mw-btn-secondary">Workspace Role Map</button>
                    <button type="button" id="metis-workspace-inspect-user-open" class="mw-btn mw-btn-secondary">Inspect User Attributes</button>
                </div>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Main Content -->
        <div class="mw-list-content">

    <section class="mw-premium-table metis-people-table metis-workspace-user-table">
        <div class="mw-premium-header">
            <div class="mw-premium-cell">Name</div>
            <div class="mw-premium-cell">Primary Email</div>
            <div class="mw-premium-cell">Org Unit</div>
            <div class="mw-premium-cell">Roles</div>
            <div class="mw-premium-cell">Status</div>
            <div class="mw-premium-cell">Linked Person</div>
        </div>
        <div id="metis-workspace-user-rows">
            <?php foreach ($workspace_users as $u) :
                $uid = (int) ($u['id'] ?? 0);
                $name = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                if ($name === '') $name = (string) ($u['display_name'] ?? '');
                if ($name === '') $name = (string) ($u['primary_email'] ?? '');
                $role_keys = (array) ($roles_by_user[$uid] ?? []);
                $role_labels = [];
                foreach ($role_keys as $rk) $role_labels[] = (string) ($workspace_roles[$rk] ?? $rk);
                $linked_pid = (string) ($u['linked_pid'] ?? '');
                $linked_name = (string) ($u['linked_name'] ?? '');
                $person_url = $linked_pid !== '' ? metis_people_person_url($linked_pid) : '';
                $search_blob = strtolower(trim(implode(' ', [
                    $name,
                    (string) ($u['primary_email'] ?? ''),
                    (string) ($u['org_unit_path'] ?? ''),
                    $linked_pid,
                ])));
            ?>
                <div class="mw-premium-row metis-workspace-user-row<?php echo $person_url !== '' ? ' is-clickable' : ''; ?>" data-search="<?php echo esc_attr($search_blob); ?>"<?php echo $person_url !== '' ? ' data-person-url="' . esc_attr($person_url) . '"' : ''; ?>>
                    <div class="mw-premium-cell">
                        <strong><?php echo esc_html($name); ?></strong>
                        <?php if (!empty($u['recovery_email'])) : ?><div class="mw-muted"><?php echo esc_html((string) $u['recovery_email']); ?></div><?php endif; ?>
                    </div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ($u['primary_email'] ?? '')); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ($u['org_unit_path'] ?? '/')); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(!empty($role_labels) ? implode(', ', $role_labels) : '—'); ?></div>
                    <div class="mw-premium-cell">
                        <?php if (!empty($u['is_suspended'])) : ?>
                            <span class="mw-chip">Suspended</span>
                        <?php else : ?>
                            <span class="mw-chip mw-chip-success">Active</span>
                        <?php endif; ?>
                        <?php if (!empty($u['is_protected'])) : ?>
                            <span class="mw-chip">Protected</span>
                        <?php endif; ?>
                    </div>
                    <div class="mw-premium-cell">
                        <?php
                        if ($linked_pid !== '') {
                            echo esc_html($linked_name !== '' ? ($linked_name . ' (' . $linked_pid . ')') : $linked_pid);
                        } else {
                            echo '—';
                        }
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="metis-people-role-grid" style="margin-top:14px;">
        <section class="mw-premium-wrap">
            <h3 class="metis-people-section-title">Groups</h3>
            <section class="mw-premium-table metis-people-table metis-workspace-group-table">
                <div class="mw-premium-header">
                    <div class="mw-premium-cell">Group</div>
                    <div class="mw-premium-cell">Group Email</div>
                    <div class="mw-premium-cell">Description</div>
                    <div class="mw-premium-cell">Members</div>
                    <div class="mw-premium-cell">Sync</div>
                </div>
                <div id="metis-workspace-group-rows">
                    <?php foreach ($workspace_groups as $g) :
                        $gid = (int) ($g['id'] ?? 0);
                        $sync_status = (string) ($g['sync_status'] ?? 'synced');
                    ?>
                        <div class="mw-premium-row metis-workspace-group-row<?php echo $can_manage ? ' is-clickable' : ''; ?>"<?php echo $can_manage ? ' data-group-id="' . esc_attr((string) $gid) . '"' : ''; ?>>
                            <div class="mw-premium-cell"><strong><?php echo esc_html((string) ($g['group_name'] ?? '')); ?></strong></div>
                            <div class="mw-premium-cell"><?php echo esc_html((string) ($g['group_email'] ?? '')); ?></div>
                            <div class="mw-premium-cell"><?php echo esc_html((string) ($g['description'] ?? '')); ?></div>
                            <div class="mw-premium-cell"><?php echo esc_html((string) (int) ($g['member_count'] ?? 0)); ?></div>
                            <div class="mw-premium-cell">
                                <span class="mw-chip<?php echo $sync_status === 'synced' ? ' mw-chip-success' : ''; ?>"><?php echo esc_html($sync_status); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($workspace_groups)) : ?><div class="mw-muted" style="padding:12px;">No groups recorded.</div><?php endif; ?>
                </div>
            </section>
        </section>

        <section class="mw-premium-wrap">
            <h3 class="metis-people-section-title">Sync Queue + Security Actions</h3>
            <div class="metis-people-mini-list">
                <?php foreach ($sync_jobs as $j) : ?>
                    <div class="metis-people-mini-item">
                        <div><strong><?php echo esc_html((string) ($j['job_type'] ?? '')); ?></strong> (<?php echo esc_html((string) ($j['status'] ?? 'queued')); ?>)</div>
                        <div class="mw-muted"><?php echo esc_html((string) ($j['entity_type'] ?? '') . ' #' . (string) ($j['entity_id'] ?? '')); ?></div>
                        <?php if (!empty($j['last_error'])) : ?>
                            <div class="mw-muted" style="color:#b42318;"><?php echo esc_html((string) $j['last_error']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($security_actions as $s) : ?>
                    <div class="metis-people-mini-item">
                        <div><strong><?php echo esc_html((string) ($s['action_type'] ?? '')); ?></strong> — <?php echo esc_html((string) ($s['primary_email'] ?? '')); ?></div>
                        <div class="mw-muted"><?php echo esc_html((string) ($s['status'] ?? 'pending') . ' | ' . (string) ($s['created_at'] ?? '')); ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($sync_jobs) && empty($security_actions)) : ?><div class="mw-muted">No queued sync or security actions.</div><?php endif; ?>
            </div>
        </section>
    </div><!-- /.metis-people-role-grid -->

        </div><!-- /.mw-list-content -->
    </div><!-- /.mw-list-layout -->
</div><!-- /.metis-people-workspace -->

<?php if ($can_manage) : ?>
    <div id="metis-workspace-user-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-people-modal-inner">
            <h3 class="metis-contacts-modal-title" id="metis-workspace-user-modal-title">Add Workspace User</h3>
            <form id="metis-workspace-user-form" class="metis-contact-form">
                <input type="hidden" id="metis-workspace-user-id" value="0">
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-user-primary-email">Primary Email</label><input id="metis-workspace-user-primary-email" class="mw-input" type="email" required></div>
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-user-linked-pid">Linked Person PID</label><input id="metis-workspace-user-linked-pid" class="mw-input" type="text" placeholder="PE..." autocomplete="off"></div>
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-user-first-name">First Name</label><input id="metis-workspace-user-first-name" class="mw-input" type="text"></div>
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-user-last-name">Last Name</label><input id="metis-workspace-user-last-name" class="mw-input" type="text"></div>
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-user-display-name">Display Name</label><input id="metis-workspace-user-display-name" class="mw-input" type="text"></div>
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-user-org-unit">Org Unit</label><input id="metis-workspace-user-org-unit" class="mw-input" type="text" placeholder="/"></div>
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-user-recovery-email">Recovery Email</label><input id="metis-workspace-user-recovery-email" class="mw-input" type="email"></div>
                <div class="metis-contact-field metis-contact-field-half"><label><input type="checkbox" id="metis-workspace-user-suspended"> Suspended</label></div>
                <div class="metis-contact-field metis-contact-field-half"><label><input type="checkbox" id="metis-workspace-user-protected"> Protected (non-removable)</label></div>
                <div class="metis-contact-field metis-contact-field-full">
                    <label>Workspace Admin Roles</label>
                    <div class="metis-people-check-grid">
                        <?php foreach ($workspace_roles as $role_key => $role_label) : if ($role_key === '') continue; ?>
                            <label class="metis-people-check"><input type="checkbox" class="metis-workspace-role-toggle" value="<?php echo esc_attr((string) $role_key); ?>"> <?php echo esc_html((string) $role_label); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="metis-contact-actions">
                    <button type="button" id="metis-workspace-user-cancel" class="mw-btn mw-btn-ghost">Cancel</button>
                    <button type="submit" class="mw-btn">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-workspace-group-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-people-modal-inner">
            <h3 class="metis-contacts-modal-title" id="metis-workspace-group-modal-title">Workspace Group Editor</h3>
            <form id="metis-workspace-group-form" class="metis-contact-form">
                <input type="hidden" id="metis-workspace-group-id" value="0">
                <div class="metis-people-tabs-nav" data-tabs-root>
                    <button type="button" class="mw-btn-xs metis-tab-btn is-active" data-tab-target="group-general">General</button>
                    <button type="button" class="mw-btn-xs metis-tab-btn" data-tab-target="group-users">Workspace Users</button>
                    <button type="button" class="mw-btn-xs metis-tab-btn" data-tab-target="group-external" id="metis-workspace-group-tab-external">External Users</button>
                    <button type="button" class="mw-btn-xs metis-tab-btn" data-tab-target="group-permissions">Permissions</button>
                </div>
                <div class="metis-tab-panel is-active" data-tab-panel="group-general">
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-group-name">Group Name</label><input id="metis-workspace-group-name" class="mw-input" type="text" required></div>
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-workspace-group-email">Group Email</label><input id="metis-workspace-group-email" class="mw-input" type="email" required></div>
                    <div class="metis-contact-field metis-contact-field-full"><label for="metis-workspace-group-description">Description</label><textarea id="metis-workspace-group-description" class="mw-input" rows="3"></textarea></div>
                </div>
                <div class="metis-tab-panel" data-tab-panel="group-users">
                    <div class="metis-contact-field metis-contact-field-full">
                        <div class="metis-workspace-members-grid-head">
                            <div>Include</div>
                            <div>User</div>
                            <div>Role</div>
                        </div>
                        <div id="metis-workspace-members-grid" class="metis-workspace-members-grid"></div>
                    </div>
                </div>
                <div class="metis-tab-panel" data-tab-panel="group-external">
                    <div class="metis-contact-field metis-contact-field-full">
                        <label>Add External Member</label>
                        <div class="metis-workspace-external-add">
                            <input id="metis-workspace-external-email" class="mw-input" type="email" placeholder="person@external.org">
                            <select id="metis-workspace-external-role" class="mw-select">
                                <option value="member">Member</option>
                                <option value="manager">Manager</option>
                                <option value="owner">Owner</option>
                            </select>
                            <button type="button" id="metis-workspace-external-add-btn" class="mw-btn-xs">Add</button>
                        </div>
                    </div>
                    <div class="metis-contact-field metis-contact-field-full">
                        <div class="metis-workspace-members-grid-head">
                            <div>Include</div>
                            <div>External User</div>
                            <div>Role</div>
                        </div>
                        <div id="metis-workspace-external-grid" class="metis-workspace-members-grid"></div>
                    </div>
                </div>
                <div class="metis-tab-panel" data-tab-panel="group-permissions">
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-workspace-group-perm-join">Who Can Join</label>
                        <select id="metis-workspace-group-perm-join" class="mw-select">
                            <option value="INVITED_CAN_JOIN">Invited Only</option>
                            <option value="CAN_REQUEST_TO_JOIN">Can Request</option>
                            <option value="ANYONE_CAN_JOIN">Anyone</option>
                        </select>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-workspace-group-perm-view">Who Can View Membership</label>
                        <select id="metis-workspace-group-perm-view" class="mw-select">
                            <option value="ALL_MANAGERS_CAN_VIEW">Managers</option>
                            <option value="ALL_MEMBERS_CAN_VIEW">Members</option>
                            <option value="ALL_IN_DOMAIN_CAN_VIEW">Domain</option>
                            <option value="ANYONE_CAN_VIEW">Anyone</option>
                        </select>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-workspace-group-perm-post">Who Can Post</label>
                        <select id="metis-workspace-group-perm-post" class="mw-select">
                            <option value="NONE_CAN_POST">No One</option>
                            <option value="ALL_MANAGERS_CAN_POST">Managers</option>
                            <option value="ALL_MEMBERS_CAN_POST">Members</option>
                            <option value="ALL_IN_DOMAIN_CAN_POST">Domain</option>
                            <option value="ANYONE_CAN_POST">Anyone</option>
                        </select>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label><input type="checkbox" id="metis-workspace-group-perm-external"> Allow External Members</label>
                    </div>
                </div>
                <div class="metis-contact-actions">
                    <button type="button" id="metis-workspace-group-delete" class="mw-btn mw-btn-danger" style="margin-right:auto;display:none;">Delete Group</button>
                    <button type="button" id="metis-workspace-group-cancel" class="mw-btn mw-btn-ghost">Cancel</button>
                    <button type="submit" class="mw-btn">Save Group</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-workspace-security-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-people-modal-inner">
            <h3 class="metis-contacts-modal-title">Workspace Security Action</h3>
            <form id="metis-workspace-security-form" class="metis-contact-form">
                <input type="hidden" id="metis-workspace-security-user-id" value="0">
                <div class="metis-contact-field metis-contact-field-full"><label for="metis-workspace-security-user-email">User</label><input id="metis-workspace-security-user-email" class="mw-input" type="text" readonly></div>
                <div class="metis-contact-field metis-contact-field-half">
                    <label for="metis-workspace-security-action">Action</label>
                    <select id="metis-workspace-security-action" class="mw-select">
                        <option value="reset_password">Reset Password</option>
                        <option value="revoke_sessions">Revoke Sessions</option>
                        <option value="force_2fa_reenroll">Force 2FA Re-enroll</option>
                        <option value="suspend_account">Suspend Account</option>
                        <option value="unsuspend_account">Unsuspend Account</option>
                    </select>
                </div>
                <div class="metis-contact-field metis-contact-field-full"><label for="metis-workspace-security-reason">Reason</label><textarea id="metis-workspace-security-reason" class="mw-input" rows="3" required></textarea></div>
                <div class="metis-contact-actions">
                    <button type="button" id="metis-workspace-security-cancel" class="mw-btn mw-btn-ghost">Cancel</button>
                    <button type="submit" class="mw-btn mw-btn-danger">Run Action</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-workspace-role-map-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-people-modal-inner metis-workspace-role-map-modal-inner">
            <h3 class="metis-contacts-modal-title">Workspace Role Map</h3>
            <p class="mw-muted">Live Google role data with internal key mapping used by Metis.</p>
            <div id="metis-workspace-role-map-alert" class="mw-alert" style="display:none;"></div>
            <div class="metis-workspace-role-map-table-wrap">
                <div class="mw-premium-table metis-people-table metis-workspace-role-map-table">
                    <div class="mw-premium-header">
                        <div class="mw-premium-cell">Role</div>
                        <div class="mw-premium-cell">Metis Key</div>
                        <div class="mw-premium-cell">Assigned</div>
                    </div>
                    <div id="metis-workspace-role-map-rows">
                        <div class="mw-muted" style="padding:12px;">Click Refresh to load live role mapping.</div>
                    </div>
                </div>
            </div>
            <div class="metis-contact-actions">
                <button type="button" id="metis-workspace-role-map-refresh" class="mw-btn">Refresh</button>
                <button type="button" id="metis-workspace-role-map-close" class="mw-btn mw-btn-ghost">Close</button>
            </div>
        </div>
    </div>

    <div id="metis-workspace-inspect-user-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-people-modal-inner metis-workspace-role-map-modal-inner">
            <h3 class="metis-contacts-modal-title">Inspect Workspace User Attributes</h3>
            <p class="mw-muted">Enter a Workspace email to view live custom schema values and available schema fields.</p>
            <form id="metis-workspace-inspect-user-form" class="metis-contact-form">
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-workspace-inspect-user-email">Workspace Email</label>
                    <input id="metis-workspace-inspect-user-email" class="mw-input" type="email" placeholder="user@mobilizewaco.org" required>
                </div>
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-workspace-inspect-user-output">Result</label>
                    <textarea id="metis-workspace-inspect-user-output" class="mw-input" rows="14" readonly></textarea>
                </div>
                <div class="metis-contact-actions">
                    <button type="button" id="metis-workspace-inspect-user-close" class="mw-btn mw-btn-ghost">Close</button>
                    <button type="submit" id="metis-workspace-inspect-user-run" class="mw-btn">Query User</button>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>
