<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\HomepageService;
use Metis\Modules\Website\Services\PostService;

require_once __DIR__ . '/_editor_bootstrap.php';

$per_page = 100;
$current_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$total_pages_count = PageService::countAll();
$page_count = max( 1, (int) ceil( $total_pages_count / $per_page ) );
$current_page = min( $current_page, $page_count );
$pages = PageService::getAll(
    [
        'limit' => $per_page,
        'offset' => ( $current_page - 1 ) * $per_page,
    ]
);
$published_pages = PageService::getAll(
    [
        'status' => 'published',
        'fetch_all' => true,
    ]
);
$homepage_page_id = HomepageService::getHomepagePageId();
$editor_page_id = (int) metis_get_query_var( 'metis_editor_page_id' );
$editor_key = (string) metis_get_query_var( 'metis_editor_key' );
$editor_new = (string) metis_get_query_var( 'metis_editor_new' );
$request_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
if ( $editor_new === '' && preg_match( '#/website/page(?:s)?/editor/new/?$#i', $request_path ) === 1 ) {
    $editor_new = 'page';
}
if ( $editor_page_id < 1 && $editor_key === '' ) {
    $matches = [];
    if ( preg_match( '#/website/pages/editor/([A-Za-z0-9_-]+)/?$#i', $request_path, $matches ) === 1 && ! empty( $matches[1] ) ) {
        $raw_ref = (string) $matches[1];
        if ( ctype_digit( $raw_ref ) ) {
            $editor_page_id = (int) $raw_ref;
        } else {
            $editor_key = $raw_ref;
        }
    }
}
$is_editor_route = $editor_page_id > 0 || $editor_key !== '' || $editor_new === 'page' || $editor_new === 'post';

$pages_by_id = [];
$children_by_parent = [];
foreach ( $pages as $page ) {
    if ( ! isset( $page->id ) ) {
        continue;
    }
    $id = (int) $page->id;
    if ( $id < 1 ) {
        continue;
    }
    $pages_by_id[ $id ] = $page;
}
foreach ( $pages_by_id as $id => $page ) {
    $id = (int) $id;
    $parent_id = isset( $page->parent_id ) ? (int) $page->parent_id : 0;
    if ( $parent_id < 1 || $parent_id === $id || ! isset( $pages_by_id[ $parent_id ] ) ) {
        $parent_id = 0;
    }
    if ( ! isset( $children_by_parent[ $parent_id ] ) ) {
        $children_by_parent[ $parent_id ] = [];
    }
    $children_by_parent[ $parent_id ][] = $id;
}

$ordered_page_entries = [];
$visited_page_ids = [];
$append_page_branch = static function ( int $parent_id, int $depth ) use ( &$append_page_branch, &$ordered_page_entries, &$visited_page_ids, $children_by_parent, $pages_by_id ): void {
    if ( ! isset( $children_by_parent[ $parent_id ] ) || ! is_array( $children_by_parent[ $parent_id ] ) ) {
        return;
    }
    foreach ( $children_by_parent[ $parent_id ] as $child_id ) {
        $child_id = (int) $child_id;
        if ( $child_id < 1 || isset( $visited_page_ids[ $child_id ] ) || ! isset( $pages_by_id[ $child_id ] ) ) {
            continue;
        }
        $visited_page_ids[ $child_id ] = true;
        $ordered_page_entries[] = [
            'page' => $pages_by_id[ $child_id ],
            'depth' => $depth,
        ];
        $append_page_branch( $child_id, $depth + 1 );
    }
};

$append_page_branch( 0, 0 );
foreach ( $pages as $page ) {
    $page_id = isset( $page->id ) ? (int) $page->id : 0;
    if ( $page_id < 1 || isset( $visited_page_ids[ $page_id ] ) ) {
        continue;
    }
    $visited_page_ids[ $page_id ] = true;
    $ordered_page_entries[] = [
        'page' => $page,
        'depth' => 0,
    ];
    $append_page_branch( $page_id, 1 );
}

if ( $is_editor_route ) {
    $context = 'website';
    $editor_target_id = 0;
    if ( $editor_new === 'post' || strtoupper( substr( $editor_key, 0, 3 ) ) === 'WBP' ) {
        $context = 'post';
    }
    if ( $editor_page_id > 0 ) {
        $editor_target_id = $editor_page_id;
    } elseif ( $editor_key !== '' ) {
        if ( $context === 'post' ) {
            $post = PostService::getByCode( $editor_key );
            $editor_target_id = $post !== null ? (int) ( $post->id ?? 0 ) : 0;
        } else {
            $page = PageService::getByCode( $editor_key );
            $editor_target_id = $page !== null ? (int) ( $page->id ?? 0 ) : 0;
        }
    }
    metis_website_render_editor_bootstrap(
        [
            'editor_new' => $editor_new,
            'editor_key' => $editor_key,
            'editor_id' => $editor_target_id,
            'editor_context' => $context,
            'editor_kind' => '',
            'editor_page_id' => $editor_page_id,
            'editor_post_id' => 0,
        ]
    );
    return;
}
?>
<div id="mwpb-inline-root" class="<?php echo $is_editor_route ? '' : 'metis-u-hidden'; ?>"></div>
<div id="metis-pages-list-shell" class="<?php echo $is_editor_route ? 'metis-u-hidden' : ''; ?>">
<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Pages</h1>
        <p class="mw-subtitle"><?php echo metis_escape_html( $total_pages_count ); ?> page<?php echo $total_pages_count !== 1 ? 's' : ''; ?> in website content.</p>
    </div>
    <div class="mw-page-header-right">
        <label for="metis-homepage-selector" class="metis-homepage-label">Homepage</label>
        <select id="metis-homepage-selector" class="mw-input mw-input-sm metis-homepage-selector">
            <option value="">Select published page…</option>
            <?php foreach ( $published_pages as $page ) : ?>
                <option value="<?php echo metis_escape_attr( (string) $page->id ); ?>"<?php echo ( $homepage_page_id !== null && (int) $homepage_page_id === (int) $page->id ) ? ' selected' : ''; ?>>
                    <?php echo metis_escape_html( $page->title ); ?> (/<?php echo metis_escape_html( $page->slug ); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-set-homepage-btn">Set Homepage</button>
        <button class="mw-btn mw-btn-primary" id="metis-create-page-btn">
            <svg class="metis-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Page
        </button>
    </div>
</div>

<div class="metis-table-wrap">
    <?php if ( empty( $pages ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#128196;</div>
            <h2>No pages yet</h2>
            <p>Create your first page to get started.</p>
            <button class="mw-btn mw-btn-primary" id="metis-create-page-btn-empty">New Page</button>
        </div>
    <?php else : ?>
        <div class="mw-premium-table metis-pages-table">
            <div class="mw-premium-row mw-premium-header">
                <div class="mw-premium-cell">Title</div>
                <div class="mw-premium-cell">Slug</div>
                <div class="mw-premium-cell">Status</div>
                <div class="mw-premium-cell">Code</div>
                <div class="mw-premium-cell">Updated</div>
                <div class="mw-premium-cell mw-col-right">Actions</div>
            </div>
                <?php foreach ( $ordered_page_entries as $entry ) : ?>
                    <?php $page = $entry['page']; ?>
                    <?php $depth = max( 0, min( 12, (int) ( $entry['depth'] ?? 0 ) ) ); ?>
                    <div class="mw-premium-row">
                        <div class="mw-premium-cell">
                            <strong class="metis-edit-page metis-page-title metis-page-depth-<?php echo metis_escape_attr( (string) $depth ); ?>" data-id="<?php echo metis_escape_attr( (string) $page->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $page->page_code ?? '' ) ); ?>"><?php echo metis_escape_html( $page->title ); ?></strong>
                            <?php if ( $homepage_page_id !== null && (int) $page->id === (int) $homepage_page_id ) : ?>
                                <span class="metis-status metis-status-published metis-homepage-badge">Homepage</span>
                            <?php endif; ?>
                        </div>
                        <div class="mw-premium-cell"><code class="metis-inline-code-muted">/<?php echo metis_escape_html( $page->slug ); ?></code></div>
                        <div class="mw-premium-cell"><span class="metis-status metis-status-<?php echo metis_escape_attr( $page->status ); ?>"><?php echo metis_escape_html( ucfirst( $page->status ) ); ?></span></div>
                        <div class="mw-premium-cell"><code class="metis-inline-code"><?php echo metis_escape_html( $page->page_code ?? '—' ); ?></code></div>
                        <div class="mw-premium-cell metis-table-updated"><?php echo metis_escape_html( $page->updated_at ? date( 'M j, Y', strtotime( $page->updated_at ) ) : '—' ); ?></div>
                        <div class="mw-premium-cell mw-col-right">
                            <div class="metis-table-actions">
                                <button class="metis-action-btn metis-edit-page" data-id="<?php echo metis_escape_attr( (string) $page->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $page->page_code ?? '' ) ); ?>" title="Edit in editor">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <?php if ( $page->status === 'draft' ) : ?>
                                <button class="metis-action-btn metis-action-btn-primary metis-publish-page" data-id="<?php echo metis_escape_attr( (string) $page->id ); ?>" title="Publish">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </button>
                                <?php else : ?>
                                <button class="metis-action-btn metis-unpublish-page" data-id="<?php echo metis_escape_attr( (string) $page->id ); ?>" title="Unpublish">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                                <?php endif; ?>
                                <?php if ( $page->status === 'published' ) : ?>
                                <a href="<?php echo metis_escape_attr( ( $homepage_page_id !== null && (int) $homepage_page_id === (int) $page->id ) ? '/' : '/' . ltrim( (string) $page->slug, '/' ) ); ?>" class="metis-action-btn" title="View live" target="_blank" rel="noopener">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                </a>
                                <?php endif; ?>
                                <button class="metis-action-btn metis-action-btn-danger metis-delete-page" data-id="<?php echo metis_escape_attr( (string) $page->id ); ?>" title="Delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
        </div>
        <?php
        $page_base_params = $_GET;
        unset( $page_base_params['paged'] );
        $page_base_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
        $page_link = static function ( int $target_page ) use ( $page_base_params, $page_base_path ): string {
            $query = $page_base_params;
            if ( $target_page > 1 ) {
                $query['paged'] = $target_page;
            }
            $query_string = http_build_query( $query );
            return $page_base_path . ( $query_string !== '' ? '?' . $query_string : '' );
        };
        ?>
        <?php if ( $page_count > 1 ) : ?>
            <div class="mw-pagination" style="margin-top:16px;">
                <?php if ( $current_page > 1 ) : ?>
                    <a class="mw-btn-xs" href="<?php echo metis_escape_url( $page_link( $current_page - 1 ) ); ?>">Prev</a>
                <?php else : ?>
                    <span class="mw-btn-xs" aria-disabled="true">Prev</span>
                <?php endif; ?>
                <span id="metis-pages-page" class="mw-muted">Page <?php echo metis_escape_html( (string) $current_page ); ?> of <?php echo metis_escape_html( (string) $page_count ); ?></span>
                <?php if ( $current_page < $page_count ) : ?>
                    <a class="mw-btn-xs" href="<?php echo metis_escape_url( $page_link( $current_page + 1 ) ); ?>">Next</a>
                <?php else : ?>
                    <span class="mw-btn-xs" aria-disabled="true">Next</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script>
(function() {
    function init($) {
        $(document).on('click', '#metis-create-page-btn-empty', function() {
            $('#metis-create-page-btn').trigger('click');
        });
        $(function() {
            try {
                var params = new URLSearchParams(window.location.search || '');
                if (String(params.get('qa') || '') === 'create_page') {
                    $('#metis-create-page-btn').trigger('click');
                }
            } catch (_error) {}
        });
    }
    if (window.jQuery) { init(window.jQuery); }
    else { document.addEventListener('DOMContentLoaded', function() { if (window.jQuery) init(window.jQuery); }); }
})();
</script>
