<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Role Templates.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();
$templates_table = Metis_Tables::get('people_role_templates');
$template_roles_table = Metis_Tables::get('people_template_roles');
$roles_table = Metis_Tables::get('people_roles');
$can_manage = metis_people_can_manage();

$templates = $db->fetchAll( "SELECT * FROM {$templates_table} ORDER BY template_name ASC" ) ?: [];
$metis_roles = $db->fetchAll( "SELECT id, role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC" ) ?: [];
?>

<div class="metis-people-ops">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Role Templates' ) ); ?></h1>
    <p class="metis-subtitle">Create and apply reusable role bundles.</p>
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-toolbar-right">
            <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-ghost">Dashboard</a>
        </div>
    </div>

    <section class="metis-premium-wrap">
        <h3 class="metis-people-section-title">Save Template</h3>
        <form id="metis-template-save-form" class="metis-form-grid">
            <div class="metis-field metis-field-half"><label for="metis-template-key">Template Key</label><input id="metis-template-key" class="metis-input" type="text" required></div>
            <div class="metis-field metis-field-half"><label for="metis-template-name">Template Name</label><input id="metis-template-name" class="metis-input" type="text" required></div>
            <div class="metis-field metis-field-full"><label for="metis-template-desc">Description</label><input id="metis-template-desc" class="metis-input" type="text"></div>
            <div class="metis-field metis-field-full"><label for="metis-template-checklist">Onboarding Checklist (one item per line)</label><textarea id="metis-template-checklist" class="metis-input" rows="4" placeholder="Set up workspace account&#10;Complete security training"></textarea></div>
            <div class="metis-field metis-field-full">
                <label>Include Metis Roles</label>
                <div class="metis-people-check-grid">
                    <?php foreach ($metis_roles as $role): ?>
                        <label class="metis-people-check"><input type="checkbox" class="metis-template-role" value="<?php echo metis_escape_attr((string) $role['role_key']); ?>"> <?php echo metis_escape_html((string) $role['role_name']); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="metis-form-actions"><button type="submit" class="metis-btn">Save Template</button></div>
        </form>
    </section>

    <section class="metis-premium-wrap" style="margin-top:14px;">
        <h3 class="metis-people-section-title">Apply Template</h3>
        <form id="metis-template-apply-form" class="metis-form-grid">
            <div class="metis-field metis-field-half"><label for="metis-template-apply-pid">Target PID</label><input id="metis-template-apply-pid" class="metis-input" type="text" required></div>
            <div class="metis-field metis-field-half"><label for="metis-template-apply-key">Template</label><select id="metis-template-apply-key" class="metis-select" required><option value="">Select template</option><?php foreach ($templates as $template): ?><option value="<?php echo metis_escape_attr((string) $template['template_key']); ?>"><?php echo metis_escape_html((string) $template['template_name']); ?></option><?php endforeach; ?></select></div>
            <div class="metis-form-actions"><button type="submit" class="metis-btn">Apply Template</button></div>
        </form>
    </section>
</div>
