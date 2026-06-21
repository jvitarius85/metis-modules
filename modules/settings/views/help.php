<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'help' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Control the in-app help system, topic overrides, and custom walkthrough definitions.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'help' ); ?>
<form method="post" class="metis-settings-form" data-metis-settings-form="1" data-settings-section="help">
    <?php metis_runtime_nonce_field( 'metis_save_settings_help', 'metis_settings_nonce' ); ?>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Help Availability</h2></div>
        <div class="metis-settings-body">
            <label class="metis-accessibility-option"><input type="checkbox" name="help_enabled" value="1" <?php metis_attr_checked( $help_enabled ); ?>> Enable contextual help mode</label>
            <label class="metis-accessibility-option"><input type="checkbox" name="walkthrough_enabled" value="1" <?php metis_attr_checked( $walkthrough_enabled ); ?>> Enable guided walkthroughs</label>
            <p class="metis-help">These switches control the Help toggle, searchable help modal, and in-app walkthrough launcher across the portal.</p>
        </div>
    </div>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Topic Overrides</h2></div>
        <div class="metis-settings-body">
            <p class="metis-help">Override generated help copy for existing topic ids. Example: <code>{"donations.deposits":{"description":"Custom text"}}</code></p>
            <textarea name="help_topic_overrides_json" class="metis-input" rows="10"><?php echo metis_escape_html( metis_json_encode( $help_topic_overrides ) ); ?></textarea>
        </div>
    </div>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Custom Topics</h2></div>
        <div class="metis-settings-body">
            <p class="metis-help">Add new help topics for custom pages or extensions. Use topic ids that match your <code>data-help</code> attributes.</p>
            <textarea name="help_custom_topics_json" class="metis-input" rows="10"><?php echo metis_escape_html( metis_json_encode( $help_custom_topics ) ); ?></textarea>
        </div>
    </div>
    <div class="metis-settings-card">
        <div class="metis-settings-header"><h2>Custom Walkthroughs</h2></div>
        <div class="metis-settings-body">
            <p class="metis-help">Add walkthrough ids and step definitions. Each step should include <code>target</code> and <code>message</code>.</p>
            <textarea name="help_custom_walkthroughs_json" class="metis-input" rows="12"><?php echo metis_escape_html( metis_json_encode( $help_custom_walkthroughs ) ); ?></textarea>
        </div>
    </div>
    <div class="metis-settings-actions">
        <button type="submit" class="metis-btn">Save Help Settings</button>
    </div>
</form>
<?php metis_settings_render_section_end(); ?>
