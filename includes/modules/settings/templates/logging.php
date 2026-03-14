<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'logging' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Control how much the platform writes to the central logger.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'logging' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_logging', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Logging Controls</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="logging_min_level">Minimum Log Level</label>
                <select id="logging_min_level" name="logging_min_level" class="mw-input mw-input-wide">
                    <option value="INFO" <?php selected( $logging_min_level, 'INFO' ); ?>>Info</option>
                    <option value="WARN" <?php selected( $logging_min_level, 'WARN' ); ?>>Warning</option>
                    <option value="ERROR" <?php selected( $logging_min_level, 'ERROR' ); ?>>Error</option>
                </select>
                <p class="mw-help">Info logs everything important, Warning keeps warnings and errors, and Error records only failures.</p>
            </div>
            <div class="mw-field">
                <label for="logging_force_url_token">Force Logging URL String</label>
                <input type="text" id="logging_force_url_token" name="logging_force_url_token" class="mw-input mw-input-wide" value="<?php echo esc_attr( $logging_force_url_token ); ?>" placeholder="debug-logging-token">
                <p class="mw-help">If this exact string appears anywhere in the request URL, that request is logged at Info level even when the global setting is stricter.</p>
            </div>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Logging Settings</button>
    </div>
</form>
