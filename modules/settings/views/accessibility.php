<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'accessibility' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Make accessibility defaults part of the shared platform shell instead of a per-page add-on.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'accessibility' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="accessibility">
    <?php metis_runtime_nonce_field( 'metis_save_settings_accessibility', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Accessibility Defaults</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label class="metis-settings-check">
                    <input type="checkbox" name="accessibility_toolbar_enabled" value="1" <?php metis_attr_checked( $accessibility_toolbar_enabled ); ?>>
                    Show the accessibility panel in the shared portal shell
                </label>
                <p class="mw-help">When enabled, visitors can open a built-in panel for high contrast, large text, simplified typography, and screen-reader-friendly navigation labels.</p>
            </div>

            <div class="mw-field">
                <label class="metis-settings-check">
                    <input type="checkbox" name="accessibility_allow_overrides" value="1" <?php metis_attr_checked( $accessibility_allow_overrides ); ?>>
                    Allow visitors to save their own accessibility preferences
                </label>
                <p class="mw-help">Preferences persist in browser storage across pages so the shell stays consistent for returning visitors.</p>
            </div>

            <div class="mw-field">
                <label for="accessibility_default_profile">Default accessibility profile</label>
                <select id="accessibility_default_profile" name="accessibility_default_profile" class="mw-input mw-input-wide">
                    <?php foreach ( $accessibility_profiles as $slug => $profile ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) $slug ); ?>" <?php metis_attr_selected( $accessibility_default_profile, (string) $slug ); ?>>
                            <?php echo metis_escape_html( (string) ( $profile['label'] ?? ucfirst( str_replace( '-', ' ', (string) $slug ) ) ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mw-help">This profile applies before any visitor override. Use it to ship accessible defaults platform-wide.</p>
            </div>

            <div class="mw-callout">
                <strong>Platform baseline</strong>
                <p class="mw-help">Metis now exposes a skip link, stronger focus states, landmarked navigation, keyboard-safe menu flyouts, and persistent accessibility modes from the shared shell.</p>
            </div>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Accessibility Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
