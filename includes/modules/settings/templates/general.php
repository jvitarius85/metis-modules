<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'general' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Manage portal identity, logo, and favicon.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'general' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" enctype="multipart/form-data">
    <?php metis_nonce_field( 'metis_save_settings_general', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Portal</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="portal_name">Site Name</label>
                <input type="text" id="portal_name" name="portal_name" class="mw-input mw-input-wide" value="<?php echo esc_attr( $portal_name ); ?>" placeholder="Metis Portal">
            </div>
            <div class="mw-field">
                <label for="org_name">Organization Name</label>
                <input type="text" id="org_name" name="org_name" class="mw-input mw-input-wide" value="<?php echo esc_attr( $org_name ); ?>" placeholder="Mobilize Waco">
            </div>
            <div class="mw-field">
                <label for="portal_logo_file">Logo</label>
                <div class="metis-logo-upload">
                    <?php if ( $portal_logo_src !== '' ) : ?>
                        <div class="metis-logo-preview-wrap">
                            <img src="<?php echo esc_attr( $portal_logo_src ); ?>" alt="Current logo" class="metis-logo-preview">
                            <div class="metis-logo-meta">
                                <strong><?php echo esc_html( (string) ( $portal_logo['filename'] ?? 'Current logo' ) ); ?></strong>
                                <span><?php echo esc_html( strtoupper( str_replace( 'image/', '', (string) ( $portal_logo['mime_type'] ?? '' ) ) ) ); ?></span>
                            </div>
                        </div>
                    <?php else : ?>
                        <p class="mw-help">No logo uploaded yet.</p>
                    <?php endif; ?>
                    <input type="file" id="portal_logo_file" name="portal_logo_file" class="mw-input mw-input-wide" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp">
                    <p class="mw-help">Stored in the Metis settings table, not an external media library. Max 2 MB.</p>
                    <?php if ( $portal_logo_src !== '' ) : ?>
                        <label class="metis-settings-flag">
                            <input type="checkbox" name="remove_portal_logo" value="1">
                            Remove current logo
                        </label>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mw-field">
                <label for="portal_favicon_file">Favicon</label>
                <div class="metis-logo-upload">
                    <?php if ( $portal_favicon_src !== '' ) : ?>
                        <div class="metis-logo-preview-wrap metis-favicon-preview-wrap">
                            <img src="<?php echo esc_attr( $portal_favicon_src ); ?>" alt="Current favicon" class="metis-logo-preview metis-favicon-preview">
                            <div class="metis-logo-meta">
                                <strong><?php echo esc_html( (string) ( $portal_favicon['filename'] ?? 'Current favicon' ) ); ?></strong>
                                <span><?php echo esc_html( strtoupper( str_replace( [ 'image/', 'vnd.microsoft.' ], '', (string) ( $portal_favicon['mime_type'] ?? '' ) ) ) ); ?></span>
                            </div>
                        </div>
                    <?php else : ?>
                        <p class="mw-help">No favicon uploaded yet.</p>
                    <?php endif; ?>
                    <input type="file" id="portal_favicon_file" name="portal_favicon_file" class="mw-input mw-input-wide" accept=".png,.ico,.svg,image/png,image/x-icon,image/vnd.microsoft.icon,image/svg+xml">
                    <p class="mw-help">Stored in the Metis settings table, not an external media library. Use a square image, ideally 32x32 or 48x48. Max 512 KB.</p>
                    <?php if ( $portal_favicon_src !== '' ) : ?>
                        <label class="metis-settings-flag">
                            <input type="checkbox" name="remove_portal_favicon" value="1">
                            Remove current favicon
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save General Settings</button>
    </div>
</form>
