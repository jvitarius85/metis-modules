<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Roles.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$can_manage = metis_people_can_manage();
$snapshot = \Metis\Modules\People\ReadService::rolesListSnapshot();
$roles_by_domain = $snapshot['roles_by_domain'] ?? [];
?>

<div class="metis-people"
     data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
     data-person-base-url="<?php echo metis_escape_url( metis_people_person_url() ); ?>"
     data-role-base-url="<?php echo metis_escape_url( metis_people_role_url() ); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Roles' ) ); ?></h1>
    <p class="metis-subtitle">Manage Metis, Stripe, and Workspace role definitions.</p>
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ($can_manage) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <input id="metis-role-search" class="metis-input" type="text" placeholder="Role key or name">
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <div class="metis-list-sidebar-actions">
                    <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-secondary">Dashboard</a>
                    <a href="<?php echo metis_escape_url(metis_people_people_list_url()); ?>" class="metis-btn metis-btn-secondary">People</a>
                </div>
            </div>
            <?php if ($can_manage) : ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Actions</div>
                <div class="metis-list-sidebar-actions">
                    <button id="metis-role-add-open" type="button" class="metis-btn">Add Role</button>
                </div>
            </div>
            <?php endif; ?>
        <?php },
        'content' => static function () use ($roles_by_domain, $can_manage) { ?>
    <section class="metis-roles-wrap">
        <div id="metis-role-rows">
            <?php
            $domain_labels = [
                'metis' => 'Metis Roles',
                'stripe' => 'Stripe Roles',
                'workspace' => 'Workspace Roles',
            ];
            foreach ($domain_labels as $domain_key => $domain_label) :
                $domain_rows = (array) ($roles_by_domain[$domain_key] ?? []);
                if (empty($domain_rows)) continue;
            ?>
                <div class="metis-roles-domain-heading"><?php echo metis_escape_html($domain_label); ?></div>
                <div class="metis-roles-domain-block">
                <table class="metis-premium-table metis-role-table <?php echo $can_manage ? 'metis-role-table--manageable' : 'metis-role-table--readonly'; ?>">
                    <thead>
                    <tr class="metis-premium-row metis-premium-header metis-role-header-row">
                        <th class="metis-premium-cell metis-role-col-key" scope="col">Role Key</th>
                        <th class="metis-premium-cell metis-role-col-name" scope="col">Role Name</th>
                        <th class="metis-premium-cell metis-role-col-perms" scope="col">Permissions</th>
                        <th class="metis-premium-cell metis-role-col-members" scope="col">Members</th>
                        <th class="metis-premium-cell metis-role-col-system" scope="col">System</th>
                        <?php if ($can_manage) : ?><th class="metis-premium-cell metis-role-col-actions" scope="col">Actions</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                <?php foreach ($domain_rows as $i => $r):
                    $is_last = ($i === count($domain_rows) - 1);
                ?>
                    <tr class="metis-premium-row metis-role-row<?php echo $is_last ? ' is-last' : ''; ?>" data-href="<?php echo metis_escape_url( metis_people_role_url( (string) ( $r['role_key'] ?? '' ), (string) ( $r['role_domain'] ?? 'metis' ) ) ); ?>">
                        <td class="metis-premium-cell metis-role-col-key"><?php echo metis_escape_html((string) ($r['role_key'] ?? '')); ?></td>
                        <td class="metis-premium-cell metis-role-col-name"><?php echo metis_escape_html((string) ($r['role_name'] ?? '')); ?></td>
                        <td class="metis-premium-cell metis-role-col-perms"><?php echo metis_escape_html((string) ((int) ($r['permission_count'] ?? 0))); ?></td>
                        <td class="metis-premium-cell metis-role-col-members"><?php echo metis_escape_html((string) ((int) ($r['member_count'] ?? 0))); ?></td>
                        <td class="metis-premium-cell metis-role-col-system"><?php echo !empty($r['is_system']) ? 'Yes' : 'No'; ?></td>
                        <?php if ($can_manage) : ?><td class="metis-premium-cell metis-role-col-actions"><a href="<?php echo metis_escape_url( metis_people_role_url( (string) ( $r['role_key'] ?? '' ), (string) ( $r['role_domain'] ?? 'metis' ) ) ); ?>" class="metis-btn-xs">Edit</a></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
        <?php },
    ]); ?>
</div>

<?php if ($can_manage) : ?>
<div id="metis-role-add-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-people-modal-inner">
        <h3 class="metis-modal-title">Add Role</h3>
        <form id="metis-role-add-form" class="metis-form-grid">
            <div class="metis-field metis-field-half">
                <label for="metis-role-add-key">Role Key</label>
                <input id="metis-role-add-key" class="metis-input" type="text" required>
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-role-add-name">Role Name</label>
                <input id="metis-role-add-name" class="metis-input" type="text" required>
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-role-add-domain">Domain</label>
                <select id="metis-role-add-domain" class="metis-select">
                    <option value="metis">Metis</option>
                    <option value="stripe">Stripe</option>
                    <option value="workspace">Workspace</option>
                </select>
            </div>
            <div class="metis-field metis-field-full">
                <label for="metis-role-add-description">Description</label>
                <input id="metis-role-add-description" class="metis-input" type="text">
            </div>
            <div class="metis-form-actions">
                <button type="button" id="metis-role-add-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="submit" class="metis-btn">Create Role</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
