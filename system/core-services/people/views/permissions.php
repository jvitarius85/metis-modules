<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Permissions.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$snapshot = \Metis\Modules\People\ReadService::permissionsSnapshot();
$permissions = $snapshot['permissions'] ?? [];
$metis_role_count = (int) ($snapshot['metis_role_count'] ?? 0);
$coverage_by_permission = $snapshot['coverage_by_permission'] ?? [];
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
            $coverage = (int) ($coverage_by_permission[$perm_id] ?? 0);
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
