<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$base_url = rtrim( metis_donations_base_url(), '/' );
?>

<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Donation Reports' ) ); ?></h1>
<p class="metis-subtitle">Analyze giving trends, campaign performance, and donor behavior.</p>

<div class="metis-report-wrapper">

    <!-- ================================================================
         CONTROLS
    ================================================================ -->
    <div class="metis-report-panel">

        <div class="metis-report-controls">

            <div class="metis-report-field">
                <label for="report_start">Start Date</label>
                <input type="date" id="report_start" class="metis-input">
            </div>

            <div class="metis-report-field">
                <label for="report_end">End Date</label>
                <input type="date" id="report_end" class="metis-input">
            </div>

            <div class="metis-report-field metis-report-field--lifetime">
                <label class="metis-lifetime-label">
                    <input type="checkbox" id="report_lifetime">
                    <span>Lifetime<br><small>all time</small></span>
                </label>
            </div>

            <div class="metis-report-field">
                <label for="report_group">Group By</label>
                <select id="report_group" class="metis-select">
                    <option value="day">Daily</option>
                    <option value="week">Weekly</option>
                    <option value="month" selected>Monthly</option>
                    <option value="year">Yearly</option>
                    <option value="campaign">Campaign</option>
                    <option value="pay_method">Payment Method</option>
                    <option value="donor">Donor Intelligence</option>
                </select>
            </div>

            <div class="metis-report-field">
                <label for="report_platform">Platform</label>
                <select id="report_platform" class="metis-select">
                    <option value="ALL" selected>All Platforms</option>
                    <option value="ST">Stripe</option>
                    <option value="GB">Givebutter</option>
                    <option value="OL">Offline</option>
                </select>
            </div>

            <div class="metis-report-field">
                <label for="report_status">Status</label>
                <select id="report_status" class="metis-select">
                    <option value="ALL" selected>All Statuses</option>
                    <option value="completed">Completed</option>
                    <option value="refunded">Refunded</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>

            <div class="metis-report-field" id="metis-chart-mode-field">
                <label>Chart Mode</label>
                <div class="metis-radio-group">
                    <label><input type="radio" name="chart_mode" value="grouped" checked> Grouped</label>
                    <label><input type="radio" name="chart_mode" value="stacked"> Stacked</label>
                    <label><input type="radio" name="chart_mode" value="cumulative"> Cumulative</label>
                </div>
            </div>

            <div class="metis-report-field" id="metis-chart-type-field">
                <label for="report_chart_type">Chart Type</label>
                <select id="report_chart_type" class="metis-select">
                    <option value="auto" selected>Auto</option>
                    <option value="bar">Bar</option>
                    <option value="line">Line</option>
                </select>
            </div>

            <div class="metis-report-field" id="metis-compare-field">
                <label for="report_compare">Compare</label>
                <select id="report_compare" class="metis-select">
                    <option value="none" selected>None</option>
                    <option value="mom">Month over Month</option>
                    <option value="qoq">Quarter over Quarter</option>
                    <option value="yoy">Year over Year</option>
                </select>
            </div>

            <div class="metis-report-field" id="metis-topn-field">
                <label for="report_top_n">Top Donors</label>
                <select id="report_top_n" class="metis-select">
                    <option value="0" selected>Off</option>
                    <option value="5">Top 5</option>
                    <option value="10">Top 10</option>
                </select>
            </div>

            <div class="metis-report-field metis-report-field--run">
                <label>&nbsp;</label>
                <button id="report_run" class="metis-btn">Run Report</button>
                <button id="report_reset" class="metis-btn metis-btn-ghost">Reset</button>
            </div>

        </div>

        <!-- METRIC TOGGLES -->
        <div class="metis-metric-toggles" id="metis-metric-toggles">
            <span class="metis-muted" style="font-size:12px; align-self:center;">Metrics:</span>
            <label><input type="checkbox" class="metis-metric-toggle" value="gross" checked> Gross</label>
            <label><input type="checkbox" class="metis-metric-toggle" value="fee" checked> Fees</label>
            <label><input type="checkbox" class="metis-metric-toggle" value="net" checked> Net</label>
            <label><input type="checkbox" class="metis-metric-toggle" value="count"> Count</label>
            <label><input type="checkbox" class="metis-metric-toggle" value="avg"> Avg Gift</label>
            <label><input type="checkbox" class="metis-metric-toggle" value="fee_pct"> Fee %</label>
        </div>

    </div>

    <!-- STATUS -->
    <div id="metis-report-status" class="metis-report-status" style="display:none;"></div>

    <!-- COMPARISON CARDS (period-over-period ↑↓ cards) -->
    <div id="metis-report-comparison-cards" class="metis-report-comparison-cards" style="display:none;"></div>

    <!-- COMPARISON SUMMARY BANNER -->
    <div id="metis-report-comparison-summary" class="metis-report-comparison-summary" style="display:none;"></div>

    <!-- KPI CARDS -->
    <div class="metis-report-kpis" id="metis-report-kpis" style="display:none;">
        <div id="kpi_total"   class="metis-kpi-card"></div>
        <div id="kpi_fees"    class="metis-kpi-card"></div>
        <div id="kpi_net"     class="metis-kpi-card"></div>
        <div id="kpi_count"   class="metis-kpi-card"></div>
        <div id="kpi_avg"     class="metis-kpi-card"></div>
        <div id="kpi_fee_pct" class="metis-kpi-card"></div>
    </div>

    <!-- EXPORT + SAVE -->
    <div class="metis-report-actions" id="metis-report-actions" style="display:none;">
        <div class="metis-report-export">
            <button id="report_export_csv" class="metis-btn metis-btn-xs">Export CSV</button>
            <button id="report_export_png" class="metis-btn metis-btn-xs">Export PNG</button>
            <button id="report_export_pdf" class="metis-btn metis-btn-xs">Export PDF</button>
        </div>
        <div class="metis-report-save-row">
            <input type="text" id="metis-report-save-name" class="metis-input" placeholder="Report name…" style="width:200px;">
            <button id="metis-report-save-btn" class="metis-btn metis-btn-xs">Save Report</button>
        </div>
    </div>

    <!-- CHART -->
    <div class="metis-report-chart" id="metis-report-chart-wrap" style="display:none;">
        <canvas id="donationsChart"></canvas>
    </div>

    <!-- TOP DONORS WIDGET -->
    <div id="metis-top-donors-wrap" style="display:none;">
        <h2 class="metis-section-header">Top Donors</h2>
        <div id="metis-top-donors-table"></div>
    </div>

    <!-- CAMPAIGN TABLE (shown when group=campaign) -->
    <div id="metis-campaign-results" style="display:none;">
        <h2 class="metis-section-header">Campaign Breakdown</h2>
        <div id="metis-campaign-table"></div>
    </div>

    <!-- PAY METHOD TABLE (shown when group=pay_method) -->
    <div id="metis-paymethod-results" style="display:none;">
        <h2 class="metis-section-header">Payment Method Breakdown</h2>
        <div id="metis-paymethod-table"></div>
    </div>

    <!-- DONOR INTELLIGENCE RESULTS -->
    <div id="metis-donor-results" style="display:none;"></div>

    <!-- ================================================================
         SAVED REPORTS PANEL
    ================================================================ -->
    <div class="metis-saved-reports-panel" id="metis-saved-reports-panel">
        <div class="metis-saved-reports-header">
            <h2 class="metis-section-header" style="margin:0;">Saved Reports</h2>
            <button id="metis-saved-reports-refresh" class="metis-btn metis-btn-xs">↻ Refresh</button>
        </div>
        <div id="metis-saved-reports-list" class="metis-saved-reports-list">
            <p class="metis-muted">Loading…</p>
        </div>
    </div>

</div>

<?php
$base_url_js = metis_escape_js( $base_url );
?>
<script>
window.MWReportsBaseUrl = '<?php echo $base_url_js; ?>';
</script>
