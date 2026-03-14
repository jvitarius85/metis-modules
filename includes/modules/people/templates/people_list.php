<?php if (!defined('ABSPATH')) exit;

if (!metis_people_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view People.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

global $wpdb;

$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');
$user_roles_table = Metis_Tables::get('people_user_roles');

$can_manage = metis_people_can_manage();

$people_rows = $wpdb->get_results("SELECT * FROM {$people_table} ORDER BY display_name ASC, email ASC", ARRAY_A) ?: [];
$roles_rows = $wpdb->get_results("SELECT * FROM {$roles_table} WHERE role_domain = 'metis' ORDER BY role_name ASC", ARRAY_A) ?: [];

$role_by_id = [];
$role_by_key = [];
foreach ($roles_rows as $r) {
    $rid = (int) ($r['id'] ?? 0);
    if ($rid < 1) continue;
    $role_by_id[$rid] = $r;
    $role_by_key[(string) $r['role_key']] = $r;
}

$assign_rows = $wpdb->get_results(
    "SELECT ur.person_id, ur.role_id FROM {$user_roles_table} ur",
    ARRAY_A
) ?: [];
$role_ids_by_person = [];
foreach ($assign_rows as $ar) {
    $person_id = (int) ($ar['person_id'] ?? 0);
    $role_id = (int) ($ar['role_id'] ?? 0);
    if ($person_id < 1 || $role_id < 1) continue;
    if (!isset($role_ids_by_person[$person_id])) {
        $role_ids_by_person[$person_id] = [];
    }
    $role_ids_by_person[$person_id][] = $role_id;
}

$people = [];
foreach ($people_rows as $p) {
    $id = (int) ($p['id'] ?? 0);
    if ($id < 1) continue;

    $role_keys = [];
    foreach ((array) ($role_ids_by_person[$id] ?? []) as $rid) {
        if (!empty($role_by_id[$rid]['role_key'])) {
            $role_keys[] = (string) $role_by_id[$rid]['role_key'];
        }
    }

    $full_name = trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? ''));
    if ($full_name === '') {
        $full_name = (string) ($p['display_name'] ?? '');
    }

    $people[] = [
        'id' => $id,
        'pid' => (string) ($p['pid'] ?? ''),
        'auth_provider' => (string) ($p['auth_provider'] ?? 'metis'),
        'email' => (string) ($p['email'] ?? ''),
        'full_name' => $full_name,
        'linked_donor_id' => (string) ($p['linked_donor_id'] ?? ''),
        'is_workspace_user' => !empty($p['is_workspace_user']) ? 1 : 0,
        'workspace_email' => (string) ($p['workspace_email'] ?? ''),
        'stripe_role' => (string) ($p['stripe_role'] ?? ''),
        'roles' => $role_keys,
    ];
}
?>

<div class="metis-people"
     data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
     data-person-base-url="<?php echo esc_url( metis_people_person_url() ); ?>"
     data-role-base-url="<?php echo esc_url( metis_people_role_url() ); ?>">
    <h1 class="mw-page-title">People</h1>
    <p class="mw-subtitle">View and edit Metis people profiles.</p>
    <div id="metis-people-alert" class="mw-alert" style="display:none;"></div>

    <div class="mw-list-layout">

    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Search</div>
            <input id="metis-people-search" class="mw-input" type="text" placeholder="Name, email, role, donor ID, or PID">
        </div>
        <?php if ($can_manage) : ?>
        <div class="mw-list-sidebar-actions">
            <button id="metis-people-add-open" type="button" class="mw-btn mw-btn-xs">Add Person</button>
            <a href="<?php echo esc_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Dashboard</a>
        </div>
        <?php else : ?>
        <div class="mw-list-sidebar-actions">
            <a href="<?php echo esc_url(metis_people_base_url()); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Dashboard</a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="mw-list-content">

    <section class="mw-premium-table metis-people-table">
        <div class="mw-premium-header">
            <div class="mw-premium-cell">Name</div>
            <div class="mw-premium-cell">Email</div>
            <div class="mw-premium-cell">Provider</div>
            <div class="mw-premium-cell">Roles</div>
            <div class="mw-premium-cell">Workspace</div>
            <div class="mw-premium-cell">Stripe Role</div>
            <?php if ($can_manage) : ?><div class="mw-premium-cell">Actions</div><?php endif; ?>
        </div>
        <div id="metis-people-rows">
            <?php foreach ($people as $p):
                $role_labels = [];
                foreach ((array) $p['roles'] as $rk) {
                    $role_labels[] = !empty($role_by_key[$rk]['role_name']) ? (string) $role_by_key[$rk]['role_name'] : (string) $rk;
                }
                $workspace_text = $p['is_workspace_user'] ? ($p['workspace_email'] !== '' ? $p['workspace_email'] : 'Enabled') : 'No';
                $search_blob = strtolower(trim(implode(' ', [
                    $p['pid'],
                    $p['full_name'],
                    $p['email'],
                    $p['linked_donor_id'],
                    implode(' ', $role_labels),
                    $p['auth_provider'],
                    $p['workspace_email'],
                    $p['stripe_role'],
                ])));
            ?>
                <div class="mw-premium-row metis-people-row"
                    data-id="<?php echo esc_attr((string) $p['id']); ?>"
                    data-search="<?php echo esc_attr($search_blob); ?>"
                    data-href="<?php echo esc_url( metis_people_person_url( (string) $p['pid'] ) ); ?>">
                    <div class="mw-premium-cell"><strong><?php echo esc_html($p['full_name']); ?></strong><div class="mw-muted"><?php echo esc_html($p['pid']); ?></div></div>
                    <div class="mw-premium-cell"><?php echo esc_html($p['email']); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(ucfirst($p['auth_provider'])); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(!empty($role_labels) ? implode(', ', $role_labels) : '—'); ?></div>
                    <div class="mw-premium-cell">
                        <?php if ( $p['is_workspace_user'] && $p['workspace_email'] !== '' ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'panel' => 'workspace' ], metis_people_person_url( (string) $p['pid'] ) ) ); ?>">
                                <?php echo esc_html($workspace_text); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html($workspace_text); ?>
                        <?php endif; ?>
                    </div>
                    <div class="mw-premium-cell"><?php echo esc_html($p['stripe_role'] !== '' ? $p['stripe_role'] : '—'); ?></div>
                    <?php if ($can_manage) : ?><div class="mw-premium-cell"><a href="<?php echo esc_url( metis_people_person_url( (string) $p['pid'] ) ); ?>" class="mw-btn-xs">Edit</a></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    </div><!-- /mw-list-content -->
    </div><!-- /mw-list-layout -->
</div>

<?php if ($can_manage) : ?>
<div id="metis-people-add-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner metis-people-modal-inner">
        <h3 class="metis-contacts-modal-title">Add Person</h3>
        <form id="metis-people-add-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-people-add-first-name">First Name</label>
                <input id="metis-people-add-first-name" class="mw-input" type="text" required>
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-people-add-last-name">Last Name</label>
                <input id="metis-people-add-last-name" class="mw-input" type="text" required>
            </div>
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-people-add-email">Email</label>
                <input id="metis-people-add-email" class="mw-input" type="email" required>
            </div>
            <div class="metis-contact-actions">
                <button type="button" id="metis-people-add-cancel" class="mw-btn mw-btn-ghost">Cancel</button>
                <button type="submit" class="mw-btn">Create Person</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
