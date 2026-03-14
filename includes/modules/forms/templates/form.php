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

$stats = \Metis\Modules\Forms\Repository::summarizeSubmissions( (int) $form['id'] );
$has_payment_field = false;
foreach ( (array) ( $form['schema'] ?? [] ) as $schema_field ) {
    if ( is_array( $schema_field ) && ( $schema_field['type'] ?? '' ) === 'payment' ) {
        $has_payment_field = true;
        break;
    }
}
metis_set_page_title( (string) $form['name'] );
?>
<div class="metis-forms-detail">
    <div class="metis-forms-detail-head">
        <div>
            <h1 class="mw-page-title"><?php echo esc_html( (string) $form['name'] ); ?></h1>
            <p class="mw-subtitle"><?php echo esc_html( (string) ( $form['description'] ?: 'No description yet.' ) ); ?></p>
        </div>
        <div class="metis-forms-detail-actions">
            <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( (string) $form['public_url'] ); ?>" target="_blank" rel="noopener">Open public form</a>
            <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_entries_url( (int) $form['id'] ) ); ?>">Entries</a>
            <?php if ( metis_forms_can_manage() ) : ?>
                <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_forms_settings_url( (int) $form['id'] ) ); ?>">Settings</a>
                <a class="mw-btn mw-btn-xs" href="<?php echo esc_url( metis_forms_build_url( (int) $form['id'] ) ); ?>">Open builder</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="metis-forms-kpis">
        <article class="metis-forms-kpi"><span>Status</span><strong><?php echo esc_html( strtoupper( (string) ( $form['status'] ?? 'draft' ) ) ); ?></strong></article>
        <article class="metis-forms-kpi"><span>Entries</span><strong><?php echo esc_html( number_format_i18n( (int) $stats['submission_count'] ) ); ?></strong></article>
        <?php if ( $has_payment_field ) : ?>
            <article class="metis-forms-kpi"><span>Revenue</span><strong>$<?php echo esc_html( number_format_i18n( (float) $stats['revenue_total'], 2 ) ); ?></strong></article>
            <article class="metis-forms-kpi"><span>Pending payments</span><strong><?php echo esc_html( number_format_i18n( (int) $stats['payment_pending_count'] ) ); ?></strong></article>
        <?php endif; ?>
    </div>

    <div class="metis-forms-detail-grid">
        <section class="metis-forms-detail-card">
            <h2>Definition</h2>
            <dl class="metis-forms-definition-list">
                <div><dt>Slug</dt><dd><?php echo esc_html( (string) $form['slug'] ); ?></dd></div>
                <div><dt>Latest version</dt><dd>v<?php echo esc_html( number_format_i18n( count( (array) ( $form['versions'] ?? [] ) ) ) ); ?></dd></div>
                <div><dt>Payments</dt><dd><?php echo $has_payment_field ? 'Stripe payment field added' : 'No payment field'; ?></dd></div>
                <div><dt>Notifications</dt><dd><?php echo esc_html( implode( ', ', (array) ( $form['settings']['notifications']['receiver']['emails'] ?? [] ) ) ?: 'None configured' ); ?></dd></div>
            </dl>
        </section>

        <section class="metis-forms-detail-card">
            <h2>Settings</h2>
            <p>Notifications, confirmations, Stripe totals, processing fees, and public styling are managed on the dedicated settings page.</p>
            <?php if ( metis_forms_can_manage() ) : ?>
                <a class="mw-btn mw-btn-xs" href="<?php echo esc_url( metis_forms_settings_url( (int) $form['id'] ) ); ?>">Open settings</a>
            <?php endif; ?>
        </section>

        <section class="metis-forms-detail-card">
            <h2>Field map</h2>
            <div class="metis-forms-field-outline">
                <?php foreach ( (array) ( $form['schema'] ?? [] ) as $field ) : ?>
                    <?php if ( ! is_array( $field ) ) continue; ?>
                    <article class="metis-forms-field-outline-item">
                        <strong><?php echo esc_html( (string) ( $field['label'] ?? $field['key'] ?? '' ) ); ?></strong>
                        <span><?php echo esc_html( strtoupper( (string) ( $field['type'] ?? 'text' ) ) ); ?></span>
                        <small><?php echo esc_html( (string) ( $field['key'] ?? '' ) ); ?><?php echo ! empty( $field['required'] ) ? ' • required' : ''; ?><?php echo ! empty( $field['pricing']['enabled'] ) ? ' • priced' : ''; ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="metis-forms-detail-card">
            <h2>Versions</h2>
            <div class="metis-forms-version-list">
                <?php foreach ( (array) ( $form['versions'] ?? [] ) as $version ) : ?>
                    <?php if ( ! is_array( $version ) ) continue; ?>
                    <div class="metis-forms-version-badge">
                        <strong>v<?php echo esc_html( number_format_i18n( (int) ( $version['version_number'] ?? 0 ) ) ); ?></strong>
                        <small><?php echo esc_html( (string) ( $version['created_at'] ?? '' ) ); ?><?php echo ! empty( $version['is_published'] ) ? ' • published' : ''; ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
