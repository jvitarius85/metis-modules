<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'metis_settings_normalize_drive_rows' ) ) {
    function metis_settings_normalize_drive_rows( $rows ): array {
        $normalized = [];
        if ( ! is_array( $rows ) ) {
            return $normalized;
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $drive_id = sanitize_text_field( $row['drive_id'] ?? '' );
            if ( $drive_id === '' ) {
                continue;
            }
            $normalized[] = [
                'label' => sanitize_text_field( $row['label'] ?? '' ),
                'drive_id' => $drive_id,
                'drive_name' => sanitize_text_field( $row['drive_name'] ?? '' ),
                'is_default' => ! empty( $row['is_default'] ) ? 1 : 0,
                'is_users_home' => ! empty( $row['is_users_home'] ) ? 1 : 0,
            ];
        }
        if ( count( $normalized ) === 1 ) {
            $normalized[0]['is_default'] = 1;
        }
        $has_default = false;
        foreach ( $normalized as $index => $row ) {
            if ( ! empty( $row['is_default'] ) && ! $has_default ) {
                $normalized[ $index ]['is_default'] = 1;
                $has_default = true;
            } else {
                $normalized[ $index ]['is_default'] = 0;
            }
        }
        if ( ! $has_default && ! empty( $normalized ) ) {
            $normalized[0]['is_default'] = 1;
        }
        return $normalized;
    }
}

if ( ! function_exists( 'metis_settings_normalize_calendar_rows' ) ) {
    function metis_settings_normalize_calendar_rows( $rows ): array {
        $normalized = [];
        if ( ! is_array( $rows ) ) {
            return $normalized;
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $calendar_id = sanitize_text_field( $row['calendar_id'] ?? '' );
            if ( $calendar_id === '' ) {
                continue;
            }
            $normalized[] = [
                'label' => sanitize_text_field( $row['label'] ?? '' ),
                'calendar_id' => $calendar_id,
                'calendar_name' => sanitize_text_field( $row['calendar_name'] ?? '' ),
                'is_default' => ! empty( $row['is_default'] ) ? 1 : 0,
            ];
        }
        if ( count( $normalized ) === 1 ) {
            $normalized[0]['is_default'] = 1;
        }
        $has_default = false;
        foreach ( $normalized as $index => $row ) {
            if ( ! empty( $row['is_default'] ) && ! $has_default ) {
                $normalized[ $index ]['is_default'] = 1;
                $has_default = true;
            } else {
                $normalized[ $index ]['is_default'] = 0;
            }
        }
        if ( ! $has_default && ! empty( $normalized ) ) {
            $normalized[0]['is_default'] = 1;
        }
        return $normalized;
    }
}

$can_admin_settings = metis_current_user_can( 'manage_options' ) || ( function_exists( 'metis_people_can_manage' ) && metis_people_can_manage() );
if ( ! $can_admin_settings ) {
    return;
}

$is_system_admin = metis_current_user_can( 'manage_options' );

metis_install_db();
$can_manage = metis_people_can_manage();
$saved  = false;
$errors = [];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset( $_POST['metis_settings_nonce'] )
    && metis_verify_nonce( $_POST['metis_settings_nonce'], 'metis_save_portal_settings' )
) {
    $portal_slug = sanitize_title( $_POST['portal_slug'] ?? '' );
    if ( $portal_slug !== '' ) {
        Core_Settings_Service::set( 'portal_slug', $portal_slug );
        flush_rewrite_rules();
        $saved = true;
    }

    $portal_name = sanitize_text_field( $_POST['portal_name'] ?? '' );
    if ( $portal_name !== '' ) {
        Core_Settings_Service::set( 'portal_name', $portal_name );
        $saved = true;
    }

    $org_name = sanitize_text_field( $_POST['org_name'] ?? '' );
    if ( $org_name !== '' ) {
        Core_Settings_Service::set( 'org_name', $org_name );
        $saved = true;
    }

    $profile_allow_name_edit = ! empty( $_POST['profile_allow_name_edit'] ) ? '1' : '0';
    Core_Settings_Service::set( 'profile_allow_name_edit', $profile_allow_name_edit );
    $saved = true;

    $workspace_customer_id = sanitize_text_field( $_POST['workspace_customer_id'] ?? '' );
    Core_Settings_Service::set( 'workspace_customer_id', $workspace_customer_id, false );
    $saved = true;

    $workspace_domain = sanitize_text_field( $_POST['workspace_domain'] ?? '' );
    if ( $workspace_domain !== '' ) {
        $workspace_domain = strtolower( preg_replace( '/[^a-z0-9\.\-]/', '', $workspace_domain ) );
    }
    Core_Settings_Service::set( 'workspace_domain', $workspace_domain, false );
    $saved = true;

    $workspace_stripe_sso_schema = sanitize_text_field( $_POST['workspace_stripe_sso_schema'] ?? '' );
    Core_Settings_Service::set( 'workspace_stripe_sso_schema', (string) $workspace_stripe_sso_schema, false );
    $saved = true;

    $workspace_stripe_sso_field = sanitize_text_field( $_POST['workspace_stripe_sso_field'] ?? '' );
    Core_Settings_Service::set( 'workspace_stripe_sso_field', (string) $workspace_stripe_sso_field, false );
    $saved = true;

    $workspace_stripe_access_group_email = sanitize_email( $_POST['workspace_stripe_access_group_email'] ?? '' );
    if ( $workspace_stripe_access_group_email !== '' && ! is_email( $workspace_stripe_access_group_email ) ) {
        $errors[] = 'Workspace Stripe access group must be a valid email.';
    } else {
        Core_Settings_Service::set( 'workspace_stripe_access_group_email', strtolower( (string) $workspace_stripe_access_group_email ), false );
        $saved = true;
    }

    $newsletter_default_from_name = sanitize_text_field( $_POST['newsletter_default_from_name'] ?? '' );
    Core_Settings_Service::set( 'newsletter_default_from_name', $newsletter_default_from_name, true );
    $saved = true;

    $newsletter_default_from_email = sanitize_email( $_POST['newsletter_default_from_email'] ?? '' );
    if ( $newsletter_default_from_email !== '' && ! is_email( $newsletter_default_from_email ) ) {
        $errors[] = 'Newsletter default from email must be a valid email.';
    } else {
        Core_Settings_Service::set( 'newsletter_default_from_email', strtolower( (string) $newsletter_default_from_email ), true );
        $saved = true;
    }

    $newsletter_default_reply_to = sanitize_email( $_POST['newsletter_default_reply_to'] ?? '' );
    if ( $newsletter_default_reply_to !== '' && ! is_email( $newsletter_default_reply_to ) ) {
        $errors[] = 'Newsletter default reply-to must be a valid email.';
    } else {
        Core_Settings_Service::set( 'newsletter_default_reply_to', strtolower( (string) $newsletter_default_reply_to ), true );
        $saved = true;
    }

    $newsletter_google_daily_limit = (int) ( $_POST['newsletter_google_daily_limit'] ?? 2000 );
    if ( $newsletter_google_daily_limit < 100 ) {
        $newsletter_google_daily_limit = 100;
    } elseif ( $newsletter_google_daily_limit > 100000 ) {
        $newsletter_google_daily_limit = 100000;
    }
    Core_Settings_Service::set( 'newsletter_google_daily_limit', (string) $newsletter_google_daily_limit, true );
    $newsletter_klipy_api_key = sanitize_text_field( $_POST['newsletter_klipy_api_key'] ?? '' );
    Core_Settings_Service::set( 'newsletter_klipy_api_key', (string) $newsletter_klipy_api_key, false );
    $newsletter_klipy_search_url = esc_url_raw( (string) ( $_POST['newsletter_klipy_search_url'] ?? '' ) );
    if ( $newsletter_klipy_search_url === '' ) {
        $newsletter_klipy_search_url = 'https://api.klipy.com/v1/gifs/search';
    }
    Core_Settings_Service::set( 'newsletter_klipy_search_url', (string) $newsletter_klipy_search_url, true );
    $saved = true;

    $submitted_sensitive = false;
    foreach ( [ 'stripe_secret', 'stripe_webhook_secret', 'workspace_impersonation_admin', 'workspace_service_account_json' ] as $sensitive_key ) {
        if ( isset( $_POST[ $sensitive_key ] ) && trim( (string) metis_unslash( $_POST[ $sensitive_key ] ) ) !== '' ) {
            $submitted_sensitive = true;
            break;
        }
    }

    if ( $is_system_admin ) {
        $stripe_secret = sanitize_text_field( $_POST['stripe_secret'] ?? '' );
        if ( $stripe_secret !== '' ) {
            if ( ! str_starts_with( $stripe_secret, 'sk_' ) ) {
                $errors[] = 'Stripe secret key must begin with <code>sk_</code>.';
            } else {
                Core_Settings_Service::set( 'stripe_secret', $stripe_secret, false );
                $saved = true;
            }
        }

        $webhook_secret = sanitize_text_field( $_POST['stripe_webhook_secret'] ?? '' );
        if ( $webhook_secret !== '' ) {
            if ( ! str_starts_with( $webhook_secret, 'whsec_' ) ) {
                $errors[] = 'Webhook secret must begin with <code>whsec_</code>.';
            } else {
                Core_Settings_Service::set( 'stripe_webhook_secret', $webhook_secret, false );
                $saved = true;
            }
        }

        $workspace_impersonation_admin = sanitize_email( $_POST['workspace_impersonation_admin'] ?? '' );
        if ( $workspace_impersonation_admin !== '' ) {
            if ( ! is_email( $workspace_impersonation_admin ) ) {
                $errors[] = 'Workspace impersonation admin must be a valid email.';
            } else {
                Core_Settings_Service::set( 'workspace_impersonation_admin', strtolower( $workspace_impersonation_admin ), false );
                $saved = true;
            }
        }

        $workspace_service_account_json = trim( (string) metis_unslash( $_POST['workspace_service_account_json'] ?? '' ) );
        if ( $workspace_service_account_json !== '' ) {
            $decoded = json_decode( $workspace_service_account_json, true );
            if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) || empty( $decoded['token_uri'] ) ) {
                $errors[] = 'Workspace service account JSON is invalid or missing required keys.';
            } else {
                Core_Settings_Service::set( 'workspace_service_account_json', $workspace_service_account_json, false );
                $saved = true;
            }
        }

        $workspace_drive_configs = metis_settings_normalize_drive_rows( metis_unslash( $_POST['workspace_drive_configs'] ?? [] ) );
        Core_Settings_Service::set( 'workspace_drive_configs', $workspace_drive_configs, false );
        $default_drive = '';
        foreach ( $workspace_drive_configs as $row ) {
            if ( ! empty( $row['is_default'] ) ) {
                $default_drive = (string) $row['drive_id'];
                break;
            }
        }
        Core_Settings_Service::set( 'workspace_shared_drive_id', $default_drive, false );
        $saved = true;

        $workspace_calendar_configs = metis_settings_normalize_calendar_rows( metis_unslash( $_POST['workspace_calendar_configs'] ?? [] ) );
        Core_Settings_Service::set( 'workspace_calendar_configs', $workspace_calendar_configs, false );
        $default_calendar = '';
        foreach ( $workspace_calendar_configs as $row ) {
            if ( ! empty( $row['is_default'] ) ) {
                $default_calendar = (string) $row['calendar_id'];
                break;
            }
        }
        Core_Settings_Service::set( 'workspace_default_calendar_id', $default_calendar, false );
        $saved = true;
    } elseif ( $submitted_sensitive ) {
        $errors[] = 'Only system admins can update API keys and service account credentials.';
    }
}

$stripe_secret      = Core_Settings_Service::get( 'stripe_secret',         '' );
$webhook_secret     = Core_Settings_Service::get( 'stripe_webhook_secret', '' );
$portal_slug        = Core_Settings_Service::get( 'portal_slug',           'mw-portal' );
$portal_name        = Core_Settings_Service::get( 'portal_name',           '' );
$org_name           = Core_Settings_Service::get( 'org_name',              '' );
$workspace_impersonation_admin = Core_Settings_Service::get( 'workspace_impersonation_admin', '' );
$workspace_customer_id         = Core_Settings_Service::get( 'workspace_customer_id', '' );
$workspace_domain              = Core_Settings_Service::get( 'workspace_domain', '' );
$workspace_shared_drive_id     = Core_Settings_Service::get( 'workspace_shared_drive_id', '' );
$workspace_default_calendar_id = Core_Settings_Service::get( 'workspace_default_calendar_id', '' );
$workspace_drive_configs       = metis_settings_normalize_drive_rows( Core_Settings_Service::get( 'workspace_drive_configs', [] ) );
$workspace_calendar_configs    = metis_settings_normalize_calendar_rows( Core_Settings_Service::get( 'workspace_calendar_configs', [] ) );
$workspace_service_account_json = Core_Settings_Service::get( 'workspace_service_account_json', '' );
$workspace_stripe_sso_schema    = Core_Settings_Service::get( 'workspace_stripe_sso_schema', '' );
$workspace_stripe_sso_field     = Core_Settings_Service::get( 'workspace_stripe_sso_field', '' );
$workspace_stripe_access_group_email = Core_Settings_Service::get( 'workspace_stripe_access_group_email', '' );
$newsletter_default_from_name = Core_Settings_Service::get( 'newsletter_default_from_name', '' );
$newsletter_default_from_email = Core_Settings_Service::get( 'newsletter_default_from_email', '' );
$newsletter_default_reply_to = Core_Settings_Service::get( 'newsletter_default_reply_to', '' );
$newsletter_google_daily_limit = (int) Core_Settings_Service::get( 'newsletter_google_daily_limit', 2000 );
$newsletter_google_daily_limit = $newsletter_google_daily_limit < 100 ? 2000 : $newsletter_google_daily_limit;
$newsletter_klipy_api_key = Core_Settings_Service::get( 'newsletter_klipy_api_key', '' );
$newsletter_klipy_search_url = Core_Settings_Service::get( 'newsletter_klipy_search_url', 'https://api.klipy.com/v1/gifs/search' );
$profile_allow_name_edit = (int) Core_Settings_Service::get( 'profile_allow_name_edit', 0 ) === 1;

$stripe_connected   = is_string( $stripe_secret )  && str_starts_with( $stripe_secret,  'sk_' );
$webhook_configured = is_string( $webhook_secret ) && str_starts_with( $webhook_secret, 'whsec_' );
$workspace_configured = is_string( $workspace_impersonation_admin ) && is_email( $workspace_impersonation_admin )
    && is_string( $workspace_service_account_json ) && trim( $workspace_service_account_json ) !== '';
$workspace_shared_drive_options = [];
$workspace_shared_drive_error = '';
if ( $is_system_admin && $workspace_configured && function_exists( 'metis_drive_workspace_settings' ) && function_exists( 'metis_drive_list_shared_drives' ) ) {
    $drive_cfg = function_exists( 'metis_drive_workspace_base_settings' ) ? metis_drive_workspace_base_settings() : metis_drive_workspace_settings();
    if ( ! empty( $drive_cfg['ok'] ) ) {
        $drive_list = metis_drive_list_shared_drives( $drive_cfg );
        if ( ! empty( $drive_list['ok'] ) ) {
            $workspace_shared_drive_options = (array) ( $drive_list['drives'] ?? [] );
        } else {
            $workspace_shared_drive_error = (string) ( $drive_list['error'] ?? 'Unable to load shared drives.' );
        }
    } else {
        $workspace_shared_drive_error = (string) ( $drive_cfg['error'] ?? 'Drive workspace config is incomplete.' );
    }
}
$workspace_calendar_options = [];
$workspace_calendar_error = '';
if ( $is_system_admin && $workspace_configured && function_exists( 'metis_calendar_workspace_base_settings' ) && function_exists( 'metis_calendar_list_calendars' ) ) {
    $calendar_cfg = metis_calendar_workspace_base_settings();
    if ( ! empty( $calendar_cfg['ok'] ) ) {
        $calendar_list = metis_calendar_list_calendars( $calendar_cfg );
        if ( ! empty( $calendar_list['ok'] ) ) {
            $workspace_calendar_options = (array) ( $calendar_list['calendars'] ?? [] );
        } else {
            $workspace_calendar_error = (string) ( $calendar_list['error'] ?? 'Unable to load calendars.' );
        }
    } else {
        $workspace_calendar_error = (string) ( $calendar_cfg['error'] ?? 'Calendar workspace config is incomplete.' );
    }
}

if ( empty( $workspace_drive_configs ) && $workspace_shared_drive_id !== '' ) {
    $workspace_drive_configs[] = [
        'label' => 'Primary Drive',
        'drive_id' => (string) $workspace_shared_drive_id,
        'drive_name' => '',
        'is_default' => 1,
        'is_users_home' => 0,
    ];
}
if ( empty( $workspace_calendar_configs ) && $workspace_default_calendar_id !== '' ) {
    $workspace_calendar_configs[] = [
        'label' => 'Primary Calendar',
        'calendar_id' => (string) $workspace_default_calendar_id,
        'calendar_name' => '',
        'is_default' => 1,
    ];
}

if ( ! function_exists( 'metis_mask_key' ) ) {
    function metis_mask_key( string $key ): string {
        if ( strlen( $key ) < 8 ) return str_repeat( '•', 20 );
        return str_repeat( '•', 16 ) . substr( $key, -4 );
    }
}
?>

<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Admin controls for Metis and integrations.</p>

<?php if ( $saved && empty( $errors ) ) : ?>
    <div class="mw-alert mw-alert-success">Settings saved.</div>
<?php endif; ?>

<?php if ( ! empty( $errors ) ) : ?>
    <div class="mw-alert mw-alert-error">
        <?php foreach ( $errors as $e ) echo '<p>' . $e . '</p>'; ?>
    </div>
<?php endif; ?>

<form method="post" class="mw-settings-form">
    <?php metis_nonce_field( 'metis_save_portal_settings', 'metis_settings_nonce' ); ?>
<div class="mw-list-layout">
    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <?php if ( metis_people_can_manage('settings','edit' ) ) : ?>
        <div class="mw-list-sidebar-actions">
        <button type="button" class="mw-btn-xs metis-profile-tab-btn is-active" data-settings-tab="general">General</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-settings-tab="profile">Profile</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-settings-tab="newsletter">Newsletter</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-settings-tab="workspace">Workspace</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-settings-tab="drive">Drive</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-settings-tab="calendar">Calendar</button>
        <button type="button" class="mw-btn-xs metis-profile-tab-btn" data-settings-tab="api">API Keys</button>
        </div>
        <?php endif; ?>
    </aside>
    
    <div class="mw-list-content">
    <div class="metis-settings-panel is-active" data-settings-panel="general">
        <div class="mw-settings-card">
            <div class="mw-settings-header"><h2>Portal</h2></div>
            <div class="mw-settings-body">
                <div class="mw-field">
                    <label for="portal_slug">Portal URL Slug</label>
                    <input type="text" id="portal_slug" name="portal_slug" class="mw-input mw-input-wide" value="<?php echo esc_attr( $portal_slug ); ?>" placeholder="mw-portal">
                </div>
                <div class="mw-field">
                    <label for="portal_name">Portal Display Name</label>
                    <input type="text" id="portal_name" name="portal_name" class="mw-input mw-input-wide" value="<?php echo esc_attr( $portal_name ); ?>" placeholder="Metis Portal">
                </div>
                <div class="mw-field">
                    <label for="org_name">Organization Name</label>
                    <input type="text" id="org_name" name="org_name" class="mw-input mw-input-wide" value="<?php echo esc_attr( $org_name ); ?>" placeholder="Mobilize Waco">
                </div>
            </div>
        </div>
    </div>

    <div class="metis-settings-panel" data-settings-panel="profile">
        <div class="mw-settings-card">
            <div class="mw-settings-header"><h2>Profile Policies</h2></div>
            <div class="mw-settings-body">
                <div class="mw-field">
                    <label class="metis-people-check">
                        <input type="checkbox" name="profile_allow_name_edit" value="1" <?php checked( $profile_allow_name_edit ); ?>>
                        Allow users to edit first/last/display name in Profile
                    </label>
                    <p class="mw-help">If disabled, name fields in self-service Profile are read-only.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="metis-settings-panel" data-settings-panel="newsletter">
        <div class="mw-settings-card">
            <div class="mw-settings-header"><h2>Newsletter Defaults</h2></div>
            <div class="mw-settings-body">
                <div class="mw-field">
                    <label for="newsletter_default_from_name">Default From Name</label>
                    <input type="text" id="newsletter_default_from_name" name="newsletter_default_from_name" class="mw-input mw-input-wide" maxlength="191" value="<?php echo esc_attr( (string) $newsletter_default_from_name ); ?>">
                </div>
                <div class="mw-field">
                    <label for="newsletter_default_from_email">Default From Email</label>
                    <input type="email" id="newsletter_default_from_email" name="newsletter_default_from_email" class="mw-input mw-input-wide" maxlength="191" value="<?php echo esc_attr( (string) $newsletter_default_from_email ); ?>" autocomplete="off">
                </div>
                <div class="mw-field">
                    <label for="newsletter_default_reply_to">Default Reply-To</label>
                    <input type="email" id="newsletter_default_reply_to" name="newsletter_default_reply_to" class="mw-input mw-input-wide" maxlength="191" value="<?php echo esc_attr( (string) $newsletter_default_reply_to ); ?>" autocomplete="off">
                </div>
                <div class="mw-field">
                    <label for="newsletter_google_daily_limit">Google Daily Send Cap</label>
                    <input type="number" id="newsletter_google_daily_limit" name="newsletter_google_daily_limit" class="mw-input mw-input-wide" min="100" max="100000" step="1" value="<?php echo esc_attr( (string) $newsletter_google_daily_limit ); ?>">
                    <p class="mw-help">Used by Newsletter usage monitoring to warn as you approach the configured send limit.</p>
                </div>
                <div class="mw-field">
                    <label for="newsletter_klipy_api_key">Klipy API Key</label>
                    <input type="password" id="newsletter_klipy_api_key" name="newsletter_klipy_api_key" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $newsletter_klipy_api_key ); ?>" autocomplete="new-password" placeholder="Paste your Klipy API key">
                    <p class="mw-help">Used by the Klipy block and GIF search in the newsletter editor.</p>
                </div>
                <div class="mw-field">
                    <label for="newsletter_klipy_search_url">Klipy Search Endpoint</label>
                    <input type="url" id="newsletter_klipy_search_url" name="newsletter_klipy_search_url" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $newsletter_klipy_search_url ); ?>" autocomplete="off" placeholder="https://api.klipy.com/...">
                    <p class="mw-help">Search endpoint used by the Klipy picker. Defaults to <code>https://api.klipy.com/v1/gifs/search</code>.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="metis-settings-panel" data-settings-panel="workspace">
        <div class="mw-settings-card">
            <div class="mw-settings-header">
                <h2>Google Workspace</h2>
                <span class="mw-settings-status <?php echo $workspace_configured ? 'is-ok' : 'is-missing'; ?>"><?php echo $workspace_configured ? 'Configured' : 'Not Configured'; ?></span>
            </div>
            <div class="mw-settings-body">
                <div class="mw-field">
                    <label for="workspace_customer_id">Workspace Customer ID (optional)</label>
                    <input type="text" id="workspace_customer_id" name="workspace_customer_id" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $workspace_customer_id ); ?>" autocomplete="off" placeholder="C0123abc4">
                </div>
                <div class="mw-field">
                    <label for="workspace_domain">Primary Workspace Domain</label>
                    <input type="text" id="workspace_domain" name="workspace_domain" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $workspace_domain ); ?>" autocomplete="off" placeholder="mobilizewaco.org">
                </div>
                <div class="mw-field">
                    <label for="workspace_stripe_sso_schema">Workspace Stripe SSO Schema Name</label>
                    <input type="text" id="workspace_stripe_sso_schema" name="workspace_stripe_sso_schema" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $workspace_stripe_sso_schema ); ?>" autocomplete="off" placeholder="SingleSignOn">
                </div>
                <div class="mw-field">
                    <label for="workspace_stripe_sso_field">Workspace Stripe SSO Field Name</label>
                    <input type="text" id="workspace_stripe_sso_field" name="workspace_stripe_sso_field" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $workspace_stripe_sso_field ); ?>" autocomplete="off" placeholder="StripeRole">
                </div>
                <div class="mw-field">
                    <label for="workspace_stripe_access_group_email">Workspace Stripe Access Group Email</label>
                    <input type="email" id="workspace_stripe_access_group_email" name="workspace_stripe_access_group_email" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $workspace_stripe_access_group_email ); ?>" autocomplete="off" placeholder="stripe-access@mobilizewaco.org">
                </div>
            </div>
        </div>
    </div>

    <div class="metis-settings-panel" data-settings-panel="drive">
        <div class="mw-settings-card">
            <div class="mw-settings-header"><h2>Drive Configurations</h2></div>
            <div class="mw-settings-body">
                <?php if ( ! $is_system_admin ) : ?>
                    <div class="mw-callout mw-callout-warning">Only system admins can manage Drive configurations.</div>
                <?php else : ?>
                    <p class="mw-help">Add one or more shared drives. Mark one as the default Drive module source and mark one as the users-home drive.</p>
                    <?php if ( $workspace_shared_drive_error !== '' ) : ?>
                        <p class="mw-help" style="color:#b91c1c;"><?php echo esc_html( $workspace_shared_drive_error ); ?></p>
                    <?php endif; ?>
                    <div class="metis-settings-repeatable" data-repeatable-root="drive">
                        <div class="metis-settings-repeatable-list" data-repeatable-list="drive">
                            <?php foreach ( $workspace_drive_configs as $index => $row ) : ?>
                                <div class="metis-settings-row" data-repeatable-row>
                                    <div class="mw-field">
                                        <label>Label</label>
                                        <input type="text" class="mw-input" name="workspace_drive_configs[<?php echo (int) $index; ?>][label]" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>" placeholder="Board Drive">
                                    </div>
                                    <div class="mw-field">
                                        <label>Shared Drive</label>
                                        <select class="mw-input" name="workspace_drive_configs[<?php echo (int) $index; ?>][drive_id]" data-drive-select>
                                            <option value="">Select shared drive</option>
                                            <?php foreach ( $workspace_shared_drive_options as $opt ) :
                                                $opt_id = (string) ( $opt['id'] ?? '' );
                                                if ( $opt_id === '' ) { continue; }
                                            ?>
                                                <option value="<?php echo esc_attr( $opt_id ); ?>" data-drive-name="<?php echo esc_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>" <?php selected( (string) ( $row['drive_id'] ?? '' ), $opt_id ); ?>>
                                                    <?php echo esc_html( (string) ( $opt['name'] ?? $opt_id ) ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="workspace_drive_configs[<?php echo (int) $index; ?>][drive_name]" value="<?php echo esc_attr( (string) ( $row['drive_name'] ?? '' ) ); ?>" data-drive-name-input>
                                    </div>
                                    <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[<?php echo (int) $index; ?>][is_default]" value="1" <?php checked( ! empty( $row['is_default'] ) ); ?>> Default</label>
                                    <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[<?php echo (int) $index; ?>][is_users_home]" value="1" <?php checked( ! empty( $row['is_users_home'] ) ); ?>> Users folder</label>
                                    <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="mw-btn mw-btn-secondary mw-btn-xs" data-repeatable-add="drive">Add Drive</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="metis-settings-panel" data-settings-panel="calendar">
        <div class="mw-settings-card">
            <div class="mw-settings-header"><h2>Calendar Configurations</h2></div>
            <div class="mw-settings-body">
                <?php if ( ! $is_system_admin ) : ?>
                    <div class="mw-callout mw-callout-warning">Only system admins can manage Calendar configurations.</div>
                <?php else : ?>
                    <p class="mw-help">Add one or more calendars. Mark one as the default Calendar module source.</p>
                    <?php if ( $workspace_calendar_error !== '' ) : ?>
                        <p class="mw-help" style="color:#b91c1c;"><?php echo esc_html( $workspace_calendar_error ); ?></p>
                    <?php endif; ?>
                    <div class="metis-settings-repeatable" data-repeatable-root="calendar">
                        <div class="metis-settings-repeatable-list" data-repeatable-list="calendar">
                            <?php foreach ( $workspace_calendar_configs as $index => $row ) : ?>
                                <div class="metis-settings-row" data-repeatable-row>
                                    <div class="mw-field">
                                        <label>Label</label>
                                        <input type="text" class="mw-input" name="workspace_calendar_configs[<?php echo (int) $index; ?>][label]" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>" placeholder="Board Calendar">
                                    </div>
                                    <div class="mw-field">
                                        <label>Calendar</label>
                                        <select class="mw-input" name="workspace_calendar_configs[<?php echo (int) $index; ?>][calendar_id]" data-calendar-select>
                                            <option value="">Select calendar</option>
                                            <?php foreach ( $workspace_calendar_options as $opt ) :
                                                $opt_id = (string) ( $opt['id'] ?? '' );
                                                if ( $opt_id === '' ) { continue; }
                                            ?>
                                                <option value="<?php echo esc_attr( $opt_id ); ?>" data-calendar-name="<?php echo esc_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>" <?php selected( (string) ( $row['calendar_id'] ?? '' ), $opt_id ); ?>>
                                                    <?php echo esc_html( (string) ( $opt['name'] ?? $opt_id ) ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="workspace_calendar_configs[<?php echo (int) $index; ?>][calendar_name]" value="<?php echo esc_attr( (string) ( $row['calendar_name'] ?? '' ) ); ?>" data-calendar-name-input>
                                    </div>
                                    <label class="metis-settings-flag"><input type="checkbox" name="workspace_calendar_configs[<?php echo (int) $index; ?>][is_default]" value="1" <?php checked( ! empty( $row['is_default'] ) ); ?>> Default</label>
                                    <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="mw-btn mw-btn-secondary mw-btn-xs" data-repeatable-add="calendar">Add Calendar</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="metis-settings-panel" data-settings-panel="api">
        <div class="mw-settings-card">
            <div class="mw-settings-header">
                <h2>API Keys and Credentials</h2>
                <span class="mw-settings-status <?php echo $is_system_admin ? 'is-ok' : 'is-missing'; ?>"><?php echo $is_system_admin ? 'System Admin' : 'Restricted'; ?></span>
            </div>
            <div class="mw-settings-body">
                <?php if ( ! $is_system_admin ) : ?>
                    <div class="mw-callout mw-callout-warning">Only system admins can view or update API keys and service account credentials.</div>
                <?php else : ?>
                    <div class="mw-field">
                        <label for="stripe_secret">Stripe Secret Key</label>
                        <input type="password" id="stripe_secret" name="stripe_secret" class="mw-input mw-input-wide" autocomplete="new-password" placeholder="<?php echo $stripe_connected ? esc_attr( metis_mask_key( $stripe_secret ) ) : 'sk_live_••••••••••'; ?>">
                    </div>
                    <div class="mw-field">
                        <label for="stripe_webhook_secret">Stripe Webhook Signing Secret</label>
                        <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" class="mw-input mw-input-wide" autocomplete="new-password" placeholder="<?php echo $webhook_configured ? esc_attr( metis_mask_key( $webhook_secret ) ) : 'whsec_••••••••••'; ?>">
                    </div>
                    <div class="mw-field">
                        <label for="workspace_impersonation_admin">Workspace Impersonation Admin (Breakglass)</label>
                        <input type="email" id="workspace_impersonation_admin" name="workspace_impersonation_admin" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $workspace_impersonation_admin ); ?>" autocomplete="off" placeholder="admin@yourdomain.org">
                    </div>
                    <div class="mw-field">
                        <label for="workspace_service_account_json">Workspace Service Account JSON</label>
                        <textarea id="workspace_service_account_json" name="workspace_service_account_json" class="mw-input mw-input-wide" rows="8" autocomplete="off" spellcheck="false" placeholder='{"type":"service_account","client_email":"...","private_key":"...","token_uri":"https://oauth2.googleapis.com/token"}'></textarea>
                    </div>
                    <div class="mw-field">
                        <label>Webhook Endpoint URL</label>
                        <div class="mw-shortcode-wrap">
                            <code class="mw-shortcode" id="mw-webhook-url"><?php echo esc_html( metis_webhook_url( 'stripe' ) ); ?></code>
                            <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-copy-webhook-url">Copy</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Settings</button>
    </div>
</div></div>
</form>
<script>
(function () {
    const tabButtons = Array.from(document.querySelectorAll('[data-settings-tab]'));
    const tabPanels = Array.from(document.querySelectorAll('[data-settings-panel]'));
    function activate(tab) {
        tabButtons.forEach(function (btn) { btn.classList.toggle('is-active', String(btn.dataset.settingsTab || '') === tab); });
        tabPanels.forEach(function (panel) { panel.classList.toggle('is-active', String(panel.dataset.settingsPanel || '') === tab); });
    }
    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () { activate(String(btn.dataset.settingsTab || 'general')); });
    });

    const copyBtn = document.getElementById('mw-copy-webhook-url');
    if (!copyBtn) return;
    copyBtn.addEventListener('click', function () {
        const urlEl = document.getElementById('mw-webhook-url');
        if (!urlEl) return;
        const url = String(urlEl.textContent || '').trim();
        navigator.clipboard.writeText(url).then(() => {
            copyBtn.textContent = 'Copied!';
            setTimeout(() => { copyBtn.textContent = 'Copy'; }, 2000);
        }).catch(() => {});
    });

    function syncRowNames(root) {
        root.querySelectorAll('[data-repeatable-row]').forEach(function (row) {
            const driveSelect = row.querySelector('[data-drive-select]');
            const driveNameInput = row.querySelector('[data-drive-name-input]');
            if (driveSelect && driveNameInput) {
                const opt = driveSelect.options[driveSelect.selectedIndex];
                driveNameInput.value = opt ? String(opt.dataset.driveName || '') : '';
            }
            const calendarSelect = row.querySelector('[data-calendar-select]');
            const calendarNameInput = row.querySelector('[data-calendar-name-input]');
            if (calendarSelect && calendarNameInput) {
                const opt = calendarSelect.options[calendarSelect.selectedIndex];
                calendarNameInput.value = opt ? String(opt.dataset.calendarName || '') : '';
            }
        });
    }

    function reindexRows(rootName) {
        const list = document.querySelector('[data-repeatable-list="' + rootName + '"]');
        if (!list) return;
        Array.from(list.querySelectorAll('[data-repeatable-row]')).forEach(function (row, index) {
            row.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (!field.name) return;
                field.name = field.name.replace(new RegExp(rootName === 'drive' ? 'workspace_drive_configs\\[(\\d+)\\]' : 'workspace_calendar_configs\\[(\\d+)\\]'), (rootName === 'drive' ? 'workspace_drive_configs[' : 'workspace_calendar_configs[') + index + ']');
            });
        });
        syncRowNames(list);
    }

    function createDriveRow(index) {
        const wrap = document.createElement('div');
        wrap.className = 'metis-settings-row';
        wrap.setAttribute('data-repeatable-row', '');
        wrap.innerHTML = `
            <div class="mw-field">
                <label>Label</label>
                <input type="text" class="mw-input" name="workspace_drive_configs[${index}][label]" placeholder="Board Drive">
            </div>
            <div class="mw-field">
                <label>Shared Drive</label>
                <select class="mw-input" name="workspace_drive_configs[${index}][drive_id]" data-drive-select>
                    <option value="">Select shared drive</option>
                    <?php foreach ( $workspace_shared_drive_options as $opt ) :
                        $opt_id = (string) ( $opt['id'] ?? '' );
                        if ( $opt_id === '' ) { continue; }
                    ?>
                    <option value="<?php echo esc_attr( $opt_id ); ?>" data-drive-name="<?php echo esc_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>"><?php echo esc_html( (string) ( $opt['name'] ?? $opt_id ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="workspace_drive_configs[${index}][drive_name]" value="" data-drive-name-input>
            </div>
            <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[${index}][is_default]" value="1"> Default</label>
            <label class="metis-settings-flag"><input type="checkbox" name="workspace_drive_configs[${index}][is_users_home]" value="1"> Users folder</label>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
        `;
        return wrap;
    }

    function createCalendarRow(index) {
        const wrap = document.createElement('div');
        wrap.className = 'metis-settings-row';
        wrap.setAttribute('data-repeatable-row', '');
        wrap.innerHTML = `
            <div class="mw-field">
                <label>Label</label>
                <input type="text" class="mw-input" name="workspace_calendar_configs[${index}][label]" placeholder="Board Calendar">
            </div>
            <div class="mw-field">
                <label>Calendar</label>
                <select class="mw-input" name="workspace_calendar_configs[${index}][calendar_id]" data-calendar-select>
                    <option value="">Select calendar</option>
                    <?php foreach ( $workspace_calendar_options as $opt ) :
                        $opt_id = (string) ( $opt['id'] ?? '' );
                        if ( $opt_id === '' ) { continue; }
                    ?>
                    <option value="<?php echo esc_attr( $opt_id ); ?>" data-calendar-name="<?php echo esc_attr( (string) ( $opt['name'] ?? $opt_id ) ); ?>"><?php echo esc_html( (string) ( $opt['name'] ?? $opt_id ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="workspace_calendar_configs[${index}][calendar_name]" value="" data-calendar-name-input>
            </div>
            <label class="metis-settings-flag"><input type="checkbox" name="workspace_calendar_configs[${index}][is_default]" value="1"> Default</label>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
        `;
        return wrap;
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-drive-select]')) {
            const row = event.target.closest('[data-repeatable-row]');
            if (row) syncRowNames(row.parentElement);
        }
        if (event.target.matches('[data-calendar-select]')) {
            const row = event.target.closest('[data-repeatable-row]');
            if (row) syncRowNames(row.parentElement);
        }
    });

    document.addEventListener('click', function (event) {
        const add = event.target.closest('[data-repeatable-add]');
        if (add) {
            const type = String(add.getAttribute('data-repeatable-add') || '');
            const list = document.querySelector('[data-repeatable-list="' + type + '"]');
            if (!list) return;
            const index = list.querySelectorAll('[data-repeatable-row]').length;
            list.appendChild(type === 'drive' ? createDriveRow(index) : createCalendarRow(index));
            return;
        }
        const remove = event.target.closest('[data-repeatable-remove]');
        if (remove) {
            const list = remove.closest('.metis-settings-repeatable-list');
            const row = remove.closest('[data-repeatable-row]');
            if (!list || !row) return;
            row.remove();
            const type = list.getAttribute('data-repeatable-list');
            if (type) reindexRows(type);
        }
    });

    document.querySelectorAll('[data-repeatable-list]').forEach(function (list) {
        syncRowNames(list);
    });
})();
</script>
