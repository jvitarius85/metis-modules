<?php
declare(strict_types=1);

$coverage = is_array( $coverage ?? null ) ? $coverage : [];
$frequent = (array) ( $coverage['frequent_issue_phrases'] ?? [] );
$unresolved = (array) ( $coverage['unresolved_issue_phrases'] ?? [] );
$failedMatches = (array) ( $coverage['failed_help_search_matches'] ?? [] );
$missingTerms = (array) ( $coverage['articles_needing_search_terms'] ?? [] );
?>

<section class="metis-help-card">
    <div class="metis-help-admin-filters">
        <a class="metis-btn metis-btn-secondary" href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">Back to Articles</a>
        <button
            class="metis-btn metis-btn-secondary"
            type="button"
            data-help-admin-rebuild
            data-endpoint="<?php echo htmlspecialchars( metis_home_url( '/system/enclave/help/index/rebuild.php' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
            data-nonce="<?php echo htmlspecialchars( (string) $rebuild_nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>"
        >
            Rebuild Search Index
        </button>
    </div>
</section>

<section class="metis-help-card">
    <h2>Frequently searched issue phrases</h2>
    <?php if ( $frequent === [] ) : ?>
        <p class="metis-help-muted">No Hermes help issue logs are available yet.</p>
    <?php else : ?>
        <div class="metis-table-wrap">
            <table class="metis-table">
                <thead>
                    <tr>
                        <th>Phrase</th>
                        <th>Hits</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $frequent as $row ) : ?>
                        <tr>
                            <td><?php echo htmlspecialchars( (string) ( $row['normalized_issue'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                            <td><?php echo (int) ( $row['hits'] ?? 0 ); ?></td>
                            <td><?php echo htmlspecialchars( (string) ( $row['last_seen'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="metis-help-card">
    <h2>Low-confidence and unresolved phrases</h2>
    <?php if ( $unresolved === [] ) : ?>
        <p class="metis-help-muted">No unresolved help phrases were found.</p>
    <?php else : ?>
        <ul class="metis-help-list">
            <?php foreach ( $unresolved as $row ) : ?>
                <li>
                    <strong><?php echo htmlspecialchars( (string) ( $row['normalized_issue'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></strong>
                    <span class="metis-help-row-meta">
                        <?php echo htmlspecialchars( (string) ( $row['classification'] ?? 'Unknown' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                        · <?php echo htmlspecialchars( (string) ( $row['confidence_label'] ?? 'none' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                        · <?php echo htmlspecialchars( (string) ( $row['created_at'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="metis-help-card">
    <h2>Failed Help Search matches</h2>
    <?php if ( $failedMatches === [] ) : ?>
        <p class="metis-help-muted">No failed matches were logged.</p>
    <?php else : ?>
        <ul class="metis-help-list">
            <?php foreach ( $failedMatches as $row ) : ?>
                <li>
                    <strong><?php echo htmlspecialchars( (string) ( $row['normalized_issue'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></strong>
                    <span class="metis-help-row-meta">
                        <?php echo htmlspecialchars( (string) ( $row['module_key'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                        <?php if ( (string) ( $row['action_key'] ?? '' ) !== '' ) : ?>
                            · <?php echo htmlspecialchars( (string) ( $row['action_key'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                        <?php endif; ?>
                        · <?php echo htmlspecialchars( (string) ( $row['confidence_label'] ?? 'none' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="metis-help-card">
    <h2>Articles needing search terms</h2>
    <?php if ( $missingTerms === [] ) : ?>
        <p class="metis-help-muted">All indexed help articles currently have search terms.</p>
    <?php else : ?>
        <div class="metis-table-wrap">
            <table class="metis-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $missingTerms as $row ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo htmlspecialchars( metis_home_url( '/admin/help/articles/edit/' . (int) ( $row['id'] ?? 0 ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>">
                                    <?php echo htmlspecialchars( (string) ( $row['title'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars( (string) ( $row['module_key'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                            <td><?php echo htmlspecialchars( (string) ( $row['action_key'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                            <td><?php echo htmlspecialchars( (string) ( $row['updated_at'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
