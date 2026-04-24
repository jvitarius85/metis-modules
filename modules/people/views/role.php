<?php if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_people_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view People roles.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$db = metis_db();

$roles_table = Metis_Tables::get( 'people_roles' );
$perms_table = Metis_Tables::get( 'people_permissions' );
$role_perms_table = Metis_Tables::get( 'people_role_perms' );
$user_roles_table = Metis_Tables::get( 'people_user_roles' );

$can_manage = metis_people_can_manage();
$is_new = isset( $_GET['new'] ) && (string) metis_runtime_unslash( $_GET['new'] ) === '1';
$role_key_param = isset( $_GET['role'] ) ? metis_key_clean( metis_runtime_unslash( $_GET['role'] ) ) : '';
$role_domain_param = isset( $_GET['domain'] ) ? metis_key_clean( metis_runtime_unslash( $_GET['domain'] ) ) : 'metis';
if ( ! in_array( $role_domain_param, [ 'metis', 'stripe', 'workspace' ], true ) ) {
    $role_domain_param = 'metis';
}

$role = null;
if ( ! $is_new && $role_key_param !== '' ) {
    $role = $db->fetchOne( "SELECT * FROM {$roles_table} WHERE role_key = %s AND role_domain = %s LIMIT 1", [ $role_key_param, $role_domain_param ] );
    if ( ! $role ) {
        echo '<div class="mw-alert mw-alert-error">Role not found.</div>';
        return;
    }
    metis_set_page_title( $role['role_name'] ?? $role_key_param );
} elseif ( $is_new ) {
    metis_set_page_title( 'New Role' );
}

$permissions_rows = $db->fetchAll( "SELECT * FROM {$perms_table} ORDER BY module_slug ASC, action_key ASC" ) ?: [];
$permissions_by_module = [];
foreach ( $permissions_rows as $perm ) {
    $module_slug = (string) ( $perm['module_slug'] ?? '' );
    if ( ! isset( $permissions_by_module[ $module_slug ] ) ) {
        $permissions_by_module[ $module_slug ] = [];
    }
    $permissions_by_module[ $module_slug ][] = $perm;
}

$selected_permission_keys = [];
$assigned_people = 0;
$assigned_people_rows = [];
if ( $role ) {
    $selected_permission_keys = $db->column(
        "SELECT p.permission_key
         FROM {$role_perms_table} rp
         INNER JOIN {$perms_table} p ON p.id = rp.permission_id
         WHERE rp.role_id = %d AND rp.allow_access = 1",
        [ (int) $role['id'] ]
    ) ?: [];

    $assigned_people = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$user_roles_table} WHERE role_id = %d",
        [ (int) $role['id'] ]
    );

    $people_table = Metis_Tables::get( 'people' );
    $assigned_people_rows = $db->fetchAll(
        "SELECT p.pid, p.first_name, p.last_name, p.display_name, p.email
         FROM {$user_roles_table} ur
         INNER JOIN {$people_table} p ON p.id = ur.person_id
         WHERE ur.role_id = %d
         ORDER BY p.display_name ASC, p.email ASC",
        [ (int) $role['id'] ]
    ) ?: [];
}
?>

<div class="metis-people-role-detail" data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>">
    <div id="metis-people-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-people-detail-header">
        <div>
            <h1 class="mw-page-title"><?php echo metis_escape_html( $is_new ? 'Add Role' : (string) ( $role['role_name'] ?? 'Role' ) ); ?></h1>
            <p class="mw-subtitle"><?php echo metis_escape_html( $is_new ? 'Define permissions for a new Metis role.' : 'Role Key: ' . (string) ( $role['role_key'] ?? '' ) ); ?></p>
        </div>
        <div class="metis-top-actions">
            <a href="<?php echo metis_escape_url( metis_people_roles_list_url() ); ?>" class="mw-btn mw-btn-ghost metis-top-action-btn">Back to Roles</a>
        </div>
    </div>

    <div class="metis-people-role-grid">
        <section class="mw-premium-wrap">
            <h3 class="metis-people-section-title">Role Summary</h3>
            <div class="metis-role-summary-stats">
                <div class="metis-role-summary-stat">
                    <div class="metis-role-summary-stat-label">Assigned People</div>
                    <div class="metis-role-summary-stat-value"><?php echo metis_escape_html( (string) $assigned_people ); ?></div>
                </div>
                <div class="metis-role-summary-stat">
                    <div class="metis-role-summary-stat-label">Permissions</div>
                    <div class="metis-role-summary-stat-value"><?php echo metis_escape_html( (string) count( $selected_permission_keys ) ); ?></div>
                </div>
                <div class="metis-role-summary-stat">
                    <div class="metis-role-summary-stat-label">Domain</div>
                    <div class="metis-role-summary-stat-value" style="font-size:16px;padding-top:4px;"><?php echo metis_escape_html( ucfirst( (string) ( $role['role_domain'] ?? 'metis' ) ) ); ?></div>
                </div>
                <div class="metis-role-summary-stat">
                    <div class="metis-role-summary-stat-label">System Role</div>
                    <div class="metis-role-summary-stat-value" style="font-size:16px;padding-top:4px;"><?php echo ! empty( $role['is_system'] ) ? 'Yes' : 'No'; ?></div>
                </div>
            </div>
            <?php if ( ! empty( $assigned_people_rows ) ) : ?>
                <div class="metis-people-section-subtitle">Members</div>
                <div class="metis-role-members-list">
                    <?php foreach ( $assigned_people_rows as $member ) :
                        $member_name = trim( (string) ( $member['first_name'] ?? '' ) . ' ' . (string) ( $member['last_name'] ?? '' ) );
                        if ( $member_name === '' ) $member_name = (string) ( $member['display_name'] ?? '' );
                    ?>
                        <a class="metis-role-member-chip" href="<?php echo metis_escape_url( metis_people_person_url( (string) ( $member['pid'] ?? '' ) ) ); ?>">
                            <?php echo metis_escape_html( $member_name !== '' ? $member_name : (string) ( $member['email'] ?? 'Person' ) ); ?>
                            <span class="mw-muted"><?php echo metis_escape_html( (string) ( $member['pid'] ?? '' ) ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="mw-muted" style="margin-top:10px;">No people currently assigned.</p>
            <?php endif; ?>
        </section>

        <section class="mw-premium-wrap">
            <h3 class="metis-people-section-title">Role Permissions</h3>
            <form id="metis-role-detail-form" class="metis-contact-form">
                <input id="metis-role-id" type="hidden" value="<?php echo metis_escape_attr( (string) ( $role['id'] ?? 0 ) ); ?>">
                <input id="metis-role-domain" type="hidden" value="<?php echo metis_escape_attr( (string) ( $role['role_domain'] ?? 'metis' ) ); ?>">

                <div class="metis-contact-field metis-contact-field-half">
                    <label for="metis-role-key">Role Key</label>
                    <input id="metis-role-key" class="mw-input" type="text" value="<?php echo metis_escape_attr( (string) ( $role['role_key'] ?? '' ) ); ?>" <?php disabled( ! $can_manage || ! empty( $role['is_system'] ) ); ?> required>
                </div>

                <div class="metis-contact-field metis-contact-field-half">
                    <label for="metis-role-name">Role Name</label>
                    <input id="metis-role-name" class="mw-input" type="text" value="<?php echo metis_escape_attr( (string) ( $role['role_name'] ?? '' ) ); ?>" <?php disabled( ! $can_manage ); ?> required>
                </div>

                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-role-description">Description</label>
                    <input id="metis-role-description" class="mw-input" type="text" value="<?php echo metis_escape_attr( (string) ( $role['description'] ?? '' ) ); ?>" <?php disabled( ! $can_manage ); ?>>
                </div>

                <div class="metis-contact-field metis-contact-field-full">
                    <label>Permissions</label>
                    <div id="metis-role-permissions" class="metis-people-perm-groups">
                        <?php foreach ( $permissions_by_module as $module_slug => $perms ) : ?>
                            <div class="metis-perm-group">
                                <div class="metis-perm-group-title"><?php echo metis_escape_html( (string) $module_slug ); ?></div>
                                <div class="metis-people-check-grid">
                                    <?php foreach ( $perms as $perm ) :
                                        $perm_key = (string) ( $perm['permission_key'] ?? '' );
                                    ?>
                                        <label class="metis-people-check">
                                            <input type="checkbox" class="metis-role-perm-toggle" value="<?php echo metis_escape_attr( $perm_key ); ?>" <?php metis_attr_checked( in_array( $perm_key, $selected_permission_keys, true ) ); ?> <?php disabled( ! $can_manage ); ?>>
                                            <?php echo metis_escape_html( (string) ( $perm['permission_name'] ?? $perm_key ) ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ( $can_manage ) : ?>
                    <div class="metis-contact-actions">
                        <a href="<?php echo metis_escape_url( metis_people_base_url() ); ?>" class="mw-btn mw-btn-ghost">Cancel</a>
                        <button type="submit" class="mw-btn">Save Role</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>
    </div>
</div>
