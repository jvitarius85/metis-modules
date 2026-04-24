<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;

$total_pages = PageService::countAll();
$pub_pages   = PageService::countAll( [ 'status' => 'published' ] );
$draft_pages = max( 0, $total_pages - $pub_pages );
$recent_pages = PageService::getAll( [ 'limit' => 3, 'offset' => 0 ] );

$total_posts = PostService::countAll();
$pub_posts   = PostService::countAll( [ 'status' => 'published' ] );
$draft_posts = max( 0, $total_posts - $pub_posts );
$recent_posts = PostService::getAll( [ 'limit' => 3, 'offset' => 0 ] );

$pages_url   = metis_portal_url( 'website', 'pages' );
$posts_url   = metis_portal_url( 'website', 'posts' );
$media_url   = metis_portal_url( 'website', 'media' );
$media_library_url = metis_portal_url( 'media', 'library' );
$banners_url = metis_portal_url( 'website', 'banners' );
$menus_url   = metis_portal_url( 'website', 'menus' );
$theme_url   = metis_portal_url( 'website', 'theme' );
$import_url  = metis_portal_url( 'website', 'import' );
?>
<div class="metis-ws-dashboard">
    <div class="mw-page-header">
        <div class="mw-page-header-left">
            <h1 class="mw-page-title">Website</h1>
            <p class="mw-subtitle">Manage pages, posts, navigation, media, banners, and import workflows.</p>
        </div>
        <div class="mw-page-header-right">
            <button type="button" id="metis-dashboard-new-page-btn" class="mw-btn mw-btn-primary mw-btn-sm">New Page</button>
            <button type="button" id="metis-dashboard-new-post-btn" class="mw-btn mw-btn-secondary mw-btn-sm">New Post</button>
        </div>
    </div>
    <div class="metis-ws-grid">

        <div class="metis-ws-card">
            <div class="metis-ws-card-icon">&#128196;</div>
            <div class="metis-ws-card-body">
                <h3>Pages</h3>
                <div class="metis-ws-stat"><?php echo metis_escape_html( (string) $total_pages ); ?></div>
                <div class="metis-ws-meta"><?php echo metis_escape_html( (string) $pub_pages ); ?> published &middot; <?php echo metis_escape_html( (string) $draft_pages ); ?> drafts</div>
                <a href="<?php echo metis_escape_url( $pages_url ); ?>" class="metis-ws-link">Manage Pages &rarr;</a>
            </div>
        </div>

        <div class="metis-ws-card">
            <div class="metis-ws-card-icon">&#9997;</div>
            <div class="metis-ws-card-body">
                <h3>Posts</h3>
                <div class="metis-ws-stat"><?php echo metis_escape_html( (string) $total_posts ); ?></div>
                <div class="metis-ws-meta"><?php echo metis_escape_html( (string) $pub_posts ); ?> published &middot; <?php echo metis_escape_html( (string) $draft_posts ); ?> drafts</div>
                <a href="<?php echo metis_escape_url( $posts_url ); ?>" class="metis-ws-link">Manage Posts &rarr;</a>
            </div>
        </div>

        <div class="metis-ws-card">
            <div class="metis-ws-card-icon">&#128247;</div>
            <div class="metis-ws-card-body">
                <h3>Media</h3>
                <div class="metis-ws-meta" style="margin-top:8px;">Manage uploaded website files</div>
                <a href="<?php echo metis_escape_url( $media_url ); ?>" class="metis-ws-link">Open Media Browser &rarr;</a>
                <a href="<?php echo metis_escape_url( $media_library_url ); ?>" class="metis-ws-link">Open Shared Media Library &rarr;</a>
            </div>
        </div>

        <div class="metis-ws-card">
            <div class="metis-ws-card-icon">&#9776;</div>
            <div class="metis-ws-card-body">
                <h3>Menus</h3>
                <div class="metis-ws-meta" style="margin-top:8px;">Manage navigation menus</div>
                <a href="<?php echo metis_escape_url( $menus_url ); ?>" class="metis-ws-link">Manage Menus &rarr;</a>
            </div>
        </div>

        <div class="metis-ws-card">
            <div class="metis-ws-card-icon">&#128227;</div>
            <div class="metis-ws-card-body">
                <h3>Banners</h3>
                <div class="metis-ws-meta" style="margin-top:8px;">Schedule site-wide announcements</div>
                <a href="<?php echo metis_escape_url( $banners_url ); ?>" class="metis-ws-link">Manage Banners &rarr;</a>
            </div>
        </div>

        <div class="metis-ws-card">
            <div class="metis-ws-card-icon">&#9889;</div>
            <div class="metis-ws-card-body">
                <h3>Quick Actions</h3>
                <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px;">
                    <button type="button" id="metis-dashboard-quick-new-page-btn" class="mw-btn mw-btn-primary mw-btn-sm">+ New Page</button>
                    <button type="button" id="metis-dashboard-quick-new-post-btn" class="mw-btn mw-btn-secondary mw-btn-sm">+ New Post</button>
                </div>
                <div style="margin-top:14px;display:flex;flex-direction:column;gap:6px;">
                    <div class="mw-help" style="margin:0;">Quick edit recent pages</div>
                    <?php foreach ( $recent_pages as $page ) : ?>
                        <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm metis-edit-page" data-id="<?php echo metis_escape_attr( (string) $page->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $page->page_code ?? '' ) ); ?>"><?php echo metis_escape_html( (string) $page->title ); ?></button>
                    <?php endforeach; ?>
                    <div class="mw-help" style="margin:6px 0 0;">Quick edit recent posts</div>
                    <?php foreach ( $recent_posts as $post ) : ?>
                        <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm metis-edit-post" data-id="<?php echo metis_escape_attr( (string) $post->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $post->post_code ?? '' ) ); ?>"><?php echo metis_escape_html( (string) $post->title ); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>
</div>
