<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Bulk Actions.</div>';
    return;
}
$can_manage = metis_people_can_manage();
$can_workspace_manage = function_exists('metis_people_can_workspace_manage') ? metis_people_can_workspace_manage() : $can_manage;
metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();
$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');
$workspace_groups_table = Metis_Tables::get('people_workspace_groups');
$workspace_users_table = Metis_Tables::get('people_workspace_users');
$positions_table = Metis_Tables::get('people_positions');
$people = $db->fetchAll("SELECT pid, display_name, email FROM {$people_table} ORDER BY display_name ASC, email ASC LIMIT 400") ?: [];
$roles = $db->fetchAll("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC") ?: [];
$stripe_roles = $db->fetchAll("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='stripe' ORDER BY role_name ASC") ?: [];
$workspace_groups = $db->fetchAll("SELECT group_email, group_name FROM {$workspace_groups_table} ORDER BY group_name ASC, group_email ASC") ?: [];
$workspace_org_units = $db->fetchAll("SELECT org_unit_path FROM {$workspace_users_table} WHERE org_unit_path IS NOT NULL AND org_unit_path <> '' GROUP BY org_unit_path ORDER BY org_unit_path ASC") ?: [];
$positions = $db->fetchAll("SELECT group_key, position_label FROM {$positions_table} ORDER BY group_key ASC, sort_order ASC, position_label ASC") ?: [];
?>

<div class="metis-people-ops">
    <div class="metis-people-bulk-page">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Bulk Actions' ) ); ?></h1>
    <p class="metis-subtitle">Run bulk updates across roles, position/type, Workspace groups, and Stripe access.</p>
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>
    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-toolbar-right"><a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-ghost">Dashboard</a></div>
    </div>

    <div class="metis-people-bulk-layout">
        <aside class="metis-premium-wrap metis-people-bulk-sidebar">
            <h3 class="metis-people-section-title">People</h3>
            <div class="metis-field metis-field-full">
                <label for="metis-bulk-person-search">Search</label>
                <input id="metis-bulk-person-search" type="text" class="metis-input" placeholder="Name, PID, email">
            </div>
            <div class="metis-people-bulk-sidebar-actions">
                <button type="button" id="metis-bulk-select-all" class="metis-btn metis-btn-secondary">Select All Visible</button>
                <button type="button" id="metis-bulk-clear-all" class="metis-btn metis-btn-ghost">Clear</button>
            </div>
            <div class="metis-muted metis-people-bulk-selected">Selected: <strong id="metis-bulk-selected-count">0</strong></div>
            <div id="metis-bulk-person-list" class="metis-people-bulk-person-list">
                <?php foreach($people as $person):
                    $person_display = trim((string) ($person['display_name'] ?? ''));
                    if ($person_display === '') $person_display = trim((string) ($person['email'] ?? ''));
                    $person_pid = (string) ($person['pid'] ?? '');
                    $person_email = trim((string) ($person['email'] ?? ''));
                    $search_blob = strtolower(trim($person_display . ' ' . $person_pid . ' ' . $person_email));
                ?>
                    <label class="metis-people-bulk-person-item" data-search="<?php echo metis_escape_attr($search_blob); ?>">
                        <input type="checkbox" class="metis-bulk-person" value="<?php echo metis_escape_attr($person_pid); ?>">
                        <span class="metis-people-bulk-person-text">
                            <strong><?php echo metis_escape_html($person_display); ?></strong>
                            <span class="metis-muted"><?php echo metis_escape_html($person_pid); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </aside>

        <div class="metis-people-bulk-main">
            <section class="metis-premium-wrap metis-people-bulk-rowbox">
                <form id="metis-bulk-role-form" class="metis-people-bulk-rowform">
                    <h3 class="metis-people-bulk-rowtitle">Metis Roles</h3>
                    <div class="metis-people-bulk-rowfields">
                        <div class="metis-people-bulk-field"><label for="metis-bulk-action">Action</label><select id="metis-bulk-action" class="metis-select"><option value="assign">Assign</option><option value="remove">Remove</option></select></div>
                        <div class="metis-people-bulk-field"><label for="metis-bulk-role">Role</label><select id="metis-bulk-role" class="metis-select"><option value="">Select role</option><?php foreach($roles as $role): ?><option value="<?php echo metis_escape_attr((string)$role['role_key']); ?>"><?php echo metis_escape_html((string)$role['role_name']); ?></option><?php endforeach; ?></select></div>
                    </div>
                    <button type="submit" class="metis-btn">Apply</button>
                </form>
            </section>

            <section class="metis-premium-wrap metis-people-bulk-rowbox">
                <form id="metis-bulk-profile-form" class="metis-people-bulk-rowform">
                    <h3 class="metis-people-bulk-rowtitle">Position + Type</h3>
                    <div class="metis-people-bulk-rowfields">
                        <div class="metis-people-bulk-field"><label for="metis-bulk-position-type">Position Type</label><select id="metis-bulk-position-type" class="metis-select"><option value="">Select type</option><option value="board">Board</option><option value="staff">Staff</option><option value="volunteer">Volunteer</option><option value="clear">Clear Position</option></select></div>
                        <div class="metis-people-bulk-field"><label for="metis-bulk-position-value">Position</label><select id="metis-bulk-position-value" class="metis-select"><option value="">Select position</option><?php foreach ($positions as $position) : $group_key = metis_key_clean((string) ($position['group_key'] ?? '')); $position_label = trim((string) ($position['position_label'] ?? '')); if ($position_label === '' || !in_array($group_key, ['board', 'staff', 'volunteer'], true)) continue; ?><option value="<?php echo metis_escape_attr($position_label); ?>" data-group="<?php echo metis_escape_attr($group_key); ?>"><?php echo metis_escape_html(ucfirst($group_key) . ': ' . $position_label); ?></option><?php endforeach; ?></select></div>
                    </div>
                    <button type="submit" class="metis-btn">Apply</button>
                </form>
            </section>

            <?php if ( $can_workspace_manage ) : ?>
            <section class="metis-premium-wrap metis-people-bulk-rowbox">
                <form id="metis-bulk-workspace-user-form" class="metis-people-bulk-rowform">
                    <h3 class="metis-people-bulk-rowtitle">Workspace Users</h3>
                    <div class="metis-people-bulk-rowfields">
                        <div class="metis-people-bulk-field">
                            <label for="metis-bulk-workspace-user-action">Action</label>
                            <select id="metis-bulk-workspace-user-action" class="metis-select">
                                <option value="set_org_unit">Move Org Unit</option>
                                <option value="suspend_account">Suspend</option>
                                <option value="unsuspend_account">Unsuspend</option>
                                <option value="reset_password">Force Password Reset</option>
                                <option value="set_hidden">Hide Service Account</option>
                                <option value="clear_hidden">Unhide Service Account</option>
                                <option value="create_drive_folder">Create Drive Folder</option>
                                <option value="sync_now">Sync Selected Now</option>
                            </select>
                        </div>
                        <div class="metis-people-bulk-field" id="metis-bulk-org-unit-field">
                            <label for="metis-bulk-org-unit">Org Unit</label>
                            <select id="metis-bulk-org-unit" class="metis-select">
                                <option value="/">/</option>
                                <?php foreach ($workspace_org_units as $org_unit_row):
                                    $org_unit_path = trim((string) ($org_unit_row['org_unit_path'] ?? ''));
                                    if ($org_unit_path === '' || $org_unit_path === '/') continue;
                                ?>
                                    <option value="<?php echo metis_escape_attr($org_unit_path); ?>"><?php echo metis_escape_html($org_unit_path); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="metis-btn">Apply</button>
                </form>
            </section>

            <section class="metis-premium-wrap metis-people-bulk-rowbox">
                <form id="metis-bulk-workspace-group-form" class="metis-people-bulk-rowform">
                    <h3 class="metis-people-bulk-rowtitle">Workspace Groups</h3>
                    <div class="metis-people-bulk-rowfields metis-people-bulk-rowfields-3">
                        <div class="metis-people-bulk-field"><label for="metis-bulk-workspace-group-action">Action</label><select id="metis-bulk-workspace-group-action" class="metis-select"><option value="assign">Assign</option><option value="remove">Remove</option></select></div>
                        <div class="metis-people-bulk-field"><label for="metis-bulk-workspace-group">Workspace Group</label><select id="metis-bulk-workspace-group" class="metis-select"><option value="">Select group</option><?php foreach($workspace_groups as $group): ?><option value="<?php echo metis_escape_attr((string)$group['group_email']); ?>"><?php echo metis_escape_html((string)$group['group_name'] . ' (' . (string)$group['group_email'] . ')'); ?></option><?php endforeach; ?></select></div>
                        <div class="metis-people-bulk-field"><label for="metis-bulk-workspace-member-role">Member Role</label><select id="metis-bulk-workspace-member-role" class="metis-select"><option value="member">Member</option><option value="manager">Manager</option><option value="owner">Owner</option></select></div>
                    </div>
                    <button type="submit" class="metis-btn">Apply</button>
                </form>
            </section>

            <section class="metis-premium-wrap metis-people-bulk-rowbox">
                <form id="metis-bulk-stripe-role-form" class="metis-people-bulk-rowform">
                    <h3 class="metis-people-bulk-rowtitle">Stripe Access</h3>
                    <div class="metis-people-bulk-rowfields">
                        <div class="metis-people-bulk-field"><label for="metis-bulk-stripe-action">Action</label><select id="metis-bulk-stripe-action" class="metis-select"><option value="set">Set Role</option><option value="clear">Clear Role</option></select></div>
                        <div class="metis-people-bulk-field"><label for="metis-bulk-stripe-role">Stripe Role</label><select id="metis-bulk-stripe-role" class="metis-select"><option value="">No Stripe Access</option><?php foreach($stripe_roles as $role): ?><option value="<?php echo metis_escape_attr((string)$role['role_key']); ?>"><?php echo metis_escape_html((string)$role['role_name']); ?></option><?php endforeach; ?></select></div>
                    </div>
                    <button type="submit" class="metis-btn">Apply</button>
                </form>
            </section>

            <section class="metis-premium-wrap metis-people-bulk-rowbox">
                <form id="metis-bulk-offboard-form" class="metis-people-bulk-rowform">
                    <h3 class="metis-people-bulk-rowtitle">Offboarding</h3>
                    <div class="metis-people-bulk-rowfields">
                        <div class="metis-people-bulk-field">
                            <label for="metis-bulk-offboard-confirm" class="metis-people-bulk-inline-check-label">
                                <input type="checkbox" id="metis-bulk-offboard-confirm" value="1">
                                Confirm offboarding selected people
                            </label>
                        </div>
                        <div class="metis-people-bulk-field"></div>
                    </div>
                    <button type="submit" class="metis-btn metis-btn-danger">Apply</button>
                </form>
            </section>
            <?php endif; ?>
        </div>
    </div>
</div>
