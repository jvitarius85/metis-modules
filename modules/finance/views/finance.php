<?php
if ( ! function_exists( 'metis_finance_can_view' ) || ! metis_finance_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Finance.</div>';
    return;
}

metis_finance_ensure_schema();

$current_section = metis_key_clean( (string) metis_get_query_var( 'metis_view' ) );
if ( $current_section === '' || $current_section === 'finance' ) {
    $current_section = 'snapshot';
}

$section_views = [
    'snapshot' => 'Snapshot',
    'gl_entry' => 'GL Entry',
    'reconciliation' => 'Reconciliation',
    'reconciliation_steps' => 'Reconciliation Steps',
    'budget' => 'Budget',
    'invoicing' => 'Invoicing',
    'reports' => 'Reports',
    'settings' => 'Settings',
    'stripe_clearing' => 'Stripe Clearing',
];

if ( ! isset( $section_views[ $current_section ] ) ) {
    $current_section = 'snapshot';
}

$section_url = static function ( string $view ): string {
    if ( function_exists( 'metis_portal_url' ) ) {
        return (string) metis_portal_url( 'finance', $view );
    }

    return (string) metis_home_url( '/admin/finance/' . trim( $view, '/' ) . '/' );
};

$finance_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance' ) : '';
$gl_create_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_gl_create' ) : $finance_nonce;
$recon_import_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_recon_import' ) : $finance_nonce;
$recon_mapping_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_recon_mapping' ) : $finance_nonce;
$recon_review_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_recon_review' ) : $finance_nonce;
$category_save_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_category_save' ) : $finance_nonce;
$budget_version_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_budget_version' ) : $finance_nonce;
$budget_lines_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_budget_lines' ) : $finance_nonce;
$invoice_create_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_invoice_create' ) : $finance_nonce;
$invoice_send_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_invoice_send' ) : $finance_nonce;
$invoice_paid_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_invoice_paid' ) : $finance_nonce;
$fiscal_settings_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_fiscal_settings' ) : $finance_nonce;
$fiscal_migrate_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_fiscal_migrate' ) : $finance_nonce;
$report_render_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_report_render' ) : $finance_nonce;
$report_pdf_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_report_pdf' ) : $finance_nonce;
$stripe_event_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_stripe_event' ) : $finance_nonce;
$stripe_payout_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_stripe_payout' ) : $finance_nonce;
$bank_line_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_bank_line' ) : $finance_nonce;
$stripe_match_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_finance_v2_stripe_match' ) : $finance_nonce;
$recon_list_url = $section_url( 'reconciliation' );
$recon_steps_url = $section_url( 'reconciliation_steps' );
?>
<div
    class="metis-finance-v2-app"
    data-finance-v2-app="1"
    data-current-section="<?php echo metis_escape_attr( $current_section ); ?>"
    data-ajax-endpoint="<?php echo metis_escape_attr( (string) metis_home_url( '/api/ajax' ) ); ?>"
    data-action-bootstrap="metis_finance_v2_bootstrap"
    data-action-entries-list="metis_finance_v2_gl_entries_list"
    data-action-entries-create="metis_finance_v2_gl_create"
    data-action-recon-import="metis_finance_v2_recon_import"
    data-action-recon-workflow="metis_finance_v2_recon_workflow"
    data-action-recon-item-toggle="metis_finance_v2_recon_item_toggle"
    data-action-recon-finalize="metis_finance_v2_recon_finalize"
    data-action-recon-reopen="metis_finance_v2_recon_reopen"
    data-action-recon-delete="metis_finance_v2_recon_delete"
    data-action-recon-mapping-list="metis_finance_v2_recon_mapping_list"
    data-action-recon-mapping-save="metis_finance_v2_recon_mapping"
    data-action-recon-review="metis_finance_v2_recon_review"
    data-action-recon-match-line="metis_finance_v2_recon_match_line"
    data-action-categories-list="metis_finance_v2_categories_list"
    data-action-categories-save="metis_finance_v2_category_save"
    data-action-budget-snapshot="metis_finance_v2_budget_snapshot"
    data-action-budget-version-create="metis_finance_v2_budget_version"
    data-action-budget-lines-save="metis_finance_v2_budget_lines"
    data-action-invoices-list="metis_finance_v2_invoices_list"
    data-action-invoices-create="metis_finance_v2_invoice_create"
    data-action-invoices-send="metis_finance_v2_invoice_send"
    data-action-invoices-paid="metis_finance_v2_invoice_paid"
    data-action-fiscal-settings-get="metis_finance_v2_fiscal_settings_get"
    data-action-fiscal-settings-update="metis_finance_v2_fiscal_settings"
    data-action-fiscal-migrate="metis_finance_v2_fiscal_migrate"
    data-action-reports-snapshot="metis_finance_v2_reports_snapshot"
    data-action-report-render="metis_finance_v2_report_render"
    data-action-report-pdf="metis_finance_v2_report_pdf"
    data-action-stripe-overview="metis_finance_v2_stripe_overview"
    data-action-stripe-event-create="metis_finance_v2_stripe_event"
    data-action-stripe-payout-create="metis_finance_v2_stripe_payout"
    data-action-bank-line-create="metis_finance_v2_bank_line"
    data-action-stripe-match-create="metis_finance_v2_stripe_match"
    data-action-stripe-auto-match="metis_finance_v2_stripe_auto_match"
    data-recon-list-url="<?php echo metis_escape_attr( $recon_list_url ); ?>"
    data-recon-steps-url="<?php echo metis_escape_attr( $recon_steps_url ); ?>"
    data-finance-nonce="<?php echo metis_escape_attr( $finance_nonce ); ?>"
    data-gl-create-nonce="<?php echo metis_escape_attr( $gl_create_nonce ); ?>"
    data-recon-import-nonce="<?php echo metis_escape_attr( $recon_import_nonce ); ?>"
    data-recon-item-toggle-nonce="<?php echo metis_escape_attr( $recon_review_nonce ); ?>"
    data-recon-finalize-nonce="<?php echo metis_escape_attr( $recon_review_nonce ); ?>"
    data-recon-reopen-nonce="<?php echo metis_escape_attr( $recon_review_nonce ); ?>"
    data-recon-delete-nonce="<?php echo metis_escape_attr( $recon_review_nonce ); ?>"
    data-recon-mapping-nonce="<?php echo metis_escape_attr( $recon_mapping_nonce ); ?>"
    data-recon-review-nonce="<?php echo metis_escape_attr( $recon_review_nonce ); ?>"
    data-category-save-nonce="<?php echo metis_escape_attr( $category_save_nonce ); ?>"
    data-budget-version-nonce="<?php echo metis_escape_attr( $budget_version_nonce ); ?>"
    data-budget-lines-nonce="<?php echo metis_escape_attr( $budget_lines_nonce ); ?>"
    data-invoice-create-nonce="<?php echo metis_escape_attr( $invoice_create_nonce ); ?>"
    data-invoice-send-nonce="<?php echo metis_escape_attr( $invoice_send_nonce ); ?>"
    data-invoice-paid-nonce="<?php echo metis_escape_attr( $invoice_paid_nonce ); ?>"
    data-fiscal-settings-nonce="<?php echo metis_escape_attr( $fiscal_settings_nonce ); ?>"
    data-fiscal-migrate-nonce="<?php echo metis_escape_attr( $fiscal_migrate_nonce ); ?>"
    data-report-render-nonce="<?php echo metis_escape_attr( $report_render_nonce ); ?>"
    data-report-pdf-nonce="<?php echo metis_escape_attr( $report_pdf_nonce ); ?>"
    data-stripe-event-nonce="<?php echo metis_escape_attr( $stripe_event_nonce ); ?>"
    data-stripe-payout-nonce="<?php echo metis_escape_attr( $stripe_payout_nonce ); ?>"
    data-bank-line-nonce="<?php echo metis_escape_attr( $bank_line_nonce ); ?>"
    data-stripe-match-nonce="<?php echo metis_escape_attr( $stripe_match_nonce ); ?>"
>
    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Finance' ) ); ?></h1>
    <p class="mw-subtitle">Finance workspace for GL entry, monthly reconciliation, budget, invoicing, reports, settings, and Stripe clearing.</p>

    <div class="mw-sidebar-layout metis-finance-v2-layout">
        <aside class="mw-sidebar-layout-sidebar metis-finance-v2-layout-sidebar">
            <div class="mw-sidebar-layout-sidebar-inner metis-finance-v2-layout-sidebar-inner">
                <div class="mw-list-sidebar-actions">
                    <div class="mw-list-sidebar-label">Finance</div>
                    <nav class="mw-list-sidebar-nav" aria-label="Finance sections">
                        <?php foreach ( $section_views as $view => $label ) : ?>
                            <?php if ( $view === 'reconciliation_steps' ) { continue; } ?>
                            <a class="mw-list-sidebar-nav-item<?php echo $current_section === $view ? ' is-active' : ''; ?>" href="<?php echo metis_escape_url( $section_url( $view ) ); ?>"><?php echo metis_escape_html( $label ); ?></a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                <div class="mw-list-sidebar-actions metis-finance-v2-workflow-sidebar">
                    <div class="mw-list-sidebar-label">Workflow</div>
                    <nav class="mw-list-sidebar-nav" aria-label="Finance workflow">
                        <button type="button" class="mw-list-sidebar-nav-item" data-open-gl-modal="1">New GL Entry</button>
                        <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url( $section_url( 'reconciliation' ) ); ?>">Run Reconciliation</a>
                        <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url( $section_url( 'budget' ) ); ?>">Manage Budget</a>
                        <button type="button" class="mw-list-sidebar-nav-item" data-open-invoice-modal="1">Create Invoice</button>
                        <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url( $section_url( 'reports' ) ); ?>">Render Report</a>
                        <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url( $section_url( 'stripe_clearing' ) ); ?>">Stripe Exceptions</a>
                    </nav>
                </div>
            </div>
        </aside>

        <div class="mw-sidebar-layout-content metis-finance-v2-layout-content">
            <?php if ( $current_section === 'snapshot' ) : ?>
                <section class="metis-finance-v2-kpis" data-finance-kpis="1">
                    <article class="metis-finance-v2-kpi">
                        <p>Total Entries</p>
                        <strong data-kpi="total_entries">0</strong>
                    </article>
                    <article class="metis-finance-v2-kpi">
                        <p>MTD Entries</p>
                        <strong data-kpi="mtd_entries">0</strong>
                    </article>
                    <article class="metis-finance-v2-kpi">
                        <p>Unmatched</p>
                        <strong data-kpi="unmatched_entries">0</strong>
                    </article>
                    <article class="metis-finance-v2-kpi">
                        <p>Review Required</p>
                        <strong data-kpi="review_count">0</strong>
                    </article>
                    <article class="metis-finance-v2-kpi">
                        <p>Expected Payouts</p>
                        <strong data-kpi="expected_payouts">0</strong>
                    </article>
                    <article class="metis-finance-v2-kpi">
                        <p>Unmatched Bank Lines</p>
                        <strong data-kpi="unmatched_bank_lines">0</strong>
                    </article>
                </section>

                <section class="mw-settings-card metis-finance-v2-snapshot-card">
                    <div class="mw-settings-header"><h2>Financial Overview</h2></div>
                    <div class="mw-settings-body">
                        <div class="metis-finance-v2-overview-grid">
                            <article class="metis-finance-v2-overview-item">
                                <h3>General Ledger</h3>
                                <dl>
                                    <div><dt>Total Debits</dt><dd data-snapshot-value="total_debits">0.00</dd></div>
                                    <div><dt>Total Credits</dt><dd data-snapshot-value="total_credits">0.00</dd></div>
                                    <div><dt>Net Activity</dt><dd data-snapshot-value="net_activity">0.00</dd></div>
                                </dl>
                            </article>
                            <article class="metis-finance-v2-overview-item">
                                <h3>Reconciliation</h3>
                                <dl>
                                    <div><dt>Current Month</dt><dd data-snapshot-value="recon_month_label">-</dd></div>
                                    <div><dt>Status</dt><dd data-snapshot-value="recon_month_status">-</dd></div>
                                    <div><dt>Cleared Entries</dt><dd data-snapshot-value="recon_cleared_count">0</dd></div>
                                    <div><dt>Difference</dt><dd data-snapshot-value="recon_difference_amount">0.00</dd></div>
                                </dl>
                            </article>
                            <article class="metis-finance-v2-overview-item">
                                <h3>Budget</h3>
                                <dl>
                                    <div><dt>Active Version</dt><dd data-snapshot-value="budget_version_label">-</dd></div>
                                    <div><dt>Planned</dt><dd data-snapshot-value="budget_planned_total">0.00</dd></div>
                                    <div><dt>Actual</dt><dd data-snapshot-value="budget_actual_total">0.00</dd></div>
                                    <div><dt>Variance</dt><dd data-snapshot-value="budget_variance_total">0.00</dd></div>
                                </dl>
                            </article>
                            <article class="metis-finance-v2-overview-item">
                                <h3>Invoicing</h3>
                                <dl>
                                    <div><dt>Open Invoices</dt><dd data-snapshot-value="invoice_open_count">0</dd></div>
                                    <div><dt>Open Total</dt><dd data-snapshot-value="invoice_open_total">0.00</dd></div>
                                    <div><dt>Total Overdue</dt><dd data-snapshot-value="invoice_overdue_total">0.00</dd></div>
                                    <div><dt>Drafts</dt><dd data-snapshot-value="invoice_draft_count">0</dd></div>
                                </dl>
                            </article>
                            <article class="metis-finance-v2-overview-item">
                                <h3>Stripe Clearing</h3>
                                <dl>
                                    <div><dt>Clearing Balance</dt><dd data-snapshot-value="stripe_clearing_balance">0.00</dd></div>
                                    <div><dt>Expected Deposits</dt><dd data-snapshot-value="stripe_expected_total">0.00</dd></div>
                                    <div><dt>Matched Payouts</dt><dd data-snapshot-value="matched_payouts">0</dd></div>
                                    <div><dt>Unmatched Bank Lines</dt><dd data-snapshot-value="unmatched_bank_lines">0</dd></div>
                                </dl>
                            </article>
                        </div>
                    </div>
                </section>
                <section class="mw-settings-card metis-finance-v2-snapshot-card">
                    <div class="mw-settings-header"><h2>Financial Performance</h2></div>
                    <div class="mw-settings-body">
                        <div class="metis-finance-v2-chart-grid">
                            <article class="metis-finance-v2-chart-card">
                                <h3>Monthly Net Activity</h3>
                                <canvas data-finance-chart="monthly_net" height="180"></canvas>
                            </article>
                            <article class="metis-finance-v2-chart-card">
                                <h3>Invoices by Month</h3>
                                <canvas data-finance-chart="invoice_monthly" height="180"></canvas>
                            </article>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'gl_entry' ) : ?>
                <section class="mw-settings-card">
            <div class="mw-settings-header"><h2>GL Entry</h2></div>
            <div class="mw-settings-body">
                    <div class="mw-settings-actions" style="padding:0;">
                        <button type="button" class="mw-btn" data-open-gl-modal="1">Quick GL Entry</button>
                    </div>
                    <p class="mw-help">Use the quick entry modal to add multiple ledger rows at once.</p>

                <div class="metis-finance-v2-table-wrap">
                    <table class="metis-finance-v2-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Category</th>
                                <th>Recon</th>
                            </tr>
                        </thead>
                        <tbody data-finance-entries="1">
                            <tr><td colspan="7" class="metis-finance-v2-empty">Loading entries...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'reconciliation' ) : ?>
                <section class="mw-settings-card metis-finance-v2-recon-panel is-active" data-recon-spa-panel="list">
            <div class="mw-settings-header"><h2>Reconciliation</h2></div>
            <div class="mw-settings-body">
                    <div class="mw-settings-actions" style="padding:0;">
                        <button type="button" class="mw-btn" data-recon-open-workflow="1">Start Reconciliation</button>
                        <button type="button" class="mw-btn mw-btn-ghost" data-finance-recon-reopen="1">Reopen Selected Month</button>
                        <input type="text" class="mw-input metis-finance-v2-recon-reason" data-finance-recon-reopen-reason="1" placeholder="Reason required to reopen finalized month">
                    </div>

                    <div class="metis-finance-v2-grid metis-finance-v2-grid--stacked">
                        <div class="metis-finance-v2-table-wrap">
                            <table class="metis-finance-v2-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Status</th>
                                        <th>Statement</th>
                                        <th>Difference</th>
                                        <th>Open</th>
                                    </tr>
                                </thead>
                                <tbody data-finance-recon-history="1">
                                    <tr><td colspan="5" class="metis-finance-v2-empty">No reconciliation months yet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="metis-finance-v2-table-wrap">
                            <table class="metis-finance-v2-table">
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Event</th>
                                        <th>Reason</th>
                                        <th>Actor</th>
                                    </tr>
                                </thead>
                                <tbody data-finance-recon-audit="1">
                                    <tr><td colspan="4" class="metis-finance-v2-empty">Select a month to view audit history.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'reconciliation_steps' || $current_section === 'reconciliation' ) : ?>
                <section id="metis-reconciliation-workflow" class="mw-settings-card<?php echo ( $current_section === 'reconciliation' ) ? ' metis-finance-v2-recon-panel' : ''; ?>"<?php echo ( $current_section === 'reconciliation' ) ? ' data-recon-spa-panel="workflow"' : ''; ?>>
            <div class="mw-settings-header"><h2>Reconciliation Workflow</h2></div>
            <div class="mw-settings-body">
                    <?php if ( $current_section === 'reconciliation' ) : ?>
                        <div class="mw-settings-actions" style="padding:0;">
                            <button type="button" class="mw-btn mw-btn-ghost" data-recon-back-list="1">Back To Reconciliation List</button>
                        </div>
                    <?php endif; ?>
                    <?php if ( $current_section === 'reconciliation_steps' ) : ?>
                        <div class="mw-settings-actions" style="padding:0;">
                            <a href="<?php echo metis_escape_url( $recon_list_url ); ?>" class="mw-btn mw-btn-ghost">Back To Reconciliation List</a>
                        </div>
                    <?php endif; ?>
                    <div class="metis-finance-v2-step-nav" role="tablist" aria-label="Reconciliation steps">
                        <button type="button" class="mw-btn mw-btn-ghost is-active" data-recon-step-nav="setup">1) Setup Month</button>
                        <button type="button" class="mw-btn mw-btn-ghost" data-recon-step-nav="review">2) Review Transactions</button>
                        <button type="button" class="mw-btn mw-btn-ghost" data-recon-step-nav="finalize">3) Finalize</button>
                    </div>
                    <div class="metis-finance-v2-recon-summary-grid">
                        <article class="metis-finance-v2-overview-item">
                            <h3>Month</h3>
                            <dl>
                                <div><dt>Selected</dt><dd data-recon-current-month-label="1">-</dd></div>
                                <div><dt>Status</dt><dd data-recon-current-month-status="1">-</dd></div>
                                <div><dt>Statement</dt><dd><a href="#" class="mw-btn mw-btn-xs mw-btn-ghost" data-recon-statement-link="1" target="_blank" rel="noopener">Not Attached</a></dd></div>
                            </dl>
                        </article>
                        <article class="metis-finance-v2-overview-item">
                            <h3>Balances</h3>
                            <dl>
                                <div><dt>Starting</dt><dd data-recon-starting-balance="1">0.00</dd></div>
                                <div><dt>Cleared Net</dt><dd data-recon-cleared-net="1">0.00</dd></div>
                                <div><dt>Expected Ending</dt><dd data-recon-expected-ending="1">0.00</dd></div>
                                <div><dt>Statement Ending</dt><dd data-recon-statement-ending="1">0.00</dd></div>
                                <div><dt>Difference</dt><dd data-recon-difference="1">0.00</dd></div>
                            </dl>
                        </article>
                        <article class="metis-finance-v2-overview-item">
                            <h3>Progress</h3>
                            <dl>
                                <div><dt>Total Entries</dt><dd data-recon-entry-count="1">0</dd></div>
                                <div><dt>Cleared Entries</dt><dd data-recon-cleared-count="1">0</dd></div>
                                <div><dt>Uncleared Net</dt><dd data-recon-uncleared-net="1">0.00</dd></div>
                                <div><dt>Balanced</dt><dd data-recon-balanced-label="1">No</dd></div>
                            </dl>
                        </article>
                    </div>
                    <div class="metis-finance-v2-step-page is-active" data-recon-step-page="setup">
                        <form data-finance-recon-form="1" class="metis-finance-v2-form metis-finance-v2-recon-start-form">
                            <div class="metis-finance-v2-form-grid">
                                <div class="mw-field">
                                    <label for="finance_recon_month">Reconciliation Month</label>
                                    <input id="finance_recon_month" name="recon_month" type="month" class="mw-input" required>
                                </div>
                                <div class="mw-field">
                                    <label for="finance_recon_starting_balance">Starting Balance (optional)</label>
                                    <input id="finance_recon_starting_balance" name="starting_balance" type="number" class="mw-input" step="0.01" placeholder="Auto from prior month if blank">
                                </div>
                                <div class="mw-field">
                                    <label for="finance_recon_statement_ending">Statement Ending Balance</label>
                                    <input id="finance_recon_statement_ending" name="statement_ending_balance" type="number" class="mw-input" step="0.01" required>
                                </div>
                                <div class="mw-field">
                                    <label for="finance_recon_file">Statement PDF</label>
                                    <input id="finance_recon_file" name="recon_file" type="file" class="mw-input" accept=".pdf,application/pdf">
                                </div>
                            </div>
                            <div class="mw-settings-actions" style="padding:0;">
                                <button type="submit" class="mw-btn" data-finance-recon-submit="1">Save Month Setup</button>
                            </div>
                        </form>
                    </div>
                    <div class="metis-finance-v2-step-page" data-recon-step-page="review">
                        <div class="metis-finance-v2-table-wrap">
                            <table class="metis-finance-v2-table">
                                <thead>
                                    <tr>
                                        <th>Posted</th>
                                        <th>Date</th>
                                        <th>Account</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Category</th>
                                    </tr>
                                </thead>
                                <tbody data-finance-recon-manual-items="1">
                                    <tr><td colspan="6" class="metis-finance-v2-empty">Start a reconciliation month to load transactions.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="metis-finance-v2-step-page" data-recon-step-page="finalize">
                        <div class="mw-settings-actions" style="padding:0;">
                            <button type="button" class="mw-btn" data-finance-recon-finalize="1">Finalize Month</button>
                        </div>
                        <p class="mw-help">Finalize is available only when difference is 0.00.</p>
                    </div>
                    <?php if ( $current_section === 'reconciliation_steps' ) : ?>
                        <div class="metis-finance-v2-grid metis-finance-v2-grid--stacked">
                            <div class="metis-finance-v2-table-wrap">
                                <table class="metis-finance-v2-table">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th>Status</th>
                                            <th>Statement</th>
                                            <th>Difference</th>
                                            <th>Select</th>
                                        </tr>
                                    </thead>
                                    <tbody data-finance-recon-history="1">
                                        <tr><td colspan="5" class="metis-finance-v2-empty">No reconciliation months yet.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="metis-finance-v2-table-wrap">
                                <table class="metis-finance-v2-table">
                                    <thead>
                                        <tr>
                                            <th>When</th>
                                            <th>Event</th>
                                            <th>Reason</th>
                                            <th>Actor</th>
                                        </tr>
                                    </thead>
                                    <tbody data-finance-recon-audit="1">
                                        <tr><td colspan="4" class="metis-finance-v2-empty">Select a month to view audit history.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'budget' ) : ?>
                <section class="mw-settings-card">
        <div class="mw-settings-header"><h2>Budget</h2></div>
        <div class="mw-settings-body">
            <div class="metis-finance-v2-budget-toolbar">
                <div class="mw-field">
                    <label for="finance_budget_version_selector">Version</label>
                    <select id="finance_budget_version_selector" class="mw-input" data-budget-version-selector="1"></select>
                </div>
                <div class="mw-field">
                    <label>Version Status</label>
                    <div data-budget-version-status="1" class="mw-help">-</div>
                </div>
            </div>

                <form data-finance-budget-quick-form="1" class="metis-finance-v2-form">
                    <h3 class="metis-finance-v2-subhead">Quick Create Budget Version</h3>
                    <div class="metis-finance-v2-form-grid">
                        <div class="mw-field">
                            <label for="budget_quick_version_label">Version Label</label>
                            <input id="budget_quick_version_label" name="version_label" type="text" class="mw-input" placeholder="FY 2026 Working v2" required>
                        </div>
                    </div>
                    <div class="mw-settings-actions" style="padding:0;">
                        <button type="submit" class="mw-btn" data-budget-quick-submit="1">Create From Previous</button>
                    </div>
                </form>

                <details class="metis-finance-v2-details">
                    <summary>Advanced Version Fields</summary>
                <form data-finance-budget-version-form="1" class="metis-finance-v2-form">
                    <h3 class="metis-finance-v2-subhead">New Version (Copies Prior by Default)</h3>
                    <div class="metis-finance-v2-form-grid">
                        <div class="mw-field">
                            <label for="budget_version_label">Version Label</label>
                            <input id="budget_version_label" name="version_label" type="text" class="mw-input" placeholder="FY 2026 Working v2" required>
                        </div>
                        <div class="mw-field">
                            <label for="budget_fiscal_year">Fiscal Year</label>
                            <input id="budget_fiscal_year" name="fiscal_year" type="number" min="2000" max="3000" class="mw-input" required>
                        </div>
                        <div class="mw-field">
                            <label for="budget_period_start">Period Start</label>
                            <input id="budget_period_start" name="period_start" type="date" class="mw-input" required>
                        </div>
                        <div class="mw-field">
                            <label for="budget_period_end">Period End</label>
                            <input id="budget_period_end" name="period_end" type="date" class="mw-input" required>
                        </div>
                    </div>
                    <div class="mw-settings-actions" style="padding:0;">
                        <button type="submit" class="mw-btn" data-budget-version-submit="1">Create Version</button>
                    </div>
                </form>
                </details>

            <div class="metis-finance-v2-table-wrap">
                <table class="metis-finance-v2-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Planned</th>
                            <th>Actual</th>
                            <th>Variance</th>
                        </tr>
                    </thead>
                    <tbody data-budget-lines="1">
                        <tr><td colspan="4" class="metis-finance-v2-empty">No budget version selected.</td></tr>
                    </tbody>
                </table>
            </div>
                <div class="mw-settings-actions" style="padding:0;">
                    <button type="button" class="mw-btn" data-budget-lines-save="1">Save Budget Lines</button>
                </div>
        </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'settings' ) : ?>
                <section class="mw-settings-card">
        <div class="mw-settings-header"><h2>Fiscal Settings</h2></div>
        <div class="mw-settings-body">
                <form data-finance-category-form="1" class="metis-finance-v2-form">
                    <h3 class="metis-finance-v2-subhead">Categories</h3>
                    <div class="metis-finance-v2-form-grid">
                        <div class="mw-field">
                            <label for="finance_category_name">Category Name</label>
                            <input id="finance_category_name" name="category_name" type="text" class="mw-input" placeholder="Programs" required>
                        </div>
                        <div class="mw-field">
                            <label for="finance_category_code">Code (optional)</label>
                            <input id="finance_category_code" name="category_code" type="text" class="mw-input" placeholder="programs">
                        </div>
                    </div>
                    <div class="mw-settings-actions" style="padding:0;">
                        <button type="submit" class="mw-btn" data-finance-category-submit="1">Save Category</button>
                    </div>
                </form>

            <div class="metis-finance-v2-table-wrap">
                <table class="metis-finance-v2-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                        </tr>
                    </thead>
                    <tbody data-finance-categories-list="1">
                        <tr><td colspan="2" class="metis-finance-v2-empty">No categories yet.</td></tr>
                    </tbody>
                </table>
            </div>

                <form data-finance-fiscal-settings-form="1" class="metis-finance-v2-form">
                    <div class="metis-finance-v2-form-grid">
                        <div class="mw-field">
                            <label for="finance_fiscal_start_month">Fiscal Year Start Month</label>
                            <select id="finance_fiscal_start_month" name="fiscal_year_start_month" class="mw-input">
                                <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                                    <option value="<?php echo (int) $m; ?>"><?php echo metis_escape_html( gmdate( 'F', gmmktime( 0, 0, 0, $m, 1, 2026 ) ) ); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mw-settings-actions" style="padding:0;">
                        <button type="submit" class="mw-btn" data-finance-fiscal-settings-submit="1">Save Fiscal Settings</button>
                    </div>
                </form>

                <form data-finance-fiscal-migrate-form="1" class="metis-finance-v2-form">
                    <h3 class="metis-finance-v2-subhead">Migrate To New Fiscal Period</h3>
                    <div class="metis-finance-v2-form-grid">
                        <div class="mw-field">
                            <label for="finance_fiscal_period_label">Label</label>
                            <input id="finance_fiscal_period_label" name="label" type="text" class="mw-input" placeholder="FY 2027" required>
                        </div>
                        <div class="mw-field">
                            <label for="finance_fiscal_period_start">Start Date</label>
                            <input id="finance_fiscal_period_start" name="start_date" type="date" class="mw-input" required>
                        </div>
                        <div class="mw-field">
                            <label for="finance_fiscal_period_end">End Date</label>
                            <input id="finance_fiscal_period_end" name="end_date" type="date" class="mw-input" required>
                        </div>
                    </div>
                    <div class="mw-settings-actions" style="padding:0;">
                        <button type="submit" class="mw-btn" data-finance-fiscal-migrate-submit="1">Migrate Fiscal Period</button>
                    </div>
                </form>

            <div class="metis-finance-v2-table-wrap">
                <table class="metis-finance-v2-table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody data-finance-fiscal-periods="1">
                        <tr><td colspan="4" class="metis-finance-v2-empty">No fiscal periods yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'invoicing' ) : ?>
                <section class="mw-settings-card">
        <div class="mw-settings-header"><h2>Invoicing</h2></div>
        <div class="mw-settings-body">
            <div class="metis-finance-v2-kpis metis-finance-v2-kpis--aging">
                <article class="metis-finance-v2-kpi">
                    <p>Open Total</p>
                    <strong data-invoice-aging="open_total">0.00</strong>
                </article>
                <article class="metis-finance-v2-kpi">
                    <p>Current</p>
                    <strong data-invoice-aging="current_amount">0.00</strong>
                </article>
                <article class="metis-finance-v2-kpi">
                    <p>1-30 Due</p>
                    <strong data-invoice-aging="overdue_1_30_amount">0.00</strong>
                </article>
                <article class="metis-finance-v2-kpi">
                    <p>31-60 Due</p>
                    <strong data-invoice-aging="overdue_31_60_amount">0.00</strong>
                </article>
                <article class="metis-finance-v2-kpi">
                    <p>61+ Due</p>
                    <strong data-invoice-aging="overdue_61_plus_amount">0.00</strong>
                </article>
            </div>

                <div class="mw-settings-actions" style="padding:0;">
                    <button type="button" class="mw-btn" data-open-invoice-modal="1">Create Invoice</button>
                </div>

            <div class="metis-finance-v2-table-wrap">
                <table class="metis-finance-v2-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Issued</th>
                            <th>Due</th>
                            <th>Paid</th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody data-finance-invoices="1">
                        <tr><td colspan="8" class="metis-finance-v2-empty">No invoices yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'reports' ) : ?>
                <section class="mw-settings-card">
        <div class="mw-settings-header"><h2>Reports (PDF)</h2></div>
        <div class="mw-settings-body">
            <form data-finance-report-form="1" class="metis-finance-v2-form">
                <div class="metis-finance-v2-form-grid">
                    <div class="mw-field">
                        <label for="finance_report_type">Report</label>
                        <select id="finance_report_type" name="report_type" class="mw-input">
                            <option value="balance_sheet">Balance Sheet</option>
                            <option value="cash_flow">Cash Flow</option>
                            <option value="treasury_summary" selected>Treasury Summary</option>
                            <option value="budget_vs_actual">Budget vs Actual</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label for="finance_report_period">Period</label>
                        <select id="finance_report_period" name="period_code" class="mw-input">
                            <option value="mtd">MTD</option>
                            <option value="qtd">QTD</option>
                            <option value="ytd">YTD</option>
                            <option value="trailing_12">Trailing 12</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label for="finance_report_orientation">Orientation</label>
                        <select id="finance_report_orientation" name="orientation" class="mw-input">
                            <option value="landscape" selected>Landscape</option>
                            <option value="portrait">Portrait</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label class="metis-finance-inline-check">
                            <input type="checkbox" name="include_previous_month" value="1">
                            Include previous month reference
                        </label>
                    </div>
                </div>
                <div class="mw-settings-actions" style="padding:0;">
                    <button type="button" class="mw-btn mw-btn-ghost" data-finance-report-render="1">Render</button>
                    <button type="submit" class="mw-btn" data-finance-report-pdf="1">Download PDF</button>
                </div>
            </form>

            <div class="metis-finance-v2-table-wrap">
                <table class="metis-finance-v2-table">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Value</th>
                            <th>Secondary</th>
                        </tr>
                    </thead>
                    <tbody data-finance-report-preview="1">
                        <tr><td colspan="3" class="metis-finance-v2-empty">No report rendered yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
                </section>
            <?php endif; ?>

            <?php if ( $current_section === 'stripe_clearing' ) : ?>
                <section class="mw-settings-card">
            <div class="mw-settings-header"><h2>Stripe Clearing</h2></div>
        <div class="mw-settings-body">
            <div class="metis-finance-v2-stripe-kpis">
                <div><label>Clearing Balance</label><strong data-stripe-kpi="clearing_balance">0.00</strong></div>
                <div><label>Donations to Clearing</label><strong data-stripe-kpi="donations_total">0.00</strong></div>
                <div><label>Fees from Clearing</label><strong data-stripe-kpi="fees_total">0.00</strong></div>
                <div><label>Expected Deposits</label><strong data-stripe-kpi="expected_total">0.00</strong></div>
            </div>
            <p class="mw-help">Stripe webhooks feed clearing activity. This screen is for auto-match monitoring and unresolved exceptions.</p>
            <div class="mw-settings-actions" style="padding:0;">
                <button type="button" class="mw-btn" data-finance-stripe-auto-match="1">Run Auto-Match</button>
                <button type="button" class="mw-btn mw-btn-ghost" data-finance-stripe-refresh="1">Refresh</button>
            </div>
            <div class="metis-finance-v2-step-grid">
                <article class="metis-finance-v2-step-card">
                    <h3>1) Webhooks Ingest Activity</h3>
                    <p>Donations, fees, refunds, and payouts are captured automatically from Stripe events.</p>
                </article>
                <article class="metis-finance-v2-step-card">
                    <h3>2) Auto-Match by Amount and Date</h3>
                    <p>Run auto-match to pair expected deposits and unmatched bank lines using strict confidence rules.</p>
                </article>
                <article class="metis-finance-v2-step-card">
                    <h3>3) Resolve Remaining Exceptions</h3>
                    <p>Only unmatched items need action. Use manual resolve if a safe auto-match is not possible.</p>
                </article>
            </div>

            <div class="metis-finance-v2-grid">
                <div class="metis-finance-v2-table-wrap">
                    <table class="metis-finance-v2-table">
                        <thead>
                            <tr>
                                <th>Deposit Ref</th>
                                <th>Date</th>
                                <th>Expected</th>
                                <th>Status</th>
                                <th>Resolve</th>
                            </tr>
                        </thead>
                        <tbody data-finance-stripe-payouts="1">
                            <tr><td colspan="5" class="metis-finance-v2-empty">No unmatched expected deposits.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="metis-finance-v2-table-wrap">
                    <table class="metis-finance-v2-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Matched Deposit</th>
                            </tr>
                        </thead>
                        <tbody data-finance-bank-lines="1">
                            <tr><td colspan="5" class="metis-finance-v2-empty">No unmatched bank lines.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <div id="metis-finance-gl-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-finance-v2-modal-inner">
            <h3 class="metis-contacts-modal-title">Quick GL Entry</h3>
            <form data-finance-gl-form="1" class="metis-finance-v2-form">
                <div class="metis-finance-v2-hidden-selects">
                    <select data-finance-account-select="1" aria-hidden="true" tabindex="-1"></select>
                    <select data-finance-category-select="1" aria-hidden="true" tabindex="-1"><option value="">None</option></select>
                </div>
                <div class="metis-finance-v2-table-wrap">
                    <table class="metis-finance-v2-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody data-gl-quick-rows="1"></tbody>
                    </table>
                </div>
                <div class="mw-settings-actions" style="padding:0;">
                    <button type="button" class="mw-btn mw-btn-ghost" data-gl-add-row="1">Add Row</button>
                    <button type="submit" class="mw-btn" data-finance-gl-submit="1">Save Entries</button>
                    <button type="button" class="mw-btn mw-btn-ghost" data-close-gl-modal="1">Close</button>
                </div>
            </form>
        </div>
    </div>

    <div id="metis-finance-invoice-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-finance-v2-modal-inner">
            <h3 class="metis-contacts-modal-title">Create Invoice</h3>
            <form data-finance-invoice-form="1" class="metis-finance-v2-form">
                <div class="metis-finance-v2-form-grid">
                    <div class="mw-field">
                        <label for="invoice_customer_name">Customer Name</label>
                        <input id="invoice_customer_name" name="customer_name" type="text" class="mw-input" required>
                    </div>
                    <div class="mw-field">
                        <label for="invoice_customer_email">Customer Email</label>
                        <input id="invoice_customer_email" name="customer_email" type="email" class="mw-input" required>
                    </div>
                    <div class="mw-field">
                        <label for="invoice_issued_date">Issue Date</label>
                        <input id="invoice_issued_date" name="issued_date" type="date" class="mw-input" required>
                    </div>
                    <div class="mw-field">
                        <label for="invoice_due_date">Due Date</label>
                        <input id="invoice_due_date" name="due_date" type="date" class="mw-input" required>
                    </div>
                    <div class="mw-field">
                        <label for="invoice_line_description">Line Description</label>
                        <input id="invoice_line_description" name="line_description" type="text" class="mw-input" placeholder="Monthly program services" required>
                    </div>
                    <div class="mw-field">
                        <label for="invoice_line_quantity">Qty</label>
                        <input id="invoice_line_quantity" name="line_quantity" type="number" min="0.01" step="0.01" class="mw-input" value="1.00" required>
                    </div>
                    <div class="mw-field">
                        <label for="invoice_line_unit_amount">Unit Amount</label>
                        <input id="invoice_line_unit_amount" name="line_unit_amount" type="number" min="0" step="0.01" class="mw-input" required>
                    </div>
                    <div class="mw-field">
                        <label for="invoice_notes">Notes (optional)</label>
                        <input id="invoice_notes" name="notes" type="text" class="mw-input" placeholder="Stripe payment only">
                    </div>
                </div>
                <div class="mw-settings-actions" style="padding:0;">
                    <button type="submit" class="mw-btn" data-finance-invoice-submit="1">Create Invoice</button>
                    <button type="button" class="mw-btn mw-btn-ghost" data-close-invoice-modal="1">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>
