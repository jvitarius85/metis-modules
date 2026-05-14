<?php
if (!defined('METIS_ROOT')) { exit; }
$brandHtml = isset($brand_html) ? (string) $brand_html : '';
$menuHtml = isset($menu_html) ? (string) $menu_html : '';
$footerHtml = isset($footer_html) ? (string) $footer_html : '';
$contentHtml = isset($content_html) ? (string) $content_html : '';
$sidebarHtml = isset($sidebar_html) ? (string) $sidebar_html : '';
$withSidebar = !empty($with_sidebar) && trim($sidebarHtml) !== '';
$sidebarPosition = isset($sidebar_position) ? (string) $sidebar_position : 'right';
if ($sidebarPosition !== 'left' && $sidebarPosition !== 'right') { $sidebarPosition = 'right'; }
?>
<div class="metis-template metis-template-image_overlay_banner metis-template-view-page">
    <header class="metis-template-header metis-template-sticky-capable" role="banner">
        <div class="metis-template-header-inner">
            <div class="metis-template-header-brand"><?php echo $brandHtml; ?></div>
            <button type="button" class="metis-shell-nav-toggle" data-metis-nav-toggle aria-expanded="false" aria-controls="metis-template-primary-menu" aria-label="Open primary menu">
                <span class="metis-shell-nav-toggle-lines" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="metis-shell-nav-toggle-text">Menu</span>
            </button>
            <nav id="metis-template-primary-menu" class="metis-template-menu metis-shell-nav metis-shell-nav-primary" aria-label="Primary menu"><?php echo $menuHtml; ?></nav>
        </div>
    </header>
    <main class="metis-template-main<?php echo $withSidebar ? ' has-sidebar' : ''; ?>" data-view="page" data-sidebar-position="<?php echo metis_escape_attr($sidebarPosition); ?>">
        <div class="metis-template-main-inner<?php echo $withSidebar ? ' has-sidebar' : ''; ?>" data-sidebar-position="<?php echo metis_escape_attr($sidebarPosition); ?>">
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
