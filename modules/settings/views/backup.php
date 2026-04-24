<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'backup' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );

$backup_drive_options = is_array( $backup_drive_options ?? null ) ? $backup_drive_options : [];
$backup_task_url = metis_settings_section_url( 'system', 'jobs-tasks' );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Configure backup storage, retention, and restore access.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'backup' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="backup">
    <?php metis_runtime_nonce_field( 'metis_save_settings_backup', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Backup Configuration</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="backup_drive_id">Backup Drive</label>
                <select id="backup_drive_id" name="backup_drive_id" class="mw-input mw-input-wide" <?php disabled( ! $is_system_admin ); ?>>
                    <option value="">Select a configured drive</option>
                    <?php foreach ( $backup_drive_options as $row ) : ?>
                        <?php
                        $drive_id = (string) ( $row['id'] ?? '' );
                        $option_label = trim( (string) ( $row['name'] ?? $drive_id ) );
                        ?>
                        <option value="<?php echo metis_escape_attr( $drive_id ); ?>" <?php metis_attr_selected( $backup_drive_id, $drive_id ); ?>>
                            <?php echo metis_escape_html( $option_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mw-help">Choose which configured Shared Drive receives automated backups. This should point at the dedicated <code>backups</code> drive.</p>
                <?php if ( ! empty( $backup_drive_error ) ) : ?>
                    <p class="mw-help" style="color:#b91c1c;"><?php echo metis_escape_html( (string) $backup_drive_error ); ?></p>
                <?php endif; ?>
            </div>
            <div class="mw-field">
                <label for="backup_environment">Environment Label</label>
                <input type="text" id="backup_environment" name="backup_environment" class="mw-input mw-input-wide" value="<?php echo metis_escape_attr( (string) $backup_environment ); ?>" placeholder="production" <?php disabled( ! $is_system_admin ); ?>>
                <p class="mw-help">Optional override. Leave blank to infer from the site URL and runtime environment.</p>
            </div>
            <div class="mw-field">
                <label for="backup_retention_runs">Retention Window</label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="backup_retention_runs" name="backup_retention_runs" class="mw-input" min="1" max="365" value="<?php echo metis_escape_attr( (string) $backup_retention_runs ); ?>" style="width:120px;" <?php disabled( ! $is_system_admin ); ?>>
                    <span class="mw-help" style="margin:0;">successful runs</span>
                </div>
                <p class="mw-help">Older successful snapshots beyond this count are rotated out after each new backup.</p>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Manual Run</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label>Automation</label>
                <p class="mw-help">Nightly automation is registered as the scheduler task <code>system_backup_snapshot</code>. Use Jobs &amp; Tasks to change cadence or disable it.</p>
                <p class="mw-help">Stored layout: <code>Shared Drive / environment / year / month / day / run</code></p>
                <p class="mw-help"><a href="<?php echo metis_escape_url( $backup_task_url ); ?>">Open Jobs &amp; Tasks</a></p>
            </div>
            <div class="mw-settings-actions" style="justify-content:flex-start;">
                <button type="button" class="mw-btn" data-backup-run-now="1">Run Backup Now</button>
                <span class="mw-help" data-backup-action-status></span>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Recent Backups</h2></div>
        <div class="mw-settings-body" data-backup-history-root="1">
            <div class="mw-settings-actions" style="justify-content:flex-start;">
                <button type="button" class="mw-btn mw-btn-ghost" data-backup-history-refresh="1">Load History</button>
                <span class="mw-help" data-backup-history-status>History not loaded.</span>
            </div>
            <div class="mw-table-wrap" style="margin-top:12px;">
                <table class="mw-table">
                    <thead>
                        <tr>
                            <th>Run</th>
                            <th>Status</th>
                            <th>Environment</th>
                            <th>Completed</th>
                            <th>Drive Folder</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody data-backup-history-body="1">
                        <tr>
                            <td colspan="6"><span class="mw-help">History loads on demand.</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mw-help">Restore replays config, uploads, runtime data, then the database snapshot from the selected run. Use carefully on the target environment.</p>
        </div>
    </div>

    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Backup Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
