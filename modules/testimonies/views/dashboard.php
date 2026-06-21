<?php if ( ! defined( 'METIS_ROOT' ) ) exit; ?>
<?php
if ( ! metis_testimonies_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Testimonies.</div>';
    return;
}

metis_testimonies_ensure_schema();

$search = isset( metis_request_get()['q'] ) ? trim( (string) metis_runtime_unslash( metis_request_get()['q'] ) ) : '';
$snapshot = \Metis\Modules\Testimonies\Repository::listSnapshot( $search );
$testimonies = isset( $snapshot['testimonies'] ) && is_array( $snapshot['testimonies'] ) ? $snapshot['testimonies'] : [];
$categories = isset( $snapshot['categories'] ) && is_array( $snapshot['categories'] ) ? $snapshot['categories'] : [];
$category_options = \Metis\Modules\Testimonies\Repository::categoryOptions( false );
$can_manage = metis_testimonies_can_manage();
$can_delete = metis_testimonies_can_delete();
$published_count = 0;
$featured_count = 0;
foreach ( $testimonies as $testimony_row ) {
    if ( (string) ( $testimony_row['status'] ?? 'draft' ) === 'published' ) {
        ++$published_count;
    }
    if ( ! empty( $testimony_row['is_featured'] ) ) {
        ++$featured_count;
    }
}
?>

<div class="metis-testimonies"
     data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
     data-can-delete="<?php echo $can_delete ? '1' : '0'; ?>"
     data-action-nonce="<?php echo function_exists( 'metis_runtime_create_nonce' ) ? metis_escape_attr( (string) metis_runtime_create_nonce( function_exists( 'metis_ajax_nonce_action' ) ? metis_ajax_nonce_action( 'metis_testimonies_save' ) : 'metis_testimonies_save' ) ) : ''; ?>"
     data-category-options="<?php echo metis_escape_attr( json_encode( $category_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '[]' ); ?>"
     data-testimony-items="<?php echo metis_escape_attr( json_encode( $testimonies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '[]' ); ?>"
     data-category-items="<?php echo metis_escape_attr( json_encode( $categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '[]' ); ?>">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Testimonies' ) ); ?></h1>
            <p class="metis-subtitle">Capture testimony quotes in one place and reuse them across Website blocks.</p>
        </div>
        <?php if ( $can_manage ) : ?>
            <div class="metis-page-header-right">
                <button type="button" id="metis-testimony-create-open" class="metis-btn metis-btn-primary">New Testimony</button>
                <button type="button" id="metis-testimony-category-open" class="metis-btn metis-btn-ghost">New Category</button>
            </div>
        <?php endif; ?>
    </div>
    <div class="metis-testimonies-stats">
        <section class="metis-card metis-testimonies-stat-card">
            <div class="metis-testimonies-stat-label">Testimonies</div>
            <strong class="metis-testimonies-stat-value"><?php echo metis_escape_html( (string) count( $testimonies ) ); ?></strong>
            <span class="metis-testimonies-stat-copy">Saved quotes available for reuse.</span>
        </section>
        <section class="metis-card metis-testimonies-stat-card">
            <div class="metis-testimonies-stat-label">Published</div>
            <strong class="metis-testimonies-stat-value"><?php echo metis_escape_html( (string) $published_count ); ?></strong>
            <span class="metis-testimonies-stat-copy">Ready for public Website blocks.</span>
        </section>
        <section class="metis-card metis-testimonies-stat-card">
            <div class="metis-testimonies-stat-label">Featured</div>
            <strong class="metis-testimonies-stat-value"><?php echo metis_escape_html( (string) $featured_count ); ?></strong>
            <span class="metis-testimonies-stat-copy">Prioritized in featured-only layouts.</span>
        </section>
        <section class="metis-card metis-testimonies-stat-card">
            <div class="metis-testimonies-stat-label">Categories</div>
            <strong class="metis-testimonies-stat-value"><?php echo metis_escape_html( (string) count( $categories ) ); ?></strong>
            <span class="metis-testimonies-stat-copy">Available filters for Website sections.</span>
        </section>
    </div>

    <?php metis_render_sidebar_layout( [
        'sidebar' => static function () use ( $search, $can_manage ): void { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <form method="get">
                    <input type="hidden" name="page" value="testimonies">
                    <input class="metis-input" type="search" name="q" value="<?php echo metis_escape_attr( $search ); ?>" placeholder="Speaker, quote, category">
                </form>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Guidance</div>
                <div class="metis-testimonies-sidebar-copy">
                    <p>Store approved quotes here, tag them with categories, and pull them into Website sections without retyping content.</p>
                </div>
            </div>
        <?php },
        'content' => static function () use ( $testimonies, $categories, $can_manage, $can_delete ): void { ?>
            <section class="metis-card metis-testimonies-card">
                <div class="metis-card-header">
                    <div>
                        <h2 class="metis-card-title">Testimonies</h2>
                        <div class="metis-card-subtitle">Manage reusable quotes, publication status, and featured ordering.</div>
                    </div>
                </div>
                <?php if ( $testimonies === [] ) : ?>
                    <div class="metis-empty-state metis-testimonies-empty-state">
                        <div class="metis-empty-state-icon" aria-hidden="true">&#10077;</div>
                        <h2>No testimonies yet</h2>
                        <p>Start by adding approved quotes from donors, clients, partners, or staff so Website editors can pull them into dynamic sections.</p>
                        <?php if ( $can_manage ) : ?>
                            <button type="button" id="metis-testimony-create-open-empty" class="metis-btn metis-btn-primary">New Testimony</button>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <table class="metis-premium-table metis-testimonies-table <?php echo $can_manage ? 'metis-testimonies-table--manageable' : 'metis-testimonies-table--readonly'; ?>">
                        <thead>
                            <tr class="metis-premium-row metis-premium-header">
                                <th class="metis-premium-cell">Speaker</th>
                                <th class="metis-premium-cell">Quote</th>
                                <th class="metis-premium-cell">Categories</th>
                                <th class="metis-premium-cell">Status</th>
                                <?php if ( $can_manage ) : ?><th class="metis-premium-cell">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="metis-testimonies-rows">
                            <?php foreach ( $testimonies as $row ) : ?>
                                <tr class="metis-premium-row">
                                    <td class="metis-premium-cell">
                                        <strong><?php echo metis_escape_html( (string) ( $row['speaker_name'] ?? '' ) ); ?></strong>
                                        <?php if ( ! empty( $row['speaker_title'] ) || ! empty( $row['speaker_company'] ) ) : ?>
                                            <div class="metis-muted"><?php echo metis_escape_html( trim( (string) ( $row['speaker_title'] ?? '' ) . ( ! empty( $row['speaker_title'] ) && ! empty( $row['speaker_company'] ) ? ' • ' : '' ) . (string) ( $row['speaker_company'] ?? '' ) ) ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="metis-premium-cell"><div class="metis-testimony-quote"><?php echo metis_escape_html( mb_strimwidth( (string) ( $row['quote_text'] ?? '' ), 0, 160, '…' ) ); ?></div></td>
                                    <td class="metis-premium-cell"><?php echo metis_escape_html( implode( ', ', array_map( 'strval', (array) ( $row['category_names'] ?? [] ) ) ) ?: '—' ); ?></td>
                                    <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( (string) ( $row['status'] ?? 'draft' ) ) ); ?><?php echo ! empty( $row['is_featured'] ) ? ' • Featured' : ''; ?></td>
                                    <?php if ( $can_manage ) : ?>
                                        <td class="metis-premium-cell">
                                            <button type="button" class="metis-btn-xs" data-testimony-edit="<?php echo metis_escape_attr( (string) ( $row['id'] ?? 0 ) ); ?>">Edit</button>
                                            <?php if ( $can_delete ) : ?>
                                                <button type="button" class="metis-btn-xs metis-btn-ghost" data-testimony-delete="<?php echo metis_escape_attr( (string) ( $row['id'] ?? 0 ) ); ?>">Delete</button>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="metis-card metis-testimonies-card">
                <div class="metis-card-header">
                    <div>
                        <h2 class="metis-card-title">Categories</h2>
                        <div class="metis-card-subtitle">Create reusable category groupings for Website filters and editorial organization.</div>
                    </div>
                </div>
                <?php if ( $categories === [] ) : ?>
                    <div class="metis-empty-state metis-testimonies-empty-state">
                        <div class="metis-empty-state-icon" aria-hidden="true">&#128278;</div>
                        <h2>No categories yet</h2>
                        <p>Create categories like Donor, Client, Partner, or Program so Website editors can filter the testimony block intentionally.</p>
                        <?php if ( $can_manage ) : ?>
                            <button type="button" id="metis-testimony-category-open-empty" class="metis-btn metis-btn-primary">New Category</button>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <table class="metis-premium-table metis-testimonies-categories-table <?php echo $can_manage ? 'metis-testimonies-categories-table--manageable' : 'metis-testimonies-categories-table--readonly'; ?>">
                        <thead>
                            <tr class="metis-premium-row metis-premium-header">
                                <th class="metis-premium-cell">Category</th>
                                <th class="metis-premium-cell">Slug</th>
                                <th class="metis-premium-cell">Usage</th>
                                <?php if ( $can_manage ) : ?><th class="metis-premium-cell">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="metis-testimony-categories-rows">
                            <?php foreach ( $categories as $row ) : ?>
                                <tr class="metis-premium-row">
                                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['name'] ?? '' ) ); ?><?php echo empty( $row['is_active'] ) ? ' • Inactive' : ''; ?></td>
                                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['slug'] ?? '' ) ); ?></td>
                                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['testimony_count'] ?? 0 ) ); ?></td>
                                    <?php if ( $can_manage ) : ?>
                                        <td class="metis-premium-cell">
                                            <button type="button" class="metis-btn-xs" data-category-edit="<?php echo metis_escape_attr( (string) ( $row['id'] ?? 0 ) ); ?>">Edit</button>
                                            <?php if ( $can_delete ) : ?>
                                                <button type="button" class="metis-btn-xs metis-btn-ghost" data-category-delete="<?php echo metis_escape_attr( (string) ( $row['id'] ?? 0 ) ); ?>">Delete</button>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php },
    ] ); ?>
</div>

<?php if ( $can_manage ) : ?>
<div id="metis-testimony-modal" class="metis-modal-backdrop" hidden aria-hidden="true">
    <div class="metis-modal metis-testimonies-modal">
        <h3 class="metis-modal-title">Testimony</h3>
        <form id="metis-testimony-form" class="metis-form-grid">
            <input type="hidden" name="id" value="0">
            <div class="metis-field metis-field-half"><label>Speaker Name</label><input class="metis-input" name="speaker_name" type="text" required></div>
            <div class="metis-field metis-field-half"><label>Speaker Title</label><input class="metis-input" name="speaker_title" type="text"></div>
            <div class="metis-field metis-field-full"><label>Speaker Company</label><input class="metis-input" name="speaker_company" type="text"></div>
            <div class="metis-field metis-field-full"><label>Quote</label><textarea class="metis-textarea" name="quote_text" rows="6" required></textarea></div>
            <div class="metis-field metis-field-full"><label>Source Notes</label><textarea class="metis-textarea" name="source_notes" rows="3"></textarea></div>
            <div class="metis-field metis-field-half"><label>Status</label><select class="metis-select" name="status"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></div>
            <div class="metis-field metis-field-quarter"><label>Sort Order</label><input class="metis-input" name="sort_order" type="number" value="0"></div>
            <div class="metis-field metis-field-quarter"><label class="metis-se-check-label"><input type="checkbox" name="is_featured" value="1"> Featured</label></div>
            <div class="metis-field metis-field-full"><label>Categories</label><div id="metis-testimony-category-checkboxes" class="metis-testimony-category-grid"></div></div>
            <div class="metis-form-actions">
                <button type="button" id="metis-testimony-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="submit" class="metis-btn">Save Testimony</button>
            </div>
        </form>
    </div>
</div>

<div id="metis-testimony-category-modal" class="metis-modal-backdrop" hidden aria-hidden="true">
    <div class="metis-modal metis-testimonies-modal">
        <h3 class="metis-modal-title">Category</h3>
        <form id="metis-testimony-category-form" class="metis-form-grid">
            <input type="hidden" name="id" value="0">
            <div class="metis-field metis-field-half"><label>Name</label><input class="metis-input" name="name" type="text" required></div>
            <div class="metis-field metis-field-half"><label>Slug</label><input class="metis-input" name="slug" type="text"></div>
            <div class="metis-field metis-field-half"><label>Sort Order</label><input class="metis-input" name="sort_order" type="number" value="0"></div>
            <div class="metis-field metis-field-half"><label class="metis-se-check-label"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
            <div class="metis-form-actions">
                <button type="button" id="metis-testimony-category-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="submit" class="metis-btn">Save Category</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
