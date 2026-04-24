<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Roles.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();

$roles_table = Metis_Tables::get('people_roles');
$role_perms_table = Metis_Tables::get('people_role_perms');
$user_roles_table = Metis_Tables::get('people_user_roles');

$can_manage = metis_people_can_manage();
$roles_rows = $db->fetchAll( "SELECT * FROM {$roles_table} ORDER BY role_domain ASC, role_name ASC" ) ?: [];
$assign_rows = $db->fetchAll( "SELECT role_id FROM {$user_roles_table}" ) ?: [];

$role_member_count = [];
foreach ($assign_rows as $ar) {
    $role_id = (int) ($ar['role_id'] ?? 0);
    if ($role_id < 1) continue;
    $role_member_count[$role_id] = (int) ($role_member_count[$role_id] ?? 0) + 1;
}

$roles_by_domain = [
    'metis' => [],
    'stripe' => [],
    'workspace' => [],
];
foreach ($roles_rows as $role_row) {
    $domain = (string) ($role_row['role_domain'] ?? 'metis');
    if (!isset($roles_by_domain[$domain])) continue;
    $roles_by_domain[$domain][] = $role_row;
}
?>

<div class="metis-people"
     data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
     data-person-base-url="<?php echo metis_escape_url( metis_people_person_url() ); ?>"
     data-role-base-url="<?php echo metis_escape_url( metis_people_role_url() ); ?>">
    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Roles' ) ); ?></h1>
    <p class="mw-subtitle">Manage Metis, Stripe, and Workspace role definitions.</p>
    <div id="metis-people-alert" class="mw-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ($can_manage) { ?>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Search</div>
                <input id="metis-role-search" class="mw-input" type="text" placeholder="Role key or name">
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Navigation</div>
                <div class="mw-list-sidebar-actions">
                    <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-secondary">Dashboard</a>
                    <a href="<?php echo metis_escape_url(metis_people_people_list_url()); ?>" class="mw-btn mw-btn-secondary">People</a>
                </div>
            </div>
            <?php if ($can_manage) : ?>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Actions</div>
                <div class="mw-list-sidebar-actions">
                    <button id="metis-role-add-open" type="button" class="mw-btn">Add Role</button>
                </div>
            </div>
            <?php endif; ?>
        <?php },
        'content' => static function () use ($roles_by_domain, $can_manage, $db, $role_perms_table, $role_member_count) { ?>
    <section class="mw-premium-table metis-roles-wrap">
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
                <div class="mw-premium-header metis-role-header-row">
                    <div class="mw-premium-cell metis-role-col-key">Role Key</div>
                    <div class="mw-premium-cell metis-role-col-name">Role Name</div>
                    <div class="mw-premium-cell metis-role-col-perms">Permissions</div>
                    <div class="mw-premium-cell metis-role-col-members">Members</div>
                    <div class="mw-premium-cell metis-role-col-system">System</div>
                    <?php if ($can_manage) : ?><div class="mw-premium-cell metis-role-col-actions">Actions</div><?php endif; ?>
                </div>
                <?php foreach ($domain_rows as $i => $r):
                    $rid = (int) ($r['id'] ?? 0);
                    $perm_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$role_perms_table} WHERE role_id = %d AND allow_access = 1", [ $rid ] );
                    $members_count = (int) ($role_member_count[$rid] ?? 0);
                    $is_last = ($i === count($domain_rows) - 1);
                ?>
                    <div class="mw-premium-row metis-role-row<?php echo $is_last ? ' is-last' : ''; ?>" data-href="<?php echo metis_escape_url( metis_people_role_url( (string) ( $r['role_key'] ?? '' ), (string) ( $r['role_domain'] ?? 'metis' ) ) ); ?>">
                        <div class="mw-premium-cell metis-role-col-key"><?php echo metis_escape_html((string) ($r['role_key'] ?? '')); ?></div>
                        <div class="mw-premium-cell metis-role-col-name"><?php echo metis_escape_html((string) ($r['role_name'] ?? '')); ?></div>
                        <div class="mw-premium-cell metis-role-col-perms"><?php echo metis_escape_html((string) $perm_count); ?></div>
                        <div class="mw-premium-cell metis-role-col-members"><?php echo metis_escape_html((string) $members_count); ?></div>
                        <div class="mw-premium-cell metis-role-col-system"><?php echo !empty($r['is_system']) ? 'Yes' : 'No'; ?></div>
                        <?php if ($can_manage) : ?><div class="mw-premium-cell metis-role-col-actions"><a href="<?php echo metis_escape_url( metis_people_role_url( (string) ( $r['role_key'] ?? '' ), (string) ( $r['role_domain'] ?? 'metis' ) ) ); ?>" class="mw-btn-xs">Edit</a></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
        <?php },
    ]); ?>
</div>

<?php if ($can_manage) : ?>
<div id="metis-role-add-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner metis-people-modal-inner">
        <h3 class="metis-contacts-modal-title">Add Role</h3>
        <form id="metis-role-add-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-role-add-key">Role Key</label>
                <input id="metis-role-add-key" class="mw-input" type="text" required>
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-role-add-name">Role Name</label>
                <input id="metis-role-add-name" class="mw-input" type="text" required>
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-role-add-domain">Domain</label>
                <select id="metis-role-add-domain" class="mw-select">
                    <option value="metis">Metis</option>
                    <option value="stripe">Stripe</option>
                    <option value="workspace">Workspace</option>
                </select>
            </div>
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-role-add-description">Description</label>
                <input id="metis-role-add-description" class="mw-input" type="text">
            </div>
            <div class="metis-contact-actions">
                <button type="button" id="metis-role-add-cancel" class="mw-btn mw-btn-ghost">Cancel</button>
                <button type="submit" class="mw-btn">Create Role</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
