<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Services;

/**
 * Lightweight CMS launch readiness summary for the admin dashboard.
 */
final class CmsReadinessService {
    /**
     * @return array{score:int,total:int,state:string,public_routes_enabled:bool,items:array<int,array<string,string>>}
     */
    public static function summary(): array {
        $public_routes_enabled = self::publicRoutesEnabled();
        $homepage = HomepageService::getHomepagePage();
        $homepage_ready = $homepage !== null && (string) ( $homepage->status ?? '' ) === 'published';
        $published_pages = PageService::countAll( [ 'status' => 'published' ] );
        $published_posts = PostService::countAll( [ 'status' => 'published' ] );
        $active_template = TemplateService::getActiveTemplateSlug();
        $active_template_ready = $active_template !== '' && TemplateService::templateMeta( $active_template ) !== [];
        $primary_menu = MenuService::getByLocation( 'primary' );
        $primary_menu_ready = is_array( $primary_menu ) && MenuService::getItems( $primary_menu ) !== [];

        $items = [
            [
                'label' => 'Public routes',
                'status' => $public_routes_enabled ? 'ready' : 'attention',
                'detail' => $public_routes_enabled
                    ? 'Visitor-facing CMS routes are enabled.'
                    : 'Enable public CMS routes when launch content is ready.',
                'action_label' => 'Theme',
                'action_url' => self::portalUrl( 'theme' ),
            ],
            [
                'label' => 'Homepage',
                'status' => $homepage_ready ? 'ready' : 'blocked',
                'detail' => $homepage_ready
                    ? 'A published homepage is selected.'
                    : 'Select a published page as the homepage.',
                'action_label' => 'Pages',
                'action_url' => self::portalUrl( 'pages' ),
            ],
            [
                'label' => 'Published pages',
                'status' => $published_pages > 0 ? 'ready' : 'attention',
                'detail' => $published_pages > 0
                    ? (string) $published_pages . ' published page' . ( $published_pages === 1 ? '' : 's' ) . ' available.'
                    : 'Publish at least one page for visitors.',
                'action_label' => 'Pages',
                'action_url' => self::portalUrl( 'pages' ),
            ],
            [
                'label' => 'Posts',
                'status' => $published_posts > 0 ? 'ready' : 'attention',
                'detail' => $published_posts > 0
                    ? (string) $published_posts . ' published post' . ( $published_posts === 1 ? '' : 's' ) . ' available.'
                    : 'Posts are optional, but publishing one verifies article routing.',
                'action_label' => 'Posts',
                'action_url' => self::portalUrl( 'posts' ),
            ],
            [
                'label' => 'Template',
                'status' => $active_template_ready ? 'ready' : 'blocked',
                'detail' => $active_template_ready
                    ? 'Active template: ' . $active_template . '.'
                    : 'Select a valid CMS template.',
                'action_label' => 'Templates',
                'action_url' => self::portalUrl( 'templates' ),
            ],
            [
                'label' => 'Navigation',
                'status' => $primary_menu_ready ? 'ready' : 'attention',
                'detail' => $primary_menu_ready
                    ? 'Primary menu has items.'
                    : 'Create or assign a primary menu before launch.',
                'action_label' => 'Menus',
                'action_url' => self::portalUrl( 'menus' ),
            ],
        ];

        $score = 0;
        foreach ( $items as $item ) {
            if ( (string) $item['status'] === 'ready' ) {
                $score++;
            }
        }

        $state = 'setup';
        if ( $score >= count( $items ) ) {
            $state = 'ready';
        } elseif ( $score >= 3 ) {
            $state = 'attention';
        }

        return [
            'score' => $score,
            'total' => count( $items ),
            'state' => $state,
            'public_routes_enabled' => $public_routes_enabled,
            'items' => $items,
        ];
    }

    private static function publicRoutesEnabled(): bool {
        if ( function_exists( 'metis_get_option' ) ) {
            return (bool) \metis_get_option( 'metis_cms_public_routes_enabled', false );
        }
        if ( class_exists( '\\Core_Settings_Service' ) ) {
            return (bool) \Core_Settings_Service::get( 'metis_cms_public_routes_enabled', false );
        }

        return false;
    }

    private static function portalUrl( string $view ): string {
        return function_exists( 'metis_portal_url' )
            ? (string) \metis_portal_url( 'cms', $view )
            : '/admin/cms/' . trim( $view, '/' ) . '/';
    }
}
