<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'scheduler' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );

$finance_can_view = function_exists( 'metis_finance_can_view' ) ? (bool) metis_finance_can_view() : false;
$finance_can_manage = function_exists( 'metis_finance_can_manage' ) ? (bool) metis_finance_can_manage() : false;
$finance_mode_state = $finance_can_view && function_exists( 'metis_finance_mode_switch_status' )
    ? (array) metis_finance_mode_switch_status()
    : [];
$finance_pending_switch = isset( $finance_mode_state['pending_switch'] ) && is_array( $finance_mode_state['pending_switch'] )
    ? $finance_mode_state['pending_switch']
    : null;
$finance_pending_effective_raw = is_array( $finance_pending_switch ) ? (string) ( $finance_pending_switch['effective_at'] ?? '' ) : '';
$finance_pending_effective_local = $finance_pending_effective_raw !== '' && strtotime( $finance_pending_effective_raw ) !== false
    ? date( 'Y-m-d\TH:i', (int) strtotime( $finance_pending_effective_raw ) )
    : '';
$finance_schedule_nonce = function_exists( 'metis_runtime_create_nonce' )
    ? (string) metis_runtime_create_nonce( 'metis_finance_mode_switch_schedule' )
    : '';
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Manage the Cloudflare Worker scheduler endpoint and shared secret.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'scheduler' ); ?>
<div class="metis-settings-card" data-scheduler-live-root="1">
    <div class="metis-settings-header"><h2>Async Queue Status</h2></div>
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
            </tbody>
        </table>
        <p class="metis-help">Cron requests now queue work and return quickly. The queue drain starts after the response flushes, and any remaining work is picked up on the next scheduler cycle.</p>
    </div>
</div>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="scheduler">
    <?php metis_runtime_nonce_field( 'metis_save_settings_scheduler', 'metis_settings_nonce' ); ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header">
            <h2>Scheduler Endpoint</h2>
            <span class="metis-settings-status <?php echo $system_cron_configured ? 'is-ok' : 'is-missing'; ?>"><?php echo $system_cron_configured ? 'Configured' : 'Needs Secret'; ?></span>
        </div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label>Cloudflare Worker Endpoint</label>
                <div class="metis-shortcode-wrap">
                    <code class="metis-shortcode" id="metis-cron-endpoint"><?php echo metis_escape_html( (string) $system_cron_endpoint ); ?></code>
                    <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-copy-target="metis-cron-endpoint">Copy</button>
                </div>
                <p class="metis-help">Register your Worker to send a <code>POST</code> request to this URL.</p>
            </div>
            <div class="metis-field">
                <label>Required Header</label>
                <div class="metis-shortcode-wrap">
                    <code class="metis-shortcode" id="metis-cron-header"><?php echo metis_escape_html( (string) $system_cron_header ); ?></code>
                    <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-copy-target="metis-cron-header">Copy</button>
                </div>
                <p class="metis-help">Set this header to the shared secret stored below.</p>
            </div>
            <div class="metis-field">
                <label for="system_cron_secret">Shared Secret</label>
                <?php if ( ! $is_system_admin ) : ?>
                    <input type="password" id="system_cron_secret" class="metis-input metis-input-wide" value="" placeholder="<?php echo metis_escape_attr( $system_cron_secret_masked !== '' ? $system_cron_secret_masked : 'Not configured' ); ?>" disabled>
                    <p class="metis-help">Only system admins can rotate the scheduler secret.</p>
                <?php else : ?>
                    <input type="password" id="system_cron_secret" name="system_cron_secret" class="metis-input metis-input-wide" autocomplete="new-password" placeholder="<?php echo metis_escape_attr( $system_cron_secret_masked !== '' ? $system_cron_secret_masked : 'Enter a strong shared secret' ); ?>">
                    <p class="metis-help">Leave blank to keep the current secret. Enter a new value to rotate it.</p>
                <?php endif; ?>
            </div>
            <?php if ( $is_system_admin ) : ?>
                <div class="metis-settings-actions" style="justify-content:flex-start;">
                    <button type="submit" class="metis-btn" name="metis_generate_cron_secret" value="1">Generate New Secret</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ( $is_system_admin ) : ?>
        <div class="metis-settings-actions">
            <button type="submit" class="metis-btn">Save Scheduler Secret</button>
        </div>
    <?php endif; ?>
</form>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Worker Setup</h2></div>
        <div class="metis-settings-body">
            <details>
                <summary class="metis-btn metis-btn-xs metis-btn-ghost">Show Worker Script</summary>
                <p class="metis-help" style="margin-top:10px;">Cloudflare Worker script (copy/paste):</p>
                <div class="metis-shortcode-wrap" style="display:block; max-width:100%;">
<pre class="metis-shortcode" id="metis-cron-worker-script" style="white-space:pre; overflow:auto; max-height:240px;"><?php echo metis_escape_html( <<<'JS'
export default {
  async scheduled(event, env, ctx) {
    ctx.waitUntil(triggerCron(env, "scheduled"));
  },
  async fetch(request, env) {
    if (request.method !== "POST" && request.method !== "GET") {
      return new Response("Method Not Allowed", { status: 405 });
    }
    const trigger = request.method === "GET" ? "manual_get" : "manual";
    const result = await triggerCron(env, trigger);
    return new Response(JSON.stringify(result.body), {
      status: result.status,
      headers: { "content-type": "application/json; charset=UTF-8" },
    });
  },
};
async function triggerCron(env, trigger) {
  if (!env.METIS_ORIGIN_URL || !env.METIS_CRON_SECRET) {
    return { status: 500, body: { success: false, error: "Worker environment is missing METIS_ORIGIN_URL or METIS_CRON_SECRET." } };
  }
  const origin = new URL(env.METIS_ORIGIN_URL);
  const basePath = origin.pathname.endsWith("/") ? origin.pathname : origin.pathname + "/";
  const url = new URL("system/cron", origin.origin + basePath);
  const requestId = crypto.randomUUID();
  const response = await fetch(url.toString(), {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "x-metis-cron-secret": env.METIS_CRON_SECRET,
      "x-request-id": requestId,
      "user-agent": "metis-cloudflare-cron/1.0",
    },
    body: JSON.stringify({ trigger }),
  });
  let body;
  try { body = await response.json(); }
  catch { body = { success: false, error: "Cron endpoint returned a non-JSON response." }; }
  return { status: response.status, body };
}
JS
); ?></pre>
                    <div style="margin-top:10px;">
                        <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-copy-target="metis-cron-worker-script">Copy Worker Script</button>
                    </div>
                </div>
            </details>
            <p class="metis-help">Set Worker env vars: <code>METIS_ORIGIN_URL</code> (your Metis base URL) and <code>METIS_CRON_SECRET</code> (same scheduler secret above). Run every minute.</p>
        </div>
    </div>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Registered Tasks</h2></div>
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
                <?php foreach ( $system_cron_task_rows as $task_row ) : ?>
                    <tr
                        class="metis-premium-row metis-scheduler-row <?php echo ! empty( $task_row['enabled'] ) ? 'is-enabled' : 'is-disabled'; ?>"
                        data-cron-task-row="<?php echo metis_escape_attr( (string) $task_row['slug'] ); ?>"
                        data-cron-task-enabled="<?php echo ! empty( $task_row['enabled'] ) ? '1' : '0'; ?>"
                        <?php if ( $is_system_admin ) : ?>
                            title="Double-click to toggle this task"
                        <?php endif; ?>
                    >
                        <td class="metis-premium-cell">
                            <strong><?php echo metis_escape_html( (string) $task_row['label'] ); ?></strong><br>
                            <code><?php echo metis_escape_html( (string) $task_row['slug'] ); ?></code>
                            <?php if ( (string) $task_row['last_error'] !== '' ) : ?>
                                <div class="metis-help" style="margin-top:6px; color:#b91c1c;"><?php echo metis_escape_html( 'Task failed. Review logs for details.' ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( (string) $task_row['module'] ) ); ?></td>
                        <td class="metis-premium-cell">
                            <?php if ( $is_system_admin ) : ?>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input
                                        type="number"
                                        min="1"
                                        step="1"
                                        data-cron-task-interval="<?php echo metis_escape_attr( (string) $task_row['slug'] ); ?>"
                                        value="<?php echo metis_escape_attr( (string) $task_row['interval_minutes'] ); ?>"
                                        class="metis-input"
                                        style="width:88px;"
                                    >
                                    <span class="metis-help" style="margin:0;">min</span>
                                </div>
                                <div class="metis-help">Default: <?php echo metis_escape_html( (string) $task_row['default_interval_minutes'] ); ?> min</div>
                            <?php else : ?>
                                <?php echo metis_escape_html( (string) $task_row['interval_label'] ); ?>
                            <?php endif; ?>
                        </td>
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
                </tbody>
            </table>
            <?php if ( $is_system_admin ) : ?>
                <p class="metis-help">Double-click a row to turn a task on or off. Change the minutes field and tab out to save the cadence.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Recent Cron Jobs</h2></div>
        <div class="metis-settings-body">
            <table class="metis-premium-table metis-scheduler-history-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Task</th>
                        <th class="metis-premium-cell" scope="col">Association</th>
                        <th class="metis-premium-cell" scope="col">Status</th>
                        <th class="metis-premium-cell" scope="col">Started</th>
                        <th class="metis-premium-cell" scope="col">Finished</th>
                        <th class="metis-premium-cell" scope="col">Job</th>
                    </tr>
                </thead>
                <tbody data-scheduler-history-body="1">
                    <?php if ( empty( $system_cron_recent_jobs ) ) : ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell"><strong>No queued cron job history yet.</strong></td>
                            <td class="metis-premium-cell">-</td>
                            <td class="metis-premium-cell">-</td>
                            <td class="metis-premium-cell">-</td>
                            <td class="metis-premium-cell">-</td>
                            <td class="metis-premium-cell">-</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $system_cron_recent_jobs as $job_row ) : ?>
                            <tr class="metis-premium-row">
                                <td class="metis-premium-cell">
                                    <div class="metis-scheduler-history-task">
                                        <strong><?php echo metis_escape_html( (string) ( $job_row['task_label'] ?? $job_row['task'] ?? $job_row['label'] ?? 'Cron Task' ) ); ?></strong>
                                        <code><?php echo metis_escape_html( (string) ( $job_row['job_code'] ?? '' ) ); ?></code>
                                    </div>
                                </td>
                                <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $job_row['association'] ?? 'Scheduled cron callback' ) ); ?></td>
                                <td class="metis-premium-cell">
                                    <span class="metis-status-chip is-<?php echo metis_escape_attr( strtolower( (string) ( $job_row['status'] ?? 'unknown' ) ) ); ?>">
                                        <?php echo metis_escape_html( ucfirst( (string) ( $job_row['status'] ?? 'unknown' ) ) ); ?>
                                    </span>
                                </td>
                                <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $job_row['started_at_display'] ?? 'Pending' ) ); ?></td>
                                <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $job_row['finished_at_display'] ?? '-' ) ); ?></td>
                                <td class="metis-premium-cell">
                                    <?php if ( (string) ( $job_row['last_error'] ?? '' ) !== '' ) : ?>
                                        <div class="metis-help" style="color:#b91c1c;"><?php echo metis_escape_html( 'Job failed. Review logs for details.' ); ?></div>
                                    <?php else : ?>
                                        <span class="metis-help"><?php echo metis_escape_html( ucfirst( (string) ( $job_row['queue_name'] ?? 'system' ) ) ); ?> queue</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php if ( $finance_can_view ) : ?>
    <div
        class="metis-settings-card"
        data-finance-mode-root="1"
        data-finance-status-action="metis_finance_mode_status"
        data-finance-switch-status-action="metis_finance_mode_switch_status"
        data-finance-schedule-action="metis_finance_mode_switch_schedule"
        data-finance-schedule-nonce="<?php echo metis_escape_attr( $finance_schedule_nonce ); ?>"
    >
        <div class="metis-settings-header">
            <h2>Finance Mode Switch</h2>
            <span class="metis-settings-status is-ok" data-finance-switch-status-badge="1"><?php echo metis_escape_html( ucfirst( (string) ( $finance_mode_state['switch_status'] ?? 'idle' ) ) ); ?></span>
        </div>
        <div class="metis-settings-body">
            <div class="metis-finance-mode-grid">
                <div class="metis-field">
                    <label>Current Mode</label>
                    <div><strong data-finance-current-mode="1"><?php echo metis_escape_html( strtoupper( (string) ( $finance_mode_state['current_mode'] ?? 'finance' ) ) ); ?></strong></div>
                </div>
                <div class="metis-field">
                    <label>Switch Status</label>
                    <div data-finance-switch-status="1"><?php echo metis_escape_html( ucfirst( (string) ( $finance_mode_state['switch_status'] ?? 'idle' ) ) ); ?></div>
                </div>
            </div>

            <div class="metis-finance-pending" data-finance-pending-wrap="1" style="<?php echo is_array( $finance_pending_switch ) ? '' : 'display:none;'; ?>">
                <div class="metis-field">
                    <label>Scheduled Target</label>
                    <div data-finance-pending-target="1"><?php echo metis_escape_html( strtoupper( (string) ( $finance_pending_switch['target_mode'] ?? '' ) ) ); ?></div>
                </div>
                <div class="metis-field">
                    <label>Effective At</label>
                    <div data-finance-pending-effective="1"><?php echo metis_escape_html( (string) ( $finance_pending_switch['effective_at'] ?? '-' ) ); ?></div>
                </div>
                <div class="metis-field">
                    <label>Queue Job</label>
                    <div data-finance-pending-queue="1"><?php echo metis_escape_html( (string) ( $finance_pending_switch['queue_job_code'] ?? '-' ) ); ?></div>
                </div>
            </div>

            <?php if ( $finance_can_manage ) : ?>
                <form class="metis-finance-mode-form" data-finance-schedule-form="1">
                    <input type="hidden" name="metis_action_nonce" value="<?php echo metis_escape_attr( $finance_schedule_nonce ); ?>">
                    <div class="metis-field">
                        <label for="finance_mode_target">Target Mode</label>
                        <select id="finance_mode_target" name="target_mode" class="metis-input metis-input-wide">
                            <option value="finance">Finance</option>
                            <option value="accounting">Accounting</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label for="finance_mode_effective_at">Effective Date/Time</label>
                        <input
                            type="datetime-local"
                            id="finance_mode_effective_at"
                            name="effective_at"
                            class="metis-input metis-input-wide"
                            value="<?php echo metis_escape_attr( $finance_pending_effective_local ); ?>"
                        >
                        <p class="metis-help">Mode switches are scheduled and run through preflight validation at activation time.</p>
                    </div>
                    <div class="metis-settings-actions" style="padding:0;">
                        <button type="submit" class="metis-btn" data-finance-schedule-submit="1">Schedule Mode Switch</button>
                    </div>
                </form>
            <?php else : ?>
                <p class="metis-help">You can view mode status, but only users with Finance manage permission can schedule mode switches.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php metis_settings_render_section_end(); ?>
