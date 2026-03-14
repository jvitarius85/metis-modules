<?php if (!defined('ABSPATH')) exit;

if (!metis_user_logged_in()) {
    echo '<div class="mw-alert mw-alert-error">You must be logged in to view your profile.</div>';
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
        global $wpdb;
        $people_table = Metis_Tables::get('people');
        $person = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$people_table} WHERE id = %d LIMIT 1", $person_id), ARRAY_A);
    }
}

if (!$person || empty($person['id'])) {
    echo '<div class="mw-alert mw-alert-error">Unable to load profile record.</div>';
    return;
}

global $wpdb;
$passkeys = [];
if (Metis_Tables::has('people_passkeys')) {
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $passkeys = $wpdb->get_results($wpdb->prepare(
        "SELECT id, label, created_at, last_used_at
         FROM {$passkeys_table}
         WHERE person_id = %d AND revoked_at IS NULL
         ORDER BY created_at DESC",
        (int) $person['id']
    ), ARRAY_A) ?: [];
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

$avatar_src = '';
if (!empty($person['avatar_url'])) {
    $avatar_src = (string) $person['avatar_url'];
} else {
    $avatar_src = metis_avatar_fallback_url((string) ($person['email'] ?? ''), 160);
}
?>

<div class="metis-profile metis-contact-detail" data-person-id="<?php echo esc_attr((string) ($person['id'] ?? 0)); ?>">
    <div id="metis-profile-alert" class="mw-alert" style="display:none;"></div>

    <div class="mw-space-between" style="margin-bottom:14px;">
        <div>
            <h1 class="mw-page-title" id="metis-profile-title" style="margin-bottom:8px;"><?php echo esc_html($full_name); ?></h1>
            <div class="mw-muted">PID: <span id="metis-profile-pid"><?php echo esc_html((string) ($person['pid'] ?? '')); ?></span></div>
        </div>
    </div>

    <div class="metis-profile-tabs">
        <button type="button" class="mw-btn-xs metis-profile-tab-btn is-active" data-profile-tab="profile">Profile</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-profile-tab="security">Security</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-profile-tab="notifications">Notifications</button>
    </div>

    <div class="metis-profile-tab-panel is-active" data-profile-panel="profile">
        <div class="metis-contact-detail-grid">
            <section class="metis-contact-card metis-profile-card">
                <div class="metis-profile-avatar-wrap">
                    <img id="metis-profile-avatar" class="metis-profile-avatar" src="<?php echo esc_url($avatar_src); ?>" alt="Profile photo">
                </div>
                <button type="button" class="mw-btn-xs" id="metis-profile-avatar-edit-open">Change Photo</button>
                <h3 id="metis-profile-name"><?php echo esc_html($full_name); ?></h3>
                <div class="mw-muted" id="metis-profile-email-text"><?php echo esc_html((string) ($person['email'] ?? '')); ?></div>
                <div class="mw-muted">Provider: <?php echo esc_html(ucfirst((string) ($person['auth_provider'] ?? 'metis'))); ?></div>
                <div class="mw-muted">Last Updated: <span id="metis-profile-updated"><?php echo esc_html((string) ($person['updated_at'] ?? '')); ?></span></div>
            </section>

            <section class="metis-contact-card">
                <h3>Profile Info</h3>
                <form id="metis-profile-form" class="metis-contact-form">
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-profile-first-name">First Name</label>
                        <input id="metis-profile-first-name" class="mw-input" type="text" value="<?php echo esc_attr((string) ($person['first_name'] ?? '')); ?>" <?php disabled(!$allow_name_edit); ?>>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-profile-last-name">Last Name</label>
                        <input id="metis-profile-last-name" class="mw-input" type="text" value="<?php echo esc_attr((string) ($person['last_name'] ?? '')); ?>" <?php disabled(!$allow_name_edit); ?>>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-profile-display-name">Display Name</label>
                        <input id="metis-profile-display-name" class="mw-input" type="text" value="<?php echo esc_attr((string) ($person['display_name'] ?? '')); ?>" <?php disabled(!$allow_name_edit); ?> required>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-profile-email">Email</label>
                        <input id="metis-profile-email" class="mw-input" type="email" value="<?php echo esc_attr((string) ($person['email'] ?? '')); ?>" readonly disabled>
                        <div class="mw-muted">Email is managed by admins in People and syncs with Workspace.</div>
                    </div>
                    <?php if (!$allow_name_edit) : ?>
                    <div class="metis-contact-field metis-contact-field-full">
                        <div class="mw-muted">Name editing is disabled by admin policy.</div>
                    </div>
                    <?php endif; ?>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-profile-mfa-method">MFA Policy</label>
                        <select id="metis-profile-mfa-method" class="mw-select">
                            <option value="none" <?php selected((string) ($person['mfa_method'] ?? 'none'), 'none'); ?>>None</option>
                            <option value="totp" <?php selected((string) ($person['mfa_method'] ?? 'none'), 'totp'); ?>>Authenticator App</option>
                            <option value="passkey" <?php selected((string) ($person['mfa_method'] ?? 'none'), 'passkey'); ?>>Passkey</option>
                            <option value="passkey_or_totp" <?php selected((string) ($person['mfa_method'] ?? 'none'), 'passkey_or_totp'); ?>>Passkey or Authenticator</option>
                            <option value="passkey_and_totp" <?php selected((string) ($person['mfa_method'] ?? 'none'), 'passkey_and_totp'); ?>>Passkey and Authenticator</option>
                        </select>
                    </div>
                    <div class="metis-contact-field metis-contact-field-full metis-profile-checkboxes">
                        <label class="metis-people-check"><input type="checkbox" id="metis-profile-email-notifications" <?php checked(!isset($person['email_notifications']) || (int) $person['email_notifications'] === 1); ?>> Email notifications enabled</label>
                        <label class="metis-people-check"><input type="checkbox" id="metis-profile-requires-2fa" <?php checked(!empty($person['requires_2fa'])); ?>> Require MFA on login</label>
                    </div>
                    <div class="metis-contact-actions">
                        <button type="submit" class="mw-btn">Save Profile</button>
                    </div>
                </form>
            </section>

            <section class="metis-contact-card">
                <h3>Org Snapshot</h3>
                <div class="metis-profile-static-list">
                    <div class="metis-profile-static-row"><span>Department:</span> <strong id="metis-profile-department-view"><?php echo esc_html((string) ($person['department'] ?: '—')); ?></strong></div>
                    <div class="metis-profile-static-row"><span>Manager PID:</span> <strong id="metis-profile-manager-pid-view"><?php echo esc_html((string) ($person['manager_pid'] ?: '—')); ?></strong></div>
                    <div class="metis-profile-static-row"><span>Lifecycle:</span> <strong id="metis-profile-lifecycle-status-view"><?php echo esc_html(ucfirst((string) ($person['lifecycle_status'] ?: 'active'))); ?></strong></div>
                </div>
                <div class="mw-muted">Managed by admin roles in People.</div>
            </section>
        </div>
    </div>

    <div class="metis-profile-tab-panel" data-profile-panel="security">
        <div class="metis-contact-detail-grid">
            <section class="metis-contact-card">
                <h3>Authentication Methods</h3>
                <div class="metis-profile-security-actions">
                    <button type="button" class="mw-btn-xs" id="metis-profile-totp-open">Set Up Authenticator App</button>
                    <button type="button" class="mw-btn-xs" id="metis-profile-passkey-register-open">Register Passkey</button>
                </div>
                <div class="metis-profile-security-state">
                    <span class="mw-chip <?php echo !empty($person['totp_enabled']) ? 'is-active' : ''; ?>" id="metis-profile-totp-state"><?php echo !empty($person['totp_enabled']) ? 'TOTP Enabled' : 'TOTP Not Enabled'; ?></span>
                    <span class="mw-chip <?php echo !empty($person['passkey_enabled']) ? 'is-active' : ''; ?>" id="metis-profile-passkey-state"><?php echo !empty($person['passkey_enabled']) ? 'Passkey Enabled' : 'Passkey Not Enabled'; ?></span>
                </div>
            </section>

            <section class="metis-contact-card">
                <h3>Registered Passkeys</h3>
                <div id="metis-profile-passkeys-list" class="metis-profile-passkey-list">
                    <?php if (!empty($passkeys)) : ?>
                        <?php foreach ($passkeys as $passkey) : ?>
                            <div class="metis-profile-passkey-item" data-passkey-id="<?php echo esc_attr((string) ($passkey['id'] ?? '')); ?>">
                                <div><strong><?php echo esc_html((string) ($passkey['label'] ?? 'Passkey')); ?></strong></div>
                                <div class="mw-muted">Created: <?php echo esc_html((string) ($passkey['created_at'] ?? '')); ?></div>
                                <div class="mw-muted">Last used: <?php echo esc_html((string) ($passkey['last_used_at'] ?? 'Never')); ?></div>
                                <div class="metis-people-mini-actions"><button type="button" class="mw-btn-xs mw-btn-danger metis-profile-passkey-revoke" data-id="<?php echo esc_attr((string) ($passkey['id'] ?? '')); ?>">Revoke</button></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="mw-muted" id="metis-profile-passkeys-empty">No passkeys registered.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="metis-contact-card">
                <h3>Password</h3>
                <form id="metis-profile-password-form" class="metis-contact-form">
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-profile-new-password">New Password</label>
                        <input type="password" id="metis-profile-new-password" class="mw-input" autocomplete="new-password" minlength="12">
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-profile-confirm-password">Confirm Password</label>
                        <input type="password" id="metis-profile-confirm-password" class="mw-input" autocomplete="new-password" minlength="12">
                    </div>
                    <div class="metis-contact-field metis-contact-field-full">
                        <div class="mw-muted">Updates your Google Workspace password directly.</div>
                    </div>
                    <div class="metis-contact-actions">
                        <button type="submit" class="mw-btn">Update Password</button>
                    </div>
                </form>
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
                    <div class="metis-profile-notification-row" data-event="<?php echo esc_attr($event_key); ?>">
                        <div class="metis-profile-notification-title"><?php echo esc_html($event_label); ?></div>
                        <label><input type="checkbox" class="metis-profile-notify-pref" data-event="<?php echo esc_attr($event_key); ?>" data-channel="email" <?php checked(!empty($event_cfg['email'])); ?>> Email</label>
                        <label><input type="checkbox" class="metis-profile-notify-pref" data-event="<?php echo esc_attr($event_key); ?>" data-channel="in_app" <?php checked(!empty($event_cfg['in_app'])); ?>> In-App</label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="metis-contact-actions">
                    <button type="button" class="mw-btn" id="metis-profile-save-notifications">Save Notifications</button>
                </div>
            </section>
        </div>
    </div>

    <div class="metis-contacts-modal" id="metis-profile-avatar-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-profile-modal-inner">
            <h3>Update Photo</h3>
            <div class="metis-contact-form">
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-profile-avatar-file">Image</label>
                    <input type="file" id="metis-profile-avatar-file" class="mw-input" accept="image/png,image/jpeg">
                </div>
                <div class="metis-contact-field metis-contact-field-full">
                    <canvas id="metis-profile-avatar-canvas" width="240" height="240"></canvas>
                </div>
                <div class="metis-contact-field metis-contact-field-third">
                    <label for="metis-profile-avatar-zoom">Zoom</label>
                    <input id="metis-profile-avatar-zoom" class="mw-input" type="range" min="0.5" max="3" step="0.05" value="1">
                </div>
                <div class="metis-contact-field metis-contact-field-third">
                    <label for="metis-profile-avatar-offset-x">X</label>
                    <input id="metis-profile-avatar-offset-x" class="mw-input" type="range" min="-200" max="200" step="1" value="0">
                </div>
                <div class="metis-contact-field metis-contact-field-third">
                    <label for="metis-profile-avatar-offset-y">Y</label>
                    <input id="metis-profile-avatar-offset-y" class="mw-input" type="range" min="-200" max="200" step="1" value="0">
                </div>
                <div class="metis-contact-actions">
                    <button type="button" class="mw-btn mw-btn-ghost" id="metis-profile-avatar-cancel">Cancel</button>
                    <button type="button" class="mw-btn" id="metis-profile-avatar-save">Save Photo</button>
                </div>
            </div>
        </div>
    </div>

    <div class="metis-contacts-modal" id="metis-profile-totp-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-profile-modal-inner">
            <h3>Set Up Authenticator App</h3>
            <div class="metis-contact-form">
                <div class="metis-contact-field metis-contact-field-full">
                    <button type="button" class="mw-btn-xs" id="metis-profile-totp-generate">Generate Key</button>
                </div>
                <div class="metis-contact-field metis-contact-field-full">
                    <img id="metis-profile-totp-qr" src="" alt="Authenticator QR" style="display:none; width:160px; height:160px;" />
                </div>
                <div class="metis-contact-field metis-contact-field-full">
                    <label>Secret Key</label>
                    <div id="metis-profile-totp-secret" class="mw-chip">Generate a key to begin.</div>
                </div>
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-profile-totp-uri">Provisioning URI</label>
                    <textarea id="metis-profile-totp-uri" class="mw-input" rows="2" readonly></textarea>
                </div>
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-profile-totp-code">Enter 6-digit code</label>
                    <input type="text" id="metis-profile-totp-code" class="mw-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="123456">
                </div>
                <div class="metis-contact-actions">
                    <button type="button" class="mw-btn mw-btn-ghost" id="metis-profile-totp-cancel">Cancel</button>
                    <button type="button" class="mw-btn" id="metis-profile-totp-verify">Verify and Enable</button>
                </div>
            </div>
        </div>
    </div>

    <div class="metis-contacts-modal" id="metis-profile-passkey-label-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-profile-modal-inner">
            <h3>Name This Passkey</h3>
            <div class="metis-contact-form">
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-profile-passkey-label">Label</label>
                    <input id="metis-profile-passkey-label" class="mw-input" type="text" placeholder="My Laptop">
                </div>
                <div class="metis-contact-actions">
                    <button type="button" class="mw-btn mw-btn-ghost" id="metis-profile-passkey-label-cancel">Cancel</button>
                    <button type="button" class="mw-btn" id="metis-profile-passkey-label-save">Save Passkey</button>
                </div>
            </div>
        </div>
    </div>
</div>
