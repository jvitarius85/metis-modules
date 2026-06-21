<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'email' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Manage outbound email defaults, editor integrations, and service usage.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'email' ); ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="email">
    <?php metis_runtime_nonce_field( 'metis_save_settings_newsletter', 'metis_settings_nonce' ); ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Email Defaults</h2></div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label for="newsletter_default_from_name">Default From Name</label>
                <input type="text" id="newsletter_default_from_name" name="newsletter_default_from_name" class="metis-input metis-input-wide" maxlength="191" value="<?php echo metis_escape_attr( (string) $newsletter_default_from_name ); ?>">
            </div>
            <div class="metis-field">
                <label for="newsletter_default_from_email">Default From Email</label>
                <input type="email" id="newsletter_default_from_email" name="newsletter_default_from_email" class="metis-input metis-input-wide" maxlength="191" value="<?php echo metis_escape_attr( (string) $newsletter_default_from_email ); ?>" autocomplete="off">
            </div>
            <div class="metis-field">
                <label for="newsletter_default_reply_to">Default Reply-To</label>
                <input type="email" id="newsletter_default_reply_to" name="newsletter_default_reply_to" class="metis-input metis-input-wide" maxlength="191" value="<?php echo metis_escape_attr( (string) $newsletter_default_reply_to ); ?>" autocomplete="off">
            </div>
            <div class="metis-field">
                <label for="newsletter_google_daily_limit">Google Daily Send Cap</label>
                <input type="number" id="newsletter_google_daily_limit" name="newsletter_google_daily_limit" class="metis-input metis-input-wide" min="100" max="100000" step="1" value="<?php echo metis_escape_attr( (string) $newsletter_google_daily_limit ); ?>">
                <p class="metis-help">Used by Newsletter usage monitoring to warn as you approach the configured send limit.</p>
            </div>
        </div>
    </div>
    <?php $usage = is_array( $email_usage_snapshot ?? null ) ? $email_usage_snapshot : []; ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Usage Metrics</h2></div>
        <div class="metis-settings-body">
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

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Service Usage By Module</h2></div>
        <div class="metis-settings-body">
            <table class="metis-premium-table metis-settings-email-module-table">
                <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Email Service Module</th>
                    <th class="metis-premium-cell" scope="col">Today</th>
                    <th class="metis-premium-cell" scope="col">Last 30 days</th>
                    <th class="metis-premium-cell" scope="col">All time</th>
                    <th class="metis-premium-cell" scope="col">Failures</th>
                </tr>
                </thead>
                <tbody>
                <?php if ( ! empty( $usage['service_module_rows'] ) && is_array( $usage['service_module_rows'] ) ) : ?>
                    <?php foreach ( (array) $usage['service_module_rows'] as $row ) : ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell"><strong><?php echo metis_escape_html( ucwords( str_replace( '_', ' ', (string) ( $row['module_slug'] ?? 'core' ) ) ) ); ?></strong></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['today_sent'] ?? 0 ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['sent_30d'] ?? 0 ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['sent_all'] ?? 0 ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $row['failed_all'] ?? 0 ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell metis-muted" colspan="5">No email service usage tracked yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Recent Email Activity</h2></div>
        <div class="metis-settings-body">
            <table class="metis-premium-table metis-settings-email-event-table">
                <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">When</th>
                    <th class="metis-premium-cell" scope="col">Module</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell" scope="col">Provider</th>
                    <th class="metis-premium-cell" scope="col">Recipient</th>
                    <th class="metis-premium-cell" scope="col">Subject / Error</th>
                </tr>
                </thead>
                <tbody>
                <?php if ( ! empty( $usage['service_event_rows'] ) && is_array( $usage['service_event_rows'] ) ) : ?>
                    <?php foreach ( (array) $usage['service_event_rows'] as $row ) : ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['event_at'] ?? '' ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( ucwords( str_replace( '_', ' ', (string) ( $row['module_slug'] ?? 'core' ) ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( strtoupper( (string) ( $row['status'] ?? '' ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( metis_settings_email_provider_label( (string) ( $row['provider'] ?? '' ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['to_email'] ?? '' ) ); ?></td>
                            <td class="metis-premium-cell">
                                <?php echo metis_escape_html( (string) ( $row['subject'] ?? '' ) ); ?>
                                <?php if ( ! empty( $row['error_message'] ) ) : ?>
                                    <div class="metis-muted"><?php echo metis_escape_html( (string) $row['error_message'] ); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell metis-muted" colspan="6">No email send events recorded yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Google Daily Usage</h2></div>
        <div class="metis-settings-body">
            <table class="metis-premium-table metis-settings-google-usage-table">
                <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Date</th>
                    <th class="metis-premium-cell" scope="col">Google Send Count</th>
                </tr>
                </thead>
                <tbody>
                <?php if ( ! empty( $usage['google_daily_rows'] ) && is_array( $usage['google_daily_rows'] ) ) : ?>
                    <?php foreach ( (array) $usage['google_daily_rows'] as $d ) : ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $d['usage_date'] ?? '' ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( metis_number_format( (int) ( $d['sent_total'] ?? 0 ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell metis-muted" colspan="2">No synced Google usage data yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Email Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
