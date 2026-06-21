<?php
declare(strict_types=1);

namespace Metis\Modules\Help;

if ( class_exists( __NAMESPACE__ . '\\HelpModule', false ) ) {
    return;
}

use Metis\Core\HelpSearchStore;
use Metis\Core\Application;
use Metis\Http\Request;
use Metis\Http\Response;

final class HelpModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_on( 'init', [ self::class, 'ensureRuntimeSeeded' ], 6 );
    }

    public static function ensureRuntimeSeeded(): void {
        $seed = static function (): void {
            try {
                ( new HelpSearchStore() )->ensureSeeded();
            } catch ( \Throwable $e ) {
                if ( class_exists( 'Metis_Logger' ) ) {
                    \Metis_Logger::warn( 'help.seed_failed', [ 'message' => $e->getMessage() ] );
                }
            }
        };

        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'help_seed',
                [ __FILE__, dirname( __DIR__, 2 ) . '/Core/HelpSearchStore.php' ],
                $seed
            );
            return;
        }

        $seed();
    }

    public static function handleIndexRoute( Request $request ): Response {
        if ( $redirect = self::requireViewAccess() ) {
            return $redirect;
        }

        $store = new HelpSearchStore();
        return self::shellResponse(
            'library',
            [
                'page_kind' => 'landing',
                'page_title' => 'How can we help?',
                'page_subtitle' => 'Search practical guidance for Metis modules, account access, admin workflows, and common fixes.',
                'landing' => $store->landingData(),
                'search_query' => '',
                'search_category' => '',
                'tree' => $store->navigationTree(),
            ]
        );
    }

    public static function handleSearchRoute( Request $request ): Response {
        if ( $redirect = self::requireViewAccess() ) {
            return $redirect;
        }

        $query = trim( (string) ( $request->query()['q'] ?? $request->query()['query'] ?? '' ) );
        $category = trim( (string) ( $request->query()['category'] ?? '' ) );
        $page = max( 1, (int) ( $request->query()['page'] ?? 1 ) );

        $store = new HelpSearchStore();
        return self::shellResponse(
            'search',
            [
                'page_kind' => 'search',
                'page_title' => 'Help Search',
                'page_subtitle' => 'Search the Help library by title, module, action, or likely user phrase.',
                'results' => $store->search( $query, $category, 12, $page, false ),
                'categories' => $store->categorySummaries(),
                'search_query' => $query,
                'search_category' => $category,
                'tree' => $store->navigationTree(),
                'active_category_slug' => $category,
            ]
        );
    }

    public static function handleArticleRoute( Request $request ): Response {
        if ( $redirect = self::requireViewAccess() ) {
            return $redirect;
        }

        $slug = (string) $request->attribute( 'slug', '' );
        $store = new HelpSearchStore();
        $article = $store->articleBySlug( $slug, false );
        if ( ! is_array( $article ) ) {
            return self::errorResponse( 404, 'Help Article Not Found', 'The requested help article could not be found.' );
        }

        return self::shellResponse(
            'article',
            [
                'page_kind' => 'article',
                'page_title' => (string) $article['title'],
                'page_subtitle' => (string) ( $article['summary'] ?? '' ),
                'article' => $article,
                'related_articles' => $store->relatedArticles( (int) $article['id'], (int) $article['category_id'], 4 ),
                'tree' => $store->navigationTree(),
                'active_category_slug' => (string) ( $article['category_slug'] ?? '' ),
                'active_article_slug' => (string) ( $article['slug'] ?? '' ),
            ]
        );
    }

    public static function handleCategoryRoute( Request $request ): Response {
        if ( $redirect = self::requireViewAccess() ) {
            return $redirect;
        }

        $slug = (string) $request->attribute( 'slug', '' );
        $page = max( 1, (int) ( $request->query()['page'] ?? 1 ) );
        $store = new HelpSearchStore();
        $category = $store->categoryBySlug( $slug );
        if ( ! is_array( $category ) ) {
            return self::errorResponse( 404, 'Help Category Not Found', 'The requested help category could not be found.' );
        }

        return self::shellResponse(
            'category',
            [
                'page_kind' => 'category',
                'page_title' => (string) $category['name'],
                'page_subtitle' => 'Browse help articles in this category.',
                'category' => $category,
                'results' => $store->articlesForCategory( $slug, 12, $page ),
                'tree' => $store->navigationTree(),
                'active_category_slug' => (string) $category['slug'],
            ]
        );
    }

    public static function handleAdminArticlesRoute( Request $request ): Response {
        if ( $redirect = self::requireManageAccess() ) {
            return $redirect;
        }

        $store = new HelpSearchStore();
        $search = trim( (string) ( $request->query()['q'] ?? '' ) );
        $category = trim( (string) ( $request->query()['category'] ?? '' ) );
        $status = trim( (string) ( $request->query()['status'] ?? '' ) );
        $page = max( 1, (int) ( $request->query()['page'] ?? 1 ) );

        return self::shellResponse(
            'articles',
            [
                'page_kind' => 'admin_list',
                'page_title' => 'Help Articles',
                'page_subtitle' => 'Search, review, and manage the Help library.',
                'admin_content' => self::renderTemplate(
                    (string) \Metis\Core\ModulePathRegistry::modulePath( 'help' ) . '/admin/articles.php',
                    [
                        'mode' => 'list',
                        'listing' => $store->adminList( $search, $category, $status, $page, 20 ),
                        'article' => null,
                        'filters' => [
                            'q' => $search,
                            'category' => $category,
                            'status' => $status,
                        ],
                        'categories' => $store->categorySummaries(),
                        'preview_requested' => false,
                        'save_nonce' => \metis_runtime_create_nonce( 'metis_help_article_save' ),
                        'publish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_publish' ),
                        'unpublish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_unpublish' ),
                        'rebuild_nonce' => \metis_runtime_create_nonce( 'metis_help_index_rebuild' ),
                    ]
                ),
                'tree' => $store->navigationTree(),
                'search_query' => $search,
                'active_category_slug' => $category,
            ]
        );
    }

    public static function handleAdminCreateRoute( Request $request ): Response {
        if ( $redirect = self::requireManageAccess() ) {
            return $redirect;
        }

        $store = new HelpSearchStore();

        return self::shellResponse(
            'editor',
            [
                'page_kind' => 'admin_editor',
                'page_title' => 'Create Help Article',
                'page_subtitle' => 'Draft or publish a help article in the Help library.',
                'admin_content' => self::renderTemplate(
                    (string) \Metis\Core\ModulePathRegistry::modulePath( 'help' ) . '/admin/articles.php',
                    [
                        'mode' => 'create',
                        'listing' => null,
                        'article' => [
                            'id' => 0,
                            'title' => '',
                            'slug' => '',
                            'summary' => '',
                            'content' => '',
                            'category_id' => 0,
                            'category_slug' => '',
                            'status' => 'draft',
                            'tags' => [],
                            'search_terms' => '',
                            'system_seeded' => 0,
                        ],
                        'filters' => [],
                        'categories' => $store->categorySummaries(),
                        'preview_requested' => (string) ( $request->query()['preview'] ?? '' ) === '1',
                        'save_nonce' => \metis_runtime_create_nonce( 'metis_help_article_save' ),
                        'publish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_publish' ),
                        'unpublish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_unpublish' ),
                        'rebuild_nonce' => \metis_runtime_create_nonce( 'metis_help_index_rebuild' ),
                    ]
                ),
                'tree' => $store->navigationTree(),
            ]
        );
    }

    public static function handleAdminIssueResolutionRoute( Request $request ): Response {
        if ( $redirect = self::requireManageAccess() ) {
            return $redirect;
        }

        $coverage = Application::service( 'hermes_repository' )->helpIssueCoverage( 25 );

        return self::shellResponse(
            'issue-resolution',
            [
                'page_kind' => 'admin_issue_resolution',
                'page_title' => 'Hermes Issue Resolution',
                'page_subtitle' => 'Review unresolved phrases, weak classifications, and help search coverage gaps.',
                'admin_content' => self::renderTemplate(
                    (string) \Metis\Core\ModulePathRegistry::modulePath( 'help' ) . '/admin/issue-resolution.php',
                    [
                        'coverage' => $coverage,
                        'rebuild_nonce' => \metis_runtime_create_nonce( 'metis_help_index_rebuild' ),
                    ]
                ),
                'tree' => ( new HelpSearchStore() )->navigationTree(),
            ]
        );
    }

    public static function handleAdminEditRoute( Request $request ): Response {
        if ( $redirect = self::requireManageAccess() ) {
            return $redirect;
        }

        $store = new HelpSearchStore();
        $article = $store->articleById( (int) $request->attribute( 'id', 0 ), true );
        if ( ! is_array( $article ) ) {
            return self::errorResponse( 404, 'Help Article Not Found', 'The requested help article could not be found.' );
        }

        return self::shellResponse(
            'editor',
            [
                'page_kind' => 'admin_editor',
                'page_title' => 'Edit Help Article',
                'page_subtitle' => 'Update article content, search terms, and publication state.',
                'admin_content' => self::renderTemplate(
                    (string) \Metis\Core\ModulePathRegistry::modulePath( 'help' ) . '/admin/articles.php',
                    [
                        'mode' => 'edit',
                        'listing' => null,
                        'article' => $article,
                        'filters' => [],
                        'categories' => $store->categorySummaries(),
                        'preview_requested' => (string) ( $request->query()['preview'] ?? '' ) === '1',
                        'save_nonce' => \metis_runtime_create_nonce( 'metis_help_article_save' ),
                        'publish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_publish' ),
                        'unpublish_nonce' => \metis_runtime_create_nonce( 'metis_help_article_unpublish' ),
                        'rebuild_nonce' => \metis_runtime_create_nonce( 'metis_help_index_rebuild' ),
                    ]
                ),
                'tree' => $store->navigationTree(),
                'active_category_slug' => (string) ( $article['category_slug'] ?? '' ),
                'active_article_slug' => (string) ( $article['slug'] ?? '' ),
            ]
        );
    }

    private static function requireViewAccess(): ?Response {
        if ( ! function_exists( 'metis_user_logged_in' ) || ! \metis_user_logged_in() ) {
            return Response::redirect( self::loginUrl() );
        }

        if ( ! function_exists( 'metis_security_user_can' ) || ! \metis_security_user_can( 'help.view' ) ) {
            return self::errorResponse( 403, 'Permission Denied', 'You do not have permission to view Help articles.' );
        }

        return null;
    }

    private static function requireManageAccess(): ?Response {
        if ( $redirect = self::requireViewAccess() ) {
            return $redirect;
        }

        if ( ! function_exists( 'metis_security_user_can' ) || ! \metis_security_user_can( 'help.manage' ) ) {
            return self::errorResponse( 403, 'Permission Denied', 'You do not have permission to manage Help articles.' );
        }

        return null;
    }

    private static function errorResponse( int $status, string $title, string $message ): Response {
        return self::shellResponse(
            'error',
            [
                'page_kind' => 'error',
                'page_title' => $title,
                'page_subtitle' => $message,
                'tree' => [],
            ],
            $status
        );
    }

    private static function shellResponse( string $view, array $state, int $status = 200 ): Response {
        \metis_set_query_var( 'metis_domain', 'help' );
        \metis_set_query_var( 'metis_view', $view );
        \metis_set_query_var( 'metis_help_state', $state );

        if ( ! empty( $state['page_title'] ) && function_exists( 'metis_set_page_title' ) ) {
            \metis_set_page_title( (string) $state['page_title'] );
        }

        if ( function_exists( 'nocache_headers' ) ) {
            \nocache_headers();
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        $shell = \METIS_SRC_PATH . 'Metis/Core/Runtime/ShellTemplate.php';
        if ( ! file_exists( $shell ) ) {
            return Response::html( '<div class="metis-error">METIS shell is missing.</div>', 500 );
        }

        ob_start();
        if ( function_exists( 'metis_security_trusted_include' ) ) {
            \metis_security_trusted_include( $shell );
        } else {
            require $shell;
        }

        return Response::html(
            (string) ob_get_clean(),
            $status,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    private static function renderTemplate( string $path, array $vars ): string {
        extract( $vars, EXTR_SKIP );
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }

    private static function loginUrl(): string {
        return function_exists( 'metis_auth_login_url' ) ? (string) \metis_auth_login_url() : '/login';
    }
}
