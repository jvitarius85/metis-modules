<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'system_health' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Run a system health snapshot of core runtime settings.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'system_health' ); ?>
<div class="mw-settings-card" data-settings-checker-root="1">
        <div class="mw-settings-header">
            <h2>System Health</h2>
        </div>
    <div class="mw-settings-body">
        <div class="metis-checker-kpi-grid" data-settings-checker-kpis>
        </div>

        <div class="mw-premium-table metis-checker-summary-table" style="margin-bottom:12px;">
            <div class="mw-premium-row mw-premium-header">
                <div class="mw-premium-cell">Score</div>
                <div class="mw-premium-cell">Pass</div>
                <div class="mw-premium-cell">Warn</div>
                <div class="mw-premium-cell">Fail</div>
                <div class="mw-premium-cell">Generated</div>
            </div>
            <div class="mw-premium-row">
                <div class="mw-premium-cell" data-checker-score>0/100</div>
                <div class="mw-premium-cell" data-checker-count="pass">0</div>
                <div class="mw-premium-cell" data-checker-count="warn">0</div>
                <div class="mw-premium-cell" data-checker-count="fail">0</div>
                <div class="mw-premium-cell" data-checker-generated>-</div>
            </div>
        </div>

        <div class="mw-help" data-settings-checker-status>Run Checker to load current health checks.</div>

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

        <section class="mw-premium-table metis-checker-results-table" data-settings-checker-results>
            <div class="mw-premium-row mw-premium-header">
                <div class="mw-premium-cell">Status</div>
                <div class="mw-premium-cell">Category</div>
                <div class="mw-premium-cell">Check</div>
                <div class="mw-premium-cell">Finding</div>
                <div class="mw-premium-cell">Recommendation</div>
            </div>
            <div class="mw-premium-row">
                <div class="mw-premium-cell">No checks loaded yet.</div>
            </div>
        </section>
    </div>
    <div class="mw-settings-actions">
        <button type="button" class="mw-btn" data-settings-checker-refresh>Run Checker</button>
        <button type="button" class="mw-btn mw-btn-secondary" data-settings-checker-remediate>Auto Remediate</button>
        <button type="button" class="mw-btn mw-btn-secondary" data-settings-checker-permission-plan>Generate Permission Plan</button>
    </div>
</div>
<?php metis_settings_render_section_end(); ?>
