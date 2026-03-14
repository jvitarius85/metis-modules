/**
 * Metis Newsletter Module
 * Uses MebeEditor (editor.js) for template/campaign content editing.
 */
(function ($) {
    'use strict';

    const $root = $('.metis-newsletter');
    if (!$root.length) return;

    const canManage = $root.data('can-manage') === '1' || $root.data('can-manage') === 1;
    const pageData  = (function () {
        try { return JSON.parse($('#metis-newsletter-data').text() || '{}'); } catch (e) { return {}; }
    })();

    const ui            = pageData.ui       || {};
    const defaults      = pageData.defaults || {};
    const templatesById = {};
    (pageData.templates_by_id || []).forEach(t => { templatesById[t.id] = t; });

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

    /* ------------------------------------------------------------------ */
    /*  TEMPLATE EDITOR                                                     */
    /* ------------------------------------------------------------------ */
    let templateEditor = null;
    const $templateWrap   = $('#metis-nl-editor-template');
    const $templateJsonTA = $('#metis-newsletter-template-doc-json');
    const $templateHtmlTA = $('#metis-newsletter-template-html');

    if ($templateWrap.length && typeof MebeEditor !== 'undefined') {
        templateEditor = new MebeEditor('metis-nl-editor-template');

        const editing = pageData.editing_template;
        if (editing && editing.doc_json) {
            templateEditor.load(editing.doc_json);
            $('#metis-newsletter-template-id').val(editing.id || 0);
            $('#metis-newsletter-template-name').val(editing.name || '');
            $('#metis-newsletter-template-subject').val(editing.subject || '');
            $('#metis-newsletter-template-from-name').val(editing.from_name || defaults.from_name || '');
            $('#metis-newsletter-template-from-email').val(editing.from_email || defaults.from_email || '');
            $('#metis-newsletter-template-reply-to').val(editing.reply_to || defaults.reply_to || '');
        } else {
            $('#metis-newsletter-template-from-name').val(defaults.from_name || '');
            $('#metis-newsletter-template-from-email').val(defaults.from_email || '');
            $('#metis-newsletter-template-reply-to').val(defaults.reply_to || '');
        }

        /* Push merge tags into toolbar dropdown */
        if (templateEditor.setMergeTags) templateEditor.setMergeTags([
            { value: '{{first_name}}',             label: 'First Name' },
            { value: '{{last_name}}',              label: 'Last Name' },
            { value: '{{full_name}}',              label: 'Full Name' },
            { value: '{{email}}',                  label: 'Email' },
            { value: '{{campaign_name}}',          label: 'Campaign Name' },
            { value: '{{contact_cid}}',            label: 'Contact CID' },
            { value: '{{unsubscribe_url}}',        label: 'Unsubscribe URL' },
            { value: '{{manage_subscription_url}}',label: 'Manage Subscription URL' },
        ]);

        /* Autosave */
        templateEditor.enableAutosave(function (exported) {
            const payload = {
                template_id:  $('#metis-newsletter-template-id').val() || '0',
                name:         $('#metis-newsletter-template-name').val() || 'Untitled',
                subject:      $('#metis-newsletter-template-subject').val() || '',
                from_name:    $('#metis-newsletter-template-from-name').val() || '',
                from_email:   $('#metis-newsletter-template-from-email').val() || '',
                reply_to:     $('#metis-newsletter-template-reply-to').val() || '',
                doc_json:     exported.json,
                html_body:    exported.html,
            };
            return new Promise(function (resolve, reject) {
                metisAjax('metis_newsletter_save_template', payload,
                    function (data) {
                        const nid = data.template_id || payload.template_id;
                        if (nid && nid !== '0' && nid !== payload.template_id) {
                            const u = new URL(window.location.href);
                            u.searchParams.set('template_id', nid);
                            history.replaceState({}, '', u.toString());
                            $('#metis-newsletter-template-id').val(nid);
                        }
                        resolve();
                    },
                    function () { reject(); }
                );
            });
        }, 4000);

        $('#metis-newsletter-template-form').on('submit', function (e) {
            e.preventDefault();
            const { json, html } = templateEditor.export();
            $templateJsonTA.val(json);
            $templateHtmlTA.val(html);

            const payload = {
                template_id:  $('#metis-newsletter-template-id').val() || '0',
                name:         $('#metis-newsletter-template-name').val(),
                subject:      $('#metis-newsletter-template-subject').val(),
                from_name:    $('#metis-newsletter-template-from-name').val(),
                from_email:   $('#metis-newsletter-template-from-email').val(),
                reply_to:     $('#metis-newsletter-template-reply-to').val(),
                doc_json:     json,
                html_body:    html,
            };

            metisAjax('metis_newsletter_save_template', payload, function (data) {
                toast('Template saved.', 'success');
                const newId = data.template_id || payload.template_id;
                if (newId && newId !== '0' && newId !== payload.template_id) {
                    const u = new URL(window.location.href);
                    u.searchParams.set('template_id', newId);
                    u.searchParams.delete('editor');
                    history.replaceState({}, '', u.toString());
                    $('#metis-newsletter-template-id').val(newId);
                }
            }, msg => toast(msg, 'error'));
        });
    }

    $root.on('click', '#metis-newsletter-new-template', function () {
        window.location.href = ui.template_editor_url || '';
    });

    $root.on('click', '.metis-newsletter-edit-template', function () {
        const id = $(this).data('template-id');
        if (!id) return;
        const base = ui.templates_url || window.location.href.split('?')[0];
        window.location.href = base + (base.includes('?') ? '&' : '?') + 'template_id=' + id;
    });

    /* ------------------------------------------------------------------ */
    /*  CAMPAIGN COMPOSER — step management                                 */
    /* ------------------------------------------------------------------ */
    const $stepTabs   = $('#metis-newsletter-step-tabs');
    const $stepPanels = $('.metis-newsletter-step-panel');
    const $stepPrev   = $('#metis-newsletter-step-prev');
    const $stepNext   = $('#metis-newsletter-step-next');
    const $stepInd    = $('#metis-newsletter-step-indicator');
    const TOTAL_STEPS = 4;
    let currentStep   = 1;

    function gotoStep(n) {
        if (n < 1 || n > TOTAL_STEPS) return;
        currentStep = n;
        $stepPanels.hide().filter('[data-step="' + n + '"]').show();
        $stepTabs.find('.metis-newsletter-step-tab').each(function () {
            const t = parseInt($(this).data('step-tab'), 10);
            $(this).toggleClass('is-active', t === n)
                   .toggleClass('mw-btn-ghost', t !== n)
                   .removeClass('is-locked');
        });
        $stepPrev.prop('disabled', n === 1);
        $stepNext.prop('disabled', n === TOTAL_STEPS);
        $stepInd.text('Step ' + n + ' of ' + TOTAL_STEPS);
    }

    if ($stepTabs.length) {
        gotoStep(1);
        $stepPrev.on('click', () => gotoStep(currentStep - 1));
        $stepNext.on('click', () => gotoStep(currentStep + 1));
        $stepTabs.on('click', '.metis-newsletter-step-tab:not([disabled])', function () {
            gotoStep(parseInt($(this).data('step-tab'), 10));
        });
    }

    /* ------------------------------------------------------------------ */
    /*  CAMPAIGN EDITOR                                                     */
    /* ------------------------------------------------------------------ */
    let campaignEditor = null;
    const $campaignWrap   = $('#metis-nl-editor-campaign');
    const $campaignJsonTA = $('#metis-newsletter-campaign-doc-json');
    const $campaignHtmlTA = $('#metis-newsletter-campaign-html');

    if ($campaignWrap.length && typeof MebeEditor !== 'undefined') {
        campaignEditor = new MebeEditor('metis-nl-editor-campaign');

        const editingCampaign = pageData.editing_campaign;
        if (editingCampaign) {
            $('#metis-newsletter-campaign-id').val(editingCampaign.id || 0);
            $('#metis-newsletter-campaign-name').val(editingCampaign.name || '');
            $('#metis-newsletter-campaign-subject').val(editingCampaign.subject || '');
            $('#metis-newsletter-campaign-preheader').val(editingCampaign.preheader || '');
            $('#metis-newsletter-campaign-from-name').val(editingCampaign.from_name || defaults.from_name || '');
            $('#metis-newsletter-campaign-from-email').val(editingCampaign.from_email || defaults.from_email || '');
            $('#metis-newsletter-campaign-reply-to').val(editingCampaign.reply_to || defaults.reply_to || '');
            if (editingCampaign.scheduled_at)
                $('#metis-newsletter-campaign-scheduled').val(editingCampaign.scheduled_at.replace(' ', 'T').substring(0, 16));
            if (editingCampaign.template_id)
                $('#metis-newsletter-campaign-template').val(editingCampaign.template_id);
            if (editingCampaign.doc_json)
                campaignEditor.load(editingCampaign.doc_json);
            (editingCampaign.list_ids || []).forEach(lid =>
                $('#metis-newsletter-campaign-lists input[value="' + lid + '"]').prop('checked', true)
            );
        } else {
            $('#metis-newsletter-campaign-from-name').val(defaults.from_name || '');
            $('#metis-newsletter-campaign-from-email').val(defaults.from_email || '');
            $('#metis-newsletter-campaign-reply-to').val(defaults.reply_to || '');
        }

        /* Push merge tags into toolbar */
        const campMergeTags = [];
        $('#metis-nl-campaign-merge-select option[value]').each(function () {
            if ($(this).val()) campMergeTags.push({ value: $(this).val(), label: $(this).text() });
        });
        if (campaignEditor.setMergeTags) campaignEditor.setMergeTags(campMergeTags);

        $('#metis-nl-campaign-merge-select').on('change', function () {
            const tag = $(this).val();
            if (!tag) return;
            campaignEditor.injectMergeTag(tag);
            $(this).val('');
        });

        /* Autosave campaign */
        campaignEditor.enableAutosave(function (exported) {
            const listIds = [];
            $('#metis-newsletter-campaign-lists input:checked').each(function () { listIds.push($(this).val()); });
            const payload = {
                campaign_id:  $('#metis-newsletter-campaign-id').val() || '0',
                name:         $('#metis-newsletter-campaign-name').val() || 'Untitled',
                subject:      $('#metis-newsletter-campaign-subject').val() || '',
                preheader:    $('#metis-newsletter-campaign-preheader').val() || '',
                from_name:    $('#metis-newsletter-campaign-from-name').val() || '',
                from_email:   $('#metis-newsletter-campaign-from-email').val() || '',
                reply_to:     $('#metis-newsletter-campaign-reply-to').val() || '',
                template_id:  $('#metis-newsletter-campaign-template').val() || '0',
                doc_json:     exported.json,
                html_body:    exported.html,
                list_ids:     JSON.stringify(listIds),
                audience_json: JSON.stringify({ mode: 'list' }),
                attachments_json: '[]',
            };
            return new Promise(function (resolve, reject) {
                metisAjax('metis_newsletter_save_campaign', payload,
                    function (data) {
                        const nid = data.campaign_id || payload.campaign_id;
                        if (nid && nid !== '0' && nid !== payload.campaign_id) {
                            const u = new URL(window.location.href);
                            u.searchParams.set('campaign_id', nid);
                            history.replaceState({}, '', u.toString());
                            $('#metis-newsletter-campaign-id').val(nid);
                        }
                        resolve();
                    },
                    function () { reject(); }
                );
            });
        }, 2000);

        $('#metis-newsletter-campaign-load-template').on('click', function () {
            const tid = parseInt($('#metis-newsletter-campaign-template').val(), 10);
            if (!tid || !templatesById[tid]) { toast('Select a template in Step 1 first.', 'error'); return; }
            const tpl = templatesById[tid];
            if (!confirm('Replace current content with this template layout?')) return;
            campaignEditor.load(tpl.doc_json || '');
            toast('Template loaded.', 'success');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Save Campaign                                                       */
    /* ------------------------------------------------------------------ */
    $('#metis-newsletter-campaign-form').on('submit', function (e) {
        e.preventDefault();

        const { json, html } = campaignEditor ? campaignEditor.export() : { json: '', html: '' };
        $campaignJsonTA.val(json);
        $campaignHtmlTA.val(html);

        const listIds = [];
        $('#metis-newsletter-campaign-lists input:checked').each(function () { listIds.push($(this).val()); });

        const audienceMode = $('input[name="metis-newsletter-audience-mode"]:checked').val();
        let audienceJson = '';
        if (audienceMode === 'custom') {
            const rules = [];
            $('#metis-newsletter-segment-rules .metis-newsletter-rule-row').each(function () {
                rules.push({
                    field: $(this).find('.metis-rule-field').val(),
                    op:    $(this).find('.metis-rule-op').val(),
                    value: $(this).find('.metis-rule-value').val(),
                });
            });
            audienceJson = JSON.stringify({
                mode: 'custom',
                match: $('#metis-newsletter-seg-rule-match').val(),
                rules,
                opened_days:  parseInt($('#metis-newsletter-seg-opened-days').val(), 10) || 0,
                clicked_days: parseInt($('#metis-newsletter-seg-clicked-days').val(), 10) || 0,
            });
        } else {
            audienceJson = JSON.stringify({ mode: 'list' });
        }

        const scheduledRaw = $('#metis-newsletter-campaign-scheduled').val();
        const payload = {
            campaign_id:      $('#metis-newsletter-campaign-id').val() || '0',
            name:             $('#metis-newsletter-campaign-name').val(),
            subject:          $('#metis-newsletter-campaign-subject').val(),
            preheader:        $('#metis-newsletter-campaign-preheader').val(),
            from_name:        $('#metis-newsletter-campaign-from-name').val(),
            from_email:       $('#metis-newsletter-campaign-from-email').val(),
            reply_to:         $('#metis-newsletter-campaign-reply-to').val(),
            template_id:      $('#metis-newsletter-campaign-template').val() || '0',
            scheduled_at:     scheduledRaw ? scheduledRaw.replace('T', ' ') + ':00' : '',
            doc_json:         json,
            html_body:        html,
            list_ids:         JSON.stringify(listIds),
            audience_json:    audienceJson,
            attachments_json: $('#metis-newsletter-campaign-attachments-json').val() || '[]',
        };

        metisAjax('metis_newsletter_save_campaign', payload, function (data) {
            toast('Campaign saved.', 'success');
            const newId = data.campaign_id || payload.campaign_id;
            if (newId && newId !== '0' && newId !== payload.campaign_id) {
                const u = new URL(window.location.href);
                u.searchParams.set('campaign_id', newId);
                u.searchParams.delete('compose');
                history.replaceState({}, '', u.toString());
                $('#metis-newsletter-campaign-id').val(newId);
            }
        }, msg => toast(msg, 'error'));
    });

    /* ------------------------------------------------------------------ */
    /*  Campaign list actions                                               */
    /* ------------------------------------------------------------------ */
    $root.on('click', '#metis-newsletter-new-campaign', function () {
        window.location.href = ui.compose_url || '';
    });

    $root.on('click', '.metis-newsletter-edit-campaign', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id');
        if (!id) return;
        const base = ui.campaigns_url || window.location.href.split('?')[0];
        window.location.href = base + (base.includes('?') ? '&' : '?') + 'campaign_id=' + id;
    });

    $root.on('click', '.metis-newsletter-queue-campaign', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id');
        if (!id || !confirm('Queue this campaign for sending?')) return;
        metisAjax('metis_newsletter_queue_campaign', { campaign_id: id }, () => {
            toast('Campaign queued.', 'success');
            setTimeout(() => window.location.reload(), 800);
        }, msg => toast(msg, 'error'));
    });

    $root.on('click', '.metis-newsletter-delete-campaign', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id');
        if (!id || !confirm('Delete this campaign? This cannot be undone.')) return;
        metisAjax('metis_newsletter_delete_campaign', { campaign_id: id }, () => {
            toast('Campaign deleted.', 'success');
            setTimeout(() => window.location.reload(), 800);
        }, msg => toast(msg, 'error'));
    });

    $root.on('click', '.metis-newsletter-archive-campaign', function (e) {
        e.stopPropagation();
        const id = $(this).data('campaign-id');
        if (!id || !confirm('Archive this campaign?')) return;
        metisAjax('metis_newsletter_archive_campaign', { campaign_id: id }, () => {
            toast('Campaign archived.', 'success');
            setTimeout(() => window.location.reload(), 800);
        }, msg => toast(msg, 'error'));
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
        $('#metis-newsletter-campaign-detail-rows').html('<div class="mw-premium-row"><div class="mw-premium-cell mw-muted">Loading…</div></div>');
        $('#metis-newsletter-progress-summary').text('Loading…');
        $('#metis-newsletter-progress-current').text('');
        $('#metis-newsletter-progress-bar').css('width', '0%');
        $('#metis-newsletter-campaign-detail-title').text($(this).find('.mw-premium-cell strong').first().text() || 'Campaign Details');
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
                $('#metis-newsletter-campaign-detail-rows').html('<div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No recipients yet.</div></div>');
                return;
            }
            let html = '';
            rows.forEach(r => {
                html += '<div class="mw-premium-row">'
                    + '<div class="mw-premium-cell">' + escHtml(r.display_name||'—') + '</div>'
                    + '<div class="mw-premium-cell">' + escHtml(r.email||'—') + '</div>'
                    + '<div class="mw-premium-cell">' + escHtml(r.cid||'—') + '</div>'
                    + '<div class="mw-premium-cell"><span class="mw-chip">' + escHtml(r.status||'—') + '</span></div>'
                    + '<div class="mw-premium-cell">' + (r.opened_at ? '✓' : '—') + '</div>'
                    + '<div class="mw-premium-cell">' + (r.clicked_at ? '✓' : '—') + '</div>'
                    + '</div>';
            });
            $('#metis-newsletter-campaign-detail-rows').html(html);
        }, msg => {
            $('#metis-newsletter-campaign-detail-rows').html('<div class="mw-premium-row"><div class="mw-premium-cell mw-muted">' + escHtml(msg) + '</div></div>');
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
                $('#metis-newsletter-test-contact-results').html(html || '<div class="mw-muted">No results.</div>');
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
    /*  Segment rule builder                                                */
    /* ------------------------------------------------------------------ */
    const RULE_FIELDS = [
        { v:'city',l:'City'},{v:'state',l:'State'},{v:'zip',l:'Zip'},
        {v:'tag',l:'Tag'},{v:'source',l:'Source'},{v:'created_at',l:'Created Date'},
    ];
    const RULE_OPS = [
        {v:'eq',l:'equals'},{v:'neq',l:'not equals'},{v:'contains',l:'contains'},
        {v:'before',l:'before (date)'},{v:'after',l:'after (date)'},
    ];

    const fieldSel = '<select class="mw-select metis-rule-field">' + RULE_FIELDS.map(f=>`<option value="${f.v}">${f.l}</option>`).join('') + '</select>';
    const opSel    = '<select class="mw-select metis-rule-op">'    + RULE_OPS.map(o=>`<option value="${o.v}">${o.l}</option>`).join('') + '</select>';

    $root.on('click', '#metis-newsletter-add-rule', function () {
        $('#metis-newsletter-segment-rules').append(
            $('<div class="metis-newsletter-rule-row" style="display:flex;gap:6px;margin-bottom:6px;">')
                .append(fieldSel, opSel,
                    '<input class="mw-input metis-rule-value" type="text" placeholder="Value">',
                    '<button type="button" class="mw-btn-xs mw-btn-danger metis-newsletter-remove-rule">×</button>')
        );
    });

    $root.on('click', '.metis-newsletter-remove-rule', function () {
        $(this).closest('.metis-newsletter-rule-row').remove();
    });

    $root.on('change', 'input[name="metis-newsletter-audience-mode"]', function () {
        const mode = $(this).val();
        $('.metis-newsletter-audience-list-section').toggle(mode === 'list');
        $('.metis-newsletter-audience-custom').toggle(mode === 'custom');
    });
    $('.metis-newsletter-audience-custom').hide();

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
        $(this).closest('.metis-contacts-modal').attr('aria-hidden', 'true').hide();
    });

    /* ------------------------------------------------------------------ */
    /*  Utility                                                             */
    /* ------------------------------------------------------------------ */
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) { return String(s).replace(/"/g,'&quot;'); }

}(jQuery));
