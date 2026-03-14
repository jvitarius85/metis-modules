<?php
if ( ! defined( 'ABSPATH' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'newsletter' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title">Settings</h1>
<p class="mw-subtitle">Set newsletter defaults and editor integrations.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'newsletter' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1">
    <?php metis_nonce_field( 'metis_save_settings_newsletter', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Newsletter Defaults</h2></div>
        <div class="mw-settings-body">
            <div class="mw-field">
                <label for="newsletter_default_from_name">Default From Name</label>
                <input type="text" id="newsletter_default_from_name" name="newsletter_default_from_name" class="mw-input mw-input-wide" maxlength="191" value="<?php echo esc_attr( (string) $newsletter_default_from_name ); ?>">
            </div>
            <div class="mw-field">
                <label for="newsletter_default_from_email">Default From Email</label>
                <input type="email" id="newsletter_default_from_email" name="newsletter_default_from_email" class="mw-input mw-input-wide" maxlength="191" value="<?php echo esc_attr( (string) $newsletter_default_from_email ); ?>" autocomplete="off">
            </div>
            <div class="mw-field">
                <label for="newsletter_default_reply_to">Default Reply-To</label>
                <input type="email" id="newsletter_default_reply_to" name="newsletter_default_reply_to" class="mw-input mw-input-wide" maxlength="191" value="<?php echo esc_attr( (string) $newsletter_default_reply_to ); ?>" autocomplete="off">
            </div>
            <div class="mw-field">
                <label for="newsletter_google_daily_limit">Google Daily Send Cap</label>
                <input type="number" id="newsletter_google_daily_limit" name="newsletter_google_daily_limit" class="mw-input mw-input-wide" min="100" max="100000" step="1" value="<?php echo esc_attr( (string) $newsletter_google_daily_limit ); ?>">
                <p class="mw-help">Used by Newsletter usage monitoring to warn as you approach the configured send limit.</p>
            </div>
            <div class="mw-field">
                <label for="newsletter_klipy_api_key">Klipy API Key</label>
                <input type="password" id="newsletter_klipy_api_key" name="newsletter_klipy_api_key" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $newsletter_klipy_api_key ); ?>" autocomplete="new-password" placeholder="Paste your Klipy API key">
                <p class="mw-help">Used by the Klipy block and GIF search in the newsletter editor.</p>
            </div>
            <div class="mw-field">
                <label for="newsletter_klipy_search_url">Klipy Search Endpoint</label>
                <input type="url" id="newsletter_klipy_search_url" name="newsletter_klipy_search_url" class="mw-input mw-input-wide" value="<?php echo esc_attr( (string) $newsletter_klipy_search_url ); ?>" autocomplete="off" placeholder="https://api.klipy.com/...">
                <p class="mw-help">Search endpoint used by the Klipy picker. Defaults to <code>https://api.klipy.com/v1/gifs/search</code>.</p>
            </div>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Newsletter Settings</button>
    </div>
</form>
