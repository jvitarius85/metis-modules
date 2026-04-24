<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$base_url = rtrim( metis_donations_base_url(), '/' );
?>

<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Donation Reports' ) ); ?></h1>
<p class="mw-subtitle">Analyze giving trends, campaign performance, and donor behavior.</p>

<div class="mw-report-wrapper">

    <!-- ================================================================
         CONTROLS
    ================================================================ -->
    <div class="mw-report-panel">

        <div class="mw-report-controls">

            <div class="mw-report-field">
                <label for="report_start">Start Date</label>
                <input type="date" id="report_start" class="mw-input">
            </div>

            <div class="mw-report-field">
                <label for="report_end">End Date</label>
                <input type="date" id="report_end" class="mw-input">
            </div>

            <div class="mw-report-field mw-report-field--lifetime">
                <label class="mw-lifetime-label">
                    <input type="checkbox" id="report_lifetime">
                    <span>Lifetime<br><small>all time</small></span>
                </label>
            </div>

            <div class="mw-report-field">
                <label for="report_group">Group By</label>
                <select id="report_group" class="mw-select">
                    <option value="day">Daily</option>
                    <option value="week">Weekly</option>
                    <option value="month" selected>Monthly</option>
                    <option value="year">Yearly</option>
                    <option value="campaign">Campaign</option>
                    <option value="pay_method">Payment Method</option>
                    <option value="donor">Donor Intelligence</option>
                </select>
            </div>

            <div class="mw-report-field">
                <label for="report_platform">Platform</label>
                <select id="report_platform" class="mw-select">
                    <option value="ALL" selected>All Platforms</option>
                    <option value="ST">Stripe</option>
                    <option value="GB">Givebutter</option>
                    <option value="OL">Offline</option>
                </select>
            </div>

            <div class="mw-report-field">
                <label for="report_status">Status</label>
                <select id="report_status" class="mw-select">
                    <option value="ALL" selected>All Statuses</option>
                    <option value="completed">Completed</option>
                    <option value="refunded">Refunded</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>

            <div class="mw-report-field" id="mw-chart-mode-field">
                <label>Chart Mode</label>
                <div class="mw-radio-group">
                    <label><input type="radio" name="chart_mode" value="grouped" checked> Grouped</label>
                    <label><input type="radio" name="chart_mode" value="stacked"> Stacked</label>
                    <label><input type="radio" name="chart_mode" value="cumulative"> Cumulative</label>
                </div>
            </div>

            <div class="mw-report-field" id="mw-chart-type-field">
                <label for="report_chart_type">Chart Type</label>
                <select id="report_chart_type" class="mw-select">
                    <option value="auto" selected>Auto</option>
                    <option value="bar">Bar</option>
                    <option value="line">Line</option>
                </select>
            </div>

            <div class="mw-report-field" id="mw-compare-field">
                <label for="report_compare">Compare</label>
                <select id="report_compare" class="mw-select">
                    <option value="none" selected>None</option>
                    <option value="mom">Month over Month</option>
                    <option value="qoq">Quarter over Quarter</option>
                    <option value="yoy">Year over Year</option>
                </select>
            </div>

            <div class="mw-report-field" id="mw-topn-field">
                <label for="report_top_n">Top Donors</label>
                <select id="report_top_n" class="mw-select">
                    <option value="0" selected>Off</option>
                    <option value="5">Top 5</option>
                    <option value="10">Top 10</option>
                </select>
            </div>

            <div class="mw-report-field mw-report-field--run">
                <label>&nbsp;</label>
                <button id="report_run" class="mw-btn">Run Report</button>
                <button id="report_reset" class="mw-btn mw-btn-ghost">Reset</button>
            </div>

        </div>

        <!-- METRIC TOGGLES -->
        <div class="mw-metric-toggles" id="mw-metric-toggles">
            <span class="mw-muted" style="font-size:12px; align-self:center;">Metrics:</span>
            <label><input type="checkbox" class="mw-metric-toggle" value="gross" checked> Gross</label>
            <label><input type="checkbox" class="mw-metric-toggle" value="fee" checked> Fees</label>
            <label><input type="checkbox" class="mw-metric-toggle" value="net" checked> Net</label>
            <label><input type="checkbox" class="mw-metric-toggle" value="count"> Count</label>
            <label><input type="checkbox" class="mw-metric-toggle" value="avg"> Avg Gift</label>
            <label><input type="checkbox" class="mw-metric-toggle" value="fee_pct"> Fee %</label>
        </div>

    </div>

    <!-- STATUS -->
    <div id="mw-report-status" class="mw-report-status" style="display:none;"></div>

    <!-- COMPARISON CARDS (period-over-period ↑↓ cards) -->
    <div id="mw-report-comparison-cards" class="mw-report-comparison-cards" style="display:none;"></div>

    <!-- COMPARISON SUMMARY BANNER -->
    <div id="mw-report-comparison-summary" class="mw-report-comparison-summary" style="display:none;"></div>

    <!-- KPI CARDS -->
    <div class="mw-report-kpis" id="mw-report-kpis" style="display:none;">
        <div id="kpi_total"   class="mw-kpi-card"></div>
        <div id="kpi_fees"    class="mw-kpi-card"></div>
        <div id="kpi_net"     class="mw-kpi-card"></div>
        <div id="kpi_count"   class="mw-kpi-card"></div>
        <div id="kpi_avg"     class="mw-kpi-card"></div>
        <div id="kpi_fee_pct" class="mw-kpi-card"></div>
    </div>

    <!-- EXPORT + SAVE -->
    <div class="mw-report-actions" id="mw-report-actions" style="display:none;">
        <div class="mw-report-export">
            <button id="report_export_csv" class="mw-btn mw-btn-xs">Export CSV</button>
            <button id="report_export_png" class="mw-btn mw-btn-xs">Export PNG</button>
            <button id="report_export_pdf" class="mw-btn mw-btn-xs">Export PDF</button>
        </div>
        <div class="mw-report-save-row">
            <input type="text" id="mw-report-save-name" class="mw-input" placeholder="Report name…" style="width:200px;">
            <button id="mw-report-save-btn" class="mw-btn mw-btn-xs">Save Report</button>
        </div>
    </div>

    <!-- CHART -->
    <div class="mw-report-chart" id="mw-report-chart-wrap" style="display:none;">
        <canvas id="donationsChart"></canvas>
    </div>

    <!-- TOP DONORS WIDGET -->
    <div id="mw-top-donors-wrap" style="display:none;">
        <h2 class="mw-section-header">Top Donors</h2>
        <div class="mw-premium-table" id="mw-top-donors-table"></div>
    </div>

    <!-- CAMPAIGN TABLE (shown when group=campaign) -->
    <div id="mw-campaign-results" style="display:none;">
        <h2 class="mw-section-header">Campaign Breakdown</h2>
        <div class="mw-premium-table" id="mw-campaign-table"></div>
    </div>

    <!-- PAY METHOD TABLE (shown when group=pay_method) -->
    <div id="mw-paymethod-results" style="display:none;">
        <h2 class="mw-section-header">Payment Method Breakdown</h2>
        <div class="mw-premium-table" id="mw-paymethod-table"></div>
    </div>

    <!-- DONOR INTELLIGENCE RESULTS -->
    <div id="mw-donor-results" style="display:none;"></div>

    <!-- ================================================================
         SAVED REPORTS PANEL
    ================================================================ -->
    <div class="mw-saved-reports-panel" id="mw-saved-reports-panel">
        <div class="mw-saved-reports-header">
            <h2 class="mw-section-header" style="margin:0;">Saved Reports</h2>
            <button id="mw-saved-reports-refresh" class="mw-btn mw-btn-xs">↻ Refresh</button>
        </div>
        <div id="mw-saved-reports-list" class="mw-saved-reports-list">
            <p class="mw-muted">Loading…</p>
        </div>
    </div>

</div>

<?php
$base_url_js = metis_escape_js( $base_url );
?>
<script>
window.MWReportsBaseUrl = '<?php echo $base_url_js; ?>';
</script>
