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

$settings = (array) ( $form['settings'] ?? [] );
$access = (array) ( $settings['access'] ?? [] );
$schedule = (array) ( $settings['schedule'] ?? [] );
$notifications = (array) ( $settings['notifications'] ?? [] );
?>
<div class="metis-forms-page">
    <header class="metis-forms-hero">
        <div>
            <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_forms_detail_url( $form_id ) ); ?>">Back to Overview</a>
            <h1 class="metis-page-title"><?php echo metis_escape_html( (string) ( $form['name'] ?? '' ) ); ?> Settings</h1>
            <p class="metis-subtitle">This page summarizes the current settings. Edit them in the builder so the build, settings, and publish flow stay in one place.</p>
        </div>
        <div class="metis-forms-hero__actions">
            <a class="metis-btn" href="<?php echo metis_escape_url( metis_forms_build_url( $form_id ) . '?step=settings' ); ?>">Open settings in builder</a>
        </div>
    </header>

    <div class="metis-forms-two-column">
        <section class="metis-forms-card">
            <div class="metis-forms-card__head"><div><h2>Access</h2></div></div>
            <dl class="metis-forms-definition-list">
                <div><dt>Mode</dt><dd><?php echo metis_escape_html( ucfirst( str_replace( '_', ' ', (string) ( $access['mode'] ?? 'public' ) ) ) ); ?></dd></div>
                <div><dt>Denied message</dt><dd><?php echo metis_escape_html( (string) ( $access['denied_message'] ?? '' ) ); ?></dd></div>
            </dl>
        </section>

        <section class="metis-forms-card">
            <div class="metis-forms-card__head"><div><h2>Schedule</h2></div></div>
            <dl class="metis-forms-definition-list">
                <div><dt>Enabled</dt><dd><?php echo ! empty( $schedule['enabled'] ) ? 'Yes' : 'No'; ?></dd></div>
                <div><dt>Start</dt><dd><?php echo metis_escape_html( (string) ( $schedule['start_at'] ?? '—' ) ); ?></dd></div>
                <div><dt>End</dt><dd><?php echo metis_escape_html( (string) ( $schedule['end_at'] ?? '—' ) ); ?></dd></div>
                <div><dt>Closed message</dt><dd><?php echo metis_escape_html( (string) ( $schedule['closed_message'] ?? '' ) ); ?></dd></div>
            </dl>
        </section>

        <section class="metis-forms-card">
            <div class="metis-forms-card__head"><div><h2>Submitter confirmation</h2></div></div>
            <dl class="metis-forms-definition-list">
                <div><dt>Enabled</dt><dd><?php echo ! empty( $notifications['submitter']['enabled'] ) ? 'Yes' : 'No'; ?></dd></div>
                <div><dt>Email field</dt><dd><?php echo metis_escape_html( (string) ( $notifications['submitter']['recipient_field'] ?? '—' ) ); ?></dd></div>
                <div><dt>From name</dt><dd><?php echo metis_escape_html( (string) ( $notifications['submitter']['from_name'] ?? '—' ) ); ?></dd></div>
                <div><dt>From email</dt><dd><?php echo metis_escape_html( (string) ( $notifications['submitter']['from_email'] ?? '—' ) ); ?></dd></div>
                <div><dt>Include submitted info</dt><dd><?php echo ! empty( $notifications['submitter']['include_submission_data'] ) ? 'Yes' : 'No'; ?></dd></div>
                <div><dt>Subject</dt><dd><?php echo metis_escape_html( (string) ( $notifications['submitter']['subject'] ?? '' ) ); ?></dd></div>
            </dl>
        </section>

        <section class="metis-forms-card">
            <div class="metis-forms-card__head"><div><h2>Internal alerts</h2></div></div>
            <dl class="metis-forms-definition-list">
                <div><dt>Enabled</dt><dd><?php echo ! empty( $notifications['internal']['enabled'] ) ? 'Yes' : 'No'; ?></dd></div>
                <div><dt>General email</dt><dd><?php echo metis_escape_html( (string) ( $notifications['internal']['general_email'] ?? '—' ) ); ?></dd></div>
                <div><dt>From name</dt><dd><?php echo metis_escape_html( (string) ( $notifications['internal']['from_name'] ?? '—' ) ); ?></dd></div>
                <div><dt>From email</dt><dd><?php echo metis_escape_html( (string) ( $notifications['internal']['from_email'] ?? '—' ) ); ?></dd></div>
                <div><dt>Include submitted info</dt><dd><?php echo ! empty( $notifications['internal']['include_submission_data'] ) ? 'Yes' : 'No'; ?></dd></div>
                <div><dt>Routing field</dt><dd><?php echo metis_escape_html( (string) ( $notifications['internal']['routing_field'] ?? '—' ) ); ?></dd></div>
            </dl>
        </section>
    </div>
</div>
