<?php if (!defined('METIS_ROOT')) exit;

if (!metis_people_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view People.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();

$people_table = Metis_Tables::get('people');
$roles_table = Metis_Tables::get('people_roles');
$user_roles_table = Metis_Tables::get('people_user_roles');

$can_manage = metis_people_can_manage();

$per_page = 100;
$page = max(1, (int) (metis_request_get()['page'] ?? 1));
$offset = max(0, ($page - 1) * $per_page);

$total_row = $db->fetchOne("SELECT COUNT(*) AS total_count FROM {$people_table}") ?: [];
$total_people = (int) ($total_row['total_count'] ?? 0);
$total_pages = max(1, (int) ceil($total_people / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = max(0, ($page - 1) * $per_page);
}

$people_rows = $db->fetchAll(
    "SELECT id, pid, auth_provider, email, first_name, last_name, display_name, linked_donor_id, is_workspace_user, workspace_email, stripe_role
     FROM {$people_table}
     ORDER BY display_name ASC, email ASC
     LIMIT %d OFFSET %d",
    [ $per_page, $offset ]
) ?: [];
$roles_rows = $db->fetchAll( "SELECT * FROM {$roles_table} WHERE role_domain = 'metis' ORDER BY role_name ASC" ) ?: [];

$role_by_id = [];
$role_by_key = [];
foreach ($roles_rows as $r) {
    $rid = (int) ($r['id'] ?? 0);
    if ($rid < 1) continue;
    $role_by_id[$rid] = $r;
    $role_by_key[(string) $r['role_key']] = $r;
}

$person_ids = array_values(array_filter(array_map(static function ($row): int {
    return (int) ($row['id'] ?? 0);
}, $people_rows), static function (int $id): bool {
    return $id > 0;
}));

$assign_rows = [];
if (!empty($person_ids)) {
    $id_list = implode(',', array_map('intval', $person_ids));
    $assign_rows = $db->fetchAll(
        "SELECT ur.person_id, ur.role_id FROM {$user_roles_table} ur WHERE ur.person_id IN ({$id_list})",
    ) ?: [];
}
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
     data-person-base-url="<?php echo metis_escape_url( metis_people_person_url() ); ?>"
     data-role-base-url="<?php echo metis_escape_url( metis_people_role_url() ); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'People' ) ); ?></h1>
    <p class="metis-subtitle">View and edit Metis people profiles.</p>
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ($can_manage) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Filter Current Page</div>
                <input id="metis-people-search" class="metis-input" type="text" placeholder="Name, email, role, donor ID, or PID">
            </div>
            <?php if ($can_manage) : ?>
            <div class="metis-list-sidebar-actions">
                <button id="metis-people-add-open" type="button" class="metis-btn metis-btn-xs">Add Person</button>
                <a href="<?php echo metis_escape_url(metis_portal_url('people', 'positions')); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">Manage Positions</a>
                <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">Dashboard</a>
            </div>
            <?php else : ?>
            <div class="metis-list-sidebar-actions">
                <a href="<?php echo metis_escape_url(metis_people_base_url()); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">Dashboard</a>
            </div>
            <?php endif; ?>
        <?php },
        'content' => static function () use ($people, $can_manage, $role_by_key, $page, $per_page, $total_people, $total_pages) { ?>

    <table class="metis-premium-table metis-people-table <?php echo $can_manage ? 'metis-people-table--manageable' : 'metis-people-table--readonly'; ?>">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Name</th>
                <th class="metis-premium-cell" scope="col">Email</th>
                <th class="metis-premium-cell" scope="col">Roles</th>
                <th class="metis-premium-cell" scope="col">Workspace</th>
                <?php if ($can_manage) : ?><th class="metis-premium-cell" scope="col">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="metis-people-rows">
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
                <tr class="metis-premium-row metis-people-row"
                    data-id="<?php echo metis_escape_attr((string) $p['id']); ?>"
                    data-search="<?php echo metis_escape_attr($search_blob); ?>"
                    data-href="<?php echo metis_escape_url( metis_people_person_url( (string) $p['pid'] ) ); ?>">
                    <td class="metis-premium-cell"><strong><?php echo metis_escape_html($p['full_name']); ?></strong><div class="metis-muted"><?php echo metis_escape_html($p['pid']); ?></div></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html($p['email']); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html(!empty($role_labels) ? implode(', ', $role_labels) : '—'); ?></td>
                    <td class="metis-premium-cell">
                        <?php if ( $p['is_workspace_user'] && $p['workspace_email'] !== '' ) : ?>
                            <a href="<?php echo metis_escape_url( metis_add_query_arg( [ 'panel' => 'workspace' ], metis_people_person_url( (string) $p['pid'] ) ) ); ?>">
                                <?php echo metis_escape_html($workspace_text); ?>
                            </a>
                        <?php else : ?>
                            <?php echo metis_escape_html($workspace_text); ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($can_manage) : ?><td class="metis-premium-cell"><a href="<?php echo metis_escape_url( metis_people_person_url( (string) $p['pid'] ) ); ?>" class="metis-btn-xs">Edit</a></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $range_start = $total_people > 0 ? (($page - 1) * $per_page) + 1 : 0;
    $range_end = min($page * $per_page, $total_people);
    $prev_url = $page > 1 ? metis_add_query_arg([ 'page' => $page - 1 ], metis_people_people_list_url()) : '';
    $next_url = $page < $total_pages ? metis_add_query_arg([ 'page' => $page + 1 ], metis_people_people_list_url()) : '';
    ?>
    <div class="metis-help" style="margin-top:10px;">
        Showing <?php echo metis_escape_html((string) $range_start); ?>-<?php echo metis_escape_html((string) $range_end); ?>
        of <?php echo metis_escape_html((string) $total_people); ?> people.
    </div>
    <div class="metis-list-sidebar-actions" style="margin-top:8px;">
        <?php if ($prev_url !== '') : ?>
            <a href="<?php echo metis_escape_url($prev_url); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">Previous</a>
        <?php else : ?>
            <span class="metis-btn metis-btn-xs metis-btn-ghost" style="pointer-events:none;opacity:.5;">Previous</span>
        <?php endif; ?>
        <?php if ($next_url !== '') : ?>
            <a href="<?php echo metis_escape_url($next_url); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">Next</a>
        <?php else : ?>
            <span class="metis-btn metis-btn-xs metis-btn-ghost" style="pointer-events:none;opacity:.5;">Next</span>
        <?php endif; ?>
    </div>

        <?php },
    ]); ?>
</div>

<?php if ($can_manage) : ?>
<div id="metis-people-add-modal" class="metis-modal-backdrop" aria-hidden="true">
    <div class="metis-modal metis-people-modal-inner">
        <h3 class="metis-modal-title">Add Person</h3>
        <form id="metis-people-add-form" class="metis-form-grid">
            <div class="metis-field metis-field-half">
                <label for="metis-people-add-first-name">First Name</label>
                <input id="metis-people-add-first-name" class="metis-input" type="text" required>
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-people-add-last-name">Last Name</label>
                <input id="metis-people-add-last-name" class="metis-input" type="text" required>
            </div>
            <div class="metis-field metis-field-full">
                <label for="metis-people-add-email">Email</label>
                <input id="metis-people-add-email" class="metis-input" type="email" required>
            </div>
            <div class="metis-form-actions">
                <button type="button" id="metis-people-add-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="submit" class="metis-btn">Create Person</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
