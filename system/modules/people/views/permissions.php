<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Permissions.</div>';
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
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Permissions' ) ); ?></h1>
    <p class="metis-subtitle">Permission keys available in Metis and their role coverage.</p>

    <div class="metis-people-toolbar metis-people-roles-toolbar">
        <div class="metis-toolbar-right">
            <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-ghost">Dashboard</a>
            <a href="<?php echo metis_escape_url(metis_people_roles_list_url()); ?>" class="metis-btn metis-btn-ghost">Roles</a>
            <a href="<?php echo metis_escape_url(metis_people_people_list_url()); ?>" class="metis-btn metis-btn-ghost">People</a>
        </div>
    </div>

    <table class="metis-premium-table metis-people-permissions-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Module</th>
                <th class="metis-premium-cell" scope="col">Action</th>
                <th class="metis-premium-cell" scope="col">Permission Key</th>
                <th class="metis-premium-cell" scope="col">Metis Roles</th>
            </tr>
        </thead>
        <tbody>
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
            <tr class="metis-premium-row">
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($perm['module_slug'] ?? '')); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($perm['action_key'] ?? '')); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($perm['permission_key'] ?? '')); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) $coverage . ' / ' . (string) $metis_role_count); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
