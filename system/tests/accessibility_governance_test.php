<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$system = dirname( __DIR__ );
$failures = [];

$resolve_relative = static function ( string $relative ) use ( $system ): string {
    $normalized = ltrim( $relative, '/\\' );

    foreach ( [ 'help', 'people', 'portal', 'profile', 'settings' ] as $slug ) {
        $prefix = 'modules/' . $slug . '/';
        if ( str_starts_with( $normalized, $prefix ) ) {
            return $system . '/src/Metis/Core/BuiltInServices/' . $slug . '/' . substr( $normalized, strlen( $prefix ) );
        }
    }

    return $system . '/' . $normalized;
};

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $resolve_relative ): string {
    $contents = @file_get_contents( $resolve_relative( $relative ) );
    return is_string( $contents ) ? $contents : '';
};

$coreJs = $read( 'assets/core.js' );
$shellTemplate = $read( 'src/Metis/Core/Runtime/ShellTemplate.php' );
$accessibilityRuntime = $read( 'src/Metis/Core/AccessibilityRuntime.php' );
$settingsJs = $read( 'modules/settings/assets/settings.js' );
$newsletterJs = $read( 'modules/newsletter/assets/newsletter.js' );
$mediaJs = $read( 'modules/media/assets/media.js' );
$mediaView = $read( 'modules/media/views/library.php' );
$peopleShellJs = $read( 'modules/people/assets/js/profile-shell.js' );
$peopleSecurityJs = $read( 'modules/people/assets/js/profile-security.js' );
$peopleWorkspaceJs = $read( 'modules/people/assets/js/profile-workspace.js' );
$peopleWorkspaceView = $read( 'modules/people/views/workspace.php' );
$peopleDashboardView = $read( 'modules/people/views/dashboard.php' );
$peopleOverviewJs = $read( 'modules/people/assets/js/profile-overview.js' );
$simpleEditorJs = $read( 'assets/js/editor/simple-editor.js' );
$websitePublicNavigation = $read( 'modules/website/assets/public-navigation.js' );
$websiteTemplateLayout = $read( 'modules/website/Templates/default/layout.php' );
$websiteTemplatePage = $read( 'modules/website/Templates/default/page.php' );
$websiteTemplateHomepage = $read( 'modules/website/Templates/default/homepage.php' );
$websiteTemplatePost = $read( 'modules/website/Templates/default/post.php' );
$websiteBannersView = $read( 'modules/website/views/banners.php' );
$websitePopupsView = $read( 'modules/website/views/popups.php' );
$websiteCategoriesView = $read( 'modules/website/views/categories.php' );
$websiteRedirectsView = $read( 'modules/website/views/redirects.php' );
$websiteMenusView = $read( 'modules/website/views/menus.php' );
$websiteWebpartsView = $read( 'modules/website/views/webparts.php' );
$websiteThemeView = $read( 'modules/website/views/theme.php' );

$assert( str_contains( $coreJs, 'Metis.a11y = (function() {' ), 'Core runtime must expose the shared accessibility helper.' );
$assert( str_contains( $coreJs, 'ensureDialogSemantics' ) && str_contains( $coreJs, 'ensureControlLabels' ), 'Core accessibility helper must normalize dialog semantics and unlabeled controls.' );
$assert( str_contains( $coreJs, 'Metis.ui.modal = Metis.modal;' ), 'Shared modal runtime must remain available for accessibility-managed dialogs.' );

$assert( str_contains( $shellTemplate, 'id="metis-accessibility-toggle"' ) && str_contains( $shellTemplate, 'id="metis-accessibility-panel"' ), 'Portal shell must render the accessibility control panel.' );
$assert( str_contains( $shellTemplate, 'aria-expanded="false"' ) && str_contains( $shellTemplate, 'aria-controls="metis-accessibility-panel"' ), 'Accessibility panel trigger must expose ARIA state and control linkage.' );

$assert( str_contains( $accessibilityRuntime, 'metis_accessibility_profiles' ) && str_contains( $accessibilityRuntime, 'screen-reader' ), 'Accessibility runtime must provide named accessibility profiles, including screen-reader mode.' );
$assert( str_contains( $accessibilityRuntime, "['contrast','large_text','readable_font','reduced_motion','underline_links','nav_labels']" ) && str_contains( $accessibilityRuntime, "d.setAttribute('data-metis-'+key.replace(/_/g,'-')" ), 'Accessibility runtime bootstrap must project accessibility preferences into document state.' );

$assert( str_contains( $settingsJs, "Metis.ui.modal.form('metis-settings-media-modal');" ), 'Settings media picker must use the shared modal runtime.' );
$assert( str_contains( $settingsJs, "Metis.ui.modal.close('metis-settings-media-modal');" ), 'Settings media picker close path must use the shared modal runtime.' );

$assert( str_contains( $newsletterJs, "Metis.ui.modal.form('metis-newsletter-campaign-detail-modal');" ), 'Newsletter campaign detail modal must use the shared modal runtime.' );
$assert( str_contains( $newsletterJs, "Metis.ui.modal.form('metis-newsletter-test-send-modal');" ), 'Newsletter test-send modal must use the shared modal runtime.' );
$assert( str_contains( $newsletterJs, "Metis.ui.modal.form('metis-newsletter-prompt-modal');" ), 'Newsletter prompt dialog must use the shared modal runtime.' );
$assert( str_contains( $newsletterJs, "Metis.ui.modal.form('metis-newsletter-image-settings-modal');" ), 'Newsletter image settings dialog must use the shared modal runtime.' );
$assert( str_contains( $newsletterJs, "Metis.ui.modal.form('metis-newsletter-theme-inline-image-modal');" ), 'Newsletter theme inline image picker must use the shared modal runtime.' );
$assert( ! str_contains( $newsletterJs, ".closest('.metis-modal-backdrop').attr('aria-hidden', 'true').hide();" ), 'Newsletter must not close modals by manually hiding backdrops.' );
$assert( ! str_contains( $newsletterJs, "modal.classList.add('is-open');" ) && ! str_contains( $newsletterJs, "modal.setAttribute('aria-hidden', 'false');" ), 'Newsletter generated dialogs must not toggle modal visibility manually.' );

$assert( str_contains( $mediaJs, "Metis.ui.modal.form(id);" ) && str_contains( $mediaJs, "Metis.ui.modal.close(id);" ), 'Media library modal controls must route through the shared modal runtime.' );
$assert( str_contains( $mediaView, 'class="metis-modal-backdrop metis-media-modal-backdrop"' ), 'Media library modal markup must use the shared modal backdrop structure.' );
$assert( ! str_contains( $mediaView, 'class="metis-media-preview-modal"' ), 'Media library must not ship a private modal backdrop system.' );

$assert( str_contains( $peopleWorkspaceView, 'aria-haspopup="menu"' ) && str_contains( $peopleWorkspaceView, 'role="menu"' ) && str_contains( $peopleWorkspaceView, 'role="menuitem"' ), 'People workspace actions must expose menu semantics in markup.' );
$assert( str_contains( $peopleShellJs, 'Metis.prompt.open' ) && ! str_contains( $peopleShellJs, 'ensurePeoplePromptModal' ), 'People prompt flows must use the shared prompt runtime instead of a local modal builder.' );
$assert( str_contains( $peopleSecurityJs, 'Metis.confirm.open' ) && ! str_contains( $peopleSecurityJs, 'ensureSecurityConfirmModal' ), 'People security confirmations must use the shared confirm runtime instead of a local modal builder.' );
$assert( str_contains( $peopleWorkspaceJs, "trigger.setAttribute('aria-expanded', 'true');" ) && str_contains( $peopleWorkspaceJs, "trigger.setAttribute('aria-expanded', 'false');" ), 'People workspace action triggers must keep aria-expanded synchronized.' );
$assert( str_contains( $peopleWorkspaceJs, "if (event.key === 'Escape')") && str_contains( $peopleWorkspaceJs, "openWorkspaceActionMenu(row, 'first');" ), 'People workspace action menus must support escape close and keyboard opening.' );
$assert( str_contains( $peopleDashboardView, 'role="combobox"' ) && str_contains( $peopleDashboardView, 'aria-controls="metis-people-dashboard-results"' ) && str_contains( $peopleDashboardView, 'role="listbox"' ), 'People dashboard search must expose combobox/listbox semantics.' );
$assert( str_contains( $peopleOverviewJs, "searchInput.addEventListener('keydown'" ) && str_contains( $peopleOverviewJs, "event.key === 'ArrowDown'" ) && str_contains( $peopleOverviewJs, "event.key === 'ArrowUp'" ) && str_contains( $peopleOverviewJs, "event.key === 'Escape'" ), 'People dashboard search must support keyboard navigation and escape close.' );
$assert( str_contains( $peopleOverviewJs, "searchInput.setAttribute('aria-activedescendant'" ) && str_contains( $peopleOverviewJs, "option.setAttribute('aria-selected'" ), 'People dashboard search must synchronize active descendant and selected option state.' );
$assert( str_contains( $websiteBannersView, 'id="metis-banner-modal" class="metis-modal-backdrop" aria-hidden="true" hidden' ) && str_contains( $websiteBannersView, "Metis.ui.modal.form('metis-banner-modal');" ), 'Website banners editor must default hidden and open through the shared modal service.' );
$assert( str_contains( $websitePopupsView, 'id="metis-popup-modal" class="metis-modal-backdrop" aria-hidden="true" hidden' ) && str_contains( $websitePopupsView, "Metis.ui.modal.form('metis-popup-modal');" ), 'Website popups editor must default hidden and open through the shared modal service.' );
$assert( str_contains( $websiteCategoriesView, 'id="metis-post-category-modal"' ) && str_contains( $websiteCategoriesView, 'aria-hidden="true"' ) && str_contains( $websiteCategoriesView, 'hidden' ), 'Website category editor modal must default hidden for shared modal lifecycle control.' );
$assert( str_contains( $websiteRedirectsView, 'id="metis-redirect-modal" class="metis-modal-backdrop" aria-hidden="true" hidden' ), 'Website redirect editor modal must default hidden for shared modal lifecycle control.' );
$assert( str_contains( $websiteMenusView, 'id="metis-menu-modal" class="metis-modal-backdrop" aria-hidden="true" hidden' ), 'Website menu editor modal must default hidden for shared modal lifecycle control.' );
$assert( str_contains( $websiteWebpartsView, 'id="metis-webpart-modal" class="metis-modal-backdrop" aria-hidden="true" hidden' ), 'Website webpart editor modal must default hidden for shared modal lifecycle control.' );
$assert( str_contains( $websiteCategoriesView, 'for="metis-post-category-name"' ) && str_contains( $websiteRedirectsView, 'for="metis-redirect-source"' ) && str_contains( $websiteMenusView, 'for="metis-menu-name"' ), 'Website editor forms must provide explicit label/control linkage for primary fields.' );
$assert( str_contains( $websiteThemeView, 'function applyBox4Accessibility(scope)' ) && str_contains( $websiteThemeView, ".attr('aria-pressed', linked ? 'true' : 'false')" ) && str_contains( $websiteThemeView, ".attr('aria-label', 'Toggle linked sides for ' + fieldLabel)" ), 'Theme box controls must expose linked/unlinked state and explicit toggle labels.' );
$assert( str_contains( $websiteThemeView, ".attr('aria-label', fieldLabel + ' top')" ) && str_contains( $websiteThemeView, ".attr('aria-label', fieldLabel + ' right')" ) && str_contains( $websiteThemeView, ".attr('aria-label', fieldLabel + ' bottom')" ) && str_contains( $websiteThemeView, ".attr('aria-label', fieldLabel + ' left')" ), 'Theme box controls must expose directional input labels for assistive technology.' );
$assert( str_contains( $simpleEditorJs, 'aria-haspopup="menu"' ) && str_contains( $simpleEditorJs, 'role="menu"' ) && str_contains( $simpleEditorJs, 'role="menuitem"' ), 'Rich editor toolbar dropdowns must expose menu semantics.' );
$assert( str_contains( $simpleEditorJs, "trigger.setAttribute('aria-expanded', 'true');" ) && str_contains( $simpleEditorJs, "trigger.setAttribute('aria-expanded', 'false');" ), 'Rich editor toolbar triggers must keep aria-expanded synchronized.' );
$assert( str_contains( $simpleEditorJs, "openRichDropdown(toggleDropdown, 'first');" ) && str_contains( $simpleEditorJs, "closeRichDropdown(itemDropdown, true);" ), 'Rich editor toolbar dropdowns must support keyboard open and escape/focus-return close.' );
$assert( str_contains( $simpleEditorJs, 'aria-controls="metis-v2-preview-drawer"' ) && str_contains( $simpleEditorJs, 'aria-controls="metis-v2-revision-drawer"' ), 'Builder drawer triggers must expose controlled panel linkage.' );
$assert( str_contains( $simpleEditorJs, "openBuilderDrawer(previewDrawer, previewToggle);" ) && str_contains( $simpleEditorJs, "closeBuilderDrawer(previewDrawer);" ), 'Builder preview drawer must use the governed drawer open/close path.' );
$assert( str_contains( $simpleEditorJs, "openBlockInserter(pickerIndex, insertToggle);" ) && str_contains( $simpleEditorJs, "if (blockOverlay && !blockOverlay.hidden)") , 'Builder block inserter must preserve trigger focus and support escape close.' );
$assert( str_contains( $websitePublicNavigation, 'aria-expanded' ) && str_contains( $websitePublicNavigation, 'lastNavToggle = triggerSource;' ) && str_contains( $websitePublicNavigation, 'focusTarget.focus();' ), 'Public navigation runtime must synchronize ARIA expanded state and preserve focus on open/close.' );
$assert( str_contains( $websitePublicNavigation, 'if (e.key === "Escape") {' ) && str_contains( $websitePublicNavigation, 'closeOpenSubmenus(null);' ), 'Public navigation runtime must support escape-close semantics for open menus.' );
$assert( str_contains( $simpleEditorJs, 'drawer._metisLastFocus = trigger || document.activeElement;' ) && str_contains( $simpleEditorJs, 'window.setTimeout(function () { lastFocus.focus(); }, 0);' ), 'Builder drawers must preserve focus-return on close.' );
$assert( str_contains( $peopleWorkspaceJs, "if (event.key === 'Escape')" ) && str_contains( $peopleWorkspaceJs, 'closeWorkspaceActionMenus(' ), 'People workspace action menu must support escape-close with governed close behavior.' );
$assert( str_contains( $websiteTemplateLayout, 'class="metis-skip-link"' ) && str_contains( $websiteTemplateLayout, 'href="#metis-template-main-content"' ), 'Default public layout template must expose a skip link to main content.' );
$assert( str_contains( $websiteTemplatePage, 'class="metis-skip-link"' ) && str_contains( $websiteTemplatePage, 'id="metis-template-main-content"' ) && str_contains( $websiteTemplatePage, 'tabindex="-1"' ), 'Default page template must expose skip-link target focus semantics on main content.' );
$assert( str_contains( $websiteTemplateHomepage, 'class="metis-skip-link"' ) && str_contains( $websiteTemplateHomepage, 'id="metis-template-main-content"' ) && str_contains( $websiteTemplateHomepage, 'tabindex="-1"' ), 'Default homepage template must expose skip-link target focus semantics on main content.' );
$assert( str_contains( $websiteTemplatePost, 'class="metis-skip-link"' ) && str_contains( $websiteTemplatePost, 'id="metis-template-main-content"' ) && str_contains( $websiteTemplatePost, 'tabindex="-1"' ), 'Default post template must expose skip-link target focus semantics on main content.' );

if ( $failures !== [] ) {
    foreach ( $failures as $failure ) {
        fwrite( STDERR, $failure . "\n" );
    }
    exit( 1 );
}

echo "Accessibility governance checks passed.\n";
