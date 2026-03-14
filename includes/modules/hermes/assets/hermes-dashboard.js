jQuery(function ($) {
    'use strict';

    var $dashboard = $('[data-hermes-dashboard]');
    if (!$dashboard.length) {
        return;
    }

    function activate(group, id) {
        if (!id) {
            return;
        }

        $dashboard.find('[data-hermes-target="' + group + '"]').removeClass('is-active');
        $dashboard.find('[data-hermes-target="' + group + '"][data-hermes-id="' + id + '"]').addClass('is-active');

        $dashboard.find('[data-hermes-panel="' + group + '"]').removeClass('is-active');
        $dashboard.find('[data-hermes-panel="' + group + '"][data-hermes-id="' + id + '"]').addClass('is-active');
    }

    $dashboard.on('click', '[data-hermes-target]', function () {
        var $button = $(this);
        activate(String($button.attr('data-hermes-target') || ''), String($button.attr('data-hermes-id') || ''));
    });
});
