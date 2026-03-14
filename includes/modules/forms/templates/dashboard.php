<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_forms_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view forms.</div>';
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
    } elseif ( ( $form['status'] ?? '' ) === 'draft' ) {
        $draft_count++;
    }
}
?>
<div class="metis-forms-home">
    <div class="metis-forms-hero">
        <div>
            <h1 class="mw-page-title">Forms</h1>
            <p class="mw-subtitle">Create public workflows, collect structured entries, and manage payment-enabled submissions without leaving Metis.</p>
        </div>
        <?php if ( metis_forms_can_manage() ) : ?>
            <a class="mw-btn" href="<?php echo esc_url( metis_forms_build_url() ); ?>">New form</a>
        <?php endif; ?>
    </div>

    <div class="metis-forms-kpis">
        <article class="metis-forms-kpi"><span>Total forms</span><strong><?php echo esc_html( number_format_i18n( count( $forms ) ) ); ?></strong></article>
        <article class="metis-forms-kpi"><span>Published</span><strong><?php echo esc_html( number_format_i18n( $published_count ) ); ?></strong></article>
        <article class="metis-forms-kpi"><span>Drafts</span><strong><?php echo esc_html( number_format_i18n( $draft_count ) ); ?></strong></article>
        <article class="metis-forms-kpi"><span>Total entries</span><strong><?php echo esc_html( number_format_i18n( $submission_total ) ); ?></strong></article>
    </div>

    <div class="metis-forms-home-grid">
        <?php foreach ( $forms as $form ) : ?>
            <article class="metis-forms-home-card">
                <div class="metis-forms-home-card-head">
                    <div>
                        <h2><?php echo esc_html( (string) ( $form['name'] ?? '' ) ); ?></h2>
                        <p><?php echo esc_html( (string) ( $form['slug'] ?? '' ) ); ?></p>
                    </div>
                    <span class="mw-chip"><?php echo esc_html( strtoupper( (string) ( $form['status'] ?? 'draft' ) ) ); ?></span>
                </div>
                <div class="metis-forms-home-card-stats">
                    <span><?php echo esc_html( number_format_i18n( (int) ( $form['submission_count'] ?? 0 ) ) ); ?> entries</span>
                    <span>v<?php echo esc_html( number_format_i18n( (int) ( $form['latest_version_number'] ?? 0 ) ) ); ?></span>
                    <span><?php echo esc_html( ! empty( $form['last_submission_at'] ) ? (string) $form['last_submission_at'] : 'No submissions yet' ); ?></span>
                </div>
                <div class="metis-forms-home-card-actions">
                    <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_detail_url( (int) $form['id'] ) ); ?>">Overview</a>
                    <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_entries_url( (int) $form['id'] ) ); ?>">Entries</a>
                    <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_settings_url( (int) $form['id'] ) ); ?>">Settings</a>
                    <a class="mw-btn mw-btn-xs" href="<?php echo esc_url( metis_forms_build_url( (int) $form['id'] ) ); ?>">Build</a>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if ( empty( $forms ) ) : ?>
            <div class="metis-forms-empty-state">
                <h2>No forms yet</h2>
                <p>Start with a blank form, drag fields into the canvas, then publish a public URL when it is ready.</p>
                <?php if ( metis_forms_can_manage() ) : ?>
                    <a class="mw-btn" href="<?php echo esc_url( metis_forms_build_url() ); ?>">Create your first form</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
