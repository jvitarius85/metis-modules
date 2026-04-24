<?php
declare(strict_types=1);

$items = is_array( $results['results'] ?? null ) ? $results['results'] : [];
?>
<section class="metis-help-hero metis-help-hero--compact">
    <a class="metis-help-back-link" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Back to Help</a>
    <p class="metis-help-eyebrow">Category</p>
    <h1 class="mw-page-title"><?php echo htmlspecialchars( (string) ( $category['name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></h1>
    <p class="mw-subtitle"><?php echo count( $items ); ?> article<?php echo count( $items ) === 1 ? '' : 's'; ?> in this category.</p>
</section>

<section class="metis-help-card">
    <?php if ( $items === [] ) : ?>
        <div class="metis-help-empty-state">
            <h2>No help documents are available yet.</h2>
            <p>Run the Help Documents Seeder to create the default help library.</p>
        </div>
    <?php else : ?>
        <ul class="metis-help-result-list">
            <?php foreach ( $items as $item ) : ?>
                <li class="metis-help-result-card">
                    <a href="<?php echo htmlspecialchars( (string) ( $item['url'] ?? '#' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
                        <?php echo htmlspecialchars( (string) ( $item['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                    </a>
                    <p><?php echo htmlspecialchars( (string) ( $item['summary'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
