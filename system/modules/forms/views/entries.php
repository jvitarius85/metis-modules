<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! metis_forms_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view forms.</div>';
    return;
}

metis_forms_ensure_schema();

$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
$form = $form_id > 0 ? \Metis\Modules\Forms\Repository::getFormById( $form_id, false ) : null;
if ( ! is_array( $form ) ) {
    echo '<div class="metis-alert metis-alert-error">That form could not be found.</div>';
    return;
}

$entries = \Metis\Modules\Forms\Repository::listSubmissions( $form_id );
$summary = \Metis\Modules\Forms\Repository::summarizeSubmissions( $form_id );
$boot = [
    'mode' => 'entries',
    'form_id' => $form_id,
    'form_name' => (string) ( $form['name'] ?? '' ),
];
?>
<div class="metis-forms-page" data-metis-forms-entries="1">
    <header class="metis-forms-hero">
        <div>
            <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_forms_detail_url( $form_id ) ); ?>">Back to Overview</a>
            <h1 class="metis-page-title"><?php echo metis_escape_html( (string) ( $form['name'] ?? '' ) ); ?> Entries</h1>
            <p class="metis-subtitle">Review submissions, export the current list, and keep light-weight entry management in one place.</p>
        </div>
        <div class="metis-forms-hero__actions">
            <button type="button" class="metis-btn" data-forms-export>Export CSV</button>
        </div>
    </header>

    <section class="metis-forms-kpi-grid" aria-label="Entries summary">
        <article class="metis-forms-kpi-card"><span>Entries</span><strong><?php echo metis_escape_html( metis_number_format( (int) ( $summary['submission_count'] ?? 0 ) ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Revenue</span><strong>$<?php echo metis_escape_html( number_format( (float) ( $summary['revenue_total'] ?? 0 ), 2 ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Pending payments</span><strong><?php echo metis_escape_html( metis_number_format( (int) ( $summary['payment_pending_count'] ?? 0 ) ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Last entry</span><strong class="metis-forms-kpi-link"><?php echo metis_escape_html( (string) ( $summary['last_submission_at'] ?? '—' ) ); ?></strong></article>
    </section>

    <section class="metis-forms-card metis-forms-table-card">
        <div class="metis-forms-card__head">
            <div><h2>Submission list</h2></div>
        </div>

        <?php if ( empty( $entries ) ) : ?>
            <p class="metis-muted">No entries have been submitted yet.</p>
        <?php else : ?>
            <div class="metis-forms-table-wrap">
                <table class="metis-forms-table">
                    <thead>
                        <tr>
                            <th scope="col">Submitted</th>
                            <th scope="col">Email</th>
                            <th scope="col">Payment</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $entries as $entry ) : ?>
                            <tr>
                                <td><?php echo metis_escape_html( (string) ( $entry['created_at'] ?? '' ) ); ?></td>
                                <td><?php echo metis_escape_html( (string) ( $entry['submitter_email'] ?? '—' ) ); ?></td>
                                <td><?php echo metis_escape_html( (string) ( $entry['payment_status'] ?? 'not_required' ) ); ?></td>
                                <td><?php echo metis_escape_html( '$' . number_format( (float) ( $entry['amount_total'] ?? 0 ), 2 ) ); ?></td>
                                <td><?php echo metis_escape_html( (string) ( $entry['submission_key'] ?? '' ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <script id="metis-forms-admin-data" type="application/json"><?php echo metis_json_encode( $boot ); ?></script>
</div>
