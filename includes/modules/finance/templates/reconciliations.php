<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'metis_finance_view_badge' ) ) {
    function metis_finance_view_badge( string $status ): string {
        $status = strtolower( $status );
        $map = [
            'matched' => [ 'Matched', 'green' ],
            'review'  => [ 'Review', 'red' ],
            'open'    => [ 'Open', 'blue' ],
            'pending' => [ 'Pending', 'muted' ],
            'posted'  => [ 'Posted', 'green' ],
        ];
        if ( ! isset( $map[ $status ] ) ) {
            return '<span class="mw-badge gray">' . esc_html( ucfirst( $status ) ) . '</span>';
        }
        [ $label, $color ] = $map[ $status ];
        return '<span class="mw-badge ' . $color . '">' . esc_html( $label ) . '</span>';
    }
}

if ( ! metis_finance_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view account reconciliation.</div>';
    return;
}

metis_finance_ensure_schema();
metis_finance_sync_ledger_from_deposits();
metis_finance_sync_reconciliations();

global $wpdb;

$accounts_table = Metis_Tables::get( 'finance_accounts' );
$ledger_table   = Metis_Tables::get( 'finance_ledger' );
$recons_table   = Metis_Tables::get( 'finance_reconciliations' );
$can_manage     = metis_finance_can_manage();
$message        = '';
$message_type   = 'success';

if ( $can_manage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['metis_finance_recon_nonce'] ) && metis_verify_nonce( sanitize_text_field( metis_unslash( $_POST['metis_finance_recon_nonce'] ) ), 'metis_finance_recon_save' ) ) {
    $recon_id = isset( $_POST['recon_id'] ) ? (int) $_POST['recon_id'] : 0;
    $statement_balance = isset( $_POST['statement_balance'] ) && $_POST['statement_balance'] !== '' ? round( (float) metis_unslash( $_POST['statement_balance'] ), 2 ) : null;
    $notes = sanitize_textarea_field( (string) metis_unslash( $_POST['notes'] ?? '' ) );
    $save_action = sanitize_key( (string) metis_unslash( $_POST['save_action'] ?? 'save' ) );

    $recon = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$recons_table} WHERE id = %d LIMIT 1", $recon_id ) );
    if ( $recon ) {
        $book_balance = round( (float) ( $recon->book_balance ?? 0 ), 2 );
        $variance = $statement_balance === null ? null : round( $statement_balance - $book_balance, 2 );
        $status = 'open';
        if ( $save_action === 'reopen' ) {
            $status = 'open';
        } elseif ( $statement_balance === null ) {
            $status = 'open';
        } elseif ( $save_action === 'force_review' ) {
            $status = 'review';
        } elseif ( abs( (float) $variance ) < 0.01 ) {
            $status = 'matched';
        } else {
            $status = 'review';
        }

        $wpdb->update(
            $recons_table,
            [
                'statement_balance' => $statement_balance,
                'variance'          => $variance,
                'notes'             => $notes,
                'status'            => $status,
                'updated_at'        => current_time( 'mysql' ),
            ],
            [ 'id' => $recon_id ],
            [ '%f', '%f', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        $message = 'Account check updated.';
    } else {
        $message = 'Reconciliation record was not found.';
        $message_type = 'error';
    }

    metis_finance_sync_reconciliations();
}

$selected_recon_id = isset( $_GET['recon'] ) ? (int) $_GET['recon'] : 0;
$recons = $wpdb->get_results(
    "SELECT r.*, a.label AS account_label
     FROM {$recons_table} r
     LEFT JOIN {$accounts_table} a ON a.account_key = r.account_key
     ORDER BY r.period_start DESC, r.id DESC
     LIMIT 24"
) ?: [];

if ( $selected_recon_id <= 0 && ! empty( $recons ) ) {
    $selected_recon_id = (int) $recons[0]->id;
}

$selected_recon = null;
foreach ( $recons as $recon_row ) {
    if ( (int) $recon_row->id === $selected_recon_id ) {
        $selected_recon = $recon_row;
        break;
    }
}

$detail_entries = [];
$now_ts = current_time( 'timestamp' );
if ( $selected_recon ) {
    $detail_entries = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, a.label AS account_label
         FROM {$ledger_table} l
         LEFT JOIN {$accounts_table} a ON a.account_key = l.account_key
         WHERE l.account_key = %s
           AND l.entry_date >= %s
           AND l.entry_date <= %s
         ORDER BY l.entry_date DESC, l.id DESC",
        (string) $selected_recon->account_key,
        (string) $selected_recon->period_start,
        (string) $selected_recon->period_end
    ) ) ?: [];
}

$matched_count = 0;
$review_count = 0;
foreach ( $recons as $recon_row ) {
    if ( (string) $recon_row->status === 'matched' ) {
        $matched_count++;
    }
    if ( (string) $recon_row->status === 'review' ) {
        $review_count++;
    }
}
?>

<h1 class="mw-page-title">Reconcile Accounts</h1>
<p class="mw-subtitle">Compare Metis balances to your bank or Stripe statements and flag anything that needs follow-up.</p>

<?php if ( $message !== '' ) : ?>
    <div class="mw-alert <?php echo $message_type === 'error' ? 'mw-alert-error' : 'mw-alert-success'; ?>"><?php echo esc_html( $message ); ?></div>
<?php endif; ?>

<div class="metis-finance-stats">
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Periods</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( number_format_i18n( count( $recons ) ) ); ?></div>
        <div class="metis-finance-stat-note">Recent account check periods on file.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Matched</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( number_format_i18n( $matched_count ) ); ?></div>
        <div class="metis-finance-stat-note">Periods with zero variance.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Needs Follow-Up</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( number_format_i18n( $review_count ) ); ?></div>
        <div class="metis-finance-stat-note">Statement and book do not currently agree.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Selected Period</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( $selected_recon ? metis_date( 'M Y', strtotime( (string) $selected_recon->period_start ) ) : '—' ); ?></div>
        <div class="metis-finance-stat-note"><?php echo esc_html( $selected_recon ? (string) ( $selected_recon->account_label ?? $selected_recon->account_key ) : 'Select a period below.' ); ?></div>
    </div>
</div>

<div class="metis-finance-grid">
    <section class="metis-finance-card">
        <div class="metis-finance-card-header">
            <h2>Periods To Review</h2>
            <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'reconciliations' ) ); ?>">Latest</a>
        </div>
        <div class="metis-finance-card-body">
            <div class="metis-finance-list">
                <?php if ( ! empty( $recons ) ) : ?>
                    <?php foreach ( $recons as $recon ) : ?>
                        <div class="metis-finance-list-row <?php echo (int) $recon->id === $selected_recon_id ? 'metis-finance-list-row-selected' : ''; ?>">
                            <div>
                                <div class="metis-finance-list-title"><a href="<?php echo esc_url( add_query_arg( [ 'recon' => (int) $recon->id ], metis_portal_url( 'finance', 'reconciliations' ) ) ); ?>"><?php echo esc_html( (string) ( $recon->account_label ?? $recon->account_key ) ); ?></a></div>
                                <div class="metis-finance-list-meta"><?php echo esc_html( metis_date( 'M j', strtotime( (string) $recon->period_start ) ) . ' - ' . metis_date( 'M j, Y', strtotime( (string) $recon->period_end ) ) ); ?> · Book <?php echo esc_html( metis_finance_currency( (float) $recon->book_balance ) ); ?></div>
                            </div>
                            <div class="metis-finance-list-amount"><?php echo metis_finance_view_badge( (string) $recon->status ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-finance-empty">No reconciliation periods exist yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="metis-finance-card">
        <div class="metis-finance-card-header">
            <h2>Review Workspace</h2>
            <span class="mw-muted"><?php echo esc_html( $selected_recon ? (string) ( $selected_recon->account_label ?? $selected_recon->account_key ) : 'Choose a period' ); ?></span>
        </div>
        <div class="metis-finance-card-body">
            <?php if ( $selected_recon ) : ?>
                <div class="metis-finance-split">
                    <div class="metis-finance-kpi">
                        <div class="metis-finance-kpi-label">Book Balance</div>
                        <div class="metis-finance-kpi-value"><?php echo esc_html( metis_finance_currency( (float) $selected_recon->book_balance ) ); ?></div>
                        <div class="metis-finance-kpi-sub"><?php echo esc_html( (string) ( $selected_recon->matched_count ?? 0 ) ); ?> posted ledger rows in this period.</div>
                    </div>
                    <div class="metis-finance-kpi">
                        <div class="metis-finance-kpi-label">Variance</div>
                        <div class="metis-finance-kpi-value"><?php echo esc_html( $selected_recon->variance !== null ? metis_finance_currency( (float) $selected_recon->variance ) : '—' ); ?></div>
                        <div class="metis-finance-kpi-sub">Statement <?php echo esc_html( $selected_recon->statement_balance !== null ? metis_finance_currency( (float) $selected_recon->statement_balance ) : 'not entered' ); ?>.</div>
                    </div>
                </div>

                <?php if ( $can_manage ) : ?>
                    <form method="post" class="metis-finance-recon-form">
                        <?php metis_nonce_field( 'metis_finance_recon_save', 'metis_finance_recon_nonce' ); ?>
                        <input type="hidden" name="recon_id" value="<?php echo esc_attr( (string) $selected_recon->id ); ?>">
                        <div class="metis-finance-filter-grid">
                            <label class="mw-field">
                                <span>Statement Balance</span>
                                <input type="number" step="0.01" name="statement_balance" class="mw-input" value="<?php echo esc_attr( $selected_recon->statement_balance !== null ? number_format( (float) $selected_recon->statement_balance, 2, '.', '' ) : '' ); ?>" placeholder="0.00">
                            </label>
                            <label class="mw-field metis-finance-filter-search">
                                <span>Notes</span>
                                <textarea name="notes" class="mw-input" rows="4" placeholder="Statement notes, timing items, or follow-up questions"><?php echo esc_textarea( (string) ( $selected_recon->notes ?? '' ) ); ?></textarea>
                            </label>
                            <div class="metis-finance-actions">
                                <button type="submit" name="save_action" value="save" class="mw-btn mw-btn-xs">Save Check</button>
                                <button type="submit" name="save_action" value="force_review" class="mw-btn mw-btn-xs mw-btn-ghost">Needs Review</button>
                                <button type="submit" name="save_action" value="reopen" class="mw-btn mw-btn-xs mw-btn-ghost">Reopen</button>
                            </div>
                        </div>
                    </form>
                <?php else : ?>
                    <div class="metis-finance-empty">You can view details here, but only editors can update statement balances and notes.</div>
                <?php endif; ?>
            <?php else : ?>
                <div class="metis-finance-empty">Select a reconciliation period to begin.</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="metis-finance-card">
    <div class="metis-finance-card-header">
        <h2>Activity In This Period</h2>
        <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'ledger' ) ); ?>">Open activity</a>
    </div>
    <div class="metis-finance-card-body">
        <div class="mw-premium-table">
            <div class="mw-premium-row mw-premium-header">
                <div class="mw-premium-cell">Date</div>
                <div class="mw-premium-cell">Account</div>
                <div class="mw-premium-cell">Source</div>
                <div class="mw-premium-cell">Memo</div>
                <div class="mw-premium-cell mw-col-numeric">Amount</div>
            </div>
            <?php if ( ! empty( $detail_entries ) ) : ?>
                <?php foreach ( $detail_entries as $entry ) : ?>
                    <?php $signed_amount = metis_finance_signed_amount( (string) $entry->direction, (float) $entry->amount ); ?>
                    <div class="mw-premium-row">
                        <div class="mw-premium-cell"><?php echo esc_html( metis_finance_short_date( (string) $entry->entry_date ) ); ?></div>
                        <div class="mw-premium-cell"><?php echo esc_html( (string) ( $entry->account_label ?? $entry->account_key ) ); ?></div>
                        <div class="mw-premium-cell"><code><?php echo esc_html( strtoupper( (string) $entry->source_type ) ); ?></code><div class="mw-muted"><?php echo esc_html( (string) $entry->source_ref ); ?></div></div>
                        <div class="mw-premium-cell"><?php echo esc_html( (string) ( $entry->memo ?? '—' ) ); ?></div>
                        <div class="mw-premium-cell mw-col-numeric <?php echo $signed_amount < 0 ? 'metis-finance-negative' : ''; ?>"><?php echo esc_html( ( $signed_amount < 0 ? '-$' : '$' ) . number_format_i18n( abs( $signed_amount ), 2 ) ); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No entries were found for the selected period.</div></div>
            <?php endif; ?>
        </div>
    </div>
</section>
