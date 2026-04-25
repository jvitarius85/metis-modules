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
$help_ui_enabled = true;
if ( function_exists( 'metis_help_service' ) ) {
    $help_service = metis_help_service();
    if ( $help_service instanceof Metis_Help_Service ) {
        $help_ui_enabled = $help_service->enabled();
    }
}
$shell_mode = metis_key_clean( (string) metis_get_query_var( 'metis_shell' ) );
$request_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
$is_editor_shell = $shell_mode === 'editor'
    || ( metis_key_clean( (string) $domain ) === 'website' && metis_key_clean( (string) $view ) === 'editor' )
    || preg_match( '#/website/editor(?:/|$)#i', $request_path ) === 1;
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
        echo metis_escape_html( implode( ' · ', $title_parts ) );
    ?></title>
    <?php if ( $portal_favicon_url !== '' ) : ?>
        <link rel="icon" href="<?php echo metis_escape_attr( $portal_favicon_url ); ?>"<?php echo $portal_favicon_type !== '' ? ' type="' . metis_escape_attr( $portal_favicon_type ) . '"' : ''; ?>>
        <link rel="shortcut icon" href="<?php echo metis_escape_attr( $portal_favicon_url ); ?>">
        <link rel="apple-touch-icon" href="<?php echo metis_escape_attr( $portal_favicon_url ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php endif; ?>
    <?php metis_head(); ?>
</head>
<body class="metis-portal<?php echo $is_editor_shell ? ' metis-shell-editor' : ''; ?>">

<?php if ( ! $is_editor_shell ) : ?>
<a class="metis-skip-link" href="#metis-main-content">Skip to main content</a>
<?php endif; ?>

<div id="metis-toast-container" aria-live="polite" aria-atomic="true"></div>
<?php
if ( $help_ui_enabled ) {
    $help_search_view = METIS_MODULES_PATH . 'help/views/search.php';
    if ( is_file( $help_search_view ) ) {
        require $help_search_view;
    }
}
?>

<?php if ( ! $is_editor_shell ) : ?>
<header class="metis-topbar" id="metis-topbar">
    <div class="metis-topbar-left">
        <a class="metis-topbar-logo" href="<?php echo metis_escape_url( metis_portal_url() ); ?>" aria-label="<?php echo metis_escape_attr( metis_portal_name() ); ?>">
            <?php if ( $portal_logo_url !== '' ) : ?>
                <img src="<?php echo metis_escape_attr( $portal_logo_url ); ?>" alt="<?php echo metis_escape_attr( metis_portal_name() ); ?>" class="metis-topbar-logo-img">
            <?php else : ?>
                <span class="metis-topbar-logo-text"><?php echo metis_escape_html( metis_portal_name() ); ?></span>
            <?php endif; ?>
        </a>
        <?php if ( $domain !== '' ) : ?>
            <span class="metis-topbar-sep">|</span>
            <span class="metis-topbar-module"><?php echo metis_escape_html( $module_label ); ?></span>
        <?php endif; ?>
    </div>

    <div class="metis-topbar-right">
        <div class="metis-code-search" id="metis-code-search">
            <label class="screen-reader-text" for="metis-code-input">Jump to code</label>
            <input
                type="text"
                id="metis-code-input"
                class="metis-code-input"
                placeholder="Jump to code..."
                spellcheck="false"
                autocomplete="off"
                maxlength="24"
                title="Search by object code (e.g. DTX-004238)"
            >
            <span class="metis-code-search-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <div class="metis-code-result" id="metis-code-result" style="display:none;" role="status" aria-live="polite"></div>
        </div>
        <div class="metis-topbar-controls">
            <div class="metis-help-actions">
                <div class="metis-quick-actions" id="metis-quick-actions">
                    <button
                        type="button"
                        class="metis-help-search-trigger"
                        id="metis-quick-actions-trigger"
                        aria-label="Open quick actions"
                        aria-haspopup="menu"
                        aria-expanded="false"
                        aria-controls="metis-quick-actions-panel"
                    >
                        Quick Actions
                    </button>
                    <div class="metis-quick-actions-panel" id="metis-quick-actions-panel" role="menu" aria-label="Quick actions" hidden>
                        <div id="metis-quick-actions-list" aria-label="Quick actions"></div>
                    </div>
                </div>
                <?php if ( $help_ui_enabled ) : ?>
                    <button
                        type="button"
                        class="metis-help-toggle"
                        id="metis-help-toggle"
                        data-help="help.mode"
                        aria-pressed="false"
                        aria-label="Toggle help mode"
                    >
                        Help
                    </button>
                    <button
                        type="button"
                        class="metis-help-search-trigger"
                        id="metis-help-search-trigger"
                        data-help="help.search"
                        aria-label="Search help"
                    >
                        Search Help
                    </button>
                <?php endif; ?>
            </div>
            <?php if ( $accessibility_ui_enabled ) : ?>
                <div class="metis-accessibility">
                    <button
                        type="button"
                        class="metis-accessibility-toggle"
                        id="metis-accessibility-toggle"
                        aria-expanded="false"
                        aria-controls="metis-accessibility-panel"
                        aria-haspopup="dialog"
                        aria-label="Open accessibility settings"
                    >
                        <span aria-hidden="true">Aa</span>
                    </button>
                    <section class="metis-accessibility-panel" id="metis-accessibility-panel" hidden aria-labelledby="metis-accessibility-panel-title">
                        <div class="metis-accessibility-panel-header">
                            <h2 id="metis-accessibility-panel-title">Accessibility</h2>
                            <button type="button" class="metis-accessibility-close" data-accessibility-close aria-label="Close accessibility settings">Close</button>
                        </div>
                        <div class="metis-accessibility-panel-body">
                            <div class="metis-field">
                                <label for="metis-accessibility-profile">Profile</label>
                                <select id="metis-accessibility-profile" class="metis-input" data-accessibility-profile>
                                    <option value="none">Standard</option>
                                    <option value="high-contrast">High Contrast</option>
                                    <option value="large-text">Large Text</option>
                                    <option value="readable">Readable Typography</option>
                                    <option value="screen-reader">Screen Reader</option>
                                </select>
                            </div>
                            <label class="metis-accessibility-option"><input type="checkbox" data-accessibility-pref="contrast"> High contrast colors</label>
                            <label class="metis-accessibility-option"><input type="checkbox" data-accessibility-pref="large_text"> Large text</label>
                            <label class="metis-accessibility-option"><input type="checkbox" data-accessibility-pref="readable_font"> Simplified typography</label>
                            <label class="metis-accessibility-option"><input type="checkbox" data-accessibility-pref="underline_links"> Underline links</label>
                            <label class="metis-accessibility-option"><input type="checkbox" data-accessibility-pref="reduced_motion"> Reduce motion</label>
                            <label class="metis-accessibility-option"><input type="checkbox" data-accessibility-pref="nav_labels"> Expanded navigation labels</label>
                        </div>
                        <div class="metis-accessibility-panel-actions">
                            <button type="button" class="metis-btn metis-btn-ghost metis-btn-xs" data-accessibility-reset>Reset</button>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <button class="metis-nav-toggle" id="metis-nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="metis-sidebar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>
</header>

<aside class="metis-sidebar" id="metis-sidebar" role="navigation" aria-label="Main navigation">
    <div class="metis-sidebar-top">
        <a class="metis-sidebar-item metis-sidebar-profile" href="<?php echo metis_escape_url( metis_portal_url( 'profile' ) ); ?>" data-tooltip="<?php echo metis_escape_attr( $user_name ); ?>">
            <span class="metis-sidebar-avatar-wrap">
                <img class="metis-sidebar-avatar" src="<?php echo metis_escape_url( (string) ( $user_avatar_payload['url'] ?? '' ) ); ?>" alt="<?php echo metis_escape_attr( $user_name !== '' ? $user_name : 'Profile' ); ?>">
            </span>
            <span class="metis-sidebar-label"><?php echo metis_escape_html( $user_name ); ?></span>
        </a>
    </div>

    <nav class="metis-sidebar-nav" aria-label="Primary modules">
        <div class="metis-sidebar-nav-scroll">
            <?php echo metis_sidebar_nav( metis_get_modules(), $domain ); ?>
        </div>
    </nav>

    <div class="metis-sidebar-footer">
        <?php echo function_exists( 'metis_sidebar_logout_nav' ) ? metis_sidebar_logout_nav( (string) $domain ) : ''; ?>
    </div>

</aside>

<div class="metis-sidebar-overlay" id="metis-sidebar-overlay"></div>
<?php endif; ?>

<main class="metis-main" id="metis-main-content" tabindex="-1">
    <?php metis_render_manager(); ?>
</main>
<?php if ( is_array( $auth_notice ) && ! empty( $auth_notice['message'] ) ) : ?>
    <script>
        window.metisAuthNotice = <?php echo metis_runtime_json_encode( [
            'message' => (string) ( $auth_notice['message'] ?? '' ),
            'type' => (string) ( $auth_notice['type'] ?? 'info' ),
        ] ); ?>;
    </script>
<?php endif; ?>

<?php if ( ! $is_editor_shell && function_exists( 'metis_hermes_can_view' ) && metis_hermes_can_view() ) : ?>
    <div id="hermes-container" class="metis-hermes-global" aria-label="Open Hermes" role="button" tabindex="0">
        <canvas id="hermesCanvas"></canvas>
    </div>

    <section id="hermes-panel" class="metis-hermes-global-panel" hidden>
        <header class="panel-header">
            <div class="header-left">
                <div class="header-avatar" id="hermesHeaderAvatar"></div>
                <div class="metis-hermes-header-copy">
                    <span class="metis-hermes-header-title">Hermes</span>
                </div>
            </div>
            <div class="metis-hermes-header-links">
                <button id="closeHermes" type="button" aria-label="Close Hermes">
                    <span class="metis-hermes-close-icon" aria-hidden="true">
                        <?php echo function_exists( 'metis_navigation_icon_markup' ) ? metis_navigation_icon_markup( 'x_circle' ) : ''; ?>
                    </span>
                </button>
            </div>
        </header>

        <div class="panel-body">
            <div id="messages" class="messages"></div>

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
