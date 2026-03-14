<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$domain = get_query_var( 'metis_domain' );
$view   = get_query_var( 'metis_view' );
$portal_logo_url = function_exists( 'metis_portal_logo_url' ) ? metis_portal_logo_url() : '';
$portal_favicon = function_exists( 'metis_portal_favicon_asset' ) ? metis_portal_favicon_asset() : [];
$portal_favicon_url = function_exists( 'metis_portal_favicon_url' ) ? metis_portal_favicon_url() : '';
$portal_favicon_type = is_array( $portal_favicon ) ? (string) ( $portal_favicon['mime_type'] ?? '' ) : '';
$accessibility_ui_enabled = function_exists( 'metis_accessibility_interface_enabled' ) && metis_accessibility_interface_enabled();
$accessibility_bootstrap = function_exists( 'metis_accessibility_bootstrap_script' ) ? metis_accessibility_bootstrap_script() : '';
?>
<!DOCTYPE html>
<html lang="en">
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
    <?php if ( $accessibility_bootstrap !== '' ) : ?>
        <script><?php echo $accessibility_bootstrap; ?></script>
    <?php endif; ?>
    <?php metis_head(); ?>
</head>
<body class="metis-portal">

<a class="mw-skip-link" href="#mw-main-content">Skip to main content</a>

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
        <div class="mw-help-actions">
            <button
                type="button"
                class="mw-help-toggle"
                id="mw-help-toggle"
                data-help="help.mode"
                aria-pressed="false"
                aria-label="Toggle help mode">
                Help
            </button>
            <button
                type="button"
                class="mw-help-search-trigger"
                id="mw-help-search-trigger"
                data-help="help.search"
                aria-label="Search help">
                Search Help
            </button>
        </div>
        <?php if ( $accessibility_ui_enabled ) : ?>
            <div class="mw-accessibility">
                <button
                    type="button"
                    class="mw-accessibility-toggle"
                    id="mw-accessibility-toggle"
                    aria-expanded="false"
                    aria-controls="mw-accessibility-panel"
                    aria-label="Open accessibility settings">
                    <span aria-hidden="true">Aa</span>
                </button>
                <section class="mw-accessibility-panel" id="mw-accessibility-panel" hidden aria-label="Accessibility settings">
                    <div class="mw-accessibility-panel-header">
                        <h2>Accessibility</h2>
                        <button type="button" class="mw-accessibility-close" data-accessibility-close aria-label="Close accessibility settings">Close</button>
                    </div>
                    <div class="mw-accessibility-panel-body">
                        <div class="mw-field">
                            <label for="mw-accessibility-profile">Profile</label>
                            <select id="mw-accessibility-profile" class="mw-input" data-accessibility-profile>
                                <option value="none">Standard</option>
                                <option value="high-contrast">High Contrast</option>
                                <option value="large-text">Large Text</option>
                                <option value="readable">Readable Typography</option>
                                <option value="screen-reader">Screen Reader</option>
                            </select>
                        </div>
                        <label class="mw-accessibility-option"><input type="checkbox" data-accessibility-pref="contrast"> High contrast colors</label>
                        <label class="mw-accessibility-option"><input type="checkbox" data-accessibility-pref="large_text"> Large text</label>
                        <label class="mw-accessibility-option"><input type="checkbox" data-accessibility-pref="readable_font"> Simplified typography</label>
                        <label class="mw-accessibility-option"><input type="checkbox" data-accessibility-pref="underline_links"> Underline links</label>
                        <label class="mw-accessibility-option"><input type="checkbox" data-accessibility-pref="reduced_motion"> Reduce motion</label>
                        <label class="mw-accessibility-option"><input type="checkbox" data-accessibility-pref="nav_labels"> Expanded navigation labels</label>
                    </div>
                    <div class="mw-accessibility-panel-actions">
                        <button type="button" class="mw-btn mw-btn-ghost mw-btn-xs" data-accessibility-reset>Reset</button>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mobile hamburger -->
    <button class="mw-nav-toggle" id="mw-nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="mw-sidebar">
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
<main class="mw-main" id="mw-main-content" tabindex="-1">
    <?php metis_render_manager(); ?>
</main>

<?php if ( function_exists( 'metis_hermes_can_view' ) && metis_hermes_can_view() ) : ?>
    <div id="hermes-container" class="metis-hermes-global" aria-label="Open Hermes" role="button" tabindex="0">
        <canvas id="hermesCanvas"></canvas>
    </div>

    <section id="hermes-panel" class="metis-hermes-global-panel" hidden>
        <header class="panel-header">
            <div class="header-left">
                <div class="header-avatar" id="hermesHeaderAvatar"></div>
                <div class="metis-hermes-header-copy">
                    <span class="metis-hermes-header-title">Hermes</span>
                    <span class="metis-hermes-header-subtitle">Approval-first secure assistant</span>
                </div>
            </div>
            <div class="metis-hermes-header-links">
                <a href="<?php echo esc_url( metis_portal_url( 'hermes', 'dashboard' ) ); ?>" class="metis-hermes-full-link">Full View</a>
                <button id="closeHermes" type="button" aria-label="Close Hermes">×</button>
            </div>
        </header>

        <div class="panel-body">
            <div id="messages" class="messages"></div>

            <div class="toolbar">
                <button id="testPulse" type="button">Diagnostics</button>
            </div>

            <div class="composer">
                <input id="chatInput" type="text" placeholder="Ask Hermes something...">
                <button id="sendBtn" type="button">Send</button>
            </div>
        </div>
    </section>
<?php endif; ?>

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
        $aria_current = $is_active ? ' aria-current="page"' : '';

        if ( $type === 'single' ) {

            $html .= '<a href="' . $url . '" class="mw-sidebar-item' . $active_cls . '" data-tooltip="' . esc_attr( $label ) . '" data-help="' . esc_attr( $slug . '.dashboard' ) . '" aria-label="' . esc_attr( $label ) . '"' . $aria_current . '>'
                   . '<span class="mw-sidebar-icon">' . $icon . '</span>'
                   . '<span class="mw-sidebar-label">' . esc_html( $label ) . '</span>'
                   . '</a>';

        } elseif ( $type === 'dropdown' && ! empty( $items ) ) {

            $submenu_id = 'mw-sidebar-submenu-' . sanitize_key( $slug );
            $html .= '<div class="mw-sidebar-group' . $active_cls . '">';
            $html .= '<button type="button" class="mw-sidebar-item mw-sidebar-group-trigger' . $active_cls . '" data-help="' . esc_attr( $slug . '.dashboard' ) . '" aria-expanded="false" aria-controls="' . esc_attr( $submenu_id ) . '" aria-label="' . esc_attr( $label . ' menu' ) . '">'
                   . '<span class="mw-sidebar-icon">' . $icon . '</span>'
                   . '<span class="mw-sidebar-label">' . esc_html( $label ) . '</span>'
                   . '</button>';

            $html .= '<div class="mw-sidebar-submenu" id="' . esc_attr( $submenu_id ) . '" aria-label="' . esc_attr( $label . ' navigation' ) . '">';
            $html .= '<div class="mw-sidebar-submenu-title">' . esc_html( $label ) . '</div>';
            $html .= '<a class="mw-sidebar-subitem" data-help="' . esc_attr( $slug . '.dashboard' ) . '" href="' . $url . '"' . $aria_current . '>Overview</a>';
            foreach ( $items as $view => $view_label ) {
                $html .= '<a class="mw-sidebar-subitem" data-help="' . esc_attr( $slug . '.' . $view ) . '" href="' . esc_url( metis_portal_url( $slug, $view ) ) . '">'
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
