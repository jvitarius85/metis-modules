<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'profile' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Manage profile editing rules for portal users.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'profile' ); ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="profile">
    <?php metis_runtime_nonce_field( 'metis_save_settings_profile', 'metis_settings_nonce' ); ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Profile Policies</h2></div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label class="metis-people-check">
                    <input type="checkbox" name="profile_allow_name_edit" value="1" <?php metis_attr_checked( $profile_allow_name_edit ); ?>>
                    Allow users to edit first/last/display name in Profile
                </label>
                <p class="metis-help">If disabled, name fields in self-service Profile are read-only.</p>
            </div>
        </div>
    </div>
    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Profile Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
