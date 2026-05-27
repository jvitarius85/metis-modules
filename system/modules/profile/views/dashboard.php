<?php if (!defined('METIS_ROOT')) exit;

if (!metis_user_logged_in()) {
    echo '<div class="metis-alert metis-alert-error">You must be logged in to view your profile.</div>';
    return;
}

if (function_exists('metis_people_ensure_schema')) {
    metis_people_ensure_schema();
}
if (function_exists('metis_people_seed_permissions_and_roles')) {
    metis_people_seed_permissions_and_roles();
}

$person = null;
if (function_exists('metis_people_get_current_person_id')) {
    $person_id = (int) metis_people_get_current_person_id();
    if ($person_id > 0) {
        $person = \Metis\Modules\People\PersonProfileService::getById( $person_id );
    }
}

if (!$person || empty($person['id'])) {
    echo '<div class="metis-alert metis-alert-error">Unable to load profile record.</div>';
    return;
}

$passkeys = [];
if (Metis_Tables::has('people_passkeys')) {
    $passkeys = \Metis\Modules\People\MfaService::activePasskeys( (int) $person['id'] );
}

$notification_events = [
    'contacts' => 'Contacts updates',
    'donations' => 'Donations activity',
    'people_access' => 'People access and role changes',
    'security' => 'Security alerts',
    'system' => 'System announcements',
];
$notification_prefs = [];
if (!empty($person['notification_prefs_json'])) {
    $decoded = json_decode((string) $person['notification_prefs_json'], true);
    if (is_array($decoded)) {
        $notification_prefs = $decoded;
    }
}

$allow_name_edit = (int) Core_Settings_Service::get('profile_allow_name_edit', 0) === 1;

$full_name = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
if ($full_name === '') {
    $full_name = (string) ($person['display_name'] ?? 'Profile');
}

$avatar_src = metis_avatar_url($full_name, (string) ($person['avatar_url'] ?? ''), 160, (string) ($person['pid'] ?? ''));
$auth_user = function_exists('metis_auth_find_user') ? metis_auth_find_user('person_id', (int) ($person['id'] ?? 0)) : null;
$has_metis_password = is_array($auth_user)
    && function_exists('metis_auth_password_hash_for_authentication')
    && metis_auth_password_hash_for_authentication($auth_user, $person) !== '';
$has_workspace_password = !empty($person['is_workspace_user']) || metis_email_is_valid((string) ($person['workspace_email'] ?? ''));
$session_auth_method = function_exists('metis_auth_current_method') ? metis_auth_current_method() : '';
$can_set_metis_password_from_session = in_array($session_auth_method, ['passkey', 'google_workspace', 'password_mfa'], true);
$carddav_endpoint = function_exists('metis_contacts_carddav_endpoint_url') ? (string) metis_contacts_carddav_endpoint_url('addressbooks/') : '';
$current_user = function_exists('metis_runtime_current_user') ? metis_runtime_current_user() : null;
$carddav_username = $current_user instanceof MetisUser
    ? (string) $current_user->user_login
    : (string) ($person['email'] ?? '');
$carddav_tokens = function_exists('metis_contacts_carddav_list_tokens')
    ? (array) metis_contacts_carddav_list_tokens(metis_current_user_id())
    : [];
?>

<div class="metis-profile metis-contact-detail" data-person-id="<?php echo metis_escape_attr((string) ($person['id'] ?? 0)); ?>">
    <div id="metis-profile-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-space-between" style="margin-bottom:14px;">
        <div>
            <h1 class="metis-page-title" id="metis-profile-title" style="margin-bottom:8px;"><?php echo metis_escape_html($full_name); ?></h1>
            <div class="metis-muted">PID: <span id="metis-profile-pid"><?php echo metis_escape_html((string) ($person['pid'] ?? '')); ?></span></div>
        </div>
    </div>

    <div class="metis-sidebar-layout metis-profile-layout">
        <aside class="metis-sidebar-layout-sidebar metis-profile-layout-sidebar">
            <div class="metis-sidebar-layout-sidebar-inner metis-profile-layout-sidebar-inner">
                <div class="metis-list-sidebar-actions">
                    <div class="metis-list-sidebar-label">Profile</div>
                    <nav class="metis-list-sidebar-nav metis-profile-tabs" aria-label="Profile sections">
                        <button type="button" class="metis-list-sidebar-nav-item metis-profile-tab-btn is-active" data-profile-tab="profile">Profile</button>
                        <button type="button" class="metis-list-sidebar-nav-item metis-profile-tab-btn" data-profile-tab="security">Security</button>
                        <button type="button" class="metis-list-sidebar-nav-item metis-profile-tab-btn" data-profile-tab="notifications">Notifications</button>
                    </nav>
                </div>
            </div>
        </aside>

        <div class="metis-sidebar-layout-content metis-profile-layout-content">
    <div class="metis-profile-tab-panel is-active" data-profile-panel="profile">
        <div class="metis-contact-detail-grid">
            <section class="metis-contact-card metis-profile-card">
                <div class="metis-profile-avatar-wrap">
                    <img id="metis-profile-avatar" class="metis-profile-avatar" src="<?php echo metis_escape_url($avatar_src); ?>" alt="Profile photo">
                </div>
                <button type="button" class="metis-btn-xs" id="metis-profile-avatar-edit-open">Change Photo</button>
                <h3 id="metis-profile-name"><?php echo metis_escape_html($full_name); ?></h3>
                <div class="metis-muted" id="metis-profile-email-text"><?php echo metis_escape_html((string) ($person['email'] ?? '')); ?></div>
                <div class="metis-muted">Provider: <?php echo metis_escape_html(ucfirst((string) ($person['auth_provider'] ?? 'metis'))); ?></div>
                <div class="metis-muted">Last Updated: <span id="metis-profile-updated"><?php echo metis_escape_html((string) ($person['updated_at'] ?? '')); ?></span></div>
            </section>

            <section class="metis-contact-card">
                <h3>Profile Info</h3>
                <form id="metis-profile-form" class="metis-form-grid">
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-first-name">First Name</label>
                        <input id="metis-profile-first-name" class="metis-input" type="text" value="<?php echo metis_escape_attr((string) ($person['first_name'] ?? '')); ?>" <?php disabled(!$allow_name_edit); ?>>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-last-name">Last Name</label>
                        <input id="metis-profile-last-name" class="metis-input" type="text" value="<?php echo metis_escape_attr((string) ($person['last_name'] ?? '')); ?>" <?php disabled(!$allow_name_edit); ?>>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-display-name">Display Name</label>
                        <input id="metis-profile-display-name" class="metis-input" type="text" value="<?php echo metis_escape_attr((string) ($person['display_name'] ?? '')); ?>" <?php disabled(!$allow_name_edit); ?> required>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-email">Email</label>
                        <input id="metis-profile-email" class="metis-input" type="email" value="<?php echo metis_escape_attr((string) ($person['email'] ?? '')); ?>" readonly disabled>
                        <div class="metis-muted">Email is managed by admins in People and syncs with Workspace.</div>
                    </div>
                    <?php if (!$allow_name_edit) : ?>
                    <div class="metis-field metis-field-full">
                        <div class="metis-muted">Name editing is disabled by admin policy.</div>
                    </div>
                    <?php endif; ?>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-mfa-method">MFA Policy</label>
                        <select id="metis-profile-mfa-method" class="metis-select">
                            <option value="none" <?php echo metis_attr_selected((string) ($person['mfa_method'] ?? 'none'), 'none', false); ?>>None</option>
                            <option value="totp" <?php echo metis_attr_selected((string) ($person['mfa_method'] ?? 'none'), 'totp', false); ?>>Authenticator App</option>
                            <option value="passkey" <?php echo metis_attr_selected((string) ($person['mfa_method'] ?? 'none'), 'passkey', false); ?>>Passkey</option>
                            <option value="passkey_or_totp" <?php echo metis_attr_selected((string) ($person['mfa_method'] ?? 'none'), 'passkey_or_totp', false); ?>>Passkey or Authenticator</option>
                            <option value="passkey_and_totp" <?php echo metis_attr_selected((string) ($person['mfa_method'] ?? 'none'), 'passkey_and_totp', false); ?>>Passkey and Authenticator</option>
                        </select>
                    </div>
                    <div class="metis-field metis-field-full metis-profile-checkboxes">
                        <label class="metis-people-check"><input type="checkbox" id="metis-profile-email-notifications" <?php echo metis_attr_checked(!isset($person['email_notifications']) || (int) $person['email_notifications'] === 1, true, false); ?>> Email notifications enabled</label>
                        <label class="metis-people-check"><input type="checkbox" id="metis-profile-requires-2fa" <?php echo metis_attr_checked(!empty($person['requires_2fa']), true, false); ?>> Require MFA on login</label>
                    </div>
                    <div class="metis-form-actions">
                        <button type="submit" class="metis-btn">Save Profile</button>
                    </div>
                </form>
            </section>

            <section class="metis-contact-card">
                <h3>Org Snapshot</h3>
                <div class="metis-profile-static-list">
                    <div class="metis-profile-static-row"><span>Department:</span> <strong id="metis-profile-department-view"><?php echo metis_escape_html((string) ($person['department'] ?: '—')); ?></strong></div>
                    <div class="metis-profile-static-row"><span>Manager PID:</span> <strong id="metis-profile-manager-pid-view"><?php echo metis_escape_html((string) ($person['manager_pid'] ?: '—')); ?></strong></div>
                    <div class="metis-profile-static-row"><span>Lifecycle:</span> <strong id="metis-profile-lifecycle-status-view"><?php echo metis_escape_html(ucfirst((string) ($person['lifecycle_status'] ?: 'active'))); ?></strong></div>
                </div>
                <div class="metis-muted">Managed by admin roles in People.</div>
            </section>
        </div>
    </div>

    <div class="metis-profile-tab-panel" data-profile-panel="security">
        <div class="metis-contact-detail-grid">
            <section class="metis-contact-card">
                <h3>Authentication Methods</h3>
                <div class="metis-profile-security-actions">
                    <button type="button" class="metis-btn-xs" id="metis-profile-totp-open">Set Up Authenticator App</button>
                    <button type="button" class="metis-btn-xs" id="metis-profile-passkey-register-open">Register Passkey</button>
                </div>
                <div class="metis-profile-security-state">
                    <span class="metis-chip <?php echo !empty($person['totp_enabled']) ? 'is-active' : ''; ?>" id="metis-profile-totp-state"><?php echo !empty($person['totp_enabled']) ? 'TOTP Enabled' : 'TOTP Not Enabled'; ?></span>
                    <span class="metis-chip <?php echo !empty($person['passkey_enabled']) ? 'is-active' : ''; ?>" id="metis-profile-passkey-state"><?php echo !empty($person['passkey_enabled']) ? 'Passkey Enabled' : 'Passkey Not Enabled'; ?></span>
                </div>
            </section>

            <section class="metis-contact-card">
                <h3>Registered Passkeys</h3>
                <div id="metis-profile-passkeys-list" class="metis-profile-passkey-list">
                    <?php if (!empty($passkeys)) : ?>
                        <?php foreach ($passkeys as $passkey) : ?>
                            <div class="metis-profile-passkey-item" data-passkey-id="<?php echo metis_escape_attr((string) ($passkey['id'] ?? '')); ?>">
                                <div><strong><?php echo metis_escape_html((string) ($passkey['label'] ?? 'Passkey')); ?></strong></div>
                                <div class="metis-muted">Created: <?php echo metis_escape_html((string) ($passkey['created_at'] ?? '')); ?></div>
                                <div class="metis-muted">Last used: <?php echo metis_escape_html((string) ($passkey['last_used_at'] ?? 'Never')); ?></div>
                                <div class="metis-people-mini-actions"><button type="button" class="metis-btn-xs metis-btn-danger metis-profile-passkey-revoke" data-id="<?php echo metis_escape_attr((string) ($passkey['id'] ?? '')); ?>">Revoke</button></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="metis-muted" id="metis-profile-passkeys-empty">No passkeys registered.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="metis-contact-card">
                <h3>CardDAV Access</h3>
                <div class="metis-profile-static-list">
                    <div class="metis-profile-static-row"><span>Server URL:</span> <strong id="metis-profile-carddav-endpoint"><?php echo metis_escape_html($carddav_endpoint); ?></strong></div>
                    <div class="metis-profile-static-row"><span>Username:</span> <strong id="metis-profile-carddav-username"><?php echo metis_escape_html($carddav_username); ?></strong></div>
                </div>
                <div class="metis-form-grid" style="margin:12px 0;">
                    <div class="metis-field metis-field-full">
                        <label for="metis-profile-carddav-token-label">Create Device Token</label>
                        <input type="text" id="metis-profile-carddav-token-label" class="metis-input" autocomplete="off" placeholder="iPhone, MacBook, Outlook, etc.">
                    </div>
                    <div class="metis-form-actions">
                        <button type="button" class="metis-btn" id="metis-profile-carddav-generate">Generate CardDAV Token</button>
                    </div>
                </div>
                <div class="metis-callout metis-callout-warning" id="metis-profile-carddav-issued" style="display:none;"></div>
                <div id="metis-profile-carddav-list" class="metis-profile-passkey-list">
                    <?php if (!empty($carddav_tokens)) : ?>
                        <?php foreach ($carddav_tokens as $token_row) : ?>
                            <div class="metis-profile-passkey-item" data-carddav-token-id="<?php echo metis_escape_attr((string) ($token_row['id'] ?? '')); ?>">
                                <div><strong><?php echo metis_escape_html((string) ($token_row['label'] ?? 'CardDAV device')); ?></strong></div>
                                <div class="metis-muted">Created: <?php echo metis_escape_html((string) ($token_row['created_at'] ?? '')); ?></div>
                                <div class="metis-muted">Last used: <?php echo metis_escape_html((string) ($token_row['last_used_at'] ?? 'Never')); ?></div>
                                <div class="metis-people-mini-actions"><button type="button" class="metis-btn-xs metis-btn-danger metis-profile-carddav-revoke" data-id="<?php echo metis_escape_attr((string) ($token_row['id'] ?? '')); ?>">Revoke</button></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="metis-muted" id="metis-profile-carddav-empty">No CardDAV tokens issued.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="metis-contact-card">
                <h3>Password</h3>
                <form id="metis-profile-password-form" class="metis-form-grid" data-has-password="<?php echo $has_metis_password ? '1' : '0'; ?>" data-can-set-from-session="<?php echo $can_set_metis_password_from_session ? '1' : '0'; ?>">
                    <div class="metis-field metis-field-full">
                        <h4 style="margin:0 0 4px;">Metis Password</h4>
                        <div class="metis-muted">
                            <?php echo $has_metis_password
                                ? ($can_set_metis_password_from_session
                                    ? 'Sets a Metis password from your current trusted session and signs out other sessions.'
                                    : 'Updates your Metis password and signs out other sessions.')
                                : 'Create a Metis password for direct sign-in. You will be signed out after it is saved.'; ?>
                        </div>
                    </div>
                    <?php if ($has_metis_password && !$can_set_metis_password_from_session) : ?>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-current-password">Current Password</label>
                        <input type="password" id="metis-profile-current-password" class="metis-input" autocomplete="current-password" required>
                    </div>
                    <?php endif; ?>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-new-password">New Password</label>
                        <input type="password" id="metis-profile-new-password" class="metis-input" autocomplete="new-password" minlength="12" required>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-confirm-password">Confirm Password</label>
                        <input type="password" id="metis-profile-confirm-password" class="metis-input" autocomplete="new-password" minlength="12" required>
                    </div>
                    <div class="metis-form-actions">
                        <button type="submit" class="metis-btn"><?php echo ($has_metis_password && !$can_set_metis_password_from_session) ? 'Update Metis Password' : 'Set Metis Password'; ?></button>
                    </div>
                </form>

                <?php if ($has_workspace_password) : ?>
                <form id="metis-profile-workspace-password-form" class="metis-form-grid" style="margin-top:24px;padding-top:24px;border-top:1px solid #e5e7eb;">
                    <div class="metis-field metis-field-full">
                        <h4 style="margin:0 0 4px;">Google Workspace Password</h4>
                        <div class="metis-muted">Updates your Google Workspace password directly.</div>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-workspace-new-password">New Password</label>
                        <input type="password" id="metis-profile-workspace-new-password" class="metis-input" autocomplete="new-password" minlength="12" required>
                    </div>
                    <div class="metis-field metis-field-half">
                        <label for="metis-profile-workspace-confirm-password">Confirm Password</label>
                        <input type="password" id="metis-profile-workspace-confirm-password" class="metis-input" autocomplete="new-password" minlength="12" required>
                    </div>
                    <div class="metis-form-actions">
                        <button type="submit" class="metis-btn">Update Workspace Password</button>
                    </div>
                </form>
                <?php endif; ?>

            </section>
        </div>
    </div>

    <div class="metis-profile-tab-panel" data-profile-panel="notifications">
        <div class="metis-contact-detail-grid">
            <section class="metis-contact-card" style="grid-column:1 / -1;">
                <h3>Notifications</h3>
                <div class="metis-profile-notification-grid">
                    <?php foreach ($notification_events as $event_key => $event_label) :
                        $event_cfg = isset($notification_prefs[$event_key]) && is_array($notification_prefs[$event_key])
                            ? $notification_prefs[$event_key]
                            : ['email' => false, 'in_app' => true];
                    ?>
                    <div class="metis-profile-notification-row" data-event="<?php echo metis_escape_attr($event_key); ?>">
                        <div class="metis-profile-notification-title"><?php echo metis_escape_html($event_label); ?></div>
                        <label><input type="checkbox" class="metis-profile-notify-pref" data-event="<?php echo metis_escape_attr($event_key); ?>" data-channel="email" <?php echo metis_attr_checked(!empty($event_cfg['email']), true, false); ?>> Email</label>
                        <label><input type="checkbox" class="metis-profile-notify-pref" data-event="<?php echo metis_escape_attr($event_key); ?>" data-channel="in_app" <?php echo metis_attr_checked(!empty($event_cfg['in_app']), true, false); ?>> In-App</label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn" id="metis-profile-save-notifications">Save Notifications</button>
                </div>
            </section>
        </div>
    </div>
        </div>
    </div>

    <div class="metis-modal-backdrop" id="metis-profile-avatar-modal" aria-hidden="true">
        <div class="metis-modal metis-profile-modal-inner">
            <h3>Update Photo</h3>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-full">
                    <label for="metis-profile-avatar-file">Image</label>
                    <input type="file" id="metis-profile-avatar-file" class="metis-input" accept="image/png,image/jpeg">
                </div>
                <div class="metis-field metis-field-full">
                    <div class="metis-avatar-cropper">
                        <canvas id="metis-profile-avatar-canvas" class="metis-avatar-canvas" width="320" height="320"></canvas>
                        <div class="metis-avatar-preview-shell">
                            <span class="metis-avatar-preview-label">Preview</span>
                            <div class="metis-avatar-preview-wrap">
                                <img id="metis-profile-avatar-preview" class="metis-avatar-preview" alt="Avatar preview">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="metis-field metis-field-full">
                    <div class="metis-muted">Drag the photo inside the circle and use the zoom control or mouse wheel to crop it.</div>
                </div>
                <div class="metis-field metis-field-full">
                    <label for="metis-profile-avatar-zoom">Zoom</label>
                    <input id="metis-profile-avatar-zoom" class="metis-input" type="range" min="1" max="4" step="0.01" value="1">
                </div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn metis-btn-ghost" id="metis-profile-avatar-cancel">Cancel</button>
                    <button type="button" class="metis-btn" id="metis-profile-avatar-save">Save Photo</button>
                </div>
            </div>
        </div>
    </div>

    <div class="metis-modal-backdrop" id="metis-profile-totp-modal" aria-hidden="true">
        <div class="metis-modal metis-profile-modal-inner">
            <h3>Set Up Authenticator App</h3>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-full">
                    <button type="button" class="metis-btn-xs" id="metis-profile-totp-generate">Generate Key</button>
                </div>
                <div class="metis-field metis-field-full">
                    <img id="metis-profile-totp-qr" src="" alt="Authenticator QR" style="display:none; width:160px; height:160px;" />
                </div>
                <div class="metis-field metis-field-full">
                    <label>Secret Key</label>
                    <div id="metis-profile-totp-secret" class="metis-chip">Generate a key to begin.</div>
                </div>
                <div class="metis-field metis-field-full">
                    <label for="metis-profile-totp-uri">Provisioning URI</label>
                    <textarea id="metis-profile-totp-uri" class="metis-input" rows="2" readonly></textarea>
                </div>
                <div class="metis-field metis-field-full">
                    <label for="metis-profile-totp-code">Enter 6-digit code</label>
                    <input type="text" id="metis-profile-totp-code" class="metis-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="123456">
                </div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn metis-btn-ghost" id="metis-profile-totp-cancel">Cancel</button>
                    <button type="button" class="metis-btn" id="metis-profile-totp-verify">Verify and Enable</button>
                </div>
            </div>
        </div>
    </div>

    <div class="metis-modal-backdrop" id="metis-profile-passkey-label-modal" aria-hidden="true">
        <div class="metis-modal metis-profile-modal-inner">
            <h3>Name This Passkey</h3>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-full">
                    <label for="metis-profile-passkey-label">Label</label>
                    <input id="metis-profile-passkey-label" class="metis-input" type="text" placeholder="My Laptop">
                </div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn metis-btn-ghost" id="metis-profile-passkey-label-cancel">Cancel</button>
                    <button type="button" class="metis-btn" id="metis-profile-passkey-label-save">Save Passkey</button>
                </div>
            </div>
        </div>
    </div>
</div>
