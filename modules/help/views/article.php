<?php
declare(strict_types=1);
?>
<article class="metis-help-article">
    <a class="metis-help-back-link" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Back to Help</a>
    <header class="metis-help-article__header">
        <p class="metis-help-eyebrow"><?php echo htmlspecialchars( (string) ( $article['category_name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></p>
        <h1 class="mw-page-title"><?php echo htmlspecialchars( (string) ( $article['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></h1>
        <p class="mw-subtitle"><?php echo htmlspecialchars( (string) ( $article['summary'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></p>
        <p class="metis-help-updated">Updated <?php echo htmlspecialchars( (string) ( $article['updated_at'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></p>
    </header>
    <div class="metis-help-article__body">
        <?php echo (string) ( $article['content'] ?? '' ); ?>
    </div>
</article>

<section class="metis-help-card">
    <h2>Related Articles</h2>
    <?php if ( $related_articles === [] ) : ?>
        <p class="metis-help-muted">No related articles are available yet.</p>
    <?php else : ?>
        <ul class="metis-help-link-list">
            <?php foreach ( $related_articles as $related ) : ?>
                <li>
                    <a href="<?php echo htmlspecialchars( (string) ( $related['url'] ?? '#' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
                        <?php echo htmlspecialchars( (string) ( $related['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                    </a>
                    <span><?php echo htmlspecialchars( (string) ( $related['category'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
