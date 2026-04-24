<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'cache' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Clear and rebuild Metis cache layers from a single control surface.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'cache' ); ?>
<div class="metis-settings-card">
    <div class="metis-settings-header"><h2>Cache Controls</h2></div>
    <div class="metis-settings-body">
        <p class="metis-help">These controls manage runtime memory cache, file cache, query cache, API cache, Hermes cache, and dashboard fragments stored under <code>storage/runtime/cache/</code>.</p>
        <div class="metis-settings-actions">
            <button type="button" class="metis-btn" data-cache-action="clear_all">Clear All Cache</button>
            <button type="button" class="metis-btn" data-cache-action="clear_group" data-cache-group="modules">Clear Module Cache</button>
            <button type="button" class="metis-btn" data-cache-action="clear_group" data-cache-group="permissions">Clear Permission Cache</button>
            <button type="button" class="metis-btn" data-cache-action="clear_group" data-cache-group="api">Clear API Cache</button>
            <button type="button" class="metis-btn" data-cache-action="rebuild">Rebuild System Cache</button>
        </div>
        <p class="metis-help" data-cache-status>Ready.</p>
    </div>
</div>
<?php metis_settings_render_section_end(); ?>
