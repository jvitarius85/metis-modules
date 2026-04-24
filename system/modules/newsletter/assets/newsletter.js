/**
 * Metis Newsletter Module
 * List and operations controller for the newsletter module.
 */
(function ($) {
    'use strict';

    var MetisNewsletter = window.MetisNewsletter || {};

    function initMetisNewsletter(context) {
    const scope = context && context.root ? context.root : document;
    const $root = $(scope).find('.metis-newsletter').first();
    if (!$root.length) return;
    if ($root.data('metisNewsletterInitialized') === 1) return;
    $root.data('metisNewsletterInitialized', 1);

    const canManage = $root.data('can-manage') === '1' || $root.data('can-manage') === 1;
    const pageData  = (function () {
        try { return JSON.parse($(scope).find('#metis-newsletter-data').first().text() || '{}'); } catch (e) { return {}; }
    })();

    const ui            = pageData.ui       || {};
    if (Array.isArray(ui.allowed_blocks)) {
        window.metisNewsletterAllowedBlocks = ui.allowed_blocks;
    }

    function trimTrailingSlash(value) {
        return String(value || '').replace(/\/+$/, '');
    }

    function campaignEditUrl(campaignRef) {
        const ref = String(campaignRef || '').trim();
        const newUrl = String(ui.compose_url || '').trim();
        const editBase = trimTrailingSlash(ui.edit_url_base || '');
        if (!ref) return newUrl || (trimTrailingSlash(ui.compose_url || ui.campaigns_url || window.location.pathname) + '/new/');
        if (editBase) {
            return editBase + '/' + encodeURIComponent(ref) + '/edit/';
        }
        const base = trimTrailingSlash(ui.edit_url_base || ui.campaigns_url || window.location.pathname);
        return base + '/' + encodeURIComponent(ref) + '/edit/';
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX helper                                                         */
    /* ------------------------------------------------------------------ */
    function metisAjax(action, data, onSuccess, onFail) {
        Metis.request.post(window.metisNewsletterAjax || null, action, data || {}, 'Newsletter AJAX not configured.')
            .then(r => {
                if (onSuccess) onSuccess(r || {});
            })
            .catch(e => onFail && onFail(e.message || 'Network error.'));
    }

    function toast(msg, type) {
        Metis.util.notify(msg, type === 'error' ? 'error' : 'success');
    }

    function initLayoutGallery() {
        const $gallery = $('#metis-newsletter-layout-gallery-grid');
        if (!$gallery.length || !canManage) return;
        $gallery.on('click', '.metis-newsletter-layout-item', function () {
            const $btn = $(this);
            const key = String($btn.data('newsletterLayoutProfile') || '').trim();
            if (!key) return;
            metisAjax('metis_newsletter_layout_profile_save', {
                newsletter_layout_profile: key
            }, function () {
                $gallery.find('.metis-newsletter-layout-item').removeClass('is-active');
                $btn.addClass('is-active');
                $('#metis-newsletter-layout-status').text('Active newsletter layout: ' + $.trim($btn.text()));
                toast('Newsletter layout updated.', 'success');
            }, function (msg) {
                toast(msg || 'Failed to update newsletter layout.', 'error');
            });
        });
    }

    function confirmAction(message, options) {
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({ message: message }, options || {}));
        }
        return Promise.resolve(false);
    }

    function navigate(url) {
        const target = String(url || '').trim();
        if (!target) return false;
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            return Metis.navigation.go(target);
        }
        window.location.assign(target);
        return true;
    }

    function campaignRow(campaignId) {
        return $root.find('.metis-newsletter-row[data-campaign-id="' + String(campaignId || '') + '"]').first();
    }

    function updateCampaignRowStatus(campaignId, status) {
        const $row = campaignRow(campaignId);
        if (!$row.length) return;
        const nextStatus = String(status || '').trim().toLowerCase();
        const isSentish = nextStatus === 'sent' || nextStatus === 'sending' || nextStatus === 'archived';
        const $statusChip = $row.children('.metis-premium-cell').eq(3).find('.metis-chip').first();
        const $actionsCell = $row.find('.metis-newsletter-actions-cell');

        $row.attr('data-campaign-status', nextStatus);
        $row.toggleClass('is-draft', !isSentish);
        if ($statusChip.length) {
            $statusChip.text(nextStatus);
        }

        if ($actionsCell.length) {
            const campaignCode = String($row.attr('data-campaign-code') || '');
            $actionsCell.empty();
            if (nextStatus !== 'archived') {
                $actionsCell.append('<button class="metis-btn-xs metis-newsletter-test-campaign" type="button" data-campaign-id="' + Metis.util.escapeHtml(String(campaignId || '')) + '">Test</button>');
            }
            if (!isSentish) {
                if (nextStatus !== 'queued' && nextStatus !== 'scheduled') {
                    $actionsCell.append('<button class="metis-btn-xs metis-newsletter-edit-campaign" type="button" data-campaign-code="' + Metis.util.escapeHtml(campaignCode) + '" data-campaign-id="' + Metis.util.escapeHtml(String(campaignId || '')) + '">Edit</button>');
                }
                $actionsCell.append('<button class="metis-btn-xs metis-newsletter-queue-campaign" type="button" data-campaign-id="' + Metis.util.escapeHtml(String(campaignId || '')) + '">Send</button>');
                $actionsCell.append('<button class="metis-btn-xs metis-btn-danger metis-newsletter-delete-campaign" type="button" data-campaign-id="' + Metis.util.escapeHtml(String(campaignId || '')) + '">Delete</button>');
            } else if (nextStatus === 'sent') {
                $actionsCell.append('<button class="metis-btn-xs metis-btn-danger metis-newsletter-archive-campaign" type="button" data-campaign-id="' + Metis.util.escapeHtml(String(campaignId || '')) + '">Archive</button>');
            }
        }
    }

    function removeCampaignRow(campaignId) {
        const $row = campaignRow(campaignId);
        if (!$row.length) return;
        const $rowsWrap = $row.parent();
        $row.remove();
        $rowsWrap.find('.metis-premium-row').filter(function () {
            return !$(this).hasClass('metis-newsletter-row') && !$(this).hasClass('metis-premium-header');
        }).remove();
        if (!$rowsWrap.find('.metis-newsletter-row').length) {
            $rowsWrap.append('<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="7">No campaigns yet.</td></tr>');
        }
    }

    var newsletterPromptModal = null;

    function ensureNewsletterPromptModal() {
        if (newsletterPromptModal) return newsletterPromptModal;
        var modal = document.createElement('div');
        modal.className = 'metis-modal-backdrop';
        modal.id = 'metis-newsletter-prompt-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-modal metis-modal-sm">' +
                '<div class="metis-modal-header">' +
                    '<h2 class="metis-modal-title" id="metis-newsletter-prompt-title">Enter Value</h2>' +
                '</div>' +
                '<div class="metis-modal-body">' +
                    '<div class="metis-field metis-newsletter-prompt-input-wrap">' +
                        '<label id="metis-newsletter-prompt-label" for="metis-newsletter-prompt-input">Value</label>' +
                        '<input id="metis-newsletter-prompt-input" class="metis-input metis-input-wide" type="text">' +
                    '</div>' +
                    '<div class="metis-field metis-newsletter-prompt-options-wrap">' +
                        '<label id="metis-newsletter-prompt-options-label">Value</label>' +
                        '<div id="metis-newsletter-prompt-options" class="metis-newsletter-prompt-options" hidden></div>' +
                    '</div>' +
                '</div>' +
                '<div class="metis-modal-footer metis-newsletter-prompt-footer">' +
                    '<button type="button" class="metis-btn metis-btn-ghost" data-newsletter-prompt-cancel>Cancel</button>' +
                    '<button type="button" class="metis-btn" data-newsletter-prompt-submit>Continue</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        if (window.Metis && Metis.modal && typeof Metis.modal.init === 'function') {
            Metis.modal.init(document);
        }
        newsletterPromptModal = modal;
        return modal;
    }

    function promptAction(options) {
        var config = options || {};
        var modal = ensureNewsletterPromptModal();
        var title = modal.querySelector('#metis-newsletter-prompt-title');
        var label = modal.querySelector('#metis-newsletter-prompt-label');
        var optionsLabel = modal.querySelector('#metis-newsletter-prompt-options-label');
        var input = modal.querySelector('#metis-newsletter-prompt-input');
        var optionsHost = modal.querySelector('#metis-newsletter-prompt-options');
        var inputWrap = modal.querySelector('.metis-newsletter-prompt-input-wrap');
        var optionsWrap = modal.querySelector('.metis-newsletter-prompt-options-wrap');
        var cancelButton = modal.querySelector('[data-newsletter-prompt-cancel]');
        var submitButton = modal.querySelector('[data-newsletter-prompt-submit]');
        if (!title || !label || !optionsLabel || !input || !optionsHost || !inputWrap || !optionsWrap || !cancelButton || !submitButton) {
            return Promise.resolve(null);
        }

        title.textContent = String(config.title || 'Enter Value');
        label.textContent = String(config.label || 'Value');
        optionsLabel.textContent = String(config.label || 'Value');
        submitButton.textContent = String(config.confirmLabel || 'Continue');

        var mode = String(config.mode || 'text').toLowerCase();
        var selectedOption = String(config.value || '');
        if (mode === 'select') {
            inputWrap.hidden = true;
            input.hidden = true;
            label.hidden = true;
            optionsWrap.hidden = false;
            optionsHost.hidden = false;
            optionsHost.innerHTML = (Array.isArray(config.options) ? config.options : []).map(function (row) {
                var value = String(row && row.value || '');
                var text = String(row && row.label || value);
                var selected = selectedOption === value ? ' is-selected' : '';
                return '<button type="button" class="metis-newsletter-prompt-option' + selected + '" data-prompt-option="' + escHtml(value) + '">' + escHtml(text) + '</button>';
            }).join('');
        } else {
            optionsWrap.hidden = true;
            optionsHost.hidden = true;
            optionsHost.innerHTML = '';
            inputWrap.hidden = false;
            input.hidden = false;
            label.hidden = false;
            input.type = String(config.inputType || 'text');
            input.placeholder = String(config.placeholder || '');
            input.value = String(config.value || '');
        }

        return new Promise(function (resolve) {
            var settled = false;

            function cleanup() {
                cancelButton.removeEventListener('click', onCancel);
                submitButton.removeEventListener('click', onSubmit);
                optionsHost.removeEventListener('click', onOptionClick);
                modal.removeEventListener('click', onBackdrop);
                document.removeEventListener('keydown', onKeyDown);
            }

            function finish(value) {
                if (settled) return;
                settled = true;
                cleanup();
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                resolve(value);
            }

            function currentValue() {
                return mode === 'select' ? selectedOption : String(input.value || '').trim();
            }

            function onCancel() { finish(null); }
            function onSubmit() { finish(currentValue()); }
            function onOptionClick(event) {
                var button = event.target.closest('[data-prompt-option]');
                if (!button) return;
                selectedOption = String(button.getAttribute('data-prompt-option') || '');
                optionsHost.querySelectorAll('.metis-newsletter-prompt-option').forEach(function (node) {
                    node.classList.toggle('is-selected', node === button);
                });
            }
            function onBackdrop(event) {
                if (event.target === modal) finish(null);
            }
            function onKeyDown(event) {
                if (event.key === 'Escape') finish(null);
                if (event.key === 'Enter') finish(currentValue());
            }

            cancelButton.addEventListener('click', onCancel);
            submitButton.addEventListener('click', onSubmit);
            optionsHost.addEventListener('click', onOptionClick);
            modal.addEventListener('click', onBackdrop);
            document.addEventListener('keydown', onKeyDown);
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            window.setTimeout(function () {
                if (mode === 'select') {
                    var selectedButton = optionsHost.querySelector('.metis-newsletter-prompt-option.is-selected') || optionsHost.querySelector('.metis-newsletter-prompt-option');
                    if (selectedButton) selectedButton.focus();
                }
                else input.focus();
            }, 0);
        });
    }

    function ensureThemeImageSettingsModal() {
        if (themeImageSettingsModal) return themeImageSettingsModal;
        var modal = document.createElement('div');
        modal.className = 'metis-modal-backdrop';
        modal.id = 'metis-newsletter-image-settings-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-modal metis-modal-sm metis-newsletter-image-settings-modal">' +
                '<div class="metis-modal-header">' +
                    '<h2 class="metis-modal-title">Image Settings</h2>' +
                '</div>' +
                '<div class="metis-modal-body">' +
                    '<div class="metis-newsletter-image-settings-group">' +
                        '<div class="metis-newsletter-image-settings-label">Size</div>' +
                        '<div class="metis-newsletter-image-settings-options" data-image-settings-size></div>' +
                    '</div>' +
                    '<div class="metis-newsletter-image-settings-group">' +
                        '<div class="metis-newsletter-image-settings-label">Alignment</div>' +
                        '<div class="metis-newsletter-image-settings-options" data-image-settings-align></div>' +
                    '</div>' +
                '</div>' +
                '<div class="metis-modal-footer metis-newsletter-image-settings-footer">' +
                    '<button type="button" class="metis-btn metis-btn-ghost" data-image-settings-cancel>Cancel</button>' +
                    '<button type="button" class="metis-btn" data-image-settings-apply>Apply</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        themeImageSettingsModal = modal;
        return modal;
    }

    function openThemeImageSettingsModal(currentSize, currentAlign) {
        var modal = ensureThemeImageSettingsModal();
        var sizeHost = modal.querySelector('[data-image-settings-size]');
        var alignHost = modal.querySelector('[data-image-settings-align]');
        var cancelButton = modal.querySelector('[data-image-settings-cancel]');
        var applyButton = modal.querySelector('[data-image-settings-apply]');
        if (!sizeHost || !alignHost || !cancelButton || !applyButton) return Promise.resolve(null);

        var selectedSize = String(currentSize || 'medium');
        var selectedAlign = String(currentAlign || 'center');
        sizeHost.innerHTML = [
            ['small', 'Small'],
            ['medium', 'Medium'],
            ['large', 'Large'],
            ['full', 'Full Width']
        ].map(function (row) {
            return '<button type="button" class="metis-newsletter-image-settings-option' + (selectedSize === row[0] ? ' is-selected' : '') + '" data-image-size-option="' + escHtml(row[0]) + '">' + escHtml(row[1]) + '</button>';
        }).join('');
        alignHost.innerHTML = [
            ['left', 'Left'],
            ['center', 'Center'],
            ['right', 'Right']
        ].map(function (row) {
            return '<button type="button" class="metis-newsletter-image-settings-option' + (selectedAlign === row[0] ? ' is-selected' : '') + '" data-image-align-option="' + escHtml(row[0]) + '">' + escHtml(row[1]) + '</button>';
        }).join('');

        return new Promise(function (resolve) {
            var settled = false;
            function cleanup() {
                modal.removeEventListener('click', onBackdrop);
                cancelButton.removeEventListener('click', onCancel);
                applyButton.removeEventListener('click', onApply);
                sizeHost.removeEventListener('click', onSizeClick);
                alignHost.removeEventListener('click', onAlignClick);
                document.removeEventListener('keydown', onKeyDown);
            }
            function finish(value) {
                if (settled) return;
                settled = true;
                cleanup();
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                resolve(value);
            }
            function onBackdrop(event) { if (event.target === modal) finish(null); }
            function onCancel() { finish(null); }
            function onApply() { finish({ size: selectedSize, align: selectedAlign }); }
            function onSizeClick(event) {
                var button = event.target.closest('[data-image-size-option]');
                if (!button) return;
                selectedSize = String(button.getAttribute('data-image-size-option') || 'medium');
                sizeHost.querySelectorAll('.metis-newsletter-image-settings-option').forEach(function (node) {
                    node.classList.toggle('is-selected', node === button);
                });
            }
            function onAlignClick(event) {
                var button = event.target.closest('[data-image-align-option]');
                if (!button) return;
                selectedAlign = String(button.getAttribute('data-image-align-option') || 'center');
                alignHost.querySelectorAll('.metis-newsletter-image-settings-option').forEach(function (node) {
                    node.classList.toggle('is-selected', node === button);
                });
            }
            function onKeyDown(event) {
                if (event.key === 'Escape') finish(null);
                if (event.key === 'Enter') finish({ size: selectedSize, align: selectedAlign });
            }
            modal.addEventListener('click', onBackdrop);
            cancelButton.addEventListener('click', onCancel);
            applyButton.addEventListener('click', onApply);
            sizeHost.addEventListener('click', onSizeClick);
            alignHost.addEventListener('click', onAlignClick);
            document.addEventListener('keydown', onKeyDown);
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    }

    initLayoutGallery();
    initThemeDefaultsCard();

    var themeSelectionStore = {};
    var themeInlineImageState = {
        search: '',
        mime: '',
        targetKey: ''
    };
    var themeImageSettingsModal = null;
    var themePreviewBooted = false;

    function appBasePath() {
        var ajax = String(window.metisNewsletterAjax || '').trim();
        if (ajax) {
            try {
                var url = new URL(ajax, window.location.origin);
                var path = String(url.pathname || '').replace(/\/api\/ajax\/?$/i, '');
                return path.replace(/\/+$/, '');
            } catch (_err) {}
        }
        var pathname = String(window.location.pathname || '');
        var adminPos = pathname.toLowerCase().indexOf('/admin/');
        if (adminPos > -1) return pathname.slice(0, adminPos).replace(/\/+$/, '');
        return '';
    }

    function iconUrl(slug) {
        return appBasePath() + '/assets/Images/icons/' + encodeURIComponent(String(slug || '')) + '.svg';
    }

    function escHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function syncThemeEditorValue(key) {
        var surface = document.querySelector('[data-theme-editor-surface="' + key + '"]');
        var hidden = document.getElementById('metis-newsletter-theme-' + key + '-html');
        if (!surface || !hidden) return;
        hidden.value = surface.innerHTML.trim();
    }

    function setSelectxTriggerContent(dd, option) {
        if (!dd) return;
        var trigger = dd.querySelector('.metis-theme-selectx-trigger');
        if (!trigger) return;
        var labelNode = dd.querySelector('.metis-theme-selectx-label');
        if (labelNode) labelNode.textContent = option ? String(option.textContent || '').trim() : '';
        var color = option ? String(option.getAttribute('data-color') || '').trim() : '';
        var dot = dd.querySelector('.metis-theme-selectx-dot');
        if (color) {
            if (!dot) {
                dot = document.createElement('span');
                dot.className = 'metis-theme-selectx-dot';
                trigger.insertBefore(dot, trigger.firstChild);
            }
            dot.style.background = color === 'transparent' ? 'transparent' : color;
        } else if (dot) {
            dot.remove();
        }
    }

    function buildThemeSelectx() {
        document.querySelectorAll('#metis-newsletter-theme-card select').forEach(function (select) {
            if (select.dataset.newsletterSelectxBuilt === '1') return;
            select.dataset.newsletterSelectxBuilt = '1';
            select.style.display = 'none';
            var wrap = document.createElement('div');
            wrap.className = 'metis-theme-selectx metis-newsletter-theme-selectx';
            wrap.dataset.for = select.id || '';
            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'metis-input metis-theme-selectx-trigger';
            var label = document.createElement('span');
            label.className = 'metis-theme-selectx-label';
            trigger.appendChild(label);
            var menu = document.createElement('div');
            menu.className = 'metis-theme-selectx-menu';
            Array.prototype.forEach.call(select.options, function (opt) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'metis-theme-selectx-option' + (opt.selected ? ' is-selected' : '');
                btn.dataset.value = opt.value;
                var color = String(opt.getAttribute('data-color') || '').trim();
                if (color) {
                    btn.setAttribute('data-color', color);
                    var dot = document.createElement('span');
                    dot.className = 'metis-theme-selectx-dot';
                    dot.style.background = color === 'transparent' ? 'transparent' : color;
                    btn.appendChild(dot);
                }
                var text = document.createElement('span');
                text.textContent = opt.textContent;
                btn.appendChild(text);
                menu.appendChild(btn);
            });
            wrap.appendChild(trigger);
            wrap.appendChild(menu);
            select.parentNode.insertBefore(wrap, select.nextSibling);
            setSelectxTriggerContent(wrap, select.options[select.selectedIndex] || null);
        });
    }

    function parseSpacingBox(raw) {
        var parts = String(raw || '').trim().split(/\s+/).filter(Boolean);
        return {
            top: parts[0] || '0',
            right: parts[1] || parts[0] || '0',
            bottom: parts[2] || parts[0] || '0',
            left: parts[3] || parts[1] || parts[0] || '0'
        };
    }

    function syncPaddingBox(box) {
        var $box = $(box);
        var group = String($box.data('paddingGroup') || '').trim();
        if (!group) return;
        var linked = String($box.find('.metis-theme-box4-link').attr('data-linked') || '1') === '1';
        var top = String($box.find('.metis-theme-box4-input[data-side="top"]').val() || '').trim() || '0';
        var right = String($box.find('.metis-theme-box4-input[data-side="right"]').val() || '').trim() || top;
        var bottom = String($box.find('.metis-theme-box4-input[data-side="bottom"]').val() || '').trim() || top;
        var left = String($box.find('.metis-theme-box4-input[data-side="left"]').val() || '').trim() || right;
        if (linked) {
            right = bottom = left = top;
            $box.find('.metis-theme-box4-input[data-side="right"]').val(right);
            $box.find('.metis-theme-box4-input[data-side="bottom"]').val(bottom);
            $box.find('.metis-theme-box4-input[data-side="left"]').val(left);
        }
        $('#metis-newsletter-theme-' + group + '-padding').val([top, right, bottom, left].join(' '));
    }

    function hydratePaddingBoxes() {
        $('#metis-newsletter-theme-card .metis-newsletter-theme-box4').each(function () {
            var $box = $(this);
            var group = String($box.data('paddingGroup') || '').trim();
            if (!group) return;
            var parsed = parseSpacingBox($('#metis-newsletter-theme-' + group + '-padding').val() || '0 0 0 0');
            $box.find('.metis-theme-box4-input[data-side="top"]').val(parsed.top);
            $box.find('.metis-theme-box4-input[data-side="right"]').val(parsed.right);
            $box.find('.metis-theme-box4-input[data-side="bottom"]').val(parsed.bottom);
            $box.find('.metis-theme-box4-input[data-side="left"]').val(parsed.left);
            var linked = parsed.top === parsed.right && parsed.right === parsed.bottom && parsed.bottom === parsed.left;
            $box.find('.metis-theme-box4-link').attr('data-linked', linked ? '1' : '0').toggleClass('is-linked', linked);
            syncPaddingBox($box[0]);
        });
    }

    function inlineImageSizeClass(value) {
        var raw = String(value || '').toLowerCase().trim();
        if (raw === 'small' || raw === 'medium' || raw === 'large' || raw === 'full') return raw;
        return 'medium';
    }

    function themeWidthPresetValue(mode) {
        if (mode === 'narrow') return 560;
        if (mode === 'wide') return 760;
        return 680;
    }

    function activeThemeWidthMode() {
        var mode = String($('#metis-newsletter-theme-width-mode').val() || 'normal').trim().toLowerCase();
        if (mode === 'narrow' || mode === 'wide') return mode;
        return 'normal';
    }

    function themeToolbarDropdown(icon, menuHtml, extraClass, label) {
        return '<div class="metis-se-rich-dropdown ' + escHtml(extraClass || '') + '">' +
            '<button type="button" class="metis-se-toolbtn metis-se-rich-menu-trigger metis-se-rich-icon-btn" data-theme-rich-toggle="menu" title="' + escHtml(label || 'Menu') + '" aria-label="' + escHtml(label || 'Menu') + '">' +
                '<img src="' + escHtml(iconUrl(icon)) + '" alt="" aria-hidden="true">' +
            '</button>' +
            '<div class="metis-se-rich-menu">' + menuHtml + '</div>' +
        '</div>';
    }

    function themeColorOptions(selectId) {
        var select = document.getElementById(selectId);
        if (!select) return [];
        return Array.prototype.map.call(select.options, function (opt) {
            return {
                value: String(opt.value || ''),
                label: String(opt.textContent || ''),
                color: String(opt.getAttribute('data-color') || '')
            };
        });
    }

    function buildThemeToolbar(key) {
        var colorMenu = themeColorOptions('metis-newsletter-theme-' + key + '-text-color').map(function (row) {
            return '<button type="button" class="metis-se-rich-menu-item metis-se-rich-menu-item--color" data-theme-rich-action="color" data-theme-target="' + escHtml(key) + '" data-theme-value="' + escHtml(row.value) + '" data-theme-color="' + escHtml(row.color) + '">' +
                '<span class="metis-se-color-swatch" style="background:' + escHtml(row.color || 'transparent') + '"></span><span>' + escHtml(row.label) + '</span></button>';
        }).join('');
        var mergeMenu = [
            ['First Name', '{{first_name}}'],
            ['Last Name', '{{last_name}}'],
            ['Full Name', '{{full_name}}'],
            ['Preferred Name', '{{name}}'],
            ['Email', '{{email}}'],
            ['City', '{{city}}'],
            ['State', '{{state}}'],
            ['Campaign Name', '{{campaign_name}}'],
            ['Unsubscribe Link', '{{unsubscribe_url}}'],
            ['Manage Subscription Link', '{{manage_subscription_url}}'],
            ['View Online Link', '{{view_online_url}}']
        ].map(function (row) {
            return '<button type="button" class="metis-se-rich-menu-item" data-theme-rich-action="merge" data-theme-target="' + escHtml(key) + '" data-theme-value="' + escHtml(row[1]) + '">' + escHtml(row[0]) + '</button>';
        }).join('');
        var blockMenu = [['P','Paragraph'],['H1','Heading 1'],['H2','Heading 2'],['H3','Heading 3'],['H4','Heading 4'],['PRE','Code']].map(function (row) {
            return '<button type="button" class="metis-se-rich-menu-item" data-theme-rich-action="block" data-theme-target="' + escHtml(key) + '" data-theme-value="' + escHtml(row[0]) + '">' + escHtml(row[1]) + '</button>';
        }).join('');
        var sizeMenu = [['default','Default'],['sm','Small'],['lg','Large'],['xl','Large+']].map(function (row) {
            return '<button type="button" class="metis-se-rich-menu-item" data-theme-rich-action="size" data-theme-target="' + escHtml(key) + '" data-theme-value="' + escHtml(row[0]) + '">' + escHtml(row[1]) + '</button>';
        }).join('');
        var weightMenu = [['600','Semi Bold'],['700','Bold'],['800','Extra Bold']].map(function (row) {
            return '<button type="button" class="metis-se-rich-menu-item" data-theme-rich-action="weight" data-theme-target="' + escHtml(key) + '" data-theme-value="' + escHtml(row[0]) + '">' + escHtml(row[1]) + '</button>';
        }).join('');
        var btns = [
            ['italic','italic','Italic'],
            ['underline','text-underline','Underline'],
            ['strikeThrough','text-strikethrough','Strike Through'],
            ['justifyLeft','text-align-left','Align Left'],
            ['justifyCenter','text-align-center','Align Center'],
            ['justifyRight','text-align-right','Align Right'],
            ['insertImagePrompt','image','Insert Image'],
            ['createLink','link','Insert Link'],
            ['unlink','close-outline','Remove Link'],
            ['insertDivider','divider','Insert Divider'],
            ['undo','undo','Undo'],
            ['redo','redo','Redo']
        ].map(function (row) {
            return '<button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-theme-rich-cmd="' + escHtml(row[0]) + '" data-theme-target="' + escHtml(key) + '" title="' + escHtml(row[2]) + '" aria-label="' + escHtml(row[2]) + '">' +
                '<img src="' + escHtml(iconUrl(row[1])) + '" alt="" aria-hidden="true"></button>';
        }).join('');
        return '<div class="metis-se-rich-toolbar"><div class="metis-se-rich-group metis-se-rich-group--actions">' +
            themeToolbarDropdown('h1', blockMenu, 'metis-se-rich-dropdown--format', 'Paragraph') +
            themeToolbarDropdown('text-scale', sizeMenu, 'metis-se-rich-dropdown--size', 'Text Size') +
            themeToolbarDropdown('text-color', colorMenu, 'metis-se-rich-dropdown--color', 'Text Color') +
            themeToolbarDropdown('text-bold', weightMenu, 'metis-se-rich-dropdown--weight', 'Weight') +
            btns + themeToolbarDropdown('code', mergeMenu, 'metis-se-rich-dropdown--merge', 'Merge Tags') +
            '</div></div>';
    }

    function mountThemeToolbars() {
        document.querySelectorAll('[data-theme-toolbar]').forEach(function (node) {
            var key = String(node.getAttribute('data-theme-toolbar') || '').trim();
            if (!key) return;
            node.innerHTML = buildThemeToolbar(key);
        });
    }

    function saveThemeSelection(surface) {
        var key = String(surface && surface.getAttribute('data-theme-editor-surface') || '').trim();
        var sel = window.getSelection ? window.getSelection() : null;
        if (!key || !sel || !sel.rangeCount) return;
        var range = sel.getRangeAt(0);
        if (!surface.contains(range.commonAncestorContainer)) return;
        themeSelectionStore[key] = range.cloneRange();
    }

    function restoreThemeSelection(surface) {
        var key = String(surface && surface.getAttribute('data-theme-editor-surface') || '').trim();
        var sel = window.getSelection ? window.getSelection() : null;
        var range = key ? themeSelectionStore[key] : null;
        if (!sel || !range) return null;
        sel.removeAllRanges();
        sel.addRange(range);
        return range;
    }

    function selectedHtml(range) {
        if (!range) return '';
        var div = document.createElement('div');
        div.appendChild(range.cloneContents());
        return div.innerHTML;
    }

    function wrapSelection(surface, html) {
        if (!surface) return;
        restoreThemeSelection(surface);
        document.execCommand('insertHTML', false, html);
        saveThemeSelection(surface);
    }

    function insertHtmlAtThemeSelection(surface, html) {
        if (!surface) return false;
        var range = restoreThemeSelection(surface);
        if (!range) {
            moveThemeCaretToEnd(surface);
            range = restoreThemeSelection(surface);
        }
        if (!range) return false;
        var template = document.createElement('template');
        template.innerHTML = String(html || '');
        var fragment = template.content.cloneNode(true);
        var lastNode = fragment.lastChild || null;
        range.deleteContents();
        range.insertNode(fragment);
        if (lastNode) {
            var nextRange = document.createRange();
            nextRange.setStartAfter(lastNode);
            nextRange.collapse(true);
            var sel = window.getSelection ? window.getSelection() : null;
            if (sel) {
                sel.removeAllRanges();
                sel.addRange(nextRange);
            }
            var key = String(surface.getAttribute('data-theme-editor-surface') || '').trim();
            if (key) {
                themeSelectionStore[key] = nextRange.cloneRange();
            }
        } else {
            saveThemeSelection(surface);
        }
        return true;
    }

    function setInlineImageSize(figure, sizeChoice) {
        if (!figure) return;
        var sizeClass = inlineImageSizeClass(sizeChoice);
        figure.classList.remove('is-small', 'is-medium', 'is-large', 'is-full');
        figure.classList.add('is-' + sizeClass);
        figure.setAttribute('data-size', sizeClass);
    }

    function inlineImageFigureForNode(node) {
        if (!node) return null;
        if (node.nodeType === Node.ELEMENT_NODE && node.matches && node.matches('figure.metis-inline-image')) {
            return node;
        }
        return node.closest ? node.closest('figure.metis-inline-image') : null;
    }

    function setInlineImageAlignment(figure, align) {
        if (!figure) return;
        var nextAlign = align === 'right' ? 'right' : (align === 'center' ? 'center' : 'left');
        figure.setAttribute('data-align', nextAlign);
        figure.style.display = 'block';
        figure.style.maxWidth = '100%';
        if (nextAlign === 'center') {
            figure.style.marginTop = '18px';
            figure.style.marginBottom = '18px';
            figure.style.marginLeft = 'auto';
            figure.style.marginRight = 'auto';
        } else if (nextAlign === 'right') {
            figure.style.marginTop = '18px';
            figure.style.marginBottom = '18px';
            figure.style.marginLeft = 'auto';
            figure.style.marginRight = '0';
        } else {
            figure.style.marginTop = '18px';
            figure.style.marginBottom = '18px';
            figure.style.marginLeft = '0';
            figure.style.marginRight = 'auto';
        }
        var size = String(figure.getAttribute('data-size') || '').trim().toLowerCase();
        if (size === 'full') {
            figure.style.width = '100%';
            figure.style.textAlign = nextAlign;
        } else {
            figure.style.width = 'fit-content';
            figure.style.textAlign = 'inherit';
        }
    }

    function inlineImageAlignment(figure) {
        if (!figure) return 'center';
        var align = String(figure.getAttribute('data-align') || '').trim().toLowerCase();
        return align === 'left' || align === 'right' ? align : 'center';
    }

    function promptThemeImageSettings(currentSize, currentAlign) {
        return openThemeImageSettingsModal(currentSize || 'medium', currentAlign || 'center');
    }

    function normalizeInlineImageForPreview(figure) {
        if (!figure) return;
        var align = inlineImageAlignment(figure);
        var size = 'medium';
        if (figure.classList.contains('is-small')) size = 'small';
        else if (figure.classList.contains('is-large')) size = 'large';
        else if (figure.classList.contains('is-full')) size = 'full';
        else if (figure.classList.contains('is-medium')) size = 'medium';
        else setInlineImageSize(figure, size);
        setInlineImageAlignment(figure, align);
        var img = figure.querySelector('img');
        if (!img) return;
        img.style.display = 'block';
        img.style.height = 'auto';
        img.style.width = size === 'full' ? '100%' : 'auto';
        img.style.maxWidth =
            size === 'small' ? '280px' :
            (size === 'large' ? '720px' :
            (size === 'full' ? '100%' : '520px'));
    }

    function applySpanStyle(surface, styleText) {
        if (!surface) return;
        var range = restoreThemeSelection(surface) || (window.getSelection && window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null);
        if (!range || range.collapsed) return;
        var html = selectedHtml(range);
        wrapSelection(surface, '<span style="' + styleText + '">' + html + '</span>');
    }

    function themeTopLevelNodeForRange(surface, range) {
        if (!surface || !range) return null;
        var node = range.commonAncestorContainer;
        if (!node) return null;
        if (node.nodeType === Node.TEXT_NODE) node = node.parentNode;
        while (node && node.parentNode && node.parentNode !== surface) {
            node = node.parentNode;
        }
        if (node && node !== surface && node.nodeType === Node.ELEMENT_NODE) return node;
        return null;
    }

    function applyThemeAlignment(surface, align) {
        if (!surface || !align) return;
        var range = restoreThemeSelection(surface) || (window.getSelection && window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null);
        var figure = range ? inlineImageFigureForNode(range.commonAncestorContainer && range.commonAncestorContainer.nodeType === Node.TEXT_NODE ? range.commonAncestorContainer.parentNode : range.commonAncestorContainer) : null;
        if (figure) {
            setInlineImageAlignment(figure, align);
            saveThemeSelection(surface);
            return;
        }
        var target = themeTopLevelNodeForRange(surface, range);
        if (target) {
            target.style.textAlign = align;
            if (target.tagName && target.tagName.toLowerCase() === 'figure') {
                setInlineImageAlignment(target, align);
            }
        } else {
            surface.style.textAlign = align;
        }
        saveThemeSelection(surface);
    }

    function themeSurfaceFor(key) {
        return document.querySelector('[data-theme-editor-surface="' + key + '"]');
    }

    function runThemeCommand(surface, cmd) {
        if (!surface || !cmd) return;
        surface.focus();
        restoreThemeSelection(surface);
        if (cmd === 'justifyLeft' || cmd === 'justifyCenter' || cmd === 'justifyRight') {
            applyThemeAlignment(surface, cmd === 'justifyCenter' ? 'center' : (cmd === 'justifyRight' ? 'right' : 'left'));
            return;
        }
        if (cmd === 'createLink') {
            promptAction({
                title: 'Insert Link',
                label: 'Link URL',
                value: 'https://',
                placeholder: 'https://example.com',
                confirmLabel: 'Insert'
            }).then(function (url) {
                if (!url) return;
                surface.focus();
                restoreThemeSelection(surface);
                document.execCommand('createLink', false, url);
                saveThemeSelection(surface);
                syncThemeEditorValue(String(surface.getAttribute('data-theme-editor-surface') || '').trim());
            });
            return;
        } else if (cmd === 'insertImagePrompt') {
            saveThemeSelection(surface);
            openThemeInlineImageModal(String(surface.getAttribute('data-theme-editor-surface') || '').trim());
            return;
        } else if (cmd === 'insertDivider') {
            document.execCommand('insertHTML', false, '<hr class="metis-inline-divider">');
        } else {
            document.execCommand(cmd, false, null);
        }
        saveThemeSelection(surface);
    }

    function themeImageMediaOptions() {
        return (Array.isArray(pageData.media) ? pageData.media : []).filter(function (row) {
            return String((row && (row.mime || row.mime_type)) || '').toLowerCase().indexOf('image/') === 0;
        });
    }

    function themeImageMediaById(id) {
        var targetId = parseInt(String(id || '0'), 10) || 0;
        var found = null;
        themeImageMediaOptions().some(function (row) {
            var rowId = parseInt(String((row && (row.id || row.media_id)) || '0'), 10) || 0;
            if (rowId === targetId) {
                found = row;
                return true;
            }
            return false;
        });
        return found;
    }

    function uniqueThemeImageMimeOptions(rows) {
        var seen = {};
        return rows.map(function (row) {
            return String((row && (row.mime || row.mime_type)) || '').toLowerCase();
        }).filter(function (value) {
            if (!value || seen[value]) return false;
            seen[value] = true;
            return true;
        }).sort();
    }

    function openThemeInlineImageModal(targetKey) {
        var modal = document.getElementById('metis-newsletter-theme-inline-image-modal');
        var searchEl = document.getElementById('metis-newsletter-theme-inline-image-search');
        var mimeEl = document.getElementById('metis-newsletter-theme-inline-image-mime');
        if (!modal) return;
        themeInlineImageState.targetKey = String(targetKey || '').trim();
        var rows = themeImageMediaOptions();
        if (!rows.length) {
            toast('No image media is available yet.', 'error');
            return;
        }
        if (mimeEl) {
            mimeEl.innerHTML = '<option value="">All image types</option>' + uniqueThemeImageMimeOptions(rows).map(function (value) {
                return '<option value="' + escHtml(value) + '">' + escHtml(value.replace('image/', '').toUpperCase()) + '</option>';
            }).join('');
            mimeEl.value = themeInlineImageState.mime || '';
        }
        if (searchEl) searchEl.value = themeInlineImageState.search || '';
        renderThemeInlineImagePickerList();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('metis-modal-open');
        if (searchEl) searchEl.focus();
    }

    function closeThemeInlineImageModal() {
        var modal = document.getElementById('metis-newsletter-theme-inline-image-modal');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('metis-modal-open');
    }

    function renderThemeInlineImagePickerList() {
        var list = document.getElementById('metis-newsletter-theme-inline-image-list');
        var countEl = document.getElementById('metis-newsletter-theme-inline-image-count');
        var searchEl = document.getElementById('metis-newsletter-theme-inline-image-search');
        var mimeEl = document.getElementById('metis-newsletter-theme-inline-image-mime');
        if (!list) return;
        var search = String(searchEl && searchEl.value || themeInlineImageState.search || '').trim().toLowerCase();
        var mime = String(mimeEl && mimeEl.value || themeInlineImageState.mime || '').trim().toLowerCase();
        themeInlineImageState.search = search;
        themeInlineImageState.mime = mime;
        var rows = themeImageMediaOptions().filter(function (row) {
            var rowMime = String((row && (row.mime || row.mime_type)) || '').toLowerCase();
            var haystack = [
                String(row && row.label || ''),
                String(row && row.file_name || ''),
                rowMime
            ].join(' ').toLowerCase();
            if (mime && rowMime !== mime) return false;
            if (search && haystack.indexOf(search) === -1) return false;
            return true;
        });
        if (countEl) countEl.textContent = rows.length ? (rows.length + ' image' + (rows.length === 1 ? '' : 's')) : 'No matches';
        if (!rows.length) {
            list.innerHTML = '<div class="metis-media-empty">No images match the current filters.</div>';
            return;
        }
        list.innerHTML = rows.map(function (row) {
            var rowId = parseInt(String((row && (row.id || row.media_id)) || '0'), 10) || 0;
            return '' +
                '<button type="button" class="metis-media-card" data-theme-inline-image-select="' + escHtml(String(rowId)) + '">' +
                    '<div class="metis-media-thumb-wrap">' +
                        '<img class="metis-media-thumb" src="' + escHtml(String(row && row.url || '')) + '" alt="' + escHtml(String(row && row.label || 'Image')) + '">' +
                    '</div>' +
                    '<div class="metis-media-meta">' +
                        '<div class="metis-media-name">' + escHtml(String(row && row.label || 'Image')) + '</div>' +
                        '<div class="metis-media-mime">' + escHtml(String(row && (row.mime || row.mime_type) || '')) + '</div>' +
                    '</div>' +
                '</button>';
        }).join('');
    }

    function themeColorValue(selectId, fallback) {
        var select = document.getElementById(selectId);
        if (!select) return String(fallback || '');
        var option = select.options[select.selectedIndex] || null;
        var color = String(option && option.getAttribute('data-color') || '').trim();
        if (color) return color;
        var value = String(select.value || '').trim();
        if (value === 'transparent') return 'transparent';
        return value || String(fallback || '');
    }

    function themePreviewContactValue(key) {
        var contact = pageData.theme_preview_contact && typeof pageData.theme_preview_contact === 'object'
            ? pageData.theme_preview_contact
            : {};
        var value = contact[key];
        return value == null ? '' : String(value);
    }

    function renderThemeMergeTags(html) {
        var template = String(html || '');
        if (!template) return '';
        var replacements = {
            first_name: themePreviewContactValue('first_name'),
            last_name: themePreviewContactValue('last_name'),
            full_name: themePreviewContactValue('full_name'),
            name: themePreviewContactValue('name'),
            email: themePreviewContactValue('email'),
            city: themePreviewContactValue('city'),
            state: themePreviewContactValue('state'),
            campaign_name: 'Newsletter Theme Preview',
            unsubscribe_url: '#',
            manage_subscription_url: '#',
            view_online_url: '#',
            view_newsletter_url: '#'
        };
        return template.replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, function (_match, token) {
            var key = String(token || '').toLowerCase();
            return Object.prototype.hasOwnProperty.call(replacements, key) ? replacements[key] : '';
        });
    }

    function themePreviewStyleForSection(key) {
        var bg = themeColorValue('metis-newsletter-theme-' + key + '-bg', 'transparent');
        var color = themeColorValue('metis-newsletter-theme-' + key + '-text-color', '#1f2937');
        var padding = String($('#metis-newsletter-theme-' + key + '-padding').val() || '0 0 0 0').trim() || '0 0 0 0';
        return {
            background: bg,
            color: color,
            padding: padding,
            display: ''
        };
    }

    function renderThemePreview() {
        var canvas = document.getElementById('metis-newsletter-theme-preview-canvas');
        var loading = document.getElementById('metis-newsletter-theme-preview-loading');
        if (!canvas) return;
        ['header', 'personalized', 'closing', 'footer'].forEach(function (key) { syncThemeEditorValue(key); });

        var inner = canvas.querySelector('[data-newsletter-preview-inner]');
        var shell = canvas.querySelector('[data-metis-newsletter-shell="1"]');
        if (!inner || !shell) return;

        var width = themeWidthPresetValue(activeThemeWidthMode());
        var canvasBg = themeColorValue('metis-newsletter-theme-canvas-bg', '#ffffff');
        var textColor = themeColorValue('metis-newsletter-theme-text-color', '#1f2937');
        var fontSize = parseInt($('#metis-newsletter-theme-font-size').val() || '16', 10) || 16;

        shell.style.background = canvasBg;
        shell.style.color = textColor;
        inner.style.width = String(width) + 'px';
        inner.style.minWidth = String(width) + 'px';
        inner.style.maxWidth = String(width) + 'px';
        inner.style.fontSize = String(fontSize) + 'px';
        inner.style.color = textColor;

        ['header', 'personalized', 'closing', 'footer'].forEach(function (key) {
            var node = canvas.querySelector('[data-metis-newsletter-region="' + key + '"]');
            if (!node) return;
            var html = renderThemeMergeTags($('#metis-newsletter-theme-' + key + '-html').val() || '');
            node.innerHTML = html;
            node.style.background = '';
            node.style.color = '';
            node.style.padding = '';
            node.style.display = html.trim() ? '' : 'none';
            $(node).css(themePreviewStyleForSection(key));
        });

        var bodyNode = canvas.querySelector('[data-metis-newsletter-region="body"]');
        if (bodyNode) {
            var sampleBody = '';
            if (pageData.theme_preview_doc && Array.isArray(pageData.theme_preview_doc.blocks) && pageData.theme_preview_doc.blocks[0] && pageData.theme_preview_doc.blocks[0].data) {
                sampleBody = String(pageData.theme_preview_doc.blocks[0].data.body || '');
            }
            bodyNode.innerHTML = renderThemeMergeTags(sampleBody);
            bodyNode.style.color = textColor;
            bodyNode.style.fontSize = String(fontSize) + 'px';
        }

        canvas.querySelectorAll('.metis-inline-divider').forEach(function (node) {
            node.style.borderTopColor = themeColorValue('metis-newsletter-theme-divider-color', '#dfe6f3');
            node.style.borderTopStyle = String($('#metis-newsletter-theme-divider-style').val() || 'solid');
            node.style.borderTopWidth = String(parseInt($('#metis-newsletter-theme-divider-weight').val() || '1', 10) || 1) + 'px';
        });

        canvas.querySelectorAll('.metis-inline-image').forEach(function (figure) {
            normalizeInlineImageForPreview(figure);
        });

        canvas.classList.add('is-ready');
        if (!themePreviewBooted && loading) {
            loading.classList.remove('is-active');
            themePreviewBooted = true;
        }
    }

    function moveThemeCaretToEnd(surface) {
        if (!surface) return;
        var range = document.createRange();
        range.selectNodeContents(surface);
        range.collapse(false);
        var sel = window.getSelection ? window.getSelection() : null;
        if (!sel) return;
        sel.removeAllRanges();
        sel.addRange(range);
        saveThemeSelection(surface);
    }

    function initThemeDefaultsCard() {
        const $card = $('#metis-newsletter-theme-card');
        if (!$card.length || !canManage) return;
        const modal = document.getElementById('metis-newsletter-theme-inline-image-modal');
        if (modal && modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        buildThemeSelectx();
        mountThemeToolbars();
        hydratePaddingBoxes();
        function syncRanges() {
            var widthMode = activeThemeWidthMode();
            var widthValue = themeWidthPresetValue(widthMode);
            $('#metis-newsletter-theme-width').val(String(widthValue));
            $('#metis-newsletter-theme-width-value').text(String(widthValue));
            $card.find('[data-theme-width-mode]').removeClass('is-active').attr('aria-pressed', 'false');
            $card.find('[data-theme-width-mode="' + widthMode + '"]').addClass('is-active').attr('aria-pressed', 'true');
            $('#metis-newsletter-theme-font-size-value').text($('#metis-newsletter-theme-font-size').val() || '16');
            $('#metis-newsletter-theme-divider-weight-value').text($('#metis-newsletter-theme-divider-weight').val() || '1');
        }
        syncRanges();
        renderThemePreview();
        let timer = null;
        ['header','personalized','closing','footer'].forEach(function (key) { syncThemeEditorValue(key); });
        $card.on('input change', 'input, textarea', function () {
            syncRanges();
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 180);
        });
        $card.on('input', '[data-theme-editor-surface]', function () {
            var key = String(this.getAttribute('data-theme-editor-surface') || '').trim();
            if (!key) return;
            syncThemeEditorValue(key);
            saveThemeSelection(this);
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 180);
        });
        $card.on('keyup mouseup focus', '[data-theme-editor-surface]', function () {
            saveThemeSelection(this);
        });
        $card.on('click', '.metis-theme-selectx-trigger', function (e) {
            e.preventDefault();
            var dd = this.closest('.metis-theme-selectx');
            document.querySelectorAll('.metis-theme-selectx.is-open').forEach(function (node) { if (node !== dd) node.classList.remove('is-open'); });
            if (dd) dd.classList.toggle('is-open');
        });
        $card.on('click', '.metis-theme-selectx-option', function (e) {
            e.preventDefault();
            var option = this;
            var dd = option.closest('.metis-theme-selectx');
            var key = dd && dd.dataset ? dd.dataset.for : '';
            var select = key ? document.getElementById(key) : null;
            if (!select) return;
            select.value = option.dataset.value || '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            dd.querySelectorAll('.metis-theme-selectx-option').forEach(function (node) { node.classList.remove('is-selected'); });
            option.classList.add('is-selected');
            setSelectxTriggerContent(dd, option);
            dd.classList.remove('is-open');
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 120);
        });
        $card.on('click', '[data-theme-width-mode]', function (e) {
            e.preventDefault();
            $('#metis-newsletter-theme-width-mode').val(String(this.getAttribute('data-theme-width-mode') || 'normal'));
            $('#metis-newsletter-theme-width').val(String(this.getAttribute('data-theme-width-value') || themeWidthPresetValue(activeThemeWidthMode())));
            syncRanges();
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 80);
        });
        $card.on('click', '.metis-theme-box4-link', function () {
            var $btn = $(this);
            var linked = String($btn.attr('data-linked') || '1') !== '1';
            $btn.attr('data-linked', linked ? '1' : '0').toggleClass('is-linked', linked);
            syncPaddingBox($btn.closest('.metis-newsletter-theme-box4')[0]);
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 120);
        });
        $card.on('input change', '.metis-theme-box4-input', function () {
            var box = $(this).closest('.metis-newsletter-theme-box4')[0];
            syncPaddingBox(box);
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 120);
        });
        $card.on('click', '[data-theme-rich-toggle="menu"]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var dd = this.closest('.metis-se-rich-dropdown');
            document.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) { if (node !== dd) node.classList.remove('is-open'); });
            if (dd) dd.classList.toggle('is-open');
        });
        $card.on('mousedown', '[data-theme-rich-cmd], [data-theme-rich-action], [data-theme-rich-toggle="menu"]', function (e) {
            e.preventDefault();
        });
        $card.on('click', '[data-theme-rich-cmd]', function (e) {
            e.preventDefault();
            var key = String(this.getAttribute('data-theme-target') || '').trim();
            var cmd = String(this.getAttribute('data-theme-rich-cmd') || '').trim();
            var surface = themeSurfaceFor(key);
            runThemeCommand(surface, cmd);
            if (key) syncThemeEditorValue(key);
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 120);
        });
        $card.on('click', '[data-theme-rich-action]', function (e) {
            e.preventDefault();
            var key = String(this.getAttribute('data-theme-target') || '').trim();
            var action = String(this.getAttribute('data-theme-rich-action') || '').trim();
            var value = String(this.getAttribute('data-theme-value') || '').trim();
            var color = String(this.getAttribute('data-theme-color') || '').trim();
            var surface = themeSurfaceFor(key);
            if (!surface) return;
            surface.focus();
            if (action === 'merge') {
                restoreThemeSelection(surface);
                document.execCommand('insertText', false, value);
            } else if (action === 'block') {
                restoreThemeSelection(surface);
                document.execCommand('formatBlock', false, value === 'P' ? '<p>' : '<' + value.toLowerCase() + '>');
            } else if (action === 'size') {
                var sizeMap = { sm: '0.92rem', lg: '1.12rem', xl: '1.28rem' };
                if (value === 'default') {
                    restoreThemeSelection(surface);
                    document.execCommand('removeFormat', false, null);
                } else {
                    applySpanStyle(surface, 'font-size:' + (sizeMap[value] || '1rem'));
                }
            } else if (action === 'color') {
                applySpanStyle(surface, 'color:' + (color || value));
            } else if (action === 'weight') {
                applySpanStyle(surface, 'font-weight:' + value);
            }
            if (key) syncThemeEditorValue(key);
            document.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) { node.classList.remove('is-open'); });
            clearTimeout(timer);
            timer = setTimeout(renderThemePreview, 120);
        });
        $(document).off('click.metisNewsletterInlineImageSelect').on('click.metisNewsletterInlineImageSelect', '[data-theme-inline-image-select]', function (e) {
            e.preventDefault();
            var mediaId = parseInt(String(this.getAttribute('data-theme-inline-image-select') || '0'), 10) || 0;
            var row = themeImageMediaById(mediaId);
            var targetKey = String(themeInlineImageState.targetKey || '').trim();
            var surface = themeSurfaceFor(targetKey);
            if (row && surface) {
                promptThemeImageSettings('medium', 'center').then(function (settingsChoice) {
                    if (!settingsChoice) return;
                    surface.focus();
                    var sizeChoice = settingsChoice.size;
                    var alignChoice = settingsChoice.align;
                    var marginStyle = alignChoice === 'right'
                        ? 'margin:18px 0 18px auto;text-align:right;'
                        : (alignChoice === 'left'
                            ? 'margin:18px auto 18px 0;text-align:left;'
                            : 'margin:18px auto;text-align:center;');
                    insertHtmlAtThemeSelection(surface, '<figure class="metis-inline-image is-' + escHtml(sizeChoice) + '" data-align="' + escHtml(alignChoice) + '" data-size="' + escHtml(sizeChoice) + '" style="' + escHtml(marginStyle) + '"><img src="' + escHtml(String(row.url || '')) + '" alt="' + escHtml(String(row.label || 'Inline image')) + '"></figure>');
                    var insertedFigure = inlineImageFigureForNode(window.getSelection && window.getSelection().anchorNode ? window.getSelection().anchorNode : null);
                    if (insertedFigure) normalizeInlineImageForPreview(insertedFigure);
                    syncThemeEditorValue(targetKey);
                    saveThemeSelection(surface);
                    clearTimeout(timer);
                    timer = setTimeout(renderThemePreview, 120);
                });
            }
            closeThemeInlineImageModal();
            themeInlineImageState.targetKey = '';
        });
        $card.on('click', '.metis-inline-image', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var figure = this;
            var surface = figure.closest('[data-theme-editor-surface]');
            if (!surface) return;
            var current = 'medium';
            if (figure.classList.contains('is-small')) current = 'small';
            else if (figure.classList.contains('is-large')) current = 'large';
            else if (figure.classList.contains('is-full')) current = 'full';
            promptThemeImageSettings(current, inlineImageAlignment(figure)).then(function (settingsChoice) {
                if (!settingsChoice) return;
                setInlineImageSize(figure, settingsChoice.size);
                setInlineImageAlignment(figure, settingsChoice.align);
                normalizeInlineImageForPreview(figure);
                var key = String(surface.getAttribute('data-theme-editor-surface') || '').trim();
                if (key) syncThemeEditorValue(key);
                saveThemeSelection(surface);
                clearTimeout(timer);
                timer = setTimeout(renderThemePreview, 120);
            });
        });
        $(document).off('click.metisNewsletterThemeMenus').on('click.metisNewsletterThemeMenus', function (e) {
            if (!e.target.closest('.metis-theme-selectx')) document.querySelectorAll('.metis-theme-selectx.is-open').forEach(function (node) { node.classList.remove('is-open'); });
            if (!e.target.closest('.metis-se-rich-dropdown')) document.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) { node.classList.remove('is-open'); });
        });
        $('#metis-newsletter-theme-inline-image-close, #metis-newsletter-theme-inline-image-cancel').on('click', function () {
            closeThemeInlineImageModal();
        });
        $('#metis-newsletter-theme-inline-image-modal').on('click', function (e) {
            if (e.target === this) closeThemeInlineImageModal();
        });
        $('#metis-newsletter-theme-inline-image-search').on('input', function () {
            renderThemeInlineImagePickerList();
        });
        $('#metis-newsletter-theme-inline-image-mime').on('change', function () {
            renderThemeInlineImagePickerList();
        });
        $('#metis-newsletter-save-theme-defaults').on('click', function () {
            ['header','personalized','closing','footer'].forEach(function (key) { syncThemeEditorValue(key); });
            metisAjax('metis_newsletter_save_theme_defaults', {
                header_html: $('#metis-newsletter-theme-header-html').val() || '',
                personalized_html: $('#metis-newsletter-theme-personalized-html').val() || '',
                closing_html: $('#metis-newsletter-theme-closing-html').val() || '',
                footer_html: $('#metis-newsletter-theme-footer-html').val() || '',
                canvas_bg: $('#metis-newsletter-theme-canvas-bg').val() || 'transparent',
                text_color: $('#metis-newsletter-theme-text-color').val() || 'text',
                font_size: $('#metis-newsletter-theme-font-size').val() || '16',
                font_family: $('#metis-newsletter-theme-font-family').val() || 'inherit',
                content_width: $('#metis-newsletter-theme-width').val() || '680',
                content_width_mode: activeThemeWidthMode(),
                divider_color: $('#metis-newsletter-theme-divider-color').val() || 'border',
                divider_style: $('#metis-newsletter-theme-divider-style').val() || 'solid',
                divider_weight: $('#metis-newsletter-theme-divider-weight').val() || '1',
                header_bg: $('#metis-newsletter-theme-header-bg').val() || 'transparent',
                header_text_color: $('#metis-newsletter-theme-header-text-color').val() || 'text',
                header_padding: $('#metis-newsletter-theme-header-padding').val() || '24px 28px 12px 28px',
                personalized_bg: $('#metis-newsletter-theme-personalized-bg').val() || 'transparent',
                personalized_text_color: $('#metis-newsletter-theme-personalized-text-color').val() || 'text',
                personalized_padding: $('#metis-newsletter-theme-personalized-padding').val() || '0 28px 8px 28px',
                closing_bg: $('#metis-newsletter-theme-closing-bg').val() || 'transparent',
                closing_text_color: $('#metis-newsletter-theme-closing-text-color').val() || 'text',
                closing_padding: $('#metis-newsletter-theme-closing-padding').val() || '12px 28px 8px 28px',
                footer_bg: $('#metis-newsletter-theme-footer-bg').val() || 'transparent',
                footer_text_color: $('#metis-newsletter-theme-footer-text-color').val() || 'muted',
                footer_padding: $('#metis-newsletter-theme-footer-padding').val() || '16px 28px 28px 28px'
            }, function (resp) {
                pageData.theme_defaults = Object.assign({}, pageData.theme_defaults || {}, resp.theme_defaults || {});
                renderThemePreview();
                toast('Newsletter theme defaults saved.', 'success');
            }, function (msg) {
                toast(msg || 'Failed to save newsletter theme defaults.', 'error');
            });
        });
    }

    $root.on('click', '#metis-newsletter-new-template', function () {
        navigate(ui.editor_template_new_url || ui.theme_url || '');
    });

    $root.on('click', '.metis-newsletter-edit-template', function () {
        const code = String($(this).data('template-code') || '').trim();
        if (!code) {
            toast('Template code is missing for this item.', 'error');
            return;
        }
        const editorBase = trimTrailingSlash(ui.editor_template_edit_base_url || '');
        if (editorBase) {
            navigate(editorBase + '/' + encodeURIComponent(code) + '/edit/');
            return;
        }
        const base = ui.template_editor_url || ui.theme_url || window.location.href.split('?')[0];
        navigate(base + (base.includes('?') ? '&' : '?') + 'template_code=' + encodeURIComponent(code));
    });

    /* ------------------------------------------------------------------ */
    /*  Campaign list actions                                               */
    /* ------------------------------------------------------------------ */
    $root.on('click', '#metis-newsletter-new-campaign', function () {
        navigate(ui.compose_url || '');
    });

    $root.on('click', '.metis-newsletter-edit-campaign', function (e) {
        e.stopPropagation();
        const code = String($(this).data('campaign-code') || '').trim();
        if (!code) {
            toast('Campaign code is missing for this item.', 'error');
            return;
        }
        navigate(campaignEditUrl(code));
    });

    $root.on('click', '.metis-newsletter-queue-campaign', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id');
        if (!id) return;
        confirmAction('Queue this campaign for sending?', {
            title: 'Queue Campaign',
            confirmLabel: 'Queue Campaign'
        }).then(function (confirmed) {
            if (!confirmed) return;
            metisAjax('metis_newsletter_queue_campaign', { campaign_id: id }, () => {
                toast('Campaign queued.', 'success');
                updateCampaignRowStatus(id, 'queued');
            }, msg => toast(msg, 'error'));
        });
    });

    $root.on('click', '.metis-newsletter-delete-campaign', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id');
        if (!id) return;
        confirmAction('Delete this campaign? This cannot be undone.', {
            title: 'Delete Campaign',
            confirmLabel: 'Delete Campaign',
            tone: 'danger'
        }).then(function (confirmed) {
            if (!confirmed) return;
            metisAjax('metis_newsletter_delete_campaign', { campaign_id: id }, () => {
                toast('Campaign deleted.', 'success');
                removeCampaignRow(id);
            }, msg => toast(msg, 'error'));
        });
    });

    $root.on('click', '.metis-newsletter-archive-campaign', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id');
        if (!id) return;
        confirmAction('Archive this campaign?', {
            title: 'Archive Campaign',
            confirmLabel: 'Archive'
        }).then(function (confirmed) {
            if (!confirmed) return;
            metisAjax('metis_newsletter_archive_campaign', { campaign_id: id }, () => {
                toast('Campaign archived.', 'success');
                updateCampaignRowStatus(id, 'archived');
            }, msg => toast(msg, 'error'));
        });
    });

    $root.on('click', '#metis-newsletter-run-queue', function () {
        metisAjax('metis_newsletter_run_send_queue', {}, function (data) {
            toast('Queue run: ' + (data.sent || 0) + ' sent.', 'success');
        }, msg => toast(msg, 'error'));
    });

    /* ------------------------------------------------------------------ */
    /*  Campaign detail modal                                               */
    /* ------------------------------------------------------------------ */
    const $detailModal = $('#metis-newsletter-campaign-detail-modal');

    $root.on('click', '.metis-newsletter-row[data-open-details="1"]', function (e) {
        if ($(e.target).is('button, a, input, select')) return;
        const id = $(this).data('campaign-id');
        if (!id) return;
        $('#metis-newsletter-campaign-detail-rows').html('<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="6">Loading…</td></tr>');
        $('#metis-newsletter-progress-summary').text('Loading…');
        $('#metis-newsletter-progress-current').text('');
        $('#metis-newsletter-progress-bar').css('width', '0%');
        $('#metis-newsletter-campaign-detail-title').text($(this).find('.metis-premium-cell strong').first().text() || 'Campaign Details');
        $detailModal.attr('aria-hidden', 'false').show();

        metisAjax('metis_newsletter_get_campaign_detail', { campaign_id: id }, function (data) {
            const rows = data.recipients || [];
            const total = data.total || rows.length, sent = data.sent || 0;
            const opened = data.opened || 0, clicked = data.clicked || 0;
            const pct = total > 0 ? Math.round((sent / total) * 100) : 0;
            $('#metis-newsletter-progress-summary').text('Recipients: ' + total + ' | Sent: ' + sent + ' | Opened: ' + opened + ' | Clicked: ' + clicked);
            $('#metis-newsletter-progress-current').text(pct + '%');
            $('#metis-newsletter-progress-bar').css('width', pct + '%');
            if (!rows.length) {
                $('#metis-newsletter-campaign-detail-rows').html('<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="6">No recipients yet.</td></tr>');
                return;
            }
            let html = '';
            rows.forEach(r => {
                html += '<tr class="metis-premium-row">'
                    + '<td class="metis-premium-cell">' + escHtml(r.display_name||'—') + '</td>'
                    + '<td class="metis-premium-cell">' + escHtml(r.email||'—') + '</td>'
                    + '<td class="metis-premium-cell">' + escHtml(r.cid||'—') + '</td>'
                    + '<td class="metis-premium-cell"><span class="metis-chip">' + escHtml(r.status||'—') + '</span></td>'
                    + '<td class="metis-premium-cell">' + (r.opened_at ? '✓' : '—') + '</td>'
                    + '<td class="metis-premium-cell">' + (r.clicked_at ? '✓' : '—') + '</td>'
                    + '</tr>';
            });
            $('#metis-newsletter-campaign-detail-rows').html(html);
        }, msg => {
            $('#metis-newsletter-campaign-detail-rows').html('<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="6">' + escHtml(msg) + '</td></tr>');
        });
    });

    /* ------------------------------------------------------------------ */
    /*  Test Send modal                                                     */
    /* ------------------------------------------------------------------ */
    const $testModal = $('#metis-newsletter-test-send-modal');
    let testContactId = null;

    $root.on('click', '.metis-newsletter-test-campaign, #metis-newsletter-campaign-test-send', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id') || $('#metis-newsletter-campaign-id').val() || '0';
        $('#metis-newsletter-test-campaign-id').val(id);
        testContactId = null;
        $('#metis-newsletter-test-contact-search').val('');
        $('#metis-newsletter-test-contact-results').html('');
        $('#metis-newsletter-test-email').val('');
        $testModal.attr('aria-hidden', 'false').show();
    });

    $testModal.on('change', 'input[name="metis-test-target-mode"]', function () {
        const mode = $(this).val();
        $testModal.find('.metis-test-target-panel').hide();
        $testModal.find('.metis-test-target-panel[data-test-panel="' + mode + '"]').show();
    });

    let testSearchTimer;
    $testModal.on('input', '#metis-newsletter-test-contact-search', function () {
        clearTimeout(testSearchTimer);
        const q = $(this).val().trim();
        if (q.length < 2) { $('#metis-newsletter-test-contact-results').html(''); return; }
        testSearchTimer = setTimeout(() => {
            metisAjax('metis_newsletter_search_contacts', { q }, function (data) {
                let html = '';
                (data.contacts || []).forEach(c => {
                    html += '<div class="metis-newsletter-search-result-item" data-contact-id="' + escAttr(c.id) + '">'
                          + escHtml(c.display_name) + ' &lt;' + escHtml(c.email) + '&gt;</div>';
                });
                $('#metis-newsletter-test-contact-results').html(html || '<div class="metis-muted">No results.</div>');
            }, () => {});
        }, 300);
    });

    $testModal.on('click', '.metis-newsletter-search-result-item', function () {
        testContactId = $(this).data('contact-id');
        $('#metis-newsletter-test-contact-search').val($(this).text());
        $('#metis-newsletter-test-contact-results').html('');
    });

    $('#metis-newsletter-send-test-confirm').on('click', function () {
        const cid = $('#metis-newsletter-test-campaign-id').val();
        const mode = $('input[name="metis-test-target-mode"]:checked').val();
        const payload = { campaign_id: cid, mode };
        if (mode === 'contact') payload.contact_id = testContactId;
        else payload.email = $('#metis-newsletter-test-email').val();
        metisAjax('metis_newsletter_send_test', payload, () => {
            toast('Test sent.', 'success');
            $testModal.attr('aria-hidden', 'true').hide();
        }, msg => toast(msg, 'error'));
    });

    /* ------------------------------------------------------------------ */
    /*  Campaign search filter                                              */
    /* ------------------------------------------------------------------ */
    $('#metis-newsletter-search').on('input', function () {
        const q = $(this).val().toLowerCase().trim();
        $('.metis-newsletter-row').each(function () {
            $(this).toggle(!q || ($(this).data('search') || '').toLowerCase().includes(q));
        });
    });

    /* ------------------------------------------------------------------ */
    /*  Modal close                                                         */
    /* ------------------------------------------------------------------ */
    $root.on('click', '.metis-newsletter-cancel', function () {
        $(this).closest('.metis-modal-backdrop').attr('aria-hidden', 'true').hide();
    });

    }

    /* ------------------------------------------------------------------ */
    /*  Utility                                                             */
    /* ------------------------------------------------------------------ */
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) { return String(s).replace(/"/g,'&quot;'); }

    MetisNewsletter.init = initMetisNewsletter;
    window.MetisNewsletter = MetisNewsletter;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initMetisNewsletter({ root: document, reason: 'dom-ready', url: window.location.href });
        });
    } else {
        initMetisNewsletter({ root: document, reason: 'dom-ready', url: window.location.href });
    }

    if (window.Metis && Metis.page && typeof Metis.page.register === 'function') {
        Metis.page.register('newsletter', initMetisNewsletter);
    }

}(jQuery));
