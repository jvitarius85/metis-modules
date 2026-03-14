<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'scheduler' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Manage the Cloudflare Worker scheduler endpoint and shared secret.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'scheduler' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_scheduler', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header">
            <h2>Scheduler Endpoint</h2>
            <span class="mw-settings-status <?php echo $system_cron_configured ? 'is-ok' : 'is-missing'; ?>"><?php echo $system_cron_configured ? 'Configured' : 'Needs Secret'; ?></span>
        </div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label>Cloudflare Worker Endpoint</label>
                <div class="mw-shortcode-wrap">
                    <code class="mw-shortcode" id="mw-cron-endpoint"><?php echo esc_html( (string) $system_cron_endpoint ); ?></code>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-copy-target="mw-cron-endpoint">Copy</button>
                </div>
                <p class="mw-help">Register your Worker to send a <code>POST</code> request to this URL.</p>
            </div>
            <div class="mw-field">
                <label>Required Header</label>
                <div class="mw-shortcode-wrap">
                    <code class="mw-shortcode" id="mw-cron-header"><?php echo esc_html( (string) $system_cron_header ); ?></code>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-copy-target="mw-cron-header">Copy</button>
                </div>
                <p class="mw-help">Set this header to the shared secret stored below.</p>
            </div>
            <div class="mw-field">
                <label for="system_cron_secret">Shared Secret</label>
                <?php if ( ! $is_system_admin ) : ?>
                    <input type="password" id="system_cron_secret" class="mw-input mw-input-wide" value="" placeholder="<?php echo esc_attr( $system_cron_secret_masked !== '' ? $system_cron_secret_masked : 'Not configured' ); ?>" disabled>
                    <p class="mw-help">Only system admins can rotate the scheduler secret.</p>
                <?php else : ?>
                    <input type="password" id="system_cron_secret" name="system_cron_secret" class="mw-input mw-input-wide" autocomplete="new-password" placeholder="<?php echo esc_attr( $system_cron_secret_masked !== '' ? $system_cron_secret_masked : 'Enter a strong shared secret' ); ?>">
                    <p class="mw-help">Leave blank to keep the current secret. Enter a new value to rotate it.</p>
                <?php endif; ?>
            </div>
            <?php if ( $is_system_admin ) : ?>
                <div class="mw-settings-actions" style="justify-content:flex-start;">
                    <button type="submit" class="mw-btn" name="metis_generate_cron_secret" value="1">Generate New Secret</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ( $is_system_admin ) : ?>
        <div class="mw-settings-actions">
            <button type="submit" class="mw-btn">Save Scheduler Secret</button>
        </div>
    <?php endif; ?>
</form>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Worker Setup</h2></div>
        <div class="mw-settings-body">
            <p class="mw-help">Recommended Worker request:</p>
            <div class="mw-shortcode-wrap" style="display:block;">
<pre class="mw-shortcode" style="white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( [
    'method' => 'POST',
    'url' => (string) $system_cron_endpoint,
    'headers' => [
        (string) $system_cron_header => 'YOUR_SHARED_SECRET',
        'content-type' => 'application/json',
    ],
    'body' => [
        'trigger' => 'cloudflare_worker',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{}' ); ?></pre>
            </div>
            <p class="mw-help">Run the Worker every minute. The platform decides which tasks are actually due based on the schedules you set below.</p>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Registered Tasks</h2></div>
        <div class="mw-settings-body">
            <?php
            $release_current = is_array( $release_status['current'] ?? null ) ? $release_status['current'] : [];
            $release_latest = is_array( $release_status['latest'] ?? null ) ? $release_status['latest'] : [];
            $release_repository = is_array( $release_status['repository'] ?? null ) ? $release_status['repository'] : [];
            $release_can_apply_latest = $is_system_admin
                && ! empty( $release_status['update_available'] )
                && ! empty( $release_latest['tag'] );
            $release_can_rollback = $is_system_admin
                && is_array( $release_status['state'] ?? null )
                && (string) ( $release_status['state']['previous_tag'] ?? '' ) !== '';
            ?>
            <div class="mw-field">
                <label>Release Integrity</label>
                <div class="mw-shortcode-wrap" style="align-items:flex-start; gap:16px; flex-wrap:wrap;">
                    <div>
                        <div><strong>Installed</strong>: <code><?php echo esc_html( (string) ( $release_current['tag'] ?? $release_status['installed_version'] ?? 'unknown' ) ); ?></code></div>
                        <div class="mw-help">Version <?php echo esc_html( (string) ( $release_current['version'] ?? $release_status['installed_version'] ?? 'unknown' ) ); ?></div>
                    </div>
                    <div>
                        <div><strong>Latest Trusted</strong>: <code><?php echo esc_html( (string) ( $release_latest['tag'] ?? 'none' ) ); ?></code></div>
                        <div class="mw-help">
                            <?php if ( ! empty( $release_status['update_available'] ) ) : ?>
                                Update available
                            <?php elseif ( ! empty( $release_latest ) ) : ?>
                                Already current
                            <?php else : ?>
                                No trusted tags discovered yet
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div><strong>Repository</strong>: <code><?php echo ! empty( $release_repository['clean'] ) ? 'clean' : 'dirty'; ?></code></div>
                        <div class="mw-help">Last check: <?php echo esc_html( (string) ( $release_status['last_checked_at'] ?? 'never' ) ); ?></div>
                    </div>
                    <?php if ( $is_system_admin ) : ?>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-release-check-updates="1">Refresh Releases</button>
                            <?php if ( $release_can_apply_latest ) : ?>
                                <button
                                    type="button"
                                    class="mw-btn mw-btn-xs"
                                    data-release-apply-tag="<?php echo esc_attr( (string) $release_latest['tag'] ); ?>"
                                >Apply Latest</button>
                            <?php endif; ?>
                            <?php if ( $release_can_rollback ) : ?>
                                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-release-rollback="1">Rollback</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ( ! empty( $release_status['remote_error'] ) ) : ?>
                    <p class="mw-help" style="color:#b91c1c;"><?php echo esc_html( (string) $release_status['remote_error'] ); ?></p>
                <?php else : ?>
                    <p class="mw-help">Release checks trust semantic Git tags and cache the results locally. Updates require a clean worktree, a passing integrity scan, and a successful pre-update backup.</p>
                <?php endif; ?>
            </div>
            <div class="mw-field">
                <label>Integrity Baseline</label>
                <div class="mw-shortcode-wrap">
                    <code class="mw-shortcode">
                        <?php echo esc_html( ucfirst( (string) ( $integrity_baseline_status['status'] ?? 'unknown' ) ) ); ?>
                    </code>
                    <?php if ( $is_system_admin ) : ?>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-integrity-build-baseline="1">Build Baseline</button>
                    <?php endif; ?>
                </div>
                <?php if ( ! empty( $integrity_baseline_status['manifest_path'] ) ) : ?>
                    <p class="mw-help">Manifest: <?php echo esc_html( (string) $integrity_baseline_status['manifest_path'] ); ?></p>
                <?php else : ?>
                    <p class="mw-help">No manifest has been built yet. Build the baseline after code is in a trusted state.</p>
                <?php endif; ?>
            </div>
            <div class="mw-table-wrap">
                <table class="mw-table metis-scheduler-table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Module</th>
                            <th>Cadence</th>
                            <th>Last Status</th>
                            <th>Last Run</th>
                            <?php if ( $is_system_admin ) : ?>
                                <th></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $system_cron_task_rows as $task_row ) : ?>
                            <tr
                                class="metis-scheduler-row <?php echo ! empty( $task_row['enabled'] ) ? 'is-enabled' : 'is-disabled'; ?>"
                                data-cron-task-row="<?php echo esc_attr( (string) $task_row['slug'] ); ?>"
                                data-cron-task-enabled="<?php echo ! empty( $task_row['enabled'] ) ? '1' : '0'; ?>"
                                <?php if ( $is_system_admin ) : ?>
                                    title="Double-click to toggle this task"
                                <?php endif; ?>
                            >
                                <td>
                                    <strong><?php echo esc_html( (string) $task_row['label'] ); ?></strong><br>
                                    <code><?php echo esc_html( (string) $task_row['slug'] ); ?></code>
                                    <?php if ( (string) $task_row['last_error'] !== '' ) : ?>
                                        <div class="mw-help" style="margin-top:6px; color:#b91c1c;"><?php echo esc_html( (string) $task_row['last_error'] ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( ucfirst( (string) $task_row['module'] ) ); ?></td>
                                <td>
                                    <?php if ( $is_system_admin ) : ?>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <input
                                                type="number"
                                                min="1"
                                                step="1"
                                                data-cron-task-interval="<?php echo esc_attr( (string) $task_row['slug'] ); ?>"
                                                value="<?php echo esc_attr( (string) $task_row['interval_minutes'] ); ?>"
                                                class="mw-input"
                                                style="width:88px;"
                                            >
                                            <span class="mw-help" style="margin:0;">min</span>
                                        </div>
                                        <div class="mw-help">Default: <?php echo esc_html( (string) $task_row['default_interval_minutes'] ); ?> min</div>
                                    <?php else : ?>
                                        <?php echo esc_html( (string) $task_row['interval_label'] ); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span data-cron-task-state="<?php echo esc_attr( (string) $task_row['slug'] ); ?>">
                                        <?php echo esc_html( ucfirst( (string) $task_row['last_status'] ) ); ?>
                                    </span>
                                    <?php if ( empty( $task_row['enabled'] ) ) : ?>
                                        <div class="mw-help">Disabled</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( (string) ( $task_row['last_finished_at'] !== '' ? $task_row['last_finished_at'] : 'Never' ) ); ?></td>
                                <?php if ( $is_system_admin ) : ?>
                                    <td>
                                        <button
                                            type="button"
                                            class="mw-btn mw-btn-xs mw-btn-ghost"
                                            data-cron-run-now="<?php echo esc_attr( (string) $task_row['slug'] ); ?>"
                                        >Run Now</button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ( $is_system_admin ) : ?>
                <p class="mw-help">Double-click a row to turn a task on or off. Change the minutes field and tab out to save the cadence.</p>
            <?php endif; ?>
        </div>
    </div>
