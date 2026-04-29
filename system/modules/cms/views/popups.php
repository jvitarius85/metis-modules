<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Cms\Services\PopupService;

$popups = PopupService::getAll();

$trigger_types = [
    'click'     => 'Button Click',
    'delay'     => 'Time Delay',
    'load'      => 'Page Load',
    'scroll'    => 'Scroll Threshold',
    'exit'      => 'Exit Intent',
];
?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Popups</h1>
        <p class="metis-subtitle"><?php echo count( $popups ); ?> popup<?php echo count( $popups ) !== 1 ? 's' : ''; ?> configured with trigger and display rules.</p>
    </div>
    <div class="metis-page-header-right">
        <button class="metis-btn metis-btn-primary" id="metis-create-popup-btn">
            <svg class="metis-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Popup
        </button>
    </div>
</div>

<div class="metis-table-wrap">
    <?php if ( empty( $popups ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#9741;</div>
            <h2>No popups yet</h2>
            <p>Create a popup to display modals triggered by buttons or page events.</p>
            <button class="metis-btn metis-btn-primary" id="metis-create-popup-btn-empty">New Popup</button>
        </div>
    <?php else : ?>
        <table class="metis-premium-table metis-popup-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Name</th>
                    <th class="metis-premium-cell" scope="col">Trigger</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $popups as $popup ) : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong><?php echo metis_escape_html( $popup['name'] ?? '' ); ?></strong></td>
                        <td class="metis-premium-cell metis-table-meta-cell"><?php echo metis_escape_html( $trigger_types[ $popup['trigger_type'] ?? 'click' ] ?? ucfirst( $popup['trigger_type'] ?? '' ) ); ?></td>
                        <td class="metis-premium-cell"><span class="metis-status metis-status-<?php echo metis_escape_attr( $popup['status'] ?? 'draft' ); ?>"><?php echo metis_escape_html( ucfirst( $popup['status'] ?? 'draft' ) ); ?></span></td>
                        <td class="metis-premium-cell metis-col-right">
                            <div class="metis-table-actions">
                                <button class="metis-action-btn metis-edit-popup"
                                    data-id="<?php echo metis_escape_attr( $popup['id'] ?? '' ); ?>"
                                    data-name="<?php echo metis_escape_attr( $popup['name'] ?? '' ); ?>"
                                    data-trigger="<?php echo metis_escape_attr( $popup['trigger_type'] ?? 'click' ); ?>"
                                    data-trigger-config="<?php echo metis_escape_attr( $popup['trigger_config_json'] ?? '{}' ); ?>"
                                    data-display-rules="<?php echo metis_escape_attr( $popup['display_rules_json'] ?? '{}' ); ?>"
                                    data-layout="<?php echo metis_escape_attr( $popup['layout_json'] ?? '' ); ?>"
                                    data-status="<?php echo metis_escape_attr( $popup['status'] ?? 'draft' ); ?>"
                                    title="Edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button class="metis-action-btn metis-action-btn-danger metis-delete-popup" data-id="<?php echo metis_escape_attr( $popup['id'] ?? '' ); ?>" title="Delete">
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

<!-- Popup editor launches the full-screen builder via JS -->
<script>
(function($) {
'use strict';

function updatePopupSubtitle() {
    var count = $('.metis-popup-table .metis-premium-row').not('.metis-premium-header').length;
    $('.metis-page-header .metis-subtitle').first().text(count + ' popup' + (count === 1 ? '' : 's') + ' configured with trigger and display rules.');
}

$(document).on('click', '#metis-create-popup-btn, #metis-create-popup-btn-empty', function() {
    MetisPopupBuilder.open(null, null, 'click', '{}', 'draft');
});

$(document).on('click', '.metis-edit-popup', function() {
    var $b = $(this);
    MetisPopupBuilder.open(
        $b.data('id'), $b.data('name'), $b.data('trigger'),
        $b.data('trigger-config'), $b.data('status'), $b.data('layout'), $b.data('display-rules')
    );
});

$(document).on('click', '.metis-delete-popup', function() {
    var id = $(this).data('id');
    var name = $(this).closest('.metis-premium-row').find('.metis-premium-cell:first strong').text();
    metis_confirm('Delete popup "' + name + '"?', function() {
        $.ajax({
            url: metisCmsAjax.ajax_url, type: 'POST',
            data: { action: 'metis_cms_popup_delete', nonce: metisCmsAjax.nonce, id: id },
            success: function(r) {
                if (r && r.success) {
                    var $row = $('.metis-delete-popup[data-id="' + String(id || '') + '"]').first().closest('.metis-premium-row');
                    $row.remove();
                    updatePopupSubtitle();
                    metis_toast('Popup deleted.', 'success');
                }
                else { metis_toast((r.data && r.data.message) || 'Delete failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    });
});

})(jQuery);
</script>
