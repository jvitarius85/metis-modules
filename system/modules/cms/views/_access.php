<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_cms_view_permission_map' ) ) {
    function metis_cms_view_permission_map(): array {
        return [
            'dashboard' => 'cms.view',
            'pages' => 'cms.view',
            'posts' => 'cms.view',
            'categories' => 'cms.view',
            'editor' => 'cms.edit',
            'media' => 'cms.manage_media',
            'banners' => 'cms.manage_banners',
            'menus' => 'cms.manage_menus',
            'popups' => 'cms.manage_popups',
            'redirects' => 'cms.manage_redirects',
            'templates' => 'cms.manage_templates',
            'theme' => 'cms.manage_theme',
            'import' => 'cms.import',
        ];
    }
}

if ( ! function_exists( 'metis_cms_user_can' ) ) {
    function metis_cms_user_can( string $permission ): bool {
        return function_exists( 'metis_security_user_can' ) && metis_security_user_can( $permission );
    }
}

if ( ! function_exists( 'metis_cms_require_view_permission' ) ) {
    function metis_cms_require_view_permission( string $view ): bool {
        $view = function_exists( 'metis_key_clean' ) ? metis_key_clean( $view ) : preg_replace( '/[^a-z0-9_]/', '', strtolower( $view ) );
        $map = metis_cms_view_permission_map();
        $permission = (string) ( $map[ $view ] ?? 'cms.view' );

        if ( metis_cms_user_can( $permission ) ) {
            return true;
        }

        echo '<div class="metis-alert metis-alert-error">You do not have permission to view this CMS area.</div>';
        return false;
    }
}
