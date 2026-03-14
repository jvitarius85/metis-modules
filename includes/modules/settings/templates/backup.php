<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'backup' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );

$backup_drive_options = is_array( $backup_drive_options ?? null ) ? $backup_drive_options : [];
$backup_runs = is_array( $backup_runs ?? null ) ? $backup_runs : [];
$backup_task_url = metis_portal_url( 'settings', 'scheduler' );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Configure backup storage, retention, and restore access.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'backup' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_backup', 'metis_settings_nonce' ); ?>
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
                        <option value="<?php echo esc_attr( $drive_id ); ?>" <?php selected( $backup_drive_id, $drive_id ); ?>>
                            <?php echo esc_html( $option_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mw-help">Choose which configured Shared Drive receives automated backups. This should point at the dedicated <code>backups</code> drive.</p>
                <?php if ( ! empty( $backup_drive_error ) ) : ?>
                    <p class="mw-help" style="color:#b91c1c;"><?php echo esc_html( (string) $backup_drive_error ); ?></p>
                <?php endif; ?>
            </div>
            <div class="mw-field">
                <label for="backup_environment">Environment Label</label>
                <input type="text" id="backup_environment" name="backup_environment" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $backup_environment ); ?>" placeholder="production" <?php disabled( ! $is_system_admin ); ?>>
                <p class="mw-help">Optional override. Leave blank to infer from the site URL and runtime environment.</p>
            </div>
            <div class="mw-field">
                <label for="backup_retention_runs">Retention Window</label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <input type="number" id="backup_retention_runs" name="backup_retention_runs" class="mw-input" min="1" max="365" value="<?php echo esc_attr( (string) $backup_retention_runs ); ?>" style="width:120px;" <?php disabled( ! $is_system_admin ); ?>>
                    <span class="mw-help" style="margin:0;">successful runs</span>
                </div>
                <p class="mw-help">Older successful snapshots beyond this count are rotated out after each new backup.</p>
            </div>
        </div>
    </div>
    <?php if ( $is_system_admin ) : ?>
        <div class="mw-settings-actions">
            <button type="submit" class="mw-btn">Save Backup Settings</button>
        </div>
    <?php endif; ?>
</form>

<div class="mw-settings-card">
    <div class="mw-settings-header"><h2>Operations</h2></div>
    <div class="mw-settings-body">
        <div class="mw-field">
            <label>Automation</label>
            <p class="mw-help">Nightly automation is registered as the scheduler task <code>system_backup_snapshot</code>. Use the Scheduler view to change cadence or disable it.</p>
            <p class="mw-help">Stored layout: <code>Shared Drive / environment / year / month / day / run</code></p>
            <p class="mw-help"><a href="<?php echo esc_url( $backup_task_url ); ?>">Open Scheduler</a></p>
        </div>
        <?php if ( $is_system_admin ) : ?>
            <div class="mw-settings-actions" style="justify-content:flex-start;">
                <button type="button" class="mw-btn" data-backup-run-now="1">Run Backup Now</button>
                <span class="mw-help" data-backup-action-status></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mw-settings-card">
    <div class="mw-settings-header"><h2>Recent Backups</h2></div>
    <div class="mw-settings-body">
        <?php if ( empty( $backup_runs ) ) : ?>
            <p class="mw-help">No backup runs have been recorded yet.</p>
        <?php else : ?>
            <div class="mw-table-wrap">
                <table class="mw-table">
                    <thead>
                        <tr>
                            <th>Run</th>
                            <th>Status</th>
                            <th>Environment</th>
                            <th>Completed</th>
                            <th>Drive Folder</th>
                            <?php if ( $is_system_admin ) : ?>
                                <th></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $backup_runs as $run ) : ?>
                            <?php
                            $run_uuid = (string) ( $run['run_uuid'] ?? '' );
                            $status = (string) ( $run['status'] ?? 'unknown' );
                            $completed_at = (string) ( $run['completed_at'] ?? '' );
                            $drive_folder_id = (string) ( $run['drive_run_folder_id'] ?? '' );
                            $components = is_array( $run['components'] ?? null ) ? $run['components'] : [];
                            $full_link = (string) ( $components['full']['drive_web_view_link'] ?? '' );
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $run_uuid ); ?></strong>
                                    <?php if ( ! empty( $run['last_error'] ) ) : ?>
                                        <div class="mw-help" style="margin-top:6px; color:#b91c1c;"><?php echo esc_html( (string) $run['last_error'] ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( ucfirst( $status ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $run['environment'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( $completed_at !== '' ? $completed_at : 'In progress' ); ?></td>
                                <td>
                                    <?php if ( $full_link !== '' ) : ?>
                                        <a href="<?php echo esc_url( $full_link ); ?>" target="_blank" rel="noopener noreferrer">Open archive</a>
                                    <?php elseif ( $drive_folder_id !== '' ) : ?>
                                        <code><?php echo esc_html( $drive_folder_id ); ?></code>
                                    <?php else : ?>
                                        <span class="mw-help">Not uploaded</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ( $is_system_admin ) : ?>
                                    <td>
                                        <button
                                            type="button"
                                            class="mw-btn mw-btn-xs mw-btn-ghost"
                                            data-backup-restore-run="<?php echo esc_attr( $run_uuid ); ?>"
                                            <?php disabled( $status !== 'success' ); ?>
                                        >Restore</button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ( $is_system_admin ) : ?>
                <p class="mw-help">Restore replays config, uploads, runtime data, then the database snapshot from the selected run. Use carefully on the target environment.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
