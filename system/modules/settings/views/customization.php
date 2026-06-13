<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'branding' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
$login_background_trigger_color = $login_background_color_binding !== '' && isset( $theme_colors[ $login_background_color_binding ] )
    ? (string) $theme_colors[ $login_background_color_binding ]
    : $login_background_color;
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Customize the shared Metis color system used by the portal.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'branding' ); ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="branding">
    <?php metis_runtime_nonce_field( 'metis_save_settings_branding', 'metis_settings_nonce' ); ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Branding</h2></div>
        <div class="metis-settings-body">
            <p class="metis-help">These colors map directly to the shared CSS variables in <code>assets/core.css</code>.</p>
            <div class="metis-theme-grid">
                <?php foreach ( $theme_color_fields as $key => $field ) : ?>
                    <div class="metis-field metis-theme-field">
                        <label for="theme_colors_<?php echo metis_escape_attr( $key ); ?>_text"><?php echo metis_escape_html( (string) $field['label'] ); ?></label>
                        <div class="metis-theme-input-row">
                            <input type="color" id="theme_colors_<?php echo metis_escape_attr( $key ); ?>" value="<?php echo metis_escape_attr( (string) $theme_colors[ $key ] ); ?>" class="metis-theme-swatch" data-theme-color-input="theme_colors_<?php echo metis_escape_attr( $key ); ?>_text">
                            <input type="text" id="theme_colors_<?php echo metis_escape_attr( $key ); ?>_text" name="theme_colors[<?php echo metis_escape_attr( $key ); ?>]" value="<?php echo metis_escape_attr( (string) $theme_colors[ $key ] ); ?>" class="metis-input metis-theme-text" pattern="^#[A-Fa-f0-9]{6}$" inputmode="text" data-theme-color-text>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Login Screen</h2></div>
        <div class="metis-settings-body">
            <div class="metis-field">
                <label for="login_organization_name">Organization Name</label>
                <input id="login_organization_name" name="login_organization_name" class="metis-input" type="text" value="<?php echo metis_escape_attr( $login_organization_name ); ?>">
            </div>
            <div class="metis-field">
                <label for="login_welcome_text">Welcome Text</label>
                <textarea id="login_welcome_text" name="login_welcome_text" class="metis-input" rows="3"><?php echo metis_escape_html( $login_welcome_text ); ?></textarea>
            </div>
            <div class="metis-field">
                <label for="login_footer_text">Footer Text</label>
                <input id="login_footer_text" name="login_footer_text" class="metis-input" type="text" value="<?php echo metis_escape_attr( $login_footer_text ); ?>">
            </div>
            <div class="metis-field metis-theme-field">
                <label for="login_background_color_binding">Background Color</label>
                <div class="metis-theme-color-wrap">
                    <input id="login_background_color" name="login_background_color" class="metis-theme-color metis-is-hidden" type="color" value="<?php echo metis_escape_attr( $login_background_color ); ?>" data-settings-custom-color="login_background_color">
                    <button type="button" class="metis-theme-color-dot" data-settings-custom-color-dot="login_background_color" style="background:<?php echo metis_escape_attr( $login_background_trigger_color ); ?>"></button>
                    <select
                        id="login_background_color_binding"
                        name="login_background_color_binding"
                        class="metis-input metis-input-sm metis-settings-color-binding"
                        data-settings-custom-color-select="login_background_color"
                        data-metis-ui-select="1"
                        data-metis-select-trigger-class="metis-input metis-input-sm"
                        data-metis-select-variant="theme-binding"
                    >
                        <option value="" data-metis-select-color="<?php echo metis_escape_attr( $login_background_color ); ?>"<?php echo $login_background_color_binding === '' ? ' selected' : ''; ?>>Custom / fixed</option>
                        <?php foreach ( $theme_color_fields as $key => $field ) : ?>
                            <option
                                value="<?php echo metis_escape_attr( $key ); ?>"
                                data-metis-select-color="<?php echo metis_escape_attr( (string) ( $theme_colors[ $key ] ?? $field['default'] ?? '#ffffff' ) ); ?>"
                                <?php echo $login_background_color_binding === $key ? ' selected' : ''; ?>
                            >
                                <?php echo metis_escape_html( (string) ( $field['label'] ?? $key ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="metis-field" data-settings-media-field="login_logo">
                <label>Login Logo</label>
                <?php if ( $login_logo_src !== '' ) : ?>
                    <div class="metis-logo-preview-wrap" data-settings-media-preview-wrap="login_logo">
                        <img src="<?php echo metis_escape_attr( $login_logo_src ); ?>" alt="Login logo" class="metis-logo-preview" data-settings-media-preview="login_logo">
                        <div class="metis-logo-meta">
                            <strong data-settings-media-name="login_logo"><?php echo metis_escape_html( (string) ( $login_logo['filename'] ?? 'Login logo' ) ); ?></strong>
                            <span data-settings-media-mime="login_logo"><?php echo metis_escape_html( strtoupper( str_replace( 'image/', '', (string) ( $login_logo['mime_type'] ?? '' ) ) ) ); ?></span>
                        </div>
                    </div>
                    <label><input type="checkbox" name="remove_login_logo" value="1"> Remove current logo</label>
                <?php else : ?>
                    <div class="metis-logo-preview-wrap" data-settings-media-preview-wrap="login_logo" style="display:none;">
                        <img src="" alt="Selected login logo" class="metis-logo-preview" data-settings-media-preview="login_logo">
                        <div class="metis-logo-meta">
                            <strong data-settings-media-name="login_logo"></strong>
                            <span data-settings-media-mime="login_logo"></span>
                        </div>
                    </div>
                    <p class="metis-help" data-settings-media-empty="login_logo">No login logo selected.</p>
                <?php endif; ?>
                <input type="hidden" name="login_logo_media_token" value="<?php echo metis_escape_attr( (string) ( $login_logo['public_token'] ?? '' ) ); ?>" data-settings-media-token="login_logo">
                <input type="hidden" name="login_logo_media_url" value="<?php echo metis_escape_attr( (string) ( $login_logo['url'] ?? '' ) ); ?>" data-settings-media-url="login_logo">
                <input type="hidden" name="login_logo_media_name" value="<?php echo metis_escape_attr( (string) ( $login_logo['filename'] ?? '' ) ); ?>" data-settings-media-filename="login_logo">
                <input type="hidden" name="login_logo_media_mime" value="<?php echo metis_escape_attr( (string) ( $login_logo['mime_type'] ?? '' ) ); ?>" data-settings-media-mimevalue="login_logo">
                <button type="button" class="metis-btn metis-btn-ghost metis-btn-sm" data-settings-media-pick="login_logo" data-settings-media-types="image">Choose from Media Library</button>
                <button type="button" class="metis-btn metis-btn-ghost metis-btn-sm" data-settings-media-clear="login_logo">Clear selection</button>
            </div>
            <div class="metis-field" data-settings-media-field="login_background_image">
                <label>Background Image</label>
                <?php if ( $login_background_image_src !== '' ) : ?>
                    <div class="metis-logo-preview-wrap" data-settings-media-preview-wrap="login_background_image">
                        <img src="<?php echo metis_escape_attr( $login_background_image_src ); ?>" alt="Login background preview" class="metis-logo-preview" data-settings-media-preview="login_background_image">
                        <div class="metis-logo-meta">
                            <strong data-settings-media-name="login_background_image"><?php echo metis_escape_html( (string) ( $login_background_image['filename'] ?? 'Background image' ) ); ?></strong>
                            <span data-settings-media-mime="login_background_image"><?php echo metis_escape_html( strtoupper( str_replace( 'image/', '', (string) ( $login_background_image['mime_type'] ?? '' ) ) ) ); ?></span>
                        </div>
                    </div>
                    <label><input type="checkbox" name="remove_login_background_image" value="1"> Remove current background image</label>
                <?php else : ?>
                    <div class="metis-logo-preview-wrap" data-settings-media-preview-wrap="login_background_image" style="display:none;">
                        <img src="" alt="Selected login background image" class="metis-logo-preview" data-settings-media-preview="login_background_image">
                        <div class="metis-logo-meta">
                            <strong data-settings-media-name="login_background_image"></strong>
                            <span data-settings-media-mime="login_background_image"></span>
                        </div>
                    </div>
                    <p class="metis-help" data-settings-media-empty="login_background_image">No background image selected.</p>
                <?php endif; ?>
                <input type="hidden" name="login_background_image_media_token" value="<?php echo metis_escape_attr( (string) ( $login_background_image['public_token'] ?? '' ) ); ?>" data-settings-media-token="login_background_image">
                <input type="hidden" name="login_background_image_media_url" value="<?php echo metis_escape_attr( (string) ( $login_background_image['url'] ?? '' ) ); ?>" data-settings-media-url="login_background_image">
                <input type="hidden" name="login_background_image_media_name" value="<?php echo metis_escape_attr( (string) ( $login_background_image['filename'] ?? '' ) ); ?>" data-settings-media-filename="login_background_image">
                <input type="hidden" name="login_background_image_media_mime" value="<?php echo metis_escape_attr( (string) ( $login_background_image['mime_type'] ?? '' ) ); ?>" data-settings-media-mimevalue="login_background_image">
                <button type="button" class="metis-btn metis-btn-ghost metis-btn-sm" data-settings-media-pick="login_background_image" data-settings-media-types="image">Choose from Media Library</button>
                <button type="button" class="metis-btn metis-btn-ghost metis-btn-sm" data-settings-media-clear="login_background_image">Clear selection</button>
            </div>
        </div>
    </div>
    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Branding Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
