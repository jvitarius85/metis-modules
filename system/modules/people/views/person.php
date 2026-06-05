<?php if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_people_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view People.</div>';
    return;
}

metis_people_ensure_schema();
metis_people_seed_permissions_and_roles();

$can_manage = metis_people_can_manage();
$is_new = isset( metis_request_get()['new'] ) && (string) metis_runtime_unslash( metis_request_get()['new'] ) === '1';
$pid = isset( metis_request_get()['pid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_get()['pid'] ) ) : '';

$snapshot = \Metis\Modules\People\ReadService::personSnapshot( $pid, $is_new );
$person = $snapshot['person'] ?? null;
if ( ! $is_new && $pid !== '' ) {
    if ( ! $person ) {
        echo '<div class="metis-alert metis-alert-error">Person not found.</div>';
        return;
    }
    $person_full_name = trim( ( $person['first_name'] ?? '' ) . ' ' . ( $person['last_name'] ?? '' ) );
    metis_set_page_title( $person_full_name ?: ( $person['email'] ?? $pid ) );
} elseif ( $is_new ) {
    metis_set_page_title( 'New Person' );
}
$roles_rows = $snapshot['roles_rows'] ?? [];
$metis_roles = $snapshot['metis_roles'] ?? [];
$stripe_roles = $snapshot['stripe_roles'] ?? [ '' => 'No Stripe Access' ];
$stripe_role_descriptions = $snapshot['stripe_role_descriptions'] ?? [ '' => 'No Stripe dashboard access from this profile.' ];
$current_stripe_role = (string) ( $snapshot['current_stripe_role'] ?? ( $person['stripe_role'] ?? '' ) );
$workspace_roles = $snapshot['workspace_roles'] ?? [ '' => 'No Workspace Role' ];
$current_workspace_role = (string) ( $snapshot['current_workspace_role'] ?? ( $person['workspace_role'] ?? '' ) );
$selected_role_keys = $snapshot['selected_role_keys'] ?? [];
$role_windows_by_key = $snapshot['role_windows_by_key'] ?? [];
$person_email = (string) ( $snapshot['person_email'] ?? '' );
$first_name = (string) ( $snapshot['first_name'] ?? '' );
$last_name = (string) ( $snapshot['last_name'] ?? '' );
$full_name = (string) ( $snapshot['full_name'] ?? '' );
$display_name = (string) ( $snapshot['display_name'] ?? '' );
$avatar_src = (string) ( $snapshot['avatar_src'] ?? '' );
$linked_donor_id = (string) ( $snapshot['linked_donor_id'] ?? '' );
$donor_profile_url = (string) ( $snapshot['donor_profile_url'] ?? '' );
$linked_donor_name = (string) ( $snapshot['linked_donor_name'] ?? '' );
$lifecycle_status = (string) ( $person['lifecycle_status'] ?? 'active' );
$manager_pid = (string) ( $person['manager_pid'] ?? '' );
$department = (string) ( $person['department'] ?? '' );
$board_position = (string) ( $person['board_position'] ?? '' );
$staff_position = (string) ( $person['staff_position'] ?? '' );
$volunteer_position = (string) ( $person['volunteer_position'] ?? '' );
$board_term_start = (string) ( $person['board_term_start'] ?? '' );
$board_term_end = (string) ( $person['board_term_end'] ?? '' );
$volunteer_area = (string) ( $person['volunteer_area'] ?? '' );
$position_options = $snapshot['position_options'] ?? [ 'board' => [], 'staff' => [], 'volunteer' => [] ];
$email_notifications = ! empty( $snapshot['email_notifications'] );
$requires_2fa = ! empty( $snapshot['requires_2fa'] );
$mfa_method = (string) ( $snapshot['mfa_method'] ?? 'none' );
$totp_enabled = ! empty( $snapshot['totp_enabled'] );
$passkey_enabled = ! empty( $snapshot['passkey_enabled'] );
$has_metis_password = ! empty( $snapshot['has_metis_password'] );
$notification_prefs = $snapshot['notification_prefs'] ?? [];
$notification_events = $snapshot['notification_events'] ?? [];
$effective_permissions = $snapshot['effective_permissions'] ?? [];
$person_activity_rows = $snapshot['person_activity_rows'] ?? [];
$person_request_rows = $snapshot['person_request_rows'] ?? [];
$person_document_rows = $snapshot['person_document_rows'] ?? [];
$person_emergency_rows = $snapshot['person_emergency_rows'] ?? [];
$person_passkey_rows = $snapshot['person_passkey_rows'] ?? [];
$person_lifecycle_tasks = $snapshot['person_lifecycle_tasks'] ?? [];
$permissions_catalog = $snapshot['permissions_catalog'] ?? [];
$workspace_linked_user_id = (int) ( $snapshot['workspace_linked_user_id'] ?? 0 );
$workspace_linked_email = (string) ( $snapshot['workspace_linked_email'] ?? '' );
$workspace_linked_suspended = ! empty( $snapshot['workspace_linked_suspended'] );
$workspace_linked_protected = ! empty( $snapshot['workspace_linked_protected'] );
$workspace_linked_roles = $snapshot['workspace_linked_roles'] ?? [];
$workspace_linked_groups = $snapshot['workspace_linked_groups'] ?? [];
$workspace_role_name_by_key = $snapshot['workspace_role_name_by_key'] ?? [];
$workspace_role_description_by_key = $snapshot['workspace_role_description_by_key'] ?? [];
$workspace_group_options = $snapshot['workspace_group_options'] ?? [];
$drive_folder_id = (string) ( $snapshot['drive_folder_id'] ?? '' );
$drive_folder_name = (string) ( $snapshot['drive_folder_name'] ?? '' );
$drive_folder_url = (string) ( $snapshot['drive_folder_url'] ?? '' );
$can_attach_drive_folder = ! empty( $snapshot['can_attach_drive_folder'] ) && $can_manage;
$drive_shared_id = (string) ( $snapshot['drive_shared_id'] ?? '' );
$drive_users_root_id = (string) ( $snapshot['drive_users_root_id'] ?? '' );
$drive_users_root_name = (string) ( $snapshot['drive_users_root_name'] ?? 'Users' );
if ( ! function_exists( 'metis_people_workspace_label_from_key' ) ) {
    function metis_people_workspace_label_from_key( string $role_key, string $description = '' ): string {
        $label = trim( $role_key );
        if ( $label === '' ) return '';
        $description_norm = strtolower( trim( $description ) );
        if ( $label === '_SEED_ADMIN_ROLE' || $label === '_seed_admin_role' || strpos( $description_norm, 'administrator seed role' ) !== false ) {
            return 'Super Admin';
        }
        $label = preg_replace( '/^_+/', '', $label );
        $label = str_replace( '_', ' ', strtolower( $label ) );
        $label = ucwords( trim( $label ) );
        return $label !== '' ? $label : $role_key;
    }
}
?>

<div class="metis-people-detail" data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>">
    <div id="metis-people-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-people-detail-header">
        <div>
            <h1 class="metis-page-title"><?php echo metis_escape_html( $is_new ? 'Add Person' : ( $full_name !== '' ? $full_name : $display_name ) ); ?></h1>
            <p class="metis-subtitle"><?php echo metis_escape_html( $is_new ? 'Create a new Metis person profile and assign access.' : 'PID: ' . (string) ( $person['pid'] ?? '' ) ); ?></p>
        </div>
        <div class="metis-top-actions">
            <a href="<?php echo metis_escape_url( metis_people_people_list_url() ); ?>" class="metis-btn metis-btn-ghost">Back to People</a>
        </div>
    </div>

    <div class="metis-people-person-grid">

        <!-- Col 1: Profile card -->
        <div class="metis-people-profile-card">
            <div class="metis-people-profile-avatar-wrap">
                <img class="metis-people-profile-avatar" src="<?php echo metis_escape_url( $avatar_src ?: '' ); ?>" alt="Profile photo">
            </div>
            <?php if ( $can_manage && ! $is_new && ! empty( $person['id'] ) ) : ?>
                <button type="button" class="metis-btn-xs" id="metis-people-avatar-edit-open">Change Photo</button>
            <?php endif; ?>
            <h3><?php echo metis_escape_html( $full_name !== '' ? $full_name : ( $display_name !== '' ? $display_name : 'New Person' ) ); ?></h3>

            <div class="metis-people-profile-meta">
                <?php if ( $person_email !== '' ) : ?>
                    <div class="metis-people-profile-meta-row"><?php echo metis_escape_html( $person_email ); ?></div>
                <?php endif; ?>
                <?php if ( ! empty( $person['pid'] ) ) : ?>
                    <div class="metis-people-profile-meta-row">
                        <span class="metis-people-profile-meta-label">PID</span>
                        <span><?php echo metis_escape_html( (string) $person['pid'] ); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ( $linked_donor_id !== '' ) : ?>
                    <div class="metis-people-profile-meta-row">
                        <span class="metis-people-profile-meta-label">Donor</span>
                        <span><?php echo metis_escape_html( $linked_donor_id ); ?></span>
                    </div>
                <?php endif; ?>
                <div class="metis-people-profile-meta-row">
                    <span class="metis-people-profile-meta-label">Auth</span>
                    <span><?php echo metis_escape_html( ucfirst( (string) ( $person['auth_provider'] ?? 'metis' ) ) ); ?></span>
                </div>
            </div>

            <?php
            $profile_tags = [];
            if ( ! empty( $person['is_staff'] ) )     $profile_tags[] = ['Staff',        'is-staff'];
            if ( ! empty( $person['is_board'] ) )     $profile_tags[] = ['Board Member', 'is-board'];
            if ( ! empty( $person['is_volunteer'] ) ) $profile_tags[] = ['Volunteer',    'is-volunteer'];
            if ( ! empty( $profile_tags ) ) : ?>
                <div class="metis-people-profile-tags">
                    <?php foreach ( $profile_tags as [$tag_label, $tag_class] ) : ?>
                        <span class="metis-people-profile-tag <?php echo metis_escape_attr( $tag_class ); ?>"><?php echo metis_escape_html( $tag_label ); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <hr class="metis-people-profile-divider">

            <div class="metis-people-profile-actions">
                <?php if ( $linked_donor_id !== '' ) : ?>
                    <a class="metis-btn metis-btn-ghost" style="font-size:13px;padding:6px 10px;" href="<?php echo metis_escape_url( $donor_profile_url ); ?>">Open Donor Profile</a>
                <?php endif; ?>
                <?php if ( $can_manage && ! $is_new && ! empty( $person['pid'] ) ) : ?>
                    <button type="button" class="metis-btn metis-btn-danger" style="font-size:13px;padding:6px 10px;" id="metis-people-offboard-btn" data-pid="<?php echo metis_escape_attr( (string) $person['pid'] ); ?>">Run Offboarding</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Col 2: Vertical tab sidebar -->
        <nav class="metis-people-tab-sidebar" data-tabs-root aria-label="Person sections">
            <button type="button" class="metis-people-tab-nav-btn is-active" data-tab-target="profile">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="7" r="3.5"/><path d="M3 17c0-3.3 3.1-6 7-6s7 2.7 7 6" stroke-linecap="round"/></svg>
                Profile
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="access">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="9" width="14" height="9" rx="2"/><path d="M7 9V6a3 3 0 0 1 6 0v3" stroke-linecap="round"/></svg>
                Access
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="workspace">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2" y="4" width="16" height="12" rx="2"/><path d="M6 4v12M14 4v12M2 10h16" stroke-linecap="round"/></svg>
                Workspace
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="security">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 2l7 3v5c0 4-3 7-7 8C7 17 4 14 3 10V5l7-3z" stroke-linejoin="round"/></svg>
                Security
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="notifications">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 2a6 6 0 0 1 6 6v3l1.5 2.5H2.5L4 11V8a6 6 0 0 1 6-6z" stroke-linejoin="round"/><path d="M8 16a2 2 0 0 0 4 0" stroke-linecap="round"/></svg>
                Notifications
            </button>
            <hr class="metis-tab-nav-divider">
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="lifecycle">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="7.5"/><path d="M10 6v4l2.5 2.5" stroke-linecap="round"/></svg>
                Lifecycle
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="activity">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 10h3l2-7 3 14 2-7h6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Activity
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="requests">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 4h12a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H8l-4 3V5a1 1 0 0 1 1-1z" stroke-linejoin="round"/></svg>
                Requests
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="documents">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 2h7l4 4v12H5V2z" stroke-linejoin="round"/><path d="M12 2v4h4" stroke-linecap="round"/><path d="M7 9h6M7 12h4" stroke-linecap="round"/></svg>
                Documents
            </button>
            <button type="button" class="metis-people-tab-nav-btn" data-tab-target="emergency">
                <svg class="metis-tab-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 2l1.8 5.6H18l-4.9 3.5 1.9 5.7L10 13.3 5 16.8l1.9-5.7L2 7.6h6.2z" stroke-linejoin="round"/></svg>
                Emergency
            </button>
        </nav>

        <!-- Col 3: Tab content -->
        <div class="metis-people-tab-content">
            <div class="metis-people-tabs" data-tabs-content>

                <div class="metis-tab-panel is-active" data-tab-panel="profile">
            <h3 class="metis-people-section-title">Person Profile</h3>
            <form id="metis-people-detail-form" class="metis-form-grid">
                <input id="metis-people-id" type="hidden" value="<?php echo metis_escape_attr( (string) ( $person['id'] ?? 0 ) ); ?>">
                <input id="metis-people-pid" type="hidden" value="<?php echo metis_escape_attr( (string) ( $person['pid'] ?? '' ) ); ?>">
                <div class="metis-field metis-field-full">
                    <h4 class="metis-people-form-subtitle">General Info</h4>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-first-name">First Name</label>
                    <input id="metis-people-first-name" class="metis-input" type="text" value="<?php echo metis_escape_attr( $first_name ); ?>" <?php disabled( ! $can_manage ); ?>>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-last-name">Last Name</label>
                    <input id="metis-people-last-name" class="metis-input" type="text" value="<?php echo metis_escape_attr( $last_name ); ?>" <?php disabled( ! $can_manage ); ?>>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-name">Display Name</label>
                    <input id="metis-people-name" class="metis-input" type="text" value="<?php echo metis_escape_attr( $display_name ); ?>" <?php disabled( ! $can_manage ); ?> required>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-email">Email</label>
                    <input id="metis-people-email" class="metis-input" type="email" value="<?php echo metis_escape_attr( $person_email ); ?>" <?php disabled( ! $can_manage ); ?> required>
                </div>

                <div class="metis-field metis-field-full">
                    <h4 class="metis-people-form-subtitle">Login</h4>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-provider">Auth Provider</label>
                    <select id="metis-people-provider" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                        <option value="workspace" <?php metis_attr_selected( (string) ( $person['auth_provider'] ?? 'metis' ), 'workspace' ); ?>>Google Workspace SSO</option>
                        <option value="metis" <?php metis_attr_selected( (string) ( $person['auth_provider'] ?? 'metis' ), 'metis' ); ?>>Metis Native</option>
                    </select>
                </div>

                <div class="metis-field metis-field-full">
                    <h4 class="metis-people-form-subtitle">Links</h4>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-linked-donor-id">Linked Donor ID</label>
                    <input id="metis-people-linked-donor-id" class="metis-input" type="text" value="<?php echo metis_escape_attr( $linked_donor_id ); ?>" placeholder="Search donor by name, email, or ID" list="metis-people-donor-list" autocomplete="off" <?php disabled( ! $can_manage ); ?>>
                    <datalist id="metis-people-donor-list"></datalist>
                    <div id="metis-people-linked-donor-name" class="metis-muted"><?php echo metis_escape_html( $linked_donor_name ); ?></div>
                </div>

                <div class="metis-field metis-field-full">
                    <h4 class="metis-people-form-subtitle">Position and Status</h4>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-status">Status</label>
                    <select id="metis-people-status" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                        <option value="active" <?php metis_attr_selected( (string) ( $person['status'] ?? 'active' ), 'active' ); ?>>Active</option>
                        <option value="inactive" <?php metis_attr_selected( (string) ( $person['status'] ?? 'active' ), 'inactive' ); ?>>Inactive</option>
                    </select>
                </div>

                <div class="metis-field metis-field-half">
                    <label for="metis-people-lifecycle-status">Lifecycle Status</label>
                    <select id="metis-people-lifecycle-status" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                        <option value="candidate" <?php metis_attr_selected( $lifecycle_status, 'candidate' ); ?>>Candidate</option>
                        <option value="active" <?php metis_attr_selected( $lifecycle_status, 'active' ); ?>>Active</option>
                        <option value="leave" <?php metis_attr_selected( $lifecycle_status, 'leave' ); ?>>On Leave</option>
                        <option value="alumni" <?php metis_attr_selected( $lifecycle_status, 'alumni' ); ?>>Alumni</option>
                    </select>
                </div>

                <div class="metis-field metis-field-full">
                    <details>
                        <summary><strong>Show Organizational Fields</strong></summary>
                        <div class="metis-form-grid" style="margin-top:10px;">
                            <div class="metis-field metis-field-half">
                                <label for="metis-people-manager-pid">Manager PID</label>
                                <input id="metis-people-manager-pid" class="metis-input" type="text" value="<?php echo metis_escape_attr( $manager_pid ); ?>" <?php disabled( ! $can_manage ); ?>>
                            </div>

                            <div class="metis-field metis-field-half">
                                <label for="metis-people-department">Department</label>
                                <input id="metis-people-department" class="metis-input" type="text" value="<?php echo metis_escape_attr( $department ); ?>" <?php disabled( ! $can_manage ); ?>>
                            </div>

                            <div class="metis-field metis-field-half">
                                <label for="metis-people-board-term-start">Board Term Start</label>
                                <input id="metis-people-board-term-start" class="metis-input" type="date" value="<?php echo metis_escape_attr( $board_term_start ); ?>" <?php disabled( ! $can_manage ); ?>>
                            </div>

                            <div class="metis-field metis-field-half">
                                <label for="metis-people-board-term-end">Board Term End</label>
                                <input id="metis-people-board-term-end" class="metis-input" type="date" value="<?php echo metis_escape_attr( $board_term_end ); ?>" <?php disabled( ! $can_manage ); ?>>
                            </div>

                            <div class="metis-field metis-field-half">
                                <label for="metis-people-volunteer-area">Volunteer Area</label>
                                <input id="metis-people-volunteer-area" class="metis-input" type="text" value="<?php echo metis_escape_attr( $volunteer_area ); ?>" <?php disabled( ! $can_manage ); ?>>
                            </div>
                        </div>
                    </details>
                </div>

                <div class="metis-field metis-field-full">
                    <h4 class="metis-people-form-subtitle">People Tags</h4>
                    <div class="metis-people-check-grid">
                        <label class="metis-people-check"><input type="checkbox" id="metis-people-staff" <?php metis_attr_checked( ! empty( $person['is_staff'] ) ); ?> <?php disabled( ! $can_manage ); ?>> Staff</label>
                        <label class="metis-people-check"><input type="checkbox" id="metis-people-board" <?php metis_attr_checked( ! empty( $person['is_board'] ) ); ?> <?php disabled( ! $can_manage ); ?>> Board Member</label>
                        <label class="metis-people-check"><input type="checkbox" id="metis-people-volunteer" <?php metis_attr_checked( ! empty( $person['is_volunteer'] ) ); ?> <?php disabled( ! $can_manage ); ?>> Volunteer</label>
                    </div>
                </div>

                <div class="metis-field metis-field-half" id="metis-people-board-position-wrap" <?php if ( empty( $person['is_board'] ) ) : ?>hidden<?php endif; ?>>
                    <label for="metis-people-board-position">Board Position</label>
                    <select id="metis-people-board-position" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                        <option value="">Select board position</option>
                        <?php foreach ( $position_options['board'] as $position_option ) : ?>
                            <?php $label = (string) ( $position_option['label'] ?? '' ); ?>
                            <option value="<?php echo metis_escape_attr( $label ); ?>" <?php metis_attr_selected( $board_position, $label ); ?>><?php echo metis_escape_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="metis-field metis-field-half" id="metis-people-staff-position-wrap" <?php if ( empty( $person['is_staff'] ) ) : ?>hidden<?php endif; ?>>
                    <label for="metis-people-staff-position">Staff Position</label>
                    <select id="metis-people-staff-position" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                        <option value="">Select staff position</option>
                        <?php foreach ( $position_options['staff'] as $position_option ) : ?>
                            <?php $label = (string) ( $position_option['label'] ?? '' ); ?>
                            <option value="<?php echo metis_escape_attr( $label ); ?>" <?php metis_attr_selected( $staff_position, $label ); ?>><?php echo metis_escape_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="metis-field metis-field-half" id="metis-people-volunteer-position-wrap" <?php if ( empty( $person['is_volunteer'] ) ) : ?>hidden<?php endif; ?>>
                    <label for="metis-people-volunteer-position">Volunteer Position</label>
                    <select id="metis-people-volunteer-position" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                        <option value="">Select volunteer position</option>
                        <?php foreach ( $position_options['volunteer'] as $position_option ) : ?>
                            <?php $label = (string) ( $position_option['label'] ?? '' ); ?>
                            <option value="<?php echo metis_escape_attr( $label ); ?>" <?php metis_attr_selected( $volunteer_position, $label ); ?>><?php echo metis_escape_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ( $can_manage ) : ?>
                    <div class="metis-form-actions">
                        <a href="<?php echo metis_escape_url( metis_people_people_list_url() ); ?>" class="metis-btn metis-btn-ghost">Cancel</a>
                        <button type="submit" class="metis-btn">Save Person</button>
                    </div>
                <?php endif; ?>
            </form>
                </div>

                <div class="metis-tab-panel" data-tab-panel="access">
                    <h3 class="metis-people-section-title">Roles and Permissions</h3>
                    <div class="metis-people-access-shell">
                        <div class="metis-people-access-block">
                            <label>Metis Roles</label>
                            <div class="metis-people-role-head">
                                <span>Role</span>
                                <span>Window</span>
                                <span>Starts</span>
                                <span>Ends</span>
                            </div>
                            <div class="metis-people-role-grid" id="metis-people-role-checkboxes">
                                <?php foreach ( $metis_roles as $r ) : ?>
                                    <?php $role_key = (string) ( $r['role_key'] ?? '' ); ?>
                                    <?php
                                    $w = $role_windows_by_key[ $role_key ] ?? [ 'start_at' => '', 'end_at' => '' ];
                                    $start_val = str_replace( ' ', 'T', substr( (string) $w['start_at'], 0, 16 ) );
                                    $end_val = str_replace( ' ', 'T', substr( (string) $w['end_at'], 0, 16 ) );
                                    ?>
                                    <div class="metis-people-role-row">
                                        <label class="metis-people-check">
                                            <input type="checkbox" class="metis-role-toggle" data-role-key="<?php echo metis_escape_attr( $role_key ); ?>" value="<?php echo metis_escape_attr( $role_key ); ?>" <?php metis_attr_checked( in_array( $role_key, $selected_role_keys, true ) ); ?> <?php disabled( ! $can_manage ); ?>>
                                            <span class="metis-role-label-stack">
                                                <span><?php echo metis_escape_html( (string) ( $r['role_name'] ?? $role_key ) ); ?></span>
                                                <span class="metis-role-subtitle"><?php echo metis_escape_html( ucfirst( (string) ( $r['role_domain'] ?? 'metis' ) ) ); ?> role</span>
                                            </span>
                                        </label>
                                        <select class="metis-select metis-role-window-preset" data-role-key="<?php echo metis_escape_attr( $role_key ); ?>" <?php disabled( ! $can_manage ); ?>>
                                            <option value="">Custom</option>
                                            <option value="always">Always</option>
                                            <option value="30d">30 days</option>
                                            <option value="90d">90 days</option>
                                        </select>
                                        <input type="datetime-local" class="metis-input metis-role-start" data-role-key="<?php echo metis_escape_attr( $role_key ); ?>" value="<?php echo metis_escape_attr( $start_val ); ?>" <?php disabled( ! $can_manage ); ?>>
                                        <input type="datetime-local" class="metis-input metis-role-end" data-role-key="<?php echo metis_escape_attr( $role_key ); ?>" value="<?php echo metis_escape_attr( $end_val ); ?>" <?php disabled( ! $can_manage ); ?>>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="metis-muted">Optional schedule window. Empty means always active.</div>
                        </div>

                        <div class="metis-people-access-block">
                            <label>Effective Permissions</label>
                            <div class="metis-people-check-grid">
                                <?php if ( ! empty( $effective_permissions ) ) : ?>
                                    <?php foreach ( $effective_permissions as $perm ) : ?>
                                        <span class="metis-chip"><?php echo metis_escape_html( (string) ( $perm['permission_key'] ?? '' ) ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="metis-muted">No active permissions found.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <details class="metis-people-access-block">
                            <summary><strong>Permission Simulator</strong></summary>
                            <div class="metis-form-grid" style="margin-top:10px;">
                                <div class="metis-field metis-field-half">
                                    <label for="metis-people-sim-module">Module</label>
                                    <select id="metis-people-sim-module" class="metis-select">
                                        <option value="">Select module</option>
                                        <?php
                                        $seen_modules = [];
                                        foreach ( $permissions_catalog as $perm_row ) :
                                            $module_slug = (string) ( $perm_row['module_slug'] ?? '' );
                                            if ( $module_slug === '' || isset( $seen_modules[ $module_slug ] ) ) continue;
                                            $seen_modules[ $module_slug ] = true;
                                        ?>
                                            <option value="<?php echo metis_escape_attr( $module_slug ); ?>"><?php echo metis_escape_html( ucfirst( $module_slug ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="metis-field metis-field-half">
                                    <label for="metis-people-sim-action">Action</label>
                                    <select id="metis-people-sim-action" class="metis-select">
                                        <option value="view">View</option>
                                        <option value="edit">Edit</option>
                                        <option value="create">Create</option>
                                        <option value="delete">Delete</option>
                                    </select>
                                </div>
                                <div class="metis-field metis-field-full">
                                    <button type="button" class="metis-btn-xs" id="metis-people-sim-run">Run Simulation</button>
                                    <span id="metis-people-sim-result" class="metis-muted"></span>
                                </div>
                            </div>
                        </details>

                        <?php if ( $can_manage ) : ?>
                            <div class="metis-form-actions">
                                <button type="button" class="metis-btn" id="metis-people-save-access">Save Access</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="metis-tab-panel" data-tab-panel="workspace">
                    <h3 class="metis-people-section-title">Workspace and Stripe</h3>
                    <div class="metis-form-grid">
                        <input type="hidden" id="metis-people-workspace-email-tab" value="<?php echo metis_escape_attr( (string) ( $person['workspace_email'] ?: $workspace_linked_email ?: '' ) ); ?>">
                        <input type="hidden" id="metis-people-workspace-role-tab" value="<?php echo metis_escape_attr( $current_workspace_role ); ?>">
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-people-workspace-user-tab" <?php metis_attr_checked( ! empty( $person['is_workspace_user'] ) ); ?> <?php disabled( ! $can_manage ); ?>> Google Workspace User</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-people-workspace-protected-tab" <?php metis_attr_checked( $workspace_linked_protected ); ?> <?php disabled( ! $can_manage ); ?>> Protected (non-removable)</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-people-stripe-role-tab">Stripe Role (via Workspace SSO)</label>
                            <select id="metis-people-stripe-role-tab" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                                <?php foreach ( $stripe_roles as $value => $label ) : ?>
                                    <option value="<?php echo metis_escape_attr( (string) $value ); ?>" <?php metis_attr_selected( $current_stripe_role, (string) $value ); ?>><?php echo metis_escape_html( (string) $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="metis-people-stripe-role-help-tab" class="metis-muted"></div>
                        </div>
                        <div class="metis-field metis-field-full">
                            <label>Workspace Account</label>
                            <?php if ( $workspace_linked_user_id > 0 ) : ?>
                                <div class="metis-muted">
                                    Connected to: <?php echo metis_escape_html( $workspace_linked_email ); ?>
                                </div>
                                <div class="metis-chip-wrap">
                                    <span class="metis-chip">Connected</span>
                                    <?php if ( $workspace_linked_suspended ) : ?>
                                        <span class="metis-chip">Suspended</span>
                                    <?php endif; ?>
                                    <?php if ( $workspace_linked_protected ) : ?>
                                        <span class="metis-chip">Protected</span>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <div class="metis-muted">Not linked yet. Saving will create or link the Workspace user.</div>
                            <?php endif; ?>
                        </div>
                        <div class="metis-field metis-field-full">
                            <label>Drive User Folder</label>
                            <div class="metis-chip-wrap" id="metis-people-drive-folder-wrap">
                                <?php if ( $drive_folder_id !== '' ) : ?>
                                    <span class="metis-chip" id="metis-people-drive-folder-name"><?php echo metis_escape_html( $drive_folder_name !== '' ? $drive_folder_name : $drive_folder_id ); ?></span>
                                    <?php if ( $drive_folder_url !== '' ) : ?>
                                        <a id="metis-people-drive-folder-open" class="metis-btn-xs" href="<?php echo metis_escape_url( $drive_folder_url ); ?>">Open in Drive</a>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="metis-muted" id="metis-people-drive-folder-name">No user folder attached yet.</span>
                                <?php endif; ?>
                                <?php if ( $can_attach_drive_folder ) : ?>
                                    <button
                                        type="button"
                                        class="metis-btn-xs"
                                        id="metis-people-drive-folder-attach"
                                        data-shared-drive-id="<?php echo metis_escape_attr( $drive_shared_id ); ?>"
                                        data-users-root-id="<?php echo metis_escape_attr( $drive_users_root_id ); ?>"
                                        data-users-root-name="<?php echo metis_escape_attr( $drive_users_root_name ); ?>">
                                        <?php echo $drive_folder_id !== '' ? 'Select Folder' : 'Attach Folder'; ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="metis-field metis-field-full">
                            <details>
                                <summary><strong>Show Workspace Sync Details</strong></summary>
                                <div style="margin-top:10px;">
                                    <label>Workspace Roles (synced)</label>
                                    <div class="metis-chip-wrap">
                                        <?php if ( ! empty( $workspace_linked_roles ) ) : ?>
                                            <?php foreach ( $workspace_linked_roles as $workspace_role_key ) : ?>
                                                <?php
                                                $workspace_role_key = (string) $workspace_role_key;
                                                $workspace_role_label = (string) ( $workspace_role_name_by_key[ $workspace_role_key ] ?? '' );
                                                $workspace_role_description = (string) ( $workspace_role_description_by_key[ $workspace_role_key ] ?? '' );
                                                if ( $workspace_role_label === '' || $workspace_role_label === $workspace_role_key ) {
                                                    $workspace_role_label = metis_people_workspace_label_from_key( $workspace_role_key, $workspace_role_description );
                                                }
                                                ?>
                                                <span class="metis-chip"><?php echo metis_escape_html( $workspace_role_label ); ?></span>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <span class="metis-muted">No synced Workspace roles.</span>
                                        <?php endif; ?>
                                    </div>
                                    <label style="margin-top:10px;display:block;">Workspace Groups</label>
                                    <div class="metis-chip-wrap metis-people-workspace-group-toggle-wrap" id="metis-people-workspace-group-toggle-wrap" data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>">
                                        <?php foreach ( $workspace_group_options as $workspace_group_option ) : ?>
                                            <?php
                                            $workspace_group_email = strtolower( trim( (string) ( $workspace_group_option['group_email'] ?? '' ) ) );
                                            if ( $workspace_group_email === '' ) continue;
                                            $workspace_group_name = trim( (string) ( $workspace_group_option['group_name'] ?? '' ) );
                                            $workspace_group_label = $workspace_group_name !== '' ? $workspace_group_name : $workspace_group_email;
                                            $is_active_group = in_array( $workspace_group_email, array_map( 'strtolower', (array) $workspace_linked_groups ), true );
                                            ?>
                                            <button
                                                type="button"
                                                class="metis-chip metis-people-workspace-group-toggle<?php echo $is_active_group ? ' is-active' : ''; ?>"
                                                data-group-email="<?php echo metis_escape_attr( $workspace_group_email ); ?>"
                                                <?php disabled( ! $can_manage ); ?>>
                                                <span><?php echo metis_escape_html( $workspace_group_label ); ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </details>
                            <input type="hidden" id="metis-people-workspace-groups-json" value="<?php echo metis_escape_attr( metis_json_encode( array_values( array_map( 'strval', (array) $workspace_linked_groups ) ) ) ); ?>">
                        </div>
                        <?php if ( $can_manage ) : ?>
                            <div class="metis-form-actions">
                                <button type="button" class="metis-btn" id="metis-people-save-workspace">Save Workspace Settings</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="metis-tab-panel" data-tab-panel="security">
                    <h3 class="metis-people-section-title">Security</h3>
                    <div class="metis-form-grid">
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-people-requires-2fa" <?php metis_attr_checked( $requires_2fa ); ?> <?php disabled( ! $can_manage ); ?>> Require MFA for login</label>
                        </div>
                        <div class="metis-field metis-field-half">
                            <label for="metis-people-mfa-method">MFA Method</label>
                            <select id="metis-people-mfa-method" class="metis-select" <?php disabled( ! $can_manage ); ?>>
                                <option value="none" <?php metis_attr_selected( $mfa_method, 'none' ); ?>>No MFA</option>
                                <option value="totp" <?php metis_attr_selected( $mfa_method, 'totp' ); ?>>Authenticator App</option>
                                <option value="passkey" <?php metis_attr_selected( $mfa_method, 'passkey' ); ?>>Passkey</option>
                                <option value="passkey_or_totp" <?php metis_attr_selected( $mfa_method, 'passkey_or_totp' ); ?>>Passkey or Authenticator App</option>
                                <option value="passkey_and_totp" <?php metis_attr_selected( $mfa_method, 'passkey_and_totp' ); ?>>Passkey and Authenticator App</option>
                            </select>
                        </div>

                        <div class="metis-field metis-field-half">
                            <label>Authenticator App</label>
                            <div class="metis-people-security-row">
                                <span class="metis-chip <?php echo $totp_enabled ? 'metis-chip-success' : ''; ?>"><?php echo $totp_enabled ? 'Configured' : 'Not configured'; ?></span>
                                <?php if ( $can_manage && ! $is_new && ! empty( $person['id'] ) ) : ?>
                                    <button type="button" class="metis-btn-xs" id="metis-people-totp-setup-open"><?php echo $totp_enabled ? 'Reset App Code' : 'Set Up App Code'; ?></button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="metis-field metis-field-half">
                            <label>Passkey</label>
                            <div class="metis-people-security-row">
                                <span class="metis-chip <?php echo $passkey_enabled ? 'metis-chip-success' : ''; ?>"><?php echo $passkey_enabled ? 'Configured' : 'Not configured'; ?></span>
                                <?php if ( $can_manage && ! $is_new && ! empty( $person['id'] ) ) : ?>
                                    <button type="button" class="metis-btn-xs" id="metis-people-passkey-register-open">Register Passkey</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="metis-field metis-field-full">
                            <label>Registered Passkeys</label>
                            <div class="metis-people-mini-list" id="metis-people-passkeys-list">
                                <?php if ( ! empty( $person_passkey_rows ) ) : ?>
                                    <?php foreach ( $person_passkey_rows as $pk ) : ?>
                                        <div class="metis-people-mini-item">
                                            <div><strong><?php echo metis_escape_html( (string) ( $pk['label'] ?? 'Passkey' ) ); ?></strong></div>
                                            <div class="metis-muted">Created: <?php echo metis_escape_html( (string) ( $pk['created_at'] ?? '' ) ); ?><?php echo ! empty( $pk['last_used_at'] ) ? ' | Last used: ' . metis_escape_html( (string) $pk['last_used_at'] ) : ''; ?></div>
                                            <?php if ( $can_manage && empty( $pk['revoked_at'] ) ) : ?>
                                                <div class="metis-people-mini-actions">
                                                    <button type="button" class="metis-btn-xs metis-btn-danger metis-passkey-revoke" data-id="<?php echo metis_escape_attr( (string) ( $pk['id'] ?? '' ) ); ?>">Revoke</button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <div class="metis-muted">No passkeys registered.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ( $can_manage && ! $is_new && ! empty( $person['id'] ) ) : ?>
                            <div class="metis-field metis-field-full">
                                <details>
                                    <summary><strong>Show Recovery Actions</strong></summary>
                                    <div style="margin-top:10px;">
                                        <label>MFA Recovery</label>
                                        <div class="metis-people-security-row">
                                            <button
                                                type="button"
                                                class="metis-btn-xs metis-btn-danger"
                                                id="metis-people-reset-mfa"
                                                data-person-id="<?php echo metis_escape_attr( (string) $person['id'] ); ?>"
                                                data-person-label="<?php echo metis_escape_attr( trim( (string) ( $person['display_name'] ?: $person['email'] ?: $person['pid'] ?: 'this account' ) ) ); ?>">
                                                Reset MFA
                                            </button>
                                        </div>
                                    </div>
                                    <div style="margin-top:10px;">
                                        <label>Metis Login Security</label>
                                        <div class="metis-people-security-row">
                                            <button
                                                type="button"
                                                class="metis-btn-xs metis-btn-danger"
                                                id="metis-people-reset-metis-password"
                                                data-person-id="<?php echo metis_escape_attr( (string) $person['id'] ); ?>"
                                                data-person-label="<?php echo metis_escape_attr( trim( (string) ( $person['display_name'] ?: $person['email'] ?: $person['pid'] ?: 'this account' ) ) ); ?>"
                                                data-has-password="<?php echo metis_escape_attr( $has_metis_password ? '1' : '0' ); ?>">
                                                <?php echo $has_metis_password ? 'Reset Metis Password' : 'Set Metis Password'; ?>
                                            </button>
                                        </div>
                                    </div>
                                    <?php if ( $workspace_linked_user_id > 0 ) : ?>
                                    <div style="margin-top:10px;">
                                        <label>Workspace Security</label>
                                        <div class="metis-people-security-row">
                                            <button type="button"
                                                class="metis-btn-xs metis-btn-danger metis-workspace-security-open"
                                                data-user-id="<?php echo metis_escape_attr( (string) $workspace_linked_user_id ); ?>"
                                                data-user-email="<?php echo metis_escape_attr( $workspace_linked_email ); ?>"
                                                data-force-action="reset_password">Reset Workspace Password</button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </details>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ( $can_manage ) : ?>
                        <div class="metis-form-actions">
                            <button type="button" class="metis-btn" id="metis-people-save-security">Save Security</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="metis-tab-panel" data-tab-panel="notifications">
                    <h3 class="metis-people-section-title">Notifications</h3>
                    <div class="metis-form-grid">
                        <div class="metis-field metis-field-half">
                            <label><input type="checkbox" id="metis-people-email-notifications" <?php metis_attr_checked( $email_notifications ); ?> <?php disabled( ! $can_manage ); ?>> Enable Email Notifications</label>
                        </div>
                        <div class="metis-field metis-field-full">
                            <details>
                                <summary><strong>Show Channel Preferences</strong></summary>
                                <div class="metis-people-notify-grid" style="margin-top:10px;">
                                    <div class="metis-people-notify-head">Event</div>
                                    <div class="metis-people-notify-head">Email</div>
                                    <div class="metis-people-notify-head">In-app</div>
                                    <?php foreach ( $notification_events as $event_key => $event_label ) : ?>
                                        <?php
                                        $pref = is_array( $notification_prefs[ $event_key ] ?? null ) ? $notification_prefs[ $event_key ] : [];
                                        $pref_email = array_key_exists( 'email', $pref ) ? ! empty( $pref['email'] ) : $email_notifications;
                                        $pref_in_app = array_key_exists( 'in_app', $pref ) ? ! empty( $pref['in_app'] ) : true;
                                        ?>
                                        <div class="metis-people-notify-event"><?php echo metis_escape_html( $event_label ); ?></div>
                                        <label class="metis-people-notify-cell"><input type="checkbox" class="metis-people-notify-pref" data-event="<?php echo metis_escape_attr( $event_key ); ?>" data-channel="email" <?php metis_attr_checked( $pref_email ); ?> <?php disabled( ! $can_manage ); ?>></label>
                                        <label class="metis-people-notify-cell"><input type="checkbox" class="metis-people-notify-pref" data-event="<?php echo metis_escape_attr( $event_key ); ?>" data-channel="in_app" <?php metis_attr_checked( $pref_in_app ); ?> <?php disabled( ! $can_manage ); ?>></label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </div>
                    </div>
                    <?php if ( $can_manage ) : ?>
                        <div class="metis-form-actions">
                            <button type="button" class="metis-btn" id="metis-people-save-notifications">Save Notifications</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="metis-tab-panel" data-tab-panel="lifecycle">
                    <h3 class="metis-people-section-title">Lifecycle Tasks</h3>
                    <?php if ( $can_manage && ! $is_new && ! empty( $person['id'] ) ) : ?>
                        <form id="metis-people-task-form" class="metis-form-grid">
                            <div class="metis-field metis-field-half">
                                <label for="metis-people-task-phase">Phase</label>
                                <select id="metis-people-task-phase" class="metis-select">
                                    <option value="onboarding">Onboarding</option>
                                    <option value="offboarding">Offboarding</option>
                                </select>
                            </div>
                            <div class="metis-field metis-field-half">
                                <label for="metis-people-task-due">Due At (optional)</label>
                                <input id="metis-people-task-due" class="metis-input" type="datetime-local">
                            </div>
                            <div class="metis-field metis-field-full">
                                <label for="metis-people-task-label">Task</label>
                                <input id="metis-people-task-label" class="metis-input" type="text" placeholder="Complete security orientation">
                            </div>
                            <div class="metis-form-actions"><button type="submit" class="metis-btn-xs">Add Task</button></div>
                        </form>
                    <?php endif; ?>
                    <div class="metis-people-mini-list" id="metis-people-task-list">
                        <?php if ( ! empty( $person_lifecycle_tasks ) ) : ?>
                            <?php foreach ( $person_lifecycle_tasks as $task_row ) : ?>
                                <div class="metis-people-mini-item">
                                    <div><strong><?php echo metis_escape_html( ucfirst( (string) ( $task_row['phase'] ?? 'onboarding' ) ) ); ?></strong> — <?php echo metis_escape_html( (string) ( $task_row['task_label'] ?? '' ) ); ?></div>
                                    <div class="metis-muted">
                                        <?php echo metis_escape_html( 'Status: ' . (string) ( $task_row['status'] ?? 'pending' ) ); ?>
                                        <?php if ( ! empty( $task_row['due_at'] ) ) : ?>
                                            <?php echo metis_escape_html( ' | Due: ' . (string) $task_row['due_at'] ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $can_manage && (string) ( $task_row['status'] ?? '' ) !== 'completed' ) : ?>
                                        <div class="metis-people-mini-actions">
                                            <button type="button" class="metis-btn-xs metis-task-complete" data-id="<?php echo metis_escape_attr( (string) ( $task_row['id'] ?? '' ) ); ?>">Mark Complete</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="metis-muted">No lifecycle tasks yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="metis-tab-panel" data-tab-panel="activity">
                    <h3 class="metis-people-section-title">Activity</h3>
                    <div class="metis-people-mini-list">
                        <?php if ( ! empty( $person_activity_rows ) ) : ?>
                            <?php foreach ( $person_activity_rows as $row ) : ?>
                                <div class="metis-people-mini-item">
                                    <div><strong><?php echo metis_escape_html( (string) ( $row['activity_type'] ?? '' ) ); ?></strong> — <?php echo metis_escape_html( (string) ( $row['summary'] ?? '' ) ); ?></div>
                                    <div class="metis-muted"><?php echo metis_escape_html( (string) ( $row['created_at'] ?? '' ) ); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="metis-muted">No activity recorded.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="metis-tab-panel" data-tab-panel="requests">
                    <h3 class="metis-people-section-title">Access Requests</h3>
                    <div class="metis-people-mini-list">
                        <?php if ( ! empty( $person_request_rows ) ) : ?>
                            <?php foreach ( $person_request_rows as $row ) : ?>
                                <div class="metis-people-mini-item">
                                    <div><strong><?php echo metis_escape_html( (string) ( $row['request_code'] ?? '' ) ); ?></strong> — <?php echo metis_escape_html( (string) ( $row['role_name'] ?? '' ) ); ?> (<?php echo metis_escape_html( (string) ( $row['status'] ?? '' ) ); ?>)</div>
                                    <div class="metis-muted"><?php echo metis_escape_html( (string) ( $row['reason'] ?? '' ) ); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="metis-muted">No requests recorded.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="metis-tab-panel" data-tab-panel="documents">
                    <h3 class="metis-people-section-title">Documents</h3>
                    <?php if ( $can_manage && ! $is_new && ! empty( $person['id'] ) ) : ?>
                        <form id="metis-people-doc-form" class="metis-form-grid">
                            <div class="metis-field metis-field-half"><label for="metis-people-doc-type">Type</label><input id="metis-people-doc-type" class="metis-input" type="text" placeholder="agreement"></div>
                            <div class="metis-field metis-field-half"><label for="metis-people-doc-label">Label</label><input id="metis-people-doc-label" class="metis-input" type="text" placeholder="NDA 2026"></div>
                            <div class="metis-field metis-field-half"><label for="metis-people-doc-remind">Remind At (optional)</label><input id="metis-people-doc-remind" class="metis-input" type="datetime-local"></div>
                            <div class="metis-field metis-field-half"><label for="metis-people-doc-expires">Expires At (optional)</label><input id="metis-people-doc-expires" class="metis-input" type="datetime-local"></div>
                            <div class="metis-field metis-field-full"><label for="metis-people-doc-ref">Storage Ref</label><input id="metis-people-doc-ref" class="metis-input" type="text" placeholder="drive://..."></div>
                            <div class="metis-form-actions"><button type="submit" class="metis-btn-xs">Add Document</button></div>
                        </form>
                    <?php endif; ?>
                    <div class="metis-people-mini-list">
                        <?php if ( ! empty( $person_document_rows ) ) : ?>
                            <?php foreach ( $person_document_rows as $row ) : ?>
                                <div class="metis-people-mini-item">
                                    <div><strong><?php echo metis_escape_html( (string) ( $row['doc_label'] ?? '' ) ); ?></strong> (<?php echo metis_escape_html( (string) ( $row['doc_type'] ?? '' ) ); ?>)</div>
                                    <div class="metis-muted">
                                        <?php echo metis_escape_html( (string) ( $row['storage_ref'] ?? '' ) ); ?>
                                        <?php if ( ! empty( $row['expires_at'] ) ) : ?>
                                            <?php echo metis_escape_html( ' | Expires: ' . (string) $row['expires_at'] ); ?>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $row['remind_at'] ) ) : ?>
                                            <?php echo metis_escape_html( ' | Remind: ' . (string) $row['remind_at'] ); ?>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $row['lifecycle_status'] ) ) : ?>
                                            <?php echo metis_escape_html( ' | Status: ' . (string) $row['lifecycle_status'] ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $can_manage ) : ?>
                                        <div class="metis-people-mini-actions">
                                            <button type="button" class="metis-btn-xs metis-btn-danger metis-doc-delete" data-id="<?php echo metis_escape_attr( (string) ( $row['id'] ?? '' ) ); ?>">Delete</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="metis-muted">No documents recorded.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="metis-tab-panel" data-tab-panel="emergency">
                    <h3 class="metis-people-section-title">Emergency Access</h3>
                    <?php if ( $can_manage && ! $is_new && ! empty( $person['id'] ) ) : ?>
                        <form id="metis-people-emergency-form" class="metis-form-grid">
                            <div class="metis-field metis-field-half"><label for="metis-people-emergency-role">Role Key</label><input id="metis-people-emergency-role" class="metis-input" type="text" placeholder="administrator"></div>
                            <div class="metis-field metis-field-half"><label for="metis-people-emergency-hours">Hours</label><input id="metis-people-emergency-hours" class="metis-input" type="number" min="1" max="72" value="4"></div>
                            <div class="metis-field metis-field-full"><label for="metis-people-emergency-reason">Reason</label><input id="metis-people-emergency-reason" class="metis-input" type="text"></div>
                            <div class="metis-form-actions"><button type="submit" class="metis-btn-xs">Grant Emergency Access</button></div>
                        </form>
                    <?php endif; ?>
                    <div class="metis-people-mini-list">
                        <?php if ( ! empty( $person_emergency_rows ) ) : ?>
                            <?php foreach ( $person_emergency_rows as $row ) : ?>
                                <div class="metis-people-mini-item">
                                    <div><strong><?php echo metis_escape_html( (string) ( $row['starts_at'] ?? '' ) ); ?></strong> to <?php echo metis_escape_html( (string) ( $row['ends_at'] ?? '' ) ); ?></div>
                                    <div class="metis-muted"><?php echo metis_escape_html( (string) ( $row['reason'] ?? '' ) ); ?> <?php echo ! empty( $row['revoked_at'] ) ? '(Revoked)' : ''; ?></div>
                                    <?php if ( $can_manage && empty( $row['revoked_at'] ) ) : ?>
                                        <div class="metis-people-mini-actions">
                                            <button type="button" class="metis-btn-xs metis-btn-danger metis-emergency-revoke" data-id="<?php echo metis_escape_attr( (string) ( $row['id'] ?? '' ) ); ?>">Revoke</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="metis-muted">No emergency access records.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /metis-people-tabs[data-tabs-content] -->
        </div><!-- /metis-people-tab-content -->

    </div><!-- /metis-people-person-grid -->
</div><!-- /metis-people-detail -->
<?php if ( $can_attach_drive_folder && ! $is_new ) : ?>
<div id="metis-people-drive-picker-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-people-modal-inner metis-people-drive-picker-inner">
        <h3 class="metis-modal-title">Select User Drive Folder</h3>
        <div class="metis-form-grid">
            <div class="metis-field metis-field-full">
                <div class="metis-muted" id="metis-people-drive-picker-subtitle">Choose a folder from the <?php echo metis_escape_html( $drive_users_root_name ); ?> container.</div>
            </div>
            <div class="metis-field metis-field-full">
                <div class="metis-people-drive-picker-toolbar">
                    <button type="button" id="metis-people-drive-picker-up" class="metis-btn metis-btn-ghost">Up</button>
                    <div id="metis-people-drive-picker-path" class="metis-chip"><?php echo metis_escape_html( $drive_users_root_name ); ?></div>
                </div>
            </div>
            <div class="metis-field metis-field-full">
                <div id="metis-people-drive-picker-status" class="metis-muted"></div>
                <div id="metis-people-drive-picker-list" class="metis-people-drive-picker-list"></div>
            </div>
        </div>
        <div class="metis-form-actions">
            <button type="button" id="metis-people-drive-picker-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ( $can_manage && ! $is_new && ! empty( $person['pid'] ) ) : ?>
<div id="metis-people-offboard-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-people-modal-inner">
        <h3 class="metis-modal-title">Confirm Offboarding</h3>
        <p>This will set status to inactive, set lifecycle to alumni, clear workspace/stripe access, and remove role assignments.</p>
        <div class="metis-form-actions">
            <button type="button" id="metis-people-offboard-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
            <button type="button" id="metis-people-offboard-confirm" class="metis-btn metis-btn-danger" data-pid="<?php echo metis_escape_attr( (string) $person['pid'] ); ?>">Run Offboarding</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ( $can_manage && ! $is_new && ! empty( $person['pid'] ) ) : ?>
<div id="metis-people-avatar-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-people-modal-inner">
        <h3 class="metis-modal-title">Update Profile Photo</h3>
        <div class="metis-form-grid">
            <div class="metis-field metis-field-full">
                <label for="metis-people-avatar-file">Upload Image</label>
                <input id="metis-people-avatar-file" class="metis-input" type="file" accept="image/*">
            </div>
            <div class="metis-field metis-field-full">
                <label>Crop Preview</label>
                <div class="metis-avatar-cropper">
                    <canvas id="metis-people-avatar-canvas" class="metis-avatar-canvas" width="320" height="320"></canvas>
                    <div class="metis-avatar-preview-shell">
                        <span class="metis-avatar-preview-label">Preview</span>
                        <div class="metis-avatar-preview-wrap">
                            <img id="metis-people-avatar-preview" class="metis-avatar-preview" alt="Avatar preview">
                        </div>
                    </div>
                </div>
            </div>
            <div class="metis-field metis-field-full">
                <div class="metis-muted">Drag the photo inside the circle and use the zoom control or mouse wheel to crop it.</div>
            </div>
            <div class="metis-field metis-field-full">
                <label for="metis-people-avatar-zoom">Zoom</label>
                <input id="metis-people-avatar-zoom" type="range" min="1" max="4" step="0.01" value="1">
            </div>
        </div>
        <div class="metis-form-actions">
            <button type="button" id="metis-people-avatar-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
            <button type="button" id="metis-people-avatar-save" class="metis-btn">Save Photo</button>
        </div>
    </div>
</div>

<div id="metis-people-totp-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-people-modal-inner">
        <h3 class="metis-modal-title">Set Up Authenticator App</h3>
        <div class="metis-form-grid">
            <div class="metis-field metis-field-full">
                <label>QR Code</label>
                <img id="metis-people-totp-qr" class="metis-people-totp-qr" alt="Authenticator QR code" style="display:none;">
                <div class="metis-muted">Generate a key, then scan this code in your authenticator app.</div>
            </div>
            <div class="metis-field metis-field-full">
                <label>Secret Key</label>
                <div id="metis-people-totp-secret" class="metis-chip">Generate to start.</div>
                <div class="metis-muted">Add this key to Google Authenticator, 1Password, or another TOTP app.</div>
            </div>
            <div class="metis-field metis-field-full">
                <label for="metis-people-totp-uri">Provisioning URI</label>
                <input id="metis-people-totp-uri" class="metis-input" type="text" readonly>
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-people-totp-code">6-digit code</label>
                <input id="metis-people-totp-code" class="metis-input" type="text" maxlength="6" inputmode="numeric" pattern="[0-9]{6}">
            </div>
        </div>
        <div class="metis-form-actions">
            <button type="button" id="metis-people-totp-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
            <button type="button" id="metis-people-totp-generate" class="metis-btn metis-btn-ghost">Generate Key</button>
            <button type="button" id="metis-people-totp-verify" class="metis-btn">Verify and Enable</button>
        </div>
    </div>
</div>
<?php endif; ?>
<script type="application/json" id="metis-people-stripe-role-descriptions"><?php echo metis_json_encode( $stripe_role_descriptions ); ?></script>
