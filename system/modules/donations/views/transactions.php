<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url = metis_donations_base_url();
$can_manage = function_exists( 'metis_donations_can_manage' ) && metis_donations_can_manage();
$can_export = function_exists( 'metis_donations_can_export' ) && metis_donations_can_export();
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
    && isset( $_POST['metis_action'] )
    && $_POST['metis_action'] === 'record_offline_donation'
) {
    if ( ! $can_manage ) {
        metis_runtime_die( 'Unauthorized.', 'Error', [ 'response' => 403 ] );
    }

    if ( ! isset( $_POST['metis_offline_donation_nonce'] ) || ! metis_runtime_verify_nonce( (string) $_POST['metis_offline_donation_nonce'], 'metis_record_offline_donation' ) ) {
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
    && isset( $_POST['metis_action'] )
    && $_POST['metis_action'] === 'create_batch'
) {
    if ( ! $can_manage ) {
        metis_runtime_die( 'Unauthorized.', 'Error', [ 'response' => 403 ] );
    }

    if ( ! isset( $_POST['metis_batch_nonce'] ) || ! metis_runtime_verify_nonce( (string) $_POST['metis_batch_nonce'], 'metis_create_batch' ) ) {
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

<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Transactions' ) ); ?></h1>
<p class="metis-subtitle">Manage individual donations and create deposit batches.</p>
<?php if ( $can_manage ) : ?>
<p><button type="button" class="metis-btn" id="metis-open-offline-donation-modal">Record Offline Donation</button></p>
<?php endif; ?>

<?php if ( $offline_notice ) : ?>
    <div class="metis-alert metis-alert-success"><?php echo metis_escape_html( $offline_notice ); ?></div>
<?php endif; ?>
<?php if ( $offline_error ) : ?>
    <div class="metis-alert metis-alert-error"><?php echo metis_escape_html( $offline_error ); ?></div>
<?php endif; ?>
<?php if ( $batch_notice ) : ?>
    <div class="metis-alert metis-alert-success"><?php echo metis_escape_html( $batch_notice ); ?></div>
<?php endif; ?>
<?php if ( $batch_error ) : ?>
    <div class="metis-alert metis-alert-error"><?php echo metis_escape_html( $batch_error ); ?></div>
<?php endif; ?>

<?php if ( $can_manage ) : ?>
<div class="metis-modal-backdrop metis-offline-donation-modal" id="metis-offline-donation-modal" aria-hidden="<?php echo $offline_error ? 'false' : 'true'; ?>"<?php echo $offline_error ? ' data-auto-open="1"' : ''; ?>>
    <div class="metis-modal metis-offline-donation-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="metis-offline-donation-title">
        <div class="metis-modal-header">
            <div>
                <h2 class="metis-modal-title" id="metis-offline-donation-title">Record Offline Donation</h2>
                <p class="metis-muted metis-offline-donation-subtitle">Search for an existing donor first. If no match fits, enter donor details and a new donor will be created when you save.</p>
            </div>
            <button type="button" class="metis-modal-close" id="metis-offline-donation-close" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
    <form method="post" class="metis-offline-donation-form" id="metis-offline-donation-form">
        <?php metis_runtime_nonce_field( 'metis_record_offline_donation', 'metis_offline_donation_nonce' ); ?>
        <input type="hidden" name="metis_action" value="record_offline_donation">
        <input type="hidden" name="donor_did" id="metis-offline-donor-did" value="<?php echo metis_escape_attr( (string) $offline_form['donor_did'] ); ?>">

        <div class="metis-offline-donation-grid">
            <div class="metis-offline-field metis-offline-field-lookup">
                <span>Donor Lookup</span>
                <input type="search" id="metis-offline-donor-lookup" class="metis-input" placeholder="Search by donor name, email, or DID" autocomplete="off" data-lookup-nonce="<?php echo metis_escape_attr( $offline_lookup_nonce ); ?>">
                <div class="metis-offline-donor-status" id="metis-offline-donor-status">No donor selected. Saving will create a new donor if needed.</div>
                <div class="metis-offline-donor-results" id="metis-offline-donor-results" hidden></div>
            </div>
            <label class="metis-offline-field">
                <span>Date</span>
                <input type="date" name="tran_date" class="metis-input" value="<?php echo metis_escape_attr( (string) $offline_form['tran_date'] ); ?>" required>
            </label>
            <label class="metis-offline-field">
                <span>Amount</span>
                <input type="number" name="amount" class="metis-input" min="0.01" step="0.01" value="<?php echo metis_escape_attr( (string) $offline_form['amount'] ); ?>" placeholder="0.00" required>
            </label>
            <label class="metis-offline-field">
                <span>Campaign</span>
                <select name="campaign_code" class="metis-select" required>
                    <option value="">Select campaign</option>
                    <?php foreach ( $campaign_options as $campaign_option ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $campaign_option->cid ); ?>"<?php echo (string) $offline_form['campaign_code'] === (string) $campaign_option->cid ? ' selected' : ''; ?>>
                            <?php echo metis_escape_html( (string) $campaign_option->cname ); ?><?php echo (int) $campaign_option->active === 1 ? '' : ' (Inactive)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="metis-offline-field">
                <span>Payment Method</span>
                <select name="payment_method" class="metis-select" data-offline-method>
                    <option value="ck"<?php echo (string) $offline_form['payment_method'] === 'ck' ? ' selected' : ''; ?>>Check</option>
                    <option value="cash"<?php echo (string) $offline_form['payment_method'] === 'cash' ? ' selected' : ''; ?>>Cash</option>
                    <option value="ach"<?php echo (string) $offline_form['payment_method'] === 'ach' ? ' selected' : ''; ?>>ACH</option>
                    <option value="other"<?php echo (string) $offline_form['payment_method'] === 'other' ? ' selected' : ''; ?>>Other</option>
                </select>
            </label>
            <label class="metis-offline-field metis-offline-field-check" data-offline-check-field>
                <span>Check Number</span>
                <input type="text" name="chk_num" class="metis-input" value="<?php echo metis_escape_attr( (string) $offline_form['chk_num'] ); ?>" placeholder="Optional">
            </label>
            <label class="metis-offline-field">
                <span>First Name</span>
                <input type="text" name="first_name" class="metis-input" value="<?php echo metis_escape_attr( (string) $offline_form['first_name'] ); ?>" placeholder="Donor first name">
            </label>
            <label class="metis-offline-field">
                <span>Last Name</span>
                <input type="text" name="last_name" class="metis-input" value="<?php echo metis_escape_attr( (string) $offline_form['last_name'] ); ?>" placeholder="Donor last name">
            </label>
            <label class="metis-offline-field">
                <span>Email</span>
                <input type="email" name="email" class="metis-input" value="<?php echo metis_escape_attr( (string) $offline_form['email'] ); ?>" placeholder="donor@example.org">
            </label>
            <label class="metis-offline-field">
                <span>Phone</span>
                <input type="text" name="phone" class="metis-input" value="<?php echo metis_escape_attr( (string) $offline_form['phone'] ); ?>" placeholder="Optional">
            </label>
            <label class="metis-offline-field metis-offline-field-notes">
                <span>Notes</span>
                <textarea name="notes" class="metis-input" rows="3" placeholder="Internal notes or source details"><?php echo metis_escape_html( (string) $offline_form['notes'] ); ?></textarea>
            </label>
        </div>

        <div class="metis-offline-donation-actions">
            <button type="button" class="metis-btn metis-btn-ghost" id="metis-offline-donation-cancel">Cancel</button>
            <button type="submit" class="metis-btn">Record Offline Donation</button>
        </div>
    </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="metis-list-layout metis-tx-list-layout">

<!-- Sidebar -->
<aside class="metis-list-sidebar">
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Search</div>
        <input type="text" id="metis-search" class="metis-input" placeholder="Search anything…">
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Status</div>
        <select id="metis-status-filter" class="metis-select">
            <option value="all">All</option>
            <option value="undeposited">Undeposited</option>
            <option value="deposited">Deposited</option>
        </select>
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Campaign</div>
        <select id="metis-campaign-filter" class="metis-select"></select>
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Payment Method</div>
        <select id="metis-method-filter" class="metis-select"></select>
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Sort</div>
        <select id="metis-sort" class="metis-select">
            <option value="date_desc">Date · Newest first</option>
            <option value="date_asc">Date · Oldest first</option>
            <option value="amount_desc">Amount · High to low</option>
            <option value="amount_asc">Amount · Low to high</option>
        </select>
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Date Range</div>
        <div style="display:flex; flex-direction:column; gap:5px;">
            <input type="text" id="metis-date-start" class="metis-input" placeholder="mm/dd/yy" maxlength="8">
            <input type="text" id="metis-date-end" class="metis-input" placeholder="mm/dd/yy" maxlength="8">
        </div>
        <div class="metis-quick-dates" style="display:flex; flex-wrap:wrap; gap:4px; margin-top:7px;">
            <button type="button" data-range="today" class="metis-pill-btn">Today</button>
            <button type="button" data-range="week" class="metis-pill-btn">Week</button>
            <button type="button" data-range="month" class="metis-pill-btn">Month</button>
            <button type="button" data-range="reset" class="metis-pill-btn metis-pill-reset">Reset</button>
        </div>
    </div>
    <?php if ( $can_export ) : ?>
    <div class="metis-list-sidebar-actions">
        <button type="button" id="metis-export-csv" class="metis-btn metis-btn-xs metis-btn-ghost">Export CSV</button>
    </div>
    <?php endif; ?>
</aside>

<!-- Main content -->
<div class="metis-list-content">
<div class="metis-active-filters" id="metis-active-filters"></div>

<!-- TRANSACTIONS + BATCH FORM -->
<form method="post" class="metis-transactions-view">
    <?php metis_runtime_nonce_field( 'metis_create_batch', 'metis_batch_nonce' ); ?>
    <input type="hidden" name="metis_action" value="create_batch">

    <table class="metis-premium-table metis-tx-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header metis-tx-header">
                <th class="metis-premium-cell metis-tx-col metis-tx-col-select" scope="col">
                    <input type="checkbox" id="metis-tx-select-all">
                </th>
                <th class="metis-premium-cell metis-tx-col metis-tx-col-main" scope="col">Date · Donor · Campaign</th>
                <th class="metis-premium-cell metis-tx-col metis-tx-col-amount" scope="col">Amount</th>
                <th class="metis-premium-cell metis-tx-col metis-tx-col-flags" scope="col">Status / Deposit</th>
            </tr>
        </thead>
        <tbody>

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
                <tr class="metis-premium-row metis-tx-row"
                     data-tid="<?php echo metis_escape_attr( $t->tid ); ?>"
                     data-status="<?php echo metis_escape_attr( strtolower( $t->status ) ); ?>"
                     data-deposited="<?php echo metis_escape_attr( $is_deposited ); ?>"
                     data-campaign="<?php echo metis_escape_attr( strtolower( $campaign ) ); ?>"
                     data-method="<?php echo metis_escape_attr( strtolower( $t->payment_method ?? '' ) ); ?>"
                     data-date="<?php echo metis_escape_attr( $iso_date ); ?>"
                     data-amount="<?php echo metis_escape_attr( $amount ); ?>">

                    <td class="metis-premium-cell metis-tx-col metis-tx-col-select">
                        <?php if ( $can_manage && empty( $t->deposit_batch_id ) ) : ?>
                            <input type="checkbox" class="metis-tx-checkbox" name="tx[]" value="<?php echo metis_escape_attr( $t->tid ); ?>">
                        <?php else : ?>
                            <span class="metis-tx-locked metis-muted">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="metis-premium-cell metis-tx-col metis-tx-col-main">
                        <a href="<?php echo metis_escape_url( $tx_url ); ?>" class="metis-tx-main-link">
                            <div class="metis-tx-line1">
                                <span class="metis-tx-date"><?php echo metis_escape_html( $display_date ); ?></span>
                                <span class="metis-tx-sep">·</span>
                                <span class="metis-tx-donor"><?php echo metis_escape_html( $donor_name ?: $t->email ?: 'Unknown donor' ); ?></span>
                            </div>
                            <div class="metis-tx-line2">
                                <span class="metis-tx-campaign"><?php echo metis_escape_html( $campaign ); ?></span>
                            </div>
                        </a>
                    </td>

                    <td class="metis-premium-cell metis-tx-col metis-tx-col-amount">
                        <span class="metis-tx-amount metis-col-numeric"><?php echo metis_escape_html( $amount_fmt ); ?></span>
                    </td>

                    <td class="metis-premium-cell metis-tx-col metis-tx-col-flags">
                        <div class="metis-tx-flags">
                            <?php echo $status_html; ?>
                            <?php echo $method_html; ?>
                            <?php echo $deposit_html; ?>
                        </div>
                    </td>

                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell metis-muted" colspan="4">No transactions found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $can_manage ) : ?>
    <div class="metis-batch-footer">
        <div class="metis-batch-summary">
            <span id="metis-batch-count">0</span> selected ·
            <span id="metis-batch-total">$0.00</span> gross
        </div>
        <button type="submit" class="metis-btn metis-btn-xs" id="metis-create-batch-btn" disabled>
            Create Deposit Batch
        </button>
    </div>
    <?php endif; ?>

</form>
</div><!-- /metis-list-content -->
</div><!-- /metis-list-layout -->
