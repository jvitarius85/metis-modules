<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_cms_require_view_permission( 'categories' ) ) {
    return;
}

use Metis\Modules\Cms\Services\PostCategoryService;

$categories = PostCategoryService::all( true );
$category_action_nonces = [
    'save' => '',
    'delete' => '',
];
if ( function_exists( 'metis_ajax_action_nonces' ) ) {
    $runtime_action_nonces = metis_ajax_action_nonces();
    $category_action_nonces['save'] = isset( $runtime_action_nonces['metis_cms_post_category_save'] ) ? (string) $runtime_action_nonces['metis_cms_post_category_save'] : '';
    $category_action_nonces['delete'] = isset( $runtime_action_nonces['metis_cms_post_category_delete'] ) ? (string) $runtime_action_nonces['metis_cms_post_category_delete'] : '';
} elseif ( function_exists( 'metis_runtime_create_nonce' ) ) {
    $category_action_nonces['save'] = (string) metis_runtime_create_nonce( 'metis_ajax:metis_cms_post_category_save' );
    $category_action_nonces['delete'] = (string) metis_runtime_create_nonce( 'metis_ajax:metis_cms_post_category_delete' );
}
$parent_options = array_values(
    array_filter(
        $categories,
        static fn ( $category ): bool => is_array( $category ) && (int) ( $category['id'] ?? 0 ) > 0
    )
);
$parent_option_payload = array_map(
    static function ( array $category ): array {
        return [
            'id'    => (int) ( $category['id'] ?? 0 ),
            'label' => (string) ( $category['indented_name'] ?? $category['name'] ?? '' ),
        ];
    },
    $parent_options
);
if ( function_exists( 'metis_json_encode' ) ) {
    $parent_options_json = (string) metis_json_encode( $parent_option_payload );
} else {
    $encoded = json_encode( $parent_option_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    $parent_options_json = is_string( $encoded ) ? $encoded : '[]';
}
$active_categories = 0;
$assigned_posts = 0;
foreach ( $categories as $category ) {
    if ( (string) ( $category['status'] ?? 'active' ) === 'active' ) {
        ++$active_categories;
    }
    $assigned_posts += (int) ( $category['post_count'] ?? 0 );
}
?>
<div
    id="metis-post-categories-view"
    data-save-nonce="<?php echo metis_escape_attr( $category_action_nonces['save'] ); ?>"
    data-delete-nonce="<?php echo metis_escape_attr( $category_action_nonces['delete'] ); ?>"
>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Post Categories</h1>
        <p class="metis-subtitle"><?php echo count( $categories ); ?> categor<?php echo count( $categories ) === 1 ? 'y' : 'ies'; ?> available for posts.</p>
    </div>
    <div class="metis-page-header-right">
        <button type="button" class="metis-btn metis-btn-primary" id="metis-create-post-category-btn">
            <svg class="metis-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Category
        </button>
    </div>
</div>

<div class="metis-cms-status-grid metis-cms-management-stats">
    <section class="metis-cms-status-card">
        <span class="metis-cms-status-label">Categories</span>
        <strong><?php echo metis_escape_html( (string) count( $categories ) ); ?></strong>
        <span>Available for post organization.</span>
    </section>
    <section class="metis-cms-status-card">
        <span class="metis-cms-status-label">Active</span>
        <strong><?php echo metis_escape_html( (string) $active_categories ); ?></strong>
        <span>Selectable in the post editor.</span>
    </section>
    <section class="metis-cms-status-card">
        <span class="metis-cms-status-label">Assigned Posts</span>
        <strong><?php echo metis_escape_html( (string) $assigned_posts ); ?></strong>
        <span>Total category assignments.</span>
    </section>
</div>

<div class="metis-table-wrap metis-post-categories-wrap">
    <?php if ( empty( $categories ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#128278;</div>
            <h2>No categories yet</h2>
            <p>Create categories here, including child categories when needed, then assign one or more categories per post in the post editor.</p>
            <button type="button" class="metis-btn metis-btn-primary" id="metis-create-post-category-btn-empty">New Category</button>
        </div>
    <?php else : ?>
        <table class="metis-premium-table metis-post-categories-table" role="table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Name</th>
                    <th class="metis-premium-cell" scope="col">Parent</th>
                    <th class="metis-premium-cell" scope="col">Slug</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell" scope="col">Posts</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $categories as $category ) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell">
                        <strong><?php echo metis_escape_html( (string) ( $category['indented_name'] ?? $category['name'] ?? '' ) ); ?></strong>
                    </td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $category['parent_name'] ?? '—' ) ?: '—' ); ?></td>
                    <td class="metis-premium-cell"><code><?php echo metis_escape_html( (string) ( $category['slug'] ?? '' ) ); ?></code></td>
                    <td class="metis-premium-cell"><span class="metis-status metis-status-<?php echo ( (string) ( $category['status'] ?? 'active' ) ) === 'active' ? 'published' : 'draft'; ?>"><?php echo metis_escape_html( ucfirst( (string) ( $category['status'] ?? 'active' ) ) ); ?></span></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $category['post_count'] ?? 0 ) ) ); ?></td>
                    <td class="metis-premium-cell metis-col-right">
                        <div class="metis-table-actions">
                            <button
                                type="button"
                                class="metis-action-btn metis-edit-post-category"
                                data-id="<?php echo metis_escape_attr( (string) ( $category['id'] ?? 0 ) ); ?>"
                                data-name="<?php echo metis_escape_attr( (string) ( $category['name'] ?? '' ) ); ?>"
                                data-slug="<?php echo metis_escape_attr( (string) ( $category['slug'] ?? '' ) ); ?>"
                                data-status="<?php echo metis_escape_attr( (string) ( $category['status'] ?? 'active' ) ); ?>"
                                data-sort-order="<?php echo metis_escape_attr( (string) ( (int) ( $category['sort_order'] ?? 0 ) ) ); ?>"
                                data-parent-id="<?php echo metis_escape_attr( (string) ( (int) ( $category['parent_id'] ?? 0 ) ) ); ?>"
                                title="Edit"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button
                                type="button"
                                class="metis-action-btn metis-action-btn-danger metis-delete-post-category"
                                data-id="<?php echo metis_escape_attr( (string) ( $category['id'] ?? 0 ) ); ?>"
                                data-post-count="<?php echo metis_escape_attr( (string) ( (int) ( $category['post_count'] ?? 0 ) ) ); ?>"
                                title="Delete"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div
    id="metis-post-category-modal"
    class="metis-modal-overlay"
    style="display:none;"
    role="dialog"
    aria-modal="true"
    aria-label="Post Category Editor"
    data-category-options="<?php echo metis_escape_attr( $parent_options_json ?: '[]' ); ?>"
>
    <div class="metis-modal metis-cms-modal-narrow">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title" id="metis-post-category-modal-title">New Category</h2>
            <button type="button" class="metis-modal-close" id="metis-post-category-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body metis-cms-modal-body">
            <div class="metis-field metis-cms-field-stack">
                <label class="metis-label">Name <span class="metis-required-marker">*</span></label>
                <input type="text" id="metis-post-category-name" class="metis-input" placeholder="e.g. News">
            </div>
            <div class="metis-field metis-cms-field-stack">
                <label class="metis-label">Slug</label>
                <input type="text" id="metis-post-category-slug" class="metis-input" placeholder="news">
            </div>
            <div class="metis-field metis-cms-field-stack">
                <label class="metis-label">Parent Category</label>
                <select id="metis-post-category-parent-id" class="metis-input">
                    <option value="0">None</option>
                    <?php foreach ( $parent_options as $option ) : ?>
                        <option value="<?php echo metis_escape_attr( (string) ( (int) ( $option['id'] ?? 0 ) ) ); ?>"><?php echo metis_escape_html( (string) ( $option['indented_name'] ?? $option['name'] ?? '' ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="metis-cms-form-grid">
                <div class="metis-field metis-cms-field-flush">
                    <label class="metis-label">Status</label>
                    <select id="metis-post-category-status" class="metis-input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="metis-field metis-cms-field-flush">
                    <label class="metis-label">Sort Order</label>
                    <input type="number" id="metis-post-category-sort-order" class="metis-input" min="0" step="1" value="0">
                </div>
            </div>
            <input type="hidden" id="metis-post-category-id" value="">
        </div>
        <div class="metis-modal-footer">
            <button type="button" class="metis-btn metis-btn-ghost" id="metis-post-category-cancel-btn">Cancel</button>
            <button type="button" class="metis-btn metis-btn-primary" id="metis-post-category-save-btn">Save Category</button>
        </div>
    </div>
</div>
</div>
