<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_cms_require_view_permission( 'dashboard' ) ) {
    return;
}

use Metis\Modules\Cms\Services\PageService;
use Metis\Modules\Cms\Services\PostService;
use Metis\Modules\Cms\Services\CmsReadinessService;

$total_pages  = PageService::countAll();
$pub_pages    = PageService::countAll( [ 'status' => 'published' ] );
$draft_pages  = max( 0, $total_pages - $pub_pages );
$recent_pages = PageService::getAll( [ 'limit' => 4, 'offset' => 0 ] );

$total_posts  = PostService::countAll();
$pub_posts    = PostService::countAll( [ 'status' => 'published' ] );
$draft_posts  = max( 0, $total_posts - $pub_posts );
$recent_posts = PostService::getAll( [ 'limit' => 4, 'offset' => 0 ] );

$pages_url     = metis_portal_url( 'cms', 'pages' );
$launch_url    = metis_portal_url( 'cms', 'launch' );
$posts_url     = metis_portal_url( 'cms', 'posts' );
$media_url     = metis_portal_url( 'cms', 'media' );
$banners_url   = metis_portal_url( 'cms', 'banners' );
$menus_url     = metis_portal_url( 'cms', 'menus' );
$popups_url    = metis_portal_url( 'cms', 'popups' );
$redirects_url = metis_portal_url( 'cms', 'redirects' );
$templates_url = metis_portal_url( 'cms', 'templates' );
$webparts_url  = metis_portal_url( 'cms', 'webparts' );
$theme_url     = metis_portal_url( 'cms', 'theme' );
$import_url    = metis_portal_url( 'cms', 'import' );

$can_create           = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.create' ) : false;
$can_launch           = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.launch' ) : false;
$can_edit             = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.edit' ) : false;
$can_manage_media     = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_media' ) : false;
$can_manage_banners   = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_banners' ) : false;
$can_manage_menus     = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_menus' ) : false;
$can_manage_popups    = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_popups' ) : false;
$can_manage_redirects = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_redirects' ) : false;
$can_manage_templates = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_templates' ) : false;
$can_manage_webparts  = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_webparts' ) : false;
$can_manage_theme     = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.manage_theme' ) : false;
$can_import           = function_exists( 'metis_security_user_can' ) ? metis_security_user_can( 'cms.import' ) : false;
$readiness            = CmsReadinessService::summary();
$public_routes_enabled = ! empty( $readiness['public_routes_enabled'] );
$readiness_action_allowed = static function ( array $item ) use ( $can_launch, $can_manage_menus, $can_manage_templates, $can_manage_webparts, $can_manage_redirects, $can_manage_theme ): bool {
    $label = (string) ( $item['action_label'] ?? '' );
    if ( $label === 'Launch' ) {
        return $can_launch;
    }
    if ( $label === 'Menus' ) {
        return $can_manage_menus;
    }
    if ( $label === 'Templates' ) {
        return $can_manage_templates;
    }
    if ( $label === 'Web Parts' ) {
        return $can_manage_webparts;
    }
    if ( $label === 'Redirects' ) {
        return $can_manage_redirects;
    }
    if ( $label === 'Theme' ) {
        return $can_manage_theme;
    }

    return in_array( $label, [ 'Pages', 'Posts' ], true );
};
?>
<div class="metis-cms-home">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <div class="metis-breadcrumb-card">CMS</div>
            <h1 class="metis-page-title">Publishing Center</h1>
            <p class="metis-subtitle">Create content, manage the public experience, and keep publishing work organized.</p>
        </div>
        <div class="metis-page-header-right">
            <?php if ( $can_create ) : ?>
                <button type="button" id="metis-dashboard-new-page-btn" class="metis-btn metis-btn-primary metis-btn-sm">New Page</button>
                <button type="button" id="metis-dashboard-new-post-btn" class="metis-btn metis-btn-secondary metis-btn-sm">New Post</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="metis-cms-status-grid">
        <section class="metis-cms-status-card">
            <span class="metis-cms-status-label">Pages</span>
            <strong><?php echo metis_escape_html( (string) $total_pages ); ?></strong>
            <span><?php echo metis_escape_html( (string) $pub_pages ); ?> published · <?php echo metis_escape_html( (string) $draft_pages ); ?> draft</span>
        </section>
        <section class="metis-cms-status-card">
            <span class="metis-cms-status-label">Posts</span>
            <strong><?php echo metis_escape_html( (string) $total_posts ); ?></strong>
            <span><?php echo metis_escape_html( (string) $pub_posts ); ?> published · <?php echo metis_escape_html( (string) $draft_posts ); ?> draft</span>
        </section>
        <section class="metis-cms-status-card">
            <span class="metis-cms-status-label">Public Routes</span>
            <strong><?php echo $public_routes_enabled ? 'On' : 'Off'; ?></strong>
            <span><?php echo $public_routes_enabled ? 'CMS public routing is enabled.' : 'CMS public routing is disabled until launch readiness is confirmed.'; ?></span>
            <?php if ( $can_launch ) : ?>
                <a class="metis-btn-xs" href="<?php echo metis_escape_url( $launch_url ); ?>">Launch Center</a>
            <?php endif; ?>
        </section>
    </div>

    <section class="metis-cms-panel metis-cms-readiness-panel">
        <div class="metis-cms-readiness-summary">
            <div class="metis-cms-panel-heading">
                <h2>Launch Readiness</h2>
                <p>Review the core publishing checks before opening the CMS to visitors.</p>
            </div>
            <div class="metis-cms-readiness-score metis-cms-readiness-score--<?php echo metis_escape_attr( (string) ( $readiness['state'] ?? 'setup' ) ); ?>">
                <strong><?php echo metis_escape_html( (string) ( $readiness['score'] ?? 0 ) ); ?>/<?php echo metis_escape_html( (string) ( $readiness['total'] ?? 0 ) ); ?></strong>
                <span>checks ready</span>
            </div>
            <?php if ( $can_launch ) : ?>
                <a class="metis-btn metis-btn-secondary metis-btn-sm" href="<?php echo metis_escape_url( $launch_url ); ?>">Manage Launch</a>
            <?php endif; ?>
        </div>
        <div class="metis-cms-readiness-list">
            <?php foreach ( (array) ( $readiness['items'] ?? [] ) as $item ) : ?>
                <?php
                $item_status = metis_key_clean( (string) ( $item['status'] ?? 'attention' ) );
                $item_status = in_array( $item_status, [ 'ready', 'attention', 'blocked' ], true ) ? $item_status : 'attention';
                $action_url = (string) ( $item['action_url'] ?? '' );
                $show_action = $action_url !== '' && $item_status !== 'ready' && $readiness_action_allowed( $item );
                ?>
                <article class="metis-cms-readiness-item metis-cms-readiness-item--<?php echo metis_escape_attr( $item_status ); ?>">
                    <span class="metis-cms-readiness-dot" aria-hidden="true"></span>
                    <div>
                        <strong><?php echo metis_escape_html( (string) ( $item['label'] ?? '' ) ); ?></strong>
                        <span><?php echo metis_escape_html( (string) ( $item['detail'] ?? '' ) ); ?></span>
                    </div>
                    <?php if ( $show_action ) : ?>
                        <a class="metis-btn-xs" href="<?php echo metis_escape_url( $action_url ); ?>"><?php echo metis_escape_html( (string) ( $item['action_label'] ?? 'Open' ) ); ?></a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="metis-cms-workspace-grid">
        <section class="metis-cms-panel metis-cms-panel-primary">
            <div class="metis-cms-panel-heading">
                <h2>Content</h2>
                <p>Everyday publishing work starts here.</p>
            </div>
            <div class="metis-cms-action-grid">
                <a class="metis-cms-action" href="<?php echo metis_escape_url( $pages_url ); ?>">
                    <strong>Pages</strong>
                    <span>Manage site pages, homepage content, and page drafts.</span>
                </a>
                <a class="metis-cms-action" href="<?php echo metis_escape_url( $posts_url ); ?>">
                    <strong>Posts</strong>
                    <span>Write updates, articles, and structured post content.</span>
                </a>
                <?php if ( $can_manage_media ) : ?>
                    <a class="metis-cms-action" href="<?php echo metis_escape_url( $media_url ); ?>">
                        <strong>Media</strong>
                        <span>Use images and files that support published content.</span>
                    </a>
                <?php endif; ?>
                <?php if ( $can_import ) : ?>
                    <a class="metis-cms-action" href="<?php echo metis_escape_url( $import_url ); ?>">
                        <strong>Import</strong>
                        <span>Bring content in through controlled import workflows.</span>
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <section class="metis-cms-panel">
            <div class="metis-cms-panel-heading">
                <h2>Site Experience</h2>
                <p>Control navigation, layout, announcements, and visitor flow.</p>
            </div>
            <div class="metis-cms-action-list">
                <?php if ( $can_manage_menus ) : ?>
                    <a href="<?php echo metis_escape_url( $menus_url ); ?>">Menus</a>
                <?php endif; ?>
                <?php if ( $can_manage_banners ) : ?>
                    <a href="<?php echo metis_escape_url( $banners_url ); ?>">Banners</a>
                <?php endif; ?>
                <?php if ( $can_manage_popups ) : ?>
                    <a href="<?php echo metis_escape_url( $popups_url ); ?>">Popups</a>
                <?php endif; ?>
                <?php if ( $can_manage_redirects ) : ?>
                    <a href="<?php echo metis_escape_url( $redirects_url ); ?>">Redirects</a>
                <?php endif; ?>
                <?php if ( $can_manage_templates ) : ?>
                    <a href="<?php echo metis_escape_url( $templates_url ); ?>">Templates</a>
                <?php endif; ?>
                <?php if ( $can_manage_webparts ) : ?>
                    <a href="<?php echo metis_escape_url( $webparts_url ); ?>">Web Parts</a>
                <?php endif; ?>
                <?php if ( $can_manage_theme ) : ?>
                    <a href="<?php echo metis_escape_url( $theme_url ); ?>">Theme</a>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="metis-cms-workspace-grid">
        <section class="metis-cms-panel">
            <div class="metis-cms-panel-heading">
                <h2>Recent Pages</h2>
                <p>Quickly return to current page work.</p>
            </div>
            <div class="metis-cms-recent-list">
                <?php if ( empty( $recent_pages ) ) : ?>
                    <span class="metis-cms-empty">No pages yet.</span>
                <?php endif; ?>
                <?php foreach ( $recent_pages as $page ) : ?>
                    <button type="button" class="metis-cms-recent-item<?php echo $can_edit ? ' metis-edit-page' : ''; ?>" data-id="<?php echo metis_escape_attr( (string) $page->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $page->page_code ?? '' ) ); ?>">
                        <strong><?php echo metis_escape_html( (string) $page->title ); ?></strong>
                        <span><?php echo metis_escape_html( $can_edit ? (string) ( $page->status ?? 'draft' ) : 'View only' ); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="metis-cms-panel">
            <div class="metis-cms-panel-heading">
                <h2>Recent Posts</h2>
                <p>Draft, review, and publish updates.</p>
            </div>
            <div class="metis-cms-recent-list">
                <?php if ( empty( $recent_posts ) ) : ?>
                    <span class="metis-cms-empty">No posts yet.</span>
                <?php endif; ?>
                <?php foreach ( $recent_posts as $post ) : ?>
                    <button type="button" class="metis-cms-recent-item<?php echo $can_edit ? ' metis-edit-post' : ''; ?>" data-id="<?php echo metis_escape_attr( (string) $post->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $post->post_code ?? '' ) ); ?>">
                        <strong><?php echo metis_escape_html( (string) $post->title ); ?></strong>
                        <span><?php echo metis_escape_html( $can_edit ? (string) ( $post->status ?? 'draft' ) : 'View only' ); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
