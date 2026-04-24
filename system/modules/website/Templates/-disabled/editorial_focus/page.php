<?php
if (!defined('METIS_ROOT')) { exit; }
$brandHtml = isset($brand_html) ? (string) $brand_html : '';
$menuHtml = isset($menu_html) ? (string) $menu_html : '';
$footerHtml = isset($footer_html) ? (string) $footer_html : '';
$contentHtml = isset($content_html) ? (string) $content_html : '';
$sidebarHtml = isset($sidebar_html) ? (string) $sidebar_html : '';
$withSidebar = !empty($with_sidebar) && trim($sidebarHtml) !== '';
?>
<div class="metis-template metis-template-editorial_focus metis-template-view-page">
    <header class="metis-template-header metis-template-sticky-capable" role="banner">
        <div class="metis-template-header-inner">
            <div class="metis-template-header-brand"><?php echo $brandHtml; ?></div>
        </div>
    </header>
    <div class="metis-template-layout-shell">
        <aside class="metis-template-menu-rail" aria-label="Navigation rail">
            <nav class="metis-template-menu" aria-label="Primary menu"><?php echo $menuHtml; ?></nav>
            <?php if ($withSidebar): ?>
                <div class="metis-template-rail-panel"><?php echo $sidebarHtml; ?></div>
            <?php endif; ?>
        </aside>
        <main class="metis-template-main" data-view="page">
            <div class="metis-template-main-inner">
                <section class="metis-template-region metis-template-region-content"><?php echo $contentHtml; ?></section>
            </div>
        </main>
    </div>
    <footer class="metis-template-footer" role="contentinfo">
        <div class="metis-template-footer-inner-wrap"><?php echo $footerHtml; ?></div>
    </footer>
</div>
