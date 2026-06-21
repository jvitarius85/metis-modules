<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_render_sidebar_module_layout' ) ) {
    require_once METIS_SRC_PATH . 'Metis/Core/Runtime/SidebarModuleLayout.php';
}

$state = metis_get_query_var( 'metis_help_state', [] );
$state = is_array( $state ) ? $state : [];

$pageKind = (string) ( $state['page_kind'] ?? 'landing' );
$pageTitle = trim( (string) ( $state['page_title'] ?? 'Help' ) );
$pageSubtitle = trim( (string) ( $state['page_subtitle'] ?? '' ) );
$tree = is_array( $state['tree'] ?? null ) ? $state['tree'] : [];
$searchQuery = (string) ( $state['search_query'] ?? '' );
$searchCategory = (string) ( $state['search_category'] ?? '' );
$activeCategorySlug = (string) ( $state['active_category_slug'] ?? '' );
$activeArticleSlug = (string) ( $state['active_article_slug'] ?? '' );
$landing = is_array( $state['landing'] ?? null ) ? $state['landing'] : [];
$categories = is_array( $state['categories'] ?? null ) ? $state['categories'] : [];
$results = is_array( $state['results'] ?? null ) ? $state['results'] : [];
$article = is_array( $state['article'] ?? null ) ? $state['article'] : [];
$relatedArticles = is_array( $state['related_articles'] ?? null ) ? $state['related_articles'] : [];
$category = is_array( $state['category'] ?? null ) ? $state['category'] : [];
$adminContent = (string) ( $state['admin_content'] ?? '' );
$canManage = function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'help.manage' );

$homeUrl = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help' ) : '/admin/help';
$searchUrl = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/search' ) : '/admin/help/search';
$articlesUrl = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/articles' ) : '/admin/help/articles';

$breadcrumbs = [
    [ 'label' => 'Help', 'url' => $homeUrl ],
];

if ( $pageKind === 'search' ) {
    $breadcrumbs[] = [ 'label' => 'Search' ];
} elseif ( $pageKind === 'category' && $pageTitle !== '' ) {
    $breadcrumbs[] = [ 'label' => $pageTitle ];
} elseif ( $pageKind === 'article' ) {
    $categoryLabel = trim( (string) ( $article['category_name'] ?? '' ) );
    $categorySlug = trim( (string) ( $article['category_slug'] ?? '' ) );
    if ( $categoryLabel !== '' && $categorySlug !== '' ) {
        $breadcrumbs[] = [
            'label' => $categoryLabel,
            'url' => ( function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/category/' . $categorySlug ) : '/admin/help/category/' . $categorySlug ),
        ];
    }
    $breadcrumbs[] = [ 'label' => $pageTitle !== '' ? $pageTitle : 'Article' ];
} elseif ( $pageKind === 'admin_list' ) {
    $breadcrumbs[] = [ 'label' => 'Manage Articles' ];
} elseif ( $pageKind === 'admin_editor' ) {
    $breadcrumbs[] = [ 'label' => 'Manage Articles', 'url' => $articlesUrl ];
    $breadcrumbs[] = [ 'label' => $pageTitle !== '' ? $pageTitle : 'Editor' ];
} elseif ( $pageKind === 'error' ) {
    $breadcrumbs[] = [ 'label' => 'Error' ];
}

if ( function_exists( 'metis_breadcrumb' ) ) {
    metis_breadcrumb( $breadcrumbs );
}

$treeCategories = [];
$treeArticles = [];
foreach ( $tree as $treeRow ) {
    if ( ! is_array( $treeRow ) ) {
        continue;
    }

    $treeCategories[] = $treeRow;
    foreach ( (array) ( $treeRow['articles'] ?? [] ) as $treeArticleRow ) {
        if ( is_array( $treeArticleRow ) ) {
            $treeArticles[] = $treeArticleRow;
        }
    }
}

$featuredCategories = array_slice( $treeCategories, 0, 6 );
$featuredArticles = array_slice( $treeArticles, 0, 8 );

$normalizeRows = static function ( array $items ): array {
    $normalized = [];
    foreach ( $items as $item ) {
        if ( is_array( $item ) ) {
            $normalized[] = $item;
            continue;
        }

        if ( is_object( $item ) ) {
            $normalized[] = (array) $item;
        }
    }

    return $normalized;
};

$renderResultsList = static function ( array $items, string $emptyTitle, string $emptyCopy ): void {
    $normalized = [];
    foreach ( $items as $item ) {
        if ( is_array( $item ) ) {
            $normalized[] = $item;
        } elseif ( is_object( $item ) ) {
            $normalized[] = (array) $item;
        }
    }

    if ( $normalized === [] ) {
        echo '<section class="metis-help-empty-state">';
        echo '<h2>' . metis_escape_html( $emptyTitle ) . '</h2>';
        echo '<p>' . metis_escape_html( $emptyCopy ) . '</p>';
        echo '</section>';
        return;
    }

    echo '<ul class="metis-help-result-list">';
    foreach ( $normalized as $item ) {
        $url = (string) ( $item['url'] ?? '#' );
        $title = (string) ( $item['title'] ?? '' );
        $summary = (string) ( $item['summary'] ?? '' );
        $meta = trim( (string) ( $item['category'] ?? '' ) );
        echo '<li class="metis-help-result-card">';
        echo '<a class="metis-help-result-link" href="' . metis_escape_url( $url ) . '">';
        echo '<span class="metis-help-result-title">' . metis_escape_html( $title ) . '</span>';
        if ( $summary !== '' ) {
            echo '<span class="metis-help-result-summary">' . metis_escape_html( $summary ) . '</span>';
        }
        if ( $meta !== '' ) {
            echo '<small>' . metis_escape_html( $meta ) . '</small>';
        }
        echo '</a>';
        echo '</li>';
    }
    echo '</ul>';
};

$renderCategoryCards = static function ( array $items ): void {
    if ( $items === [] ) {
        echo '<p class="metis-help-muted">No help categories are available yet.</p>';
        return;
    }

    echo '<div class="metis-help-category-grid">';
    foreach ( $items as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }
        echo '<a class="metis-help-category-card" href="' . metis_escape_url( (string) ( $item['url'] ?? '#' ) ) . '">';
        echo '<strong>' . metis_escape_html( (string) ( $item['name'] ?? '' ) ) . '</strong>';
        echo '<span>' . (int) ( $item['article_count'] ?? 0 ) . ' article' . ( (int) ( $item['article_count'] ?? 0 ) === 1 ? '' : 's' ) . '</span>';
        echo '</a>';
    }
    echo '</div>';
};
?>
<section class="metis-help-shell">
    <?php
    metis_render_sidebar_module_layout(
        [
            'class' => 'metis-help-workspace',
            'title' => $pageTitle !== '' ? $pageTitle : 'Help',
            'subtitle' => $pageSubtitle,
            'shell_class' => 'metis-help-workspace-shell',
            'sidebar_class' => 'metis-help-workspace-sidebar',
            'content_class' => 'metis-help-workspace-content',
            'header_actions' => static function () use ( $homeUrl, $searchUrl, $articlesUrl, $canManage ): void {
                echo '<div class="metis-help-header-actions">';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( $homeUrl ) . '">Help Home</a>';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( $searchUrl ) . '">Search</a>';
                if ( $canManage ) {
                    echo '<a class="metis-btn" href="' . metis_escape_url( $articlesUrl ) . '">Manage Articles</a>';
                }
                echo '</div>';
            },
            'sidebar' => static function () use ( $tree, $searchQuery, $searchCategory, $categories, $activeCategorySlug, $activeArticleSlug, $searchUrl ): void {
                if ( $categories === [] ) {
                    $categories = [];
                    foreach ( $tree as $treeCategory ) {
                        $categories[] = [
                            'id' => (int) ( $treeCategory['id'] ?? 0 ),
                            'name' => (string) ( $treeCategory['name'] ?? '' ),
                            'slug' => (string) ( $treeCategory['slug'] ?? '' ),
                        ];
                    }
                }

                echo '<div class="metis-list-sidebar-section">';
                echo '<div class="metis-list-sidebar-label">Search Help</div>';
                echo '<form class="metis-help-search-form metis-help-search-form--stacked" action="' . metis_escape_url( $searchUrl ) . '" method="get">';
                echo '<label class="screen-reader-text" for="metis-help-sidebar-query">Search help</label>';
                echo '<input id="metis-help-sidebar-query" class="metis-input" type="search" name="q" value="' . metis_escape_attr( $searchQuery ) . '" placeholder="Search help by task, issue, or module">';
                echo '<label class="screen-reader-text" for="metis-help-sidebar-category">Help category</label>';
                echo '<select id="metis-help-sidebar-category" class="metis-input" name="category" aria-label="Help category">';
                echo '<option value="">All categories</option>';
                foreach ( $categories as $sidebarCategory ) {
                    $slug = (string) ( $sidebarCategory['slug'] ?? '' );
                    $name = (string) ( $sidebarCategory['name'] ?? '' );
                    echo '<option value="' . metis_escape_attr( $slug ) . '"' . ( $searchCategory === $slug ? ' selected' : '' ) . '>' . metis_escape_html( $name ) . '</option>';
                }
                echo '</select>';
                echo '<button class="metis-btn" type="submit">Search Help</button>';
                echo '</form>';
                echo '</div>';

                echo '<div class="metis-list-sidebar-section">';
                echo '<div class="metis-list-sidebar-label">Navigation</div>';
                echo '<div class="metis-list-sidebar-actions">';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( $searchUrl ) . '">Open Search</a>';
                echo '<a class="metis-btn metis-btn-secondary" href="' . metis_escape_url( function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help' ) : '/admin/help' ) . '">Help Home</a>';
                echo '</div>';
                echo '</div>';

                echo '<div class="metis-list-sidebar-section">';
                echo '<div class="metis-list-sidebar-label">Help Library</div>';

                if ( $tree === [] ) {
                    echo '<p class="metis-help-muted">No help documents are available yet.</p>';
                } else {
                    foreach ( $tree as $treeCategory ) {
                        $categorySlug = (string) ( $treeCategory['slug'] ?? '' );
                        $isActiveCategory = $activeCategorySlug === $categorySlug;
                        $isOpen = $isActiveCategory || $activeArticleSlug !== '' && $isActiveCategory;
                        echo '<details class="metis-help-tree-group"' . ( $isOpen ? ' open' : '' ) . '>';
                        echo '<summary class="metis-help-tree-summary">';
                        echo '<span class="metis-help-tree-summary-main">';
                        echo '<span class="metis-help-tree-caret" aria-hidden="true"></span>';
                        echo '<a class="metis-list-sidebar-nav-item metis-help-tree-category' . ( $isActiveCategory && $activeArticleSlug === '' ? ' is-active' : '' ) . '" href="' . metis_escape_url( (string) ( $treeCategory['url'] ?? '#' ) ) . '" onclick="event.stopPropagation();">';
                        echo metis_escape_html( (string) ( $treeCategory['name'] ?? '' ) );
                        echo '</a>';
                        echo '</span>';
                        echo '<span class="metis-help-tree-count">' . (int) ( $treeCategory['article_count'] ?? 0 ) . '</span>';
                        echo '</summary>';
                        echo '<nav class="metis-list-sidebar-nav metis-help-tree-nav" aria-label="' . metis_escape_attr( (string) ( $treeCategory['name'] ?? 'Category' ) ) . ' articles">';
                        foreach ( (array) ( $treeCategory['articles'] ?? [] ) as $treeArticle ) {
                            $articleSlug = (string) ( $treeArticle['slug'] ?? '' );
                            echo '<a class="metis-list-sidebar-nav-item metis-help-tree-article' . ( $activeArticleSlug === $articleSlug ? ' is-active' : '' ) . '" href="' . metis_escape_url( (string) ( $treeArticle['url'] ?? '#' ) ) . '">' . metis_escape_html( (string) ( $treeArticle['title'] ?? '' ) ) . '</a>';
                        }
                        echo '</nav>';
                        echo '</details>';
                    }
                }

                echo '</div>';
            },
            'content' => static function () use ( $pageKind, $landing, $results, $article, $relatedArticles, $category, $adminContent, $normalizeRows, $renderResultsList, $renderCategoryCards, $homeUrl, $searchUrl, $searchQuery, $searchCategory, $categories, $featuredCategories, $featuredArticles, $treeCategories, $treeArticles ): void {
                if ( $pageKind === 'landing' ) {
                    $landingCategories = $normalizeRows( is_array( $landing['categories'] ?? null ) ? $landing['categories'] : [] );
                    $popularArticles = $normalizeRows( is_array( $landing['popular_articles'] ?? null ) ? $landing['popular_articles'] : [] );
                    $recentArticles = $normalizeRows( is_array( $landing['recent_articles'] ?? null ) ? $landing['recent_articles'] : [] );
                    $browseCategories = $landingCategories !== [] ? array_map(
                        static function ( array $item ) use ( $homeUrl ): array {
                            return [
                                'name' => (string) ( $item['name'] ?? '' ),
                                'article_count' => (int) ( $item['article_count'] ?? 0 ),
                                'url' => function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/admin/help/category/' . (string) ( $item['slug'] ?? '' ) ) : $homeUrl,
                            ];
                        },
                        $landingCategories
                    ) : $featuredCategories;

                    echo '<section class="metis-help-hero metis-help-hero--dashboard">';
                    echo '<div class="metis-help-hero-copy">';
                    echo '<p class="metis-help-eyebrow">Start Here</p>';
                    echo '<h2 class="metis-help-hero-title">Find answers fast without leaving the admin.</h2>';
                    echo '<p class="metis-help-hero-text">Search by task, browse a module area, or jump into the most-used articles below.</p>';
                    echo '</div>';
                    echo '<form class="metis-help-search-form" action="' . metis_escape_url( $searchUrl ) . '" method="get">';
                    echo '<label class="screen-reader-text" for="help-search-home">Search help</label>';
                    echo '<input id="help-search-home" class="metis-input" type="search" name="q" placeholder="Search help by module, task, or issue">';
                    echo '<button class="metis-btn" type="submit">Search Help</button>';
                    echo '</form>';
                    echo '<div class="metis-help-stat-grid">';
                    echo '<div class="metis-help-stat-card"><strong>' . count( $treeCategories ) . '</strong><span>help areas</span></div>';
                    echo '<div class="metis-help-stat-card"><strong>' . count( $treeArticles ) . '</strong><span>published articles</span></div>';
                    echo '<div class="metis-help-stat-card"><strong>' . count( $featuredArticles ) . '</strong><span>featured topics</span></div>';
                    echo '</div>';
                    echo '</section>';

                    echo '<section class="metis-help-grid">';
                    echo '<div class="metis-help-card"><h2>Popular Articles</h2>';
                    $renderResultsList(
                        $popularArticles !== [] ? $popularArticles : $featuredArticles,
                        'No help documents are available yet.',
                        'Run the Help Documents Seeder to create the default help library.'
                    );
                    echo '</div>';
                    echo '<div class="metis-help-card"><h2>Recently Updated</h2>';
                    $renderResultsList(
                        $recentArticles !== [] ? $recentArticles : $featuredArticles,
                        'No help documents are available yet.',
                        'Run the Help Documents Seeder to create the default help library.'
                    );
                    echo '</div>';
                    echo '</section>';

                    echo '<section class="metis-help-grid">';
                    echo '<div class="metis-help-card"><h2>Browse by Area</h2>';
                    $renderCategoryCards( $browseCategories );
                    echo '</div>';
                    echo '<div class="metis-help-card"><h2>Common Tasks</h2>';
                    $renderResultsList(
                        $featuredArticles,
                        'No task articles are available yet.',
                        'Seed the Help library to populate common tasks.'
                    );
                    echo '</div>';
                    echo '</section>';
                    return;
                }

                if ( $pageKind === 'search' ) {
                    $items = $normalizeRows( is_array( $results['results'] ?? null ) ? $results['results'] : [] );
                    $total = (int) ( $results['total'] ?? 0 );
                    $categoryOptions = $normalizeRows( $categories );

                    echo '<section class="metis-help-card">';
                    echo '<form class="metis-help-search-form" action="' . metis_escape_url( $searchUrl ) . '" method="get">';
                    echo '<label class="screen-reader-text" for="help-search-query">Search help</label>';
                    echo '<input id="help-search-query" class="metis-input" type="search" name="q" value="' . metis_escape_attr( $searchQuery ) . '" placeholder="Search help by module, task, or issue">';
                    echo '<label class="screen-reader-text" for="help-search-category">Filter by category</label>';
                    echo '<select id="help-search-category" class="metis-input" name="category" aria-label="Help category">';
                    echo '<option value="">All categories</option>';
                    foreach ( $categoryOptions as $searchCategoryOption ) {
                        $slug = (string) ( $searchCategoryOption['slug'] ?? '' );
                        echo '<option value="' . metis_escape_attr( $slug ) . '"' . ( $searchCategory === $slug ? ' selected' : '' ) . '>' . metis_escape_html( (string) ( $searchCategoryOption['name'] ?? '' ) ) . '</option>';
                    }
                    echo '</select>';
                    echo '<button class="metis-btn" type="submit">Search</button>';
                    echo '</form>';
                    echo '</section>';

                    echo '<section class="metis-help-card">';
                    if ( $items === [] ) {
                        echo '<div class="metis-help-empty-state">';
                        echo '<h2>No matching help articles found.</h2>';
                        if ( $searchQuery !== '' ) {
                            echo '<p>No results matched <strong>' . metis_escape_html( $searchQuery ) . '</strong>.</p>';
                        }
                        echo '<ul class="metis-help-empty-list">';
                        echo '<li>Check spelling.</li><li>Try fewer words.</li><li>Search by module name.</li><li>Contact an administrator if the issue continues.</li>';
                        echo '</ul>';
                        echo '</div>';
                        echo '<div class="metis-help-search-fallback">';
                        echo '<h3>Try These Instead</h3>';
                        $renderResultsList(
                            $featuredArticles,
                            'No suggested articles are available yet.',
                            'Use the category tree to browse Help areas.'
                        );
                        echo '</div>';
                    } else {
                        echo '<p class="metis-help-results-count">' . $total . ' matching article' . ( $total === 1 ? '' : 's' ) . '</p>';
                        $renderResultsList( $items, '', '' );
                    }
                    echo '</section>';
                    return;
                }

                if ( $pageKind === 'category' ) {
                    $items = $normalizeRows( is_array( $results['results'] ?? null ) ? $results['results'] : [] );
                    echo '<section class="metis-help-card">';
                    echo '<p class="metis-help-eyebrow">Category</p>';
                    echo '<p class="metis-help-muted">' . count( $items ) . ' article' . ( count( $items ) === 1 ? '' : 's' ) . ' in this category.</p>';
                    echo '</section>';
                    echo '<section class="metis-help-card">';
                    $renderResultsList( $items, 'No help documents are available yet.', 'Run the Help Documents Seeder to create the default help library.' );
                    echo '</section>';
                    return;
                }

                if ( $pageKind === 'article' ) {
                    $articleUrl = function_exists( 'metis_home_url' )
                        ? (string) metis_home_url( '/admin/help/article/' . (string) ( $article['slug'] ?? '' ) )
                        : '/admin/help/article/' . (string) ( $article['slug'] ?? '' );

                    echo '<div class="metis-help-article-layout">';
                    echo '<article class="metis-help-article metis-help-article--embedded">';
                    echo '<div class="metis-help-article__meta-row">';
                    if ( ! empty( $article['category_name'] ) ) {
                        echo '<span class="metis-help-badge">' . metis_escape_html( (string) $article['category_name'] ) . '</span>';
                    }
                    if ( ! empty( $article['updated_at'] ) ) {
                        echo '<span class="metis-help-updated">Updated ' . metis_escape_html( (string) $article['updated_at'] ) . '</span>';
                    }
                    echo '</div>';
                    echo '<div class="metis-help-article__body">' . (string) ( $article['content'] ?? '' ) . '</div>';
                    echo '</article>';

                    echo '<aside class="metis-help-article-rail">';
                    echo '<section class="metis-help-card metis-help-card--accent">';
                    echo '<p class="metis-help-eyebrow">Need More Help?</p>';
                    echo '<h2>Still stuck on this step?</h2>';
                    echo '<p>If the documented workflow does not match what you see on screen, send a message to the system admin with the article and page context already attached.</p>';
                    echo '<button type="button" class="metis-btn" data-help-support-open'
                        . ' data-article-title="' . metis_escape_attr( (string) ( $article['title'] ?? 'Help Article' ) ) . '"'
                        . ' data-article-slug="' . metis_escape_attr( (string) ( $article['slug'] ?? '' ) ) . '"'
                        . ' data-article-url="' . metis_escape_attr( $articleUrl ) . '">Contact System Admin</button>';
                    echo '</section>';

                    echo '<section class="metis-help-card">';
                    echo '<h2>Related Articles</h2>';
                    $renderResultsList( $relatedArticles, 'No related articles are available yet.', 'Use the Help search to find nearby topics.' );
                    echo '</section>';
                    echo '</aside>';
                    echo '</div>';

                    echo '<div class="metis-modal-backdrop" id="metis-help-support-modal" aria-hidden="true" hidden>';
                    echo '<div class="metis-modal metis-help-support-modal" role="dialog" aria-modal="true" aria-labelledby="metis-help-support-title">';
                    echo '<div class="metis-modal-header">';
                    echo '<h2 id="metis-help-support-title" class="metis-modal-title">Contact System Admin</h2>';
                    echo '<button type="button" class="metis-modal-close" data-help-support-close aria-label="Close">&times;</button>';
                    echo '</div>';
                    echo '<div class="metis-modal-body">';
                    echo '<form class="metis-help-support-form" data-help-support-form>';
                    echo '<input type="hidden" name="article_title" value="' . metis_escape_attr( (string) ( $article['title'] ?? 'Help Article' ) ) . '">';
                    echo '<input type="hidden" name="article_slug" value="' . metis_escape_attr( (string) ( $article['slug'] ?? '' ) ) . '">';
                    echo '<input type="hidden" name="article_url" value="' . metis_escape_attr( $articleUrl ) . '">';
                    echo '<input type="hidden" name="route" value="' . metis_escape_attr( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) . '">';
                    echo '<div class="metis-help-support-context">';
                    echo '<div><strong>Article</strong><span>' . metis_escape_html( (string) ( $article['title'] ?? 'Help Article' ) ) . '</span></div>';
                    echo '<div><strong>Category</strong><span>' . metis_escape_html( (string) ( $article['category_name'] ?? 'Help' ) ) . '</span></div>';
                    echo '</div>';
                    echo '<label for="metis-help-support-message">What do you need help with?</label>';
                    echo '<textarea id="metis-help-support-message" class="metis-input" name="message" rows="6" placeholder="Describe what you expected to see, what actually happened, and what you were trying to finish." required></textarea>';
                    echo '<p class="metis-help-muted">Your message includes the article and current Help page so the system admin has context.</p>';
                    echo '</form>';
                    echo '</div>';
                    echo '<div class="metis-modal-footer">';
                    echo '<button type="button" class="metis-btn metis-btn-secondary" data-help-support-close>Cancel</button>';
                    echo '<button type="button" class="metis-btn" data-help-support-submit>Send Request</button>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    return;
                }

                if ( $pageKind === 'admin_list' || $pageKind === 'admin_editor' ) {
                    echo '<div class="metis-help-admin-shell">';
                    echo $adminContent;
                    echo '</div>';
                    return;
                }

                echo '<section class="metis-help-empty-state">';
                echo '<h2>' . metis_escape_html( $pageKind === 'error' ? 'The requested help page could not be found.' : 'No help documents are available yet.' ) . '</h2>';
                echo '<p><a class="metis-btn" href="' . metis_escape_url( $homeUrl ) . '">Back to Help</a></p>';
                echo '</section>';
            },
        ]
    );
    ?>
</section>
