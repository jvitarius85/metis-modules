<?php
if (!defined('METIS_ROOT')) { exit; }
$brandHtml = isset($brand_html) ? (string) $brand_html : '';
$menuHtml = isset($menu_html) ? (string) $menu_html : '';
$ctaMenuHtml = isset($cta_menu_html) ? (string) $cta_menu_html : '';
$footerHtml = isset($footer_html) ? (string) $footer_html : '';
$heroHtml = isset($hero_html) ? (string) $hero_html : '';
$contentHtml = isset($content_html) ? (string) $content_html : '';
$sidebarHtml = isset($sidebar_html) ? (string) $sidebar_html : '';
$withSidebar = !empty($with_sidebar) && trim($sidebarHtml) !== '';
$sidebarPosition = isset($sidebar_position) ? (string) $sidebar_position : 'right';
if ($sidebarPosition !== 'left' && $sidebarPosition !== 'right') { $sidebarPosition = 'right'; }
?>
<div class="metis-template metis-template-image_overlay_banner metis-template-view-homepage">
    <a class="metis-skip-link" href="#metis-template-main-content">Skip to main content</a>
    <header class="metis-template-header metis-template-sticky-capable" role="banner">
        <div class="metis-template-header-inner">
            <div class="metis-template-header-brand"><?php echo $brandHtml; ?></div>
            <button type="button" class="metis-shell-nav-toggle" data-metis-nav-toggle aria-expanded="false" aria-controls="metis-template-nav-panel" aria-label="Open primary menu">
                <span class="metis-shell-nav-toggle-lines" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="metis-shell-nav-toggle-text">Menu</span>
            </button>
            <div id="metis-template-nav-panel" class="metis-template-nav-panel" data-metis-nav-panel aria-hidden="true">
                <nav id="metis-template-primary-menu" class="metis-template-menu metis-shell-nav metis-shell-nav-primary" aria-label="Primary menu"><?php echo $menuHtml; ?></nav>
                <?php if (trim($ctaMenuHtml) !== ''): ?>
                    <div class="metis-template-menu-cta metis-shell-nav metis-shell-nav-cta" aria-label="Primary actions"><?php echo $ctaMenuHtml; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main class="metis-template-main<?php echo $withSidebar ? ' has-sidebar' : ''; ?>" data-view="homepage" data-sidebar-position="<?php echo metis_escape_attr($sidebarPosition); ?>">
        <div class="metis-template-main-intro<?php echo $withSidebar ? ' has-sidebar' : ''; ?>">
            <?php if ($heroHtml !== ''): ?>
                <section class="metis-template-region metis-template-region-hero" aria-label="Hero"><?php echo $heroHtml; ?></section>
            <?php endif; ?>
            <?php if ($withSidebar): ?>
                <aside class="metis-template-intro-panel"><?php echo $sidebarHtml; ?></aside>
            <?php endif; ?>
        </div>
        <div class="metis-template-main-inner">
            <section id="metis-template-main-content" class="metis-template-region metis-template-region-content" tabindex="-1"><?php echo $contentHtml; ?></section>
        </div>
    </main>
    <footer class="metis-template-footer" role="contentinfo">
        <div class="metis-template-footer-inner-wrap"><?php echo $footerHtml; ?></div>
    </footer>
</div>
