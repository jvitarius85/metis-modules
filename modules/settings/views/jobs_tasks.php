<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'jobs_tasks' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Review queue status, scheduler config, and approved operations.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'jobs_tasks' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="jobs_tasks">
    <?php metis_runtime_nonce_field( 'metis_save_settings_jobs_tasks', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Queue Status</h2></div>
        <div class="mw-settings-body">
            <div class="mw-premium-table metis-scheduler-queue-table">
                <div class="mw-premium-row mw-premium-header">
                    <div class="mw-premium-cell">Queue</div>
                    <div class="mw-premium-cell">Queued</div>
                    <div class="mw-premium-cell">Processing</div>
                    <div class="mw-premium-cell">Completed</div>
                    <div class="mw-premium-cell">Failed</div>
                </div>
                <div class="mw-premium-row">
                    <div class="mw-premium-cell"><strong>Cron Tasks</strong></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['queued'] ?? 0 ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['processing'] ?? 0 ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['completed'] ?? 0 ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['failed'] ?? 0 ) ) ); ?></div>
                </div>
                <div class="mw-premium-row">
                    <div class="mw-premium-cell"><strong>Operations</strong></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['queued'] ?? 0 ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['processing'] ?? 0 ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['completed'] ?? 0 ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['failed'] ?? 0 ) ) ); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Scheduler Config</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label>Scheduler endpoint</label>
                <div class="mw-shortcode-wrap"><code class="mw-shortcode"><?php echo metis_escape_html( (string) $system_cron_endpoint ); ?></code></div>
            </div>
            <div class="mw-field">
                <label for="system_cron_secret">Shared secret</label>
                <input type="password" id="system_cron_secret" name="system_cron_secret" class="mw-input mw-input-wide" autocomplete="new-password" placeholder="<?php echo metis_escape_attr( $system_cron_secret_masked !== '' ? $system_cron_secret_masked : 'Enter a strong shared secret' ); ?>" <?php disabled( ! $is_system_admin ); ?>>
                <p class="mw-help">Leave blank to keep the current secret.</p>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Operations Console</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="metis-operations-command">Approved Command</label>
                <input type="text" id="metis-operations-command" class="mw-input mw-input-wide" placeholder="Example: cron run integrity_scan" data-operations-command-input>
            </div>
            <div class="mw-settings-actions" style="justify-content:flex-start;">
                <button type="button" class="mw-btn" data-operations-command-submit>Queue Command</button>
            </div>
            <div class="mw-help" data-operations-command-status></div>
        </div>
    </div>

    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Jobs & Tasks Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
