<?php if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_people_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view People roles.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$can_manage = metis_people_can_manage();
$is_new = isset( metis_request_get()['new'] ) && (string) metis_runtime_unslash( metis_request_get()['new'] ) === '1';
$role_key_param = isset( metis_request_get()['role'] ) ? metis_key_clean( metis_runtime_unslash( metis_request_get()['role'] ) ) : '';
$role_domain_param = isset( metis_request_get()['domain'] ) ? metis_key_clean( metis_runtime_unslash( metis_request_get()['domain'] ) ) : 'metis';
if ( ! in_array( $role_domain_param, [ 'metis', 'stripe', 'workspace' ], true ) ) {
    $role_domain_param = 'metis';
}

$snapshot = \Metis\Modules\People\ReadService::roleDetailSnapshot( $role_key_param, $role_domain_param, $is_new );
$role = $snapshot['role'] ?? null;
if ( ! $is_new && $role_key_param !== '' ) {
    if ( ! $role ) {
        echo '<div class="metis-alert metis-alert-error">Role not found.</div>';
        return;
    }
    metis_set_page_title( $role['role_name'] ?? $role_key_param );
} elseif ( $is_new ) {
    metis_set_page_title( 'New Role' );
}

$permissions_by_module = $snapshot['permissions_by_module'] ?? [];
$selected_permission_keys = $snapshot['selected_permission_keys'] ?? [];
$assigned_people = (int) ( $snapshot['assigned_people'] ?? 0 );
$assigned_people_rows = $snapshot['assigned_people_rows'] ?? [];
?>

<div class="metis-people-role-detail" data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>">
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-people-detail-header">
        <div>
            <h1 class="metis-page-title"><?php echo metis_escape_html( $is_new ? 'Add Role' : (string) ( $role['role_name'] ?? 'Role' ) ); ?></h1>
            <p class="metis-subtitle"><?php echo metis_escape_html( $is_new ? 'Define permissions for a new Metis role.' : 'Role Key: ' . (string) ( $role['role_key'] ?? '' ) ); ?></p>
        </div>
        <div class="metis-top-actions">
            <a href="<?php echo metis_escape_url( metis_people_roles_list_url() ); ?>" class="metis-btn metis-btn-ghost metis-top-action-btn">Back to Roles</a>
        </div>
    </div>

    <div class="metis-people-role-grid">
        <section class="metis-premium-wrap">
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
                            <span class="metis-muted"><?php echo metis_escape_html( (string) ( $member['pid'] ?? '' ) ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="metis-muted" style="margin-top:10px;">No people currently assigned.</p>
            <?php endif; ?>
        </section>

        <section class="metis-premium-wrap">
            <h3 class="metis-people-section-title">Role Permissions</h3>
            <form id="metis-role-detail-form" class="metis-form-grid">
                <input id="metis-role-id" type="hidden" value="<?php echo metis_escape_attr( (string) ( $role['id'] ?? 0 ) ); ?>">
                <input id="metis-role-domain" type="hidden" value="<?php echo metis_escape_attr( (string) ( $role['role_domain'] ?? 'metis' ) ); ?>">

                <div class="metis-field metis-field-half">
                    <label for="metis-role-key">Role Key</label>
                    <input id="metis-role-key" class="metis-input" type="text" value="<?php echo metis_escape_attr( (string) ( $role['role_key'] ?? '' ) ); ?>" <?php disabled( ! $can_manage || ! empty( $role['is_system'] ) ); ?> required>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-role-name">Role Name</label>
                    <input id="metis-role-name" class="metis-input" type="text" value="<?php echo metis_escape_attr( (string) ( $role['role_name'] ?? '' ) ); ?>" <?php disabled( ! $can_manage ); ?> required>
                </div>

                <div class="metis-field metis-field-full">
                    <label for="metis-role-description">Description</label>
                    <input id="metis-role-description" class="metis-input" type="text" value="<?php echo metis_escape_attr( (string) ( $role['description'] ?? '' ) ); ?>" <?php disabled( ! $can_manage ); ?>>
                </div>

                <div class="metis-field metis-field-full">
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
                    <div class="metis-form-actions">
                        <a href="<?php echo metis_escape_url( metis_people_base_url() ); ?>" class="metis-btn metis-btn-ghost">Cancel</a>
                        <button type="submit" class="metis-btn">Save Role</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>
    </div>
</div>
