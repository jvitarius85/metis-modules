<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'banners' ) ) {
    return;
}

use Metis\Modules\Website\Services\BannerService;
use Metis\Modules\Website\Services\PageService;

$banners = BannerService::getAll();
$site_timezone = function_exists( 'metis_runtime_timezone_name' ) ? (string) metis_runtime_timezone_name() : 'UTC';

$banner_types = [
    'top_banner' => 'Top banner',
    'announcement_bar' => 'Announcement bar',
    'inline' => 'Inline page banner',
];

$audience_options = [
    'site_wide' => 'Entire website',
    'selected_pages' => 'Selected pages',
    'all_pages' => 'All pages',
    'all_posts' => 'All posts',
];

$page_options = [];
foreach ( PageService::getAll( [ 'status' => 'published', 'fetch_all' => true ] ) as $page ) {
    if ( ! is_object( $page ) ) {
        continue;
    }
    $id = (int) ( $page->id ?? 0 );
    if ( $id < 1 ) {
        continue;
    }
    $path = method_exists( PageService::class, 'publishedPathForPage' ) ? (string) PageService::publishedPathForPage( $page ) : '';
    $title = trim( (string) ( $page->title ?? '' ) );
    $page_options[] = [
        'id' => $id,
        'path' => $path !== '' ? $path : '/',
        'slug' => trim( (string) ( $page->slug ?? '' ) ),
        'label' => $title !== '' ? $title : ( 'Page #' . $id ),
    ];
}
?>
<style>
.metis-campaign-editor{display:grid;gap:20px}
.metis-modal.metis-campaign-modal{width:min(980px,94vw);max-width:980px}
.metis-modal.metis-campaign-modal .metis-modal-body{display:grid;gap:18px;padding:24px}
.metis-campaign-section{border:1px solid #dbe2f0;border-radius:18px;padding:18px;background:#f8fbff}
.metis-campaign-section__head{display:grid;gap:4px;margin-bottom:14px}
.metis-campaign-section__title{margin:0;font-size:18px;font-weight:700;color:#22304a}
.metis-campaign-section__help{margin:0;color:#60708d;font-size:14px;line-height:1.45}
.metis-campaign-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}
.metis-campaign-field{display:grid;gap:6px}
.metis-campaign-field label{font-size:13px;font-weight:700;color:#2b3954}
.metis-campaign-field .metis-input,.metis-campaign-field .metis-ui-select,.metis-campaign-field textarea.metis-input{width:100%}
.metis-campaign-field textarea{min-height:104px;resize:vertical}
.metis-campaign-field-help{font-size:12px;color:#6a7890;line-height:1.4}
.metis-campaign-checklist{display:grid;gap:8px;max-height:240px;overflow:auto;padding:4px}
.metis-campaign-check{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #dbe2f0;border-radius:12px;background:#fff}
.metis-campaign-check input{margin-top:2px}
.metis-campaign-check strong{display:block;font-size:14px;color:#22304a}
.metis-campaign-check span{display:block;font-size:12px;color:#6a7890}
.metis-campaign-inline-note{padding:12px 14px;border-radius:12px;background:#eef4ff;color:#35508a;font-size:13px;line-height:1.45}
.metis-campaign-row-hidden{display:none !important}
@media (max-width: 860px){
  .metis-campaign-grid{grid-template-columns:minmax(0,1fr)}
}
</style>

<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Banners</h1>
        <p class="metis-subtitle" id="metis-banner-subtitle"><?php echo count( $banners ); ?> banner<?php echo count( $banners ) !== 1 ? 's' : ''; ?> ready for public website announcements.</p>
    </div>
    <div class="metis-page-header-right">
        <button class="metis-btn metis-btn-primary" id="metis-banner-create-btn">New Banner</button>
    </div>
</div>

<div class="metis-table-wrap" id="metis-banner-list-region">
    <?php if ( $banners === [] ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#128227;</div>
            <h2>No banners yet</h2>
            <p>Create a top-of-site announcement, inline notice, or dismissible message bar.</p>
            <button class="metis-btn metis-btn-primary" id="metis-banner-create-empty-btn">New Banner</button>
        </div>
    <?php else : ?>
        <table class="metis-premium-table metis-banner-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Banner</th>
                    <th class="metis-premium-cell" scope="col">Placement</th>
                    <th class="metis-premium-cell" scope="col">Schedule</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $banners as $banner ) :
                    $targeting = json_decode( (string) ( $banner['targeting_json'] ?? '{}' ), true );
                    if ( ! is_array( $targeting ) ) {
                        $targeting = [];
                    }
                    $content = json_decode( (string) ( $banner['content_json'] ?? '{}' ), true );
                    if ( ! is_array( $content ) ) {
                        $content = [];
                    }
                ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong><?php echo metis_escape_html( (string) ( $banner['name'] ?? '' ) ); ?></strong></td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $banner_types[ $banner['type'] ?? 'top_banner' ] ?? ucfirst( str_replace( '_', ' ', (string) ( $banner['type'] ?? 'top_banner' ) ) ) ); ?></td>
                        <td class="metis-premium-cell metis-table-meta-cell"><?php echo metis_escape_html( (string) ( $banner['start_at'] ?? 'Always' ) ); ?> &rarr; <?php echo metis_escape_html( (string) ( $banner['end_at'] ?? 'Always' ) ); ?></td>
                        <td class="metis-premium-cell"><span class="metis-status metis-status-<?php echo metis_escape_attr( (string) ( $banner['status'] ?? 'draft' ) ); ?>"><?php echo metis_escape_html( ucfirst( (string) ( $banner['status'] ?? 'draft' ) ) ); ?></span></td>
                        <td class="metis-premium-cell metis-col-right">
                            <div class="metis-table-actions">
                                <button
                                    class="metis-action-btn metis-banner-edit-btn"
                                    data-id="<?php echo metis_escape_attr( (string) ( $banner['id'] ?? '' ) ); ?>"
                                    data-name="<?php echo metis_escape_attr( (string) ( $banner['name'] ?? '' ) ); ?>"
                                    data-type="<?php echo metis_escape_attr( (string) ( $banner['type'] ?? 'top_banner' ) ); ?>"
                                    data-status="<?php echo metis_escape_attr( (string) ( $banner['status'] ?? 'draft' ) ); ?>"
                                    data-dismiss="<?php echo metis_escape_attr( (string) ( $banner['dismiss_mode'] ?? 'session' ) ); ?>"
                                    data-start="<?php echo metis_escape_attr( (string) ( $banner['start_at'] ?? '' ) ); ?>"
                                    data-end="<?php echo metis_escape_attr( (string) ( $banner['end_at'] ?? '' ) ); ?>"
                                    data-content="<?php echo metis_escape_attr( metis_json_encode( $content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?>"
                                    data-targeting="<?php echo metis_escape_attr( metis_json_encode( $targeting, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?>"
                                    title="Edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button class="metis-action-btn metis-action-btn-danger metis-banner-delete-btn" data-id="<?php echo metis_escape_attr( (string) ( $banner['id'] ?? '' ) ); ?>" title="Delete">
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

<script id="metis-banner-page-options" type="application/json"><?php echo metis_json_encode( $page_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<div id="metis-banner-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-campaign-modal">
        <div class="metis-modal-header">
            <div>
                <h2 class="metis-modal-title" id="metis-banner-modal-title">New Banner</h2>
                <p class="metis-campaign-section__help">Configure what visitors see, when they see it, and where it should appear.</p>
            </div>
            <button type="button" class="metis-modal-close" data-modal-close="metis-banner-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <input type="hidden" id="metis-banner-id" value="">

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">Basics</h3>
                    <p class="metis-campaign-section__help">Give the banner a name, choose where it sits on the site, and decide whether it is live.</p>
                </div>
                <div class="metis-campaign-grid">
                    <div class="metis-campaign-field">
                        <label for="metis-banner-name">Internal name</label>
                        <input id="metis-banner-name" type="text" class="metis-input" placeholder="Spring registration notice">
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-banner-type">Placement</label>
                        <select id="metis-banner-type" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $banner_types as $type_key => $type_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $type_key ); ?>"><?php echo metis_escape_html( $type_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-banner-status">Status</label>
                        <select id="metis-banner-status" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-banner-dismiss">Dismiss behavior</label>
                        <select id="metis-banner-dismiss" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <option value="session">Once per session</option>
                            <option value="persisted">Remember across visits</option>
                            <option value="none">Always visible</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">What Visitors See</h3>
                    <p class="metis-campaign-section__help">Write the announcement and optionally add one action button.</p>
                </div>
                <div class="metis-campaign-grid">
                    <div class="metis-campaign-field" style="grid-column:1 / -1">
                        <label for="metis-banner-text">Banner message</label>
                        <textarea id="metis-banner-text" class="metis-input" rows="4" placeholder="Registration closes Friday at 5 PM."></textarea>
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-banner-cta-label">Button label</label>
                        <input id="metis-banner-cta-label" type="text" class="metis-input" placeholder="Register now">
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-banner-cta-url">Button link</label>
                        <input id="metis-banner-cta-url" type="url" class="metis-input" placeholder="https://example.org">
                    </div>
                    <div class="metis-campaign-field">
                        <label class="metis-inline-toggle"><input id="metis-banner-allow-dismiss" type="checkbox" checked> Show dismiss button</label>
                    </div>
                </div>
            </section>

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">When It Shows</h3>
                    <p class="metis-campaign-section__help">Leave dates blank if the banner should run until you remove it. Times use the site timezone: <?php echo metis_escape_html( $site_timezone ); ?>.</p>
                </div>
                <div class="metis-campaign-grid">
                    <div class="metis-campaign-field">
                        <label for="metis-banner-start">Start date and time</label>
                        <input id="metis-banner-start" type="datetime-local" class="metis-input">
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-banner-end">End date and time</label>
                        <input id="metis-banner-end" type="datetime-local" class="metis-input">
                    </div>
                </div>
            </section>

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">Where It Appears</h3>
                    <p class="metis-campaign-section__help">Choose an audience rule without typing page slugs or path lists.</p>
                </div>
                <div class="metis-campaign-grid">
                    <div class="metis-campaign-field">
                        <label for="metis-banner-audience-mode">Audience</label>
                        <select id="metis-banner-audience-mode" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $audience_options as $audience_key => $audience_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $audience_key ); ?>"><?php echo metis_escape_html( $audience_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="metis-banner-page-targets" class="metis-campaign-row-hidden" style="margin-top:14px">
                    <div class="metis-campaign-field">
                        <label for="metis-banner-page-search">Filter pages</label>
                        <input id="metis-banner-page-search" type="search" class="metis-input" placeholder="Search by page title">
                    </div>
                    <div id="metis-banner-page-checklist" class="metis-campaign-checklist"></div>
                </div>
                <div class="metis-campaign-inline-note">For most teams, <strong>Entire website</strong> or <strong>Selected pages</strong> will cover nearly every announcement use case.</div>
            </section>
        </div>
        <div class="metis-modal-footer">
            <button id="metis-banner-save-btn" class="metis-btn metis-btn-primary">Save Banner</button>
            <button class="metis-btn metis-btn-ghost" type="button" data-modal-close="metis-banner-modal">Cancel</button>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';
if (!window.jQuery) {
    document.addEventListener('DOMContentLoaded', function(){
        if (window.jQuery && !window.__metisBannerViewInit) {
            window.__metisBannerViewInit = true;
            init(window.jQuery);
        }
    });
    return;
}
if (window.__metisBannerViewInit) { return; }
window.__metisBannerViewInit = true;
init(window.jQuery);

function init($){
    var pageOptions = parseJson($('#metis-banner-page-options').text(), []);
    var ajaxConfig = window.metisWebsiteAjax || {};

    function parseJson(raw, fallback) {
        try {
            var parsed = JSON.parse(String(raw || ''));
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (err) {
            return fallback;
        }
    }

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function toLocalInput(value) {
        if (!value) return '';
        return String(value).replace(' ', 'T').slice(0, 16);
    }

    function nonceFor(action) {
        if (window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function') {
            return String(Metis.ajax.nonceFor(action, String(ajaxConfig.nonce || '')) || '');
        }
        var map = ajaxConfig.action_nonces && typeof ajaxConfig.action_nonces === 'object' ? ajaxConfig.action_nonces : {};
        return String(map[action] || ajaxConfig.nonce || '');
    }

    function extractErrorMessage(xhr, fallback) {
        var message = '';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            message = String(xhr.responseJSON.data.message || '');
        } else if (xhr && typeof xhr.responseText === 'string' && xhr.responseText) {
            try {
                var parsed = JSON.parse(xhr.responseText);
                if (parsed && parsed.data && parsed.data.message) {
                    message = String(parsed.data.message || '');
                }
            } catch (err) {}
        }
        return message || fallback || 'Request failed.';
    }

    function renderPageChecklist(filter) {
        var query = String(filter || '').trim().toLowerCase();
        var html = '';
        pageOptions.forEach(function(page){
            var label = String(page.label || '');
            var path = String(page.path || '/');
            var haystack = (label + ' ' + path).toLowerCase();
            if (query && haystack.indexOf(query) === -1) return;
            html += '<label class="metis-campaign-check">'
                + '<input type="checkbox" value="' + esc(path) + '">'
                + '<span><strong>' + esc(label) + '</strong><span>' + esc(path) + '</span></span>'
                + '</label>';
        });
        if (!html) {
            html = '<div class="metis-campaign-inline-note">No pages match that filter.</div>';
        }
        $('#metis-banner-page-checklist').html(html);
    }

    function selectedPagePaths() {
        return $('#metis-banner-page-checklist input[type="checkbox"]:checked').map(function(){ return String(this.value || ''); }).get();
    }

    function setSelectedPagePaths(paths) {
        var selected = {};
        (Array.isArray(paths) ? paths : []).forEach(function(path){ selected[String(path || '')] = true; });
        $('#metis-banner-page-checklist input[type="checkbox"]').each(function(){
            this.checked = !!selected[String(this.value || '')];
        });
    }

    function updateAudienceFields() {
        $('#metis-banner-page-targets').toggleClass('metis-campaign-row-hidden', String($('#metis-banner-audience-mode').val() || 'site_wide') !== 'selected_pages');
    }

    function buildTargeting() {
        var audience = String($('#metis-banner-audience-mode').val() || 'site_wide');
        if (audience === 'site_wide') {
            return { site_wide: true, paths: [], slugs: [], content_types: [] };
        }
        if (audience === 'all_pages') {
            return { site_wide: false, paths: [], slugs: [], content_types: ['page'] };
        }
        if (audience === 'all_posts') {
            return { site_wide: false, paths: [], slugs: [], content_types: ['post'] };
        }
        return { site_wide: false, paths: selectedPagePaths(), slugs: [], content_types: [] };
    }

    function openEditor(data) {
        data = data || {};
        var targeting = data.targeting || {};
        var audience = 'site_wide';
        if (targeting && !targeting.site_wide) {
            var contentTypes = Array.isArray(targeting.content_types) ? targeting.content_types : [];
            if (contentTypes.length === 1 && contentTypes[0] === 'page') audience = 'all_pages';
            else if (contentTypes.length === 1 && contentTypes[0] === 'post') audience = 'all_posts';
            else audience = 'selected_pages';
        }

        $('#metis-banner-modal-title').text(data.id ? 'Edit Banner' : 'New Banner');
        $('#metis-banner-id').val(data.id || '');
        $('#metis-banner-name').val(data.name || '');
        $('#metis-banner-type').val(data.type || 'top_banner');
        $('#metis-banner-status').val(data.status || 'draft');
        $('#metis-banner-dismiss').val(data.dismiss_mode || 'session');
        $('#metis-banner-start').val(toLocalInput(data.start_at || ''));
        $('#metis-banner-end').val(toLocalInput(data.end_at || ''));
        $('#metis-banner-text').val(data.text || '');
        $('#metis-banner-cta-label').val(data.cta_label || '');
        $('#metis-banner-cta-url').val(data.cta_url || '');
        $('#metis-banner-allow-dismiss').prop('checked', data.allow_dismiss !== false);
        $('#metis-banner-audience-mode').val(audience);
        renderPageChecklist($('#metis-banner-page-search').val());
        setSelectedPagePaths(Array.isArray(targeting.paths) ? targeting.paths : []);
        updateAudienceFields();
        if (window.Metis && Metis.ui && Metis.ui.select) {
            Metis.ui.select.refresh(document.getElementById('metis-banner-modal'));
        }
        if (window.Metis && Metis.ui && Metis.ui.modal) {
            Metis.ui.modal.form('metis-banner-modal');
        }
    }

    var bannerTypeLabels = <?php echo metis_json_encode( $banner_types, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>;
    var bannerEditIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    var bannerDeleteIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';

    function bannerTypeLabel(type) {
        var key = String(type || 'top_banner');
        return String(bannerTypeLabels[key] || key.replace(/_/g, ' '));
    }

    function bannerAttrs(banner) {
        var content = parseJson(String(banner.content_json || '{}'), {});
        var targeting = parseJson(String(banner.targeting_json || '{}'), {});
        return [
            'data-id="' + esc(String(banner.id || '')) + '"',
            'data-name="' + esc(String(banner.name || '')) + '"',
            'data-type="' + esc(String(banner.type || 'top_banner')) + '"',
            'data-status="' + esc(String(banner.status || 'draft')) + '"',
            'data-dismiss="' + esc(String(banner.dismiss_mode || 'session')) + '"',
            'data-start="' + esc(String(banner.start_at || '')) + '"',
            'data-end="' + esc(String(banner.end_at || '')) + '"',
            'data-content="' + esc(JSON.stringify(content)) + '"',
            'data-targeting="' + esc(JSON.stringify(targeting)) + '"'
        ].join(' ');
    }

    function renderBannerRows(banners) {
        return (Array.isArray(banners) ? banners : []).map(function(banner) {
            var status = String(banner.status || 'draft');
            var startAt = String(banner.start_at || 'Always');
            var endAt = String(banner.end_at || 'Always');
            return '<tr class="metis-premium-row">'
                + '<td class="metis-premium-cell"><strong>' + escapeHtml(String(banner.name || '')) + '</strong></td>'
                + '<td class="metis-premium-cell">' + escapeHtml(bannerTypeLabel(banner.type || 'top_banner')) + '</td>'
                + '<td class="metis-premium-cell metis-table-meta-cell">' + escapeHtml(startAt) + ' &rarr; ' + escapeHtml(endAt) + '</td>'
                + '<td class="metis-premium-cell"><span class="metis-status metis-status-' + esc(status) + '">' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></td>'
                + '<td class="metis-premium-cell metis-col-right"><div class="metis-table-actions">'
                + '<button class="metis-action-btn metis-banner-edit-btn" ' + bannerAttrs(banner) + ' title="Edit">' + bannerEditIcon + '</button>'
                + '<button class="metis-action-btn metis-action-btn-danger metis-banner-delete-btn" data-id="' + esc(String(banner.id || '')) + '" title="Delete">' + bannerDeleteIcon + '</button>'
                + '</div></td></tr>';
        }).join('');
    }

    function renderBannerList(banners) {
        var items = Array.isArray(banners) ? banners : [];
        $('#metis-banner-subtitle').text(items.length + ' banner' + (items.length === 1 ? '' : 's') + ' ready for public website announcements.');
        if (!items.length) {
            $('#metis-banner-list-region').html(
                '<div class="metis-empty-state">'
                + '<div class="metis-empty-state-icon">&#128227;</div>'
                + '<h2>No banners yet</h2>'
                + '<p>Create a top-of-site announcement, inline notice, or dismissible message bar.</p>'
                + '<button class="metis-btn metis-btn-primary" id="metis-banner-create-empty-btn">New Banner</button>'
                + '</div>'
            );
            return;
        }
        $('#metis-banner-list-region').html(
            '<table class="metis-premium-table metis-banner-table">'
            + '<thead><tr class="metis-premium-row metis-premium-header">'
            + '<th class="metis-premium-cell" scope="col">Banner</th>'
            + '<th class="metis-premium-cell" scope="col">Placement</th>'
            + '<th class="metis-premium-cell" scope="col">Schedule</th>'
            + '<th class="metis-premium-cell" scope="col">Status</th>'
            + '<th class="metis-premium-cell metis-col-right" scope="col">Actions</th>'
            + '</tr></thead><tbody>' + renderBannerRows(items) + '</tbody></table>'
        );
    }

    $(document).on('click', '#metis-banner-create-btn, #metis-banner-create-empty-btn', function(){
        openEditor({});
    });

    $(document).on('click', '.metis-banner-edit-btn', function(){
        var $btn = $(this);
        var content = parseJson($btn.attr('data-content'), {});
        var targeting = parseJson($btn.attr('data-targeting'), {});
        openEditor({
            id: Number($btn.data('id') || 0),
            name: String($btn.data('name') || ''),
            type: String($btn.data('type') || 'top_banner'),
            status: String($btn.data('status') || 'draft'),
            dismiss_mode: String($btn.data('dismiss') || 'session'),
            start_at: String($btn.data('start') || ''),
            end_at: String($btn.data('end') || ''),
            text: String(content.text || ''),
            cta_label: String(content.cta_label || ''),
            cta_url: String(content.cta_url || ''),
            allow_dismiss: content.allow_dismiss !== false,
            targeting: targeting
        });
    });

    $(document).on('change', '#metis-banner-audience-mode', updateAudienceFields);
    $(document).on('input', '#metis-banner-page-search', function(){
        var selected = selectedPagePaths();
        renderPageChecklist($(this).val());
        setSelectedPagePaths(selected);
    });

    $(document).on('click', '#metis-banner-save-btn', function(){
        var $saveButton = $(this);
        var id = Number($('#metis-banner-id').val() || 0);
        var name = String($('#metis-banner-name').val() || '').trim();
        var message = String($('#metis-banner-text').val() || '').trim();
        if (!name) {
            Metis.ui.toast.error('Add an internal name for this banner.');
            return;
        }
        if (!message) {
            Metis.ui.toast.error('Add the banner message visitors should see.');
            return;
        }
        if (String($('#metis-banner-audience-mode').val() || 'site_wide') === 'selected_pages' && !selectedPagePaths().length) {
            Metis.ui.toast.error('Choose at least one page for this banner.');
            return;
        }
        var payload = {
            action: 'metis_website_banner_save',
            nonce: nonceFor('metis_website_banner_save'),
            metis_action_nonce: nonceFor('metis_website_banner_save'),
            name: name,
            type: String($('#metis-banner-type').val() || 'top_banner'),
            status: String($('#metis-banner-status').val() || 'draft'),
            dismiss_mode: String($('#metis-banner-dismiss').val() || 'session'),
            start_at: $('#metis-banner-start').val(),
            end_at: $('#metis-banner-end').val(),
            timezone: <?php echo metis_json_encode( $site_timezone ); ?>,
            content_json: JSON.stringify({
                text: message,
                cta_label: String($('#metis-banner-cta-label').val() || '').trim(),
                cta_url: String($('#metis-banner-cta-url').val() || '').trim(),
                allow_dismiss: $('#metis-banner-allow-dismiss').is(':checked')
            }),
            targeting_json: JSON.stringify(buildTargeting())
        };
        if (id > 0) payload.id = id;

        $saveButton.prop('disabled', true).text('Saving...');
        $.post(metisWebsiteAjax.ajax_url, payload).done(function(response){
            if (response && response.success) {
                Metis.ui.toast.success('Banner saved.');
                renderBannerList(response.data && response.data.banners ? response.data.banners : []);
                Metis.modal.close('metis-banner-modal');
                return;
            }
            Metis.ui.toast.error((response && response.data && response.data.message) || 'Save failed.');
        }).fail(function(xhr){
            Metis.ui.toast.error(extractErrorMessage(xhr, 'Request failed.'));
        }).always(function() {
            $saveButton.prop('disabled', false).text('Save Banner');
        });
    });

    $(document).on('click', '.metis-banner-delete-btn', function(){
        var id = Number($(this).data('id') || 0);
        if (id < 1) return;
        Metis.ui.confirm.open({ message: 'Delete this banner?', confirmLabel: 'Delete', tone: 'danger' }).then(function(confirmed){
            if (!confirmed) return;
            $.post(metisWebsiteAjax.ajax_url, {
                action: 'metis_website_banner_delete',
                nonce: nonceFor('metis_website_banner_delete'),
                metis_action_nonce: nonceFor('metis_website_banner_delete'),
                id: id
            }).done(function(response){
                if (response && response.success) {
                    Metis.ui.toast.success('Banner deleted.');
                    renderBannerList(response.data && response.data.banners ? response.data.banners : []);
                    Metis.modal.close('metis-banner-modal');
                    return;
                }
                Metis.ui.toast.error((response && response.data && response.data.message) || 'Delete failed.');
            }).fail(function(xhr){
                Metis.ui.toast.error(extractErrorMessage(xhr, 'Request failed.'));
            });
        });
    });

    renderPageChecklist('');
    updateAudienceFields();
}
})();
</script>
