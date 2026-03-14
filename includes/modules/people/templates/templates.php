<?php if (!defined('ABSPATH')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Role Templates.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

global $wpdb;
$templates_table = Metis_Tables::get('people_role_templates');
$template_roles_table = Metis_Tables::get('people_template_roles');
$roles_table = Metis_Tables::get('people_roles');
$can_manage = metis_people_can_manage();

$templates = $wpdb->get_results("SELECT * FROM {$templates_table} ORDER BY template_name ASC", ARRAY_A) ?: [];
$metis_roles = $wpdb->get_results("SELECT id, role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC", ARRAY_A) ?: [];
?>

<div class="metis-people-ops">
    <h1 class="mw-page-title">Role Templates</h1>
    <p class="mw-subtitle">Create and apply reusable role bundles.</p>
    <div id="metis-people-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-contacts-toolbar-right">
            <a href="<?php echo esc_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-ghost">Dashboard</a>
        </div>
    </div>

    <section class="mw-premium-wrap">
        <h3 class="metis-people-section-title">Save Template</h3>
        <form id="metis-template-save-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-template-key">Template Key</label><input id="metis-template-key" class="mw-input" type="text" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-template-name">Template Name</label><input id="metis-template-name" class="mw-input" type="text" required></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-template-desc">Description</label><input id="metis-template-desc" class="mw-input" type="text"></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-template-checklist">Onboarding Checklist (one item per line)</label><textarea id="metis-template-checklist" class="mw-input" rows="4" placeholder="Set up workspace account&#10;Complete security training"></textarea></div>
            <div class="metis-contact-field metis-contact-field-full">
                <label>Include Metis Roles</label>
                <div class="metis-people-check-grid">
                    <?php foreach ($metis_roles as $role): ?>
                        <label class="metis-people-check"><input type="checkbox" class="metis-template-role" value="<?php echo esc_attr((string) $role['role_key']); ?>"> <?php echo esc_html((string) $role['role_name']); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="metis-contact-actions"><button type="submit" class="mw-btn">Save Template</button></div>
        </form>
    </section>

    <section class="mw-premium-wrap" style="margin-top:14px;">
        <h3 class="metis-people-section-title">Apply Template</h3>
        <form id="metis-template-apply-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-template-apply-pid">Target PID</label><input id="metis-template-apply-pid" class="mw-input" type="text" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-template-apply-key">Template</label><select id="metis-template-apply-key" class="mw-select" required><option value="">Select template</option><?php foreach ($templates as $template): ?><option value="<?php echo esc_attr((string) $template['template_key']); ?>"><?php echo esc_html((string) $template['template_name']); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-actions"><button type="submit" class="mw-btn">Apply Template</button></div>
        </form>
    </section>
</div>
