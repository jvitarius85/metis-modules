<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$domain = get_query_var( 'metis_domain' );
$view   = get_query_var( 'metis_view' );
$portal_logo_url = function_exists( 'metis_portal_logo_url' ) ? metis_portal_logo_url() : '';
$portal_favicon = function_exists( 'metis_portal_favicon_asset' ) ? metis_portal_favicon_asset() : [];
$portal_favicon_url = function_exists( 'metis_portal_favicon_url' ) ? metis_portal_favicon_url() : '';
$portal_favicon_type = is_array( $portal_favicon ) ? (string) ( $portal_favicon['mime_type'] ?? '' ) : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php
        $title_parts = [ metis_portal_name() ];
        if ( $domain !== '' ) $title_parts[] = ucfirst( $domain );
        if ( $view   !== '' ) $title_parts[] = ucwords( str_replace( '_', ' ', $view ) );
        echo esc_html( implode( ' · ', $title_parts ) );
    ?></title>
    <?php if ( $portal_favicon_url !== '' ) : ?>
        <link rel="icon" href="<?php echo esc_attr( $portal_favicon_url ); ?>"<?php echo $portal_favicon_type !== '' ? ' type="' . esc_attr( $portal_favicon_type ) . '"' : ''; ?>>
        <link rel="shortcut icon" href="<?php echo esc_attr( $portal_favicon_url ); ?>">
        <link rel="apple-touch-icon" href="<?php echo esc_attr( $portal_favicon_url ); ?>">
    <?php endif; ?>
    <?php metis_head(); ?>
</head>
<body class="metis-portal">

<!-- Toast container -->
<div id="mw-toast-container" aria-live="polite" aria-atomic="true"></div>

<!-- ============================================================
     TOPBAR — full width, sits above sidebar
     ============================================================ -->
<header class="mw-topbar" id="mw-topbar">

    <div class="mw-topbar-left">
        <a class="mw-topbar-logo" href="<?php echo esc_url( metis_portal_url() ); ?>" aria-label="<?php echo esc_attr( metis_portal_name() ); ?>">
            <?php if ( $portal_logo_url !== '' ) : ?>
                <img src="<?php echo esc_attr( $portal_logo_url ); ?>"
                     alt="<?php echo esc_attr( metis_portal_name() ); ?>" class="mw-topbar-logo-img">
            <?php else : ?>
                <span class="mw-topbar-logo-text"><?php echo esc_html( metis_portal_name() ); ?></span>
            <?php endif; ?>
        </a>
        <?php if ( $domain !== '' ) : ?>
            <span class="mw-topbar-sep">|</span>
            <span class="mw-topbar-module"><?php echo esc_html( ucfirst( $domain ) ); ?></span>
        <?php endif; ?>
    </div>

    <div class="mw-topbar-right">
        <div class="mw-code-search" id="metis-code-search">
            <input type="text"
                   id="metis-code-input"
                   class="mw-code-input"
                   placeholder="Jump to code…"
                   spellcheck="false"
                   autocomplete="off"
                   maxlength="24"
                   title="Search by object code (e.g. DTX-004238)">
            <span class="mw-code-search-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <div class="mw-code-result" id="metis-code-result" style="display:none;"></div>
        </div>
    </div>

    <!-- Mobile hamburger -->
    <button class="mw-nav-toggle" id="mw-nav-toggle" aria-label="Open menu" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="3" y1="6"  x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>

</header>

<!-- ============================================================
     SIDEBAR NAV
     ============================================================ -->
<aside class="mw-sidebar" id="mw-sidebar" role="navigation" aria-label="Main navigation">

    <!-- Spacer pushes nav icons below the topbar -->
    <div class="mw-sidebar-top-spacer"></div>

    <!-- Nav items -->
    <nav class="mw-sidebar-nav">
        <?php echo metis_sidebar_nav( metis_get_modules(), $domain ); ?>
    </nav>

    <!-- Footer: profile + logout -->
    <div class="mw-sidebar-footer">
        <?php
        $user     = metis_current_user();
        $initials = '';
        if ( $user->first_name ) $initials .= strtoupper( $user->first_name[0] );
        if ( $user->last_name  ) $initials .= strtoupper( $user->last_name[0] );
        if ( ! $initials ) $initials = strtoupper( substr( $user->display_name, 0, 2 ) );
        ?>
        <a class="mw-sidebar-item mw-sidebar-profile"
           href="<?php echo esc_url( metis_portal_url( 'profile' ) ); ?>"
           data-tooltip="<?php echo esc_attr( $user->display_name ); ?>">
            <span class="mw-sidebar-avatar"><?php echo esc_html( $initials ); ?></span>
            <span class="mw-sidebar-label"><?php echo esc_html( $user->display_name ); ?></span>
        </a>
        <a class="mw-sidebar-item mw-sidebar-logout"
           href="<?php echo esc_url( metis_logout_url() ); ?>"
           data-tooltip="Log out">
            <span class="mw-sidebar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </span>
            <span class="mw-sidebar-label">Log out</span>
        </a>
    </div>

</aside>

<!-- Mobile overlay -->
<div class="mw-sidebar-overlay" id="mw-sidebar-overlay"></div>

<!-- ============================================================
     MAIN CONTENT
     ============================================================ -->
<main class="mw-main">
    <?php metis_render_manager(); ?>
</main>

<?php metis_footer(); ?>
</body>
</html>

<?php
// =========================================================================
// Sidebar nav builder
// =========================================================================

function metis_sidebar_nav( array $modules, string $active_domain ): string {

    if ( empty( $modules ) ) return '';

    $ordered = function_exists( 'metis_order_modules_for_navigation' )
        ? metis_order_modules_for_navigation( $modules )
        : $modules;

    $html = '';
    $fallback_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>';

    foreach ( $ordered as $slug => $mod ) {
        if ( $slug === 'profile' ) continue;

        $cfg        = $mod['config'] ?? [];
        $label      = $cfg['label'] ?? ucfirst( $slug );
        $menu       = $cfg['menu']  ?? [ 'type' => 'single' ];
        $type       = $menu['type']  ?? 'single';
        $items      = $menu['items'] ?? [];
        $is_active  = ( $slug === $active_domain );
        // Icon comes from the module's JSON config — no hardcoding here
        $icon       = $cfg['icon'] ?? $fallback_icon;
        $url        = esc_url( metis_portal_url( $slug ) );
        $active_cls = $is_active ? ' is-active' : '';

        if ( $type === 'single' ) {

            $html .= '<a href="' . $url . '" class="mw-sidebar-item' . $active_cls . '" data-tooltip="' . esc_attr( $label ) . '">'
                   . '<span class="mw-sidebar-icon">' . $icon . '</span>'
                   . '<span class="mw-sidebar-label">' . esc_html( $label ) . '</span>'
                   . '</a>';

        } elseif ( $type === 'dropdown' && ! empty( $items ) ) {

            // Outer group — JS hover triggers the flyout; icon click navigates to module root
            $html .= '<div class="mw-sidebar-group' . $active_cls . '">';
            $html .= '<a href="' . $url . '" class="mw-sidebar-item mw-sidebar-group-trigger' . $active_cls . '">'
                   . '<span class="mw-sidebar-icon">' . $icon . '</span>'
                   . '</a>';

            // Flyout panel — positioned to the right of the sidebar via CSS/JS
            $html .= '<div class="mw-sidebar-submenu">';
            $html .= '<div class="mw-sidebar-submenu-title">' . esc_html( $label ) . '</div>';
            foreach ( $items as $view => $view_label ) {
                $html .= '<a class="mw-sidebar-subitem" href="' . esc_url( metis_portal_url( $slug, $view ) ) . '">'
                       . esc_html( $view_label ) . '</a>';
            }
            $html .= '</div>'; // .mw-sidebar-submenu
            $html .= '</div>'; // .mw-sidebar-group
        }
    }

    return $html;
}

// Backward-compat alias
function metis_nav_build( array $modules, string $active_domain ): string {
    return metis_sidebar_nav( $modules, $active_domain );
}
