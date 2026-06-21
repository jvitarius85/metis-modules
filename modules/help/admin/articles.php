<?php
declare(strict_types=1);

$isList = $mode === 'list';
$isEditor = ! $isList;
$articleTags = is_array( $article['tags'] ?? null ) ? implode( ', ', (array) $article['tags'] ) : '';
?>
<?php if ( $isList ) : ?>
    <section class="metis-help-card">
        <form class="metis-help-admin-filters" method="get" action="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
            <input class="metis-input" type="search" name="q" value="<?php echo htmlspecialchars( (string) ( $filters['q'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" placeholder="Search articles">
            <select class="metis-input" name="category">
                <option value="">All categories</option>
                <?php foreach ( $categories as $category ) : ?>
                    <option value="<?php echo htmlspecialchars( (string) ( $category['slug'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"<?php echo (string) ( $filters['category'] ?? '' ) === (string) ( $category['slug'] ?? '' ) ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars( (string) ( $category['name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="metis-input" name="status">
                <option value="">All statuses</option>
                <option value="draft"<?php echo (string) ( $filters['status'] ?? '' ) === 'draft' ? ' selected' : ''; ?>>Draft</option>
                <option value="published"<?php echo (string) ( $filters['status'] ?? '' ) === 'published' ? ' selected' : ''; ?>>Published</option>
            </select>
            <button class="metis-btn" type="submit">Apply</button>
            <a class="metis-btn metis-btn-secondary" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles/create' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Create Article</a>
            <button
                class="metis-btn metis-btn-secondary"
                type="button"
                data-help-admin-rebuild
                data-endpoint="<?php echo htmlspecialchars( metis_home_url( '/system/enclave/help/index/rebuild.php' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
                data-nonce="<?php echo htmlspecialchars( (string) $rebuild_nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
            >
                Rebuild Search Index
            </button>
        </form>
    </section>

    <section class="metis-help-card">
        <?php if ( ! is_array( $listing ) || (array) ( $listing['results'] ?? [] ) === [] ) : ?>
            <div class="metis-help-empty-state">
                <h2>No help documents are available yet.</h2>
                <p>Run the Help Documents Seeder to create the default help library.</p>
            </div>
        <?php else : ?>
            <div class="metis-table-wrap">
                <table class="metis-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th>Seeded</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( (array) ( $listing['results'] ?? [] ) as $row ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars( (string) ( $row['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></strong>
                                    <div class="metis-help-row-meta"><?php echo htmlspecialchars( (string) ( $row['object_code'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars( (string) ( $row['category'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                                <td><?php echo htmlspecialchars( ucfirst( (string) ( $row['status'] ?? 'draft' ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                                <td><?php echo htmlspecialchars( (string) ( $row['updated_at'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                                <td>
                                    <?php if ( (int) ( $row['system_seeded'] ?? 0 ) === 1 ) : ?>
                                        <span class="metis-help-badge">System seeded</span>
                                    <?php else : ?>
                                        <span class="metis-help-muted">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td class="metis-help-table-actions">
                                    <a class="metis-btn metis-btn-secondary" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles/edit/' . (int) ( $row['id'] ?? 0 ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Edit</a>
                                    <?php if ( (string) ( $row['status'] ?? '' ) === 'published' ) : ?>
                                        <a class="metis-btn metis-btn-secondary" href="<?php echo htmlspecialchars( (string) ( $row['preview_url'] ?? '#' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Preview</a>
                                        <button
                                            class="metis-btn metis-btn-secondary"
                                            type="button"
                                            data-help-admin-confirm
                                            data-endpoint="<?php echo htmlspecialchars( metis_home_url( '/system/enclave/help/article/unpublish.php' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
                                            data-nonce="<?php echo htmlspecialchars( (string) $unpublish_nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
                                            data-article-id="<?php echo (int) ( $row['id'] ?? 0 ); ?>"
                                            data-confirm-title="Unpublish Article"
                                            data-confirm-message="Unpublish this help article?"
                                        >
                                            Unpublish
                                        </button>
                                    <?php else : ?>
                                        <a class="metis-btn metis-btn-secondary" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles/edit/' . (int) ( $row['id'] ?? 0 ) . '?preview=1#help-preview' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Preview</a>
                                        <button
                                            class="metis-btn"
                                            type="button"
                                            data-help-admin-confirm
                                            data-endpoint="<?php echo htmlspecialchars( metis_home_url( '/system/enclave/help/article/publish.php' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
                                            data-nonce="<?php echo htmlspecialchars( (string) $publish_nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
                                            data-article-id="<?php echo (int) ( $row['id'] ?? 0 ); ?>"
                                            data-confirm-title="Publish Article"
                                            data-confirm-message="Publish this help article?"
                                        >
                                            Publish
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php else : ?>
    <section class="metis-help-card">
        <form
            class="metis-help-editor"
            data-help-admin-form
            data-endpoint="<?php echo htmlspecialchars( metis_home_url( '/system/enclave/help/article/save.php' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
            data-nonce="<?php echo htmlspecialchars( (string) $save_nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
            data-return-url="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
        >
            <input type="hidden" name="id" value="<?php echo (int) ( $article['id'] ?? 0 ); ?>">
            <div class="metis-help-editor-grid">
                <label>
                    <span>Title</span>
                    <input class="metis-input" type="text" name="title" value="<?php echo htmlspecialchars( (string) ( $article['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" required>
                </label>
                <label>
                    <span>Slug</span>
                    <input class="metis-input" type="text" name="slug" value="<?php echo htmlspecialchars( (string) ( $article['slug'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" required>
                </label>
                <label class="metis-help-editor-grid__full">
                    <span>Summary</span>
                    <textarea class="metis-input" name="summary" rows="3" required><?php echo htmlspecialchars( (string) ( $article['summary'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></textarea>
                </label>
                <label>
                    <span>Category</span>
                    <select class="metis-input" name="category_id" required>
                        <option value="">Select a category</option>
                        <?php foreach ( $categories as $category ) : ?>
                            <option value="<?php echo (int) ( $category['id'] ?? 0 ); ?>"<?php echo (int) ( $article['category_id'] ?? 0 ) === (int) ( $category['id'] ?? 0 ) ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars( (string) ( $category['name'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Status</span>
                    <select class="metis-input" name="status" required>
                        <option value="draft"<?php echo (string) ( $article['status'] ?? '' ) === 'draft' ? ' selected' : ''; ?>>Draft</option>
                        <option value="published"<?php echo (string) ( $article['status'] ?? '' ) === 'published' ? ' selected' : ''; ?>>Published</option>
                    </select>
                </label>
                <label class="metis-help-editor-grid__full">
                    <span>Tags</span>
                    <input class="metis-input" type="text" name="tags" value="<?php echo htmlspecialchars( $articleTags, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>" placeholder="Comma-separated tags">
                </label>
                <label class="metis-help-editor-grid__full">
                    <span>Search terms</span>
                    <textarea class="metis-input" name="search_terms" rows="3" placeholder="Alternate terms, likely phrases, and synonyms"><?php echo htmlspecialchars( (string) ( $article['search_terms'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></textarea>
                </label>
                <label class="metis-help-editor-grid__full">
                    <span>Content</span>
                    <textarea class="metis-input" name="content" rows="24" required data-help-preview-source><?php echo htmlspecialchars( (string) ( $article['content'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></textarea>
                </label>
            </div>

            <div class="metis-help-editor-actions">
                <a class="metis-btn metis-btn-secondary" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Back to Articles</a>
                <?php if ( (int) ( $article['id'] ?? 0 ) > 0 ) : ?>
                    <?php if ( (string) ( $article['status'] ?? '' ) === 'published' ) : ?>
                        <a class="metis-btn metis-btn-secondary" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/article/' . (string) ( $article['slug'] ?? '' ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Preview</a>
                    <?php else : ?>
                        <a class="metis-btn metis-btn-secondary" href="#help-preview">Preview</a>
                    <?php endif; ?>
                <?php endif; ?>
                <button class="metis-btn" type="submit">Save Article</button>
            </div>
        </form>
    </section>

    <section class="metis-help-card" id="help-preview">
        <h2>Preview</h2>
        <article class="metis-help-article__body" data-help-preview-target>
            <?php echo (string) ( $article['content'] ?? '' ); ?>
        </article>
    </section>
<?php endif; ?>

<div class="metis-modal-backdrop" id="metis-help-admin-confirm" aria-hidden="true">
    <div class="metis-modal" role="dialog" aria-modal="true" aria-labelledby="metis-help-admin-confirm-title">
        <div class="metis-modal-header">
            <h2 id="metis-help-admin-confirm-title">Confirm</h2>
            <button type="button" class="metis-modal-close" data-help-admin-confirm-close aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <p data-help-admin-confirm-message>Confirm this action.</p>
        </div>
        <div class="metis-modal-footer">
            <button type="button" class="metis-btn metis-btn-secondary" data-help-admin-confirm-close>Cancel</button>
            <button type="button" class="metis-btn" data-help-admin-confirm-submit>Confirm</button>
        </div>
    </div>
</div>
