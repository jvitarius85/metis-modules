<?php
if (!defined('METIS_ROOT')) { exit; }
$brandHtml = isset($brand_html) ? (string) $brand_html : '';
$menuHtml = isset($menu_html) ? (string) $menu_html : '';
$footerHtml = isset($footer_html) ? (string) $footer_html : '';
$heroHtml = isset($hero_html) ? (string) $hero_html : '';
$contentHtml = isset($content_html) ? (string) $content_html : '';
$sidebarHtml = isset($sidebar_html) ? (string) $sidebar_html : '';
$withSidebar = !empty($with_sidebar) && trim($sidebarHtml) !== '';
$sidebarPosition = isset($sidebar_position) ? (string) $sidebar_position : 'right';
if ($sidebarPosition !== 'left' && $sidebarPosition !== 'right') { $sidebarPosition = 'right'; }
?>
<div class="metis-template metis-template-compact_app_style metis-template-view-homepage">
    <header class="metis-template-header metis-template-sticky-capable" role="banner">
        <div class="metis-template-header-inner">
            <div class="metis-template-header-brand"><?php echo $brandHtml; ?></div>
            <nav class="metis-template-menu" aria-label="Primary menu"><?php echo $menuHtml; ?></nav>
        </div>
    </header>
    <?php if ($heroHtml !== ''): ?>
        <section class="metis-template-region metis-template-region-hero" aria-label="Hero"><?php echo $heroHtml; ?></section>
    <?php endif; ?>
    <main class="metis-template-main<?php echo $withSidebar ? ' has-sidebar' : ''; ?>" data-view="homepage" data-sidebar-position="<?php echo metis_escape_attr($sidebarPosition); ?>">
        <div class="metis-template-main-inner">
            <?php if ($withSidebar && $sidebarPosition === 'left'): ?>
                <aside class="metis-template-sidebar metis-template-sidebar-left"><?php echo $sidebarHtml; ?></aside>
            <?php endif; ?>
            <section class="metis-template-region metis-template-region-content"><?php echo $contentHtml; ?></section>
            <?php if ($withSidebar && $sidebarPosition === 'right'): ?>
                <aside class="metis-template-sidebar metis-template-sidebar-right"><?php echo $sidebarHtml; ?></aside>
            <?php endif; ?>
        </div>
    </main>
    <footer class="metis-template-footer" role="contentinfo">
        <div class="metis-template-footer-inner-wrap"><?php echo $footerHtml; ?></div>
    </footer>
</div>
