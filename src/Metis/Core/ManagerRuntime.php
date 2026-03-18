<?php
if (!defined('METIS_ROOT')) exit;

/**
 * Metis – Manager Renderer
 * Renders the resolved template inside the shell.
 */

function metis_render_manager() {

    $domain = metis_get_query_var('metis_domain');
    $view   = metis_get_query_var('metis_view');

    if (!$domain || !$view) {
        echo '<div class="metis-error">No domain / view specified.</div>';
        return;
    }

    $resolved = metis_resolve_view($domain, $view);

    if (!empty($resolved['error'])) {
        echo '<div class="metis-error">' . esc_html($resolved['error']) . '</div>';
        return;
    }

    // ── Auto-breadcrumb ───────────────────────────────────────────────────
    // Build breadcrumbs from module config. Templates may call
    // metis_breadcrumb() themselves (before their <h1>) to override this
    // for detail pages that need a dynamic label (e.g. a deposit code).
    // We output a placeholder div; if the template never calls
    // metis_breadcrumb(), we flush the auto-generated one after.
    // Simple approach: buffer the template, prepend auto-crumb if none present.

    $modules    = metis_get_modules();
    $mod_cfg    = $modules[ $domain ]['config'] ?? [];
    $mod_label  = function_exists( 'metis_module_label' )
        ? metis_module_label( [ 'slug' => $domain, 'config' => $mod_cfg ], (string) $domain )
        : ( $mod_cfg['label'] ?? ucfirst( $domain ) );
    $menu_items = $mod_cfg['menu']['items'] ?? [];
    $view_label = $menu_items[ $view ] ?? ucwords( str_replace( '_', ' ', $view ) );
    $portal_url = metis_portal_url();
    $domain_url = metis_portal_url( $domain );

    // parent_views maps detail view keys to their parent list view keys.
    // e.g. 'donor' => 'donors', 'person' => 'people_list'
    // Declared in each module's JSON so no hardcoding is needed here.
    $parent_views = $mod_cfg['parent_views'] ?? [];
    $parent_view  = $parent_views[ $view ] ?? null;

    // Label for the parent list link — use the menu label if the parent view
    // is in the menu, otherwise fall back to a generic "List".
    $parent_label = isset( $menu_items[ $parent_view ] )
        ? $menu_items[ $parent_view ]
        : 'List';
    $parent_url = $parent_view ? metis_portal_url( $domain, $parent_view ) : $domain_url;

    // Build the default crumb trail for this view.
    // Only suppress breadcrumbs on pure dashboard views (including portal/dashboard).
    if ( $view === 'dashboard' ) {
        // Root pages — no breadcrumb needed.
        $auto_crumbs = [];
    } elseif ( $parent_view ) {
        // Detail view — Module > List > Record
        // The record label gets appended after buffering (via metis_set_page_title).
        $auto_crumbs = [
            [ 'label' => $mod_label,    'url' => $domain_url  ],
            [ 'label' => $parent_label, 'url' => $parent_url  ],
        ];
    } else {
        // Standard sub-view — Module > View
        $auto_crumbs = [
            [ 'label' => $mod_label, 'url' => $domain_url ],
            [ 'label' => $view_label ],
        ];
    }

    // Buffer the template so we can detect whether it already emitted a breadcrumb.
    ob_start();
    if ( function_exists( 'metis_security_trusted_include' ) ) {
        metis_security_trusted_include( $resolved['template'], false );
    } else {
        include $resolved['template'];
    }
    $output = ob_get_clean();

    // If the template didn't emit its own breadcrumb, build the auto one.
    if ( ! empty( $auto_crumbs ) && strpos( $output, 'mw-breadcrumb' ) === false ) {

        // Append record title as final crumb if the template registered one.
        $page_title = metis_get_page_title();
        if ( $page_title !== '' ) {
            $auto_crumbs[] = [ 'label' => $page_title ];
        } else {
            // No record title — make the last crumb non-linked (it's the current page).
            $last = count( $auto_crumbs ) - 1;
            unset( $auto_crumbs[ $last ]['url'] );
        }

        metis_breadcrumb( $auto_crumbs );
    }

    $topic_id = sanitize_key( $domain ) . '.' . sanitize_key( $view );
    echo '<div class="metis-view-shell" data-metis-module="' . esc_attr( (string) $domain ) . '" data-metis-view="' . esc_attr( (string) $view ) . '" data-metis-topic="' . esc_attr( $topic_id ) . '">';
    echo $output;
    echo '</div>';
}
