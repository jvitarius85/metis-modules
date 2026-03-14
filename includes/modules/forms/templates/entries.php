<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_forms_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view forms.</div>';
    return;
}

metis_forms_ensure_schema();

$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
$form = $form_id > 0 ? \Metis\Modules\Forms\Repository::getFormById( $form_id ) : null;

if ( ! $form ) {
    echo '<div class="mw-alert mw-alert-error">Form not found.</div>';
    return;
}

$entries = \Metis\Modules\Forms\Repository::listSubmissions( $form_id );
$stats = \Metis\Modules\Forms\Repository::summarizeSubmissions( $form_id );
$has_payment_field = false;
foreach ( (array) ( $form['schema'] ?? [] ) as $schema_field ) {
    if ( is_array( $schema_field ) && ( $schema_field['type'] ?? '' ) === 'payment' ) {
        $has_payment_field = true;
        break;
    }
}
metis_set_page_title( 'Entries' );
?>
<div class="metis-forms-entries-page" data-entries-view="1" data-form-id="<?php echo esc_attr( (string) $form_id ); ?>">
    <div class="metis-forms-detail-head">
        <div>
            <h1 class="mw-page-title"><?php echo esc_html( (string) $form['name'] ); ?> Entries</h1>
            <p class="mw-subtitle">Submission log, payment progress, and structured values collected by this form.</p>
        </div>
        <div class="metis-forms-detail-actions">
            <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_detail_url( $form_id ) ); ?>">Overview</a>
            <?php if ( metis_forms_can_manage() ) : ?>
                <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_build_url( $form_id ) ); ?>">Builder</a>
                <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_settings_url( $form_id ) ); ?>">Settings</a>
            <?php endif; ?>
            <button type="button" class="mw-btn mw-btn-xs" id="metis-forms-export">Export CSV</button>
        </div>
    </div>

    <div class="metis-forms-kpis">
        <article class="metis-forms-kpi"><span>Total entries</span><strong><?php echo esc_html( number_format_i18n( (int) $stats['submission_count'] ) ); ?></strong></article>
        <?php if ( $has_payment_field ) : ?>
            <article class="metis-forms-kpi"><span>Revenue</span><strong>$<?php echo esc_html( number_format_i18n( (float) $stats['revenue_total'], 2 ) ); ?></strong></article>
            <article class="metis-forms-kpi"><span>Pending payments</span><strong><?php echo esc_html( number_format_i18n( (int) $stats['payment_pending_count'] ) ); ?></strong></article>
        <?php endif; ?>
        <article class="metis-forms-kpi"><span>Last activity</span><strong><?php echo esc_html( (string) ( $stats['last_submission_at'] ?: 'n/a' ) ); ?></strong></article>
    </div>

    <div id="metis-forms-entries" class="metis-forms-entries-table">
        <?php foreach ( $entries as $entry ) : ?>
            <article class="metis-forms-entry-card">
                <div class="metis-forms-entry-card-head">
                    <strong><?php echo esc_html( (string) ( $entry['submission_key'] ?? '' ) ); ?></strong>
                    <?php if ( $has_payment_field ) : ?>
                        <span class="mw-chip"><?php echo esc_html( strtoupper( (string) ( $entry['payment_status'] ?? 'not_required' ) ) ); ?></span>
                    <?php endif; ?>
                </div>
                <small><?php echo esc_html( (string) ( $entry['created_at'] ?? '' ) ); ?> • <?php echo esc_html( (string) ( $entry['submitter_email'] ?? 'No email' ) ); ?><?php if ( $has_payment_field ) : ?> • $<?php echo esc_html( number_format_i18n( (float) ( $entry['amount_total'] ?? 0 ), 2 ) ); ?><?php endif; ?></small>
                <pre class="metis-forms-entry-json"><?php echo esc_html( metis_json_encode( (array) ( $entry['normalized'] ?? [] ) ) ?: '{}' ); ?></pre>
            </article>
        <?php endforeach; ?>
        <?php if ( empty( $entries ) ) : ?>
            <div class="metis-forms-empty-state">
                <h2>No entries yet</h2>
                <p>Publish the form to start collecting submissions.</p>
            </div>
        <?php endif; ?>
    </div>

    <script id="metis-forms-admin-data" type="application/json"><?php
        echo metis_json_encode( [
            'mode' => 'entries',
            'selected' => $form,
        ] );
    ?></script>
</div>
