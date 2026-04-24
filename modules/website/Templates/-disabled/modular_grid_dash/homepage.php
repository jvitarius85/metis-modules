<?php
if (!defined('METIS_ROOT')) { exit; }
$brandHtml = isset($brand_html) ? (string) $brand_html : '';
$menuHtml = isset($menu_html) ? (string) $menu_html : '';
$footerHtml = isset($footer_html) ? (string) $footer_html : '';
$heroHtml = isset($hero_html) ? (string) $hero_html : '';
$contentHtml = isset($content_html) ? (string) $content_html : '';
$sidebarHtml = isset($sidebar_html) ? (string) $sidebar_html : '';
$withSidebar = !empty($with_sidebar) && trim($sidebarHtml) !== '';
?>
<div class="metis-template metis-template-modular_grid_dash metis-template-view-homepage">
    <header class="metis-template-header metis-template-sticky-capable" role="banner">
        <div class="metis-template-header-inner">
            <div class="metis-template-header-brand"><?php echo $brandHtml; ?></div>
            <nav class="metis-template-menu" aria-label="Primary menu"><?php echo $menuHtml; ?></nav>
        </div>
    </header>
    <main class="metis-template-main<?php echo $withSidebar ? ' has-sidebar' : ''; ?>" data-view="homepage">
        <div class="metis-template-main-inner">
            <?php if ($heroHtml !== ''): ?>
                <section class="metis-template-region metis-template-region-hero" aria-label="Hero"><?php echo $heroHtml; ?></section>
            <?php endif; ?>
            <div class="metis-template-grid-shell<?php echo $withSidebar ? ' has-sidebar' : ''; ?>">
                <section class="metis-template-region metis-template-region-content metis-template-content-masonry"><?php echo $contentHtml; ?></section>
                <?php if ($withSidebar): ?>
                    <aside class="metis-template-sidebar metis-template-sidebar-right"><?php echo $sidebarHtml; ?></aside>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <footer class="metis-template-footer" role="contentinfo">
        <div class="metis-template-footer-inner-wrap"><?php echo $footerHtml; ?></div>
    </footer>
</div>
