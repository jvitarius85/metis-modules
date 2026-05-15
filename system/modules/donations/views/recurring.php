<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Modules\Donations\RecurringDonationsService;

RecurringDonationsService::ensureSchema();
$plans = RecurringDonationsService::listPlans();
$active = count( array_filter( $plans, static fn ( array $row ): bool => (string) ( $row['status'] ?? '' ) === 'active' ) );
$paused = count( array_filter( $plans, static fn ( array $row ): bool => (string) ( $row['status'] ?? '' ) === 'paused' ) );
$monthly_total = array_sum( array_map( static function ( array $row ): float {
    if ( (string) ( $row['status'] ?? '' ) !== 'active' ) {
        return 0.0;
    }
    $amount = (float) ( $row['amount'] ?? 0 );
    return match ( (string) ( $row['frequency'] ?? 'monthly' ) ) {
        'quarterly' => $amount / 3,
        'semiannual' => $amount / 6,
        'annual' => $amount / 12,
        default => $amount,
    };
}, $plans ) );
?>

<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Recurring Donations</h1>
        <p class="metis-subtitle">Metis-managed schedules for off-session Stripe donations. Processing is limited to 1:00 AM - 5:00 AM America/Chicago.</p>
    </div>
    <div class="metis-page-header-right">
        <button type="button" class="metis-btn metis-btn-ghost" id="metis-recurring-migrate-stripe">Migrate Stripe Subscriptions</button>
        <button type="button" class="metis-btn metis-btn-secondary" id="metis-recurring-process-now">Process Due Now</button>
    </div>
</div>

<div class="metis-detail-panel" id="metis-recurring-migration-panel" hidden>
    <h2 class="metis-section-title">Stripe Subscription Migration</h2>
    <p class="metis-muted">This imports active Stripe subscriptions into Metis-managed recurring donations, then cancels successfully imported Stripe subscriptions. Cancelling a Stripe subscription does not remove the Stripe customer or saved payment method.</p>
    <label class="metis-inline-toggle">
        <input type="checkbox" id="metis-recurring-cancel-stripe" checked>
        Cancel Stripe subscriptions after successful import
    </label>
    <div class="metis-form-actions">
        <button type="button" class="metis-btn metis-btn-primary" id="metis-recurring-run-migration">Run Migration</button>
        <button type="button" class="metis-btn metis-btn-ghost" id="metis-recurring-close-migration">Close</button>
    </div>
    <div class="metis-muted" id="metis-recurring-migration-status"></div>
    <div id="metis-recurring-migration-results"></div>
</div>

<div class="metis-summary-grid metis-recurring-summary-grid">
    <div class="metis-premium-cell"><div class="metis-muted small-label">Active</div><div class="large-number"><?php echo metis_escape_html( (string) $active ); ?></div></div>
    <div class="metis-premium-cell"><div class="metis-muted small-label">Paused</div><div class="large-number"><?php echo metis_escape_html( (string) $paused ); ?></div></div>
    <div class="metis-premium-cell"><div class="metis-muted small-label">Monthly Forecast</div><div class="large-number">$<?php echo metis_escape_html( number_format( $monthly_total, 2 ) ); ?></div></div>
</div>

<div class="metis-table-wrap">
    <table class="metis-premium-table metis-recurring-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Donor</th>
                <th class="metis-premium-cell" scope="col">Campaign</th>
                <th class="metis-premium-cell metis-col-numeric" scope="col">Amount</th>
                <th class="metis-premium-cell" scope="col">Frequency</th>
                <th class="metis-premium-cell" scope="col">Next Run</th>
                <th class="metis-premium-cell" scope="col">Status</th>
                <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( $plans === [] ) : ?>
                <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="7">No recurring donations have been created yet.</td></tr>
            <?php else : ?>
                <?php foreach ( $plans as $plan ) : ?>
                    <tr class="metis-premium-row" data-recurring-row="<?php echo metis_escape_attr( (string) $plan['id'] ); ?>">
                        <td class="metis-premium-cell">
                            <strong><?php echo metis_escape_html( (string) ( $plan['donor_name'] ?: $plan['donor_email'] ) ); ?></strong>
                            <div class="metis-muted"><?php echo metis_escape_html( (string) $plan['donor_email'] ); ?></div>
                        </td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $plan['campaign_name'] ?: $plan['campaign_code'] ) ); ?></td>
                        <td class="metis-premium-cell metis-col-numeric">$<?php echo metis_escape_html( number_format( (float) $plan['amount'], 2 ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( str_replace( '_', ' ', (string) $plan['frequency'] ) ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( (string) $plan['next_run_at'] ); ?></td>
                        <td class="metis-premium-cell"><span class="metis-status metis-status-<?php echo metis_escape_attr( (string) $plan['status'] ); ?>"><?php echo metis_escape_html( ucfirst( (string) $plan['status'] ) ); ?></span></td>
                        <td class="metis-premium-cell metis-col-right">
                            <div class="metis-table-actions">
                                <?php if ( (string) $plan['status'] === 'active' ) : ?>
                                    <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-recurring-status="paused" data-id="<?php echo metis_escape_attr( (string) $plan['id'] ); ?>">Pause</button>
                                <?php else : ?>
                                    <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-recurring-status="active" data-id="<?php echo metis_escape_attr( (string) $plan['id'] ); ?>">Resume</button>
                                <?php endif; ?>
                                <button type="button" class="metis-btn metis-btn-xs metis-btn-danger" data-recurring-status="cancelled" data-id="<?php echo metis_escape_attr( (string) $plan['id'] ); ?>">Cancel</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function() {
    'use strict';
    function body(action, fields) {
        var form = new URLSearchParams(Object.assign({ action: action }, fields || {}));
        var fallback = window.metisAjax && metisAjax.nonce ? metisAjax.nonce : '';
        form.set('metis_action_nonce', window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function' ? Metis.ajax.nonceFor(action, fallback) : fallback);
        return form;
    }
    function post(action, fields) {
        return fetch(metisAjax.ajax_url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body(action, fields) }).then(function(r) { return r.json(); });
    }
    function toast(message, type) {
        if (typeof window.metis_toast === 'function') window.metis_toast(message, type || 'info');
    }
    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }
    function renderMigrationRows(rows, errors) {
        var target = document.getElementById('metis-recurring-migration-results');
        rows = Array.isArray(rows) ? rows : [];
        errors = Array.isArray(errors) ? errors : [];
        if (!rows.length && !errors.length) {
            target.innerHTML = '';
            return;
        }
        var html = '<table class="metis-premium-table"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell">Subscription</th><th class="metis-premium-cell">Result</th><th class="metis-premium-cell">Notes</th></tr></thead><tbody>';
        rows.forEach(function(row) {
            html += '<tr class="metis-premium-row"><td class="metis-premium-cell">' + esc(row.subscription) + '</td><td class="metis-premium-cell">' + esc(row.status) + '</td><td class="metis-premium-cell">' + esc(row.message) + '</td></tr>';
        });
        errors.forEach(function(error) {
            html += '<tr class="metis-premium-row"><td class="metis-premium-cell">-</td><td class="metis-premium-cell">error</td><td class="metis-premium-cell">' + esc(error) + '</td></tr>';
        });
        html += '</tbody></table>';
        target.innerHTML = html;
    }
    document.addEventListener('click', function(event) {
        var statusBtn = event.target.closest('[data-recurring-status]');
        if (statusBtn) {
            post('metis_recurring_donation_status', { id: statusBtn.dataset.id, status: statusBtn.dataset.recurringStatus }).then(function(result) {
                if (!result || !result.success) {
                    toast((result && result.data && result.data.message) || 'Update failed.', 'error');
                    return;
                }
                window.location.reload();
            });
            return;
        }
        if (event.target.closest('#metis-recurring-process-now')) {
            post('metis_recurring_donations_process_now', {}).then(function(result) {
                if (!result || !result.success) {
                    toast((result && result.data && result.data.message) || 'Processing failed.', 'error');
                    return;
                }
                toast((result.data && result.data.message) || 'Processing complete.', 'success');
            });
            return;
        }
        if (event.target.closest('#metis-recurring-migrate-stripe')) {
            document.getElementById('metis-recurring-migration-panel').hidden = false;
            return;
        }
        if (event.target.closest('#metis-recurring-close-migration')) {
            document.getElementById('metis-recurring-migration-panel').hidden = true;
            return;
        }
        if (event.target.closest('#metis-recurring-run-migration')) {
            var cancelStripe = document.getElementById('metis-recurring-cancel-stripe').checked;
            var status = document.getElementById('metis-recurring-migration-status');
            status.textContent = 'Migrating subscriptions...';
            post('metis_recurring_migrate_stripe_subscriptions', { cancel_stripe_subscriptions: cancelStripe ? '1' : '' }).then(function(result) {
                if (!result || !result.success) {
                    status.textContent = (result && result.data && result.data.message) || 'Migration failed.';
                    toast(status.textContent, 'error');
                    return;
                }
                status.textContent = (result.data && result.data.message) || 'Migration complete.';
                renderMigrationRows(result.data && result.data.rows, result.data && result.data.errors);
                toast(status.textContent, 'success');
            });
        }
    });
})();
</script>
