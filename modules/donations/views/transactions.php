<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url = metis_donations_base_url();
$offline_lookup_nonce = function_exists( 'metis_runtime_create_nonce' ) && function_exists( 'metis_ajax_nonce_action' )
    ? (string) metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_donations_lookup_donors' ) )
    : '';

// -------------------------------------------------------------------------
// Handle offline donation POST
// -------------------------------------------------------------------------
$offline_notice = '';
$offline_error  = '';
$offline_form   = [
    'donor_did'      => '',
    'tran_date'      => date( 'Y-m-d' ),
    'amount'         => '',
    'campaign_code'  => '',
    'payment_method' => 'ck',
    'chk_num'        => '',
    'first_name'     => '',
    'last_name'      => '',
    'email'          => '',
    'phone'          => '',
    'notes'          => '',
];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset( $_POST['mw_action'] )
    && $_POST['mw_action'] === 'record_offline_donation'
) {
    if ( ! isset( $_POST['mw_offline_donation_nonce'] ) || ! metis_runtime_verify_nonce( (string) $_POST['mw_offline_donation_nonce'], 'mw_record_offline_donation' ) ) {
        metis_runtime_die( 'Invalid nonce.', 'Error', [ 'response' => 403 ] );
    }

    foreach ( $offline_form as $key => $value ) {
        $offline_form[ $key ] = is_string( $_POST[ $key ] ?? null ) ? trim( (string) $_POST[ $key ] ) : $value;
    }

    $result = \Metis\Modules\Donations\DonationsModule::recordOfflineDonation( $offline_form, (int) metis_current_user_id() );
    if ( ! empty( $result['ok'] ) ) {
        $offline_notice = sprintf( 'Offline donation %s recorded successfully.', (string) ( $result['tid'] ?? '' ) );
        $offline_form   = [
            'donor_did'      => '',
            'tran_date'      => date( 'Y-m-d' ),
            'amount'         => '',
            'campaign_code'  => '',
            'payment_method' => 'ck',
            'chk_num'        => '',
            'first_name'     => '',
            'last_name'      => '',
            'email'          => '',
            'phone'          => '',
            'notes'          => '',
        ];
    } else {
        $offline_error = (string) ( $result['message'] ?? 'Unable to record the offline donation.' );
    }
}

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
// Fetch campaigns for offline entry
// -------------------------------------------------------------------------
$campaign_options = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "SELECT cid, cname, active
     FROM {$campaigns_table}
     ORDER BY active DESC, cname ASC, cid ASC"
) ?: [] );

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
<p><button type="button" class="mw-btn" id="mw-open-offline-donation-modal">Record Offline Donation</button></p>

<?php if ( $offline_notice ) : ?>
    <div class="mw-alert mw-alert-success"><?php echo metis_escape_html( $offline_notice ); ?></div>
<?php endif; ?>
<?php if ( $offline_error ) : ?>
    <div class="mw-alert mw-alert-error"><?php echo metis_escape_html( $offline_error ); ?></div>
<?php endif; ?>
<?php if ( $batch_notice ) : ?>
    <div class="mw-alert mw-alert-success"><?php echo metis_escape_html( $batch_notice ); ?></div>
<?php endif; ?>
<?php if ( $batch_error ) : ?>
    <div class="mw-alert mw-alert-error"><?php echo metis_escape_html( $batch_error ); ?></div>
<?php endif; ?>

<div class="mw-modal-backdrop mw-offline-donation-modal" id="mw-offline-donation-modal" aria-hidden="<?php echo $offline_error ? 'false' : 'true'; ?>"<?php echo $offline_error ? ' data-auto-open="1"' : ''; ?>>
    <div class="mw-modal mw-offline-donation-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mw-offline-donation-title">
        <div class="mw-modal-header">
            <div>
                <h2 class="mw-modal-title" id="mw-offline-donation-title">Record Offline Donation</h2>
                <p class="mw-muted mw-offline-donation-subtitle">Search for an existing donor first. If no match fits, enter donor details and a new donor will be created when you save.</p>
            </div>
            <button type="button" class="mw-modal-close" id="mw-offline-donation-close" aria-label="Close">&times;</button>
        </div>
        <div class="mw-modal-body">
    <form method="post" class="mw-offline-donation-form" id="mw-offline-donation-form">
        <?php metis_runtime_nonce_field( 'mw_record_offline_donation', 'mw_offline_donation_nonce' ); ?>
        <input type="hidden" name="mw_action" value="record_offline_donation">
        <input type="hidden" name="donor_did" id="mw-offline-donor-did" value="<?php echo metis_escape_attr( (string) $offline_form['donor_did'] ); ?>">

        <div class="mw-offline-donation-grid">
            <div class="mw-offline-field mw-offline-field-lookup">
                <span>Donor Lookup</span>
                <input type="search" id="mw-offline-donor-lookup" class="mw-input" placeholder="Search by donor name, email, or DID" autocomplete="off" data-lookup-nonce="<?php echo metis_escape_attr( $offline_lookup_nonce ); ?>">
                <div class="mw-offline-donor-status" id="mw-offline-donor-status">No donor selected. Saving will create a new donor if needed.</div>
                <div class="mw-offline-donor-results" id="mw-offline-donor-results" hidden></div>
            </div>
            <label class="mw-offline-field">
                <span>Date</span>
                <input type="date" name="tran_date" class="mw-input" value="<?php echo metis_escape_attr( (string) $offline_form['tran_date'] ); ?>" required>
            </label>
            <label class="mw-offline-field">
                <span>Amount</span>
                <input type="number" name="amount" class="mw-input" min="0.01" step="0.01" value="<?php echo metis_escape_attr( (string) $offline_form['amount'] ); ?>" placeholder="0.00" required>
            </label>
            <label class="mw-offline-field">
                <span>Campaign</span>
                <select name="campaign_code" class="mw-select" required>
                    <option value="">Select campaign</option>
                    <?php foreach ( $campaign_options as $campaign_option ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $campaign_option->cid ); ?>"<?php echo (string) $offline_form['campaign_code'] === (string) $campaign_option->cid ? ' selected' : ''; ?>>
                            <?php echo metis_escape_html( (string) $campaign_option->cname ); ?><?php echo (int) $campaign_option->active === 1 ? '' : ' (Inactive)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mw-offline-field">
                <span>Payment Method</span>
                <select name="payment_method" class="mw-select" data-offline-method>
                    <option value="ck"<?php echo (string) $offline_form['payment_method'] === 'ck' ? ' selected' : ''; ?>>Check</option>
                    <option value="cash"<?php echo (string) $offline_form['payment_method'] === 'cash' ? ' selected' : ''; ?>>Cash</option>
                    <option value="ach"<?php echo (string) $offline_form['payment_method'] === 'ach' ? ' selected' : ''; ?>>ACH</option>
                    <option value="other"<?php echo (string) $offline_form['payment_method'] === 'other' ? ' selected' : ''; ?>>Other</option>
                </select>
            </label>
            <label class="mw-offline-field mw-offline-field-check" data-offline-check-field>
                <span>Check Number</span>
                <input type="text" name="chk_num" class="mw-input" value="<?php echo metis_escape_attr( (string) $offline_form['chk_num'] ); ?>" placeholder="Optional">
            </label>
            <label class="mw-offline-field">
                <span>First Name</span>
                <input type="text" name="first_name" class="mw-input" value="<?php echo metis_escape_attr( (string) $offline_form['first_name'] ); ?>" placeholder="Donor first name">
            </label>
            <label class="mw-offline-field">
                <span>Last Name</span>
                <input type="text" name="last_name" class="mw-input" value="<?php echo metis_escape_attr( (string) $offline_form['last_name'] ); ?>" placeholder="Donor last name">
            </label>
            <label class="mw-offline-field">
                <span>Email</span>
                <input type="email" name="email" class="mw-input" value="<?php echo metis_escape_attr( (string) $offline_form['email'] ); ?>" placeholder="donor@example.org">
            </label>
            <label class="mw-offline-field">
                <span>Phone</span>
                <input type="text" name="phone" class="mw-input" value="<?php echo metis_escape_attr( (string) $offline_form['phone'] ); ?>" placeholder="Optional">
            </label>
            <label class="mw-offline-field mw-offline-field-notes">
                <span>Notes</span>
                <textarea name="notes" class="mw-input" rows="3" placeholder="Internal notes or source details"><?php echo metis_escape_html( (string) $offline_form['notes'] ); ?></textarea>
            </label>
        </div>

        <div class="mw-offline-donation-actions">
            <button type="button" class="mw-btn mw-btn-ghost" id="mw-offline-donation-cancel">Cancel</button>
            <button type="submit" class="mw-btn">Record Offline Donation</button>
        </div>
    </form>
        </div>
    </div>
</div>

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
