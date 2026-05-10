<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! metis_forms_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view forms.</div>';
    return;
}

metis_forms_ensure_schema();

$form_id = isset( metis_request_get()['form_id'] ) ? (int) metis_request_get()['form_id'] : 0;
$form = $form_id > 0 ? \Metis\Modules\Forms\Repository::getFormById( $form_id, false ) : null;
if ( ! is_array( $form ) ) {
    echo '<div class="metis-alert metis-alert-error">That form could not be found.</div>';
    return;
}

$summary = \Metis\Modules\Forms\Repository::summarizeSubmissions( $form_id );
$versions = array_slice( (array) ( $form['versions'] ?? [] ), 0, 6 );
?>
<div class="metis-forms-page">
    <header class="metis-forms-hero">
        <div>
            <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_forms_base_url() ); ?>">Back to Forms</a>
            <h1 class="metis-page-title"><?php echo metis_escape_html( (string) ( $form['name'] ?? '' ) ); ?></h1>
            <p class="metis-subtitle"><?php echo metis_escape_html( (string) ( $form['description'] ?? '' ) ); ?></p>
        </div>
        <div class="metis-forms-hero__actions">
            <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_forms_entries_url( $form_id ) ); ?>">Entries</a>
            <a class="metis-btn" href="<?php echo metis_escape_url( metis_forms_build_url( $form_id ) ); ?>">Open builder</a>
        </div>
    </header>

    <section class="metis-forms-kpi-grid" aria-label="Form summary">
        <article class="metis-forms-kpi-card"><span>Status</span><strong><?php echo metis_escape_html( ucfirst( (string) ( $form['status'] ?? 'draft' ) ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Entries</span><strong><?php echo metis_escape_html( metis_number_format( (int) ( $summary['submission_count'] ?? 0 ) ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Revenue</span><strong>$<?php echo metis_escape_html( number_format( (float) ( $summary['revenue_total'] ?? 0 ), 2 ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Public URL</span><strong class="metis-forms-kpi-link"><?php echo metis_escape_html( (string) ( $form['public_url'] ?? '' ) ); ?></strong></article>
    </section>

    <div class="metis-forms-two-column">
        <section class="metis-forms-card">
            <div class="metis-forms-card__head">
                <div><h2>Form summary</h2></div>
            </div>
            <dl class="metis-forms-definition-list">
                <div><dt>Slug</dt><dd><?php echo metis_escape_html( (string) ( $form['slug'] ?? '' ) ); ?></dd></div>
                <div><dt>Module</dt><dd><?php echo metis_escape_html( (string) ( $form['module_label'] ?? 'Unassigned' ) ); ?></dd></div>
                <div><dt>Payments</dt><dd><?php echo ! empty( $form['payments_enabled'] ) ? 'Enabled' : 'Not enabled'; ?></dd></div>
                <div><dt>Last entry</dt><dd><?php echo metis_escape_html( (string) ( $summary['last_submission_at'] ?? '—' ) ); ?></dd></div>
            </dl>
        </section>

        <section class="metis-forms-card">
            <div class="metis-forms-card__head">
                <div><h2>Recent versions</h2></div>
            </div>
            <?php if ( empty( $versions ) ) : ?>
                <p class="metis-muted">No versions yet.</p>
            <?php else : ?>
                <ul class="metis-forms-version-list">
                    <?php foreach ( $versions as $version ) : ?>
                        <li>
                            <strong>v<?php echo metis_escape_html( (string) ( $version['version_number'] ?? 0 ) ); ?></strong>
                            <span><?php echo ! empty( $version['is_published'] ) ? 'Published' : 'Draft'; ?></span>
                            <time><?php echo metis_escape_html( (string) ( $version['created_at'] ?? '' ) ); ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
