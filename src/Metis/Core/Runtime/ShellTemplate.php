<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_sidebar_nav' ) ) {
    require_once dirname( __DIR__ ) . '/CoreHelpers.php';
}

$domain = metis_get_query_var( 'metis_domain' );
$view   = metis_get_query_var( 'metis_view' );
$portal_logo_url = function_exists( 'metis_portal_logo_url' ) ? metis_portal_logo_url() : '';
$portal_favicon = function_exists( 'metis_portal_favicon_asset' ) ? metis_portal_favicon_asset() : [];
$portal_favicon_url = function_exists( 'metis_portal_favicon_url' ) ? metis_portal_favicon_url() : '';
$portal_favicon_type = is_array( $portal_favicon ) ? (string) ( $portal_favicon['mime_type'] ?? '' ) : '';
$accessibility_ui_enabled = function_exists( 'metis_accessibility_interface_enabled' ) && metis_accessibility_interface_enabled();
$user = metis_runtime_current_user();
$user_name = trim( (string) ( $user->display_name ?? '' ) );
if ( $user_name === '' ) {
    $user_name = trim( (string) ( $user->first_name ?? '' ) . ' ' . (string) ( $user->last_name ?? '' ) );
}
$current_person_id = function_exists( 'metis_auth_current_person_id' ) ? (int) metis_auth_current_person_id() : 0;
$current_person = function_exists( 'metis_profile_current_person' ) ? metis_profile_current_person() : null;
$current_person_avatar_key = is_array( $current_person ) ? (string) ( $current_person['pid'] ?? '' ) : '';
$current_person_avatar_url = is_array( $current_person ) ? (string) ( $current_person['avatar_url'] ?? '' ) : '';
$module_config = function_exists( 'metis_get_module' ) ? metis_get_module( $domain ) : [];
$module_label = is_array( $module_config ) && function_exists( 'metis_module_label' )
    ? metis_module_label( $module_config, (string) $domain )
    : '';
if ( $module_label === '' && $domain !== '' ) {
    $module_label = ucwords( str_replace( '_', ' ', $domain ) );
}
$user_avatar_payload = function_exists( 'metis_avatar_payload' )
    ? metis_avatar_payload( $user_name !== '' ? $user_name : 'Metis User', $current_person_avatar_url, 64, $current_person_avatar_key )
    : [ 'type' => 'initials', 'url' => '', 'initials' => 'MU', 'name' => $user_name ];
$auth_notice = function_exists( 'metis_auth_consume_flash_notice' ) ? metis_auth_consume_flash_notice() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php
        $title_parts = [ metis_portal_name() ];
        if ( $module_label !== '' ) {
            $title_parts[] = $module_label;
        }
        if ( $view !== '' ) {
            $title_parts[] = ucwords( str_replace( '_', ' ', $view ) );
        }
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

<a class="mw-skip-link" href="#mw-main-content">Skip to main content</a>

<div id="mw-toast-container" aria-live="polite" aria-atomic="true"></div>

<header class="mw-topbar" id="mw-topbar">
    <div class="mw-topbar-left">
        <a class="mw-topbar-logo" href="<?php echo esc_url( metis_portal_url() ); ?>" aria-label="<?php echo esc_attr( metis_portal_name() ); ?>">
            <?php if ( $portal_logo_url !== '' ) : ?>
                <img src="<?php echo esc_attr( $portal_logo_url ); ?>" alt="<?php echo esc_attr( metis_portal_name() ); ?>" class="mw-topbar-logo-img">
            <?php else : ?>
                <span class="mw-topbar-logo-text"><?php echo esc_html( metis_portal_name() ); ?></span>
            <?php endif; ?>
        </a>
        <?php if ( $domain !== '' ) : ?>
            <span class="mw-topbar-sep">|</span>
            <span class="mw-topbar-module"><?php echo esc_html( $module_label ); ?></span>
        <?php endif; ?>
    </div>

    <div class="mw-topbar-right">
        <div class="mw-code-search" id="metis-code-search">
            <input
                type="text"
                id="metis-code-input"
                class="mw-code-input"
                placeholder="Jump to code..."
                spellcheck="false"
                autocomplete="off"
                maxlength="24"
                title="Search by object code (e.g. DTX-004238)"
            >
            <span class="mw-code-search-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <div class="mw-code-result" id="metis-code-result" style="display:none;"></div>
        </div>
        <div class="mw-topbar-controls">
            <div class="mw-help-actions">
                <button
                    type="button"
                    class="mw-help-toggle"
                    id="mw-help-toggle"
                    data-help="help.mode"
                    aria-pressed="false"
                    aria-label="Toggle help mode"
                >
                    Help
                </button>
                <button
                    type="button"
                    class="mw-help-search-trigger"
                    id="mw-help-search-trigger"
                    data-help="help.search"
                    aria-label="Search help"
                >
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
                        aria-label="Open accessibility settings"
                    >
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
    </div>

    <button class="mw-nav-toggle" id="mw-nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="mw-sidebar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>
</header>

<aside class="mw-sidebar" id="mw-sidebar" role="navigation" aria-label="Main navigation">
    <div class="mw-sidebar-top">
        <a class="mw-sidebar-item mw-sidebar-profile" href="<?php echo esc_url( metis_portal_url( 'profile' ) ); ?>" data-tooltip="<?php echo esc_attr( $user_name ); ?>">
            <span class="mw-sidebar-avatar-wrap">
                <img class="mw-sidebar-avatar" src="<?php echo esc_url( (string) ( $user_avatar_payload['url'] ?? '' ) ); ?>" alt="<?php echo esc_attr( $user_name !== '' ? $user_name : 'Profile' ); ?>">
            </span>
            <span class="mw-sidebar-label"><?php echo esc_html( $user_name ); ?></span>
        </a>
    </div>

    <nav class="mw-sidebar-nav" aria-label="Primary modules">
        <div class="mw-sidebar-nav-scroll">
            <?php echo metis_sidebar_nav( metis_get_modules(), $domain ); ?>
        </div>
    </nav>

    <div class="mw-sidebar-footer">
        <a class="mw-sidebar-item mw-sidebar-logout" href="<?php echo esc_url( function_exists( 'metis_auth_logout_url' ) ? metis_auth_logout_url() : metis_home_url( '/logout' ) ); ?>" data-tooltip="Log out">
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

<div class="mw-sidebar-overlay" id="mw-sidebar-overlay"></div>

<main class="mw-main" id="mw-main-content" tabindex="-1">
    <?php if ( is_array( $auth_notice ) && ! empty( $auth_notice['message'] ) ) : ?>
        <div class="mw-alert <?php echo (string) ( $auth_notice['type'] ?? 'info' ) === 'error' ? 'mw-alert-error' : 'mw-alert-success'; ?>">
            <?php echo esc_html( (string) $auth_notice['message'] ); ?>
        </div>
    <?php endif; ?>
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
                <button id="closeHermes" type="button" aria-label="Close Hermes">x</button>
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
