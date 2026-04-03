/**
 * Metis Page Builder — Full-Page Editor Runtime
 * Wires MetisBlockEditor into the dedicated editor shell.
 */
(function($) {
'use strict';

var MetisPageBuilder = {

    editor:        null,
    currentId:     null,
    currentKey:    null,
    currentContext: null,
    currentKind:   '',
    _dirty:        false,
    _blockRegistry: null,
    _contextProfile: null,
    _templateStructure: null,
    _templateMeta: null,
    _templateActiveRegion: 'main',
    _saveInFlight: false,
    _autosaveTimer: null,
    _autosaveDelayMs: 2200,
    _previewDevice: 'desktop',
    _entityMeta: null,
    _lockHeartbeatTimer: null,
    _lockToken: '',
    _lockBlocked: false,
    _lockAdvisoryShown: false,
    _newsletterMeta: null,
    _newsletterAdapterLoading: false,
    _saveStateResetTimer: null,
    _reusableItems: null,
    _reusableLoading: false,
    _menuOptions: null,
    _menuOptionsLoading: false,

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    openNew: function(context) {
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForNew(context || 'website');
            return;
        }
        this.currentId      = null;
        this.currentKey     = null;
        this.currentContext = context || 'website';
        this._dirty         = false;
        this._openEditor([], null);
    },

    openEdit: function(entityRef, context) {
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForEdit(entityRef, context || 'website');
            return;
        }
        var self = this;
        this.currentId      = null;
        this.currentKey     = String(entityRef || '').trim();
        this.currentContext = context || 'website';
        this._dirty         = false;

        var actionKey = context === 'post' ? 'metis_website_post_get' : 'metis_website_page_get';
        var requestData = {
            action: actionKey,
            nonce: this._ajaxConfigForAction(actionKey).nonce,
            metis_action_nonce: this._ajaxConfigForAction(actionKey).action_nonce || '',
            metis_csrf_action: this._ajaxConfigForAction(actionKey).csrf_action || ('metis_ajax:' + actionKey)
        };
        if (this.currentKey !== '') {
            requestData.key = this.currentKey;
        }

        var ajaxCfg = this._ajaxConfigForAction(actionKey);
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: requestData,
            success: function(r) {
                if (!r.success) { self._toast((r.data && r.data.message) || 'Failed to load.', 'error'); return; }
                var entity  = r.data.page || r.data.post || {};
                self.currentId = entity.id || null;
                if (context === 'post') {
                    self.currentKey = String(entity.post_code || self.currentKey || '');
                } else {
                    self.currentKey = String(entity.page_code || self.currentKey || '');
                }
                var rawJson = entity.draft_layout_json || entity.layout_json
                           || entity.draft_content_json || entity.content_json || null;
                /* BB model: pass full layout when it has sections; fall back to flat blocks */
                var parsedRaw = null;
                try { parsedRaw = rawJson ? (typeof rawJson === 'string' ? JSON.parse(rawJson) : rawJson) : null; } catch(e) {}
                if (parsedRaw && parsedRaw.sections && Array.isArray(parsedRaw.sections)) {
                    self._openEditor([], entity, parsedRaw);
                } else {
                    var blocks = self._parseBlocks(rawJson);
                    self._openEditor(blocks, entity, null);
                }
            },
            error: function() { self._toast('Could not load content.', 'error'); }
        });
    },

    openTemplateNew: function() {
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForTemplateNew();
            return;
        }
        this.currentId = null;
        this.currentKey = null;
        this.currentContext = 'template';
        this._dirty = false;
        this._templateStructure = this._defaultTemplateStructure();
        this._templateMeta = {
            template_key: '',
            kind: 'body',
            body_for: 'page',
            is_default: 0,
            suppress_home_header: 0
        };
        this._templateActiveRegion = 'main';
        this._openEditor([], { name: '', status: 'draft', template_type: 'page' });
    },

    openTemplateEditByKey: function(templateKey) {
        var self = this;
        var key = String(templateKey || '').trim();
        if (key === '') return;
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForTemplateKey(key);
            return;
        }

        this.currentId = null;
        this.currentKey = null;
        this.currentContext = 'template';
        this._dirty = false;

        var ajaxCfg = this._ajaxConfigForAction('metis_website_template_get');
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: 'metis_website_template_get',
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || 'metis_ajax:metis_website_template_get',
                template_key: key
            },
            success: function(r) {
                if (!r || !r.success || !r.data || !r.data.template) {
                    self._toast((r && r.data && r.data.message) || 'Failed to load template.', 'error');
                    return;
                }
                var template = r.data.template || {};
                self.currentId = template.id || null;
                self._templateStructure = self._normalizeTemplateStructureFromEntity(template);
                var kind = (self._templateStructure.layout && self._templateStructure.layout.template_kind) || '';
                if (kind !== 'header' && kind !== 'footer' && kind !== 'body') {
                    kind = (template.template_type === 'header' || template.template_type === 'footer') ? template.template_type : 'body';
                }
                self._templateMeta = {
                    template_key: String(template.template_key || key),
                    kind: kind,
                    body_for: template.template_type === 'post' ? 'post' : 'page',
                    is_default: template.is_default ? 1 : 0,
                    suppress_home_header: self._templateStructure.layout && self._templateStructure.layout.suppress_header_on_homepage ? 1 : 0
                };
                self._templateActiveRegion = kind === 'header' ? 'header' : (kind === 'footer' ? 'footer' : 'main');
                self._openEditor(self._templateRegionBlocks(self._templateActiveRegion), template);
            },
            error: function() {
                self._toast('Could not load template.', 'error');
            }
        });
    },

    openTemplateEditById: function(templateId) {
        var self = this;
        var id = parseInt(String(templateId || ''), 10);
        if (!Number.isFinite(id) || id < 1) return;
        if (!this._isDedicatedEditorShell()) {
            return;
        }

        this.currentId = null;
        this.currentKey = null;
        this.currentContext = 'template';
        this._dirty = false;

        var ajaxCfg = this._ajaxConfigForAction('metis_website_template_get');
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: 'metis_website_template_get',
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || 'metis_ajax:metis_website_template_get',
                id: id
            },
            success: function(r) {
                if (!r || !r.success || !r.data || !r.data.template) {
                    self._toast((r && r.data && r.data.message) || 'Failed to load template.', 'error');
                    return;
                }
                var template = r.data.template || {};
                self.currentId = template.id || null;
                self.currentKey = String(template.template_key || '');
                self._templateStructure = self._normalizeTemplateStructureFromEntity(template);
                var kind = (self._templateStructure.layout && self._templateStructure.layout.template_kind) || '';
                if (kind !== 'header' && kind !== 'footer' && kind !== 'body') {
                    kind = (template.template_type === 'header' || template.template_type === 'footer') ? template.template_type : 'body';
                }
                self._templateMeta = {
                    template_key: String(template.template_key || ''),
                    kind: kind,
                    body_for: template.template_type === 'post' ? 'post' : 'page',
                    is_default: template.is_default ? 1 : 0,
                    suppress_home_header: self._templateStructure.layout && self._templateStructure.layout.suppress_header_on_homepage ? 1 : 0
                };
                self._templateActiveRegion = kind === 'header' ? 'header' : (kind === 'footer' ? 'footer' : 'main');
                self._syncTemplateEditorUrl(self._templateMeta.template_key);
                self._openEditor(self._templateRegionBlocks(self._templateActiveRegion), template);
            },
            error: function() {
                self._toast('Could not load template.', 'error');
            }
        });
    },

    openNewsletterCampaignNew: function() {
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForNewsletterCampaignNew();
            return;
        }
        this.currentId = null;
        this.currentKey = null;
        this.currentContext = 'newsletter';
        this.currentKind = 'campaign';
        this._dirty = false;
        this._newsletterMeta = {
            subject: '',
            preheader: '',
            from_name: '',
            from_email: '',
            reply_to: '',
            template_code: '',
            scheduled_at: '',
            list_ids: [],
            audience_json: JSON.stringify({ mode: 'list' }),
            attachments_json: '[]'
        };
        this._openEditor([], { name: '', status: 'draft' });
    },

    openNewsletterCampaignEditByKey: function(campaignCode) {
        var self = this;
        var key = String(campaignCode || '').trim();
        if (key === '') return;
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForNewsletterCampaignKey(key);
            return;
        }

        this.currentId = null;
        this.currentKey = key;
        this.currentContext = 'newsletter';
        this.currentKind = 'campaign';
        this._dirty = false;

        var action = 'metis_newsletter_campaign_get';
        var ajaxCfg = this._ajaxConfigForAction(action);
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || ('metis_ajax:' + action),
                campaign_code: key,
                key: key
            },
            success: function(r) {
                if (!r || !r.success || !r.data || !r.data.campaign) {
                    self._toast((r && r.data && r.data.message) || 'Failed to load campaign.', 'error');
                    return;
                }
                var campaign = r.data.campaign || {};
                self.currentId = campaign.id || null;
                self.currentKey = String(campaign.campaign_code || key);
                self._newsletterMeta = {
                    subject: String(campaign.subject || ''),
                    preheader: String(campaign.preheader || ''),
                    from_name: String(campaign.from_name || ''),
                    from_email: String(campaign.from_email || ''),
                    reply_to: String(campaign.reply_to || ''),
                    template_code: String(campaign.template_code || ''),
                    scheduled_at: String(campaign.scheduled_at || ''),
                    list_ids: Array.isArray(campaign.list_ids) ? campaign.list_ids : [],
                    audience_json: String(campaign.audience_json || JSON.stringify({ mode: 'list' })),
                    attachments_json: String(campaign.attachments_json || '[]')
                };
                self._ensureNewsletterAdapter(function() {
                    self._openEditor(self._newsletterDocToBlocks(campaign.doc_json || ''), campaign);
                });
            },
            error: function() {
                self._toast('Could not load campaign.', 'error');
            }
        });
    },

    openNewsletterTemplateNew: function() {
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForNewsletterTemplateNew();
            return;
        }
        this.currentId = null;
        this.currentKey = null;
        this.currentContext = 'newsletter_template';
        this.currentKind = 'template';
        this._dirty = false;
        this._newsletterMeta = {
            subject: '',
            from_name: '',
            from_email: '',
            reply_to: ''
        };
        this._openEditor([], { name: '', status: 'draft' });
    },

    openNewsletterTemplateEditByKey: function(templateCode) {
        var self = this;
        var key = String(templateCode || '').trim();
        if (key === '') return;
        if (!this._isDedicatedEditorShell()) {
            window.location.href = this._editorUrlForNewsletterTemplateKey(key);
            return;
        }

        this.currentId = null;
        this.currentKey = key;
        this.currentContext = 'newsletter_template';
        this.currentKind = 'template';
        this._dirty = false;

        var action = 'metis_newsletter_template_get';
        var ajaxCfg = this._ajaxConfigForAction(action);
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || ('metis_ajax:' + action),
                template_code: key,
                key: key
            },
            success: function(r) {
                if (!r || !r.success || !r.data || !r.data.template) {
                    self._toast((r && r.data && r.data.message) || 'Failed to load template.', 'error');
                    return;
                }
                var template = r.data.template || {};
                self.currentId = template.id || null;
                self.currentKey = String(template.template_code || key);
                self._newsletterMeta = {
                    subject: String(template.subject || ''),
                    from_name: String(template.from_name || ''),
                    from_email: String(template.from_email || ''),
                    reply_to: String(template.reply_to || '')
                };
                self._ensureNewsletterAdapter(function() {
                    self._openEditor(self._newsletterDocToBlocks(template.doc_json || ''), template);
                });
            },
            error: function() {
                self._toast('Could not load template.', 'error');
            }
        });
    },

    // -----------------------------------------------------------------------
    // Editor lifecycle
    // -----------------------------------------------------------------------

    _openEditor: function(blocks, entity, layout) {
        var self = this;
        this._lockAdvisoryShown = false;
        var inlineShell = this._isDedicatedEditorShell();
        if (inlineShell) {
            if ($('#mwpb-inline-root #mwpb-overlay').length) return;
        } else if ($('#mwpb-overlay').length) {
            return;
        }

        var isNewsletterCampaign = this._isNewsletterCampaignContext();
        var isNewsletterTemplate = this._isNewsletterTemplateContext();
        var isNewsletter = isNewsletterCampaign || isNewsletterTemplate;
        var isTemplate = this.currentContext === 'template' || isNewsletterTemplate;
        var label    = entity && (entity.title || entity.name) ? (entity.title || entity.name) : '';
        var slug     = entity && entity.slug  ? entity.slug  : '';
        var isPage   = this.currentContext !== 'post' && !isTemplate && !isNewsletterCampaign;
        var typeLabel = isNewsletterCampaign ? 'Campaign' : (isNewsletterTemplate ? 'Newsletter Template' : (isTemplate ? 'Template' : (isPage ? 'Page' : 'Post')));
        var currentStatus = entity && entity.status ? String(entity.status).toLowerCase() : 'draft';
        if (currentStatus !== 'draft' && currentStatus !== 'published' && currentStatus !== 'scheduled') currentStatus = 'draft';
        if (isTemplate && currentStatus === 'scheduled') currentStatus = 'draft';
        var scheduleSource = entity && (entity.publish_date || entity.published_at) ? String(entity.publish_date || entity.published_at) : '';
        var scheduleLocal = this._toDateTimeLocal(scheduleSource);
        var excerpt   = entity && entity.excerpt ? entity.excerpt : '';
        var isHomepage = !!(entity && entity.is_homepage);
        var seoTitle  = '';
        var seoDesc   = '';
        if ( entity && entity.seo_meta_json ) {
            try {
                var seo = JSON.parse( entity.seo_meta_json );
                seoTitle = seo.title || '';
                seoDesc  = seo.description || '';
            } catch(e) {}
        }

        // Store on builder for save access
        this._excerpt  = excerpt;
        this._seoTitle = seoTitle;
        this._seoDesc  = seoDesc;
        this._entityMeta = {
            slug: slug,
            is_homepage: isHomepage,
            status: currentStatus,
            schedule_at: this._fromDateTimeLocal(scheduleLocal),
            created_by: this._resolveCreatorLabel(entity)
        };

        var overlayOpen = inlineShell
            ? '<div id="mwpb-overlay" class="mwpb-inline" data-shell="editor">'
            : '<div id="mwpb-overlay" role="dialog" aria-modal="true" aria-label="' + typeLabel + ' Editor">';

        var html = [
            overlayOpen,
            '  <div id="mwpb-frame">',
            '    <div id="mwpb-topbar">',
            '      <div id="mwpb-topbar-left">',
            '        <button id="mwpb-close-btn" class="mwpb-icon-btn" title="Close editor" aria-label="Close editor">',
            '          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
            '        </button>',
            '        <span class="mwpb-type-badge">' + typeLabel + '</span>',
            '        <input id="mwpb-title-input" class="mwpb-title-input" type="text" placeholder="' + typeLabel + ' title…" value="' + self._esc(label) + '" autocomplete="off">',
            '      </div>',
            '      <div id="mwpb-topbar-right">',
            '        <span id="mwpb-save-status" class="mwpb-save-status"></span>',
            '        <button id="mwpb-preview-btn" class="mwpb-ghost-btn" title="Open preview">Preview</button>',
            (isNewsletter ? '' : '        <div id="mwpb-device-toggle" class="mwpb-device-toggle" role="group" aria-label="Preview device"><button type="button" class="mwpb-device-btn" data-device="desktop" title="Desktop preview" aria-label="Desktop preview"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="12" rx="1.8"/><path d="M8 20h8"/><path d="M12 16v4"/></svg></button><button type="button" class="mwpb-device-btn" data-device="tablet" title="Tablet preview" aria-label="Tablet preview"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="3" width="12" height="18" rx="2"/><circle cx="12" cy="17.5" r="0.8" fill="currentColor" stroke="none"/></svg></button><button type="button" class="mwpb-device-btn" data-device="mobile" title="Mobile preview" aria-label="Mobile preview"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="8" y="2.5" width="8" height="19" rx="2"/><circle cx="12" cy="18" r="0.8" fill="currentColor" stroke="none"/></svg></button></div>'),
            '        <button id="mwpb-undo-btn" class="mwpb-ghost-btn mwpb-icon-ghost-btn" title="Undo (Ctrl/Cmd+Z)" aria-label="Undo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14L4 9l5-5"/><path d="M4 9h8a8 8 0 0 1 8 8v2"/></svg></button>',
            '        <button id="mwpb-redo-btn" class="mwpb-ghost-btn mwpb-icon-ghost-btn" title="Redo (Ctrl/Cmd+Y)" aria-label="Redo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 14l5-5-5-5"/><path d="M20 9h-8a8 8 0 0 0-8 8v2"/></svg></button>',
            '        <button id="mwpb-history-btn" class="mwpb-ghost-btn mwpb-icon-ghost-btn" title="Version history" aria-label="Version history"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"></circle><path d="M12 7v5l3 2"></path></svg></button>',
            '        <button id="mwpb-save-draft-btn" class="mw-btn mw-btn-ghost mw-btn-sm mwpb-save-icon-btn" data-save-state="idle" title="Save draft" aria-label="Save draft"><span class="mwpb-save-icon-wrap">' + self._saveIconSvg('idle') + '</span></button>',
            '        <button id="mwpb-publish-btn" class="mw-btn mw-btn-primary mw-btn-sm">Publish</button>',
            '      </div>',
            '    </div>',
            '    <div id="mwpb-body">',
            '      <div id="mwpb-editor-mount"></div>',
            '      <div id="mwpb-props-panel">',
            '        <div class="mwpb-settings-tabs" role="tablist" aria-label="Editor sidebar tabs">',
            '          <button type="button" class="mwpb-settings-tab is-active" data-sidebar-tab="blocks" role="tab" aria-selected="true">Blocks</button>',
            '          <button type="button" class="mwpb-settings-tab" data-sidebar-tab="properties" role="tab" aria-selected="false">Properties</button>',
            '          <button type="button" class="mwpb-settings-tab" data-sidebar-tab="settings" role="tab" aria-selected="false">Settings</button>',
            '        </div>',
            '        <div class="mwpb-sidebar-pane is-active" data-pane="blocks"><div id="mwpb-blocks-sidebar-slot" class="mwpb-blocks-sidebar-slot"></div></div>',
            '        <div id="mwpb-props-body" class="mwpb-sidebar-pane" data-pane="properties"><div class="mwpb-props-empty">Select a block to edit its properties.</div></div>',
            '        <div class="mwpb-page-settings mwpb-sidebar-pane" data-pane="settings"></div>',
            '      </div>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n');

        if (inlineShell) {
            var $inlineRoot = $('#mwpb-inline-root');
            if (!$inlineRoot.length) {
                $('body').append('<div id="mwpb-inline-root"></div>');
                $inlineRoot = $('#mwpb-inline-root');
            }
            $inlineRoot.html(html);
        } else {
            $('body').append(html);
            $('body').addClass('mwpb-open');
        }

        this._ensureNewsletterAdapter(function() {
        self._loadBlockRegistry(function(registry) {
            try {
                // Mount editor
                self.editor = new MetisBlockEditor('mwpb-editor-mount', {
                    context: self._editorRegistryContext(),
                    blockRegistry: registry || undefined,
                    contextProfile: self._contextProfile || undefined,
                    layout: (layout && layout.sections) ? layout : undefined,
                    blocks: (layout && layout.sections) ? [] : blocks,
                    pageMeta: {
                        status:      String((self._entityMeta && self._entityMeta.status) || 'draft'),
                        slug:        String((self._entityMeta && self._entityMeta.slug) || ''),
                        is_homepage: !!(self._entityMeta && self._entityMeta.is_homepage),
                        seo_title:   String(self._seoTitle || ''),
                        seo_desc:    String(self._seoDesc  || ''),
                        excerpt:     String(self._excerpt  || ''),
                        created_by:  String(self._resolveCreatorLabel(entity) || ''),
                        context:     self._editorRegistryContext(),
                        schedule_at: String((self._entityMeta && self._entityMeta.schedule_at) || '')
                    },
                    onPageMetaChange: function(meta) {
                        /* Write changes back into page-builder's _entityMeta */
                        self._entityMeta = self._entityMeta || {};
                        if (meta.status    !== undefined) self._entityMeta.status      = meta.status;
                        if (meta.slug      !== undefined) self._entityMeta.slug        = meta.slug;
                        if (meta.is_homepage !== undefined) self._entityMeta.is_homepage = meta.is_homepage;
                        if (meta.schedule_at !== undefined) self._entityMeta.schedule_at = meta.schedule_at;
                        if (meta.seo_title !== undefined) self._seoTitle = meta.seo_title;
                        if (meta.seo_desc  !== undefined) self._seoDesc  = meta.seo_desc;
                        if (meta.excerpt   !== undefined) self._excerpt  = meta.excerpt;
                        self._dirty = true;
                        self._showSaveStatus('Unsaved changes');
                        self._scheduleAutosave();
                    },
                    onChange: function() {
                        self._dirty = true;
                        self._showSaveStatus('Unsaved changes');
                        self._scheduleAutosave();
                    },
                    onOpenBlockSettings: function(path, block) {
                        /* BB model: editor manages its own right panel — no-op here */
                        if (typeof self.editor.setPropsVisible === 'function') {
                            self.editor.setPropsVisible(true);
                        }
                    },
                    onSelectionCleared: function() {
                        if (self.editor && typeof self.editor.setPropsVisible === 'function') {
                            self.editor.setPropsVisible(false);
                        }
                        self._setSidebarTab('blocks');
                    },
                    onRequestReusableLibrary: function(done) {
                        self._loadReusableBlocks(done);
                    },
                    onSaveBlockAsReusable: function(path, block) {
                        self._promptSaveReusableBlock(path, block);
                    },
                    onInsertReusableBlock: function(code, mode, listPath, index) {
                        self._insertReusableBlock(code, mode, listPath, index);
                    },
                    onUpdateLinkedReusable: function(path, block, reusableMeta) {
                        self._updateLinkedReusableBlock(path, block, reusableMeta);
                    }
                });
            } catch (initError) {
                var statusEl = document.getElementById('mwpb-editor-boot-status');
                if (statusEl) {
                    var titleEl = statusEl.querySelector('.metis-editor-boot-title');
                    var copyEl = statusEl.querySelector('.metis-editor-boot-copy');
                    if (titleEl) titleEl.textContent = 'Editor Failed To Start';
                    if (copyEl) copyEl.textContent = 'Editor runtime failed to initialize.';
                }
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('[Metis Builder] initError', initError && initError.stack ? initError.stack : initError);
                }
                self._toast('Editor runtime failed to initialize: ' + String((initError && initError.message) || initError || 'unknown error'), 'error');
                return;
            }
            $('#mwpb-editor-boot-status').remove();

            // Wire properties panel
            window.MetisBlockPropertiesPanel = new MetisPropsPanel(
                document.getElementById('mwpb-props-body'), self
            );

            self._bindOverlayEvents(entity, isPage, isTemplate);
            self._bindSidebarTabs();
            self._mountBlocksPanelInSidebar();
            self._loadMenuOptions();
            if (self.currentContext === 'template') {
                self._renderTemplateSettingsPane();
            } else if (self._isNewsletterContext()) {
                self._renderNewsletterSettingsPane();
            } else {
                self._renderStandardSettingsPane({
                    isPage: isPage,
                    isTemplate: isTemplate,
                    excerpt: excerpt,
                    seoTitle: seoTitle,
                    seoDesc: seoDesc,
                    isHomepage: isHomepage
                });
            }
            if (self._isNewsletterContext()) {
                self._setPreviewDevice('desktop', { persist: false });
            } else {
                self._restorePreviewDevice();
            }
            self._startEditorLock();
            setTimeout(function() { $('#mwpb-title-input').focus(); }, 100);
        });
        });
    },

    _bindOverlayEvents: function(entity, isPage, isTemplate) {
        var self = this;

        $('#mwpb-close-btn').on('click', function() { self._confirmClose(); });
        $(document).on('keydown.mwpb', function(e) {
            var alreadyPrevented = !!e.defaultPrevented || (typeof e.isDefaultPrevented === 'function' && e.isDefaultPrevented());
            if (alreadyPrevented) return;
            var inEditorSurface = !!(e.target && e.target.closest && e.target.closest('#mwpb-editor-mount'));
            if (inEditorSurface) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); self._save('draft'); }
                return;
            }
            if (self.editor && typeof self.editor.handleGlobalKeydown === 'function') {
                if (self.editor.handleGlobalKeydown(e)) {
                    return;
                }
            }
            if (e.key === 'Escape') self._confirmClose();
            if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); self._save('draft'); }
        });

        $('#mwpb-save-draft-btn').on('click', function() { self._save('draft'); });
        $('#mwpb-publish-btn').on('click', function() { self._save('published'); });
        $('#mwpb-title-input, #mwpb-seo-title, #mwpb-seo-desc, #mwpb-excerpt, #mwpb-slug-input').on('input change', function() {
            self._dirty = true;
            self._showSaveStatus('Unsaved changes');
            self._scheduleAutosave();
        });
        $('#mwpb-history-btn').on('click', function() { self._openRevisionHistory(); });
        if (self._isNewsletterContext()) {
            $('#mwpb-history-btn').hide();
        }
        $('#mwpb-undo-btn').on('click', function() {
            if (!self.editor || typeof self.editor.undo !== 'function') return;
            if (self.editor.undo()) {
                self._dirty = true;
                self._showSaveStatus('Unsaved changes');
                self._scheduleAutosave();
            }
        });
        $('#mwpb-redo-btn').on('click', function() {
            if (!self.editor || typeof self.editor.redo !== 'function') return;
            if (self.editor.redo()) {
                self._dirty = true;
                self._showSaveStatus('Unsaved changes');
                self._scheduleAutosave();
            }
        });
        $('#mwpb-preview-btn').on('click', function() {
            self._openPreview();
        });
        $('#mwpb-device-toggle [data-device]').on('click', function() {
            var device = String($(this).data('device') || 'desktop');
            self._setPreviewDevice(device);
        });

        $(window).off('beforeunload.mwpb').on('beforeunload.mwpb', function() {
            self._releaseEditorLock();
            if (self._dirty) {
                return 'Unsaved changes will be lost.';
            }
            return undefined;
        });
    },

    _mediaLibraryUrl: function() {
        if (window.metisWebsiteAjax && metisWebsiteAjax.media_library_url) {
            return String(metisWebsiteAjax.media_library_url);
        }

        try {
            var current = new URL(window.location.href);
            var segments = current.pathname.split('/').filter(function(part) { return part !== ''; });
            var websiteIndex = segments.lastIndexOf('website');

            if (websiteIndex !== -1) {
                segments[websiteIndex] = 'media';
                segments = segments.slice(0, websiteIndex + 1);
                segments.push('library');
                current.pathname = '/' + segments.join('/') + '/';
                current.search = '';
                current.hash = '';
                return current.toString();
            }
        } catch (err) {}

        return '/media/library/';
    },

    _openMediaLibraryModal: function(onSelect, options) {
        var self = this;
        var opts = options && typeof options === 'object' ? options : {};
        var mediaType = String(opts.type || 'image').toLowerCase() === 'video' ? 'video' : 'image';
        var previousFocus = document.activeElement;
        var sharedAction = 'metis_media_library_list';
        var sharedActionNonce = (window.metisAjax && metisAjax.action_nonces && metisAjax.action_nonces[sharedAction])
            ? metisAjax.action_nonces[sharedAction]
            : '';
        var sharedModuleNonce = (window.metisMediaAjax && window.metisMediaAjax.nonce) ? window.metisMediaAjax.nonce : '';
        var sharedNonce = sharedModuleNonce || sharedActionNonce;
        var sharedCsrfAction = sharedActionNonce ? ('metis_ajax:' + sharedAction) : 'metis_media';
        $('#mwpb-media-modal').remove();
        var html = [
            '<div id="mwpb-media-modal" class="mwpb-media-modal" role="dialog" aria-modal="true" aria-label="Select Media">',
            '  <div class="mwpb-media-panel">',
            '    <div class="mwpb-media-header">',
            '      <strong>Media Library</strong>',
            '      <button type="button" class="mwpb-media-close" aria-label="Close">×</button>',
            '    </div>',
            '    <div class="mwpb-media-toolbar">',
            '      <input type="text" class="mwpb-media-search" placeholder="Search filename...">',
            '      <button type="button" class="mw-btn mw-btn-ghost mw-btn-xs mwpb-media-refresh">Refresh</button>',
            '    </div>',
            '    <div class="mwpb-media-grid"><div class="mwpb-media-empty">Loading media...</div></div>',
            '  </div>',
            '</div>'
        ].join('');
        $('body').append(html);

        function closeModal() {
            $('#mwpb-media-modal').remove();
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        }

        function loadItems() {
            var search = ($('#mwpb-media-modal .mwpb-media-search').val() || '').trim();
            $.ajax({
                url: metisWebsiteAjax.ajax_url,
                type: 'POST',
                data: {
                    action: sharedAction,
                    nonce: sharedNonce,
                    metis_action_nonce: sharedActionNonce || '',
                    metis_csrf_action: sharedCsrfAction,
                    type: mediaType,
                    search: search,
                    limit: 60
                },
                success: function(r) {
                    var $grid = $('#mwpb-media-modal .mwpb-media-grid');
                    if (!r || !r.success || !r.data || !Array.isArray(r.data.items)) {
                        $grid.html('<div class="mwpb-media-empty">Unable to load media.</div>');
                        return;
                    }
                    if (!r.data.items.length) {
                        $grid.html('<div class="mwpb-media-empty">No media found.</div>');
                        return;
                    }
                    var cards = r.data.items.map(function(item) {
                        var url = String(item.url || '');
                        var name = self._esc(item.file_name || 'image');
                        var safeUrl = self._esc(url);
                        var mime = String(item.mime_type || '').toLowerCase();
                        var preview = (mediaType === 'video' || mime.indexOf('video/') === 0)
                            ? '<span class="mwpb-media-video-chip">VIDEO</span>'
                            : '<img src="' + safeUrl + '" alt="' + name + '">';
                        return '<button type="button" class="mwpb-media-item" data-url="' + safeUrl + '" title="' + name + '">' + preview + '<span>' + name + '</span></button>';
                    }).join('');
                    $grid.html(cards);
                },
                error: function() {
                    $('#mwpb-media-modal .mwpb-media-grid').html('<div class="mwpb-media-empty">Unable to load media.</div>');
                }
            });
        }

        $('#mwpb-media-modal').on('click', '.mwpb-media-close', closeModal);
        $(document).off('keydown.mwpbMedia').on('keydown.mwpbMedia', function(e) {
            if (e.key === 'Escape' && $('#mwpb-media-modal').length) {
                e.preventDefault();
                closeModal();
                $(document).off('keydown.mwpbMedia');
            }
        });
        $('#mwpb-media-modal').on('click', function(e) {
            if (e.target && e.target.id === 'mwpb-media-modal') closeModal();
        });
        $('#mwpb-media-modal').on('click', '.mwpb-media-refresh', loadItems);
        $('#mwpb-media-modal').on('keydown', '.mwpb-media-search', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadItems();
            }
        });
        $('#mwpb-media-modal').on('click', '.mwpb-media-item', function() {
            var url = $(this).data('url') || '';
            if (typeof onSelect === 'function') onSelect(String(url));
            $(document).off('keydown.mwpbMedia');
            closeModal();
        });

        loadItems();
    },

    _uploadMediaAsset: function(onSelect, options) {
        var opts = options && typeof options === 'object' ? options : {};
        var mediaType = String(opts.type || 'image').toLowerCase() === 'video' ? 'video' : 'image';
        var nonce = (window.metisMediaAjax && metisMediaAjax.nonce)
            ? String(metisMediaAjax.nonce)
            : String((window.metisWebsiteAjax && metisWebsiteAjax.nonce) || '');
        if (!nonce) {
            this._toast('Media upload nonce is unavailable.', 'error');
            return;
        }
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = mediaType === 'video' ? 'video/*' : 'image/*';
        input.style.display = 'none';
        document.body.appendChild(input);
        var self = this;
        input.addEventListener('change', function() {
            var file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                if (input.parentNode) input.parentNode.removeChild(input);
                return;
            }
            var formData = new window.FormData();
            formData.append('action', 'metis_media_library_upload');
            formData.append('nonce', nonce);
            formData.append('file', file);
            $.ajax({
                url: (window.metisWebsiteAjax && metisWebsiteAjax.ajax_url) ? metisWebsiteAjax.ajax_url : '/api/ajax',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(r) {
                    if (r && r.success && r.data && r.data.url) {
                        if (typeof onSelect === 'function') onSelect(String(r.data.url));
                        self._toast('Upload complete.', 'success');
                        return;
                    }
                    self._toast((r && r.data && r.data.message) ? r.data.message : 'Upload failed.', 'error');
                },
                error: function(xhr) {
                    var message = '';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = String(xhr.responseJSON.data.message);
                    }
                    self._toast(message || 'Upload failed.', 'error');
                },
                complete: function() {
                    if (input.parentNode) input.parentNode.removeChild(input);
                }
            });
        });
        input.click();
    },

    _safeSlug: function(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '');
    },

    _publicPreviewUrl: function() {
        if (!this._hasSavedEntity()) return '';
        var context = String(this.currentContext || 'website');
        if (this._isNewsletterContext()) return '';
        if (context === 'template') return '';
        var meta = this._entityMeta || {};
        var slug = this._safeSlug(meta.slug || '');
        var origin = String(window.location.origin || '');
        if (context === 'post') {
            if (!slug) return '';
            return origin + '/blog/' + encodeURIComponent(slug) + '/';
        }
        if (!slug || meta.is_homepage) {
            return origin + '/';
        }
        return origin + '/' + encodeURIComponent(slug) + '/';
    },

    _openPreview: function() {
        if (String(this.currentContext || '') === 'template') {
            this._toast('Template preview is context-driven. Preview from an assigned page or post.', 'warning');
            return;
        }
        if (this._isNewsletterContext()) {
            this._toast('Newsletter preview is generated in campaign/template workflows.', 'warning');
            return;
        }
        if (!this._hasSavedEntity()) {
            this._toast('Save once before previewing.', 'warning');
            return;
        }
        var url = this._publicPreviewUrl();
        if (!url) {
            this._toast('Preview URL unavailable. Save and set a slug first.', 'warning');
            return;
        }
        window.open(url, '_blank', 'noopener');
    },

    _previewDeviceStorageKey: function() {
        return 'metis.editor.preview_device.' + String(this.currentContext || 'website');
    },

    _previewWidthMap: function() {
        if (this._isNewsletterContext()) {
            return {
                desktop: 720,
                tablet: 640,
                mobile: 420
            };
        }
        return {
            desktop: 1200,
            tablet: 860,
            mobile: 430
        };
    },

    _sanitizePreviewDevice: function(device) {
        var value = String(device || '').toLowerCase();
        if (value === 'tablet' || value === 'mobile') return value;
        return 'desktop';
    },

    _restorePreviewDevice: function() {
        var saved = '';
        try {
            saved = String(window.sessionStorage.getItem(this._previewDeviceStorageKey()) || '');
        } catch (_e) {}
        this._setPreviewDevice(saved || this._previewDevice || 'desktop', { persist: false });
    },

    _setPreviewDevice: function(device, options) {
        var opts = options && typeof options === 'object' ? options : {};
        var value = this._sanitizePreviewDevice(device);
        this._previewDevice = value;

        $('#mwpb-device-toggle [data-device]').removeClass('is-active').attr('aria-pressed', 'false');
        $('#mwpb-device-toggle [data-device="' + value + '"]').addClass('is-active').attr('aria-pressed', 'true');

        if (this.editor && typeof this.editor.setPreviewDevice === 'function') {
            this.editor.setPreviewDevice(value, this._previewWidthMap());
        }

        if (opts.persist === false) return;
        try {
            window.sessionStorage.setItem(this._previewDeviceStorageKey(), value);
        } catch (_e) {}
    },

    _defaultTemplateStructure: function() {
        return {
            layout: {
                main_with_sidebar: false,
                sidebar_position: 'right',
                content_max_width: 'var(--metis-container-content,860px)',
                suppress_header_on_homepage: false,
                template_kind: 'body'
            },
            regions: {
                header: { enabled: true, source: 'blocks', layout_id: 0, blocks: [] },
                main: { enabled: true, source: 'blocks', layout_id: 0, blocks: [] },
                sidebar: { enabled: false, source: 'none', layout_id: 0, blocks: [] },
                footer: { enabled: true, source: 'blocks', layout_id: 0, blocks: [] }
            }
        };
    },

    _normalizeTemplateStructureFromEntity: function(template) {
        var out = this._defaultTemplateStructure();
        var parsed = null;
        if (template && template.structure_json) {
            try { parsed = JSON.parse(template.structure_json); } catch (_e) {}
        }
        if (!parsed || typeof parsed !== 'object') return out;
        var layout = parsed.layout && typeof parsed.layout === 'object' ? parsed.layout : {};
        out.layout.main_with_sidebar = !!layout.main_with_sidebar;
        out.layout.sidebar_position = String(layout.sidebar_position || 'right') === 'left' ? 'left' : 'right';
        out.layout.content_max_width = String(layout.content_max_width || out.layout.content_max_width);
        out.layout.suppress_header_on_homepage = !!layout.suppress_header_on_homepage;
        out.layout.template_kind = (layout.template_kind === 'header' || layout.template_kind === 'footer') ? layout.template_kind : 'body';
        var regions = parsed.regions && typeof parsed.regions === 'object' ? parsed.regions : {};
        ['header', 'main', 'sidebar', 'footer'].forEach(function(region) {
            var current = regions[region] && typeof regions[region] === 'object' ? regions[region] : {};
            out.regions[region] = {
                enabled: region === 'main' ? true : !!current.enabled,
                source: (current.source === 'global_layout' || current.source === 'none' || current.source === 'blocks') ? current.source : (region === 'main' ? 'blocks' : 'none'),
                layout_id: parseInt(current.layout_id || 0, 10) || 0,
                blocks: Array.isArray(current.blocks) ? current.blocks : []
            };
            if (region === 'main' && out.regions[region].source === 'none') {
                out.regions[region].source = 'blocks';
            }
        });
        return out;
    },

    _templateRegionBlocks: function(region) {
        if (!this._templateStructure || !this._templateStructure.regions) return [];
        var key = String(region || 'main');
        var regionData = this._templateStructure.regions[key] || {};
        return Array.isArray(regionData.blocks) ? regionData.blocks : [];
    },

    _templateCommitActiveRegion: function() {
        if (!this.editor || this.currentContext !== 'template') return;
        var region = String(this._templateActiveRegion || 'main');
        if (!this._templateStructure || !this._templateStructure.regions || !this._templateStructure.regions[region]) return;
        this._templateStructure.regions[region].blocks = this.editor.getBlocks();
    },

    _templateSwitchRegion: function(region) {
        var next = String(region || 'main');
        if (!this._templateStructure || !this._templateStructure.regions[next]) return;
        this._templateCommitActiveRegion();
        this._templateActiveRegion = next;
        if (this.editor && typeof this.editor.setBlocks === 'function') {
            this.editor.setBlocks(this._templateRegionBlocks(next));
        }
    },

    _renderTemplateSettingsPane: function() {
        var kind = this._templateMeta && this._templateMeta.kind ? this._templateMeta.kind : 'body';
        var bodyFor = this._templateMeta && this._templateMeta.body_for ? this._templateMeta.body_for : 'page';
        var isDefault = this._templateMeta && this._templateMeta.is_default ? 1 : 0;
        var suppressHomeHeader = this._templateMeta && this._templateMeta.suppress_home_header ? 1 : 0;
        var status = String((this._entityMeta && this._entityMeta.status) || 'draft').toLowerCase();
        if (status !== 'draft' && status !== 'published') status = 'draft';
        var creator = String((this._entityMeta && this._entityMeta.created_by) || 'Unknown');
        var regions = this._templateStructure && this._templateStructure.regions ? this._templateStructure.regions : this._defaultTemplateStructure().regions;
        var paneHtml = [
            '<div class="mwpb-props-section-label">Template Basics</div>',
            '<div class="mwpb-field" style="margin-bottom:8px;"><label>Status</label><select id="mwpb-setting-status" class="mwpb-input mwpb-select"><option value="draft"' + (status === 'draft' ? ' selected' : '') + '>Draft</option><option value="published"' + (status === 'published' ? ' selected' : '') + '>Published</option></select></div>',
            '<div class="mwpb-field" style="margin-bottom:8px;"><label>Created By</label><div class="mwpb-meta-value">' + this._esc(creator) + '</div></div>',
            '<div class="mwpb-field" style="margin-bottom:8px;"><label>Template Kind</label><select id="mwpb-template-kind" class="mwpb-input mwpb-select"><option value="body"' + (kind === 'body' ? ' selected' : '') + '>Body</option><option value="header"' + (kind === 'header' ? ' selected' : '') + '>Header</option><option value="footer"' + (kind === 'footer' ? ' selected' : '') + '>Footer</option></select></div>',
            '<div class="mwpb-field" id="mwpb-template-body-for-wrap" style="' + (kind === 'body' ? '' : 'display:none;') + 'margin-bottom:8px;"><label>Body For</label><select id="mwpb-template-body-for" class="mwpb-input mwpb-select"><option value="page"' + (bodyFor === 'page' ? ' selected' : '') + '>Page</option><option value="post"' + (bodyFor === 'post' ? ' selected' : '') + '>Post</option></select></div>',
            '<label class="metis-settings-flag" id="mwpb-template-default-wrap" style="' + (kind === 'body' ? '' : 'display:none;') + 'margin-bottom:8px;"><input type="checkbox" id="mwpb-template-is-default" value="1"' + (isDefault ? ' checked' : '') + '> Set as default for this body type</label>',
            '<label class="metis-settings-flag" id="mwpb-template-hide-home-header-wrap" style="' + (kind === 'body' ? '' : 'display:none;') + 'margin-bottom:8px;"><input type="checkbox" id="mwpb-template-hide-home-header" value="1"' + (suppressHomeHeader ? ' checked' : '') + '> Hide header on homepage only</label>',
            '<div class="mwpb-props-section-label">Build Target</div>',
            '<div class="mwpb-field" style="margin-bottom:8px;"><label>Section To Edit</label><select id="mwpb-template-active-region" class="mwpb-input mwpb-select"></select></div>',
            '<div id="mwpb-template-body-options"' + (kind === 'body' ? '' : ' style="display:none;"') + '>',
            '  <div class="mwpb-field" style="margin-bottom:8px;"><label>Header Source</label><select id="mwpb-template-header-source" class="mwpb-input mwpb-select"><option value="blocks"' + (regions.header.source === 'blocks' ? ' selected' : '') + '>Custom Header</option><option value="global_layout"' + (regions.header.source === 'global_layout' ? ' selected' : '') + '>Global Header</option><option value="none"' + (regions.header.source === 'none' ? ' selected' : '') + '>No Header</option></select></div>',
            '  <div class="mwpb-field" style="margin-bottom:8px;"><label>Footer Source</label><select id="mwpb-template-footer-source" class="mwpb-input mwpb-select"><option value="blocks"' + (regions.footer.source === 'blocks' ? ' selected' : '') + '>Custom Footer</option><option value="global_layout"' + (regions.footer.source === 'global_layout' ? ' selected' : '') + '>Global Footer</option><option value="none"' + (regions.footer.source === 'none' ? ' selected' : '') + '>No Footer</option></select></div>',
            '  <div class="mwpb-field" style="margin-bottom:8px;"><label>Body Source</label><select id="mwpb-template-main-source" class="mwpb-input mwpb-select"><option value="blocks"' + (regions.main.source === 'blocks' ? ' selected' : '') + '>Custom Body</option><option value="global_layout"' + (regions.main.source === 'global_layout' ? ' selected' : '') + '>Global Body</option></select></div>',
            '</div>'
        ].join('');
        $('#mwpb-props-panel .mwpb-page-settings').html(paneHtml);
        this._bindTemplateSettingsEvents();
        this._refreshTemplateRegionOptions();
    },

    _refreshTemplateRegionOptions: function() {
        if (this.currentContext !== 'template') return;
        var kind = this._templateMeta && this._templateMeta.kind ? this._templateMeta.kind : 'body';
        var $select = $('#mwpb-template-active-region');
        if (!$select.length) return;
        var options = [];
        if (kind === 'header') options = [ { value: 'header', label: 'Header' } ];
        else if (kind === 'footer') options = [ { value: 'footer', label: 'Footer' } ];
        else options = [{ value: 'header', label: 'Header' }, { value: 'main', label: 'Body' }, { value: 'sidebar', label: 'Sidebar' }, { value: 'footer', label: 'Footer' }];
        $select.html(options.map(function(opt) {
            return '<option value="' + opt.value + '"' + (String(opt.value) === String(this._templateActiveRegion) ? ' selected' : '') + '>' + opt.label + '</option>';
        }, this).join(''));
    },

    _bindTemplateSettingsEvents: function() {
        var self = this;
        var $scope = $('#mwpb-props-panel .mwpb-page-settings');
        $scope.off('.mwpbtemplate');
        $scope.on('change.mwpbtemplate input.mwpbtemplate', '#mwpb-template-kind, #mwpb-template-body-for, #mwpb-template-is-default, #mwpb-template-hide-home-header, #mwpb-template-header-source, #mwpb-template-footer-source, #mwpb-template-main-source, #mwpb-setting-status', function() {
            var kind = String($('#mwpb-template-kind').val() || 'body');
            self._templateMeta.kind = kind;
            self._templateMeta.body_for = String($('#mwpb-template-body-for').val() || 'page');
            self._templateMeta.is_default = $('#mwpb-template-is-default').is(':checked') ? 1 : 0;
            self._templateMeta.suppress_home_header = $('#mwpb-template-hide-home-header').is(':checked') ? 1 : 0;
            self._entityMeta = self._entityMeta || {};
            self._entityMeta.status = String($('#mwpb-setting-status').val() || 'draft');
            self._templateStructure.layout.template_kind = kind;
            self._templateStructure.layout.suppress_header_on_homepage = !!self._templateMeta.suppress_home_header;
            self._templateStructure.regions.header.source = String($('#mwpb-template-header-source').val() || self._templateStructure.regions.header.source);
            self._templateStructure.regions.footer.source = String($('#mwpb-template-footer-source').val() || self._templateStructure.regions.footer.source);
            self._templateStructure.regions.main.source = String($('#mwpb-template-main-source').val() || self._templateStructure.regions.main.source);
            $('#mwpb-template-body-for-wrap, #mwpb-template-default-wrap, #mwpb-template-hide-home-header-wrap, #mwpb-template-body-options').toggle(kind === 'body');
            self._refreshTemplateRegionOptions();
            self._dirty = true;
            self._showSaveStatus('Unsaved changes');
            self._scheduleAutosave();
        });
        $scope.on('change.mwpbtemplate', '#mwpb-template-active-region', function() {
            self._templateSwitchRegion($(this).val());
            self._dirty = true;
            self._showSaveStatus('Unsaved changes');
            self._scheduleAutosave();
        });
    },

    _bindSidebarTabs: function() {
        var self = this;
        var $panel = $('#mwpb-props-panel');
        $panel.off('click.mwpbsidebartabs').on('click.mwpbsidebartabs', '[data-sidebar-tab]', function() {
            self._setSidebarTab($(this).data('sidebar-tab'));
        });
        this._setSidebarTab('blocks');
    },

    _setSidebarTab: function(tab) {
        var requested = String(tab || '').toLowerCase();
        var value = (requested === 'blocks' || requested === 'properties' || requested === 'settings') ? requested : 'blocks';
        var $panel = $('#mwpb-props-panel');
        $panel.find('[data-sidebar-tab]')
            .removeClass('is-active')
            .attr('aria-selected', 'false');
        $panel.find('[data-sidebar-tab="' + value + '"]')
            .addClass('is-active')
            .attr('aria-selected', 'true');
        $panel.find('.mwpb-sidebar-pane').removeClass('is-active').hide();
        $panel.find('.mwpb-sidebar-pane[data-pane="' + value + '"]').addClass('is-active').show();
        if (value === 'blocks' && this.editor && typeof this.editor.toggleBlocksPanel === 'function') {
            this.editor.toggleBlocksPanel(true);
        }
        if (this.editor && typeof this.editor.setPropsVisible === 'function') {
            this.editor.setPropsVisible(value === 'properties');
            /* _renderPropsPanel is internal to BB editor; refresh() is the public API */
            if (value === 'properties' && typeof this.editor.refresh === 'function') {
                this.editor.refresh();
            }
        }
    },

    _mountBlocksPanelInSidebar: function() {
        /* BB model: the editor's own left sidebar is part of the .mube-root flex layout
           inside #mwpb-editor-mount. The shell's #mwpb-props-panel is hidden by CSS.
           Nothing to move — the editor is self-contained. */
    },

    _renderStandardSettingsPane: function(opts) {
        var cfg = (opts && typeof opts === 'object') ? opts : {};
        var isPage = !!cfg.isPage;
        var slug = String((this._entityMeta && this._entityMeta.slug) || '');
        var status = String((this._entityMeta && this._entityMeta.status) || 'draft').toLowerCase();
        if (status !== 'draft' && status !== 'scheduled' && status !== 'published') status = 'draft';
        var scheduleLocal = this._toDateTimeLocal((this._entityMeta && this._entityMeta.schedule_at) || '');
        var creator = String((this._entityMeta && this._entityMeta.created_by) || 'Unknown');
        var excerpt = String(cfg.excerpt || '');
        var seoTitle = String(cfg.seoTitle || '');
        var seoDesc = String(cfg.seoDesc || '');
        var isHomepage = !!cfg.isHomepage;
        var paneHtml = [
            '<div class="mwpb-props-section-label">Basics</div>',
            '<div class="mwpb-field"><label>Slug</label><input type="text" id="mwpb-slug-input" class="mwpb-input" placeholder="my-slug" value="' + this._esc(slug) + '"></div>',
            '<div class="mwpb-field"><label>Status</label><select id="mwpb-setting-status" class="mwpb-input mwpb-select"><option value="draft"' + (status === 'draft' ? ' selected' : '') + '>Draft</option><option value="scheduled"' + (status === 'scheduled' ? ' selected' : '') + '>Scheduled</option><option value="published"' + (status === 'published' ? ' selected' : '') + '>Published</option></select></div>',
            '<div class="mwpb-field" id="mwpb-setting-schedule-wrap"' + (status === 'scheduled' ? '' : ' style="display:none;"') + '><label>Schedule At</label><input type="datetime-local" id="mwpb-setting-schedule-at" class="mwpb-input" value="' + this._esc(scheduleLocal) + '"></div>',
            '<div class="mwpb-field"><label>Created By</label><div class="mwpb-meta-value">' + this._esc(creator) + '</div></div>',
            (!isPage ? '<div class="mwpb-field"><label class="mwpb-field-label">Excerpt</label><textarea id="mwpb-excerpt" class="mwpb-input mwpb-textarea" placeholder="Short summary…" style="height:60px;">' + this._esc(excerpt) + '</textarea></div>' : ''),
            '<div class="mwpb-props-section-label">SEO</div>',
            '<div class="mwpb-field"><label>SEO Title</label><input type="text" id="mwpb-seo-title" class="mwpb-input" placeholder="Overrides page title…" value="' + this._esc(seoTitle) + '"></div>',
            '<div class="mwpb-field"><label>Meta Description</label><textarea id="mwpb-seo-desc" class="mwpb-input mwpb-textarea" placeholder="160 chars max…" style="height:54px;">' + this._esc(seoDesc) + '</textarea></div>',
            (isPage ? '<label class="metis-settings-flag"><input type="checkbox" id="mwpb-set-homepage" value="1"' + (isHomepage ? ' checked' : '') + '> Set as Homepage</label>' : '')
        ].join('');
        $('#mwpb-props-panel .mwpb-page-settings').html(paneHtml);
        var self = this;
        $('#mwpb-props-panel .mwpb-page-settings').off('.mwpbsettings').on('input.mwpbsettings change.mwpbsettings', '#mwpb-slug-input, #mwpb-excerpt, #mwpb-seo-title, #mwpb-seo-desc, #mwpb-set-homepage, #mwpb-setting-status, #mwpb-setting-schedule-at', function() {
            var selectedStatus = String($('#mwpb-setting-status').val() || 'draft');
            $('#mwpb-setting-schedule-wrap').toggle(selectedStatus === 'scheduled');
            self._entityMeta = self._entityMeta || {};
            self._entityMeta.status = selectedStatus;
            self._entityMeta.schedule_at = self._fromDateTimeLocal(self._safeFieldTrim('#mwpb-setting-schedule-at'));
            self._dirty = true;
            self._showSaveStatus('Unsaved changes');
            self._scheduleAutosave();
        });
    },

    _renderNewsletterSettingsPane: function() {
        var self = this;
        var isTemplate = this._isNewsletterTemplateContext();
        this._newsletterMeta = this._newsletterMeta && typeof this._newsletterMeta === 'object' ? this._newsletterMeta : {};
        var meta = this._newsletterMeta;
        if (typeof meta.subject !== 'string') meta.subject = '';
        if (typeof meta.preheader !== 'string') meta.preheader = '';
        if (typeof meta.from_name !== 'string') meta.from_name = '';
        if (typeof meta.from_email !== 'string') meta.from_email = '';
        if (typeof meta.reply_to !== 'string') meta.reply_to = '';
        if (typeof meta.template_code !== 'string') meta.template_code = '';
        if (!Array.isArray(meta.list_ids)) meta.list_ids = [];
        if (typeof meta.scheduled_at !== 'string') meta.scheduled_at = '';
        if (typeof meta.audience_json !== 'string' || meta.audience_json === '') meta.audience_json = JSON.stringify({ mode: 'list' });
        if (typeof meta.attachments_json !== 'string' || meta.attachments_json === '') meta.attachments_json = '[]';

        var scheduleLocal = this._toDateTimeLocal(meta.scheduled_at || '');
        var listIdsValue = Array.isArray(meta.list_ids) ? meta.list_ids.join(', ') : '';
        var creator = String((this._entityMeta && this._entityMeta.created_by) || 'Unknown');
        var paneHtml = [
            '<div class="mwpb-props-section-label">' + (isTemplate ? 'Template Basics' : 'Campaign Basics') + '</div>',
            '<div class="mwpb-field"><label>Status</label><div class="mwpb-meta-value">Draft</div></div>',
            '<div class="mwpb-field"><label>Created By</label><div class="mwpb-meta-value">' + this._esc(creator) + '</div></div>',
            '<div class="mwpb-field"><label>Subject (required)</label><input type="text" id="mwpb-nl-subject" class="mwpb-input" required value="' + this._esc(meta.subject || '') + '" placeholder="Email subject"></div>',
            (isTemplate ? '' : '<div class="mwpb-field"><label>Preheader</label><input type="text" id="mwpb-nl-preheader" class="mwpb-input" value="' + this._esc(meta.preheader || '') + '" placeholder="Preview text shown in inbox"></div>'),
            (isTemplate ? '' : '<div class="mwpb-field"><label>Template Code</label><input type="text" id="mwpb-nl-template-code" class="mwpb-input" value="' + this._esc(meta.template_code || '') + '" placeholder="NLT-..."></div>'),
            '<div class="mwpb-props-section-label">Sender</div>',
            '<div class="mwpb-field"><label>From Name (required)</label><input type="text" id="mwpb-nl-from-name" class="mwpb-input" required value="' + this._esc(meta.from_name || '') + '"></div>',
            '<div class="mwpb-field"><label>From Email (required)</label><input type="email" id="mwpb-nl-from-email" class="mwpb-input" required value="' + this._esc(meta.from_email || '') + '" placeholder="name@domain.com"></div>',
            '<div class="mwpb-field"><label>Reply-To (required)</label><input type="email" id="mwpb-nl-reply-to" class="mwpb-input" required value="' + this._esc(meta.reply_to || '') + '" placeholder="name@domain.com"></div>',
            (isTemplate ? '' : '<div class="mwpb-props-section-label">Audience</div>'),
            (isTemplate ? '' : '<div class="mwpb-field"><label>List IDs</label><input type="text" id="mwpb-nl-list-ids" class="mwpb-input" value="' + this._esc(listIdsValue) + '" placeholder="1, 2, 3"></div>'),
            (isTemplate ? '' : '<div class="mwpb-props-section-label">Delivery</div>'),
            (isTemplate ? '' : '<div class="mwpb-field"><label>Scheduled Send</label><input type="datetime-local" id="mwpb-nl-scheduled-at" class="mwpb-input mwpb-topbar-datetime" value="' + this._esc(scheduleLocal) + '"></div>')
        ].join('');
        $('#mwpb-props-panel .mwpb-page-settings').html(paneHtml);

        var $scope = $('#mwpb-props-panel .mwpb-page-settings');
        $scope.off('.mwpbnl').on('input.mwpbnl change.mwpbnl', '#mwpb-nl-subject, #mwpb-nl-preheader, #mwpb-nl-from-name, #mwpb-nl-from-email, #mwpb-nl-reply-to, #mwpb-nl-template-code, #mwpb-nl-list-ids, #mwpb-nl-scheduled-at', function() {
            self._collectNewsletterSettingsFromPane();
            self._dirty = true;
            self._showSaveStatus('Unsaved changes');
            self._scheduleAutosave();
        });
    },

    _collectNewsletterSettingsFromPane: function() {
        if (!this._isNewsletterContext()) return;
        this._newsletterMeta = this._newsletterMeta && typeof this._newsletterMeta === 'object' ? this._newsletterMeta : {};
        var meta = this._newsletterMeta;
        if ($('#mwpb-nl-subject').length) meta.subject = this._safeFieldTrim('#mwpb-nl-subject');
        if ($('#mwpb-nl-preheader').length) meta.preheader = this._safeFieldTrim('#mwpb-nl-preheader');
        if ($('#mwpb-nl-from-name').length) meta.from_name = this._safeFieldTrim('#mwpb-nl-from-name');
        if ($('#mwpb-nl-from-email').length) meta.from_email = this._safeFieldTrim('#mwpb-nl-from-email');
        if ($('#mwpb-nl-reply-to').length) meta.reply_to = this._safeFieldTrim('#mwpb-nl-reply-to');
        if ($('#mwpb-nl-template-code').length) meta.template_code = this._safeFieldTrim('#mwpb-nl-template-code');
        if ($('#mwpb-nl-list-ids').length) {
            var rawListIds = this._safeFieldTrim('#mwpb-nl-list-ids');
            meta.list_ids = rawListIds === ''
                ? []
                : rawListIds.split(',')
                    .map(function(part) { return parseInt(String(part || '').trim(), 10); })
                    .filter(function(value) { return Number.isFinite(value) && value > 0; });
        }
        if ($('#mwpb-nl-scheduled-at').length) {
            meta.scheduled_at = this._fromDateTimeLocal(this._safeFieldTrim('#mwpb-nl-scheduled-at'));
        }
    },

    _validateNewsletterSettings: function(title) {
        if (!this._isNewsletterContext()) return '';
        var meta = this._newsletterMeta && typeof this._newsletterMeta === 'object' ? this._newsletterMeta : {};
        var subject = String(meta.subject || '').trim();
        if (!subject) {
            subject = String(title || '').trim();
            meta.subject = subject;
        }
        var fromName = String(meta.from_name || '').trim();
        var fromEmail = String(meta.from_email || '').trim();
        var replyTo = String(meta.reply_to || '').trim();
        if (!subject) return 'Subject is required.';
        if (!fromName) return 'From name is required.';
        if (!fromEmail) return 'From email is required.';
        if (!replyTo) return 'Reply-to email is required.';
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(fromEmail)) return 'From email must be valid.';
        if (!emailPattern.test(replyTo)) return 'Reply-to email must be valid.';
        return '';
    },

    _save: function(status, options) {
        var self   = this;
        var opts = options && typeof options === 'object' ? options : {};
        var autosave = !!opts.autosave;
        var silent = !!opts.silent;
        if (this._lockBlocked) {
            if (!silent) this._toast('This editor is locked by another user.', 'error');
            return;
        }
        if (this._saveInFlight) {
            return;
        }
        var title  = $('#mwpb-title-input').val().trim();
        if (!title) {
            if (!silent) {
                this._toast('Please enter a title before saving.', 'warning');
                $('#mwpb-title-input').focus();
            }
            return;
        }

        var isNewsletterCampaign = this._isNewsletterCampaignContext();
        var isNewsletterTemplate = this._isNewsletterTemplateContext();
        var isTemplate = this.currentContext === 'template' || isNewsletterTemplate;
        var isPage = this.currentContext !== 'post' && !isTemplate && !isNewsletterCampaign;
        var _em = (this.editor && typeof this.editor.getPageMeta === 'function') ? this.editor.getPageMeta() : null;
        var requestedStatus = String(status || (_em && _em.status) || (this._entityMeta && this._entityMeta.status) || 'draft').toLowerCase();
        if (this._isNewsletterContext()) {
            requestedStatus = 'draft';
        }
        if (isTemplate && requestedStatus === 'scheduled') requestedStatus = 'draft';
        if (requestedStatus !== 'draft' && requestedStatus !== 'published' && requestedStatus !== 'scheduled') {
            requestedStatus = 'draft';
        }
        if (this._isNewsletterContext()) {
            this._collectNewsletterSettingsFromPane();
            var newsletterSettingsError = this._validateNewsletterSettings(title);
            if (newsletterSettingsError) {
                if (!silent) this._toast(newsletterSettingsError, 'warning');
                return;
            }
        }
        if (autosave) {
            requestedStatus = 'draft';
        }
        var scheduleAt = (_em && _em.schedule_at) || this._safeFieldTrim('#mwpb-setting-schedule-at');
        if (requestedStatus === 'scheduled' && !scheduleAt) {
            if (autosave) {
                requestedStatus = 'draft';
            } else {
                this._toast('Please pick a schedule date/time.', 'warning');
                $('#mwpb-setting-schedule-at').focus();
                return;
            }
        }
        var action;
        var data;

        if (isNewsletterTemplate) {
            var templateBlocks = this.editor.getBlocks();
            if (this.editor && typeof this.editor.validateForSave === 'function') {
                var newsletterTemplateValidation = this.editor.validateForSave();
                if (!newsletterTemplateValidation.valid) {
                    this._toast((newsletterTemplateValidation.errors[0] && newsletterTemplateValidation.errors[0].message) || 'Template content is invalid for this context.', 'error');
                    return;
                }
            }
            action = 'metis_newsletter_save_template';
            var templateDoc = this._newsletterBlocksToDoc(templateBlocks);
            data = {
                action: action,
                template_code: String(this.currentKey || ''),
                name: title,
                subject: String((this._newsletterMeta && this._newsletterMeta.subject) || title || ''),
                from_name: String((this._newsletterMeta && this._newsletterMeta.from_name) || ''),
                from_email: String((this._newsletterMeta && this._newsletterMeta.from_email) || ''),
                reply_to: String((this._newsletterMeta && this._newsletterMeta.reply_to) || ''),
                doc_json: JSON.stringify(templateDoc),
                autosave: autosave ? 1 : 0
            };
            if (this.currentId) {
                data.template_id = this.currentId;
            }
        } else if (isTemplate) {
            var liveRegionBlocks = (this.editor && typeof this.editor.getBlocks === 'function' && Array.isArray(this.editor.getBlocks()))
                ? this.editor.getBlocks()
                : [];
            this._templateCommitActiveRegion();
            if (!this._templateStructure || typeof this._templateStructure !== 'object') {
                this._templateStructure = this._defaultTemplateStructure();
            }
            if (!this._templateStructure.regions || typeof this._templateStructure.regions !== 'object') {
                this._templateStructure.regions = this._defaultTemplateStructure().regions;
            }
            var activeRegionKey = String(this._templateActiveRegion || 'main');
            if (Array.isArray(liveRegionBlocks) && liveRegionBlocks.length && this._templateStructure.regions[activeRegionKey]) {
                var activeRegionBlocks = this._templateStructure.regions[activeRegionKey].blocks;
                if (!Array.isArray(activeRegionBlocks) || activeRegionBlocks.length === 0) {
                    this._templateStructure.regions[activeRegionKey].blocks = JSON.parse(JSON.stringify(liveRegionBlocks));
                    if (this._templateStructure.regions[activeRegionKey].source === 'none') {
                        this._templateStructure.regions[activeRegionKey].source = 'blocks';
                    }
                }
            }
            var regionNames = ['header', 'main', 'sidebar', 'footer', 'banners'];
            var templateBlockCount = 0;
            for (var r = 0; r < regionNames.length; r++) {
                var bucket = this._templateStructure.regions[regionNames[r]];
                var bucketBlocks = bucket && Array.isArray(bucket.blocks) ? bucket.blocks : [];
                templateBlockCount += bucketBlocks.length;
            }
            if (templateBlockCount === 0 && Array.isArray(liveRegionBlocks) && liveRegionBlocks.length) {
                this._templateStructure.regions.main = this._templateStructure.regions.main || { enabled: true, source: 'blocks', layout_id: 0, blocks: [] };
                this._templateStructure.regions.main.blocks = JSON.parse(JSON.stringify(liveRegionBlocks));
                this._templateStructure.regions.main.enabled = true;
                this._templateStructure.regions.main.source = 'blocks';
            }
            if (this.editor && typeof this.editor.validateForSave === 'function') {
                var templateValidation = this.editor.validateForSave();
                if (!templateValidation.valid) {
                    this._toast((templateValidation.errors[0] && templateValidation.errors[0].message) || 'Template content is invalid for this context.', 'error');
                    return;
                }
            }
            action = 'metis_website_template_save';
            var templateKind = this._templateMeta && this._templateMeta.kind ? String(this._templateMeta.kind) : 'body';
            var bodyFor = this._templateMeta && this._templateMeta.body_for ? String(this._templateMeta.body_for) : 'page';
            var templateType = (templateKind === 'header' || templateKind === 'footer') ? templateKind : (bodyFor === 'post' ? 'post' : 'page');
            var templateKey = this._templateMeta && this._templateMeta.template_key ? String(this._templateMeta.template_key) : '';
            if (templateKey === '') {
                templateKey = 'tpl_' + String(title || 'template').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 48);
            }
            var structure = this._templateStructure && typeof this._templateStructure === 'object'
                ? this._templateStructure
                : this._defaultTemplateStructure();
            structure.layout = structure.layout && typeof structure.layout === 'object' ? structure.layout : {};
            structure.layout.template_kind = templateKind;
            structure.layout.suppress_header_on_homepage = templateKind === 'body' && !!(this._templateMeta && this._templateMeta.suppress_home_header);
            data = {
                action: action,
                nonce: this._ajaxConfigForAction(action).nonce,
                metis_action_nonce: this._ajaxConfigForAction(action).action_nonce || '',
                metis_csrf_action: this._ajaxConfigForAction(action).csrf_action || ('metis_ajax:' + action),
                name: title,
                template_key: templateKey,
                template_type: templateType,
                status: requestedStatus,
                structure_json: JSON.stringify(structure),
                is_default: templateKind === 'body' && this._templateMeta && this._templateMeta.is_default ? 1 : 0,
                autosave: autosave ? 1 : 0
            };
        } else if (isNewsletterCampaign) {
            var campaignBlocks = this.editor.getBlocks();
            if (this.editor && typeof this.editor.validateForSave === 'function') {
                var newsletterValidation = this.editor.validateForSave();
                if (!newsletterValidation.valid) {
                    if (!silent) {
                        this._toast((newsletterValidation.errors[0] && newsletterValidation.errors[0].message) || 'Content is invalid for this context.', 'error');
                    }
                    return;
                }
            }
            action = 'metis_newsletter_save_campaign';
            var campaignDoc = this._newsletterBlocksToDoc(campaignBlocks);
            data = {
                action: action,
                campaign_code: String(this.currentKey || ''),
                name: title,
                subject: String((this._newsletterMeta && this._newsletterMeta.subject) || title || ''),
                preheader: String((this._newsletterMeta && this._newsletterMeta.preheader) || ''),
                from_name: String((this._newsletterMeta && this._newsletterMeta.from_name) || ''),
                from_email: String((this._newsletterMeta && this._newsletterMeta.from_email) || ''),
                reply_to: String((this._newsletterMeta && this._newsletterMeta.reply_to) || ''),
                template_code: String((this._newsletterMeta && this._newsletterMeta.template_code) || ''),
                scheduled_at: String((this._newsletterMeta && this._newsletterMeta.scheduled_at) || ''),
                doc_json: JSON.stringify(campaignDoc),
                list_ids: JSON.stringify((this._newsletterMeta && Array.isArray(this._newsletterMeta.list_ids)) ? this._newsletterMeta.list_ids : []),
                audience_json: String((this._newsletterMeta && this._newsletterMeta.audience_json) || JSON.stringify({ mode: 'list' })),
                attachments_json: String((this._newsletterMeta && this._newsletterMeta.attachments_json) || '[]'),
                autosave: autosave ? 1 : 0
            };
            if (this.currentId) {
                data.campaign_id = this.currentId;
            }
        } else {
            /* Use full sections layout if available (BB model), else fall back to flat blocks */
            var layoutJson;
            if (this.editor && typeof this.editor.getSaveLayout === 'function') {
                layoutJson = JSON.stringify(this.editor.getSaveLayout());
            } else {
                var blocks = this.editor.getBlocks();
                if (this.editor && typeof this.editor.validateForSave === 'function') {
                    var validation = this.editor.validateForSave();
                    if (!validation.valid) {
                        if (!silent) {
                            this._toast((validation.errors[0] && validation.errors[0].message) || 'Content is invalid for this context.', 'error');
                        }
                        return;
                    }
                }
                layoutJson = JSON.stringify(this._serializeLayoutFromBlocks(blocks));
            }
            /* SEO + excerpt: read from editor pageMeta (set via onPageMetaChange),
               fall back to DOM fields if the editor hasn't been mounted yet */
            var editorMeta = (this.editor && typeof this.editor.getPageMeta === 'function')
                ? this.editor.getPageMeta() : null;
            var seoTitle = (editorMeta && editorMeta.seo_title) || this._seoTitle || this._safeFieldTrim('#mwpb-seo-title');
            var seoDesc  = (editorMeta && editorMeta.seo_desc)  || this._seoDesc  || this._safeFieldTrim('#mwpb-seo-desc');
            var seoJson  = ( seoTitle || seoDesc ) ? JSON.stringify({ title: seoTitle, description: seoDesc }) : '';
            var excerpt  = (editorMeta && editorMeta.excerpt)   || this._excerpt  || this._safeFieldTrim('#mwpb-excerpt');
            var requestKey = String(this.currentKey || '').trim();
            var hasExistingEntity = requestKey !== '' || !!this.currentId;
            action = isPage ? (hasExistingEntity ? 'metis_website_page_save' : 'metis_website_page_create') : (hasExistingEntity ? 'metis_website_post_save' : 'metis_website_post_create');
            data = {
                action:       action,
                nonce:        this._ajaxConfigForAction(action).nonce,
                metis_action_nonce: this._ajaxConfigForAction(action).action_nonce || '',
                metis_csrf_action: this._ajaxConfigForAction(action).csrf_action || ('metis_ajax:' + action),
                title:        title,
                layout_json:  layoutJson,
                content_json: layoutJson,
                status:       requestedStatus,
                autosave:     autosave ? 1 : 0
            };
            if (seoJson) data.seo_meta_json = seoJson;
            if (!isPage && excerpt) data.excerpt = excerpt;
            if (requestedStatus === 'scheduled' && scheduleAt) {
                data.schedule_at = scheduleAt;
            }
            if (isPage && requestedStatus === 'published' && ((editorMeta && editorMeta.is_homepage) || (this._entityMeta && this._entityMeta.is_homepage) || $('#mwpb-set-homepage').is(':checked'))) {
                data.set_as_homepage = 1;
            }
            var slugVal = this._safeSlug(
                (editorMeta && editorMeta.slug) || this._safeFieldTrim('#mwpb-slug-input')
            );
            if (slugVal) data.slug = slugVal;
            if (requestKey !== '') {
                data.key = requestKey;
            }
        }

        if (isTemplate && !isNewsletterTemplate && this.currentId) data.id = this.currentId;
        var ajaxCfg = this._ajaxConfigForAction(action);
        data.nonce = ajaxCfg.nonce;
        data.metis_action_nonce = ajaxCfg.action_nonce || '';
        data.metis_csrf_action = ajaxCfg.csrf_action || ('metis_ajax:' + action);

        this._saveInFlight = true;
        $('#mwpb-save-draft-btn, #mwpb-publish-btn').prop('disabled', true);
        self._showSaveStatus(autosave ? 'Autosaving…' : 'Saving…');

        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: data,
            success: function(r) {
                self._saveInFlight = false;
                $('#mwpb-save-draft-btn, #mwpb-publish-btn').prop('disabled', false);
                if (r.success) {
                    if (!self._isSaveResponseConfirmed(r, action)) {
                        self._toast('Save did not return a confirmed entity response.', 'error');
                        self._showSaveStatus('Save failed', false, true);
                        return;
                    }
                    self._dirty = false;
                    var msg = requestedStatus === 'published' ? 'Published.' : (requestedStatus === 'scheduled' ? 'Scheduled.' : (autosave ? 'Autosaved' : 'Draft saved.'));
                    self._showSaveStatus(msg, true);
                    if (!autosave) self._toast(msg, 'success');
                    if (!self.currentId && r.data && r.data.page) self.currentId = r.data.page.id;
                    if (!self.currentId && r.data && r.data.post) self.currentId = r.data.post.id;
                    if (!self.currentId && r.data && r.data.template) self.currentId = r.data.template.id;
                    if (!self.currentId && r.data && r.data.campaign_id) self.currentId = r.data.campaign_id;
                    if (!self.currentId && r.data && r.data.template_id) self.currentId = r.data.template_id;
                    if (r.data && r.data.page && r.data.page.page_code) {
                        self.currentKey = String(r.data.page.page_code || self.currentKey || '');
                    }
                    if (r.data && r.data.post && r.data.post.post_code) {
                        self.currentKey = String(r.data.post.post_code || self.currentKey || '');
                    }
                    if (r.data && r.data.campaign_code) {
                        self.currentKey = String(r.data.campaign_code || self.currentKey || '');
                    }
                    if (r.data && r.data.template_code && self._isNewsletterTemplateContext()) {
                        self.currentKey = String(r.data.template_code || self.currentKey || '');
                    }
                    if (isTemplate && r.data && r.data.template) {
                        var savedTemplateKey = String(r.data.template.template_key || '');
                        if (savedTemplateKey) {
                            self.currentKey = savedTemplateKey;
                            self._templateMeta = self._templateMeta || {};
                            self._templateMeta.template_key = savedTemplateKey;
                            self._syncTemplateEditorUrl(savedTemplateKey);
                        }
                    }
                    if (r.data && r.data.page) {
                        self._entityMeta = self._entityMeta || {};
                        self._entityMeta.slug = String(r.data.page.slug || self._entityMeta.slug || '');
                        self._entityMeta.is_homepage = !!r.data.page.is_homepage;
                        self._entityMeta.status = String(r.data.page.status || requestedStatus || self._entityMeta.status || 'draft');
                        self._entityMeta.schedule_at = scheduleAt || self._entityMeta.schedule_at || '';
                    } else if (r.data && r.data.post) {
                        self._entityMeta = self._entityMeta || {};
                        self._entityMeta.slug = String(r.data.post.slug || self._entityMeta.slug || '');
                        self._entityMeta.is_homepage = false;
                        self._entityMeta.status = String(r.data.post.status || requestedStatus || self._entityMeta.status || 'draft');
                        self._entityMeta.schedule_at = scheduleAt || self._entityMeta.schedule_at || '';
                    } else if (self._entityMeta) {
                        self._entityMeta.status = requestedStatus;
                        self._entityMeta.schedule_at = scheduleAt || self._entityMeta.schedule_at || '';
                    }
                    if (self._hasSavedEntity() && !self._lockToken) {
                        self._startEditorLock();
                    }
                    if (!autosave && requestedStatus === 'published') {
                        if (self._isDedicatedEditorShell()) {
                            self._closeEditor();
                        } else {
                            self._closeEditor();
                            location.reload();
                        }
                    }
                } else {
                    var errMsg = (r.data && r.data.message) ? r.data.message : 'Save failed.';
                    self._toast(errMsg, 'error');
                    self._showSaveStatus('Save failed', false, true);
                }
            },
            error: function(xhr) {
                self._saveInFlight = false;
                $('#mwpb-save-draft-btn, #mwpb-publish-btn').prop('disabled', false);
                var serverMsg = '';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    serverMsg = String(xhr.responseJSON.data.message);
                } else if (xhr && xhr.responseText) {
                    serverMsg = String(xhr.responseText).slice(0, 200);
                }
                self._toast(serverMsg !== '' ? serverMsg : 'Network error - changes not saved.', 'error');
                self._showSaveStatus('Save failed', false, true);
            }
        });
    },

    _confirmClose: function() {
        var self = this;
        if (this._dirty) {
            self._confirm('Close without saving? Unsaved changes will be lost.', function() {
                self._closeEditor();
            });
        } else {
            this._closeEditor();
        }
    },

    _closeEditor: function() {
        this._stopEditorLock();
        this._releaseEditorLock();
        this._lockAdvisoryShown = false;
        if (this._autosaveTimer) {
            window.clearTimeout(this._autosaveTimer);
            this._autosaveTimer = null;
        }
        if (this._saveStateResetTimer) {
            window.clearTimeout(this._saveStateResetTimer);
            this._saveStateResetTimer = null;
        }
        $(window).off('beforeunload.mwpb');
        if (this._isDedicatedEditorShell()) {
            var self = this;
            window.setTimeout(function() {
                window.location.href = self._listUrlForContext(self.currentContext);
            }, 120);
            return;
        }
        $(document).off('keydown.mwpb');
        $('body').removeClass('mwpb-open');
        $('#mwpb-overlay').remove();
        this.editor    = null;
        this.currentId = null;
        this.currentKey = null;
        this.currentKind = '';
        this._templateStructure = null;
        this._templateMeta = null;
        this._templateActiveRegion = 'main';
        this._newsletterMeta = null;
        this._dirty    = false;
        this._entityMeta = null;
        this._lockToken = '';
        this._lockBlocked = false;
        window.MetisBlockPropertiesPanel = null;
    },

    _showSaveStatus: function(msg, success, error) {
        var $s = $('#mwpb-save-status');
        if ($s.length) {
            if (msg) $s.text(msg);
            $s.removeClass('mwpb-status-ok mwpb-status-err');
            if (success) $s.addClass('mwpb-status-ok');
            else if (error) $s.addClass('mwpb-status-err');
        }
        var normalized = String(msg || '').toLowerCase();
        if (success) {
            this._setSaveButtonState('success', msg || 'Saved');
            return;
        }
        if (error) {
            this._setSaveButtonState('error', msg || 'Save failed');
            return;
        }
        if (normalized.indexOf('saving') !== -1) {
            this._setSaveButtonState('saving', msg || 'Saving');
            return;
        }
        this._setSaveButtonState('idle', msg || 'Save draft');
    },

    _setSaveButtonState: function(state, label) {
        var $btn = $('#mwpb-save-draft-btn');
        if (!$btn.length) return;
        var next = String(state || 'idle').toLowerCase();
        if (next !== 'saving' && next !== 'success' && next !== 'error') next = 'idle';
        var text = String(label || '').trim();
        if (this._saveStateResetTimer) {
            window.clearTimeout(this._saveStateResetTimer);
            this._saveStateResetTimer = null;
        }
        $btn.attr('data-save-state', next);
        $btn.attr('title', text || 'Save draft');
        $btn.attr('aria-label', text || 'Save draft');
        $btn.find('.mwpb-save-icon-wrap').html(this._saveIconSvg(next));
        if (next === 'success' || next === 'error') {
            var self = this;
            this._saveStateResetTimer = window.setTimeout(function() {
                self._setSaveButtonState('idle', 'Save draft');
            }, 1400);
        }
    },

    _saveIconSvg: function(state) {
        var key = String(state || 'idle').toLowerCase();
        if (key === 'saving') {
            return '<svg class="mwpb-save-icon is-spinning" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.3" opacity=".25"></circle><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"></path></svg>';
        }
        if (key === 'success') {
            return '<svg class="mwpb-save-icon is-success" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="#16a34a" stroke-width="2"></circle><path d="M7.5 12.5l3 3 6-7" stroke="#16a34a" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
        }
        if (key === 'error') {
            return '<svg class="mwpb-save-icon is-error" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="#dc2626" stroke-width="2"></circle><path d="M8 8l8 8M16 8l-8 8" stroke="#dc2626" stroke-width="2.4" stroke-linecap="round"></path></svg>';
        }
        return '<svg class="mwpb-save-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 4h13l3 3v13H4z" stroke="currentColor" stroke-width="2"></path><path d="M8 4v6h8V4" stroke="currentColor" stroke-width="2"></path><path d="M8 20v-6h8v6" stroke="currentColor" stroke-width="2"></path></svg>';
    },

    _isSaveResponseConfirmed: function(response, action) {
        if (!response || !response.success) return false;
        var data = response.data && typeof response.data === 'object' ? response.data : {};
        var currentAction = String(action || '').toLowerCase();
        if (currentAction.indexOf('newsletter_save_campaign') !== -1) {
            return !!(data.campaign_id || data.campaign_code || this.currentId || this.currentKey);
        }
        if (currentAction.indexOf('newsletter_save_template') !== -1) {
            return !!(data.template_id || data.template_code || this.currentId || this.currentKey);
        }
        if (currentAction.indexOf('template_save') !== -1) {
            return !!(data.template && data.template.id && data.template.template_key);
        }
        if (currentAction.indexOf('page_') !== -1) {
            return !!((data.page && (data.page.id || data.page.page_code)) || this.currentId || this.currentKey);
        }
        if (currentAction.indexOf('post_') !== -1) {
            return !!((data.post && (data.post.id || data.post.post_code)) || this.currentId || this.currentKey);
        }
        return true;
    },

    _scheduleAutosave: function() {
        if (this._lockBlocked) return;
        if (this._autosaveTimer) {
            window.clearTimeout(this._autosaveTimer);
        }
        var self = this;
        this._autosaveTimer = window.setTimeout(function() {
            self._autosaveTimer = null;
            if (!self._dirty) return;
            self._save(null, { autosave: true, silent: true });
        }, this._autosaveDelayMs);
    },

    _toDateTimeLocal: function(raw) {
        var value = String(raw || '').trim();
        if (!value) return '';
        var normalized = value.replace(' ', 'T');
        var date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return '';
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        var h = String(date.getHours()).padStart(2, '0');
        var i = String(date.getMinutes()).padStart(2, '0');
        return y + '-' + m + '-' + d + 'T' + h + ':' + i;
    },

    _fromDateTimeLocal: function(raw) {
        var value = String(raw || '').trim();
        if (!value) return '';
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) {
            return value.replace('T', ' ') + ':00';
        }
        return value;
    },

    _startEditorLock: function() {
        if (this._isNewsletterContext()) return;
        if (!this._hasSavedEntity() || this._lockBlocked) return;
        var self = this;
        this._lockRequest('acquire', function(ok) {
            if (!ok) return;
            if (self._lockHeartbeatTimer) {
                window.clearInterval(self._lockHeartbeatTimer);
            }
            self._lockHeartbeatTimer = window.setInterval(function() {
                self._lockRequest('refresh');
            }, 30000);
        });
    },

    _stopEditorLock: function() {
        if (this._lockHeartbeatTimer) {
            window.clearInterval(this._lockHeartbeatTimer);
            this._lockHeartbeatTimer = null;
        }
    },

    _releaseEditorLock: function() {
        if (this._isNewsletterContext()) return;
        if (!this._hasSavedEntity()) return;
        this._lockRequest('release');
    },

    _setLockBlocked: function(blocked, message) {
        this._lockBlocked = !!blocked;
        var disabled = this._lockBlocked;
        $('#mwpb-title-input, #mwpb-save-draft-btn, #mwpb-publish-btn, #mwpb-undo-btn, #mwpb-redo-btn')
            .prop('disabled', disabled);
        $('#mwpb-preview-btn').prop('disabled', disabled);
        $('#mwpb-editor-mount, #mwpb-props-panel').css('pointer-events', disabled ? 'none' : '');
        if (disabled) {
            this._showSaveStatus('Locked', false, true);
            if (message) this._toast(message, 'error');
        }
    },

    _lockRequest: function(intent, done, opts) {
        if (!this._hasSavedEntity()) {
            if (typeof done === 'function') done(false);
            return;
        }
        opts = (opts && typeof opts === 'object') ? opts : {};
        var self = this;
        var action = 'metis_website_editor_lock';
        var cfg = this._ajaxConfigForAction(action);
        var useWebsiteCsrfFallback = !!opts.useWebsiteCsrfFallback;
        var requestKey = this._entityRequestKey();
        var lockActionNonce = useWebsiteCsrfFallback
            ? (cfg.nonce || cfg.action_nonce || '')
            : (cfg.action_nonce || '');
        var lockCsrfAction = useWebsiteCsrfFallback
            ? 'metis_website'
            : (cfg.csrf_action || ('metis_ajax:' + action));
        $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: cfg.nonce,
                metis_action_nonce: lockActionNonce,
                metis_csrf_action: lockCsrfAction,
                context: self.currentContext || 'website',
                key: requestKey,
                id: requestKey === '' ? self.currentId : '',
                intent: intent
            },
            success: function(r) {
                var code = (r && r.data && r.data.code) ? String(r.data.code) : '';
                var lock = (r && r.success && r.data && r.data.lock) ? r.data.lock : null;
                if (r && r.success) {
                    if (lock && lock.token) {
                        self._lockToken = String(lock.token);
                    }
                    if (intent !== 'release') {
                        self._setLockBlocked(false);
                    }
                    if (typeof done === 'function') done(true);
                    return;
                }
                if (!useWebsiteCsrfFallback && code === 'invalid_nonce') {
                    self._lockRequest(intent, done, { useWebsiteCsrfFallback: true });
                    return;
                }
                if (code === 'editor_locked') {
                    // Advisory-only lock mode: keep editing/saving available to avoid dead-end sessions.
                    self._stopEditorLock();
                    self._setLockBlocked(false);
                    self._showSaveStatus('Unlocked');
                    if (!self._lockAdvisoryShown) {
                        self._lockAdvisoryShown = true;
                        self._toast('Another session is editing this item. Continuing in advisory mode.', 'warning');
                    }
                    if (typeof done === 'function') done(false);
                    return;
                }
                if (intent === 'refresh' && code === 'invalid_nonce') {
                    self._stopEditorLock();
                    self._setLockBlocked(false);
                    self._showSaveStatus('Lock stale');
                    self._toast('Editor lock heartbeat expired. Continue editing and save. Lock checks will resume after reload.', 'warning');
                    if (typeof done === 'function') done(false);
                    return;
                }
                var msg = (r && r.data && r.data.message) ? String(r.data.message) : 'Unable to acquire editor lock.';
                self._setLockBlocked(true, msg);
                if (typeof done === 'function') done(false);
            },
            error: function(xhr) {
                if (intent === 'release') {
                    if (typeof done === 'function') done(false);
                    return;
                }
                var code = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.code)
                    ? String(xhr.responseJSON.data.code)
                    : '';
                if (!useWebsiteCsrfFallback && xhr && Number(xhr.status || 0) === 403 && code === 'invalid_nonce') {
                    self._lockRequest(intent, done, { useWebsiteCsrfFallback: true });
                    return;
                }
                if (xhr && Number(xhr.status || 0) === 423) {
                    self._stopEditorLock();
                    self._setLockBlocked(false);
                    self._showSaveStatus('Unlocked');
                    if (!self._lockAdvisoryShown) {
                        self._lockAdvisoryShown = true;
                        self._toast('Another session is editing this item. Continuing in advisory mode.', 'warning');
                    }
                    if (typeof done === 'function') done(false);
                    return;
                }
                if (intent === 'refresh' && xhr && Number(xhr.status || 0) === 403 && code === 'invalid_nonce') {
                    self._stopEditorLock();
                    self._setLockBlocked(false);
                    self._showSaveStatus('Lock stale');
                    self._toast('Editor lock heartbeat expired. Continue editing and save. Lock checks will resume after reload.', 'warning');
                    if (typeof done === 'function') done(false);
                    return;
                }
                var msg = 'Unable to acquire editor lock.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = String(xhr.responseJSON.data.message);
                }
                self._setLockBlocked(true, msg);
                if (typeof done === 'function') done(false);
            }
        });
    },

    _openRevisionHistory: function() {
        if (this._isNewsletterContext()) {
            this._toast('Revision history is not enabled for newsletter editor yet.', 'warning');
            return;
        }
        if (!this._hasSavedEntity()) {
            this._toast('Save once before opening history.', 'warning');
            return;
        }
        var self = this;
        var action = 'metis_website_editor_revisions_list';
        var cfg = this._ajaxConfigForAction(action);
        var requestKey = this._entityRequestKey();
        $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: cfg.nonce,
                metis_action_nonce: cfg.action_nonce || '',
                metis_csrf_action: cfg.csrf_action || ('metis_ajax:' + action),
                context: self.currentContext || 'website',
                key: requestKey,
                id: requestKey === '' ? self.currentId : '',
                limit: 40
            },
            success: function(r) {
                if (!r || !r.success) {
                    self._toast((r && r.data && r.data.message) ? r.data.message : 'Failed to load history.', 'error');
                    return;
                }
                self._renderRevisionHistoryModal((r.data && r.data.revisions) ? r.data.revisions : []);
            },
            error: function() {
                self._toast('Failed to load history.', 'error');
            }
        });
    },

    _renderRevisionHistoryModal: function(revisions) {
        var self = this;
        $('#mwpb-revision-modal').remove();
        var rows = Array.isArray(revisions) ? revisions : [];
        var body = rows.length ? rows.map(function(item) {
            var id = Number(item.id || 0);
            var when = String(item.created_at || '');
            var note = String(item.note || '');
            var status = String(item.status || '');
            return '<div class="mwpb-revision-row" data-revision-id="' + id + '">'
                + '<div class="mwpb-revision-meta"><strong>#' + id + '</strong> <span>' + self._esc(when) + '</span> <span>' + self._esc(status || 'draft') + '</span></div>'
                + '<div class="mwpb-revision-note">' + self._esc(note || 'Revision') + '</div>'
                + '<div class="mwpb-revision-actions"><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs" data-revision-restore="' + id + '">Restore</button></div>'
                + '</div>';
        }).join('') : '<div class="mwpb-revision-empty">No revisions yet.</div>';
        var html = ''
            + '<div id="mwpb-revision-modal" class="mwpb-revision-modal" role="dialog" aria-modal="true" aria-label="Version history">'
            + '  <div class="mwpb-revision-card">'
            + '    <div class="mwpb-revision-head"><strong>Version History</strong><button type="button" class="mwpb-icon-btn" data-revision-close="1">✕</button></div>'
            + '    <div class="mwpb-revision-body">' + body + '</div>'
            + '  </div>'
            + '</div>';
        $('body').append(html);
        $('#mwpb-revision-modal [data-revision-close]').on('click', function() {
            $('#mwpb-revision-modal').remove();
        });
        $('#mwpb-revision-modal [data-revision-restore]').on('click', function() {
            var revisionId = parseInt(String($(this).data('revisionRestore') || ''), 10);
            if (!Number.isFinite(revisionId) || revisionId < 1) return;
            self._confirm('Restore this revision? Unsaved changes will be overwritten.', function() {
                self._restoreRevision(revisionId);
            });
        });
    },

    _restoreRevision: function(revisionId) {
        if (!this._hasSavedEntity() || !revisionId) return;
        var self = this;
        var action = 'metis_website_editor_revision_restore';
        var cfg = this._ajaxConfigForAction(action);
        var requestKey = this._entityRequestKey();
        self._showSaveStatus('Restoring…');
        $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: cfg.nonce,
                metis_action_nonce: cfg.action_nonce || '',
                metis_csrf_action: cfg.csrf_action || ('metis_ajax:' + action),
                context: self.currentContext || 'website',
                key: requestKey,
                id: self.currentId || '',
                revision_id: revisionId
            },
            success: function(r) {
                if (!r || !r.success) {
                    self._toast((r && r.data && r.data.message) ? r.data.message : 'Restore failed.', 'error');
                    self._showSaveStatus('Restore failed', false, true);
                    return;
                }
                self._toast('Revision restored.', 'success');
                self._dirty = false;
                self._refreshEditorAfterRestore(r.data || {});
            },
            error: function() {
                self._toast('Restore failed.', 'error');
            }
        });
    },

    _refreshEditorAfterRestore: function(payload) {
        var restored = payload && typeof payload === 'object' ? payload : {};
        var context = String(this.currentContext || 'website');
        if (context === 'template') {
            var restoredTemplate = restored.template && typeof restored.template === 'object' ? restored.template : null;
            if (restoredTemplate) {
                if (restoredTemplate.id) {
                    this.currentId = parseInt(String(restoredTemplate.id), 10) || this.currentId;
                }
                var restoredKey = String(restoredTemplate.template_key || '');
                if (restoredKey) {
                    this.currentKey = restoredKey;
                    this._templateMeta = this._templateMeta || {};
                    this._templateMeta.template_key = restoredKey;
                    this._syncTemplateEditorUrl(restoredKey);
                }
                this._templateStructure = this._normalizeTemplateStructureFromEntity(restoredTemplate);
                var restoredKind = (this._templateStructure.layout && this._templateStructure.layout.template_kind) || '';
                if (restoredKind !== 'header' && restoredKind !== 'footer' && restoredKind !== 'body') {
                    restoredKind = (restoredTemplate.template_type === 'header' || restoredTemplate.template_type === 'footer') ? restoredTemplate.template_type : 'body';
                }
                this._templateMeta = this._templateMeta || {};
                this._templateMeta.kind = restoredKind;
                this._templateMeta.body_for = restoredTemplate.template_type === 'post' ? 'post' : 'page';
                this._templateMeta.is_default = restoredTemplate.is_default ? 1 : 0;
                this._templateMeta.suppress_home_header = this._templateStructure.layout && this._templateStructure.layout.suppress_header_on_homepage ? 1 : 0;
                this._templateActiveRegion = restoredKind === 'header' ? 'header' : (restoredKind === 'footer' ? 'footer' : (this._templateActiveRegion || 'main'));
                if (this._templateActiveRegion !== 'header' && this._templateActiveRegion !== 'main' && this._templateActiveRegion !== 'sidebar' && this._templateActiveRegion !== 'footer') {
                    this._templateActiveRegion = 'main';
                }
                if (this.editor && typeof this.editor.setBlocks === 'function') {
                    this.editor.setBlocks(this._templateRegionBlocks(this._templateActiveRegion));
                }
                if ($('#mwpb-title-input').length) {
                    $('#mwpb-title-input').val(String(restoredTemplate.name || ''));
                }
                this._entityMeta = this._entityMeta || {};
                this._entityMeta.status = String(restoredTemplate.status || this._entityMeta.status || 'draft');
                this._renderTemplateSettingsPane();
                this._showSaveStatus('Revision restored.', true);
                return;
            }
            var templateKey = restoredTemplate && restoredTemplate.template_key
                ? String(restoredTemplate.template_key)
                : (this._templateMeta && this._templateMeta.template_key
                ? String(this._templateMeta.template_key)
                : String(this.currentKey || ''));
            if (templateKey) {
                this.currentKey = templateKey;
                this._templateMeta = this._templateMeta || {};
                this._templateMeta.template_key = templateKey;
                this._syncTemplateEditorUrl(templateKey);
                this.openTemplateEditByKey(templateKey);
            } else if (this.currentId) {
                this.openTemplateEditById(this.currentId);
            } else {
                this._toast('Template restored. Reload the editor state manually.', 'warning');
            }
            return;
        }
        if (context === 'newsletter') {
            var campaignCode = String(this.currentKey || '');
            if (campaignCode) {
                this.openNewsletterCampaignEditByKey(campaignCode);
            } else {
                this._toast('Campaign restored. Reload the editor state manually.', 'warning');
            }
            return;
        }
        if (context === 'newsletter_template') {
            var newsletterTemplateCode = String(this.currentKey || '');
            if (newsletterTemplateCode) {
                this.openNewsletterTemplateEditByKey(newsletterTemplateCode);
            } else {
                this._toast('Template restored. Reload the editor state manually.', 'warning');
            }
            return;
        }
        var entityRef = String(this.currentKey || '').trim();
        if (!entityRef && this.currentId) {
            entityRef = String(this.currentId);
        }
        if (!entityRef) {
            this._toast('Revision restored. Reopen the editor to load restored content.', 'warning');
            return;
        }
        this.openEdit(entityRef, context);
    },

    _syncTemplateEditorUrl: function(templateKey) {
        if (!this._isDedicatedEditorShell()) return;
        var key = String(templateKey || '').trim();
        if (!key || !window.history || typeof window.history.replaceState !== 'function') return;
        var next = this._editorUrlForTemplateKey(key);
        var current = String(window.location.pathname || '');
        if (current === next) return;
        try {
            window.history.replaceState(window.history.state, '', next);
        } catch (_e) {}
    },

    _parseBlocks: function(raw) {
        if (!raw) return [];
        try {
            var parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (Array.isArray(parsed)) return parsed;
            if (parsed.sections && Array.isArray(parsed.sections)) {
                var structured = this._flattenStructuredSectionsToBlocks(parsed.sections);
                if (structured.length) return structured;
                var flattened = [];
                for (var i = 0; i < parsed.sections.length; i++) {
                    var section = parsed.sections[i];
                    if (!section || typeof section !== 'object') continue;
                    var sectionBlocks = Array.isArray(section.blocks) ? section.blocks : [];
                    if (!sectionBlocks.length) continue;

                    var hasSectionMeta = !!section.type
                        || (section.style && typeof section.style === 'object' && Object.keys(section.style).length > 0)
                        || (typeof section.max_width === 'string' && section.max_width !== '')
                        || (typeof section.align === 'string' && section.align !== '');

                    if (hasSectionMeta) {
                        flattened.push({
                            id: section.id || ('section_import_' + i),
                            type: 'container',
                            data: {
                                blocks: sectionBlocks,
                                max_width: section.max_width || '100%',
                                align: section.align || 'full'
                            },
                            style: (section.style && typeof section.style === 'object') ? section.style : {}
                        });
                    } else {
                        flattened = flattened.concat(sectionBlocks);
                    }
                }
                if (flattened.length) return flattened;
            }
            if (parsed.blocks) return parsed.blocks;
        } catch(e) {}
        return [];
    },

    _serializeLayoutFromBlocks: function(blocks) {
        var list = Array.isArray(blocks) ? blocks : [];
        return {
            version: 2,
            sections: [
                {
                    id: 'section_main',
                    columns: [
                        {
                            id: 'section_main_col_0',
                            width: 1,
                            modules: list
                        }
                    ],
                    sections: [],
                    settings: {}
                }
            ]
        };
    },

    _flattenStructuredSectionsToBlocks: function(sections) {
        if (!Array.isArray(sections) || !sections.length) return [];
        var out = [];
        for (var i = 0; i < sections.length; i++) {
            var mapped = this._mapStructuredSectionToEditorBlocks(sections[i], i);
            if (Array.isArray(mapped) && mapped.length) {
                out = out.concat(mapped);
            }
        }
        return out;
    },

    _mapStructuredSectionToEditorBlocks: function(section, index) {
        if (!section || typeof section !== 'object') return [];
        if (!Array.isArray(section.columns)) return [];

        var sectionId = String(section.id || ('section_import_' + index));
        var settings = (section.settings && typeof section.settings === 'object') ? section.settings : {};
        var sectionStyle = (settings.style && typeof settings.style === 'object') ? settings.style : {};
        var maxWidth = (typeof settings.max_width === 'string' && settings.max_width.trim()) ? settings.max_width : '100%';
        var align = (typeof settings.align === 'string' && settings.align.trim()) ? settings.align : 'full';

        var colModules = [];
        var ratios = [];
        for (var c = 0; c < section.columns.length; c++) {
            var column = section.columns[c];
            if (!column || typeof column !== 'object') continue;
            var modules = Array.isArray(column.modules)
                ? column.modules.filter(function(m) { return !!(m && typeof m === 'object' && m.type); })
                : [];
            colModules.push(modules);
            var width = parseFloat(column.width);
            if (!isFinite(width) || width <= 0) width = 1;
            ratios.push(width);
        }

        if (!colModules.length) return [];

        var hasSectionMeta = Object.keys(sectionStyle).length > 0
            || (typeof maxWidth === 'string' && maxWidth !== '')
            || (typeof align === 'string' && align !== '')
            || (Array.isArray(section.sections) && section.sections.length > 0)
            || colModules.length > 1;

        var contentBlocks = [];
        if (colModules.length > 1) {
            contentBlocks.push({
                id: sectionId + '_grid',
                type: 'grid',
                data: {
                    columns: colModules.length,
                    ratios: ratios.length ? ratios : new Array(colModules.length).fill(1),
                    gap: '24px',
                    col_blocks: colModules
                },
                style: {}
            });
        } else {
            contentBlocks = contentBlocks.concat(colModules[0]);
        }

        if (Array.isArray(section.sections) && section.sections.length) {
            contentBlocks = contentBlocks.concat(this._flattenStructuredSectionsToBlocks(section.sections));
        }

        if (!hasSectionMeta) return contentBlocks;

        return [{
            id: sectionId,
            type: 'container',
            data: {
                blocks: contentBlocks,
                max_width: maxWidth,
                align: align
            },
            style: sectionStyle
        }];
    },

    _loadBlockRegistry: function(done) {
        if (this._blockRegistry) {
            if (typeof done === 'function') done(this._blockRegistry);
            return;
        }
        var self = this;
        var ajaxCfg = this._ajaxConfigForAction('metis_website_block_registry');
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: 'metis_website_block_registry',
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || 'metis_ajax:metis_website_block_registry',
                context: self._editorRegistryContext(),
                render_mode: self._isNewsletterContext() ? 'email_safe' : 'standard'
            },
            success: function(r) {
                var registry = (r && r.success && r.data && r.data.registry) ? r.data.registry : null;
                var profile = (r && r.success && r.data && r.data.profile) ? r.data.profile : null;
                self._blockRegistry = registry || null;
                self._contextProfile = (profile && typeof profile === 'object') ? profile : null;
                if (typeof done === 'function') done(self._blockRegistry);
            },
            error: function() {
                if (typeof done === 'function') done(null);
            }
        });
    },

    _loadReusableBlocks: function(done, forceReload) {
        if (!forceReload && Array.isArray(this._reusableItems)) {
            if (typeof done === 'function') done(this._reusableItems);
            return;
        }
        if (this._reusableLoading) {
            if (typeof done === 'function') done(Array.isArray(this._reusableItems) ? this._reusableItems : []);
            return;
        }
        this._reusableLoading = true;
        var self = this;
        var action = 'metis_website_reusable_blocks_list';
        var ajaxCfg = this._ajaxConfigForAction(action);
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || ('metis_ajax:' + action),
                context: self._editorRegistryContext(),
                render_mode: self._isNewsletterContext() ? 'email_safe' : 'standard'
            },
            success: function(r) {
                self._reusableLoading = false;
                var items = (r && r.success && r.data && Array.isArray(r.data.items)) ? r.data.items : [];
                self._reusableItems = items;
                if (typeof done === 'function') done(items);
            },
            error: function() {
                self._reusableLoading = false;
                self._reusableItems = [];
                if (typeof done === 'function') done([]);
            }
        });
    },

    _findReusableByCode: function(code) {
        var target = String(code || '').trim().toUpperCase();
        if (!target || !Array.isArray(this._reusableItems)) return null;
        for (var i = 0; i < this._reusableItems.length; i++) {
            var item = this._reusableItems[i] || {};
            if (String(item.block_code || '').trim().toUpperCase() === target) {
                return item;
            }
        }
        return null;
    },

    _stripReusableMeta: function(block) {
        if (!block || typeof block !== 'object') return block;
        var clone = JSON.parse(JSON.stringify(block));
        if (clone.meta && typeof clone.meta === 'object' && clone.meta.reusable) {
            delete clone.meta.reusable;
            if (!Object.keys(clone.meta).length) delete clone.meta;
        }
        return clone;
    },

    _promptSaveReusableBlock: function(path, block) {
        var self = this;
        var source = block && typeof block === 'object' ? block : null;
        /* _getBlockByPath is a legacy flat-model method; not available in BB editor */
        if (!source) return;
        var defaultName = self._friendlyReusableName(source);
        var ask = function(nameValue) {
            var cleanName = String(nameValue || '').trim();
            if (!cleanName) {
                self._toast('Reusable block name is required.', 'warning');
                return;
            }
            self._saveReusableBlock(source, cleanName, '', false);
        };
        if (typeof window.metis_prompt === 'function') {
            window.metis_prompt('Reusable block name', ask, defaultName);
            return;
        }
        var typed = window.prompt('Reusable block name', defaultName);
        if (typed === null) return;
        ask(typed);
    },

    _friendlyReusableName: function(block) {
        var type = String((block && block.type) || 'block').replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
        if (!type) type = 'Block';
        return type.replace(/\b\w/g, function(c) { return c.toUpperCase(); });
    },

    _saveReusableBlock: function(block, name, blockCode, fromLinkedUpdate) {
        var self = this;
        var action = 'metis_website_reusable_block_save';
        var ajaxCfg = this._ajaxConfigForAction(action);
        var payloadBlock = this._stripReusableMeta(block);
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || ('metis_ajax:' + action),
                name: String(name || '').trim(),
                block_code: String(blockCode || '').trim(),
                category: String((payloadBlock && payloadBlock.type) || 'custom'),
                is_global: 1,
                context: self._editorRegistryContext(),
                render_mode: self._isNewsletterContext() ? 'email_safe' : 'standard',
                block_json: JSON.stringify(payloadBlock || {})
            },
            success: function(r) {
                if (!r || !r.success || !r.data || !r.data.item) {
                    self._toast((r && r.data && r.data.message) ? r.data.message : 'Failed to save reusable block.', 'error');
                    return;
                }
                var item = r.data.item || {};
                self._upsertReusableItem(item);
                if (self.editor && typeof self.editor.setReusableLibrary === 'function') {
                    self.editor.setReusableLibrary(self._reusableItems || []);
                }
                if (fromLinkedUpdate) {
                    self._applyLinkedReusableUpdateToCanvas(item);
                    self._toast('Linked reusable updated globally.', 'success');
                } else {
                    self._toast('Reusable block saved.', 'success');
                }
            },
            error: function(xhr) {
                var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                    ? String(xhr.responseJSON.data.message)
                    : 'Failed to save reusable block.';
                self._toast(msg, 'error');
            }
        });
    },

    _insertReusableBlock: function(code, mode, listPath, index) {
        var self = this;
        var normalizedCode = String(code || '').trim().toUpperCase();
        if (!normalizedCode) return;
        var insertFromItem = function(item) {
            if (!item || !item.block || !self.editor || typeof self.editor.insertReusableBlock !== 'function') return;
            var clone = JSON.parse(JSON.stringify(item.block));
            var useLinked = String(mode || 'linked') === 'linked';
            clone.meta = clone.meta && typeof clone.meta === 'object' ? clone.meta : {};
            if (useLinked) {
                clone.meta.reusable = {
                    block_code: String(item.block_code || ''),
                    name: String(item.name || ''),
                    linked: true
                };
            } else if (clone.meta.reusable) {
                delete clone.meta.reusable;
            }
            var targetList = typeof listPath === 'string' ? listPath : '';
            var targetIndex = (typeof index === 'number' && Number.isFinite(index)) ? index : null;
            self.editor.insertReusableBlock(clone, targetList, targetIndex);
            self._dirty = true;
            self._showSaveStatus('Unsaved changes');
            self._scheduleAutosave();
        };

        var existing = this._findReusableByCode(normalizedCode);
        if (existing) {
            insertFromItem(existing);
            return;
        }

        var action = 'metis_website_reusable_block_get';
        var ajaxCfg = this._ajaxConfigForAction(action);
        $.ajax({
            url: ajaxCfg.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: ajaxCfg.nonce,
                metis_action_nonce: ajaxCfg.action_nonce || '',
                metis_csrf_action: ajaxCfg.csrf_action || ('metis_ajax:' + action),
                block_code: normalizedCode
            },
            success: function(r) {
                if (!r || !r.success || !r.data || !r.data.item) {
                    self._toast((r && r.data && r.data.message) ? r.data.message : 'Reusable block not found.', 'error');
                    return;
                }
                var item = r.data.item || {};
                if (item.block_json && !item.block) {
                    try { item.block = JSON.parse(item.block_json); } catch (_e) { item.block = null; }
                }
                self._upsertReusableItem(item);
                insertFromItem(item);
            },
            error: function() {
                self._toast('Failed to load reusable block.', 'error');
            }
        });
    },

    _updateLinkedReusableBlock: function(path, block, reusableMeta) {
        var self = this;
        if (!block || !reusableMeta) return;
        var code = String(reusableMeta.block_code || '').trim();
        if (!code) return;
        self._confirm('Update this reusable block globally for all linked instances?', function() {
            var name = String(reusableMeta.name || self._friendlyReusableName(block));
            self._saveReusableBlock(block, name, code, true);
        });
    },

    _applyLinkedReusableUpdateToCanvas: function(item) {
        if (!item || !item.block || !this.editor || typeof this.editor.getBlocks !== 'function' || typeof this.editor.setBlocks !== 'function') {
            return;
        }
        var code = String(item.block_code || '').trim().toUpperCase();
        if (!code) return;
        var updated = JSON.parse(JSON.stringify(item.block));
        var changed = false;
        var walk = function(nodes) {
            if (!Array.isArray(nodes)) return;
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                if (!node || typeof node !== 'object') continue;
                var reusable = node.meta && node.meta.reusable && typeof node.meta.reusable === 'object' ? node.meta.reusable : null;
                if (reusable && String(reusable.block_code || '').trim().toUpperCase() === code && reusable.linked) {
                    var keepId = node.id;
                    var keepMeta = {
                        block_code: String(item.block_code || reusable.block_code || ''),
                        name: String(item.name || reusable.name || ''),
                        linked: true
                    };
                    var merged = JSON.parse(JSON.stringify(updated));
                    merged.id = keepId;
                    merged.meta = merged.meta && typeof merged.meta === 'object' ? merged.meta : {};
                    merged.meta.reusable = keepMeta;
                    nodes[i] = merged;
                    changed = true;
                    continue;
                }
                if (node.data && Array.isArray(node.data.blocks)) {
                    walk(node.data.blocks);
                }
                if (node.data && Array.isArray(node.data.col_blocks)) {
                    for (var c = 0; c < node.data.col_blocks.length; c++) {
                        if (Array.isArray(node.data.col_blocks[c])) {
                            walk(node.data.col_blocks[c]);
                        }
                    }
                }
            }
        };
        var blocks = this.editor.getBlocks();
        walk(blocks);
        if (changed) {
            this.editor.setBlocks(blocks);
            this._dirty = true;
            this._showSaveStatus('Unsaved changes');
            this._scheduleAutosave();
        }
    },

    _upsertReusableItem: function(item) {
        if (!item || typeof item !== 'object') return;
        var code = String(item.block_code || '').trim().toUpperCase();
        if (!code) return;
        this._reusableItems = Array.isArray(this._reusableItems) ? this._reusableItems : [];
        var replaced = false;
        for (var i = 0; i < this._reusableItems.length; i++) {
            var currentCode = String((this._reusableItems[i] && this._reusableItems[i].block_code) || '').trim().toUpperCase();
            if (currentCode === code) {
                this._reusableItems[i] = item;
                replaced = true;
                break;
            }
        }
        if (!replaced) {
            this._reusableItems.unshift(item);
        }
    },

    _isNewsletterCampaignContext: function() {
        var ctx = String(this.currentContext || '').toLowerCase();
        return ctx === 'newsletter' || ctx === 'newsletter_campaign';
    },

    _isNewsletterTemplateContext: function() {
        return String(this.currentContext || '').toLowerCase() === 'newsletter_template';
    },

    _isNewsletterContext: function() {
        return this._isNewsletterCampaignContext() || this._isNewsletterTemplateContext() || String(this.currentContext || '').toLowerCase() === 'email';
    },

    _editorRegistryContext: function() {
        return this._isNewsletterContext() ? 'newsletter' : String(this.currentContext || 'website');
    },

    _newsletterAdapterAssetUrl: function() {
        var path = String(window.location.pathname || '');
        var idx = path.toLowerCase().indexOf('/admin/');
        var base = idx > -1 ? path.slice(0, idx) : '';
        return base.replace(/\/+$/, '') + '/assets/modules/newsletter/newsletter-adapter.js';
    },

    _ensureNewsletterAdapter: function(done) {
        if (!this._isNewsletterContext()) {
            if (typeof done === 'function') done();
            return;
        }
        if (window.MetisNewsletterAdapter && typeof window.MetisNewsletterAdapter === 'object') {
            if (typeof done === 'function') done();
            return;
        }
        if (this._newsletterAdapterLoading) {
            var self = this;
            var attempts = 0;
            var poll = window.setInterval(function() {
                attempts += 1;
                if (window.MetisNewsletterAdapter || attempts > 60) {
                    window.clearInterval(poll);
                    self._newsletterAdapterLoading = false;
                    if (typeof done === 'function') done();
                }
            }, 100);
            return;
        }
        this._newsletterAdapterLoading = true;
        var script = document.createElement('script');
        var selfBuilder = this;
        script.src = this._newsletterAdapterAssetUrl();
        script.async = false;
        script.onload = function() {
            selfBuilder._newsletterAdapterLoading = false;
            if (typeof done === 'function') done();
        };
        script.onerror = function() {
            selfBuilder._newsletterAdapterLoading = false;
            if (typeof done === 'function') done();
        };
        document.head.appendChild(script);
    },

    _newsletterDocToBlocks: function(rawDoc) {
        var adapter = window.MetisNewsletterAdapter || null;
        if (adapter && typeof adapter.newsletterDocToMubeBlocks === 'function') {
            return adapter.newsletterDocToMubeBlocks(rawDoc);
        }
        return this._parseBlocks(rawDoc);
    },

    _newsletterBlocksToDoc: function(blocks) {
        var adapter = window.MetisNewsletterAdapter || null;
        if (adapter && typeof adapter.mubeBlocksToNewsletterDoc === 'function') {
            return adapter.mubeBlocksToNewsletterDoc(Array.isArray(blocks) ? blocks : []);
        }
        return { version: 1, settings: {}, blocks: [] };
    },

    _safeFieldTrim: function(selector) {
        var $field = $(selector);
        if (!$field.length) return '';
        var value = $field.val();
        if (value === null || value === undefined) return '';
        return String(value).trim();
    },

    _resolveCreatorLabel: function(entity) {
        var src = entity && typeof entity === 'object' ? entity : {};
        var name = String(src.created_by_name || src.author_name || src.owner_name || src.created_by || '').trim();
        var email = String(src.created_by_email || src.author_email || src.owner_email || '').trim();
        if (name && email) return name + ' (' + email + ')';
        if (name) return name;
        if (email) return email;
        return 'Unknown';
    },

    _ajaxConfigForAction: function(action) {
        var website = window.metisWebsiteAjax || {};
        var core = window.metisAjax || {};
        var websiteNonces = (website.action_nonces && typeof website.action_nonces === 'object') ? website.action_nonces : {};
        var coreNonces = (core.action_nonces && typeof core.action_nonces === 'object') ? core.action_nonces : {};
        var actionNonce = websiteNonces[action] || coreNonces[action] || '';
        var moduleNonce = website.nonce || core.nonce || '';
        var nonce = moduleNonce || actionNonce;
        var ajaxUrl = website.ajax_url || core.ajax_url || '/api/ajax';
        return {
            ajax_url: ajaxUrl,
            nonce: nonce,
            action_nonce: actionNonce,
            csrf_action: actionNonce ? ('metis_ajax:' + String(action || '')) : 'metis_website'
        };
    },

    _normalizedMenuOptions: function() {
        var rows = Array.isArray(this._menuOptions) ? this._menuOptions : [];
        var out = [{ value: '', label: 'Select menu…' }];
        var seen = {};
        var coerceLabel = function(item, fallback) {
            if (!item || typeof item !== 'object') return fallback;
            var direct = item.name;
            if (typeof direct === 'string' && direct.trim()) return direct.trim();
            if (direct && typeof direct === 'object') {
                var nested = String(direct.label || direct.name || direct.title || direct.rendered || direct.text || '').trim();
                if (nested) return nested;
            }
            var fallbackDirect = String(item.title || item.label || '').trim();
            if (fallbackDirect) return fallbackDirect;
            return fallback;
        };
        for (var i = 0; i < rows.length; i += 1) {
            var item = rows[i] && typeof rows[i] === 'object' ? rows[i] : null;
            if (!item) continue;
            var value = item.id != null ? String(item.id) : String(item.menu_id || item.value || '');
            if (!value) continue;
            if (seen[value]) continue;
            seen[value] = true;
            var label = coerceLabel(item, 'Menu ' + value);
            out.push({ value: value, label: label });
        }
        return out;
    },

    _loadMenuOptions: function(done) {
        if (Array.isArray(this._menuOptions) && this._menuOptions.length) {
            if (typeof done === 'function') done(this._normalizedMenuOptions());
            return;
        }
        if (this._menuOptionsLoading) {
            if (typeof done === 'function') window.setTimeout(function() { done(); }, 120);
            return;
        }
        this._menuOptionsLoading = true;
        var self = this;
        var action = 'metis_website_menus_list';
        var cfg = this._ajaxConfigForAction(action);
        var send = function(payload, retried) {
            $.ajax({
                url: cfg.ajax_url,
                type: 'POST',
                timeout: 12000,
                data: payload,
                success: function(r) {
                    self._menuOptionsLoading = false;
                    var menus = (r && r.success && r.data && Array.isArray(r.data.menus)) ? r.data.menus : [];
                    self._menuOptions = menus;
                    if (typeof done === 'function') done(self._normalizedMenuOptions());
                },
                error: function(xhr) {
                    var fallbackNonce = (window.metisAjax && window.metisAjax.nonce) ? String(window.metisAjax.nonce) : '';
                    var canRetry = !retried && fallbackNonce && fallbackNonce !== String(payload.nonce || '');
                    if (canRetry && xhr && Number(xhr.status || 0) === 403) {
                        send({
                            action: action,
                            nonce: fallbackNonce,
                            metis_action_nonce: cfg.action_nonce || '',
                            metis_csrf_action: 'metis_website'
                        }, true);
                        return;
                    }
                    self._menuOptionsLoading = false;
                    self._menuOptions = [];
                    if (typeof done === 'function') done(self._normalizedMenuOptions());
                }
            });
        };
        send({
            action: action,
            nonce: cfg.nonce,
            metis_action_nonce: cfg.action_nonce || '',
            metis_csrf_action: cfg.csrf_action || ('metis_ajax:' + action)
        }, false);
    },

    _toast: function(message, level) {
        if (typeof window.metis_toast === 'function') {
            window.metis_toast(message, level || 'info');
            return;
        }
        var fn = (level === 'error') ? 'error' : 'log';
        if (window.console && typeof window.console[fn] === 'function') {
            window.console[fn]('[Metis Builder] ' + String(message || ''));
        }
    },

    _confirm: function(message, onConfirm) {
        var text = String(message || 'Are you sure?');
        var proceed = function() {
            if (typeof onConfirm === 'function') onConfirm();
        };
        if (typeof window.metis_confirm === 'function') {
            window.metis_confirm(text, proceed);
            return;
        }
        var existing = document.getElementById('mwpb-confirm-modal');
        if (existing) existing.remove();

        var backdrop = document.createElement('div');
        backdrop.id = 'mwpb-confirm-modal';
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.style.position = 'fixed';
        backdrop.style.inset = '0';
        backdrop.style.background = 'rgba(15, 23, 42, 0.45)';
        backdrop.style.zIndex = '99150';
        backdrop.style.display = 'flex';
        backdrop.style.alignItems = 'center';
        backdrop.style.justifyContent = 'center';

        var panel = document.createElement('div');
        panel.style.width = 'min(92vw, 420px)';
        panel.style.background = '#fff';
        panel.style.borderRadius = '10px';
        panel.style.border = '1px solid #e2e8f0';
        panel.style.boxShadow = '0 18px 36px rgba(15,23,42,0.25)';
        panel.style.padding = '16px';
        panel.innerHTML =
            '<div style="font-size:15px;font-weight:600;color:#0f172a;margin-bottom:8px;">Confirm Action</div>' +
            '<div style="font-size:14px;color:#334155;line-height:1.45;"></div>' +
            '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">' +
            '  <button type="button" data-role="cancel" style="height:34px;padding:0 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;color:#374151;cursor:pointer;">Cancel</button>' +
            '  <button type="button" data-role="confirm" style="height:34px;padding:0 12px;border:1px solid #2563eb;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;">Confirm</button>' +
            '</div>';
        var messageNode = panel.querySelector('div:nth-child(2)');
        if (messageNode) messageNode.textContent = text;

        backdrop.appendChild(panel);
        document.body.appendChild(backdrop);

        var close = function() {
            if (backdrop && backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
        };
        backdrop.addEventListener('click', function(e) {
            if (e.target === backdrop) close();
        });
        var cancelBtn = panel.querySelector('[data-role="cancel"]');
        var confirmBtn = panel.querySelector('[data-role="confirm"]');
        if (cancelBtn) cancelBtn.addEventListener('click', close);
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                close();
                proceed();
            });
        }
    },

    _esc: function(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    },

    _isDedicatedEditorShell: function() {
        return !!document.getElementById('mwpb-editor-bootstrap');
    },

    _portalBasePath: function() {
        var path = String(window.location.pathname || '');
        var lower = path.toLowerCase();
        var websiteIdx = lower.indexOf('/website');
        var editorIdx = lower.indexOf('/editor');
        var idx = -1;
        if (websiteIdx !== -1 && (editorIdx === -1 || websiteIdx < editorIdx)) {
            idx = websiteIdx;
        } else if (editorIdx !== -1) {
            idx = editorIdx;
        }
        if (idx === -1) return '';
        var base = path.slice(0, idx);
        return base.replace(/\/+$/, '');
    },

    _editorPath: function(relativePath) {
        var base = this._portalBasePath();
        var rel = String(relativePath || '').replace(/^\/+/, '');
        return (base === '' ? '' : base) + '/editor/' + rel;
    },

    _websitePath: function(relativePath) {
        var base = this._portalBasePath();
        var rel = String(relativePath || '').replace(/^\/+/, '');
        return (base === '' ? '' : base) + '/website/' + rel;
    },

    _editorUrlForNew: function(context) {
        var ctx = String(context || 'website');
        if (ctx === 'newsletter' || ctx === 'newsletter_campaign') {
            return this._editorUrlForNewsletterCampaignNew();
        }
        if (ctx === 'newsletter_template') {
            return this._editorUrlForNewsletterTemplateNew();
        }
        if (ctx === 'post') {
            return this._editorPath('new/post/');
        }
        return this._editorPath('new/page/');
    },

    _editorUrlForEdit: function(id, context) {
        var key = String(id || '').trim();
        if (key === '') {
            return this._editorUrlForNew(context);
        }
        var ctx = String(context || 'website');
        if (ctx === 'newsletter' || ctx === 'newsletter_campaign') {
            return this._editorUrlForNewsletterCampaignKey(key);
        }
        if (ctx === 'newsletter_template') {
            return this._editorUrlForNewsletterTemplateKey(key);
        }
        return this._editorPath(encodeURIComponent(key) + '/');
    },

    _editorUrlForTemplateNew: function() {
        return this._editorPath('new/template/');
    },

    _editorUrlForTemplateKey: function(templateKey) {
        return this._editorPath('template/' + encodeURIComponent(String(templateKey || '')) + '/');
    },

    _editorUrlForNewsletterCampaignNew: function() {
        return this._editorPath('new/newsletter/campaign/');
    },

    _editorUrlForNewsletterTemplateNew: function() {
        return this._editorPath('new/newsletter/template/');
    },

    _editorUrlForNewsletterCampaignKey: function(campaignCode) {
        return this._editorPath('newsletter/campaign/' + encodeURIComponent(String(campaignCode || '')) + '/');
    },

    _editorUrlForNewsletterTemplateKey: function(templateCode) {
        return this._editorPath('newsletter/template/' + encodeURIComponent(String(templateCode || '')) + '/');
    },

    _listUrlForContext: function(context) {
        var ctx = String(context || 'website');
        if (ctx === 'newsletter' || ctx === 'newsletter_campaign') {
            return (this._portalBasePath() || '') + '/newsletter/campaigns/';
        }
        if (ctx === 'newsletter_template') {
            return (this._portalBasePath() || '') + '/newsletter/templates/';
        }
        if (ctx === 'post') {
            return this._websitePath('posts/');
        }
        if (ctx === 'template') {
            return this._websitePath('templates/');
        }
        return this._websitePath('pages/');
    },

    _entityRequestKey: function() {
        if (String(this.currentContext || '') === 'template') {
            return this._templateMeta && this._templateMeta.template_key ? String(this._templateMeta.template_key) : '';
        }
        return String(this.currentKey || '');
    },

    _hasSavedEntity: function() {
        var key = this._entityRequestKey();
        if (key !== '') return true;
        return !!this.currentId;
    }
};

// -----------------------------------------------------------------------
// Properties Panel
// -----------------------------------------------------------------------

function MetisPropsPanel(container, builder) {
    this.container = container;
    this.builder   = builder;
    this._collapsedStyleSections = {};
}

MetisPropsPanel.prototype.render = function(block, index) {
    if (!block || index === null || index === undefined) {
        this.container.innerHTML = '<div class="mwpb-props-empty">Select a block to edit its properties.</div>';
        return;
    }

    var self  = this;
    var blockLabelHtml  = '<div class="mwpb-props-block-label">' + self._labelFromKey(block.type || 'block') + '</div>';
    var html  = '';
    var data  = block.data || {};
    var style = block.style || {};

    html += '<div class="mwpb-props-fields">';

    // Render fields based on block type
    switch (block.type) {
        case 'heading':
            html += self._fieldSelect('level', 'Level', data.level || 'h2', ['h1','h2','h3','h4','h5','h6']);
            break;
        case 'text':
            break;
        case 'button':
            html += self._fieldText('label', 'Label', data.label || '');
            html += self._fieldText('url', 'URL', data.url || '');
            html += self._fieldColor('bgcolor', 'Background', data.bgcolor || '#0d6efd');
            html += self._fieldColor('color', 'Text Color', data.color || '#ffffff');
            html += self._fieldSelect('size', 'Size', data.size || 'medium', ['small','medium','large']);
            html += self._fieldDynamic('label', block);
            break;
        case 'image':
            html += '<div class="mwpb-field"><label>Media</label><div class="mwpb-media-actions"><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs mwpb-image-library-btn">Media Library</button><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs mwpb-image-upload-btn">Upload</button></div></div>';
            html += self._fieldText('src', 'Image URL', data.src || '');
            html += self._fieldText('alt', 'Alt Text', data.alt || '');
            html += self._fieldText('link', 'Link URL', data.link || '');
            html += self._fieldText('width', 'Width', data.width || '100%');
            break;
        case 'spacer':
            html += self._fieldNumber('height', 'Height (px)', data.height || 24);
            html += self._fieldSelect('responsive', 'Responsive Heights', data.responsive ? 'true' : 'false', ['false','true']);
            html += self._fieldNumber('desktop_height', 'Desktop Height (px)', data.desktop_height || 32, 0, 5000, 1);
            html += self._fieldNumber('tablet_height', 'Tablet Height (px)', data.tablet_height || 24, 0, 5000, 1);
            html += self._fieldNumber('mobile_height', 'Mobile Height (px)', data.mobile_height || 16, 0, 5000, 1);
            break;
        case 'divider':
            html += self._fieldColor('color', 'Color', data.color || '#e2e6ea');
            html += self._fieldNumber('height', 'Thickness (px)', data.height || 1);
            html += self._fieldSelect('style', 'Style', data.style || 'solid', ['solid','dashed','dotted']);
            html += self._fieldText('label', 'Label (optional)', data.label || '');
            html += self._fieldDynamic('label', block);
            break;
        case 'video':
            html += '<div class="mwpb-field"><label>Media</label><div class="mwpb-media-actions"><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs mwpb-video-library-btn">Media Library</button><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs mwpb-video-upload-btn">Upload</button></div></div>';
            html += self._fieldText('url', 'Video URL', data.url || '');
            html += self._fieldSelect('provider', 'Provider', data.provider || 'youtube', ['youtube','vimeo','local']);
            html += self._fieldSelect('aspect_ratio', 'Aspect Ratio', data.aspect_ratio || '16:9', ['16:9','4:3','1:1']);
            break;
        case 'menu': {
            var menuOptions = self.builder && typeof self.builder._normalizedMenuOptions === 'function'
                ? self.builder._normalizedMenuOptions()
                : [{ value: '', label: 'Select menu…' }];
            if (self.builder && typeof self.builder._loadMenuOptions === 'function' && (!menuOptions || menuOptions.length <= 1) && !self.builder._menuOptionsLoading) {
                self.builder._loadMenuOptions(function() {
                    self.render(block, index);
                });
            }
            html += self._fieldSelect('menu_id', 'Menu', data.menu_id == null ? '' : String(data.menu_id), menuOptions);
            html += self._fieldSelect('menu_type', 'Menu Layout', data.menu_type || 'horizontal', ['horizontal','sidebar','offcanvas']);
            html += self._fieldSelect('orientation', 'Stack Items', data.orientation || 'horizontal', ['horizontal','vertical']);
            html += self._fieldSelect('justify', 'Align Items', data.justify || 'left', ['left','center','right']);
            html += self._fieldText('gap', 'Item Spacing', data.gap || '12px');
            html += self._fieldColor('item_color', 'Text Color', data.item_color || '#1a1f2b');
            html += self._fieldColor('item_hover_color', 'Hover Text Color', data.item_hover_color || '#2563eb');
            html += self._fieldText('item_size', 'Font Size', data.item_size || '16px');
            html += self._fieldSelect('item_weight', 'Font Weight', String(data.item_weight || '400'), [
                { value: '300', label: 'Thin' },
                { value: '400', label: 'Regular' },
                { value: '700', label: 'Bold' },
                { value: '900', label: 'Heavy' }
            ]);
            html += '<div class="mwpb-props-divider">Menu Buttons</div>';
            html += self._fieldSelect('buttonize_items', 'Buttons For Items', data.buttonize_items || 'none', ['none','all','first','last']);
            html += self._fieldText('button_padding', 'Button Padding', data.button_padding || '8px 12px');
            html += self._fieldColor('button_bg', 'Button Background', data.button_bg || '#485bc7');
            html += self._fieldColor('button_text_color', 'Button Text', data.button_text_color || '#ffffff');
            html += self._fieldText('button_radius', 'Button Radius', data.button_radius || '8px');
            html += self._fieldColor('button_hover_bg', 'Button Hover BG', data.button_hover_bg || '#3f51b8');
            html += self._fieldColor('button_hover_text_color', 'Button Hover Text', data.button_hover_text_color || '#ffffff');
            html += '<div class="mwpb-props-divider">Per-Item Buttons</div>';
            html += self._fieldRepeater('menu_item_buttons', 'Custom Buttons By Item', data.menu_item_buttons || [], self._repeaterSchemaFor(block.type, 'menu_item_buttons', data));
            html += '<div class="mwpb-props-divider">Responsive</div>';
            html += self._fieldSelect('responsive_toggle', 'Mobile Toggle', data.responsive_toggle || 'hamburger', ['hamburger','none']);
            html += self._fieldSelect('responsive_style', 'Mobile Menu Style', data.responsive_style || 'overlay', ['overlay','dropdown','inline']);
            html += self._fieldSelect('flyout_position', 'Flyout Side', data.flyout_position || 'right', ['left','right']);
            html += self._fieldNumber('flyout_width', 'Flyout Width (px)', data.flyout_width || 260, 180, 520, 1);
            html += self._fieldSelect('breakpoint', 'Show Mobile Menu On', data.breakpoint || 'medium_small', ['small_only','medium_small','always']);
            html += self._fieldSelect('submenu_icon', 'Submenu Indicator', data.submenu_icon || 'none', [
                { value: 'none', label: 'None' },
                { value: 'chevron_down', label: 'Chevron Down' },
                { value: 'plus', label: 'Plus' },
                { value: 'caret', label: 'Caret' }
            ]);
            break;
        }
        case 'container':
        case 'section':
            var widthMode = String(data.width_mode || '').toLowerCase();
            if (widthMode !== 'full' && widthMode !== 'fixed') {
                widthMode = 'full';
            }
            var fixedDefault = block.type === 'container' ? 1200 : 1440;
            var fixedPx = self._extractPx(data.fixed_width || data.max_width || (String(fixedDefault) + 'px'), fixedDefault);
            html += self._fieldSelect('width_mode', 'Width Mode', widthMode, ['fixed','full']);
            if (widthMode === 'fixed') {
                html += self._fieldRange('_fixed_width_px', 'Width', fixedPx, 320, 2400, 10, 'px');
                html += self._fieldSelect('align', 'Align', data.align || 'center', ['left','center','right']);
            }
            break;
        case 'grid':
        case 'columns':
        case 'advanced_columns':
            self._syncColumnConfig(block);
            data = block.data || {};
            html += self._fieldNumber('columns', 'Columns', data.columns || 2, 1, 4, 1);
            html += self._fieldText('gap', 'Gap', data.gap || '24px');
            html += self._fieldSelect('responsive_mode', 'Layout Flow', data.responsive_mode || 'fit', ['fit','flex']);
            break;
        case 'post_list':
            html += self._fieldNumber('count', 'Count', data.count || 5);
            html += self._fieldSelect('layout', 'Layout', data.layout || 'list', ['list','grid','cards']);
            break;
        case 'page_title':
            html += self._fieldSelect('tag', 'Tag', data.tag || 'h1', ['h1','h2','h3']);
            html += self._fieldText('content', 'Override Title', data.content || '');
            html += self._fieldDynamic('content', block);
            break;
        case 'donation_description':
        case 'campaign_description_block':
            html += self._fieldText('title', 'Title', data.title || '');
            html += self._fieldTextarea('content', 'Content', data.content || '');
            html += self._fieldText('campaign_id', 'Campaign ID', data.campaign_id || '');
            html += self._fieldDynamic('title', block);
            html += self._fieldDynamic('content', block);
            break;
        case 'button_group':
            html += self._fieldSelect('align', 'Align', data.align || 'left', ['left','center','right']);
            html += self._fieldText('gap', 'Gap', data.gap || '8px');
            html += self._fieldRepeater('buttons', 'Buttons', data.buttons || [], self._repeaterSchemaFor(block.type, 'buttons'));
            break;
        case 'tabs':
            html += self._fieldRepeater('items', 'Tabs', data.items || [], self._repeaterSchemaFor(block.type, 'items'));
            break;
        case 'accordion':
        case 'faq':
            html += self._fieldRepeater('items', 'Items', data.items || [], self._repeaterSchemaFor(block.type, 'items'));
            break;
        case 'card_grid':
        case 'feature_grid':
        case 'testimonials':
        case 'impact_metrics':
            html += self._fieldRepeater('items', 'Items', data.items || [], self._repeaterSchemaFor(block.type, 'items'));
            break;
        case 'pricing':
            html += self._fieldRepeater('plans', 'Plans', data.plans || [], self._repeaterSchemaFor(block.type, 'plans'));
            break;
        case 'team':
        case 'team_block':
            html += self._fieldRepeater('members', 'Members', data.members || [], self._repeaterSchemaFor(block.type, 'members'));
            break;
        default:
            var keys = Object.keys(data || {});
            if (!keys.length) {
                html += '<div class="mwpb-props-empty" style="margin:0;">No editable properties.</div>';
            } else {
                keys.forEach(function(key) {
                    var val = data[key];
                    if (Array.isArray(val) || (val && typeof val === 'object')) {
                        html += self._fieldTextarea(key, self._labelFromKey(key), JSON.stringify(val));
                        return;
                    }
                    if (typeof val === 'boolean') {
                        html += self._fieldSelect(key, self._labelFromKey(key), val ? 'true' : 'false', ['true','false']);
                        return;
                    }
                    if (typeof val === 'number') {
                        html += self._fieldNumber(key, self._labelFromKey(key), val);
                        return;
                    }
                    html += self._fieldText(key, self._labelFromKey(key), val == null ? '' : String(val));
                });
            }
    }

    var generalHtml = html + '</div>';
    var styleSections = [];
    var advancedHtml = '<div class="mwpb-props-fields">';
    var responsiveStyle = (style && style.responsive && typeof style.responsive === 'object') ? style.responsive : {};
    var responsiveDevices = (responsiveStyle.devices && typeof responsiveStyle.devices === 'object') ? responsiveStyle.devices : responsiveStyle;
    var visibilityConfig = (style && style.visibility && typeof style.visibility === 'object') ? style.visibility : {};
    var visibilityDevices = (visibilityConfig.devices && typeof visibilityConfig.devices === 'object') ? visibilityConfig.devices : {};
    var visibilityFromMode = function(device) {
        var mode = String((visibilityConfig && visibilityConfig.mode) || 'always').toLowerCase();
        if (mode === 'hidden') return 'hide';
        if (mode === 'hidden_desktop' && device === 'desktop') return 'hide';
        if (mode === 'hidden_tablet' && device === 'tablet') return 'hide';
        if (mode === 'hidden_mobile' && device === 'mobile') return 'hide';
        return 'show';
    };
    var responsiveValue = function(device, key, fallback) {
        var bucket = (responsiveDevices && responsiveDevices[device] && typeof responsiveDevices[device] === 'object')
            ? responsiveDevices[device]
            : {};
        var direct = bucket[key];
        if (direct == null || String(direct).trim() === '') {
            return fallback;
        }
        return String(direct);
    };
    var visibilityValue = function(device) {
        var direct = visibilityDevices && visibilityDevices[device] ? String(visibilityDevices[device]) : '';
        if (direct === 'hide' || direct === 'show') return direct;
        return visibilityFromMode(device);
    };

    if (self._supportsBlockBackground(block)) {
        var appearanceHtml = '';
        var bgColor = (style.color && style.color.background) || '#ffffff';
        appearanceHtml += self._fieldColor('_style_background', 'Background', bgColor);
        appearanceHtml += self._fieldRange('_style_bg_alpha', 'Background Opacity', self._alphaFromColor(bgColor), 0, 100, 1, '%');
        styleSections.push(self._sectionPanel('style-appearance', 'Appearance', appearanceHtml, false));
    }

    var layoutHtml = '';
    layoutHtml += self._fieldSelect('_horizontal_align', 'Horizontal Align', (style && style.horizontal_align) || (data.align || 'left'), ['left','center','right']);
    if (self._supportsVerticalAlign(block)) {
        layoutHtml += self._fieldSelect('_vertical_align', 'Vertical Align', (style && style.vertical_align) || 'top', ['top','center','bottom']);
    }
    var widthModeValue = String((style && style.block_width_mode) || 'full').toLowerCase();
    if (widthModeValue !== 'fixed' && widthModeValue !== 'full') widthModeValue = 'full';
    layoutHtml += self._fieldSelect('_block_width_mode', 'Block Width', widthModeValue, [
        { value: 'full', label: 'Full Width' },
        { value: 'fixed', label: 'Fixed Width' }
    ]);
    if (widthModeValue === 'fixed') {
        var blockFixedPx = self._extractPx((style && style.block_fixed_width) || (style && style.width) || '860px', 860);
        layoutHtml += self._fieldRange('_block_fixed_width_px', 'Fixed Width', blockFixedPx, 320, 2400, 10, 'px');
    }
    styleSections.push(self._sectionPanel('style-layout', 'Layout', layoutHtml, false));

    var spacingHtml = '';
    spacingHtml += self._fieldText('_spacing_padding', 'Padding', (style.spacing && style.spacing.padding) || '');
    spacingHtml += self._fieldText('_spacing_margin', 'Margin', (style.spacing && style.spacing.margin) || '');
    styleSections.push(self._sectionPanel('style-spacing', 'Spacing', spacingHtml, false));

    var typographyHtml = '';
    var fontSizeValue = String((style.typography && style.typography.size) || (style && style.font_size) || '').trim();
    var fontWeightValue = self._normalizeFontWeightValue((style.typography && style.typography.weight) || (style && style.font_weight) || '');
    typographyHtml += self._fieldText('_style_font_size', 'Font Size', fontSizeValue);
    typographyHtml += self._fieldSelect('_style_font_weight', 'Font Weight', fontWeightValue, [
        { value: '', label: 'Default' },
        { value: '300', label: 'Thin' },
        { value: '400', label: 'Regular' },
        { value: '700', label: 'Bold' },
        { value: '900', label: 'Heavy' }
    ]);
    styleSections.push(self._sectionPanel('style-typography', 'Typography', typographyHtml, true));

    var responsiveHtml = '';
    responsiveHtml += self._fieldSelect('_responsive_desktop_align', 'Desktop Align', responsiveValue('desktop', 'align', (style && style.horizontal_align) || 'left'), ['left','center','right']);
    responsiveHtml += self._fieldSelect('_responsive_tablet_align', 'Tablet Align', responsiveValue('tablet', 'align', (style && style.horizontal_align) || 'left'), ['left','center','right']);
    responsiveHtml += self._fieldSelect('_responsive_mobile_align', 'Mobile Align', responsiveValue('mobile', 'align', (style && style.horizontal_align) || 'left'), ['left','center','right']);
    responsiveHtml += self._fieldText('_responsive_desktop_font_size', 'Desktop Font Size', responsiveValue('desktop', 'font_size', (style && style.font_size) || ''));
    responsiveHtml += self._fieldText('_responsive_tablet_font_size', 'Tablet Font Size', responsiveValue('tablet', 'font_size', (style && style.font_size) || ''));
    responsiveHtml += self._fieldText('_responsive_mobile_font_size', 'Mobile Font Size', responsiveValue('mobile', 'font_size', (style && style.font_size) || ''));
    responsiveHtml += self._fieldText('_responsive_desktop_padding', 'Desktop Padding', responsiveValue('desktop', 'padding', (style.spacing && style.spacing.padding) || ''));
    responsiveHtml += self._fieldText('_responsive_tablet_padding', 'Tablet Padding', responsiveValue('tablet', 'padding', (style.spacing && style.spacing.padding) || ''));
    responsiveHtml += self._fieldText('_responsive_mobile_padding', 'Mobile Padding', responsiveValue('mobile', 'padding', (style.spacing && style.spacing.padding) || ''));
    responsiveHtml += self._fieldText('_responsive_desktop_margin', 'Desktop Margin', responsiveValue('desktop', 'margin', (style.spacing && style.spacing.margin) || ''));
    responsiveHtml += self._fieldText('_responsive_tablet_margin', 'Tablet Margin', responsiveValue('tablet', 'margin', (style.spacing && style.spacing.margin) || ''));
    responsiveHtml += self._fieldText('_responsive_mobile_margin', 'Mobile Margin', responsiveValue('mobile', 'margin', (style.spacing && style.spacing.margin) || ''));
    styleSections.push(self._sectionPanel('style-responsive', 'Responsive Style', responsiveHtml, true));

    advancedHtml += '<div class="mwpb-props-section-label">Visibility</div>';
    advancedHtml += self._fieldSelect('_visibility_mode', 'Default Display', (style.visibility && style.visibility.mode) || 'always', [
        { value: 'always', label: 'Always Show' },
        { value: 'hidden', label: 'Always Hide' },
        { value: 'hidden_desktop', label: 'Hide On Desktop' },
        { value: 'hidden_tablet', label: 'Hide On Tablet' },
        { value: 'hidden_mobile', label: 'Hide On Mobile' }
    ]);
    advancedHtml += self._fieldSelect('_responsive_desktop_visibility', 'Desktop Visibility', visibilityValue('desktop'), [
        { value: 'show', label: 'Show On Desktop' },
        { value: 'hide', label: 'Hide On Desktop' }
    ]);
    advancedHtml += self._fieldSelect('_responsive_tablet_visibility', 'Tablet Visibility', visibilityValue('tablet'), [
        { value: 'show', label: 'Show On Tablet' },
        { value: 'hide', label: 'Hide On Tablet' }
    ]);
    advancedHtml += self._fieldSelect('_responsive_mobile_visibility', 'Mobile Visibility', visibilityValue('mobile'), [
        { value: 'show', label: 'Show On Mobile' },
        { value: 'hide', label: 'Hide On Mobile' }
    ]);
    advancedHtml += self._fieldSelect('_visibility_homepage', 'Homepage', (visibilityConfig && visibilityConfig.homepage) || 'inherit', [
        { value: 'inherit', label: 'Use Default' },
        { value: 'show', label: 'Show On Homepage' },
        { value: 'hide', label: 'Hide On Homepage' }
    ]);
    advancedHtml += self._fieldText('_visibility_suppress_pages', 'Hide On Specific Pages', (visibilityConfig && visibilityConfig.suppress_pages) || '');
    advancedHtml += '<div class="mwpb-props-section-label">Anchor</div>';
    advancedHtml += self._fieldText('_anchor_id', 'Anchor ID', data.anchor_id || '');

    var styleHtml = '<div class="mwpb-props-fields">' + styleSections.join('') + '</div>';
    advancedHtml += '</div>';

    this.container.innerHTML = [
        blockLabelHtml,
        '<div class="mwpb-props-tabbar">',
        '  <button type="button" class="mwpb-props-tab is-active" data-props-tab="general">General</button>',
        '  <button type="button" class="mwpb-props-tab" data-props-tab="style">Style</button>',
        '  <button type="button" class="mwpb-props-tab" data-props-tab="advanced">Advanced</button>',
        '</div>',
        '<div class="mwpb-props-pane is-active" data-props-pane="general">' + generalHtml + '</div>',
        '<div class="mwpb-props-pane" data-props-pane="style">' + styleHtml + '</div>',
        '<div class="mwpb-props-pane" data-props-pane="advanced">' + advancedHtml + '</div>'
    ].join('');

    $(this.container).find('[data-props-tab]').on('click', function() {
        var tab = String($(this).data('propsTab') || 'general').toLowerCase();
        if (tab !== 'general' && tab !== 'style' && tab !== 'advanced') tab = 'general';
        $(self.container).find('[data-props-tab]').removeClass('is-active');
        $(this).addClass('is-active');
        $(self.container).find('[data-props-pane]').removeClass('is-active');
        $(self.container).find('[data-props-pane="' + tab + '"]').addClass('is-active');
    });

    $(this.container).find('[data-props-section-toggle]').on('click', function() {
        var id = String($(this).data('propsSectionToggle') || '').trim();
        if (!id) return;
        var panel = self.container.querySelector('[data-props-section="' + self._esc(id) + '"]');
        if (!panel) return;
        var collapsed = panel.classList.toggle('is-collapsed');
        this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        self._collapsedStyleSections[id] = collapsed;
    });

    // Bind live updates
    $(this.container).find('[data-field]').on('input change', function() {
        var field = $(this).data('field');
        var value = this.type === 'checkbox' ? this.checked : $(this).val();
        if (this.type === 'range') {
            var display = this.closest('.mwpb-range-row');
            if (display) {
                var out = display.querySelector('.mwpb-range-value');
                if (out) {
                    var rangeUnit = String(this.getAttribute('data-unit') || '').trim();
                    out.textContent = String(value) + (rangeUnit ? (' ' + rangeUnit) : '');
                }
            }
        }
        self._updateField(block, index, field, value);
        if (field === 'width_mode' || field === 'menu_id' || field === '_block_width_mode') {
            self.render(block, index);
        }
    });
    this._bindRepeaters(block, index);

    $(this.container).find('.mwpb-image-library-btn').on('click', function() {
        self._openMediaLibrary(function(url) {
            if (!url) return;
            $(self.container).find('[data-field="src"]').val(url);
            self._updateField(block, index, 'src', url);
        });
    });
    $(this.container).find('.mwpb-video-library-btn').on('click', function() {
        self._openMediaLibrary(function(url) {
            if (!url) return;
            $(self.container).find('[data-field="url"]').val(url);
            self._updateField(block, index, 'url', url);
            self._updateField(block, index, 'provider', 'local');
        }, { type: 'video' });
    });
    $(this.container).find('.mwpb-image-upload-btn').on('click', function() {
        self._uploadMedia(function(url) {
            if (!url) return;
            $(self.container).find('[data-field="src"]').val(url);
            self._updateField(block, index, 'src', url);
        }, { type: 'image' });
    });
    $(this.container).find('.mwpb-video-upload-btn').on('click', function() {
        self._uploadMedia(function(url) {
            if (!url) return;
            $(self.container).find('[data-field="url"]').val(url);
            self._updateField(block, index, 'url', url);
            self._updateField(block, index, 'provider', 'local');
        }, { type: 'video' });
    });

    $(this.container).find('[data-dynamic-insert]').on('click', function() {
        var target = String($(this).data('targetField') || '').trim();
        var $token = $(self.container).find('[data-dynamic-token="' + self._esc(target) + '"]');
        if (!target || !$token.length) return;
        var token = String($token.val() || '').trim();
        if (!token) return;
        var placeholder = '[metis:' + token + ']';
        var $input = $(self.container).find('[data-field="' + self._esc(target) + '"]').first();
        if (!$input.length) return;
        var current = String($input.val() || '');
        var next = current ? (current + ' ' + placeholder) : placeholder;
        $input.val(next);
        self._updateField(block, index, target, next);
    });
};

MetisPropsPanel.prototype._openMediaLibrary = function(onSelect, options) {
    if (this.builder && typeof this.builder._openMediaLibraryModal === 'function') {
        this.builder._openMediaLibraryModal(onSelect, options);
        return;
    }
    var self = this;
    var opts = options && typeof options === 'object' ? options : {};
    var mediaType = String(opts.type || 'image').toLowerCase() === 'video' ? 'video' : 'image';
    var previousFocus = document.activeElement;
    var sharedAction = 'metis_media_library_list';
    var sharedActionNonce = (window.metisAjax && metisAjax.action_nonces && metisAjax.action_nonces[sharedAction])
        ? metisAjax.action_nonces[sharedAction]
        : '';
    var sharedModuleNonce = (window.metisMediaAjax && window.metisMediaAjax.nonce) ? window.metisMediaAjax.nonce : '';
    var sharedNonce = sharedModuleNonce || sharedActionNonce;
    var sharedCsrfAction = sharedActionNonce ? ('metis_ajax:' + sharedAction) : 'metis_media';
    $('#mwpb-media-modal').remove();
    var html = [
        '<div id="mwpb-media-modal" class="mwpb-media-modal" role="dialog" aria-modal="true" aria-label="Select Media">',
        '  <div class="mwpb-media-panel">',
        '    <div class="mwpb-media-header">',
        '      <strong>Media Library</strong>',
        '      <button type="button" class="mwpb-media-close" aria-label="Close">×</button>',
        '    </div>',
        '    <div class="mwpb-media-toolbar">',
        '      <input type="text" class="mwpb-media-search" placeholder="Search filename...">',
        '      <button type="button" class="mw-btn mw-btn-ghost mw-btn-xs mwpb-media-refresh">Refresh</button>',
        '    </div>',
        '    <div class="mwpb-media-grid"><div class="mwpb-media-empty">Loading media...</div></div>',
        '  </div>',
        '</div>'
    ].join('');
    $('body').append(html);

    function closeModal() {
        $('#mwpb-media-modal').remove();
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    }

    function loadItems() {
        var search = ($('#mwpb-media-modal .mwpb-media-search').val() || '').trim();
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: {
                action: sharedAction,
                nonce: sharedNonce,
                metis_action_nonce: sharedActionNonce || '',
                metis_csrf_action: sharedCsrfAction,
                type: mediaType,
                search: search,
                limit: 60
            },
            success: function(r) {
                var $grid = $('#mwpb-media-modal .mwpb-media-grid');
                if (!r || !r.success || !r.data || !Array.isArray(r.data.items)) {
                    $grid.html('<div class="mwpb-media-empty">Unable to load media.</div>');
                    return;
                }
                if (!r.data.items.length) {
                    $grid.html('<div class="mwpb-media-empty">No media found.</div>');
                    return;
                }
                var cards = r.data.items.map(function(item) {
                    var url = String(item.url || '');
                    var name = self._esc(item.file_name || 'image');
                    var safeUrl = self._esc(url);
                    var mime = String(item.mime_type || '').toLowerCase();
                    var preview = (mediaType === 'video' || mime.indexOf('video/') === 0)
                        ? '<span class="mwpb-media-video-chip">VIDEO</span>'
                        : '<img src="' + safeUrl + '" alt="' + name + '">';
                    return '<button type="button" class="mwpb-media-item" data-url="' + safeUrl + '" title="' + name + '">' + preview + '<span>' + name + '</span></button>';
                }).join('');
                $grid.html(cards);
            },
            error: function() {
                $('#mwpb-media-modal .mwpb-media-grid').html('<div class="mwpb-media-empty">Unable to load media.</div>');
            }
        });
    }

    $('#mwpb-media-modal').on('click', '.mwpb-media-close', closeModal);
    $(document).off('keydown.mwpbMedia').on('keydown.mwpbMedia', function(e) {
        if (e.key === 'Escape' && $('#mwpb-media-modal').length) {
            e.preventDefault();
            closeModal();
            $(document).off('keydown.mwpbMedia');
        }
    });
    $('#mwpb-media-modal').on('click', function(e) {
        if (e.target && e.target.id === 'mwpb-media-modal') closeModal();
    });
    $('#mwpb-media-modal').on('click', '.mwpb-media-refresh', loadItems);
    $('#mwpb-media-modal').on('keydown', '.mwpb-media-search', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            loadItems();
        }
    });
    $('#mwpb-media-modal').on('click', '.mwpb-media-item', function() {
        var url = $(this).data('url') || '';
        if (typeof onSelect === 'function') onSelect(String(url));
        $(document).off('keydown.mwpbMedia');
        closeModal();
    });

    loadItems();
};

MetisPropsPanel.prototype._uploadMedia = function(onSelect, options) {
    var opts = options && typeof options === 'object' ? options : {};
    if (this.builder && typeof this.builder._uploadMediaAsset === 'function') {
        this.builder._uploadMediaAsset(onSelect, { type: String(opts.type || 'image') });
        return;
    }
    var mediaType = String(opts.type || 'image').toLowerCase() === 'video' ? 'video' : 'image';
    var nonce = (window.metisMediaAjax && metisMediaAjax.nonce)
        ? String(metisMediaAjax.nonce)
        : String((window.metisWebsiteAjax && metisWebsiteAjax.nonce) || '');
    if (!nonce) {
        this.builder._toast('Media upload nonce is unavailable.', 'error');
        return;
    }
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = mediaType === 'video' ? 'video/*' : 'image/*';
    input.style.display = 'none';
    document.body.appendChild(input);
    var self = this;
    input.addEventListener('change', function() {
        var file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) {
            if (input.parentNode) input.parentNode.removeChild(input);
            return;
        }
        var formData = new window.FormData();
        formData.append('action', 'metis_media_library_upload');
        formData.append('nonce', nonce);
        formData.append('file', file);
        $.ajax({
            url: (window.metisWebsiteAjax && metisWebsiteAjax.ajax_url) ? metisWebsiteAjax.ajax_url : '/api/ajax',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(r) {
                if (r && r.success && r.data && r.data.url) {
                    if (typeof onSelect === 'function') onSelect(String(r.data.url));
                    self.builder._toast('Upload complete.', 'success');
                    return;
                }
                self.builder._toast((r && r.data && r.data.message) ? r.data.message : 'Upload failed.', 'error');
            },
            error: function(xhr) {
                var message = '';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = String(xhr.responseJSON.data.message);
                }
                self.builder._toast(message || 'Upload failed.', 'error');
            },
            complete: function() {
                if (input.parentNode) input.parentNode.removeChild(input);
            }
        });
    });
    input.click();
};

MetisPropsPanel.prototype._updateField = function(block, index, field, value) {
    if (!block || !this.builder || !this.builder.editor) return;
    var numericFields = {
        height: true,
        desktop_height: true,
        tablet_height: true,
        mobile_height: true,
        count: true,
        columns: true
    };
    if (field === '_column_ratios') {
        block.data = block.data || {};
        var columnCount = this._normalizeColumnCount(block.data.columns);
        block.data.ratios = this._normalizeColumnRatios(String(value || ''), columnCount);
    } else if (field === 'width_mode') {
        block.data = block.data || {};
        var nextMode = String(value || '').toLowerCase() === 'full' ? 'full' : 'fixed';
        block.data.width_mode = nextMode;
        if (nextMode === 'full') {
            block.data.align = 'full';
        } else if (String(block.data.align || '').toLowerCase() === 'full') {
            block.data.align = 'center';
        }
    } else if (field === '_fixed_width_px') {
        block.data = block.data || {};
        var widthPx = parseInt(String(value || ''), 10);
        if (!Number.isFinite(widthPx) || widthPx < 320) widthPx = 320;
        if (widthPx > 2400) widthPx = 2400;
        block.data.fixed_width = String(widthPx) + 'px';
        block.data.max_width = String(widthPx) + 'px';
    } else if (field === '_horizontal_align') {
        block.style = block.style || {};
        var nextAlign = String(value || 'left');
        block.style.horizontal_align = nextAlign;
        block.style.align = nextAlign;
        block.style.text_align = nextAlign;
        block.data = block.data || {};
        block.data.align = nextAlign;
    } else if (field === '_vertical_align') {
        block.style = block.style || {};
        block.style.vertical_align = String(value || 'top');
    } else if (field === '_style_font_size') {
        block.style = block.style || {};
        block.style.typography = block.style.typography || {};
        var fontSize = String(value || '').trim();
        if (fontSize === '') {
            delete block.style.font_size;
            if (block.style.typography && Object.prototype.hasOwnProperty.call(block.style.typography, 'size')) {
                delete block.style.typography.size;
            }
        } else {
            block.style.font_size = fontSize;
            block.style.typography.size = fontSize;
        }
    } else if (field === '_style_font_weight') {
        block.style = block.style || {};
        block.style.typography = block.style.typography || {};
        var mappedWeight = this._normalizeFontWeightValue(value);
        if (!mappedWeight) {
            delete block.style.font_weight;
            if (block.style.typography && Object.prototype.hasOwnProperty.call(block.style.typography, 'weight')) {
                delete block.style.typography.weight;
            }
        } else {
            block.style.font_weight = mappedWeight;
            block.style.typography.weight = mappedWeight;
        }
    } else if (field === '_block_width_mode') {
        block.style = block.style || {};
        var blockWidthMode = String(value || 'full').toLowerCase() === 'fixed' ? 'fixed' : 'full';
        block.style.block_width_mode = blockWidthMode;
        if (blockWidthMode === 'fixed') {
            var existingFixed = this._extractPx(block.style.block_fixed_width || block.style.width || '860px', 860);
            block.style.block_fixed_width = String(existingFixed) + 'px';
            block.style.width = String(existingFixed) + 'px';
            block.style.max_width = String(existingFixed) + 'px';
        } else {
            delete block.style.block_fixed_width;
            if (Object.prototype.hasOwnProperty.call(block.style, 'width')) delete block.style.width;
            if (Object.prototype.hasOwnProperty.call(block.style, 'max_width')) delete block.style.max_width;
        }
    } else if (field === '_block_fixed_width_px') {
        block.style = block.style || {};
        var blockWidthPx = parseInt(String(value || ''), 10);
        if (!Number.isFinite(blockWidthPx) || blockWidthPx < 320) blockWidthPx = 320;
        if (blockWidthPx > 2400) blockWidthPx = 2400;
        block.style.block_width_mode = 'fixed';
        block.style.block_fixed_width = String(blockWidthPx) + 'px';
        block.style.width = String(blockWidthPx) + 'px';
        block.style.max_width = String(blockWidthPx) + 'px';
    } else if (field === '_style_background') {
        block.style = block.style || {};
        block.style.color = block.style.color || {};
        var bg = String(value || '').trim();
        var currentBg = String((block.style.color && block.style.color.background) || '').trim();
        var alphaPct = this._alphaFromColor(currentBg);
        if (bg === '') {
            if (block.style.color && Object.prototype.hasOwnProperty.call(block.style.color, 'background')) {
                delete block.style.color.background;
            }
        } else {
            block.style.color.background = this._applyAlphaToColor(bg, alphaPct);
        }
    } else if (field === '_style_bg_alpha') {
        block.style = block.style || {};
        block.style.color = block.style.color || {};
        var existingBg = String((block.style.color && block.style.color.background) || '#ffffff');
        block.style.color.background = this._applyAlphaToColor(existingBg, value);
    } else if (field === '_spacing_padding') {
        block.style = block.style || {};
        block.style.spacing = block.style.spacing || {};
        block.style.spacing.padding = value;
    } else if (field === '_spacing_margin') {
        block.style = block.style || {};
        block.style.spacing = block.style.spacing || {};
        block.style.spacing.margin = value;
    } else if (/^_responsive_(desktop|tablet|mobile)_(align|font_size|padding|margin|visibility)$/.test(String(field || ''))) {
        block.style = block.style || {};
        block.style.responsive = block.style.responsive || {};
        block.style.responsive.devices = block.style.responsive.devices || {};
        var responsiveMatch = String(field || '').match(/^_responsive_(desktop|tablet|mobile)_(align|font_size|padding|margin|visibility)$/);
        if (responsiveMatch) {
            var device = String(responsiveMatch[1] || '');
            var responsiveKey = String(responsiveMatch[2] || '');
            block.style.responsive.devices[device] = block.style.responsive.devices[device] || {};
            if (responsiveKey === 'visibility') {
                var visibilityValue = String(value || '').toLowerCase() === 'hide' ? 'hide' : 'show';
                block.style.visibility = block.style.visibility || {};
                block.style.visibility.devices = block.style.visibility.devices || {};
                block.style.visibility.devices[device] = visibilityValue;
                var desktopVis = String((block.style.visibility.devices.desktop || 'show')).toLowerCase();
                var tabletVis = String((block.style.visibility.devices.tablet || 'show')).toLowerCase();
                var mobileVis = String((block.style.visibility.devices.mobile || 'show')).toLowerCase();
                if (desktopVis === 'hide' && tabletVis === 'hide' && mobileVis === 'hide') {
                    block.style.visibility.mode = 'hidden';
                } else if (desktopVis === 'hide' && tabletVis === 'show' && mobileVis === 'show') {
                    block.style.visibility.mode = 'hidden_desktop';
                } else if (desktopVis === 'show' && tabletVis === 'hide' && mobileVis === 'show') {
                    block.style.visibility.mode = 'hidden_tablet';
                } else if (desktopVis === 'show' && tabletVis === 'show' && mobileVis === 'hide') {
                    block.style.visibility.mode = 'hidden_mobile';
                } else if (desktopVis === 'show' && tabletVis === 'show' && mobileVis === 'show') {
                    block.style.visibility.mode = 'always';
                }
            } else {
                block.style.responsive.devices[device][responsiveKey] = String(value || '').trim();
            }
        }
    } else if (field === '_visibility_mode') {
        block.style = block.style || {};
        block.style.visibility = block.style.visibility || {};
        block.style.visibility.mode = String(value || 'always');
    } else if (field === '_visibility_homepage') {
        block.style = block.style || {};
        block.style.visibility = block.style.visibility || {};
        var homepageRule = String(value || 'inherit').toLowerCase();
        if (homepageRule !== 'show' && homepageRule !== 'hide') {
            if (Object.prototype.hasOwnProperty.call(block.style.visibility, 'homepage')) {
                delete block.style.visibility.homepage;
            }
        } else {
            block.style.visibility.homepage = homepageRule;
        }
    } else if (field === '_visibility_suppress_pages') {
        block.style = block.style || {};
        block.style.visibility = block.style.visibility || {};
        var suppressValue = String(value || '').trim();
        if (!suppressValue) {
            if (Object.prototype.hasOwnProperty.call(block.style.visibility, 'suppress_pages')) {
                delete block.style.visibility.suppress_pages;
            }
        } else {
            block.style.visibility.suppress_pages = suppressValue;
        }
    } else if (field === '_anchor_id') {
        block.data = block.data || {};
        block.data.anchor_id = String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '');
    } else {
        block.data = block.data || {};
        if (field === 'item_weight') {
            value = this._normalizeFontWeightValue(value || '') || '400';
        }
        if (typeof value === 'string' && (value.charAt(0) === '{' || value.charAt(0) === '[')) {
            try {
                block.data[field] = JSON.parse(value);
            } catch (_e) {
                block.data[field] = value;
            }
        } else if (value === 'true' || value === 'false') {
            block.data[field] = value === 'true';
        } else if (numericFields[field]) {
            var num = parseFloat(String(value || '').trim());
            block.data[field] = Number.isFinite(num) ? num : 0;
        } else {
            block.data[field] = value;
        }
    }
    if (String(block.type || '') === 'spacer' && block.data && block.data.responsive) {
        var desktop = parseFloat(block.data.desktop_height);
        if (Number.isFinite(desktop) && desktop >= 0) {
            block.data.height = desktop;
        }
    }
    this._syncColumnConfig(block);
    if (typeof index === 'string' && typeof this.builder.editor.updateBlockAtPath === 'function') {
        this.builder.editor.updateBlockAtPath(index, block, { partial: true });
    } else if (Array.isArray(this.builder.editor.blocks)) {
        this.builder.editor.blocks[index] = block;
        /* _renderCanvas is internal; use public refresh() in BB model */
        if (typeof this.builder.editor.refresh === 'function') {
            this.builder.editor.refresh();
        }
        if (typeof this.builder.editor.onChange === 'function') {
            this.builder.editor.onChange(this.builder.editor.getLayout ? this.builder.editor.getLayout() : this.builder.editor.blocks);
        }
    }
    this.builder._dirty = true;
};

MetisPropsPanel.prototype._fieldText = function(f, label, val) {
    return '<div class="mwpb-field"><label>' + label + '</label><input type="text" class="mwpb-input" data-field="' + f + '" value="' + this._esc(val) + '"></div>';
};
MetisPropsPanel.prototype._fieldTextarea = function(f, label, val) {
    return '<div class="mwpb-field"><label>' + label + '</label><textarea class="mwpb-input mwpb-textarea" data-field="' + f + '">' + this._esc(val) + '</textarea></div>';
};
MetisPropsPanel.prototype._fieldNumber = function(f, label, val, min, max, step) {
    var attrs = '';
    if (min !== undefined && min !== null) attrs += ' min="' + Number(min) + '"';
    if (max !== undefined && max !== null) attrs += ' max="' + Number(max) + '"';
    if (step !== undefined && step !== null) attrs += ' step="' + Number(step) + '"';
    return '<div class="mwpb-field"><label>' + label + '</label><input type="number" class="mwpb-input" data-field="' + f + '" value="' + Number(val) + '"' + attrs + '></div>';
};
MetisPropsPanel.prototype._fieldRange = function(f, label, val, min, max, step, unit) {
    var value = parseInt(String(val == null ? '' : val), 10);
    if (!Number.isFinite(value)) value = Number(min || 0);
    var lo = Number(min || 0);
    var hi = Number(max || lo);
    if (value < lo) value = lo;
    if (value > hi) value = hi;
    var suffix = String(unit || '').trim();
    return '<div class="mwpb-field"><label>' + label + '</label><div class="mwpb-range-row"><input type="range" class="mwpb-range-input" data-field="' + f + '" data-unit="' + this._esc(suffix) + '" min="' + lo + '" max="' + hi + '" step="' + Number(step || 1) + '" value="' + value + '"><span class="mwpb-range-value">' + value + (suffix ? (' ' + this._esc(suffix)) : '') + '</span></div></div>';
};
MetisPropsPanel.prototype._fieldColor = function(f, label, val) {
    return '<div class="mwpb-field"><label>' + label + '</label><div class="mwpb-color-row"><input type="color" class="mwpb-color" data-field="' + f + '" value="' + this._esc(val) + '"><input type="text" class="mwpb-input mwpb-color-text" data-field="' + f + '" value="' + this._esc(val) + '"></div></div>';
};
MetisPropsPanel.prototype._fieldSelect = function(f, label, val, opts) {
    var list = Array.isArray(opts) ? opts : [];
    var current = val == null ? '' : String(val);
    var options = list.map(function(opt) {
        if (opt && typeof opt === 'object') {
            var ov = opt.value == null ? '' : String(opt.value);
            var ol = opt.label == null ? ov : String(opt.label);
            return '<option value="' + this._esc(ov) + '"' + (ov === current ? ' selected' : '') + '>' + this._esc(ol) + '</option>';
        }
        var sv = opt == null ? '' : String(opt);
        return '<option value="' + this._esc(sv) + '"' + (sv === current ? ' selected' : '') + '>' + this._esc(sv) + '</option>';
    }, this).join('');
    return '<div class="mwpb-field"><label>' + label + '</label><select class="mwpb-input mwpb-select" data-field="' + f + '">' + options + '</select></div>';
};

MetisPropsPanel.prototype._sectionPanel = function(id, title, bodyHtml, defaultCollapsed) {
    var sectionId = String(id || '').trim();
    if (!sectionId) sectionId = 'section-' + String(Math.random()).slice(2);
    var collapsed = Object.prototype.hasOwnProperty.call(this._collapsedStyleSections, sectionId)
        ? !!this._collapsedStyleSections[sectionId]
        : !!defaultCollapsed;
    var cls = 'mwpb-props-section-panel' + (collapsed ? ' is-collapsed' : '');
    return [
        '<section class="' + cls + '" data-props-section="' + this._esc(sectionId) + '">',
        '  <button type="button" class="mwpb-props-section-head" data-props-section-toggle="' + this._esc(sectionId) + '" aria-expanded="' + (collapsed ? 'false' : 'true') + '">',
        '    <span class="mwpb-props-section-title">' + this._esc(title) + '</span>',
        '    <span class="mwpb-props-section-caret" aria-hidden="true">▾</span>',
        '  </button>',
        '  <div class="mwpb-props-section-body">' + String(bodyHtml || '') + '</div>',
        '</section>'
    ].join('');
};

MetisPropsPanel.prototype._normalizeFontWeightValue = function(raw) {
    var value = String(raw == null ? '' : raw).trim().toLowerCase();
    if (!value) return '';
    if (value === 'thin') return '300';
    if (value === 'regular' || value === 'normal') return '400';
    if (value === 'bold') return '700';
    if (value === 'heavy' || value === 'black') return '900';
    if (value === '300' || value === '400' || value === '700' || value === '900') return value;
    var n = parseInt(value, 10);
    if (!Number.isFinite(n)) return '';
    if (n <= 350) return '300';
    if (n <= 550) return '400';
    if (n <= 800) return '700';
    return '900';
};

MetisPropsPanel.prototype._supportsBlockBackground = function(block) {
    var type = String((block && block.type) || '').toLowerCase();
    if (!type) return false;
    if (type === 'spacer' || type === 'divider') return false;
    return true;
};

MetisPropsPanel.prototype._supportsVerticalAlign = function(block) {
    var type = String((block && block.type) || '').toLowerCase();
    if (!type) return false;
    if (type === 'menu') return false;
    if (type === 'container' || type === 'section' || type === 'spacer' || type === 'divider') return false;
    return true;
};
MetisPropsPanel.prototype._fieldDynamic = function(targetField, block) {
    if (!this.builder || !this.builder.editor || typeof this.builder.editor.getDynamicTokensForBlock !== 'function') {
        return '';
    }
    var tokens = this.builder.editor.getDynamicTokensForBlock(block.type || '');
    if (!Array.isArray(tokens) || !tokens.length) return '';
    var options = ['<option value="">Select dynamic content…</option>'];
    tokens.forEach(function(item) {
        var token = String(item.token || '').trim();
        if (!token) return;
        var label = String(item.label || token);
        options.push('<option value="' + this._esc(token) + '">' + this._esc(label) + '</option>');
    }, this);
    return [
        '<div class="mwpb-field">',
        '<label>Dynamic Content</label>',
        '<div class="mwpb-dynamic-row">',
        '<select class="mwpb-input mwpb-select" data-dynamic-token="' + this._esc(targetField) + '">',
        options.join(''),
        '</select>',
        '<button type="button" class="mw-btn mw-btn-ghost mw-btn-xs" data-dynamic-insert="1" data-target-field="' + this._esc(targetField) + '">Insert</button>',
        '</div>',
        '</div>'
    ].join('');
};
MetisPropsPanel.prototype._fieldRepeater = function(field, label, value, schema) {
    var items = Array.isArray(value) ? value : [];
    var rows = '';
    if (items.length) {
        for (var i = 0; i < items.length; i += 1) {
            rows += this._repeaterRowHtml(items[i], i, schema);
        }
    } else {
        rows = '<div class="mwpb-repeater-empty">No items yet.</div>';
    }
    return [
        '<div class="mwpb-field">',
        '<label>' + this._esc(label) + '</label>',
        '<div class="mwpb-repeater" data-repeater="' + this._esc(field) + '">',
        '<div class="mwpb-repeater-list">',
        rows,
        '</div>',
        '<button type="button" class="mw-btn mw-btn-ghost mw-btn-xs" data-repeater-add="' + this._esc(field) + '">Add Item</button>',
        '</div>',
        '</div>'
    ].join('');
};
MetisPropsPanel.prototype._repeaterRowHtml = function(item, rowIndex, schema) {
    var fields = schema && Array.isArray(schema.fields) ? schema.fields : [];
    var rows = ['<div class="mwpb-repeater-item" data-repeater-item="' + Number(rowIndex) + '">'];
    rows.push('<div class="mwpb-repeater-head"><strong>Item ' + (Number(rowIndex) + 1) + '</strong><div class="mwpb-repeater-actions"><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs" data-repeater-up="1" title="Move up">↑</button><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs" data-repeater-down="1" title="Move down">↓</button><button type="button" class="mw-btn mw-btn-ghost mw-btn-xs" data-repeater-remove="1">Remove</button></div></div>');
    for (var i = 0; i < fields.length; i += 1) {
        var field = fields[i] || {};
        var key = String(field.key || '').trim();
        if (key === '') continue;
        var type = String(field.type || 'text');
        var label = String(field.label || key);
        var raw = item && item[key] != null ? String(item[key]) : '';
        if (type === 'textarea') {
            rows.push('<label>' + this._esc(label) + '</label><textarea class="mwpb-input mwpb-textarea" data-repeater-field="' + this._esc(key) + '">' + this._esc(raw) + '</textarea>');
        } else if (type === 'color') {
            var colorVal = raw || String(field.default || '#485bc7');
            rows.push('<label>' + this._esc(label) + '</label><div class="mwpb-color-row"><input type="color" class="mwpb-color" data-repeater-field="' + this._esc(key) + '" value="' + this._esc(colorVal) + '"><input type="text" class="mwpb-input mwpb-color-text" data-repeater-field="' + this._esc(key) + '" value="' + this._esc(colorVal) + '"></div>');
        } else if (type === 'select') {
            var options = Array.isArray(field.options) ? field.options : [];
            var selected = raw || String(field.default || '');
            var opts = options.map(function(option) {
                if (option && typeof option === 'object') {
                    var optValue = option.value == null ? '' : String(option.value);
                    var optLabel = option.label == null ? optValue : String(option.label);
                    return '<option value="' + this._esc(optValue) + '"' + (optValue === selected ? ' selected' : '') + '>' + this._esc(optLabel) + '</option>';
                }
                var text = String(option);
                return '<option value="' + this._esc(text) + '"' + (text === selected ? ' selected' : '') + '>' + this._esc(text) + '</option>';
            }, this).join('');
            rows.push('<label>' + this._esc(label) + '</label><select class="mwpb-input mwpb-select" data-repeater-field="' + this._esc(key) + '">' + opts + '</select>');
        } else if (type === 'number') {
            var attrs = '';
            if (field.min != null) attrs += ' min="' + Number(field.min) + '"';
            if (field.max != null) attrs += ' max="' + Number(field.max) + '"';
            if (field.step != null) attrs += ' step="' + Number(field.step) + '"';
            var numVal = raw || String(field.default != null ? field.default : 0);
            rows.push('<label>' + this._esc(label) + '</label><input type="number" class="mwpb-input" data-repeater-field="' + this._esc(key) + '" value="' + this._esc(numVal) + '"' + attrs + '>');
        } else {
            rows.push('<label>' + this._esc(label) + '</label><input type="text" class="mwpb-input" data-repeater-field="' + this._esc(key) + '" value="' + this._esc(raw) + '">');
        }
    }
    rows.push('</div>');
    return rows.join('');
};
MetisPropsPanel.prototype._menuRowsForMenuId = function(menuId) {
    if (menuId && typeof menuId === 'object') {
        menuId = menuId.id != null ? menuId.id : (menuId.value != null ? menuId.value : '');
    }
    var targetId = String(menuId == null ? '' : menuId).trim();
    if (targetId === '') return [];
    var rows = (this.builder && Array.isArray(this.builder._menuOptions)) ? this.builder._menuOptions : [];
    var selected = null;
    for (var i = 0; i < rows.length; i += 1) {
        var item = rows[i] && typeof rows[i] === 'object' ? rows[i] : null;
        if (!item) continue;
        var id = item.id != null ? String(item.id) : String(item.menu_id || item.value || '');
        if (id === targetId) {
            selected = item;
            break;
        }
    }
    if (!selected) return [];
    var raw = selected.items_json;
    var decoded = [];
    if (Array.isArray(raw)) {
        decoded = raw;
    } else if (typeof raw === 'string' && raw.trim() !== '') {
        try {
            var parsed = JSON.parse(raw);
            decoded = Array.isArray(parsed) ? parsed : [];
        } catch (_e) {
            decoded = [];
        }
    }
    return this._normalizeMenuItems(decoded);
};

MetisPropsPanel.prototype._normalizeMenuItems = function(items) {
    var list = Array.isArray(items) ? items : [];
    var out = [];
    var seen = Object.create(null);
    var counter = 1;
    for (var i = 0; i < list.length; i += 1) {
        var item = list[i];
        if (!item || typeof item !== 'object') continue;
        var label = String(item.label || '').trim();
        var url = String(item.url || '').trim();
        if (!label || !url) continue;
        var id = String(item.id || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_');
        if (!id || seen[id]) {
            id = 'mitem_' + String(counter++);
        }
        seen[id] = true;
        var parentId = String(item.parent_id || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '_');
        out.push({
            id: id,
            parent_id: parentId,
            label: label,
            url: url
        });
    }
    var idMap = Object.create(null);
    for (var j = 0; j < out.length; j += 1) {
        idMap[out[j].id] = true;
    }
    for (var k = 0; k < out.length; k += 1) {
        var p = String(out[k].parent_id || '');
        if (!p || !idMap[p] || p === out[k].id) {
            out[k].parent_id = '';
        }
    }
    return out;
};

MetisPropsPanel.prototype._menuItemSelectOptions = function(menuId) {
    var rows = this._menuRowsForMenuId(menuId);
    var options = [{ value: '', label: 'Select menu item…' }];
    if (!rows.length) return options;
    var children = Object.create(null);
    for (var i = 0; i < rows.length; i += 1) {
        var row = rows[i];
        var parent = String(row.parent_id || '');
        if (!children[parent]) children[parent] = [];
        children[parent].push(row);
    }
    var walk = function(parentId, depth) {
        var items = children[parentId] || [];
        for (var idx = 0; idx < items.length; idx += 1) {
            var item = items[idx];
            var prefix = depth > 0 ? (new Array(depth + 1).join('— ')) : '';
            options.push({
                value: item.id,
                label: prefix + item.label
            });
            walk(item.id, depth + 1);
        }
    };
    walk('', 0);
    return options;
};

MetisPropsPanel.prototype._repeaterSchemaFor = function(blockType, field, blockData) {
    var type = String(blockType || '').toLowerCase();
    var key = String(field || '').toLowerCase();
    var data = blockData && typeof blockData === 'object' ? blockData : {};
    if (type === 'menu' && key === 'menu_item_buttons') {
        return {
            fields: [
                { key: 'item_id', label: 'Menu Item', type: 'select', options: this._menuItemSelectOptions(data.menu_id) },
                { key: 'is_button', label: 'Style As Button', type: 'select', default: 'yes', options: [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }] },
                { key: 'button_bg', label: 'Button Background', type: 'color', default: '#485bc7' },
                { key: 'button_text_color', label: 'Button Text', type: 'color', default: '#ffffff' },
                { key: 'button_hover_bg', label: 'Button Hover BG', type: 'color', default: '#3f51b8' },
                { key: 'button_hover_text_color', label: 'Button Hover Text', type: 'color', default: '#ffffff' },
                { key: 'button_padding', label: 'Button Padding', default: '8px 12px' },
                { key: 'button_radius', label: 'Button Radius', default: '8px' }
            ]
        };
    }
    if (type === 'button_group' && key === 'buttons') {
        return {
            fields: [
                { key: 'label', label: 'Label' },
                { key: 'url', label: 'URL' },
                { key: 'padding', label: 'Padding', default: '10px 14px' },
                { key: 'bgcolor', label: 'Background', type: 'color', default: '#485bc7' },
                { key: 'color', label: 'Text Color', type: 'color', default: '#ffffff' },
                { key: 'text_size', label: 'Text Size', default: '16px' },
                { key: 'border_radius', label: 'Radius', default: '8px' },
                { key: 'hover_bgcolor', label: 'Hover Background', type: 'color', default: '#3f51b8' },
                { key: 'hover_color', label: 'Hover Text', type: 'color', default: '#ffffff' },
                { key: 'animation', label: 'Animation', type: 'select', default: 'none', options: ['none', 'lift', 'pulse', 'glow'] }
            ]
        };
    }
    if (type === 'tabs' && key === 'items') {
        return { fields: [ { key: 'title', label: 'Title' }, { key: 'content', label: 'Content', type: 'textarea' } ] };
    }
    if ((type === 'accordion' || type === 'faq') && key === 'items') {
        return { fields: [ { key: 'title', label: 'Question' }, { key: 'content', label: 'Answer', type: 'textarea' } ] };
    }
    if ((type === 'card_grid' || type === 'feature_grid' || type === 'testimonials' || type === 'impact_metrics') && key === 'items') {
        return { fields: [ { key: 'title', label: 'Title' }, { key: 'content', label: 'Content', type: 'textarea' } ] };
    }
    if (type === 'pricing' && key === 'plans') {
        return { fields: [ { key: 'name', label: 'Plan Name' }, { key: 'price', label: 'Price' }, { key: 'description', label: 'Description', type: 'textarea' } ] };
    }
    if ((type === 'team' || type === 'team_block') && key === 'members') {
        return { fields: [ { key: 'name', label: 'Name' }, { key: 'role', label: 'Role' } ] };
    }
    return { fields: [ { key: 'title', label: 'Title' }, { key: 'content', label: 'Content', type: 'textarea' } ] };
};
MetisPropsPanel.prototype._readRepeaterItems = function($repeater) {
    var items = [];
    $repeater.find('.mwpb-repeater-item').each(function() {
        var row = {};
        $(this).find('[data-repeater-field]').each(function() {
            var key = String($(this).data('repeaterField') || '').trim();
            if (!key) return;
            row[key] = String($(this).val() || '');
        });
        items.push(row);
    });
    return items;
};
MetisPropsPanel.prototype._bindRepeaters = function(block, index) {
    var self = this;
    var $root = $(this.container);
    if (!$root.find('[data-repeater]').length) return;

    $root.find('[data-repeater-add]').on('click', function() {
        var field = String($(this).data('repeaterAdd') || '').trim();
        if (field === '') return;
        var schema = self._repeaterSchemaFor(block.type, field, block.data || {});
        var items = Array.isArray(block.data && block.data[field]) ? block.data[field].slice(0) : [];
        var entry = {};
        var fields = schema && Array.isArray(schema.fields) ? schema.fields : [];
        for (var i = 0; i < fields.length; i += 1) {
            var key = String((fields[i] && fields[i].key) || '').trim();
            if (key !== '') {
            var defVal = (fields[i] && Object.prototype.hasOwnProperty.call(fields[i], 'default')) ? fields[i].default : '';
            entry[key] = defVal == null ? '' : String(defVal);
        }
        }
        items.push(entry);
        self._updateField(block, index, field, items);
        self.render(block, index);
    });

    $root.find('[data-repeater-field]').on('input change', function() {
        var $repeater = $(this).closest('[data-repeater]');
        var field = String($repeater.data('repeater') || '').trim();
        if (field === '') return;
        var items = self._readRepeaterItems($repeater);
        if (field === 'menu_item_buttons') {
            items = items.filter(function(item) {
                return item && String(item.item_id || '').trim() !== '';
            });
        }
        self._updateField(block, index, field, items);
    });

    $root.find('[data-repeater-remove]').on('click', function() {
        var $row = $(this).closest('.mwpb-repeater-item');
        var $repeater = $(this).closest('[data-repeater]');
        var field = String($repeater.data('repeater') || '').trim();
        if (field === '') return;
        var rowIndex = parseInt(String($row.data('repeaterItem') || ''), 10);
        if (Number.isNaN(rowIndex) || rowIndex < 0) return;
        var items = self._readRepeaterItems($repeater);
        if (rowIndex >= items.length) return;
        items.splice(rowIndex, 1);
        self._updateField(block, index, field, items);
        self.render(block, index);
    });

    $root.find('[data-repeater-up], [data-repeater-down]').on('click', function() {
        var moveDir = $(this).is('[data-repeater-up]') ? -1 : 1;
        var $row = $(this).closest('.mwpb-repeater-item');
        var $repeater = $(this).closest('[data-repeater]');
        var field = String($repeater.data('repeater') || '').trim();
        if (field === '') return;
        var rowIndex = parseInt(String($row.data('repeaterItem') || ''), 10);
        if (Number.isNaN(rowIndex) || rowIndex < 0) return;
        var items = self._readRepeaterItems($repeater);
        var target = rowIndex + moveDir;
        if (target < 0 || target >= items.length) return;
        var tmp = items[rowIndex];
        items[rowIndex] = items[target];
        items[target] = tmp;
        self._updateField(block, index, field, items);
        self.render(block, index);
    });
};
MetisPropsPanel.prototype._esc = function(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
};
MetisPropsPanel.prototype._labelFromKey = function(key) {
    return String(key || '')
        .replace(/_/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, function(c) { return c.toUpperCase(); });
};

MetisPropsPanel.prototype._extractPx = function(raw, fallback) {
    var text = String(raw == null ? '' : raw).trim();
    if (!text) return Number(fallback || 0);
    var match = text.match(/(-?\d+(\.\d+)?)/);
    var value = match ? parseFloat(match[1]) : NaN;
    if (!Number.isFinite(value)) return Number(fallback || 0);
    return Math.round(value);
};

MetisPropsPanel.prototype._alphaFromColor = function(color) {
    var c = String(color == null ? '' : color).trim();
    var rgba = c.match(/^rgba?\(([^)]+)\)$/i);
    if (rgba) {
        var parts = rgba[1].split(',').map(function(part) { return String(part).trim(); });
        if (parts.length >= 4) {
            var a = parseFloat(parts[3]);
            if (Number.isFinite(a)) {
                var pct = Math.round(a * 100);
                if (pct < 0) pct = 0;
                if (pct > 100) pct = 100;
                return pct;
            }
        }
    }
    return 100;
};

MetisPropsPanel.prototype._applyAlphaToColor = function(color, alphaPct) {
    var c = String(color == null ? '' : color).trim();
    var pct = parseInt(String(alphaPct == null ? '' : alphaPct), 10);
    if (!Number.isFinite(pct)) pct = 100;
    if (pct < 0) pct = 0;
    if (pct > 100) pct = 100;
    if (c === '') return '';
    var alpha = (pct / 100);
    var hexMatch = c.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (hexMatch) {
        var hex = hexMatch[1];
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        var r = parseInt(hex.slice(0, 2), 16);
        var g = parseInt(hex.slice(2, 4), 16);
        var b = parseInt(hex.slice(4, 6), 16);
        if (pct >= 100) return ('#' + hex.toLowerCase());
        return 'rgba(' + r + ',' + g + ',' + b + ',' + String(Number(alpha.toFixed(2))) + ')';
    }
    var rgbaMatch = c.match(/^rgba?\(([^)]+)\)$/i);
    if (rgbaMatch) {
        var channels = rgbaMatch[1].split(',').map(function(part) { return String(part).trim(); });
        if (channels.length >= 3) {
            var r2 = parseInt(channels[0], 10);
            var g2 = parseInt(channels[1], 10);
            var b2 = parseInt(channels[2], 10);
            if (!Number.isFinite(r2)) r2 = 0;
            if (!Number.isFinite(g2)) g2 = 0;
            if (!Number.isFinite(b2)) b2 = 0;
            if (pct >= 100) return 'rgb(' + r2 + ',' + g2 + ',' + b2 + ')';
            return 'rgba(' + r2 + ',' + g2 + ',' + b2 + ',' + String(Number(alpha.toFixed(2))) + ')';
        }
    }
    return c;
};

MetisPropsPanel.prototype._normalizeColumnCount = function(value) {
    var count = parseInt(String(value == null ? '' : value), 10);
    if (Number.isNaN(count) || count < 1) count = 2;
    if (count > 4) count = 4;
    return count;
};

MetisPropsPanel.prototype._normalizeColumnRatios = function(raw, count) {
    var target = this._normalizeColumnCount(count);
    var parts = [];
    if (Array.isArray(raw)) {
        parts = raw.slice(0);
    } else {
        parts = String(raw || '').split(',');
    }

    var ratios = [];
    for (var i = 0; i < parts.length; i++) {
        var n = parseFloat(String(parts[i]).trim());
        if (!Number.isFinite(n) || n <= 0) continue;
        ratios.push(Number(n.toFixed(4)));
    }

    while (ratios.length < target) ratios.push(1);
    if (ratios.length > target) ratios = ratios.slice(0, target);
    if (!ratios.length) {
        for (var x = 0; x < target; x++) ratios.push(1);
    }
    return ratios;
};

MetisPropsPanel.prototype._ratiosToInput = function(raw, count) {
    return this._normalizeColumnRatios(raw, count).join(', ');
};

MetisPropsPanel.prototype._syncColumnConfig = function(block) {
    if (!block || !block.data) return;
    var type = String(block.type || '');
    if (type !== 'grid' && type !== 'columns' && type !== 'advanced_columns') return;

    var count = this._normalizeColumnCount(block.data.columns);
    block.data.columns = count;
    if (!Array.isArray(block.data.col_blocks)) block.data.col_blocks = [];
    block.data.col_blocks = block.data.col_blocks.map(function(col) {
        return Array.isArray(col) ? col.slice(0) : [];
    });
    while (block.data.col_blocks.length < count) block.data.col_blocks.push([]);
    if (block.data.col_blocks.length > count) block.data.col_blocks = block.data.col_blocks.slice(0, count);
    block.data.ratios = this._normalizeColumnRatios(block.data.ratios, count);
    var responsiveMode = String(block.data.responsive_mode || 'fit').toLowerCase();
    block.data.responsive_mode = responsiveMode === 'flex' ? 'flex' : 'fit';
};

window.MetisPageBuilder = MetisPageBuilder;
window.MetisPropsPanel  = MetisPropsPanel;

})(jQuery);
