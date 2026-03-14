<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'api' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Manage API keys, webhook secrets, and service credentials.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'api' ); ?>
<form method="post" enctype="multipart/form-data" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_api', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header">
            <h2>API Keys and Credentials</h2>
            <span class="mw-settings-status <?php echo $is_system_admin ? 'is-ok' : 'is-missing'; ?>"><?php echo $is_system_admin ? 'System Admin' : 'Restricted'; ?></span>
        </div>
        <div class="mw-settings-body">
            <?php if ( ! $is_system_admin ) : ?>
                <div class="mw-callout mw-callout-warning">Only system admins can view or update workspace secrets below. Personal CardDAV tokens are still available.</div>
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
                    <label for="workspace_service_account_json_file">Workspace Service Account JSON File</label>
                    <input type="file" id="workspace_service_account_json_file" name="workspace_service_account_json_file" class="mw-input mw-input-wide" accept="application/json,.json">
                    <p class="mw-help"><?php echo ! empty( $workspace_service_account_present ) ? 'A service account JSON file is already stored. Upload a new file only when rotating credentials.' : 'Upload the original Google service account `.json` file.'; ?></p>
                </div>
                <div class="mw-field">
                    <label>Webhook Endpoint URL</label>
                <div class="mw-shortcode-wrap">
                        <code class="mw-shortcode" id="mw-webhook-url"><?php echo esc_html( metis_webhook_url( 'stripe' ) ); ?></code>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-copy-target="mw-webhook-url">Copy</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="mw-settings-card">
        <div class="mw-settings-header">
            <h2>CardDAV Access</h2>
            <span class="mw-settings-status is-ok">Enabled</span>
        </div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label>Server URL</label>
                <div class="mw-shortcode-wrap">
                    <code class="mw-shortcode"><?php echo esc_html( (string) $carddav_endpoint ); ?></code>
                </div>
            </div>
            <div class="mw-field">
                <label>Username</label>
                <div class="mw-shortcode-wrap">
                    <code class="mw-shortcode"><?php echo esc_html( (string) $carddav_username ); ?></code>
                </div>
            </div>
            <?php if ( is_array( $carddav_token_notice ) && ! empty( $carddav_token_notice['token'] ) ) : ?>
                <div class="mw-callout mw-callout-warning">
                    New CardDAV token for <strong><?php echo esc_html( (string) ( $carddav_token_notice['label'] ?? 'CardDAV device' ) ); ?></strong>: <code><?php echo esc_html( (string) $carddav_token_notice['token'] ); ?></code>
                </div>
            <?php endif; ?>
            <div class="mw-field">
                <label for="metis_carddav_token_label">Create Device Token</label>
                <input type="text" id="metis_carddav_token_label" name="metis_carddav_token_label" class="mw-input mw-input-wide" autocomplete="off" placeholder="iPhone, MacBook, Outlook, etc.">
            </div>
            <div class="mw-settings-actions" style="justify-content:flex-start;">
                <button type="submit" class="mw-btn" name="metis_carddav_generate_token" value="1">Generate CardDAV Token</button>
            </div>
            <?php if ( ! empty( $carddav_tokens ) ) : ?>
                <div class="mw-field">
                    <label>Existing Tokens</label>
                    <div class="mw-table-wrap">
                        <table class="mw-table">
                            <thead>
                                <tr>
                                    <th>Label</th>
                                    <th>Prefix</th>
                                    <th>Created</th>
                                    <th>Last Used</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $carddav_tokens as $token_row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( (string) ( $token_row['label'] ?? '' ) ); ?></td>
                                        <td><code><?php echo esc_html( (string) ( $token_row['token_prefix'] ?? '' ) ); ?></code></td>
                                        <td><?php echo esc_html( (string) ( $token_row['created_at'] ?? '—' ) ); ?></td>
                                        <td><?php echo esc_html( (string) ( $token_row['last_used_at'] ?? '—' ) ); ?></td>
                                        <td><?php echo ! empty( $token_row['revoked_at'] ) ? 'Revoked' : 'Active'; ?></td>
                                        <td>
                                            <?php if ( empty( $token_row['revoked_at'] ) ) : ?>
                                                <button type="submit" class="mw-btn mw-btn-xs mw-btn-ghost" name="metis_carddav_revoke_token" value="1" onclick="document.getElementById('metis-carddav-token-id').value='<?php echo esc_attr( (string) ( $token_row['id'] ?? '' ) ); ?>';">Revoke</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            <input type="hidden" id="metis-carddav-token-id" name="metis_carddav_token_id" value="">
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save API Settings</button>
    </div>
</form>
