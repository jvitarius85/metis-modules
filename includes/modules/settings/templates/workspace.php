<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'workspace' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Manage Google Workspace sync and Stripe group settings.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'workspace' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_workspace', 'metis_settings_nonce' ); ?>
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
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Workspace Settings</button>
    </div>
</form>
