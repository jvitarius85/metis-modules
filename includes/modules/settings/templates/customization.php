<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'customization' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Customize the shared Metis color system used by the portal.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'customization' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_customization', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Customization</h2></div>
        <div class="mw-settings-body">
            <p class="mw-help">These colors map directly to the shared CSS variables in <code>assets/core.css</code>.</p>
            <div class="metis-theme-grid">
                <?php foreach ( $theme_color_fields as $key => $field ) : ?>
                    <div class="mw-field metis-theme-field">
                        <label for="theme_colors_<?php echo esc_attr( $key ); ?>_text"><?php echo esc_html( (string) $field['label'] ); ?></label>
                        <div class="metis-theme-input-row">
                            <input type="color" id="theme_colors_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $theme_colors[ $key ] ); ?>" class="metis-theme-swatch" data-theme-color-input="theme_colors_<?php echo esc_attr( $key ); ?>_text">
                            <input type="text" id="theme_colors_<?php echo esc_attr( $key ); ?>_text" name="theme_colors[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $theme_colors[ $key ] ); ?>" class="mw-input metis-theme-text" pattern="^#[A-Fa-f0-9]{6}$" inputmode="text" data-theme-color-text>
                        </div>
                        <p class="mw-help">Default: <code><?php echo esc_html( (string) $field['default'] ); ?></code> for <code><?php echo esc_html( (string) $field['css_var'] ); ?></code>.</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Customization</button>
    </div>
</form>
