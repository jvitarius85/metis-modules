<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! metis_forms_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view forms.</div>';
    return;
}

metis_forms_ensure_schema();

$forms = \Metis\Modules\Forms\Repository::listForms();
$published_count = 0;
$draft_count = 0;
$submission_total = 0;

foreach ( $forms as $form ) {
    $submission_total += (int) ( $form['submission_count'] ?? 0 );
    if ( ( $form['status'] ?? '' ) === 'published' ) {
        $published_count++;
    } else {
        $draft_count++;
    }
}
?>
<div class="metis-forms-page">
    <header class="metis-forms-hero">
        <div>
            <p class="metis-forms-kicker">Forms</p>
            <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Forms' ) ); ?></h1>
            <p class="metis-subtitle">Build forms, route follow-up, collect payments only when a form needs them, and keep the publishing flow predictable.</p>
        </div>
        <?php if ( metis_forms_can_manage() ) : ?>
            <a class="metis-btn" href="<?php echo metis_escape_url( metis_forms_build_url() ); ?>">Create form</a>
        <?php endif; ?>
    </header>

    <section class="metis-forms-kpi-grid" aria-label="Forms overview">
        <article class="metis-forms-kpi-card"><span>Total forms</span><strong><?php echo metis_escape_html( metis_number_format( count( $forms ) ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Published</span><strong><?php echo metis_escape_html( metis_number_format( $published_count ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Drafts</span><strong><?php echo metis_escape_html( metis_number_format( $draft_count ) ); ?></strong></article>
        <article class="metis-forms-kpi-card"><span>Total entries</span><strong><?php echo metis_escape_html( metis_number_format( $submission_total ) ); ?></strong></article>
    </section>

    <?php if ( empty( $forms ) ) : ?>
        <section class="metis-forms-empty-card">
            <h2>No forms yet</h2>
            <p>Start with a blank form and add payments only when you explicitly add a payment field.</p>
            <?php if ( metis_forms_can_manage() ) : ?>
                <a class="metis-btn" href="<?php echo metis_escape_url( metis_forms_build_url() ); ?>">Create your first form</a>
            <?php endif; ?>
        </section>
    <?php else : ?>
        <section class="metis-forms-list-grid">
            <?php foreach ( $forms as $form ) : ?>
                <article class="metis-forms-card">
                    <div class="metis-forms-card__head">
                        <div>
                            <h2><?php echo metis_escape_html( (string) ( $form['name'] ?? '' ) ); ?></h2>
                            <p><?php echo metis_escape_html( (string) ( $form['slug'] ?? '' ) ); ?></p>
                        </div>
                        <span class="metis-forms-status-chip is-<?php echo metis_escape_attr( \Metis\Modules\Forms\Support::cssClassToken( (string) ( $form['status'] ?? 'draft' ), 'draft' ) ); ?>">
                            <?php echo metis_escape_html( ucfirst( (string) ( $form['status'] ?? 'draft' ) ) ); ?>
                        </span>
                    </div>
                    <dl class="metis-forms-card__meta">
                        <div><dt>Module</dt><dd><?php echo metis_escape_html( (string) ( $form['module_label'] ?? 'Unassigned' ) ); ?></dd></div>
                        <div><dt>Type</dt><dd><?php echo ! empty( $form['payments_enabled'] ) ? 'Payment form' : 'Standard form'; ?></dd></div>
                        <div><dt>Entries</dt><dd><?php echo metis_escape_html( metis_number_format( (int) ( $form['submission_count'] ?? 0 ) ) ); ?></dd></div>
                    </dl>
                    <div class="metis-forms-card__actions">
                        <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_forms_detail_url( (int) $form['id'] ) ); ?>">Overview</a>
                        <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_forms_entries_url( (int) $form['id'] ) ); ?>">Entries</a>
                        <a class="metis-btn metis-btn-xs" href="<?php echo metis_escape_url( metis_forms_build_url( (int) $form['id'] ) ); ?>">Open builder</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>
