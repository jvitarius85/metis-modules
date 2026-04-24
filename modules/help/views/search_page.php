<?php
declare(strict_types=1);

$items = is_array( $results['results'] ?? null ) ? $results['results'] : [];
$total = (int) ( $results['total'] ?? 0 );
?>
<section class="metis-help-hero metis-help-hero--compact">
    <a class="metis-help-back-link" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Back to Help</a>
    <h1 class="mw-page-title">Help Search</h1>
    <p class="mw-subtitle">Search the Help library by title, module, action, or likely user phrase.</p>
    <form class="metis-help-search-form" action="<?php echo htmlspecialchars( metis_home_url( '/admin/help/search' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" method="get">
        <label class="screen-reader-text" for="help-search-query">Search help</label>
        <input id="help-search-query" class="mw-input" type="search" name="q" value="<?php echo htmlspecialchars( (string) $search_query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" placeholder="Search help by module, task, or issue">
        <label class="screen-reader-text" for="help-search-category">Filter by category</label>
        <select id="help-search-category" class="mw-input" name="category" aria-label="Help category">
            <option value="">All categories</option>
            <?php foreach ( $categories as $category ) : ?>
                <option value="<?php echo htmlspecialchars( (string) ( $category['slug'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"<?php echo (string) $search_category === (string) ( $category['slug'] ?? '' ) ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars( (string) ( $category['name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="mw-btn" type="submit">Search</button>
    </form>
</section>

<section class="metis-help-card">
    <?php if ( $items === [] ) : ?>
        <div class="metis-help-empty-state">
            <h2>No matching help articles found.</h2>
            <ul class="metis-help-empty-list">
                <li>Check spelling.</li>
                <li>Try fewer words.</li>
                <li>Search by module name.</li>
                <li>Contact an administrator if the issue continues.</li>
            </ul>
        </div>
    <?php else : ?>
        <p class="metis-help-results-count"><?php echo $total; ?> matching article<?php echo $total === 1 ? '' : 's'; ?></p>
        <ul class="metis-help-result-list">
            <?php foreach ( $items as $item ) : ?>
                <li class="metis-help-result-card">
                    <a href="<?php echo htmlspecialchars( (string) ( $item['url'] ?? '#' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
                        <?php echo htmlspecialchars( (string) ( $item['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                    </a>
                    <p><?php echo htmlspecialchars( (string) ( $item['summary'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></p>
                    <small><?php echo htmlspecialchars( (string) ( $item['category'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
