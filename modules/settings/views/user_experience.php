<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'user_experience' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Configure profile rules and accessibility defaults.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'user_experience' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="user_experience">
    <?php metis_runtime_nonce_field( 'metis_save_settings_user_experience', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Profile Rules</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label class="metis-people-check">
                    <input type="checkbox" name="profile_allow_name_edit" value="1" <?php metis_attr_checked( $profile_allow_name_edit ); ?>>
                    Allow users to edit first/last/display name in Profile
                </label>
                <p class="mw-help">If disabled, self-service name fields remain read-only.</p>
            </div>
        </div>
    </div>

    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Accessibility Defaults</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label class="metis-settings-check">
                    <input type="checkbox" name="accessibility_toolbar_enabled" value="1" <?php metis_attr_checked( $accessibility_toolbar_enabled ); ?>>
                    Show accessibility panel in the portal shell
                </label>
            </div>
            <div class="mw-field">
                <label class="metis-settings-check">
                    <input type="checkbox" name="accessibility_allow_overrides" value="1" <?php metis_attr_checked( $accessibility_allow_overrides ); ?>>
                    Allow users to save personal accessibility preferences
                </label>
            </div>
            <div class="mw-field">
                <label for="accessibility_default_profile">Default accessibility profile</label>
                <select id="accessibility_default_profile" name="accessibility_default_profile" class="mw-input mw-input-wide">
                    <?php foreach ( $accessibility_profiles as $slug => $profile ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $slug ); ?>" <?php metis_attr_selected( $accessibility_default_profile, (string) $slug ); ?>>
                            <?php echo metis_escape_html( (string) ( $profile['label'] ?? strtoupper( (string) $slug ) ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save User Experience Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
