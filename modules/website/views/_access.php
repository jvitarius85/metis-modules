<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_website_view_permission_map' ) ) {
    function metis_website_view_permission_map(): array {
        return [
            'dashboard' => 'website.view',
            'launch' => 'website.launch',
            'pages' => 'website.view',
            'posts' => 'website.view',
            'categories' => 'website.view',
            'editor' => 'website.edit',
            'media' => 'website.manage_media',
            'banners' => 'website.manage_banners',
            'menus' => 'website.manage_menus',
            'popups' => 'website.manage_popups',
            'redirects' => 'website.manage_redirects',
            'templates' => 'website.manage_templates',
            'webparts' => 'website.manage_webparts',
            'theme' => 'website.manage_theme',
            'import' => 'website.import',
        ];
    }
}

if ( ! function_exists( 'metis_website_user_can' ) ) {
    function metis_website_user_can( string $permission ): bool {
        return function_exists( 'metis_security_user_can' ) && metis_security_user_can( $permission );
    }
}

if ( ! function_exists( 'metis_website_require_view_permission' ) ) {
    function metis_website_require_view_permission( string $view ): bool {
        $view = function_exists( 'metis_key_clean' ) ? metis_key_clean( $view ) : preg_replace( '/[^a-z0-9_]/', '', strtolower( $view ) );
        $map = metis_website_view_permission_map();
        $permission = (string) ( $map[ $view ] ?? 'website.view' );

        if ( metis_website_user_can( $permission ) ) {
            return true;
        }

        echo '<div class="metis-alert metis-alert-error">You do not have permission to view this Website area.</div>';
        return false;
    }
}
