<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Website\Services\TemplateService;

$templates = TemplateService::discoverTemplates();
$active_template = TemplateService::getActiveTemplateSlug();
$active_label = $active_template;
foreach ( $templates as $candidate ) {
    if ( metis_key_clean( (string) ( $candidate['slug'] ?? '' ) ) === $active_template ) {
        $active_label = (string) ( $candidate['name'] ?? $active_template );
        break;
    }
}
?>
<div id="metis-templates-view" class="metis-config-view">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <h1 class="metis-page-title">Templates</h1>
            <p class="metis-subtitle">Set the site-wide default template for new content. Pages and posts can choose a different template in their own editor.</p>
        </div>
    </div>

    <section class="metis-premium-wrap metis-layout-gallery-card">
        <div class="metis-layout-gallery-grid" id="metis-layout-gallery-grid">
            <?php foreach ( $templates as $template ) :
                $slug = metis_key_clean( (string) ( $template['slug'] ?? '' ) );
                if ( $slug === '' ) {
                    continue;
                }
                $name = (string) ( $template['name'] ?? $slug );
                $description = (string) ( $template['description'] ?? '' );
                $is_active = $active_template === $slug;
            ?>
                <button type="button" class="metis-layout-gallery-item<?php echo $is_active ? ' is-active' : ''; ?>" data-layout-profile="<?php echo metis_escape_attr( $slug ); ?>" data-layout-name="<?php echo metis_escape_attr( $name ); ?>">
                    <span class="metis-layout-gallery-thumb metis-layout-gallery-thumb-<?php echo metis_escape_attr( $slug ); ?>">
                        <?php echo TemplateService::renderPreviewSurface( $slug ); ?>
                    </span>
                    <span class="metis-layout-gallery-content">
                        <strong><?php echo metis_escape_html( $name ); ?></strong>
                        <small><?php echo metis_escape_html( $description ); ?></small>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="metis-layout-gallery-actions">
            <span id="metis-layout-gallery-status" class="metis-inline-code-muted">Active template: <?php echo metis_escape_html( $active_label ); ?></span>
            <button type="button" class="metis-btn metis-btn-ghost" id="metis-layout-preview-open">Open Full Preview</button>
        </div>
    </section>

    <div id="metis-layout-preview-modal" class="metis-layout-preview-modal" hidden>
        <div class="metis-layout-preview-backdrop" data-action="close"></div>
        <div class="metis-layout-preview-dialog">
            <div class="metis-layout-preview-topbar">
                <button type="button" class="metis-btn metis-btn-ghost" data-action="close">&larr; Back to templates</button>
                <div id="metis-layout-preview-name" class="metis-layout-preview-name">Template Preview</div>
                <div class="metis-layout-preview-controls">
                    <select id="metis-layout-preview-select" class="metis-input metis-input-sm">
                        <?php foreach ( $templates as $template ) :
                            $slug = metis_key_clean( (string) ( $template['slug'] ?? '' ) );
                            if ( $slug === '' ) {
                                continue;
                            }
                            $name = (string) ( $template['name'] ?? $slug );
                        ?>
                            <option value="<?php echo metis_escape_attr( $slug ); ?>"<?php echo $active_template === $slug ? ' selected' : ''; ?>><?php echo metis_escape_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="metis-layout-preview-source" class="metis-input metis-input-sm">
                        <option value="auto" selected>Auto content</option>
                        <option value="homepage">Homepage</option>
                        <option value="post">Post</option>
                        <option value="page">Page</option>
                        <option value="demo">Demo</option>
                    </select>
                </div>
            </div>
            <div class="metis-layout-preview-canvas-wrap">
                <iframe id="metis-layout-preview-frame" class="metis-layout-preview-frame-live" title="Template Preview" loading="lazy"></iframe>
            </div>
        </div>
    </div>
</div>
