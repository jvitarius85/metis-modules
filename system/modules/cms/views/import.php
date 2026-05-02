<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_cms_require_view_permission( 'import' ) ) {
    return;
}

$import_url = metis_portal_url( 'import', 'dashboard' );
$media_url = metis_portal_url( 'cms', 'media' );
$templates_url = metis_portal_url( 'cms', 'templates' );
$pages_url = metis_portal_url( 'cms', 'pages' );
$posts_url = metis_portal_url( 'cms', 'posts' );
$can_manage_media = function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'cms.manage_media' );
$can_manage_templates = function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'cms.manage_templates' );
?>
<div class="metis-cms-home metis-cms-import-hub">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <div class="metis-breadcrumb-card">CMS</div>
            <h1 class="metis-page-title">Import Center</h1>
            <p class="metis-subtitle">Bring content into the CMS through review-first import workflows.</p>
        </div>
        <div class="metis-page-header-right">
            <a href="<?php echo metis_escape_url( $import_url ); ?>" class="metis-btn metis-btn-primary metis-btn-sm">Open Import Tool</a>
        </div>
    </div>

    <div class="metis-cms-status-grid">
        <section class="metis-cms-status-card">
            <span class="metis-cms-status-label">Flow</span>
            <strong>Preview</strong>
            <span>Imports should be reviewed before any publishing changes are applied.</span>
        </section>
        <section class="metis-cms-status-card">
            <span class="metis-cms-status-label">Content</span>
            <strong>Pages + Posts</strong>
            <span>Use controlled import mapping for public content.</span>
        </section>
        <section class="metis-cms-status-card">
            <span class="metis-cms-status-label">Assets</span>
            <strong>Media</strong>
            <span>Media references should resolve before publishing imported content.</span>
        </section>
    </div>

    <section class="metis-cms-panel metis-cms-panel-primary">
        <div class="metis-cms-panel-heading">
            <h2>Recommended Import Workflow</h2>
            <p>Keep the process predictable for admins and safe for public pages.</p>
        </div>
        <div class="metis-cms-workflow-steps" aria-label="Recommended import workflow">
            <article>
                <strong>1</strong>
                <span>Upload source content</span>
                <small>Use the Import module to bring in the source file or supported feed.</small>
            </article>
            <article>
                <strong>2</strong>
                <span>Map content</span>
                <small>Confirm pages, posts, slugs, categories, and media references before import.</small>
            </article>
            <article>
                <strong>3</strong>
                <span>Review drafts</span>
                <small>Imported content should land as reviewable drafts unless explicitly published.</small>
            </article>
            <article>
                <strong>4</strong>
                <span>Publish intentionally</span>
                <small>Use CMS publishing controls after layout, navigation, and redirects are verified.</small>
            </article>
        </div>
    </section>

    <div class="metis-cms-workspace-grid">
        <section class="metis-cms-panel">
            <div class="metis-cms-panel-heading">
                <h2>After Import</h2>
                <p>Use these areas to finish the public experience.</p>
            </div>
            <div class="metis-cms-action-grid">
                <a class="metis-cms-action" href="<?php echo metis_escape_url( $pages_url ); ?>">
                    <strong>Pages</strong>
                    <span>Review imported page hierarchy, homepage, and slugs.</span>
                </a>
                <a class="metis-cms-action" href="<?php echo metis_escape_url( $posts_url ); ?>">
                    <strong>Posts</strong>
                    <span>Check categories, drafts, and published post paths.</span>
                </a>
                <?php if ( $can_manage_media ) : ?>
                    <a class="metis-cms-action" href="<?php echo metis_escape_url( $media_url ); ?>">
                        <strong>Media</strong>
                        <span>Confirm referenced images and files are present.</span>
                    </a>
                <?php endif; ?>
                <?php if ( $can_manage_templates ) : ?>
                    <a class="metis-cms-action" href="<?php echo metis_escape_url( $templates_url ); ?>">
                        <strong>Templates</strong>
                        <span>Apply reusable layouts after content is staged.</span>
                    </a>
                <?php endif; ?>
            </div>
        </section>
        <section class="metis-cms-panel">
            <div class="metis-cms-panel-heading">
                <h2>Import Guardrails</h2>
                <p>These checks keep imported content from disrupting the live site.</p>
            </div>
            <ul class="metis-cms-check-list">
                <li>Do not overwrite published pages without review.</li>
                <li>Preserve existing URLs unless redirects are planned.</li>
                <li>Validate media paths before enabling public routes.</li>
                <li>Use drafts for uncertain or incomplete content.</li>
            </ul>
        </section>
    </div>
</div>
