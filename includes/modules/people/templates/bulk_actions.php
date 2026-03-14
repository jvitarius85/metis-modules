<?php if (!defined('ABSPATH')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Bulk Actions.</div>';
    return;
}
$can_manage = metis_people_can_manage();
$can_workspace_manage = function_exists('metis_people_can_workspace_manage') ? metis_people_can_workspace_manage() : $can_manage;
metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

global $wpdb;
$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');
$workspace_groups_table = Metis_Tables::get('people_workspace_groups');
$people = $wpdb->get_results("SELECT pid, display_name, email FROM {$people_table} ORDER BY display_name ASC, email ASC LIMIT 400", ARRAY_A) ?: [];
$roles = $wpdb->get_results("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC", ARRAY_A) ?: [];
$stripe_roles = $wpdb->get_results("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='stripe' ORDER BY role_name ASC", ARRAY_A) ?: [];
$workspace_groups = $wpdb->get_results("SELECT group_email, group_name FROM {$workspace_groups_table} ORDER BY group_name ASC, group_email ASC", ARRAY_A) ?: [];
?>

<div class="metis-people-ops">
    <h1 class="mw-page-title">Bulk Actions</h1>
    <p class="mw-subtitle">Assign roles, Workspace groups, and Stripe access to selected people.</p>
    <div id="metis-people-alert" class="mw-alert" style="display:none;"></div>
    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-contacts-toolbar-right"><a href="<?php echo esc_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-ghost">Dashboard</a></div>
    </div>

    <section class="mw-premium-wrap" style="margin-bottom:16px;">
        <form id="metis-bulk-role-form" class="metis-contact-form">
            <h3 style="margin:0 0 8px;">Bulk Metis Roles</h3>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-bulk-role">Role</label><select id="metis-bulk-role" class="mw-select" required><option value="">Select role</option><?php foreach($roles as $role): ?><option value="<?php echo esc_attr((string)$role['role_key']); ?>"><?php echo esc_html((string)$role['role_name']); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-bulk-action">Action</label><select id="metis-bulk-action" class="mw-select" required><option value="assign">Assign</option><option value="remove">Remove</option></select></div>
            <div class="metis-contact-actions"><button type="submit" class="mw-btn">Run Metis Role Action</button></div>
        </form>
    </section>

    <?php if ( $can_workspace_manage ) : ?>
    <section class="mw-premium-wrap" style="margin-bottom:16px;">
        <form id="metis-bulk-workspace-group-form" class="metis-contact-form">
            <h3 style="margin:0 0 8px;">Bulk Workspace Groups</h3>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-bulk-workspace-group">Workspace Group</label><select id="metis-bulk-workspace-group" class="mw-select" required><option value="">Select group</option><?php foreach($workspace_groups as $group): ?><option value="<?php echo esc_attr((string)$group['group_email']); ?>"><?php echo esc_html((string)$group['group_name'] . ' (' . (string)$group['group_email'] . ')'); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-bulk-workspace-group-action">Action</label><select id="metis-bulk-workspace-group-action" class="mw-select" required><option value="assign">Assign</option><option value="remove">Remove</option></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-bulk-workspace-member-role">Member Role</label><select id="metis-bulk-workspace-member-role" class="mw-select"><option value="member">Member</option><option value="manager">Manager</option><option value="owner">Owner</option></select></div>
            <div class="metis-contact-actions"><button type="submit" class="mw-btn">Run Workspace Group Action</button></div>
        </form>
    </section>

    <section class="mw-premium-wrap" style="margin-bottom:16px;">
        <form id="metis-bulk-stripe-role-form" class="metis-contact-form">
            <h3 style="margin:0 0 8px;">Bulk Stripe Access</h3>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-bulk-stripe-role">Stripe Role</label><select id="metis-bulk-stripe-role" class="mw-select"><option value="">No Stripe Access</option><?php foreach($stripe_roles as $role): ?><option value="<?php echo esc_attr((string)$role['role_key']); ?>"><?php echo esc_html((string)$role['role_name']); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-bulk-stripe-action">Action</label><select id="metis-bulk-stripe-action" class="mw-select" required><option value="set">Set Role</option><option value="clear">Clear Role</option></select></div>
            <div class="metis-contact-actions"><button type="submit" class="mw-btn">Run Stripe Action</button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="mw-premium-wrap">
        <form class="metis-contact-form" onsubmit="return false;">
            <div class="metis-contact-field metis-contact-field-full">
                <label>People (applies to all bulk actions above)</label>
                <div class="metis-people-check-grid" style="max-height:320px;overflow-y:auto;">
                    <?php foreach($people as $person): ?>
                        <label class="metis-people-check"><input type="checkbox" class="metis-bulk-person" value="<?php echo esc_attr((string)$person['pid']); ?>"> <?php echo esc_html((string)$person['display_name'] . ' (' . (string)$person['pid'] . ')'); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
    </section>
</div>
