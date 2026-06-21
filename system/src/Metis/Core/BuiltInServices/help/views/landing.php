<?php
declare(strict_types=1);

$categories = is_array( $landing['categories'] ?? null ) ? $landing['categories'] : [];
$popularArticles = is_array( $landing['popular_articles'] ?? null ) ? $landing['popular_articles'] : [];
$recentArticles = is_array( $landing['recent_articles'] ?? null ) ? $landing['recent_articles'] : [];
$hasArticles = $popularArticles !== [] || $recentArticles !== [];
?>
<section class="metis-help-hero">
    <p class="metis-help-eyebrow">Help Library</p>
    <h1 class="metis-page-title">How can we help?</h1>
    <p class="metis-subtitle">Search practical guidance for Metis modules, account access, admin workflows, and common fixes.</p>
    <form class="metis-help-search-form" action="<?php echo htmlspecialchars( metis_home_url( '/admin/help/search' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" method="get">
        <label class="screen-reader-text" for="help-search-home">Search help</label>
        <input id="help-search-home" class="metis-input" type="search" name="q" placeholder="Search help by module, task, or issue">
        <button class="metis-btn" type="submit">Search Help</button>
    </form>
</section>

<?php if ( ! $hasArticles ) : ?>
    <section class="metis-help-empty-state">
        <h2>No help documents are available yet.</h2>
        <p>Run the Help Documents Seeder to create the default help library.</p>
    </section>
<?php endif; ?>

<section class="metis-help-grid">
    <div class="metis-help-card">
        <h2>Popular Articles</h2>
        <?php if ( $popularArticles === [] ) : ?>
            <p class="metis-help-muted">No help documents are available yet.</p>
        <?php else : ?>
            <ul class="metis-help-link-list">
                <?php foreach ( $popularArticles as $article ) : ?>
                    <li>
                        <a href="<?php echo htmlspecialchars( (string) ( $article['url'] ?? '#' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
                            <?php echo htmlspecialchars( (string) ( $article['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                        </a>
                        <span><?php echo htmlspecialchars( (string) ( $article['category'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="metis-help-card">
        <h2>Recently Updated</h2>
        <?php if ( $recentArticles === [] ) : ?>
            <p class="metis-help-muted">No help documents are available yet.</p>
        <?php else : ?>
            <ul class="metis-help-link-list">
                <?php foreach ( $recentArticles as $article ) : ?>
                    <li>
                        <a href="<?php echo htmlspecialchars( (string) ( $article['url'] ?? '#' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
                            <?php echo htmlspecialchars( (string) ( $article['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                        </a>
                        <span><?php echo htmlspecialchars( (string) ( $article['updated_at'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<section class="metis-help-card">
    <h2>Categories</h2>
    <?php if ( $categories === [] ) : ?>
        <p class="metis-help-muted">No help documents are available yet.</p>
    <?php else : ?>
        <div class="metis-help-category-grid">
            <?php foreach ( $categories as $category ) : ?>
                <a class="metis-help-category-card" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/category/' . (string) ( $category['slug'] ?? '' ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
                    <strong><?php echo htmlspecialchars( (string) ( $category['name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></strong>
                    <span><?php echo (int) ( $category['article_count'] ?? 0 ); ?> article<?php echo (int) ( $category['article_count'] ?? 0 ) === 1 ? '' : 's'; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
