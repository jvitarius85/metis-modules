<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'system_health' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Run a system health snapshot of core runtime settings.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'system_health' ); ?>
<div class="metis-settings-card" data-settings-checker-root="1">
        <div class="metis-settings-header">
            <h2>System Health</h2>
        </div>
    <div class="metis-settings-body">
        <div class="metis-checker-kpi-grid" data-settings-checker-kpis>
        </div>

        <table class="metis-premium-table metis-checker-summary-table" style="margin-bottom:12px;">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Score</th>
                    <th class="metis-premium-cell" scope="col">Pass</th>
                    <th class="metis-premium-cell" scope="col">Warn</th>
                    <th class="metis-premium-cell" scope="col">Fail</th>
                    <th class="metis-premium-cell" scope="col">Generated</th>
                </tr>
            </thead>
            <tbody>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell" data-checker-score>0/100</td>
                    <td class="metis-premium-cell" data-checker-count="pass">0</td>
                    <td class="metis-premium-cell" data-checker-count="warn">0</td>
                    <td class="metis-premium-cell" data-checker-count="fail">0</td>
                    <td class="metis-premium-cell" data-checker-generated>-</td>
                </tr>
            </tbody>
        </table>

        <div class="metis-help" data-settings-checker-status>Run Checker to load current health checks.</div>

        <div class="metis-checker-tabs" data-settings-checker-tabs>
            <button type="button" class="metis-checker-tab is-active" data-checker-filter="all">
                All
                <span class="metis-checker-tab-count" data-checker-tab-count="all">0</span>
            </button>
            <button type="button" class="metis-checker-tab" data-checker-filter="pass">
                Pass
                <span class="metis-checker-tab-count" data-checker-tab-count="pass">0</span>
            </button>
            <button type="button" class="metis-checker-tab" data-checker-filter="warn">
                Warn
                <span class="metis-checker-tab-count" data-checker-tab-count="warn">0</span>
            </button>
            <button type="button" class="metis-checker-tab" data-checker-filter="fail">
                Fail
                <span class="metis-checker-tab-count" data-checker-tab-count="fail">0</span>
            </button>
        </div>

        <table class="metis-premium-table metis-checker-results-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell" scope="col">Category</th>
                    <th class="metis-premium-cell" scope="col">Check</th>
                    <th class="metis-premium-cell" scope="col">Finding</th>
                    <th class="metis-premium-cell" scope="col">Recommendation</th>
                </tr>
            </thead>
            <tbody data-settings-checker-results>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell" colspan="5">No checks loaded yet.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="metis-settings-actions">
        <button type="button" class="metis-btn" data-settings-checker-refresh>Run Checker</button>
        <button type="button" class="metis-btn metis-btn-secondary" data-settings-checker-remediate>Auto Remediate</button>
        <button type="button" class="metis-btn metis-btn-secondary" data-settings-checker-permission-plan>Generate Permission Plan</button>
    </div>
</div>
<?php metis_settings_render_section_end(); ?>
