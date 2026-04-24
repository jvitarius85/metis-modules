<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Permissions.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();

$perms_table = Metis_Tables::get('people_permissions');
$roles_table = Metis_Tables::get('people_roles');
$role_perms_table = Metis_Tables::get('people_role_perms');

$permissions = $db->fetchAll("SELECT * FROM {$perms_table} ORDER BY module_slug ASC, action_key ASC") ?: [];
$metis_role_count = (int) $db->scalar("SELECT COUNT(*) FROM {$roles_table} WHERE role_domain = 'metis'");
?>

<div class="metis-people-permissions">
    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Permissions' ) ); ?></h1>
    <p class="mw-subtitle">Permission keys available in Metis and their role coverage.</p>

    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-contacts-toolbar-right">
            <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-ghost">Dashboard</a>
            <a href="<?php echo metis_escape_url(metis_people_roles_list_url()); ?>" class="mw-btn mw-btn-ghost">Roles</a>
            <a href="<?php echo metis_escape_url(metis_people_people_list_url()); ?>" class="mw-btn mw-btn-ghost">People</a>
        </div>
    </div>

    <section class="mw-premium-table">
        <div class="mw-premium-header" style="display:grid;grid-template-columns:minmax(160px,1fr) minmax(120px,0.7fr) minmax(220px,1.3fr) minmax(130px,0.8fr);">
            <div class="mw-premium-cell">Module</div>
            <div class="mw-premium-cell">Action</div>
            <div class="mw-premium-cell">Permission Key</div>
            <div class="mw-premium-cell">Metis Roles</div>
        </div>
        <?php foreach ($permissions as $perm):
            $perm_id = (int) ($perm['id'] ?? 0);
            $coverage = (int) $db->scalar(
                "SELECT COUNT(DISTINCT rp.role_id)
                 FROM {$role_perms_table} rp
                 INNER JOIN {$roles_table} r ON r.id = rp.role_id
                 WHERE rp.permission_id = %d
                   AND rp.allow_access = 1
                   AND r.role_domain = 'metis'",
                [ $perm_id ]
            );
        ?>
            <div class="mw-premium-row" style="display:grid;grid-template-columns:minmax(160px,1fr) minmax(120px,0.7fr) minmax(220px,1.3fr) minmax(130px,0.8fr);">
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) ($perm['module_slug'] ?? '')); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) ($perm['action_key'] ?? '')); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) ($perm['permission_key'] ?? '')); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html((string) $coverage . ' / ' . (string) $metis_role_count); ?></div>
            </div>
        <?php endforeach; ?>
    </section>
</div>
