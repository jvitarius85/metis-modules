<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'developers_api' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Centralized credentials and endpoint references for integrations.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'developers_api' ); ?>
<form method="post" enctype="multipart/form-data" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="developers_api">
    <?php metis_runtime_nonce_field( 'metis_save_settings_developers_api', 'metis_settings_nonce' ); ?>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Credentials</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="stripe_secret_credential_id">Stripe Key Credential</label>
                <select id="stripe_secret_credential_id" name="stripe_secret_credential_id" class="mw-input mw-input-wide">
                    <option value="">Select credential</option>
                    <?php foreach ( (array) $stripe_credentials as $credential ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( $credential['id'] ?? '' ) ); ?>" <?php metis_attr_selected( (string) Core_Settings_Service::get( 'stripe_secret_credential_id', '' ), (string) ( $credential['id'] ?? '' ) ); ?>>
                            <?php echo metis_escape_html( (string) ( $credential['label'] ?? $credential['id'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="password" id="stripe_secret" name="stripe_secret" class="mw-input mw-input-wide" autocomplete="new-password" placeholder="<?php echo $stripe_connected ? metis_escape_attr( metis_mask_key( $stripe_secret ) ) : 'sk_live_••••••••••'; ?>" style="margin-top:8px;">
                <p class="mw-help">Paste to create/update the selected credential.</p>
            </div>
            <div class="mw-field">
                <label for="stripe_webhook_secret_credential_id">Webhook Secret Credential</label>
                <select id="stripe_webhook_secret_credential_id" name="stripe_webhook_secret_credential_id" class="mw-input mw-input-wide">
                    <option value="">Select credential</option>
                    <?php foreach ( (array) $webhook_credentials as $credential ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( $credential['id'] ?? '' ) ); ?>" <?php metis_attr_selected( (string) Core_Settings_Service::get( 'stripe_webhook_secret_credential_id', '' ), (string) ( $credential['id'] ?? '' ) ); ?>>
                            <?php echo metis_escape_html( (string) ( $credential['label'] ?? $credential['id'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" class="mw-input mw-input-wide" autocomplete="new-password" placeholder="<?php echo $webhook_configured ? metis_escape_attr( metis_mask_key( $webhook_secret ) ) : 'whsec_••••••••••'; ?>" style="margin-top:8px;">
            </div>
            <div class="mw-field">
                <label for="workspace_service_account_json_credential_id">Google Service Account Credential</label>
                <select id="workspace_service_account_json_credential_id" name="workspace_service_account_json_credential_id" class="mw-input mw-input-wide">
                    <option value="">Select credential</option>
                    <?php foreach ( (array) $google_service_account_credentials as $credential ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( $credential['id'] ?? '' ) ); ?>" <?php metis_attr_selected( (string) Core_Settings_Service::get( 'workspace_service_account_json_credential_id', '' ), (string) ( $credential['id'] ?? '' ) ); ?>>
                            <?php echo metis_escape_html( (string) ( $credential['label'] ?? $credential['id'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="file" id="workspace_service_account_json_file" name="workspace_service_account_json_file" class="mw-input mw-input-wide" accept="application/json,.json" style="margin-top:8px;">
            </div>
            <div class="mw-field">
                <label for="workspace_google_sso_client_secret_credential_id">Google OAuth Client Secret Credential</label>
                <select id="workspace_google_sso_client_secret_credential_id" name="workspace_google_sso_client_secret_credential_id" class="mw-input mw-input-wide">
                    <option value="">Select credential</option>
                    <?php foreach ( (array) $google_oauth_client_secret_credentials as $credential ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( $credential['id'] ?? '' ) ); ?>" <?php metis_attr_selected( (string) Core_Settings_Service::get( 'workspace_google_sso_client_secret_credential_id', '' ), (string) ( $credential['id'] ?? '' ) ); ?>>
                            <?php echo metis_escape_html( (string) ( $credential['label'] ?? $credential['id'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="password" id="workspace_google_sso_client_secret" name="workspace_google_sso_client_secret" class="mw-input mw-input-wide" autocomplete="new-password" placeholder="<?php echo is_string( $workspace_google_sso_client_secret ) && trim( $workspace_google_sso_client_secret ) !== '' ? metis_escape_attr( metis_mask_key( (string) $workspace_google_sso_client_secret ) ) : 'GOCSPX-...'; ?>" style="margin-top:8px;">
                <p class="mw-help">Paste to create/update the selected credential.</p>
            </div>
            <div class="mw-field">
                <label for="workspace_impersonation_admin">Workspace Impersonation Admin (Breakglass)</label>
                <input type="email" id="workspace_impersonation_admin" name="workspace_impersonation_admin" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $workspace_impersonation_admin ); ?>" autocomplete="off" placeholder="admin@yourdomain.org">
            </div>
            <div class="mw-field">
                <label>Webhook Endpoint URL</label>
                <div class="mw-shortcode-wrap">
                    <code class="mw-shortcode" id="mw-webhook-url"><?php echo metis_escape_html( (string) $stripe_webhook_endpoint ); ?></code>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-copy-target="mw-webhook-url">Copy</button>
                </div>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Inbound Email</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="communications_inbound_google_project_id">Google Cloud Project ID</label>
                <input type="text" id="communications_inbound_google_project_id" name="communications_inbound_google_project_id" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $communications_inbound_google_project_id ); ?>" autocomplete="off" placeholder="your-google-project-id">
            </div>
            <div class="mw-field">
                <label for="communications_inbound_pubsub_topic">Pub/Sub Topic</label>
                <input type="text" id="communications_inbound_pubsub_topic" name="communications_inbound_pubsub_topic" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $communications_inbound_pubsub_topic ); ?>" autocomplete="off" placeholder="gmail-inbound">
                <p class="mw-help">Use a short topic name or a full <code>projects/.../topics/...</code> path.</p>
            </div>
            <div class="mw-field">
                <label for="communications_inbound_pubsub_audience">Push Audience</label>
                <input type="text" id="communications_inbound_pubsub_audience" name="communications_inbound_pubsub_audience" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $communications_inbound_pubsub_audience ); ?>" autocomplete="off" placeholder="https://metis.example.org/metis-webhooks/gmail_pubsub">
            </div>
            <div class="mw-field">
                <label for="communications_inbound_pubsub_service_account_email">Push Service Account Email</label>
                <input type="email" id="communications_inbound_pubsub_service_account_email" name="communications_inbound_pubsub_service_account_email" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $communications_inbound_pubsub_service_account_email ); ?>" autocomplete="off" placeholder="pubsub-push@your-project.iam.gserviceaccount.com">
            </div>
            <div class="mw-field">
                <label>Mailboxes To Watch</label>
                <p class="mw-help">Add each inbox you want Metis to monitor. Most teams only need the mailbox address and a friendly name.</p>
                <div class="metis-settings-repeatable">
                    <div class="metis-settings-repeatable-list" data-repeatable-list="inbound-mailbox">
                        <?php foreach ( $communications_inbound_mailboxes as $index => $row ) : ?>
                            <div class="metis-settings-row" data-repeatable-row style="grid-template-columns:minmax(220px,1.5fr) minmax(170px,1fr) minmax(220px,1.35fr) minmax(180px,1fr) minmax(150px,.8fr) auto auto;">
                                <input type="hidden" name="communications_inbound_mailboxes[<?php echo (int) $index; ?>][mailbox_key]" value="<?php echo metis_escape_attr( (string) ( $row['mailbox_key'] ?? '' ) ); ?>">
                                <div class="mw-field">
                                    <label>Mailbox Email</label>
                                    <input type="email" class="mw-input" name="communications_inbound_mailboxes[<?php echo (int) $index; ?>][mailbox_email]" value="<?php echo metis_escape_attr( (string) ( $row['mailbox_email'] ?? '' ) ); ?>" placeholder="newsletter@example.org">
                                </div>
                                <div class="mw-field">
                                    <label>Mailbox Name</label>
                                    <input type="text" class="mw-input" name="communications_inbound_mailboxes[<?php echo (int) $index; ?>][display_name]" value="<?php echo metis_escape_attr( (string) ( $row['display_name'] ?? '' ) ); ?>" placeholder="Newsletter">
                                </div>
                                <div class="mw-field">
                                    <label>Google User</label>
                                    <input type="email" class="mw-input" name="communications_inbound_mailboxes[<?php echo (int) $index; ?>][delegated_user]" value="<?php echo metis_escape_attr( (string) ( $row['delegated_user'] ?? '' ) ); ?>" placeholder="newsletter@example.org">
                                    <p class="mw-help">Leave this the same as the mailbox unless Google gave you a different delegated user.</p>
                                </div>
                                <div class="mw-field">
                                    <label>Inbox Labels</label>
                                    <input type="text" class="mw-input" name="communications_inbound_mailboxes[<?php echo (int) $index; ?>][label_ids]" value="<?php echo metis_escape_attr( implode( ', ', array_map( 'strval', (array) ( $row['label_ids'] ?? [] ) ) ) ); ?>" placeholder="INBOX">
                                    <p class="mw-help">Optional. Separate multiple labels with commas.</p>
                                </div>
                                <div class="mw-field">
                                    <label>Label Rule</label>
                                    <select class="mw-input" name="communications_inbound_mailboxes[<?php echo (int) $index; ?>][label_filter_behavior]">
                                        <option value="" <?php metis_attr_selected( (string) ( $row['label_filter_behavior'] ?? '' ), '' ); ?>>Use all mail</option>
                                        <option value="include" <?php metis_attr_selected( (string) ( $row['label_filter_behavior'] ?? '' ), 'include' ); ?>>Only these labels</option>
                                        <option value="exclude" <?php metis_attr_selected( (string) ( $row['label_filter_behavior'] ?? '' ), 'exclude' ); ?>>Skip these labels</option>
                                    </select>
                                </div>
                                <label class="metis-settings-flag"><input type="checkbox" name="communications_inbound_mailboxes[<?php echo (int) $index; ?>][enabled]" value="1" <?php metis_attr_checked( ! empty( $row['enabled'] ) ); ?>> Active</label>
                                <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="mw-btn mw-btn-secondary mw-btn-xs" data-repeatable-add="inbound-mailbox">Add Mailbox</button>
                </div>
            </div>
            <div class="mw-field">
                <label for="communications_inbound_log_verbosity">Logging Verbosity</label>
                <select id="communications_inbound_log_verbosity" name="communications_inbound_log_verbosity" class="mw-input mw-input-wide">
                    <option value="quiet" <?php metis_attr_selected( (string) $communications_inbound_log_verbosity, 'quiet' ); ?>>Quiet</option>
                    <option value="standard" <?php metis_attr_selected( (string) $communications_inbound_log_verbosity, 'standard' ); ?>>Standard</option>
                    <option value="verbose" <?php metis_attr_selected( (string) $communications_inbound_log_verbosity, 'verbose' ); ?>>Verbose</option>
                </select>
            </div>
            <div class="mw-field">
                <label for="communications_inbound_full_sync_days">Full Sync Lookback (days)</label>
                <input type="number" min="1" max="90" id="communications_inbound_full_sync_days" name="communications_inbound_full_sync_days" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $communications_inbound_full_sync_days ); ?>">
            </div>
            <div class="mw-field">
                <label><input type="checkbox" name="communications_inbound_allow_reprocess" value="1" <?php metis_attr_checked( $communications_inbound_allow_reprocess ); ?>> Allow manual reprocess from stored payloads</label>
            </div>
            <div class="mw-field">
                <label><input type="checkbox" name="communications_inbound_enable_bounce_handler" value="1" <?php metis_attr_checked( $communications_inbound_enable_bounce_handler ); ?>> Enable bounce handler</label>
            </div>
            <div class="mw-field">
                <label><input type="checkbox" name="communications_inbound_enable_unsubscribe_handler" value="1" <?php metis_attr_checked( $communications_inbound_enable_unsubscribe_handler ); ?>> Enable unsubscribe handler</label>
            </div>
            <div class="mw-field">
                <label><input type="checkbox" name="communications_inbound_enable_grandys_stash_handler" value="1" <?php metis_attr_checked( $communications_inbound_enable_grandys_stash_handler ); ?>> Enable Grandy’s Stash handler</label>
            </div>
            <div class="mw-field">
                <label>Gmail Push Webhook</label>
                <div class="mw-shortcode-wrap">
                    <code class="mw-shortcode" id="mw-gmail-pubsub-webhook"><?php echo metis_escape_html( (string) $gmail_pubsub_webhook_endpoint ); ?></code>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-copy-target="mw-gmail-pubsub-webhook">Copy</button>
                </div>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Endpoints</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field"><label>AJAX</label><div class="mw-shortcode-wrap"><code class="mw-shortcode"><?php echo metis_escape_html( (string) $ajax_endpoint_url ); ?></code></div></div>
            <div class="mw-field"><label>Auth</label><div class="mw-shortcode-wrap"><code class="mw-shortcode"><?php echo metis_escape_html( (string) $api_auth_resolve_endpoint ); ?></code></div></div>
            <div class="mw-field"><label>Cron</label><div class="mw-shortcode-wrap"><code class="mw-shortcode"><?php echo metis_escape_html( (string) $system_cron_endpoint ); ?></code></div></div>
            <div class="mw-field"><label>Batch</label><div class="mw-shortcode-wrap"><code class="mw-shortcode"><?php echo metis_escape_html( (string) $batch_api_endpoint ); ?></code></div></div>
            <div class="mw-field"><label>Base URLs</label><div class="mw-shortcode-wrap"><code class="mw-shortcode"><?php echo metis_escape_html( (string) ( function_exists( 'metis_home_url' ) ? metis_home_url( '/' ) : '' ) ); ?></code></div></div>
        </div>
    </div>

    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save API & Endpoints</button>
    </div>
</form>
<script>
(function () {
    function reindexMailboxRows() {
        const list = document.querySelector('[data-repeatable-list="inbound-mailbox"]');
        if (!list) return;
        Array.from(list.querySelectorAll('[data-repeatable-row]')).forEach(function (row, index) {
            row.querySelectorAll('input, select').forEach(function (field) {
                if (!field.name) return;
                field.name = field.name.replace(/communications_inbound_mailboxes\[(\d+)\]/, 'communications_inbound_mailboxes[' + index + ']');
            });
        });
    }

    function createMailboxRow(index) {
        const wrap = document.createElement('div');
        wrap.className = 'metis-settings-row';
        wrap.setAttribute('data-repeatable-row', '');
        wrap.style.gridTemplateColumns = 'minmax(220px,1.5fr) minmax(170px,1fr) minmax(220px,1.35fr) minmax(180px,1fr) minmax(150px,.8fr) auto auto';
        wrap.innerHTML = `
            <input type="hidden" name="communications_inbound_mailboxes[${index}][mailbox_key]" value="">
            <div class="mw-field">
                <label>Mailbox Email</label>
                <input type="email" class="mw-input" name="communications_inbound_mailboxes[${index}][mailbox_email]" placeholder="newsletter@example.org">
            </div>
            <div class="mw-field">
                <label>Mailbox Name</label>
                <input type="text" class="mw-input" name="communications_inbound_mailboxes[${index}][display_name]" placeholder="Newsletter">
            </div>
            <div class="mw-field">
                <label>Google User</label>
                <input type="email" class="mw-input" name="communications_inbound_mailboxes[${index}][delegated_user]" placeholder="newsletter@example.org">
                <p class="mw-help">Leave this the same as the mailbox unless Google gave you a different delegated user.</p>
            </div>
            <div class="mw-field">
                <label>Inbox Labels</label>
                <input type="text" class="mw-input" name="communications_inbound_mailboxes[${index}][label_ids]" placeholder="INBOX">
                <p class="mw-help">Optional. Separate multiple labels with commas.</p>
            </div>
            <div class="mw-field">
                <label>Label Rule</label>
                <select class="mw-input" name="communications_inbound_mailboxes[${index}][label_filter_behavior]">
                    <option value="">Use all mail</option>
                    <option value="include">Only these labels</option>
                    <option value="exclude">Skip these labels</option>
                </select>
            </div>
            <label class="metis-settings-flag"><input type="checkbox" name="communications_inbound_mailboxes[${index}][enabled]" value="1" checked> Active</label>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" data-repeatable-remove>Remove</button>
        `;
        return wrap;
    }

    document.addEventListener('click', function (event) {
        const add = event.target.closest('[data-repeatable-add="inbound-mailbox"]');
        if (add) {
            const list = document.querySelector('[data-repeatable-list="inbound-mailbox"]');
            if (!list) return;
            list.appendChild(createMailboxRow(list.querySelectorAll('[data-repeatable-row]').length));
            return;
        }

        const remove = event.target.closest('[data-repeatable-remove]');
        if (!remove) return;
        const row = remove.closest('[data-repeatable-row]');
        const list = remove.closest('[data-repeatable-list="inbound-mailbox"]');
        if (!row || !list) return;
        row.remove();
        if (list.querySelectorAll('[data-repeatable-row]').length === 0) {
            list.appendChild(createMailboxRow(0));
        }
        reindexMailboxRows();
    });
})();
</script>
<?php metis_settings_render_section_end(); ?>
