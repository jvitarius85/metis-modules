/**
 * Donations Reports JS
 * Handles: report running, chart rendering, KPI cards,
 *          campaign/pay-method tables, donor intelligence,
 *          cumulative mode, top-N donors, comparison cards,
 *          saved reports, CSV / PNG / PDF exports.
 */
(function () {

    'use strict';

    if ( typeof MWDonationsReports === 'undefined' ) {
        console.error( 'MWDonationsReports config not defined' );
        return;
    }

    const AJAX_URL  = MWDonationsReports.ajax_url;
    const NONCE     = MWDonationsReports.nonce;
    const DONOR_URL = ( typeof MWReportsBaseUrl !== 'undefined' ) ? MWReportsBaseUrl : '';

    // -------------------------------------------------------------------------
    // DOM refs
    // -------------------------------------------------------------------------

    const $  = id => document.getElementById( id );
    const $$ = sel => Array.from( document.querySelectorAll( sel ) );
    const confirmAction = (message, options) => {
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({ message }, options || {}));
        }
        return Promise.resolve(false);
    };

    const runBtn        = $( 'report_run' );
    const startInput    = $( 'report_start' );
    const endInput      = $( 'report_end' );
    const groupSelect   = $( 'report_group' );
    const chartCanvas   = $( 'donationsChart' );
    const lifetimeCk    = $( 'report_lifetime' );
    const compareSelect = $( 'report_compare' );
    const platformSel   = $( 'report_platform' );
    const statusSel     = $( 'report_status' );
    const chartTypeSel  = $( 'report_chart_type' );
    const topNSel       = $( 'report_top_n' );
    const statusBar     = $( 'metis-report-status' );

    const kpisWrap        = $( 'metis-report-kpis' );
    const actionsWrap     = $( 'metis-report-actions' );
    const chartWrap       = $( 'metis-report-chart-wrap' );
    const campaignWrap    = $( 'metis-campaign-results' );
    const paymethodWrap   = $( 'metis-paymethod-results' );
    const donorWrap       = $( 'metis-donor-results' );
    const compCards       = $( 'metis-report-comparison-cards' );
    const compSummary     = $( 'metis-report-comparison-summary' );
    const topDonorsWrap   = $( 'metis-top-donors-wrap' );

    const exportCsvBtn  = $( 'report_export_csv' );
    const exportPngBtn  = $( 'report_export_png' );
    const exportPdfBtn  = $( 'report_export_pdf' );
    const saveNameInput = $( 'metis-report-save-name' );
    const saveBtn       = $( 'metis-report-save-btn' );
    const savedList     = $( 'metis-saved-reports-list' );
    const savedRefresh  = $( 'metis-saved-reports-refresh' );

    if ( ! runBtn || ! chartCanvas ) {
        console.warn( 'MW Reports: required DOM elements missing' );
        return;
    }

    let donationsChart = null;
    let lastReport     = null;
    let currentSavedId = null;

    // Pay method display labels
    const PM_LABELS = {
        cc: 'Credit Card', ach: 'ACH', cash: 'Cash',
        ck: 'Check', other: 'Other', Unknown: 'Unknown',
    };

    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------

    runBtn.addEventListener( 'click', runReport );

    if ( lifetimeCk ) {
        lifetimeCk.addEventListener( 'change', function () {
            const off = this.checked;
            if ( startInput ) startInput.disabled = off;
            if ( endInput )   endInput.disabled   = off;
        } );
    }

    if ( groupSelect ) {
        groupSelect.addEventListener( 'change', syncControlVisibility );
    }

    const resetBtn = $( 'report_reset' );
    if ( resetBtn ) resetBtn.addEventListener( 'click', resetReport );

    if ( exportCsvBtn ) exportCsvBtn.addEventListener( 'click', exportCSV );
    if ( exportPngBtn ) exportPngBtn.addEventListener( 'click', exportPNG );
    if ( exportPdfBtn ) exportPdfBtn.addEventListener( 'click', exportPDF );
    if ( saveBtn )      saveBtn.addEventListener( 'click', saveReport );
    if ( savedRefresh ) savedRefresh.addEventListener( 'click', loadSavedList );

    // -------------------------------------------------------------------------
    // Control visibility
    // -------------------------------------------------------------------------

    function syncControlVisibility() {
        const g          = groupSelect ? groupSelect.value : 'month';
        const isDonor    = g === 'donor';
        const isCampaign = g === 'campaign';
        const isPM       = g === 'pay_method';
        const isTime     = ! isDonor && ! isCampaign && ! isPM;

        toggleField( 'metis-chart-mode-field', isTime );
        toggleField( 'metis-chart-type-field', isTime );
        toggleField( 'metis-compare-field',    isTime );
        toggleEl(    'metis-metric-toggles',   isTime );
        // Top N donors makes sense for all non-donor-intelligence modes
        toggleField( 'metis-topn-field', ! isDonor );
    }

    function toggleField( id, visible ) {
        const el = $( id );
        if ( el ) el.style.display = visible ? '' : 'none';
    }

    function toggleEl( id, visible ) {
        const el = $( id );
        if ( el ) el.style.display = visible ? '' : 'none';
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    function setStatus( msg, type ) {
        if ( ! statusBar ) return;
        if ( ! msg ) { statusBar.style.display = 'none'; return; }
        statusBar.textContent  = msg;
        statusBar.dataset.type = type || '';
        statusBar.style.display = '';
    }

    // -------------------------------------------------------------------------
    // Config helpers
    // -------------------------------------------------------------------------

    function getMetrics() {
        const boxes = $$( '.metis-metric-toggle' ).filter( b => b.checked ).map( b => b.value );
        return boxes.length ? boxes : [ 'gross', 'fee', 'net' ];
    }

    function getFilters() {
        return {
            platform: platformSel ? platformSel.value : 'ALL',
            status:   statusSel   ? statusSel.value   : 'ALL',
        };
    }

    function getChartMode() {
        const sel = $$( 'input[name="chart_mode"]' ).find( r => r.checked );
        return sel ? sel.value : 'grouped';
    }

    function getTopN() {
        return topNSel ? parseInt( topNSel.value, 10 ) || 0 : 0;
    }

    function getCurrentConfig() {
        return {
            start:    startInput  ? startInput.value  : '',
            end:      endInput    ? endInput.value    : '',
            group:    groupSelect ? groupSelect.value : 'month',
            metrics:  getMetrics(),
            filters:  getFilters(),
            lifetime: lifetimeCk && lifetimeCk.checked ? 1 : 0,
            compare:  compareSelect ? compareSelect.value : 'none',
            top_n:    getTopN(),
        };
    }

    function applyConfig( cfg ) {
        if ( ! cfg ) return;
        if ( startInput    && cfg.start    ) startInput.value    = cfg.start;
        if ( endInput      && cfg.end      ) endInput.value      = cfg.end;
        if ( groupSelect   && cfg.group    ) groupSelect.value   = cfg.group;
        if ( compareSelect && cfg.compare  ) compareSelect.value = cfg.compare;
        if ( lifetimeCk    && cfg.lifetime ) lifetimeCk.checked  = !! cfg.lifetime;
        if ( topNSel       && cfg.top_n != null ) topNSel.value  = String( cfg.top_n );

        if ( cfg.filters ) {
            if ( platformSel && cfg.filters.platform ) platformSel.value = cfg.filters.platform;
            if ( statusSel   && cfg.filters.status   ) statusSel.value   = cfg.filters.status;
        }

        if ( Array.isArray( cfg.metrics ) ) {
            $$( '.metis-metric-toggle' ).forEach( cb => {
                cb.checked = cfg.metrics.includes( cb.value );
            } );
        }

        syncControlVisibility();
    }

    // -------------------------------------------------------------------------
    // Reset report
    // -------------------------------------------------------------------------

    function resetReport() {
        if ( startInput    ) { startInput.value    = ''; startInput.disabled  = false; }
        if ( endInput      ) { endInput.value      = ''; endInput.disabled    = false; }
        if ( groupSelect   ) groupSelect.value   = 'month';
        if ( platformSel   ) platformSel.value   = 'ALL';
        if ( statusSel     ) statusSel.value     = 'ALL';
        if ( compareSelect ) compareSelect.value = 'none';
        if ( topNSel       ) topNSel.value       = '0';
        if ( lifetimeCk    ) lifetimeCk.checked  = false;
        if ( chartTypeSel  ) chartTypeSel.value  = 'auto';

        const groupedRadio = document.querySelector( 'input[name="chart_mode"][value="grouped"]' );
        if ( groupedRadio ) groupedRadio.checked = true;

        $$( '.metis-metric-toggle' ).forEach( cb => {
            cb.checked = [ 'gross', 'fee', 'net' ].includes( cb.value );
        } );

        if ( saveNameInput ) saveNameInput.value = '';
        currentSavedId = null;
        lastReport     = null;

        if ( donationsChart ) { donationsChart.destroy(); donationsChart = null; }

        hideResults();
        setStatus( '' );
        syncControlVisibility();
    }

    // -------------------------------------------------------------------------
    // Run report
    // -------------------------------------------------------------------------

    function runReport() {

        const group = groupSelect ? groupSelect.value : 'month';

        hideResults();
        setStatus( 'Running report\u2026', 'busy' );

        if ( group === 'donor' ) {
            runDonorIntelligence();
            return;
        }

        const cfg     = getCurrentConfig();
        const payload = {
            action:   'metis_donations_report',
            nonce:    NONCE,
            start:    cfg.start,
            end:      cfg.end,
            group:    cfg.group,
            metrics:  cfg.metrics,
            filters:  JSON.stringify( cfg.filters ),
            lifetime: cfg.lifetime,
            compare:  cfg.compare,
        };

        post( payload )
            .then( data => {
                if ( ! data.success ) throw new Error( data.data?.message || 'Report failed' );
                setStatus( '' );
                renderReport( data.data, cfg );
            } )
            .catch( err => {
                setStatus( 'Report failed: ' + err.message, 'error' );
                console.error( err );
            } );
    }

    // -------------------------------------------------------------------------
    // Render report
    // -------------------------------------------------------------------------

    function renderReport( response, cfg ) {

        if ( ! response ) return;

        lastReport = { response, metrics: cfg.metrics, cfg };

        const group      = cfg.group;
        const isCampaign = group === 'campaign';
        const isPM       = group === 'pay_method';
        const isTime     = ! isCampaign && ! isPM;

        updateKPIs( response.kpis, response.comparison );
        renderComparisonCards( response.comparison );
        renderComparisonSummary( response.comparison );

        if ( kpisWrap )   kpisWrap.style.display   = '';
        if ( actionsWrap ) actionsWrap.style.display = '';

        if ( isCampaign ) {
            renderCampaignTable( response.series );
            if ( chartWrap )      chartWrap.style.display      = 'none';
            if ( campaignWrap )   campaignWrap.style.display   = '';
            if ( paymethodWrap )  paymethodWrap.style.display  = 'none';
        } else if ( isPM ) {
            renderPayMethodTable( response.series, response.kpis );
            if ( chartWrap )      chartWrap.style.display      = 'none';
            if ( campaignWrap )   campaignWrap.style.display   = 'none';
            if ( paymethodWrap )  paymethodWrap.style.display  = '';
        } else {
            renderChart( response.series, cfg.metrics, response.comparison, getChartMode() );
            if ( chartWrap )      chartWrap.style.display      = '';
            if ( campaignWrap )   campaignWrap.style.display   = 'none';
            if ( paymethodWrap )  paymethodWrap.style.display  = 'none';
        }

        // Top N donors widget
        const topN = cfg.top_n || 0;
        if ( topN > 0 && Array.isArray( response.top_donors ) && response.top_donors.length ) {
            renderTopDonors( response.top_donors.slice( 0, topN ) );
        } else if ( topDonorsWrap ) {
            topDonorsWrap.style.display = 'none';
        }
    }

    // -------------------------------------------------------------------------
    // Chart
    // -------------------------------------------------------------------------

    function renderChart( series, metrics, comparison, mode ) {

        if ( ! Array.isArray( series ) ) return;

        const isCumulative = mode === 'cumulative';
        const isStacked    = mode === 'stacked';

        const labels    = series.map( r => r.period );
        const seriesMap = { gross: [], fee: [], net: [], count: [], avg: [], fee_pct: [] };

        series.forEach( row => {
            seriesMap.gross.push(   toNum( row.gross   ) );
            seriesMap.fee.push(     toNum( row.fee     ) );
            seriesMap.net.push(     toNum( row.net     ) );
            seriesMap.count.push(   toNum( row.count   ) );
            seriesMap.avg.push(     toNum( row.avg     ) );
            seriesMap.fee_pct.push( toNum( row.fee_pct ) );
        } );

        // Build cumulative versions for all numeric series
        if ( isCumulative ) {
            [ 'gross', 'fee', 'net', 'count', 'avg' ].forEach( m => {
                let running = 0;
                seriesMap[ m ] = seriesMap[ m ].map( v => ( running += v, running ) );
            } );
            // fee_pct doesn't accumulate meaningfully — leave as-is
        }

        const ctx = chartCanvas.getContext( '2d' );
        if ( donationsChart ) { donationsChart.destroy(); donationsChart = null; }

        // Cumulative always renders as line
        const chartType = isCumulative ? 'line' : resolveChartType( metrics );
        const datasets  = buildDatasets( seriesMap, metrics, chartType );

        // Comparison overlay (only when not cumulative)
        if ( ! isCumulative && comparison && comparison.previous ) {
            const muted = {
                gross: 'rgba(16,185,129,0.3)', fee: 'rgba(239,68,68,0.3)',
                net:   'rgba(59,130,246,0.3)', count: 'rgba(168,85,247,0.3)',
                avg:   'rgba(249,115,22,0.3)', fee_pct: 'rgba(234,179,8,0.3)',
            };
            metrics.forEach( m => {
                if ( comparison.previous[ m ] == null ) return;
                datasets.push( {
                    label:           metricLabel( m ) + ' (Prev)',
                    data:            labels.map( () => comparison.previous[ m ] ),
                    borderWidth:     2,
                    borderColor:     muted[ m ] || 'rgba(120,120,120,0.3)',
                    backgroundColor: muted[ m ] || 'rgba(120,120,120,0.3)',
                    borderDash:      chartType === 'line' ? [ 6, 4 ] : [],
                    type:            chartType,
                    tension:         0.25,
                    fill:            false,
                    _metisMetric:       m,
                    _isComparison:   true,
                } );
            } );
        }

        donationsChart = new Chart( ctx, {
            type: chartType,
            data: { labels, datasets },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                interaction:         { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const m   = ctx.dataset._metisMetric;
                                const val = ctx.raw;
                                const pfx = isCumulative ? 'Cumulative ' : '';
                                if ( m === 'count'   ) return pfx + ctx.dataset.label + ': ' + String( val );
                                if ( m === 'fee_pct' ) return pfx + ctx.dataset.label + ': ' + toPct( val );
                                return pfx + ctx.dataset.label + ': ' + toMoney( val );
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: chartType === 'bar' ? isStacked : false },
                    y: {
                        beginAtZero: true,
                        stacked:     chartType === 'bar' ? isStacked : false,
                        ticks: {
                            callback: v => {
                                if ( metrics.length === 1 && metrics[0] === 'fee_pct' ) return toPct( v );
                                if ( metrics.length === 1 && metrics[0] === 'count'   ) return String( v );
                                return toMoney( v );
                            },
                        },
                    },
                },
            },
        } );
    }

    function resolveChartType( metrics ) {
        const val = chartTypeSel ? chartTypeSel.value : 'auto';
        if ( val === 'bar'  ) return 'bar';
        if ( val === 'line' ) return 'line';
        const nonMoney = metrics.every( m => m === 'count' || m === 'fee_pct' );
        return nonMoney ? 'line' : 'bar';
    }

    function buildDatasets( seriesMap, metrics, chartType ) {
        const order  = [ 'gross', 'fee', 'net', 'count', 'avg', 'fee_pct' ];
        const colors = {
            gross: 'rgba(16,185,129,0.85)',  fee:     'rgba(239,68,68,0.85)',
            net:   'rgba(59,130,246,0.85)',  count:   'rgba(168,85,247,0.85)',
            avg:   'rgba(249,115,22,0.85)',  fee_pct: 'rgba(234,179,8,0.85)',
        };
        const isLine = chartType === 'line';

        return order
            .filter( m => metrics.includes( m ) )
            .map( m => ( {
                label:           metricLabel( m ),
                data:            Array.isArray( seriesMap[ m ] ) ? seriesMap[ m ] : [],
                borderWidth:     2,
                borderColor:     colors[ m ] || 'rgba(59,130,246,0.85)',
                backgroundColor: isLine
                    ? colors[ m ] || 'rgba(59,130,246,0.85)'
                    : ( colors[ m ] || 'rgba(59,130,246,0.85)' ).replace( '0.85', '0.6' ),
                tension:         isLine ? 0.25 : 0,
                fill:            false,
                _metisMetric:       m,
            } ) );
    }

    // -------------------------------------------------------------------------
    // Campaign table
    // -------------------------------------------------------------------------

    function renderCampaignTable( series ) {

        const wrap = $( 'metis-campaign-table' );
        if ( ! wrap || ! Array.isArray( series ) ) return;

        if ( ! series.length ) {
            wrap.innerHTML = '<table class="metis-premium-table"><tbody><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted">No campaign data found.</td></tr></tbody></table>';
            return;
        }

        const sorted = [ ...series ].sort( ( a, b ) => toNum( b.gross ) - toNum( a.gross ) );

        let html = `
            <table class="metis-premium-table metis-report-breakdown-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Campaign</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Gross</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Fees</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Net</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Count</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Avg Gift</th>
                    </tr>
                </thead>
                <tbody>
        `;

        sorted.forEach( row => {
            const avg = row.count > 0 ? toNum( row.gross ) / toNum( row.count ) : 0;
            html += `
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell">${ esc( row.period ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.gross ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.fee   ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.net   ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toNum( row.count ).toLocaleString() }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( avg ) }</td>
                </tr>
            `;
        } );

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    // -------------------------------------------------------------------------
    // Pay Method table
    // -------------------------------------------------------------------------

    function renderPayMethodTable( series, kpis ) {

        const wrap = $( 'metis-paymethod-table' );
        if ( ! wrap || ! Array.isArray( series ) ) return;

        if ( ! series.length ) {
            wrap.innerHTML = '<table class="metis-premium-table"><tbody><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted">No payment data found.</td></tr></tbody></table>';
            return;
        }

        const totalGross = toNum( kpis?.gross ?? 0 );
        const sorted = [ ...series ].sort( ( a, b ) => toNum( b.gross ) - toNum( a.gross ) );

        let html = `
            <table class="metis-premium-table metis-report-breakdown-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Method</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Gross</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">% of Total</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Net</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Count</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Avg Gift</th>
                    </tr>
                </thead>
                <tbody>
        `;

        sorted.forEach( row => {
            const label   = PM_LABELS[ row.period ] || esc( row.period );
            const avg     = row.count > 0 ? toNum( row.gross ) / toNum( row.count ) : 0;
            const pctVal  = totalGross > 0 ? ( toNum( row.gross ) / totalGross * 100 ) : 0;
            const pct     = pctVal.toFixed( 1 ) + '%';

            // Mini bar for visual pct
            const barW    = Math.max( 2, Math.round( pctVal ) );
            const barHtml = `<div style="display:flex;align-items:center;gap:6px;">
                <div style="width:60px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">
                    <div style="width:${barW}%;height:100%;background:#3b82f6;border-radius:3px;"></div>
                </div>
                <span>${pct}</span>
            </div>`;

            html += `
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell"><span class="metis-badge metis-badge--blue">${ label }</span></td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.gross ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ barHtml }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.net ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toNum( row.count ).toLocaleString() }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( avg ) }</td>
                </tr>
            `;
        } );

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    // -------------------------------------------------------------------------
    // Top N Donors widget
    // -------------------------------------------------------------------------

    function renderTopDonors( donors ) {

        const tableWrap = $( 'metis-top-donors-table' );
        if ( ! tableWrap || ! donors.length ) return;

        let html = `
            <table class="metis-premium-table metis-top-donors-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell metis-top-donor-rank" scope="col">#</th>
                        <th class="metis-premium-cell metis-top-donor-name-cell" scope="col">Name</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Gross</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Net</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Gifts</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Avg Gift</th>
                        <th class="metis-premium-cell metis-top-donor-date-cell" scope="col">First Gift</th>
                        <th class="metis-premium-cell metis-top-donor-date-cell" scope="col">Last Gift</th>
                    </tr>
                </thead>
                <tbody>
        `;

        donors.forEach( ( row, i ) => {
            const displayName = row.display_name || row.did;
            const profileUrl  = DONOR_URL ? DONOR_URL + '/donor/?id=' + encodeURIComponent( row.did ) : '';
            const firstDate   = row.first_gift ? row.first_gift.slice( 0, 10 ) : '\u2014';
            const lastDate    = row.last_gift  ? row.last_gift.slice( 0, 10 )  : '\u2014';
            const rowAttrs    = profileUrl ? ` class="metis-premium-row metis-top-donor-row metis-clickable-row" data-href="${ esc( profileUrl ) }"` : ' class="metis-premium-row metis-top-donor-row"';

            html += `
                <tr${ rowAttrs }>
                    <td class="metis-premium-cell metis-top-donor-rank">${ i + 1 }</td>
                    <td class="metis-premium-cell metis-top-donor-name-cell">
                        <div class="metis-donor-name">${ profileUrl
                            ? `<a href="${ esc( profileUrl ) }" class="metis-di-name-link">${ esc( displayName ) }</a>`
                            : esc( displayName ) }</div>
                        <div class="metis-muted metis-top-donor-did">DID: ${ esc( row.did ) }</div>
                    </td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.gross ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.net ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toNum( row.gift_count ).toLocaleString() }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( row.avg_gift ) }</td>
                    <td class="metis-premium-cell metis-top-donor-date-cell">${ esc( firstDate ) }</td>
                    <td class="metis-premium-cell metis-top-donor-date-cell">${ esc( lastDate ) }</td>
                </tr>
            `;
        } );

        html += '</tbody></table>';
        tableWrap.innerHTML = html;
        topDonorsWrap.style.display = '';
    }

    // -------------------------------------------------------------------------
    // KPI cards
    // -------------------------------------------------------------------------

    function updateKPIs( kpis, comparison ) {

        if ( ! kpis ) return;

        const metrics = lastReport ? lastReport.metrics : [ 'gross', 'fee', 'net' ];
        const show    = m => metrics.includes( m );

        function delta( metric ) {
            if ( ! comparison || ! comparison.delta || comparison.delta[ metric ] == null ) return '';
            const d   = comparison.delta[ metric ];
            const pct = comparison.delta_pct && comparison.delta_pct[ metric ] != null
                ? comparison.delta_pct[ metric ] : null;

            let cls   = 'metis-delta-neutral';
            let arrow = '';
            if ( d > 0 ) { cls = 'metis-delta-positive'; arrow = '\u25b2 '; }
            if ( d < 0 ) { cls = 'metis-delta-negative'; arrow = '\u25bc '; }

            let label = metric === 'count'
                ? arrow + Math.abs( d ).toLocaleString()
                : arrow + toMoney( Math.abs( d ) );

            if ( pct != null ) label += ' (' + Math.abs( pct ).toFixed( 1 ) + '%)';

            return `<div class="metis-kpi-delta ${ cls }">${ label }</div>`;
        }

        function fill( id, condition, value, label, deltaMetric ) {
            const el = $( id );
            if ( ! el ) return;
            if ( condition ) {
                el.innerHTML = `<div class="metis-kpi-value">${ value }</div><div class="metis-kpi-label">${ label }</div>${ delta( deltaMetric ) }`;
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        }

        fill( 'kpi_total',   show( 'gross' )   && kpis.gross   != null, toMoney( kpis.gross ),        'Total Gross',  'gross' );
        fill( 'kpi_fees',    show( 'fee' )      && kpis.fee     != null, toMoney( kpis.fee ),          'Total Fees',   'fee'   );
        fill( 'kpi_net',     show( 'net' )      && kpis.net     != null, toMoney( kpis.net ),          'Total Net',    'net'   );
        fill( 'kpi_count',   show( 'count' )    && kpis.count   != null, kpis.count.toLocaleString(),  'Donations',    'count' );
        fill( 'kpi_avg',     show( 'avg' )      && kpis.avg     != null, toMoney( kpis.avg ),          'Avg Donation', ''      );
        fill( 'kpi_fee_pct', show( 'fee_pct' )  && kpis.fee_pct != null, toPct( kpis.fee_pct ),       'Fee Rate',     ''      );
    }

    // -------------------------------------------------------------------------
    // Period-over-period comparison CARDS  (\u2191\u2193 summary cards)
    // -------------------------------------------------------------------------

    function renderComparisonCards( comparison ) {

        if ( ! compCards ) return;

        if ( ! comparison || ! comparison.current || ! comparison.previous ) {
            compCards.style.display = 'none';
            return;
        }

        const cur  = comparison.current;
        const prv  = comparison.previous;
        const dpct = comparison.delta_pct || {};
        const d    = comparison.delta     || {};

        function card( label, curVal, prvVal, deltaPct, deltaAbs, isMoney ) {
            const pct   = toNum( deltaPct );
            const abs   = toNum( deltaAbs );
            const up    = pct >= 0;
            const cls   = pct === 0 ? 'neutral' : ( up ? 'positive' : 'negative' );
            const arrow = pct === 0 ? '\u2192' : ( up ? '\u2191' : '\u2193' );
            const sign  = pct === 0 ? '' : ( up ? '+' : '' );
            const fmt   = v => isMoney ? toMoney( v ) : toNum( v ).toLocaleString();

            return `
                <div class="metis-comp-card metis-comp-card--${ cls }">
                    <div class="metis-comp-card-label">${ label }</div>
                    <div class="metis-comp-card-current">${ fmt( curVal ) }</div>
                    <div class="metis-comp-card-arrow">${ arrow }</div>
                    <div class="metis-comp-card-change">${ sign }${ Math.abs( pct ).toFixed( 1 ) }%</div>
                    <div class="metis-comp-card-prev">vs ${ fmt( prvVal ) }</div>
                </div>
            `;
        }

        compCards.innerHTML = `
            <div class="metis-comp-cards-row">
                ${ card( 'Gross',     cur.gross, prv.gross, dpct.gross, d.gross, true  ) }
                ${ card( 'Net',       cur.net,   prv.net,   dpct.net,   d.net,   true  ) }
                ${ card( 'Donations', cur.count, prv.count, dpct.count, d.count, false ) }
            </div>
        `;
        compCards.style.display = '';
    }

    // -------------------------------------------------------------------------
    // Comparison summary banner (compact text version below cards)
    // -------------------------------------------------------------------------

    function renderComparisonSummary( comparison ) {

        if ( ! compSummary ) return;

        if ( ! comparison || ! comparison.current || ! comparison.previous ) {
            compSummary.style.display = 'none';
            return;
        }

        const cur = comparison.current;
        const prv = comparison.previous;
        const pct = comparison.delta_pct ? ( comparison.delta_pct.gross ?? 0 ) : 0;

        let cls = 'metis-delta-neutral', arrow = '';
        if ( pct > 0 ) { cls = 'metis-delta-positive'; arrow = '\u25b2 '; }
        if ( pct < 0 ) { cls = 'metis-delta-negative'; arrow = '\u25bc '; }

        compSummary.innerHTML = `
            <div class="metis-compare-headline ${ cls }">${ arrow }${ Math.abs( pct ).toFixed( 2 ) }% vs Previous Period</div>
            <div class="metis-compare-detail">
                <span><strong>Current</strong> \u2014 Gross: ${ toMoney( cur.gross ) } \u00b7 Net: ${ toMoney( cur.net ) } \u00b7 Count: ${ toNum( cur.count ).toLocaleString() }</span>
                <span><strong>Previous</strong> \u2014 Gross: ${ toMoney( prv.gross ) } \u00b7 Net: ${ toMoney( prv.net ) } \u00b7 Count: ${ toNum( prv.count ).toLocaleString() }</span>
            </div>
        `;
        compSummary.style.display = '';
    }

    // -------------------------------------------------------------------------
    // Donor Intelligence
    // -------------------------------------------------------------------------

    function runDonorIntelligence() {

        const cfg = getCurrentConfig();

        const payload = {
            action:   'metis_donations_donor_intelligence',
            nonce:    NONCE,
            start:    cfg.start,
            end:      cfg.end,
            filters:  JSON.stringify( cfg.filters ),
            lifetime: cfg.lifetime,
        };

        post( payload )
            .then( data => {
                if ( ! data.success ) throw new Error( data.data?.message || 'Donor intelligence failed' );
                setStatus( '' );
                renderDonorIntelligence( data.data );
                if ( actionsWrap ) actionsWrap.style.display = '';
            } )
            .catch( err => {
                setStatus( 'Donor intelligence failed: ' + err.message, 'error' );
                console.error( err );
            } );
    }

    function renderDonorIntelligence( data ) {

        if ( ! donorWrap || ! data || ! Array.isArray( data.rows ) ) return;

        if ( donationsChart ) { donationsChart.destroy(); donationsChart = null; }
        if ( chartWrap )      chartWrap.style.display      = 'none';
        if ( campaignWrap )   campaignWrap.style.display   = 'none';
        if ( paymethodWrap )  paymethodWrap.style.display  = 'none';
        if ( kpisWrap )       kpisWrap.style.display       = 'none';
        if ( topDonorsWrap )  topDonorsWrap.style.display  = 'none';

        const k   = data.kpis     || {};
        const seg = data.segments || {};

        let html = `<h2 class="metis-section-header">Donor Intelligence</h2>`;

        html += `
            <div class="metis-report-kpis" style="margin-bottom:20px;">
                <div class="metis-kpi-card"><div class="metis-kpi-value">${ toMoney( k.gross || 0 ) }</div><div class="metis-kpi-label">Total Gross</div></div>
                <div class="metis-kpi-card"><div class="metis-kpi-value">${ toMoney( k.net || 0 ) }</div><div class="metis-kpi-label">Total Net</div></div>
                <div class="metis-kpi-card"><div class="metis-kpi-value">${ toNum( k.donors || 0 ).toLocaleString() }</div><div class="metis-kpi-label">Donors</div></div>
                <div class="metis-kpi-card"><div class="metis-kpi-value">${ toNum( k.gifts || 0 ).toLocaleString() }</div><div class="metis-kpi-label">Total Gifts</div></div>
                <div class="metis-kpi-card"><div class="metis-kpi-value">${ toMoney( k.avg_gift || 0 ) }</div><div class="metis-kpi-label">Avg Gift</div></div>
                <div class="metis-kpi-card"><div class="metis-kpi-value">${ toMoney( k.avg_ltv || 0 ) }</div><div class="metis-kpi-label">Avg Lifetime Value</div></div>
                <div class="metis-kpi-card"><div class="metis-kpi-value">${ toNum( k.frequency || 0 ).toFixed( 2 ) }\xd7</div><div class="metis-kpi-label">Avg Frequency</div></div>
            </div>
        `;

        const segDefs = [
            { key: 'recurring', label: 'Recurring', cls: 'green' },
            { key: 'returning', label: 'Returning', cls: 'blue'  },
            { key: 'one-time',  label: 'One-Time',  cls: 'gray'  },
            { key: 'lapsed',    label: 'Lapsed',    cls: 'red'   },
        ];

        html += '<div class="metis-di-segment-bar">';
        segDefs.forEach( s => {
            const count = seg[ s.key ] || 0;
            html += `<div class="metis-di-seg metis-di-seg--${ s.cls }">
                <span class="metis-di-seg-count">${ count }</span>
                <span class="metis-di-seg-label">${ s.label }</span>
            </div>`;
        } );
        html += '</div>';

        const segMap = {
            'recurring': { label: 'Recurring', cls: 'green' },
            'returning': { label: 'Returning', cls: 'blue'  },
            'one-time':  { label: 'One-Time',  cls: 'gray'  },
            'lapsed':    { label: 'Lapsed',    cls: 'red'   },
        };

        html += `
            <table class="metis-premium-table metis-di-table" id="metis-di-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header metis-di-row">
                        <th class="metis-premium-cell metis-sortable" scope="col" data-sort="name">Name</th>
                        <th class="metis-premium-cell metis-col-numeric metis-sortable" scope="col" data-sort="gross">Gross</th>
                        <th class="metis-premium-cell metis-col-numeric metis-sortable" scope="col" data-sort="net">Net</th>
                        <th class="metis-premium-cell metis-col-numeric metis-sortable" scope="col" data-sort="gifts">Gifts</th>
                        <th class="metis-premium-cell metis-col-numeric" scope="col">Avg Gift</th>
                        <th class="metis-premium-cell" scope="col">First Gift</th>
                        <th class="metis-premium-cell metis-sortable" scope="col" data-sort="last">Last Gift</th>
                        <th class="metis-premium-cell" scope="col">Segment</th>
                    </tr>
                </thead>
                <tbody id="metis-di-rows">
        `;

        data.rows.forEach( r => {
            const profileUrl  = DONOR_URL ? DONOR_URL + '/donor/?id=' + encodeURIComponent( r.did ) : '';
            const firstDate   = r.first_gift ? r.first_gift.slice( 0, 10 ) : '\u2014';
            const lastDate    = r.last_gift  ? r.last_gift.slice(  0, 10 ) : '\u2014';
            const displayName = r.display_name || r.did;
            const segInfo     = segMap[ r.segment ] || { label: r.segment, cls: 'gray' };

            html += `
                <tr class="metis-premium-row metis-di-row"
                     data-name="${ esc( displayName.toLowerCase() ) }"
                     data-gross="${ r.gross }"
                     data-net="${ r.net }"
                     data-gifts="${ r.donation_count }"
                     data-last="${ lastDate }"
                     ${ profileUrl ? `style="cursor:pointer" onclick="window.location='${ profileUrl }'"` : '' }>
                    <td class="metis-premium-cell metis-col-donor-name">
                        <div class="metis-donor-name">${ profileUrl ? `<a href="${ profileUrl }" class="metis-di-name-link" onclick="event.stopPropagation()">${ esc( displayName ) }</a>` : esc( displayName ) }</div>
                        <div class="metis-donor-sub metis-muted">DID: ${ esc( r.did ) }</div>
                    </td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( r.gross ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( r.net ) }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toNum( r.donation_count ).toLocaleString() }</td>
                    <td class="metis-premium-cell metis-col-numeric">${ toMoney( r.avg_gift ) }</td>
                    <td class="metis-premium-cell">${ esc( firstDate ) }</td>
                    <td class="metis-premium-cell">${ esc( lastDate ) }</td>
                    <td class="metis-premium-cell"><span class="metis-badge metis-badge--${ segInfo.cls }">${ segInfo.label }</span></td>
                </tr>
            `;
        } );

        html += '</tbody></table>';

        donorWrap.innerHTML     = html;
        donorWrap.style.display = '';

        // Sorting
        let sortCol = 'gross', sortDir = -1;

        function sortDiRows() {
            const tbody  = document.getElementById( 'metis-di-rows' );
            if ( ! tbody ) return;
            const rowEls = Array.from( tbody.querySelectorAll( '.metis-di-row' ) );

            rowEls.sort( ( a, b ) => {
                let av, bv;
                if ( sortCol === 'name' ) {
                    av = a.dataset.name || ''; bv = b.dataset.name || '';
                    return sortDir * av.localeCompare( bv );
                }
                if ( sortCol === 'last' ) {
                    av = a.dataset.last || ''; bv = b.dataset.last || '';
                    return sortDir * av.localeCompare( bv );
                }
                av = parseFloat( a.dataset[ sortCol ] || 0 );
                bv = parseFloat( b.dataset[ sortCol ] || 0 );
                return sortDir * ( av - bv );
            } );

            rowEls.forEach( r => tbody.appendChild( r ) );

            document.querySelectorAll( '#metis-di-table .metis-sortable' ).forEach( h => {
                const col = h.dataset.sort;
                h.classList.remove( 'metis-sort-active', 'metis-sort-asc', 'metis-sort-desc' );
                if ( col === sortCol ) {
                    h.classList.add( 'metis-sort-active' );
                    h.classList.add( sortDir === 1 ? 'metis-sort-asc' : 'metis-sort-desc' );
                }
            } );
        }

        document.querySelectorAll( '#metis-di-table .metis-sortable' ).forEach( h => {
            h.style.cursor = 'pointer';
            h.addEventListener( 'click', () => {
                const col = h.dataset.sort;
                if ( sortCol === col ) { sortDir *= -1; } else { sortCol = col; sortDir = -1; }
                sortDiRows();
            } );
        } );

        sortDiRows();

        lastReport = { response: data, metrics: [], cfg: {} };
    }

    // -------------------------------------------------------------------------
    // Saved Reports
    // -------------------------------------------------------------------------

    function saveReport() {

        const name = saveNameInput ? saveNameInput.value.trim() : '';
        if ( ! name ) { Metis.util.notify( 'Enter a report name.', 'warning' ); return; }

        const cfg = getCurrentConfig();
        const payload = {
            action:  'metis_donations_report_save',
            nonce:   NONCE,
            name:    name,
            config:  JSON.stringify( cfg ),
        };

        if ( currentSavedId ) payload.id = currentSavedId;

        setStatus( 'Saving\u2026', 'busy' );

        post( payload )
            .then( data => {
                if ( ! data.success ) throw new Error( data.data?.message || 'Save failed' );
                currentSavedId = data.data.id;
                setStatus( 'Saved \u2713', 'ok' );
                setTimeout( () => setStatus( '' ), 2000 );
                loadSavedList();
            } )
            .catch( err => {
                setStatus( 'Save failed: ' + err.message, 'error' );
            } );
    }

    function loadSavedList() {

        if ( ! savedList ) return;
        savedList.innerHTML = '<p class="metis-muted">Loading\u2026</p>';

        post( { action: 'metis_donations_report_list', nonce: NONCE } )
            .then( data => {
                if ( ! data.success ) throw new Error( 'List failed' );
                renderSavedList( data.data.items || [] );
            } )
            .catch( () => {
                savedList.innerHTML = '<p class="metis-muted">Could not load saved reports.</p>';
            } );
    }

    const savedItemCache = {};

    function renderSavedList( items ) {

        if ( ! savedList ) return;
        items.forEach( i => { savedItemCache[ i.id ] = i; } );

        if ( ! items.length ) {
            savedList.innerHTML = '<p class="metis-muted">No saved reports yet.</p>';
            return;
        }

        let html = '';
        items.forEach( item => {
            const updated = item.updated_at ? item.updated_at.slice( 0, 10 ) : '';
            html += `
                <div class="metis-saved-report-row" data-id="${ item.id }">
                    <div class="metis-saved-report-name">${ esc( item.name ) }</div>
                    <div class="metis-saved-report-meta metis-muted">${ esc( updated ) }</div>
                    <div class="metis-saved-report-actions">
                        <button class="metis-btn metis-btn-xs metis-saved-load-btn" data-id="${ item.id }">Load</button>
                        <button class="metis-btn metis-btn-xs metis-btn-danger metis-saved-delete-btn" data-id="${ item.id }">\xd7</button>
                    </div>
                </div>
            `;
        } );

        savedList.innerHTML = html;

        savedList.querySelectorAll( '.metis-saved-load-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => loadSavedReport( parseInt( btn.dataset.id ) ) );
        } );
        savedList.querySelectorAll( '.metis-saved-delete-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => deleteSavedReport( parseInt( btn.dataset.id ) ) );
        } );
    }

    function loadSavedReport( id ) {
        const item = savedItemCache[ id ];
        if ( ! item ) { console.warn( 'Saved report not in cache', id ); return; }
        try {
            const parsed = item.config_json ? JSON.parse( item.config_json ) : null;
            if ( parsed ) {
                applyConfig( parsed );
                currentSavedId = id;
                if ( saveNameInput ) saveNameInput.value = item.name;
                runReport();
            }
        } catch ( e ) {
            console.error( 'Could not parse saved config', e );
        }
    }

    function deleteSavedReport( id ) {
        confirmAction( 'Delete this saved report?', {
            title: 'Delete Report',
            confirmLabel: 'Delete Report',
            tone: 'danger'
        } ).then( confirmed => {
            if ( ! confirmed ) return;
            post( { action: 'metis_donations_report_delete', nonce: NONCE, id: id } )
                .then( data => {
                    if ( ! data.success ) throw new Error( 'Delete failed' );
                    if ( currentSavedId === id ) currentSavedId = null;
                    loadSavedList();
                } )
                .catch( err => console.error( err ) );
        } );
    }

    // -------------------------------------------------------------------------
    // Exports
    // -------------------------------------------------------------------------

    function exportCSV() {

        if ( ! lastReport || ! lastReport.response ) { Metis.util.notify( 'Run a report first.', 'warning' ); return; }

        const series  = lastReport.response.series;
        const metrics = lastReport.metrics;

        if ( ! Array.isArray( series ) || ! series.length ) { Metis.util.notify( 'No data to export.', 'warning' ); return; }

        const cols = [ 'period', ...( metrics.length ? metrics : [ 'gross', 'fee', 'net', 'count' ] ) ];
        const rows = [ cols ];
        series.forEach( r => rows.push( cols.map( c => safeCsv( r[ c ] ) ) ) );

        const csv  = rows.map( r => r.join( ',' ) ).join( '\r\n' );
        const date = new Date().toISOString().slice( 0, 10 );
        downloadBlob( new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } ), `donations-report-${ date }.csv` );
    }

    function exportPNG() {
        if ( ! chartCanvas ) return;
        try {
            const link    = document.createElement( 'a' );
            link.download = 'donations-report-' + new Date().toISOString().slice( 0, 10 ) + '.png';
            link.href     = chartCanvas.toDataURL( 'image/png' );
            document.body.appendChild( link );
            link.click();
            document.body.removeChild( link );
        } catch ( e ) { console.error( 'PNG export failed', e ); }
    }

    function exportPDF() {

        if ( ! lastReport ) { Metis.util.notify( 'Run a report first.', 'warning' ); return; }

        const cfg   = lastReport.cfg || getCurrentConfig();
        const group = cfg.group;

        let chartImg = '';
        const hasChart = ( group !== 'donor' && group !== 'campaign' && group !== 'pay_method' );
        if ( hasChart && chartCanvas ) {
            try { chartImg = chartCanvas.toDataURL( 'image/png', 1.0 ); } catch ( e ) {}
        }

        const reportData = lastReport.response ? JSON.stringify( lastReport.response ) : '{}';

        const form = document.createElement( 'form' );
        form.method = 'POST';
        form.action = AJAX_URL;
        form.style.display = 'none';

        const fields = {
            action:      'metis_donations_report_pdf',
            nonce:       NONCE,
            group:       group,
            metrics:     JSON.stringify( cfg.metrics ),
            lifetime:    cfg.lifetime,
            start:       cfg.start,
            end:         cfg.end,
            report_data: reportData,
            chart_image: chartImg,
        };

        Object.entries( fields ).forEach( ( [ k, v ] ) => {
            const inp = document.createElement( 'input' );
            inp.type = 'hidden'; inp.name = k; inp.value = v;
            form.appendChild( inp );
        } );

        document.body.appendChild( form );
        form.submit();
        document.body.removeChild( form );
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    function hideResults() {
        if ( kpisWrap )       kpisWrap.style.display       = 'none';
        if ( actionsWrap )    actionsWrap.style.display    = 'none';
        if ( chartWrap )      chartWrap.style.display      = 'none';
        if ( campaignWrap )   campaignWrap.style.display   = 'none';
        if ( paymethodWrap )  paymethodWrap.style.display  = 'none';
        if ( donorWrap )      donorWrap.style.display      = 'none';
        if ( compCards )      compCards.style.display      = 'none';
        if ( compSummary )    compSummary.style.display    = 'none';
        if ( topDonorsWrap )  topDonorsWrap.style.display  = 'none';
    }

    function post( payload ) {
        const form = new URLSearchParams( payload );
        const action = form.get( 'action' ) || '';
        if ( action ) {
            form.set( 'metis_action_nonce', Metis.ajax.nonceFor( action, form.get( 'metis_action_nonce' ) || form.get( 'nonce' ) || NONCE ) );
            form.set( 'nonce', form.get( 'nonce' ) || NONCE );
        }
        return fetch( AJAX_URL, {
            method:  'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form,
        } ).then( r => Metis.ajax.parseJson( r ) );
    }

    function toNum( v )   { const n = parseFloat( v ); return Number.isFinite( n ) ? n : 0; }
    function toMoney( v ) { return '$' + toNum( v ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ); }
    function toPct( v )   { return toNum( v ).toFixed( 2 ) + '%'; }
    function metricLabel( m ) {
        return { gross: 'Gross', fee: 'Fees', net: 'Net', count: 'Count', avg: 'Avg', fee_pct: 'Fee %' }[ m ] || m;
    }
    function esc( s )     { return String( s ?? '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ); }
    function safeCsv( v ) { const s = String( v ?? '' ); return /[",\n\r]/.test( s ) ? '"' + s.replace( /"/g, '""' ) + '"' : s; }
    function downloadBlob( blob, name ) {
        const url = URL.createObjectURL( blob );
        const a   = document.createElement( 'a' );
        a.href = url; a.download = name;
        document.body.appendChild( a ); a.click();
        document.body.removeChild( a ); URL.revokeObjectURL( url );
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    syncControlVisibility();
    loadSavedList();

} )();
