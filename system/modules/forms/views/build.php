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
$existing = $form_id > 0 ? \Metis\Modules\Forms\Repository::getFormById( $form_id, false ) : null;
if ( $form_id > 0 && ! is_array( $existing ) ) {
    echo '<div class="metis-alert metis-alert-error">That form could not be found.</div>';
    return;
}

$default_step = isset( $_GET['step'] ) ? metis_key_clean( (string) $_GET['step'] ) : 'build';
$normalized_step = match ( $default_step ) {
    'settings', 'notifications', 'logic', 'payments' => 'settings',
    'publish', 'review' => 'publish',
    default => 'build',
};

$form = is_array( $existing ) ? $existing : \Metis\Modules\Forms\Repository::blankForm();
$boot = [
    'mode' => 'builder',
    'default_step' => $normalized_step,
    'form' => $form,
    'options' => \Metis\Modules\Forms\Repository::adminOptions(),
    'can_manage' => metis_forms_can_manage(),
    'can_delete' => metis_forms_can_delete(),
    'can_publish' => metis_forms_can_publish(),
    'urls' => [
        'home' => metis_forms_base_url(),
        'detail' => ! empty( $form['id'] ) ? metis_forms_detail_url( (int) $form['id'] ) : '',
        'entries' => ! empty( $form['id'] ) ? metis_forms_entries_url( (int) $form['id'] ) : '',
        'settings' => ! empty( $form['id'] ) ? metis_forms_settings_url( (int) $form['id'] ) : '',
        'public' => (string) ( $form['public_url'] ?? '' ),
    ],
];

if ( ! empty( $form['id'] ) ) {
    metis_set_page_title( 'Builder' );
}
?>
<div class="metis-forms-page">
    <div
        class="metis-forms-builder-shell"
        data-metis-forms-builder="1"
        data-can-manage="<?php echo metis_escape_attr( metis_forms_can_manage() ? '1' : '0' ); ?>"
        data-can-delete="<?php echo metis_escape_attr( metis_forms_can_delete() ? '1' : '0' ); ?>"
        data-can-publish="<?php echo metis_escape_attr( metis_forms_can_publish() ? '1' : '0' ); ?>"
    ></div>
    <script id="metis-forms-admin-data" type="application/json"><?php echo metis_json_encode( $boot ); ?></script>
</div>
