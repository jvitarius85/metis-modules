<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'help' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="mw-subtitle">Control the in-app help system, topic overrides, and custom walkthrough definitions.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'help' ); ?>
<form method="post" class="mw-settings-form" data-metis-settings-form="1" data-settings-section="help">
    <?php metis_runtime_nonce_field( 'metis_save_settings_help', 'metis_settings_nonce' ); ?>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Help Availability</h2></div>
        <div class="mw-settings-body">
            <label class="mw-accessibility-option"><input type="checkbox" name="help_enabled" value="1" <?php metis_attr_checked( $help_enabled ); ?>> Enable contextual help mode</label>
            <label class="mw-accessibility-option"><input type="checkbox" name="walkthrough_enabled" value="1" <?php metis_attr_checked( $walkthrough_enabled ); ?>> Enable guided walkthroughs</label>
            <p class="mw-help">These switches control the Help toggle, searchable help modal, and in-app walkthrough launcher across the portal.</p>
        </div>
    </div>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Topic Overrides</h2></div>
        <div class="mw-settings-body">
            <p class="mw-help">Override generated help copy for existing topic ids. Example: <code>{"donations.deposits":{"description":"Custom text"}}</code></p>
            <textarea name="help_topic_overrides_json" class="mw-input" rows="10"><?php echo esc_textarea( metis_json_encode( $help_topic_overrides ) ); ?></textarea>
        </div>
    </div>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Custom Topics</h2></div>
        <div class="mw-settings-body">
            <p class="mw-help">Add new help topics for custom pages or extensions. Use topic ids that match your <code>data-help</code> attributes.</p>
            <textarea name="help_custom_topics_json" class="mw-input" rows="10"><?php echo esc_textarea( metis_json_encode( $help_custom_topics ) ); ?></textarea>
        </div>
    </div>
    <div class="mw-settings-card">
        <div class="mw-settings-header"><h2>Custom Walkthroughs</h2></div>
        <div class="mw-settings-body">
            <p class="mw-help">Add walkthrough ids and step definitions. Each step should include <code>target</code> and <code>message</code>.</p>
            <textarea name="help_custom_walkthroughs_json" class="mw-input" rows="12"><?php echo esc_textarea( metis_json_encode( $help_custom_walkthroughs ) ); ?></textarea>
        </div>
    </div>
    <div class="mw-settings-actions">
        <button type="submit" class="mw-btn">Save Help Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
