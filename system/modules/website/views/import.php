<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'import' ) ) {
    return;
}

$import_ready = \Metis\Modules\Website\Services\ImportService::readiness();
$import_url = (string) ( $import_ready['url'] ?? metis_portal_url( 'import', 'dashboard' ) );
$media_url = metis_portal_url( 'website', 'media' );
$templates_url = metis_portal_url( 'website', 'templates' );
$pages_url = metis_portal_url( 'website', 'pages' );
$posts_url = metis_portal_url( 'website', 'posts' );
$can_manage_media = function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'website.manage_media' );
$can_manage_templates = function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'website.manage_templates' );
$import_available = ! empty( $import_ready['available'] );
?>
<div class="metis-website-home metis-website-import-hub">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <div class="metis-breadcrumb-card">Website</div>
            <h1 class="metis-page-title">Import Center</h1>
            <p class="metis-subtitle">Bring content into the Website through review-first import workflows.</p>
        </div>
        <div class="metis-page-header-right">
            <?php if ( $import_available ) : ?>
                <a href="<?php echo metis_escape_url( $import_url ); ?>" class="metis-btn metis-btn-primary metis-btn-sm">Open Import Tool</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="metis-website-status-grid">
        <section class="metis-website-status-card">
            <span class="metis-website-status-label">Flow</span>
            <strong><?php echo $import_available ? 'Preview' : 'Manual'; ?></strong>
            <span><?php echo metis_escape_html( (string) ( $import_ready['message'] ?? '' ) ); ?></span>
        </section>
        <section class="metis-website-status-card">
            <span class="metis-website-status-label">Content</span>
            <strong>Pages + Posts</strong>
            <span>Use controlled import mapping for public content.</span>
        </section>
        <section class="metis-website-status-card">
            <span class="metis-website-status-label">Assets</span>
            <strong>Media</strong>
            <span>Media references should resolve before publishing imported content.</span>
        </section>
    </div>

    <section class="metis-website-panel metis-website-panel-primary">
        <div class="metis-website-panel-heading">
            <h2>Recommended Import Workflow</h2>
            <p>Keep the process predictable for admins and safe for public pages.</p>
        </div>
        <div class="metis-website-workflow-steps" aria-label="Recommended import workflow">
            <?php foreach ( (array) ( $import_ready['steps'] ?? [] ) as $index => $step ) : ?>
                <article>
                    <strong><?php echo metis_escape_html( (string) ( $index + 1 ) ); ?></strong>
                    <span><?php echo metis_escape_html( (string) ( $step['label'] ?? '' ) ); ?></span>
                    <small><?php echo metis_escape_html( (string) ( $step['detail'] ?? '' ) ); ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="metis-website-workspace-grid">
        <section class="metis-website-panel">
            <div class="metis-website-panel-heading">
                <h2>After Import</h2>
                <p>Use these areas to finish the public experience.</p>
            </div>
            <div class="metis-website-action-grid">
                <a class="metis-website-action" href="<?php echo metis_escape_url( $pages_url ); ?>">
                    <strong>Pages</strong>
                    <span>Review imported page hierarchy, homepage, and slugs.</span>
                </a>
                <a class="metis-website-action" href="<?php echo metis_escape_url( $posts_url ); ?>">
                    <strong>Posts</strong>
                    <span>Check categories, drafts, and published post paths.</span>
                </a>
                <?php if ( $can_manage_media ) : ?>
                    <a class="metis-website-action" href="<?php echo metis_escape_url( $media_url ); ?>">
                        <strong>Media</strong>
                        <span>Confirm referenced images and files are present.</span>
                    </a>
                <?php endif; ?>
                <?php if ( $can_manage_templates ) : ?>
                    <a class="metis-website-action" href="<?php echo metis_escape_url( $templates_url ); ?>">
                        <strong>Templates</strong>
                        <span>Apply reusable layouts after content is staged.</span>
                    </a>
                <?php endif; ?>
            </div>
        </section>
        <section class="metis-website-panel">
            <div class="metis-website-panel-heading">
                <h2>Import Guardrails</h2>
                <p>These checks keep imported content from disrupting the live site.</p>
            </div>
            <ul class="metis-website-check-list">
                <?php foreach ( \Metis\Modules\Website\Services\ImportService::guardrails() as $guardrail ) : ?>
                    <li><?php echo metis_escape_html( $guardrail ); ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>
