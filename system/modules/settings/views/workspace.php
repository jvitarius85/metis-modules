<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'google_workspace' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Manage Google Workspace sync and Stripe group settings.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'google_workspace' ); ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="google_workspace">
    <?php metis_runtime_nonce_field( 'metis_save_settings_google_workspace', 'metis_settings_nonce' ); ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header">
            <h2>Google Workspace</h2>
            <span class="metis-settings-status <?php echo $workspace_configured ? 'is-ok' : 'is-missing'; ?>"><?php echo $workspace_configured ? 'Configured' : 'Not Configured'; ?></span>
        </div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label for="workspace_customer_id">Workspace Customer ID (optional)</label>
                <input type="text" id="workspace_customer_id" name="workspace_customer_id" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_customer_id ); ?>" autocomplete="off" placeholder="C0123abc4">
            </div>
            <div class="metis-field">
                <label for="workspace_domain">Primary Workspace Domain</label>
                <input type="text" id="workspace_domain" name="workspace_domain" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_domain ); ?>" autocomplete="off" placeholder="mobilizewaco.org">
            </div>
            <div class="metis-field">
                <label for="workspace_stripe_sso_schema">Workspace Stripe SSO Schema Name</label>
                <input type="text" id="workspace_stripe_sso_schema" name="workspace_stripe_sso_schema" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_stripe_sso_schema ); ?>" autocomplete="off" placeholder="SingleSignOn">
            </div>
            <div class="metis-field">
                <label for="workspace_stripe_sso_field">Workspace Stripe SSO Field Name</label>
                <input type="text" id="workspace_stripe_sso_field" name="workspace_stripe_sso_field" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_stripe_sso_field ); ?>" autocomplete="off" placeholder="StripeRole">
            </div>
            <div class="metis-field">
                <label for="workspace_stripe_access_group_email">Workspace Stripe Access Group Email</label>
                <input type="email" id="workspace_stripe_access_group_email" name="workspace_stripe_access_group_email" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_stripe_access_group_email ); ?>" autocomplete="off" placeholder="stripe-access@mobilizewaco.org">
            </div>
        </div>
    </div>
    <div class="metis-settings-card">
        <div class="metis-settings-header">
            <h2>Google SSO</h2>
            <span class="metis-settings-status <?php echo $workspace_google_sso_configured ? 'is-ok' : 'is-missing'; ?>"><?php echo $workspace_google_sso_configured ? 'Configured' : 'Not Configured'; ?></span>
        </div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label for="workspace_google_sso_client_id">OAuth Client ID</label>
                <input type="text" id="workspace_google_sso_client_id" name="workspace_google_sso_client_id" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_google_sso_client_id ); ?>" autocomplete="off" placeholder="1234567890-xxxxx.apps.googleusercontent.com">
            </div>
            <div class="metis-field">
                <label for="workspace_google_sso_client_secret_credential_id">OAuth Client Secret Credential</label>
                <select id="workspace_google_sso_client_secret_credential_id" name="workspace_google_sso_client_secret_credential_id" class="metis-input metis-input-wide">
                    <option value="">Select credential</option>
                    <?php foreach ( (array) $google_oauth_client_secret_credentials as $credential ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( $credential['id'] ?? '' ) ); ?>" <?php metis_attr_selected( (string) $workspace_google_sso_client_secret_credential_id, (string) ( $credential['id'] ?? '' ) ); ?>>
                            <?php echo metis_escape_html( (string) ( $credential['label'] ?? $credential['id'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="metis-help">Credentials are managed under Developers → API & Endpoints.</p>
            </div>
            <div class="metis-field">
                <label for="workspace_google_sso_hosted_domain">Hosted Domain</label>
                <input type="text" id="workspace_google_sso_hosted_domain" name="workspace_google_sso_hosted_domain" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_google_sso_hosted_domain ); ?>" autocomplete="off" placeholder="mobilizewaco.org">
                <p class="metis-help">Optional. Restricts Google sign-in to a specific Workspace domain.</p>
            </div>
            <div class="metis-field">
                <label for="workspace_google_sso_redirect_uri">Redirect URI</label>
                <input type="text" id="workspace_google_sso_redirect_uri" class="metis-input metis-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_google_sso_redirect_uri ); ?>" readonly>
                <p class="metis-help">Use this exact URI in the Google Cloud Console OAuth client configuration.</p>
            </div>
        </div>
    </div>
    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Workspace Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
