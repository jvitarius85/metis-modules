<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'banners' ) ) {
    return;
}

use Metis\Modules\Website\Services\BannerService;

$banners = BannerService::getAll();
?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Banners</h1>
        <p class="metis-subtitle"><?php echo count( $banners ); ?> banner<?php echo count( $banners ) !== 1 ? 's' : ''; ?> configured for public scheduling and targeting.</p>
    </div>
    <div class="metis-page-header-right">
        <button class="metis-btn metis-btn-primary" id="metis-banner-create-btn">New Banner</button>
    </div>
</div>

<div class="metis-table-wrap">
    <?php if ( $banners === [] ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#128227;</div>
            <h2>No banners yet</h2>
            <p>Create scheduled announcements for the public site.</p>
            <button class="metis-btn metis-btn-primary" id="metis-banner-create-empty-btn">New Banner</button>
        </div>
    <?php else : ?>
        <table class="metis-premium-table metis-banner-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Name</th>
                    <th class="metis-premium-cell" scope="col">Type</th>
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
                        <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( str_replace( '_', ' ', (string) ( $banner['type'] ?? 'top_banner' ) ) ) ); ?></td>
                        <td class="metis-premium-cell metis-table-meta-cell">
                            <?php echo metis_escape_html( (string) ( $banner['start_at'] ?? 'Always' ) ); ?>
                            &rarr;
                            <?php echo metis_escape_html( (string) ( $banner['end_at'] ?? 'Always' ) ); ?>
                        </td>
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
                                    data-timezone="<?php echo metis_escape_attr( (string) ( $banner['timezone'] ?? 'UTC' ) ); ?>"
                                    data-content="<?php echo metis_escape_attr( function_exists( 'metis_json_encode' ) ? metis_json_encode( $content ) : json_encode( $content ) ); ?>"
                                    data-targeting="<?php echo metis_escape_attr( function_exists( 'metis_json_encode' ) ? metis_json_encode( $targeting ) : json_encode( $targeting ) ); ?>"
                                    title="Edit"
                                >
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

<div id="metis-banner-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title" id="metis-banner-modal-title">New Banner</h2>
            <button type="button" class="metis-modal-close" data-modal-close="metis-banner-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <input type="hidden" id="metis-banner-id" value="">
            <div class="metis-form-grid metis-form-grid-3">
                <label>Name<input id="metis-banner-name" type="text" class="metis-editor-input" /></label>
                <label>Type
                    <select id="metis-banner-type" class="metis-editor-input">
                        <option value="top_banner">Top Banner</option>
                        <option value="announcement_bar">Announcement Bar</option>
                        <option value="inline">Inline</option>
                    </select>
                </label>
                <label>Status
                    <select id="metis-banner-status" class="metis-editor-input">
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                    </select>
                </label>
                <label>Dismiss
                    <select id="metis-banner-dismiss" class="metis-editor-input">
                        <option value="session">Once per session</option>
                        <option value="persisted">Persisted</option>
                        <option value="none">Always visible</option>
                    </select>
                </label>
                <label>Start At<input id="metis-banner-start" type="datetime-local" class="metis-editor-input" /></label>
                <label>End At<input id="metis-banner-end" type="datetime-local" class="metis-editor-input" /></label>
                <label>Timezone<input id="metis-banner-timezone" type="text" class="metis-editor-input" placeholder="America/Chicago" value="UTC" /></label>
            </div>
            <div class="metis-form-grid metis-form-grid-2 metis-form-grid-top">
                <label>Banner Text<textarea id="metis-banner-text" class="metis-editor-input" rows="3"></textarea></label>
                <label>CTA Label<textarea id="metis-banner-cta-label" class="metis-editor-input" rows="1"></textarea></label>
                <label>CTA URL<input id="metis-banner-cta-url" type="url" class="metis-editor-input" placeholder="https://example.org" /></label>
                <label>Target Paths (comma separated)<input id="metis-banner-target-paths" type="text" class="metis-editor-input" placeholder="/,/about,/news" /></label>
                <label>Target Slugs (comma separated)<input id="metis-banner-target-slugs" type="text" class="metis-editor-input" placeholder="home,about-us" /></label>
                <label>Target Content Types (comma separated)<input id="metis-banner-target-types" type="text" class="metis-editor-input" placeholder="page,post" /></label>
            </div>
            <label class="metis-inline-toggle">
                <input id="metis-banner-site-wide" type="checkbox" /> Site-wide
            </label>
            <label class="metis-inline-toggle">
                <input id="metis-banner-allow-dismiss" type="checkbox" checked /> Show dismiss button
            </label>
        </div>
        <div class="metis-modal-footer">
            <button id="metis-banner-save-btn" class="metis-btn metis-btn-primary">Save Banner</button>
            <button id="metis-banner-cancel-btn" class="metis-btn metis-btn-ghost" type="button" data-modal-close="metis-banner-modal">Cancel</button>
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

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function splitCsv(value) {
        return String(value || '').split(',').map(function(v){ return v.trim(); }).filter(Boolean);
    }

    function toLocalInput(value) {
        if (!value) return '';
        return String(value).replace(' ', 'T').slice(0, 16);
    }

    function renderBannerTable(banners) {
        var list = Array.isArray(banners) ? banners : [];
        var $wrap = $('.metis-table-wrap').first();
        $('.metis-page-header .metis-subtitle').first().text(list.length + ' banner' + (list.length === 1 ? '' : 's') + ' configured for public scheduling and targeting.');
        $wrap.children('.metis-empty-state, .metis-premium-table.metis-banner-table').remove();

        if (!list.length) {
            $wrap.prepend(
                '<div class="metis-empty-state">' +
                    '<div class="metis-empty-state-icon">&#128227;</div>' +
                    '<h2>No banners yet</h2>' +
                    '<p>Create scheduled announcements for the public site.</p>' +
                    '<button class="metis-btn metis-btn-primary" id="metis-banner-create-empty-btn">New Banner</button>' +
                '</div>'
            );
            return;
        }

        var rows = list.map(function(banner) {
            var targeting = banner && typeof banner.targeting_json === 'string' ? banner.targeting_json : JSON.stringify(banner && banner.targeting_json ? banner.targeting_json : {});
            var content = banner && typeof banner.content_json === 'string' ? banner.content_json : JSON.stringify(banner && banner.content_json ? banner.content_json : {});
            var typeLabel = String(banner && banner.type ? banner.type : 'top_banner').replace(/_/g, ' ');
            typeLabel = typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1);
            var status = String(banner && banner.status ? banner.status : 'draft');
            return '<tr class="metis-premium-row">'
                + '<td class="metis-premium-cell"><strong>' + esc(String(banner && banner.name ? banner.name : '')) + '</strong></td>'
                + '<td class="metis-premium-cell">' + esc(typeLabel) + '</td>'
                + '<td class="metis-premium-cell metis-table-meta-cell">' + esc(String((banner && banner.start_at) || 'Always')) + ' &rarr; ' + esc(String((banner && banner.end_at) || 'Always')) + '</td>'
                + '<td class="metis-premium-cell"><span class="metis-status metis-status-' + esc(status) + '">' + esc(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></td>'
                + '<td class="metis-premium-cell metis-col-right"><div class="metis-table-actions">'
                + '<button class="metis-action-btn metis-banner-edit-btn" data-id="' + esc(String(banner && banner.id ? banner.id : '')) + '" data-name="' + esc(String(banner && banner.name ? banner.name : '')) + '" data-type="' + esc(String(banner && banner.type ? banner.type : 'top_banner')) + '" data-status="' + esc(status) + '" data-dismiss="' + esc(String(banner && banner.dismiss_mode ? banner.dismiss_mode : 'session')) + '" data-start="' + esc(String((banner && banner.start_at) || '')) + '" data-end="' + esc(String((banner && banner.end_at) || '')) + '" data-timezone="' + esc(String((banner && banner.timezone) || 'UTC')) + '" data-content="' + esc(content) + '" data-targeting="' + esc(targeting) + '" title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
                + '<button class="metis-action-btn metis-action-btn-danger metis-banner-delete-btn" data-id="' + esc(String(banner && banner.id ? banner.id : '')) + '" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>'
                + '</div></td>'
                + '</tr>';
        }).join('');

        $wrap.prepend(
            '<table class="metis-premium-table metis-banner-table">' +
                '<thead><tr class="metis-premium-row metis-premium-header">' +
                    '<th class="metis-premium-cell" scope="col">Name</th>' +
                    '<th class="metis-premium-cell" scope="col">Type</th>' +
                    '<th class="metis-premium-cell" scope="col">Schedule</th>' +
                    '<th class="metis-premium-cell" scope="col">Status</th>' +
                    '<th class="metis-premium-cell metis-col-right" scope="col">Actions</th>' +
                '</tr></thead><tbody>' +
                rows +
            '</tbody></table>'
        );
    }

    function openEditor(data) {
        $('#metis-banner-modal-title').text(data && data.id ? 'Edit Banner' : 'New Banner');
        $('#metis-banner-id').val(data.id || '');
        $('#metis-banner-name').val(data.name || '');
        $('#metis-banner-type').val(data.type || 'top_banner');
        $('#metis-banner-status').val(data.status || 'draft');
        $('#metis-banner-dismiss').val(data.dismiss_mode || 'session');
        $('#metis-banner-start').val(toLocalInput(data.start_at || ''));
        $('#metis-banner-end').val(toLocalInput(data.end_at || ''));
        $('#metis-banner-timezone').val(data.timezone || 'UTC');
        $('#metis-banner-text').val(data.text || '');
        $('#metis-banner-cta-label').val(data.cta_label || '');
        $('#metis-banner-cta-url').val(data.cta_url || '');
        $('#metis-banner-site-wide').prop('checked', !!data.site_wide);
        $('#metis-banner-allow-dismiss').prop('checked', data.allow_dismiss !== false);
        $('#metis-banner-target-paths').val((data.paths || []).join(', '));
        $('#metis-banner-target-slugs').val((data.slugs || []).join(', '));
        $('#metis-banner-target-types').val((data.content_types || []).join(', '));
        if (window.Metis && Metis.ui && Metis.ui.modal) {
            Metis.ui.modal.form('metis-banner-modal');
        }
    }

    $(document).on('click', '#metis-banner-create-btn, #metis-banner-create-empty-btn', function(){
        openEditor({});
    });

    $(document).on('click', '.metis-banner-edit-btn', function(){
        var $btn = $(this);
        var content = {};
        var targeting = {};
        try { content = JSON.parse(String($btn.attr('data-content') || '{}')); } catch (e) {}
        try { targeting = JSON.parse(String($btn.attr('data-targeting') || '{}')); } catch (e) {}
        openEditor({
            id: Number($btn.data('id') || 0),
            name: String($btn.data('name') || ''),
            type: String($btn.data('type') || 'top_banner'),
            status: String($btn.data('status') || 'draft'),
            dismiss_mode: String($btn.data('dismiss') || 'session'),
            start_at: String($btn.data('start') || ''),
            end_at: String($btn.data('end') || ''),
            timezone: String($btn.data('timezone') || 'UTC'),
            text: String(content.text || ''),
            cta_label: String(content.cta_label || ''),
            cta_url: String(content.cta_url || ''),
            allow_dismiss: !!content.allow_dismiss,
            site_wide: !!targeting.site_wide,
            paths: Array.isArray(targeting.paths) ? targeting.paths : [],
            slugs: Array.isArray(targeting.slugs) ? targeting.slugs : [],
            content_types: Array.isArray(targeting.content_types) ? targeting.content_types : []
        });
    });

    $(document).on('click', '#metis-banner-save-btn', function(){
        var id = Number($('#metis-banner-id').val() || 0);
        var payload = {
            action: 'metis_website_banner_save',
            nonce: metisWebsiteAjax.nonce,
            name: $('#metis-banner-name').val().trim(),
            type: $('#metis-banner-type').val(),
            status: $('#metis-banner-status').val(),
            dismiss_mode: $('#metis-banner-dismiss').val(),
            start_at: $('#metis-banner-start').val(),
            end_at: $('#metis-banner-end').val(),
            timezone: $('#metis-banner-timezone').val().trim() || 'UTC',
            content_json: JSON.stringify({
                text: $('#metis-banner-text').val(),
                cta_label: $('#metis-banner-cta-label').val(),
                cta_url: $('#metis-banner-cta-url').val(),
                allow_dismiss: $('#metis-banner-allow-dismiss').is(':checked')
            }),
            targeting_json: JSON.stringify({
                site_wide: $('#metis-banner-site-wide').is(':checked'),
                paths: splitCsv($('#metis-banner-target-paths').val()),
                slugs: splitCsv($('#metis-banner-target-slugs').val()),
                content_types: splitCsv($('#metis-banner-target-types').val())
            })
        };
        if (id > 0) payload.id = id;

        $.post(metisWebsiteAjax.ajax_url, payload).done(function(r){
            if (r && r.success) {
                Metis.ui.toast.success('Banner saved.');
                renderBannerTable((r.data && r.data.banners) || []);
                if (window.Metis && Metis.ui && Metis.ui.modal) {
                    Metis.ui.modal.close('metis-banner-modal');
                }
                return;
            }
            Metis.ui.toast.error((r && r.data && r.data.message) || 'Save failed.');
        }).fail(function(){
            Metis.ui.toast.error('Request failed.');
        });
    });

    $(document).on('click', '.metis-banner-delete-btn', function(){
        var id = Number($(this).data('id') || 0);
        if (id < 1) return;
        Metis.ui.confirm.open({ message: 'Delete this banner?', confirmLabel: 'Delete', tone: 'danger' }).then(function(confirmed){
            if (!confirmed) return;
            $.post(metisWebsiteAjax.ajax_url, {
                action: 'metis_website_banner_delete',
                nonce: metisWebsiteAjax.nonce,
                id: id
            }).done(function(r){
                if (r && r.success) {
                    Metis.ui.toast.success('Banner deleted.');
                    renderBannerTable((r.data && r.data.banners) || []);
                    return;
                }
                Metis.ui.toast.error((r && r.data && r.data.message) || 'Delete failed.');
            }).fail(function(){
                Metis.ui.toast.error('Request failed.');
            });
        });
    });
    }
})();
</script>
