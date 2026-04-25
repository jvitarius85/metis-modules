<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'jobs_tasks' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Review queue status, scheduler config, and approved operations.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'jobs_tasks' ); ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="jobs_tasks" data-scheduler-live-root="1">
    <?php metis_runtime_nonce_field( 'metis_save_settings_jobs_tasks', 'metis_settings_nonce' ); ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Queue Status</h2></div>
        <div class="metis-settings-body">
            <table class="metis-premium-table metis-scheduler-queue-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Queue</th>
                        <th class="metis-premium-cell" scope="col">Queued</th>
                        <th class="metis-premium-cell" scope="col">Processing</th>
                        <th class="metis-premium-cell" scope="col">Completed</th>
                        <th class="metis-premium-cell" scope="col">Failed</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong>Cron Tasks</strong></td>
                        <td class="metis-premium-cell" data-scheduler-queue="cron:queued"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['queued'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="cron:processing"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['processing'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="cron:completed"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['completed'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="cron:failed"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['cron']['failed'] ?? 0 ) ) ); ?></td>
                    </tr>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong>Operations</strong></td>
                        <td class="metis-premium-cell" data-scheduler-queue="operations:queued"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['queued'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="operations:processing"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['processing'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="operations:completed"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['completed'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="operations:failed"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['operations']['failed'] ?? 0 ) ) ); ?></td>
                    </tr>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong>Hermes Diagnostics</strong></td>
                        <td class="metis-premium-cell" data-scheduler-queue="hermes:queued"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['hermes']['queued'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="hermes:processing"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['hermes']['processing'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="hermes:completed"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['hermes']['completed'] ?? 0 ) ) ); ?></td>
                        <td class="metis-premium-cell" data-scheduler-queue="hermes:failed"><?php echo metis_escape_html( (string) ( (int) ( $queue_summary['hermes']['failed'] ?? 0 ) ) ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Registered Scheduled Tasks</h2></div>
        <div class="metis-settings-body">
            <table class="metis-premium-table metis-scheduler-table <?php echo $is_system_admin ? 'is-admin' : ''; ?>">
                <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Task</th>
                    <th class="metis-premium-cell" scope="col">Module</th>
                    <th class="metis-premium-cell" scope="col">Cadence</th>
                    <th class="metis-premium-cell" scope="col">Last Status</th>
                    <th class="metis-premium-cell" scope="col">Last Run</th>
                    <?php if ( $is_system_admin ) : ?>
                        <th class="metis-premium-cell" scope="col">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ( empty( $system_cron_task_rows ) ) : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell">No scheduled system tasks are registered.</td>
                        <td class="metis-premium-cell">-</td>
                        <td class="metis-premium-cell">-</td>
                        <td class="metis-premium-cell">-</td>
                        <td class="metis-premium-cell">-</td>
                        <?php if ( $is_system_admin ) : ?>
                            <td class="metis-premium-cell">-</td>
                        <?php endif; ?>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $system_cron_task_rows as $task_row ) : ?>
                        <tr
                            class="metis-premium-row metis-scheduler-row <?php echo ! empty( $task_row['enabled'] ) ? 'is-enabled' : 'is-disabled'; ?>"
                            data-cron-task-row="<?php echo metis_escape_attr( (string) $task_row['slug'] ); ?>"
                            data-cron-task-enabled="<?php echo ! empty( $task_row['enabled'] ) ? '1' : '0'; ?>"
                        >
                            <td class="metis-premium-cell">
                                <strong><?php echo metis_escape_html( (string) $task_row['label'] ); ?></strong><br>
                                <code><?php echo metis_escape_html( (string) $task_row['slug'] ); ?></code>
                                <?php if ( (string) $task_row['last_error'] !== '' ) : ?>
                                    <div class="metis-help"><?php echo metis_escape_html( 'Task failed. Review logs for details.' ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( (string) $task_row['module'] ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html( (string) $task_row['interval_label'] ); ?></td>
                            <td class="metis-premium-cell">
                                <span data-cron-task-state="<?php echo metis_escape_attr( (string) $task_row['slug'] ); ?>">
                                    <?php echo metis_escape_html( ucfirst( (string) $task_row['last_status'] ) ); ?>
                                </span>
                                <?php if ( empty( $task_row['enabled'] ) ) : ?>
                                    <div class="metis-help">Disabled</div>
                                <?php endif; ?>
                            </td>
                            <td class="metis-premium-cell" data-cron-task-last-run="<?php echo metis_escape_attr( (string) $task_row['slug'] ); ?>"><?php echo metis_escape_html( (string) ( $task_row['last_finished_at_display'] ?? 'Never' ) ); ?></td>
                            <?php if ( $is_system_admin ) : ?>
                                <td class="metis-premium-cell">
                                    <button
                                        type="button"
                                        class="metis-btn metis-btn-xs metis-btn-ghost"
                                        data-cron-run-now="<?php echo metis_escape_attr( (string) $task_row['slug'] ); ?>"
                                    >Run Now</button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Recent System Jobs</h2></div>
        <div class="metis-settings-body">
            <div class="metis-table-wrap">
                <table class="metis-table">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Type</th>
                            <th>Queue</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Started</th>
                            <th>Finished</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $recent_async_jobs ) ) : ?>
                            <tr><td colspan="8">No system jobs have been queued yet.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $recent_async_jobs as $job_row ) : ?>
                                <?php
                                $job_label = (string) ( $job_row['label'] ?: $job_row['task'] ?: $job_row['operation'] ?: $job_row['job_type'] ?: 'System job' );
                                $finished_at = (string) ( $job_row['completed_at'] ?: $job_row['failed_at'] ?: '' );
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo metis_escape_html( $job_label ); ?></strong><br>
                                        <code><?php echo metis_escape_html( (string) ( $job_row['job_code'] ?? '' ) ); ?></code>
                                    </td>
                                    <td><code><?php echo metis_escape_html( (string) ( $job_row['job_type'] ?? '' ) ); ?></code></td>
                                    <td><?php echo metis_escape_html( ucfirst( (string) ( $job_row['queue_name'] ?? 'system' ) ) ); ?></td>
                                    <td><?php echo metis_escape_html( ucfirst( (string) ( $job_row['status'] ?? 'unknown' ) ) ); ?></td>
                                    <td><?php echo metis_escape_html( (string) ( (int) ( $job_row['attempts'] ?? 0 ) ) . ' / ' . (string) ( (int) ( $job_row['max_attempts'] ?? 0 ) ) ); ?></td>
                                    <td><?php echo metis_escape_html( (string) ( $job_row['started_at'] ?: $job_row['available_at'] ?: '-' ) ); ?></td>
                                    <td><?php echo metis_escape_html( $finished_at !== '' ? $finished_at : '-' ); ?></td>
                                    <td>
                                        <?php if ( (string) ( $job_row['last_error'] ?? '' ) !== '' ) : ?>
                                            <span class="metis-help"><?php echo metis_escape_html( (string) $job_row['last_error'] ); ?></span>
                                        <?php elseif ( ! empty( $job_row['result'] ) ) : ?>
                                            <code><?php echo metis_escape_html( substr( metis_json_encode( (array) $job_row['result'] ) ?: '{}', 0, 160 ) ); ?></code>
                                        <?php else : ?>
                                            <span class="metis-help">Pending result</span>
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

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Scheduler Config</h2></div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label>Scheduler endpoint</label>
                <div class="metis-shortcode-wrap"><code class="metis-shortcode"><?php echo metis_escape_html( (string) $system_cron_endpoint ); ?></code></div>
            </div>
            <div class="metis-field">
                <label for="system_cron_secret">Shared secret</label>
                <input type="password" id="system_cron_secret" name="system_cron_secret" class="metis-input metis-input-wide" autocomplete="new-password" placeholder="<?php echo metis_escape_attr( $system_cron_secret_masked !== '' ? $system_cron_secret_masked : 'Enter a strong shared secret' ); ?>" <?php disabled( ! $is_system_admin ); ?>>
                <p class="metis-help">Leave blank to keep the current secret.</p>
            </div>
        </div>
    </div>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Operations Console</h2></div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label for="metis-operations-command">Approved Command</label>
                <input type="text" id="metis-operations-command" class="metis-input metis-input-wide" placeholder="Example: cron run integrity_scan" data-operations-command-input>
            </div>
            <div class="metis-settings-actions" style="justify-content:flex-start;">
                <button type="button" class="metis-btn" data-operations-command-submit>Queue Command</button>
            </div>
            <div class="metis-help" data-operations-command-status></div>
        </div>
    </div>

    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Jobs & Tasks Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
