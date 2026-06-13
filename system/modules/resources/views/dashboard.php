<?php if ( ! defined( 'METIS_ROOT' ) ) exit; ?>
<?php
if ( ! metis_resources_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Resources.</div>';
    return;
}

metis_resources_ensure_schema();

$snapshot = \Metis\Modules\Resources\Repository::listSnapshot();
$types = isset( $snapshot['types'] ) && is_array( $snapshot['types'] ) ? $snapshot['types'] : [];
$categories = isset( $snapshot['categories'] ) && is_array( $snapshot['categories'] ) ? $snapshot['categories'] : [];
$tags = isset( $snapshot['tags'] ) && is_array( $snapshot['tags'] ) ? $snapshot['tags'] : [];
$resources = isset( $snapshot['resources'] ) && is_array( $snapshot['resources'] ) ? $snapshot['resources'] : [];
$stats = isset( $snapshot['stats'] ) && is_array( $snapshot['stats'] ) ? $snapshot['stats'] : [];
$can_manage = metis_resources_can_manage();
$can_delete = metis_resources_can_delete();

$action_names = [
    'metis_resources_type_save',
    'metis_resources_type_delete',
    'metis_resources_category_save',
    'metis_resources_category_delete',
    'metis_resources_tag_save',
    'metis_resources_tag_delete',
    'metis_resources_resource_save',
    'metis_resources_resource_delete',
];
$action_nonces = [];
if ( function_exists( 'metis_ajax_action_nonces' ) ) {
    $runtime_action_nonces = metis_ajax_action_nonces();
    foreach ( $action_names as $action_name ) {
        $action_nonces[ $action_name ] = isset( $runtime_action_nonces[ $action_name ] ) ? (string) $runtime_action_nonces[ $action_name ] : '';
    }
} elseif ( function_exists( 'metis_runtime_create_nonce' ) && function_exists( 'metis_ajax_nonce_action' ) ) {
    foreach ( $action_names as $action_name ) {
        $action_nonces[ $action_name ] = (string) metis_runtime_create_nonce( metis_ajax_nonce_action( $action_name ) );
    }
}
?>

<div class="metis-resources"
     data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
     data-can-delete="<?php echo $can_delete ? '1' : '0'; ?>"
     data-snapshot="<?php echo metis_escape_attr( (string) ( function_exists( 'metis_json_encode' ) ? metis_json_encode( $snapshot ) : json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ); ?>"
     data-action-nonces="<?php echo metis_escape_attr( (string) ( function_exists( 'metis_json_encode' ) ? metis_json_encode( $action_nonces ) : json_encode( $action_nonces, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ); ?>">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Resources' ) ); ?></h1>
            <p class="metis-subtitle">Publish searchable public resource directories with dedicated types, categories, tags, files, and location-aware entries.</p>
        </div>
        <?php if ( $can_manage ) : ?>
            <div class="metis-page-header-right metis-resources-header-actions">
                <button type="button" class="metis-btn metis-btn-primary" data-resource-open="new">New Resource</button>
                <button type="button" class="metis-btn metis-btn-ghost" data-resource-type-open="new">New Type</button>
                <button type="button" class="metis-btn metis-btn-ghost" data-resource-category-open="new">New Category</button>
                <button type="button" class="metis-btn metis-btn-ghost" data-resource-tag-open="new">New Tag</button>
            </div>
        <?php endif; ?>
    </div>

    <div class="metis-resources-stats">
        <section class="metis-card metis-resources-stat-card">
            <div class="metis-resources-stat-label">Resource types</div>
            <strong class="metis-resources-stat-value"><?php echo metis_escape_html( (string) ( $stats['types'] ?? count( $types ) ) ); ?></strong>
            <span class="metis-resources-stat-copy">Top-level directory groupings such as legal help, benefits, or transportation.</span>
        </section>
        <section class="metis-card metis-resources-stat-card">
            <div class="metis-resources-stat-label">Published resources</div>
            <strong class="metis-resources-stat-value"><?php echo metis_escape_html( (string) ( $stats['published_resources'] ?? 0 ) ); ?></strong>
            <span class="metis-resources-stat-copy">Visible on public directory pages and resource detail routes.</span>
        </section>
        <section class="metis-card metis-resources-stat-card">
            <div class="metis-resources-stat-label">Categories</div>
            <strong class="metis-resources-stat-value"><?php echo metis_escape_html( (string) ( $stats['categories'] ?? count( $categories ) ) ); ?></strong>
            <span class="metis-resources-stat-copy">Dedicated archive pages with scoped filtering per resource type.</span>
        </section>
        <section class="metis-card metis-resources-stat-card">
            <div class="metis-resources-stat-label">Tags</div>
            <strong class="metis-resources-stat-value"><?php echo metis_escape_html( (string) ( $stats['tags'] ?? count( $tags ) ) ); ?></strong>
            <span class="metis-resources-stat-copy">Granular refinement for public search and curated category views.</span>
        </section>
    </div>

    <?php metis_render_sidebar_layout( [
        'sidebar' => static function () use ( $types, $categories, $tags, $resources ): void { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Public routes</div>
                <div class="metis-resources-sidebar-copy">
                    <p>Resources publish on dedicated public routes using the pattern <code>/resources/{resource-type}/{category}/{resource-slug}/</code>.</p>
                </div>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Coverage</div>
                <ul class="metis-resources-sidebar-list">
                    <li><?php echo metis_escape_html( (string) count( $types ) ); ?> types</li>
                    <li><?php echo metis_escape_html( (string) count( $categories ) ); ?> categories</li>
                    <li><?php echo metis_escape_html( (string) count( $tags ) ); ?> tags</li>
                    <li><?php echo metis_escape_html( (string) count( $resources ) ); ?> total resources</li>
                </ul>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Editorial guidance</div>
                <div class="metis-resources-sidebar-copy">
                    <p>Use categories for dedicated archive pages, use tags for finer filtering inside a type, and keep expired or incomplete items unpublished until they are ready.</p>
                </div>
            </div>
        <?php },
        'content' => static function () use ( $can_manage ): void { ?>
            <section class="metis-card metis-resources-card">
                <div class="metis-card-header">
                    <div>
                        <h2 class="metis-card-title">Directory Resources</h2>
                        <div class="metis-card-subtitle">Manage public entries, attachments, service area details, and publication state.</div>
                    </div>
                    <?php if ( $can_manage ) : ?>
                        <button type="button" class="metis-btn metis-btn-primary" data-resource-open="new">New Resource</button>
                    <?php endif; ?>
                </div>
                <div id="metis-resources-list-region"></div>
            </section>

            <section class="metis-card metis-resources-card">
                <div class="metis-card-header">
                    <div>
                        <h2 class="metis-card-title">Resource Types</h2>
                        <div class="metis-card-subtitle">Distinct groups with their own category, tag, archive, and SEO structure.</div>
                    </div>
                    <?php if ( $can_manage ) : ?>
                        <button type="button" class="metis-btn metis-btn-ghost" data-resource-type-open="new">New Type</button>
                    <?php endif; ?>
                </div>
                <div id="metis-resources-types-region"></div>
            </section>

            <section class="metis-resources-subgrid">
                <section class="metis-card metis-resources-card">
                    <div class="metis-card-header">
                        <div>
                            <h2 class="metis-card-title">Categories</h2>
                            <div class="metis-card-subtitle">Scoped archive groupings within each resource type.</div>
                        </div>
                        <?php if ( $can_manage ) : ?>
                            <button type="button" class="metis-btn metis-btn-ghost" data-resource-category-open="new">New Category</button>
                        <?php endif; ?>
                    </div>
                    <div id="metis-resources-categories-region"></div>
                </section>

                <section class="metis-card metis-resources-card">
                    <div class="metis-card-header">
                        <div>
                            <h2 class="metis-card-title">Tags</h2>
                            <div class="metis-card-subtitle">Granular refinements scoped to a specific type.</div>
                        </div>
                        <?php if ( $can_manage ) : ?>
                            <button type="button" class="metis-btn metis-btn-ghost" data-resource-tag-open="new">New Tag</button>
                        <?php endif; ?>
                    </div>
                    <div id="metis-resources-tags-region"></div>
                </section>
            </section>
        <?php },
    ] ); ?>
</div>

<?php if ( $can_manage ) : ?>
<div id="metis-resources-type-modal" class="metis-modal-backdrop" hidden aria-hidden="true">
    <div class="metis-modal metis-resources-modal">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title">Resource Type</h2>
            <button type="button" class="metis-modal-close" data-modal-close="metis-resources-type-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <form id="metis-resources-type-form" class="metis-form-grid">
                <input type="hidden" name="id" value="0">
                <input type="hidden" id="metis-resources-type-intro-html" name="intro_html" value="">
                <div class="metis-field metis-field-half"><label>Name</label><input class="metis-input" type="text" name="name" required></div>
                <div class="metis-field metis-field-half"><label>Slug</label><input class="metis-input" type="text" name="slug" placeholder="leave blank to generate"></div>
                <div class="metis-field metis-field-half"><label>Sort Order</label><input class="metis-input" type="number" name="sort_order" value="0"></div>
                <div class="metis-field metis-field-half"><label class="metis-se-check-label"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
                <div class="metis-field metis-field-full">
                    <label>Public Intro</label>
                    <div class="metis-shared-rich-shell">
                        <div class="metis-se-rich-toolbar" data-rich-toolbar="resources-type-intro">
                            <button type="button" class="metis-btn-xs" data-rich-cmd="bold">Bold</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="italic">Italic</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="insertUnorderedList">Bullets</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="formatBlock" data-rich-value="blockquote">Quote</button>
                            <button type="button" class="metis-btn-xs" data-rich-action="link">Link</button>
                        </div>
                        <div id="metis-resources-type-intro-editor" class="metis-input metis-shared-rich-editor" contenteditable="true" data-rich-editor-input="resources-type-intro"></div>
                    </div>
                </div>
                <div class="metis-field metis-field-half"><label>SEO Title</label><input class="metis-input" type="text" name="seo_title"></div>
                <div class="metis-field metis-field-half"><label>SEO Description</label><textarea class="metis-textarea" name="seo_description" rows="3"></textarea></div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn metis-btn-ghost" data-modal-close="metis-resources-type-modal">Cancel</button>
                    <button type="submit" class="metis-btn">Save Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="metis-resources-category-modal" class="metis-modal-backdrop" hidden aria-hidden="true">
    <div class="metis-modal metis-resources-modal">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title">Resource Category</h2>
            <button type="button" class="metis-modal-close" data-modal-close="metis-resources-category-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <form id="metis-resources-category-form" class="metis-form-grid">
                <input type="hidden" name="id" value="0">
                <input type="hidden" id="metis-resources-category-intro-html" name="intro_html" value="">
                <div class="metis-field metis-field-half"><label>Resource Type</label><select class="metis-select" name="resource_type_id" required></select></div>
                <div class="metis-field metis-field-half"><label>Name</label><input class="metis-input" type="text" name="name" required></div>
                <div class="metis-field metis-field-half"><label>Slug</label><input class="metis-input" type="text" name="slug"></div>
                <div class="metis-field metis-field-half"><label>Sort Order</label><input class="metis-input" type="number" name="sort_order" value="0"></div>
                <div class="metis-field metis-field-half"><label class="metis-se-check-label"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
                <div class="metis-field metis-field-full">
                    <label>Archive Intro</label>
                    <div class="metis-shared-rich-shell">
                        <div class="metis-se-rich-toolbar" data-rich-toolbar="resources-category-intro">
                            <button type="button" class="metis-btn-xs" data-rich-cmd="bold">Bold</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="italic">Italic</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="insertUnorderedList">Bullets</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="formatBlock" data-rich-value="blockquote">Quote</button>
                            <button type="button" class="metis-btn-xs" data-rich-action="link">Link</button>
                        </div>
                        <div id="metis-resources-category-intro-editor" class="metis-input metis-shared-rich-editor" contenteditable="true" data-rich-editor-input="resources-category-intro"></div>
                    </div>
                </div>
                <div class="metis-field metis-field-half"><label>SEO Title</label><input class="metis-input" type="text" name="seo_title"></div>
                <div class="metis-field metis-field-half"><label>SEO Description</label><textarea class="metis-textarea" name="seo_description" rows="3"></textarea></div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn metis-btn-ghost" data-modal-close="metis-resources-category-modal">Cancel</button>
                    <button type="submit" class="metis-btn">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="metis-resources-tag-modal" class="metis-modal-backdrop" hidden aria-hidden="true">
    <div class="metis-modal metis-resources-modal metis-resources-modal--compact">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title">Resource Tag</h2>
            <button type="button" class="metis-modal-close" data-modal-close="metis-resources-tag-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <form id="metis-resources-tag-form" class="metis-form-grid">
                <input type="hidden" name="id" value="0">
                <div class="metis-field metis-field-half"><label>Resource Type</label><select class="metis-select" name="resource_type_id" required></select></div>
                <div class="metis-field metis-field-half"><label>Name</label><input class="metis-input" type="text" name="name" required></div>
                <div class="metis-field metis-field-half"><label>Slug</label><input class="metis-input" type="text" name="slug"></div>
                <div class="metis-field metis-field-quarter"><label>Sort Order</label><input class="metis-input" type="number" name="sort_order" value="0"></div>
                <div class="metis-field metis-field-quarter"><label class="metis-se-check-label"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn metis-btn-ghost" data-modal-close="metis-resources-tag-modal">Cancel</button>
                    <button type="submit" class="metis-btn">Save Tag</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="metis-resources-resource-modal" class="metis-modal-backdrop" hidden aria-hidden="true">
    <div class="metis-modal metis-resources-modal metis-resources-modal--wide">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title">Resource</h2>
            <button type="button" class="metis-modal-close" data-modal-close="metis-resources-resource-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <form id="metis-resources-resource-form" class="metis-form-grid" enctype="multipart/form-data">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="existing_logo_token" value="">
                <input type="hidden" name="existing_logo_url" value="">
                <input type="hidden" id="metis-resources-description-html" name="description_html" value="">
                <input type="hidden" id="metis-resources-existing-attachments-json" name="existing_attachments_json" value="[]">
                <div class="metis-field metis-field-half"><label>Resource Type</label><select class="metis-select" name="resource_type_id" required></select></div>
                <div class="metis-field metis-field-half"><label>Status</label><select class="metis-select" name="status"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></div>
                <div class="metis-field metis-field-half"><label>Title</label><input class="metis-input" type="text" name="title" required></div>
                <div class="metis-field metis-field-half"><label>Slug</label><input class="metis-input" type="text" name="slug"></div>
                <div class="metis-field metis-field-half"><label>Organization</label><input class="metis-input" type="text" name="organization_name"></div>
                <div class="metis-field metis-field-quarter"><label>Sort Order</label><input class="metis-input" type="number" name="sort_order" value="0"></div>
                <div class="metis-field metis-field-quarter"><label class="metis-se-check-label"><input type="checkbox" name="is_featured" value="1"> Featured</label></div>
                <div class="metis-field metis-field-full"><label>Summary</label><textarea class="metis-textarea" name="summary" rows="3" placeholder="Short public summary for cards and search results."></textarea></div>
                <div class="metis-field metis-field-full">
                    <label>Full Description</label>
                    <div class="metis-shared-rich-shell">
                        <div class="metis-se-rich-toolbar" data-rich-toolbar="resources-description">
                            <button type="button" class="metis-btn-xs" data-rich-cmd="bold">Bold</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="italic">Italic</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="insertUnorderedList">Bullets</button>
                            <button type="button" class="metis-btn-xs" data-rich-cmd="formatBlock" data-rich-value="blockquote">Quote</button>
                            <button type="button" class="metis-btn-xs" data-rich-action="link">Link</button>
                        </div>
                        <div id="metis-resources-description-editor" class="metis-input metis-shared-rich-editor metis-resources-description-editor" contenteditable="true" data-rich-editor-input="resources-description"></div>
                    </div>
                </div>
                <div class="metis-field metis-field-half"><label>Primary Category</label><select class="metis-select" name="primary_category_id"></select></div>
                <div class="metis-field metis-field-half"><label>Public Website</label><input class="metis-input" type="url" name="website_url" placeholder="https://example.org"></div>
                <div class="metis-field metis-field-half"><label>Phone</label><input class="metis-input" type="text" name="phone"></div>
                <div class="metis-field metis-field-half"><label>Email</label><input class="metis-input" type="email" name="email"></div>
                <div class="metis-field metis-field-half"><label>Logo</label><input class="metis-input" type="file" name="logo_file" accept="image/*"></div>
                <div class="metis-field metis-field-half">
                    <label>Current Logo</label>
                    <div id="metis-resources-logo-preview" class="metis-resources-logo-preview metis-muted">No logo uploaded.</div>
                </div>
                <div class="metis-field metis-field-full">
                    <label>Categories</label>
                    <div id="metis-resources-category-checkboxes" class="metis-resources-check-grid"></div>
                </div>
                <div class="metis-field metis-field-full">
                    <label>Tags</label>
                    <div id="metis-resources-tag-checkboxes" class="metis-resources-check-grid"></div>
                </div>
                <div class="metis-field metis-field-full">
                    <label>Downloadable Files</label>
                    <input class="metis-input" type="file" name="resource_files[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.zip,image/*" multiple>
                    <div id="metis-resources-attachments-list" class="metis-resources-attachment-list"></div>
                </div>
                <div class="metis-field metis-field-half"><label>Address</label><input class="metis-input" type="text" name="address_line1"></div>
                <div class="metis-field metis-field-quarter"><label>City</label><input class="metis-input" type="text" name="city"></div>
                <div class="metis-field metis-field-quarter"><label>State</label><input class="metis-input" type="text" name="state_code" maxlength="32"></div>
                <div class="metis-field metis-field-quarter"><label>County</label><input class="metis-input" type="text" name="county"></div>
                <div class="metis-field metis-field-quarter"><label>Postal Code</label><input class="metis-input" type="text" name="postal_code"></div>
                <div class="metis-field metis-field-half"><label>Service Radius</label><input class="metis-input" type="text" name="service_radius" placeholder="Statewide, 30 miles, virtual only"></div>
                <div class="metis-field metis-field-quarter"><label class="metis-se-check-label"><input type="checkbox" name="is_online" value="1"> Available online</label></div>
                <div class="metis-field metis-field-quarter"><label>Review Due</label><input class="metis-input" type="datetime-local" name="review_due_at"></div>
                <div class="metis-field metis-field-quarter"><label>Expires At</label><input class="metis-input" type="datetime-local" name="expires_at"></div>
                <div class="metis-field metis-field-full"><label>Eligibility Notes</label><textarea class="metis-textarea" name="eligibility_notes" rows="3"></textarea></div>
                <div class="metis-form-actions">
                    <button type="button" class="metis-btn metis-btn-ghost" data-modal-close="metis-resources-resource-modal">Cancel</button>
                    <button type="submit" class="metis-btn">Save Resource</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.metisResourcesAjax = Object.assign({}, window.metisResourcesAjax || {}, {
    action_nonces: Object.assign(
        {},
        (window.metisResourcesAjax && window.metisResourcesAjax.action_nonces) || {},
        <?php echo function_exists( 'metis_json_encode' ) ? (string) metis_json_encode( $action_nonces ) : json_encode( $action_nonces, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>
    )
});
</script>
<?php endif; ?>
