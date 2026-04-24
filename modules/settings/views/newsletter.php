<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'email' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Manage outbound email defaults, editor integrations, and service usage.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'email' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="email">
    <?php metis_runtime_nonce_field( 'metis_save_settings_newsletter', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Email Defaults</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="newsletter_default_from_name">Default From Name</label>
                <input type="text" id="newsletter_default_from_name" name="newsletter_default_from_name" class="mw-input mw-input-wide" maxlength="191" value="<?php echo metis_escape_attr( (string) $newsletter_default_from_name ); ?>">
            </div>
            <div class="mw-field">
                <label for="newsletter_default_from_email">Default From Email</label>
                <input type="email" id="newsletter_default_from_email" name="newsletter_default_from_email" class="mw-input mw-input-wide" maxlength="191" value="<?php echo metis_escape_attr( (string) $newsletter_default_from_email ); ?>" autocomplete="off">
            </div>
            <div class="mw-field">
                <label for="newsletter_default_reply_to">Default Reply-To</label>
                <input type="email" id="newsletter_default_reply_to" name="newsletter_default_reply_to" class="mw-input mw-input-wide" maxlength="191" value="<?php echo metis_escape_attr( (string) $newsletter_default_reply_to ); ?>" autocomplete="off">
            </div>
            <div class="mw-field">
                <label for="newsletter_google_daily_limit">Google Daily Send Cap</label>
                <input type="number" id="newsletter_google_daily_limit" name="newsletter_google_daily_limit" class="mw-input mw-input-wide" min="100" max="100000" step="1" value="<?php echo metis_escape_attr( (string) $newsletter_google_daily_limit ); ?>">
                <p class="mw-help">Used by Newsletter usage monitoring to warn as you approach the configured send limit.</p>
            </div>
            <div class="mw-field">
                <label for="newsletter_klipy_credential_id">Klipy API Credential</label>
                <select id="newsletter_klipy_credential_id" name="newsletter_klipy_credential_id" class="mw-input mw-input-wide">
                    <option value="">Select credential</option>
                    <?php foreach ( (array) $klipy_credentials as $credential ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( $credential['id'] ?? '' ) ); ?>" <?php metis_attr_selected( (string) $newsletter_klipy_credential_id, (string) ( $credential['id'] ?? '' ) ); ?>>
                            <?php echo metis_escape_html( (string) ( $credential['label'] ?? $credential['id'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mw-help">Credentials are managed under Developers → API & Endpoints.</p>
            </div>
            <div class="mw-field">
                <label for="newsletter_klipy_search_url">Klipy Search Endpoint</label>
                <input type="url" id="newsletter_klipy_search_url" name="newsletter_klipy_search_url" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $newsletter_klipy_search_url ); ?>" autocomplete="off" placeholder="https://api.klipy.com/...">
                <p class="mw-help">Search endpoint used by the Klipy picker. Defaults to <code>https://api.klipy.com/v1/gifs/search</code>.</p>
            </div>
        </div>
    </div>
    <?php $usage = is_array( $email_usage_snapshot ?? null ) ? $email_usage_snapshot : []; ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Usage Metrics</h2></div>
        <div class="mw-settings-body">
            <div class="kpi-card-grid">
                <article class="kpi-card"><div class="kpi-label">Today (Google)</div><div class="kpi-value"><?php echo metis_escape_html( metis_number_format( (int) ( $usage['google_today_sent'] ?? 0 ) ) ); ?></div><div class="kpi-trend"><?php echo metis_escape_html( (string) (int) ( $usage['google_today_pct'] ?? 0 ) ); ?>% of <?php echo metis_escape_html( metis_number_format( (int) ( $usage['google_daily_limit'] ?? 0 ) ) ); ?></div></article>
                <article class="kpi-card"><div class="kpi-label">Service Sends Today</div><div class="kpi-value"><?php echo metis_escape_html( metis_number_format( (int) ( $usage['service_today_total'] ?? 0 ) ) ); ?></div><div class="kpi-trend">Across all modules</div></article>
                <article class="kpi-card"><div class="kpi-label">Service Sends (30d)</div><div class="kpi-value"><?php echo metis_escape_html( metis_number_format( (int) ( $usage['service_30_total'] ?? 0 ) ) ); ?></div><div class="kpi-trend">Across all modules</div></article>
                <article class="kpi-card"><div class="kpi-label">Service Sends (All time)</div><div class="kpi-value"><?php echo metis_escape_html( metis_number_format( (int) ( $usage['service_all_total'] ?? 0 ) ) ); ?></div><div class="kpi-trend">Across all modules</div></article>
                <article class="kpi-card"><div class="kpi-label">Newsletter Sent (30d)</div><div class="kpi-value"><?php echo metis_escape_html( metis_number_format( (int) ( $usage['newsletter_30'] ?? 0 ) ) ); ?></div><div class="kpi-trend">Campaign mailings</div></article>
                <article class="kpi-card"><div class="kpi-label">Newsletter Campaigns</div><div class="kpi-value"><?php echo metis_escape_html( metis_number_format( (int) ( $usage['newsletter_campaigns'] ?? 0 ) ) ); ?></div><div class="kpi-trend">Tracked campaigns</div></article>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Service Usage By Module</h2></div>
        <div class="mw-settings-body">
            <section class="mw-premium-table metis-newsletter-table">
                <div class="mw-premium-row mw-premium-header" style="grid-template-columns:1.4fr 0.9fr 0.9fr 0.9fr 0.9fr;">
                    <div class="mw-premium-cell">Email Service Module</div>
                    <div class="mw-premium-cell">Today</div>
                    <div class="mw-premium-cell">Last 30 days</div>
                    <div class="mw-premium-cell">All time</div>
                    <div class="mw-premium-cell">Failures</div>
                </div>
                <?php if ( ! empty( $usage['service_module_rows'] ) && is_array( $usage['service_module_rows'] ) ) : ?>
                    <?php foreach ( (array) $usage['service_module_rows'] as $row ) : ?>
                        <div class="mw-premium-row" style="grid-template-columns:1.4fr 0.9fr 0.9fr 0.9fr 0.9fr;">
                            <div class="mw-premium-cell"><strong><?php echo metis_escape_html( ucwords( str_replace( '_', ' ', (string) ( $row['module_slug'] ?? 'core' ) ) ) ); ?></strong></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['today_sent'] ?? 0 ) ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['sent_30d'] ?? 0 ) ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['sent_all'] ?? 0 ) ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['failed_all'] ?? 0 ) ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="mw-premium-row" style="grid-template-columns:1fr;">
                        <div class="mw-premium-cell mw-muted">No email service usage tracked yet.</div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Recent Email Activity</h2></div>
        <div class="mw-settings-body">
            <section class="mw-premium-table metis-newsletter-table">
                <div class="mw-premium-row mw-premium-header" style="grid-template-columns:1.1fr 0.8fr 0.8fr 1fr 1.2fr 1.4fr;">
                    <div class="mw-premium-cell">When</div>
                    <div class="mw-premium-cell">Module</div>
                    <div class="mw-premium-cell">Status</div>
                    <div class="mw-premium-cell">Provider</div>
                    <div class="mw-premium-cell">Recipient</div>
                    <div class="mw-premium-cell">Subject / Error</div>
                </div>
                <?php if ( ! empty( $usage['service_event_rows'] ) && is_array( $usage['service_event_rows'] ) ) : ?>
                    <?php foreach ( (array) $usage['service_event_rows'] as $row ) : ?>
                        <div class="mw-premium-row" style="grid-template-columns:1.1fr 0.8fr 0.8fr 1fr 1.2fr 1.4fr;">
                            <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( $row['event_at'] ?? '' ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( ucwords( str_replace( '_', ' ', (string) ( $row['module_slug'] ?? 'core' ) ) ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( strtoupper( (string) ( $row['status'] ?? '' ) ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( $row['provider'] ?? '' ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( $row['to_email'] ?? '' ) ); ?></div>
                            <div class="mw-premium-cell">
                                <?php echo metis_escape_html( (string) ( $row['subject'] ?? '' ) ); ?>
                                <?php if ( ! empty( $row['error_message'] ) ) : ?>
                                    <div class="mw-muted"><?php echo metis_escape_html( (string) $row['error_message'] ); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="mw-premium-row" style="grid-template-columns:1fr;">
                        <div class="mw-premium-cell mw-muted">No email send events recorded yet.</div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Google Daily Usage</h2></div>
        <div class="mw-settings-body">
            <section class="mw-premium-table metis-newsletter-table">
                <div class="mw-premium-row mw-premium-header" style="grid-template-columns:1fr 1fr;">
                    <div class="mw-premium-cell">Date</div>
                    <div class="mw-premium-cell">Google Send Count</div>
                </div>
                <?php if ( ! empty( $usage['google_daily_rows'] ) && is_array( $usage['google_daily_rows'] ) ) : ?>
                    <?php foreach ( (array) $usage['google_daily_rows'] as $d ) : ?>
                        <div class="mw-premium-row" style="grid-template-columns:1fr 1fr;">
                            <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( $d['usage_date'] ?? '' ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $d['sent_total'] ?? 0 ) ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="mw-premium-row" style="grid-template-columns:1fr;">
                        <div class="mw-premium-cell mw-muted">No synced Google usage data yet.</div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Email Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
