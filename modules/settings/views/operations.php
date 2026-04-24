<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'operations' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Queue approved administrative operations and review recent async activity.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'operations' ); ?>
<div class="mw-settings-card">
    <div class="mw-settings-header"><h2>Operations Console</h2></div>
    <div class="mw-settings-body">
        <div class="mw-field">
            <label for="metis-operations-command">Approved Command</label>
            <input type="text" id="metis-operations-command" class="mw-input mw-input-wide" placeholder="Example: cron run integrity_scan" data-operations-command-input>
            <p class="mw-help">Commands are allowlisted and execute through the async job queue. This is an operations console, not a raw shell.</p>
        </div>
        <div class="mw-settings-actions" style="justify-content:flex-start;">
            <button type="button" class="mw-btn" data-operations-command-submit>Queue Command</button>
        </div>
        <div class="mw-help" data-operations-command-status></div>
    </div>
</div>

<div class="mw-settings-card">
    <div class="mw-settings-header"><h2>Approved Commands</h2></div>
    <div class="mw-settings-body">
        <div class="mw-table-wrap">
            <table class="mw-table">
                <thead>
                    <tr>
                        <th>Command</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $operations_command_catalog as $command_row ) : ?>
                        <tr>
                            <td><code><?php echo metis_escape_html( (string) ( $command_row['command'] ?? '' ) ); ?></code></td>
                            <td><?php echo metis_escape_html( (string) ( $command_row['description'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mw-settings-card">
    <div class="mw-settings-header"><h2>Recent Operations</h2></div>
    <div class="mw-settings-body">
        <div class="mw-table-wrap">
            <table class="mw-table">
                <thead>
                    <tr>
                        <th>Operation</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Finished</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $operations_recent_jobs ) ) : ?>
                        <tr><td colspan="5">No operations have been queued yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $operations_recent_jobs as $job_row ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo metis_escape_html( (string) ( $job_row['label'] ?: $job_row['operation'] ?: 'Operation' ) ); ?></strong><br>
                                    <code><?php echo metis_escape_html( (string) ( $job_row['job_code'] ?? '' ) ); ?></code>
                                </td>
                                <td><?php echo metis_escape_html( ucfirst( (string) ( $job_row['status'] ?? 'unknown' ) ) ); ?></td>
                                <td><?php echo metis_escape_html( (string) ( $job_row['available_at'] ?: '-' ) ); ?></td>
                                <td><?php echo metis_escape_html( (string) ( $job_row['completed_at'] ?: $job_row['failed_at'] ?: '-' ) ); ?></td>
                                <td>
                                    <?php if ( (string) ( $job_row['last_error'] ?? '' ) !== '' ) : ?>
                                        <div class="mw-help" style="color:#b91c1c;"><?php echo metis_escape_html( 'Operation failed. Review logs for details.' ); ?></div>
                                    <?php elseif ( ! empty( $job_row['result'] ) ) : ?>
                                        <code><?php echo metis_escape_html( substr( metis_json_encode( (array) $job_row['result'] ) ?: '{}', 0, 180 ) ); ?></code>
                                    <?php else : ?>
                                        <span class="mw-help">Pending result</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php metis_settings_render_section_end(); ?>
