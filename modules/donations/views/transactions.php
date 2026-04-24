<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url = metis_donations_base_url();

// -------------------------------------------------------------------------
// Handle batch creation POST
// -------------------------------------------------------------------------
$batch_notice = '';
$batch_error  = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset( $_POST['mw_action'] )
    && $_POST['mw_action'] === 'create_batch'
) {
    if ( ! isset( $_POST['mw_batch_nonce'] ) || ! metis_runtime_verify_nonce( (string) $_POST['mw_batch_nonce'], 'mw_create_batch' ) ) {
        metis_runtime_die( 'Invalid nonce.', 'Error', [ 'response' => 403 ] );
    }

    $selected_tids = isset( $_POST['tx'] ) && is_array( $_POST['tx'] )
        ? array_filter( array_map( 'metis_text_clean', $_POST['tx'] ) )
        : [];

    if ( empty( $selected_tids ) ) {
        $batch_error = 'No transactions were selected for the batch.';
    } else {
        $batch_code = metis_create_deposit_batch( array_values( $selected_tids ) );

        if ( metis_runtime_is_error( $batch_code ) ) {
            $batch_error = 'Unable to create the deposit batch.';
        } else {
            $batch_notice = sprintf(
                'Deposit batch %s created with %d transaction(s).',
                metis_escape_html( $batch_code ),
                count( $selected_tids )
            );
        }
    }
}

// -------------------------------------------------------------------------
// Fetch transactions
// -------------------------------------------------------------------------
$transactions = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll( "
    SELECT
        t.*,
        c.cname      AS campaign_name,
        d.first_name,
        d.last_name,
        d.email
    FROM {$transactions_table} t
    LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
    LEFT JOIN {$contacts_table}  d ON d.did = t.did
    ORDER BY t.tran_date DESC, t.id DESC
    LIMIT 500
" ) ?: [] );
?>

<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Transactions' ) ); ?></h1>
<p class="mw-subtitle">Manage individual donations and create deposit batches.</p>

<?php if ( $batch_notice ) : ?>
    <div class="mw-alert mw-alert-success"><?php echo metis_escape_html( $batch_notice ); ?></div>
<?php endif; ?>
<?php if ( $batch_error ) : ?>
    <div class="mw-alert mw-alert-error"><?php echo metis_escape_html( $batch_error ); ?></div>
<?php endif; ?>

<div class="mw-list-layout mw-tx-list-layout">

<!-- Sidebar -->
<aside class="mw-list-sidebar">
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Search</div>
        <input type="text" id="mw-search" class="mw-input" placeholder="Search anything…">
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Status</div>
        <select id="mw-status-filter" class="mw-select">
            <option value="all">All</option>
            <option value="undeposited">Undeposited</option>
            <option value="deposited">Deposited</option>
        </select>
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Campaign</div>
        <select id="mw-campaign-filter" class="mw-select"></select>
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Payment Method</div>
        <select id="mw-method-filter" class="mw-select"></select>
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Sort</div>
        <select id="mw-sort" class="mw-select">
            <option value="date_desc">Date · Newest first</option>
            <option value="date_asc">Date · Oldest first</option>
            <option value="amount_desc">Amount · High to low</option>
            <option value="amount_asc">Amount · Low to high</option>
        </select>
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Date Range</div>
        <div style="display:flex; flex-direction:column; gap:5px;">
            <input type="text" id="mw-date-start" class="mw-input" placeholder="mm/dd/yy" maxlength="8">
            <input type="text" id="mw-date-end" class="mw-input" placeholder="mm/dd/yy" maxlength="8">
        </div>
        <div class="mw-quick-dates" style="display:flex; flex-wrap:wrap; gap:4px; margin-top:7px;">
            <button type="button" data-range="today" class="mw-pill-btn">Today</button>
            <button type="button" data-range="week" class="mw-pill-btn">Week</button>
            <button type="button" data-range="month" class="mw-pill-btn">Month</button>
            <button type="button" data-range="reset" class="mw-pill-btn mw-pill-reset">Reset</button>
        </div>
    </div>
    <div class="mw-list-sidebar-actions">
        <button type="button" id="mw-export-csv" class="mw-btn mw-btn-xs mw-btn-ghost">Export CSV</button>
    </div>
</aside>

<!-- Main content -->
<div class="mw-list-content">
<div class="mw-active-filters" id="mw-active-filters"></div>

<!-- TRANSACTIONS + BATCH FORM -->
<form method="post" class="mw-transactions-view">
    <?php metis_runtime_nonce_field( 'mw_create_batch', 'mw_batch_nonce' ); ?>
    <input type="hidden" name="mw_action" value="create_batch">

    <div class="mw-premium-table mw-tx-table">

        <div class="mw-premium-row mw-premium-header mw-tx-header">
            <div class="mw-tx-col mw-tx-col-select">
                <input type="checkbox" id="mw-tx-select-all">
            </div>
            <div class="mw-tx-col mw-tx-col-main">Date · Donor · Campaign</div>
            <div class="mw-tx-col mw-tx-col-amount">Amount</div>
            <div class="mw-tx-col mw-tx-col-flags">Status / Deposit</div>
        </div>

        <?php if ( ! empty( $transactions ) ) : ?>
            <?php foreach ( $transactions as $t ) :

                $donor_name   = trim( ( $t->first_name ?: '' ) . ' ' . ( $t->last_name ?: '' ) );
                $campaign     = $t->campaign_name ?: $t->campaign_code ?: '—';
                $amount       = (float) $t->amount;
                $amount_fmt   = '$' . number_format( $amount, 2 );
                $timestamp    = $t->tran_date ? strtotime( $t->tran_date ) : 0;
                $display_date = $timestamp ? date( 'm/d/y', $timestamp ) : '—';
                $iso_date     = $timestamp ? date( 'Y-m-d', $timestamp ) : '';
                $is_deposited = empty( $t->deposit_batch_id ) ? 'no' : 'yes';
                $tx_url       = $base_url . '/transaction/?tid=' . urlencode( $t->tid );

                $status_html  = metis_status_badge( $t->status );
                $method_html  = metis_paymethod_badge( $t->payment_method );
                $deposit_html = metis_deposit_badge( $t->deposit_date );
            ?>
                <div class="mw-premium-row mw-tx-row"
                     data-tid="<?php echo metis_escape_attr( $t->tid ); ?>"
                     data-status="<?php echo metis_escape_attr( strtolower( $t->status ) ); ?>"
                     data-deposited="<?php echo metis_escape_attr( $is_deposited ); ?>"
                     data-campaign="<?php echo metis_escape_attr( strtolower( $campaign ) ); ?>"
                     data-method="<?php echo metis_escape_attr( strtolower( $t->payment_method ?? '' ) ); ?>"
                     data-date="<?php echo metis_escape_attr( $iso_date ); ?>"
                     data-amount="<?php echo metis_escape_attr( $amount ); ?>">

                    <div class="mw-tx-col mw-tx-col-select">
                        <?php if ( empty( $t->deposit_batch_id ) ) : ?>
                            <input type="checkbox" class="mw-tx-checkbox" name="tx[]" value="<?php echo metis_escape_attr( $t->tid ); ?>">
                        <?php else : ?>
                            <span class="mw-tx-locked mw-muted">—</span>
                        <?php endif; ?>
                    </div>

                    <div class="mw-tx-col mw-tx-col-main">
                        <a href="<?php echo metis_escape_url( $tx_url ); ?>" class="mw-tx-main-link">
                            <div class="mw-tx-line1">
                                <span class="mw-tx-date"><?php echo metis_escape_html( $display_date ); ?></span>
                                <span class="mw-tx-sep">·</span>
                                <span class="mw-tx-donor"><?php echo metis_escape_html( $donor_name ?: $t->email ?: 'Unknown donor' ); ?></span>
                            </div>
                            <div class="mw-tx-line2">
                                <span class="mw-tx-campaign"><?php echo metis_escape_html( $campaign ); ?></span>
                            </div>
                        </a>
                    </div>

                    <div class="mw-tx-col mw-tx-col-amount">
                        <span class="mw-tx-amount mw-col-numeric"><?php echo metis_escape_html( $amount_fmt ); ?></span>
                    </div>

                    <div class="mw-tx-col mw-tx-col-flags">
                        <div class="mw-tx-flags">
                            <?php echo $status_html; ?>
                            <?php echo $method_html; ?>
                            <?php echo $deposit_html; ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="mw-premium-row">
                <div class="mw-premium-cell mw-muted">No transactions found.</div>
            </div>
        <?php endif; ?>

    </div>

    <div class="mw-batch-footer">
        <div class="mw-batch-summary">
            <span id="mw-batch-count">0</span> selected ·
            <span id="mw-batch-total">$0.00</span> gross
        </div>
        <button type="submit" class="mw-btn mw-btn-xs" id="mw-create-batch-btn" disabled>
            Create Deposit Batch
        </button>
    </div>

</form>
</div><!-- /mw-list-content -->
</div><!-- /mw-list-layout -->
