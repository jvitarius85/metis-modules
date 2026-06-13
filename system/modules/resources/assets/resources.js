(function () {
    function init() {
        var root = document.querySelector('.metis-resources');
        if (!root || root.getAttribute('data-resources-initialized') === '1') return;
        root.setAttribute('data-resources-initialized', '1');

        function parseJsonAttr(name, fallback) {
            try {
                var raw = root.getAttribute(name) || '';
                return raw ? JSON.parse(raw) : fallback;
            } catch (_error) {
                return fallback;
            }
        }

        function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
        function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
        function esc(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function modalOpen(id) {
            if (window.Metis && Metis.ui && Metis.ui.modal && typeof Metis.ui.modal.form === 'function') {
                Metis.ui.modal.form(id);
            }
        }

        function modalClose(id) {
            if (window.Metis && Metis.ui && Metis.ui.modal && typeof Metis.ui.modal.close === 'function') {
                Metis.ui.modal.close(id);
            }
        }

        function toast(type, message) {
            var text = String(message || '');
            if (window.Metis && Metis.ui && Metis.ui.toast && typeof Metis.ui.toast[type] === 'function') {
                Metis.ui.toast[type](text);
                return;
            }
            if (window.Metis && Metis.toast && typeof Metis.toast[type] === 'function') {
                Metis.toast[type](text);
            }
        }

        function confirmAction(message, options) {
            if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
                return Metis.confirm.open(Object.assign({}, options || {}, { message: String(message || 'Are you sure?') }));
            }
            return Promise.resolve(false);
        }

        function setButtonBusy(button, busy, label) {
            if (!button) return;
            if (window.Metis && Metis.ui && Metis.ui.loading && typeof Metis.ui.loading.button === 'function') {
                Metis.ui.loading.button(button, !!busy, { label: label || 'Saving…' });
                return;
            }
            if (busy) {
                if (!button.dataset.originalLabel) {
                    button.dataset.originalLabel = button.textContent.trim();
                }
                button.disabled = true;
                if (label) button.textContent = label;
                return;
            }
            button.disabled = false;
            if (button.dataset.originalLabel) {
                button.textContent = button.dataset.originalLabel;
                delete button.dataset.originalLabel;
            }
        }

        var canManage = root.getAttribute('data-can-manage') === '1';
        var canDelete = root.getAttribute('data-can-delete') === '1';
        var ajax = window.metisResourcesAjax || window.metisAjax || null;
        var state = parseJsonAttr('data-snapshot', {
            types: [],
            categories: [],
            tags: [],
            resources: [],
            type_options: [],
            category_options: [],
            tag_options: [],
            stats: {}
        });

        var regions = {
            resources: qs('#metis-resources-list-region'),
            types: qs('#metis-resources-types-region'),
            categories: qs('#metis-resources-categories-region'),
            tags: qs('#metis-resources-tags-region')
        };
        var statsEls = qsa('.metis-resources-stat-value', root);

        var typeModal = qs('#metis-resources-type-modal');
        var typeForm = qs('#metis-resources-type-form');
        var categoryModal = qs('#metis-resources-category-modal');
        var categoryForm = qs('#metis-resources-category-form');
        var tagModal = qs('#metis-resources-tag-modal');
        var tagForm = qs('#metis-resources-tag-form');
        var resourceModal = qs('#metis-resources-resource-modal');
        var resourceForm = qs('#metis-resources-resource-form');
        var attachmentsField = qs('#metis-resources-existing-attachments-json');
        var attachmentsList = qs('#metis-resources-attachments-list');
        var logoPreview = qs('#metis-resources-logo-preview');

        function requestPost(action, payload) {
            if (!(window.Metis && Metis.request && typeof Metis.request.post === 'function')) {
                return Promise.reject(new Error('Resources AJAX is not configured.'));
            }
            return Metis.request.post(ajax, action, payload || {}, 'Resources AJAX is not configured.');
        }

        function requestPostForm(action, formData) {
            if (!(window.Metis && Metis.request && typeof Metis.request.postForm === 'function')) {
                return Promise.reject(new Error('Resources AJAX is not configured.'));
            }
            return Metis.request.postForm(ajax, action, formData, 'Resources AJAX is not configured.');
        }

        function typeName(typeId) {
            var match = (state.types || []).find(function (row) { return Number(row.id) === Number(typeId); });
            return match ? String(match.name || '') : '';
        }

        function updateStateFromResponse(resp) {
            if (!resp || !resp.snapshot || typeof resp.snapshot !== 'object') return;
            state = resp.snapshot;
            root.setAttribute('data-snapshot', JSON.stringify(state));
            renderAll();
        }

        function renderStats() {
            var stats = state.stats || {};
            if (statsEls[0]) statsEls[0].textContent = String(stats.types || (state.types || []).length || 0);
            if (statsEls[1]) statsEls[1].textContent = String(stats.published_resources || 0);
            if (statsEls[2]) statsEls[2].textContent = String(stats.categories || (state.categories || []).length || 0);
            if (statsEls[3]) statsEls[3].textContent = String(stats.tags || (state.tags || []).length || 0);
        }

        function actionButtons(kind, id) {
            if (!canManage) return '';
            var html = '<button type="button" class="metis-btn-xs" data-edit-kind="' + esc(kind) + '" data-edit-id="' + esc(id) + '">Edit</button>';
            if (canDelete) {
                html += ' <button type="button" class="metis-btn-xs metis-btn-ghost" data-delete-kind="' + esc(kind) + '" data-delete-id="' + esc(id) + '">Delete</button>';
            }
            return html;
        }

        function renderResources() {
            var items = Array.isArray(state.resources) ? state.resources : [];
            if (!regions.resources) return;
            if (!items.length) {
                regions.resources.innerHTML = '<div class="metis-empty-state metis-resources-empty-state"><div class="metis-empty-state-icon" aria-hidden="true">&#128214;</div><h2>No resources yet</h2><p>Start by creating a resource entry with categories, tags, files, and service area details.</p>' + (canManage ? '<div class="metis-resources-empty-actions"><button type="button" class="metis-btn metis-btn-primary" data-resource-open="new">New Resource</button></div>' : '') + '</div>';
                return;
            }
            regions.resources.innerHTML = '<table class="metis-premium-table metis-resources-table metis-resources-table--resources"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell">Resource</th><th class="metis-premium-cell">Type</th><th class="metis-premium-cell">Categories</th><th class="metis-premium-cell">Status</th>' + (canManage ? '<th class="metis-premium-cell">Actions</th>' : '') + '</tr></thead><tbody>' + items.map(function (row) {
                var summary = String(row.summary || '');
                var categories = Array.isArray(row.category_names) && row.category_names.length ? row.category_names.map(function (name) { return '<span class="metis-resources-chip">' + esc(name) + '</span>'; }).join('') : '<span class="metis-muted">No categories</span>';
                return '<tr class="metis-premium-row"><td class="metis-premium-cell"><div class="metis-resources-meta-stack"><strong>' + esc(row.title || '') + '</strong><span class="metis-muted">' + esc(summary ? summary.slice(0, 140) : (row.organization_name || '')) + '</span></div></td><td class="metis-premium-cell">' + esc(row.type_name || '') + '</td><td class="metis-premium-cell"><div class="metis-resources-chip-row">' + categories + '</div></td><td class="metis-premium-cell">' + esc(row.status || 'draft') + (row.is_featured ? ' • Featured' : '') + '</td>' + (canManage ? '<td class="metis-premium-cell">' + actionButtons('resource', row.id || 0) + '</td>' : '') + '</tr>';
            }).join('') + '</tbody></table>';
        }

        function renderTypes() {
            var items = Array.isArray(state.types) ? state.types : [];
            if (!regions.types) return;
            if (!items.length) {
                regions.types.innerHTML = '<div class="metis-empty-state metis-resources-empty-state"><div class="metis-empty-state-icon" aria-hidden="true">&#129489;</div><h2>No resource types yet</h2><p>Create the top-level directories first so categories, tags, and resources have a clear public structure.</p></div>';
                return;
            }
            regions.types.innerHTML = '<table class="metis-premium-table metis-resources-table metis-resources-table--types"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell">Type</th><th class="metis-premium-cell">Slug</th><th class="metis-premium-cell">Status</th>' + (canManage ? '<th class="metis-premium-cell">Actions</th>' : '') + '</tr></thead><tbody>' + items.map(function (row) {
                return '<tr class="metis-premium-row"><td class="metis-premium-cell"><div class="metis-resources-meta-stack"><strong>' + esc(row.name || '') + '</strong><span class="metis-muted">' + esc(row.seo_title || 'Public directory group') + '</span></div></td><td class="metis-premium-cell">' + esc(row.slug || '') + '</td><td class="metis-premium-cell">' + (row.is_active ? 'Active' : 'Inactive') + '</td>' + (canManage ? '<td class="metis-premium-cell">' + actionButtons('type', row.id || 0) + '</td>' : '') + '</tr>';
            }).join('') + '</tbody></table>';
        }

        function renderCategories() {
            var items = Array.isArray(state.categories) ? state.categories : [];
            if (!regions.categories) return;
            if (!items.length) {
                regions.categories.innerHTML = '<div class="metis-empty-state metis-resources-empty-state"><div class="metis-empty-state-icon" aria-hidden="true">&#128193;</div><h2>No categories yet</h2><p>Add scoped categories such as legal aid, transportation, or benefits enrollment within each type.</p></div>';
                return;
            }
            regions.categories.innerHTML = '<table class="metis-premium-table metis-resources-table metis-resources-table--categories"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell">Category</th><th class="metis-premium-cell">Type</th><th class="metis-premium-cell">Status</th>' + (canManage ? '<th class="metis-premium-cell">Actions</th>' : '') + '</tr></thead><tbody>' + items.map(function (row) {
                return '<tr class="metis-premium-row"><td class="metis-premium-cell"><div class="metis-resources-meta-stack"><strong>' + esc(row.name || '') + '</strong><span class="metis-muted">' + esc(row.slug || '') + '</span></div></td><td class="metis-premium-cell">' + esc(row.type_name || '') + '</td><td class="metis-premium-cell">' + (row.is_active ? 'Active' : 'Inactive') + '</td>' + (canManage ? '<td class="metis-premium-cell">' + actionButtons('category', row.id || 0) + '</td>' : '') + '</tr>';
            }).join('') + '</tbody></table>';
        }

        function renderTags() {
            var items = Array.isArray(state.tags) ? state.tags : [];
            if (!regions.tags) return;
            if (!items.length) {
                regions.tags.innerHTML = '<div class="metis-empty-state metis-resources-empty-state"><div class="metis-empty-state-icon" aria-hidden="true">&#35;</div><h2>No tags yet</h2><p>Create scoped tags for finer public filtering without multiplying archive pages.</p></div>';
                return;
            }
            regions.tags.innerHTML = '<table class="metis-premium-table metis-resources-table metis-resources-table--tags"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell">Tag</th><th class="metis-premium-cell">Type</th><th class="metis-premium-cell">Status</th>' + (canManage ? '<th class="metis-premium-cell">Actions</th>' : '') + '</tr></thead><tbody>' + items.map(function (row) {
                return '<tr class="metis-premium-row"><td class="metis-premium-cell"><div class="metis-resources-meta-stack"><strong>' + esc(row.name || '') + '</strong><span class="metis-muted">' + esc(row.slug || '') + '</span></div></td><td class="metis-premium-cell">' + esc(row.type_name || '') + '</td><td class="metis-premium-cell">' + (row.is_active ? 'Active' : 'Inactive') + '</td>' + (canManage ? '<td class="metis-premium-cell">' + actionButtons('tag', row.id || 0) + '</td>' : '') + '</tr>';
            }).join('') + '</tbody></table>';
        }

        function renderAll() {
            renderStats();
            renderResources();
            renderTypes();
            renderCategories();
            renderTags();
        }

        function fillSelect(select, items, selectedValue, blankLabel) {
            if (!select) return;
            var html = '';
            if (blankLabel) {
                html += '<option value="">' + esc(blankLabel) + '</option>';
            }
            html += (items || []).map(function (row) {
                var value = String(row.id || row.value || '');
                return '<option value="' + esc(value) + '"' + (String(selectedValue || '') === value ? ' selected' : '') + '>' + esc(row.name || row.label || value) + '</option>';
            }).join('');
            select.innerHTML = html;
        }

        function activeCategoriesForType(typeId) {
            return (state.categories || []).filter(function (row) {
                return Number(row.resource_type_id) === Number(typeId) && Number(row.is_active) === 1;
            });
        }

        function activeTagsForType(typeId) {
            return (state.tags || []).filter(function (row) {
                return Number(row.resource_type_id) === Number(typeId) && Number(row.is_active) === 1;
            });
        }

        function renderRelationCheckboxes(typeId, selectedCategoryIds, selectedTagIds, primaryCategoryId) {
            selectedCategoryIds = Array.isArray(selectedCategoryIds) ? selectedCategoryIds.map(String) : [];
            selectedTagIds = Array.isArray(selectedTagIds) ? selectedTagIds.map(String) : [];
            var categoryRows = activeCategoriesForType(typeId);
            var tagRows = activeTagsForType(typeId);
            var primaryCategorySelect = resourceForm ? resourceForm.elements.primary_category_id : null;
            fillSelect(primaryCategorySelect, categoryRows, String(primaryCategoryId || ''), 'Choose a primary category');
            var categoryTarget = qs('#metis-resources-category-checkboxes');
            var tagTarget = qs('#metis-resources-tag-checkboxes');
            if (categoryTarget) {
                categoryTarget.innerHTML = categoryRows.length ? categoryRows.map(function (row) {
                    var id = String(row.id || '');
                    return '<label class="metis-se-check-label"><input type="checkbox" name="category_ids[]" value="' + esc(id) + '"' + (selectedCategoryIds.indexOf(id) !== -1 ? ' checked' : '') + '> ' + esc(row.name || '') + '</label>';
                }).join('') : '<div class="metis-muted">No active categories for this type yet.</div>';
            }
            if (tagTarget) {
                tagTarget.innerHTML = tagRows.length ? tagRows.map(function (row) {
                    var id = String(row.id || '');
                    return '<label class="metis-se-check-label"><input type="checkbox" name="tag_ids[]" value="' + esc(id) + '"' + (selectedTagIds.indexOf(id) !== -1 ? ' checked' : '') + '> ' + esc(row.name || '') + '</label>';
                }).join('') : '<div class="metis-muted">No active tags for this type yet.</div>';
            }
        }

        function normalizeDateTimeLocal(value) {
            var text = String(value || '').trim();
            return text ? text.replace(' ', 'T').slice(0, 16) : '';
        }

        function syncAttachmentsField(list) {
            if (attachmentsField) attachmentsField.value = JSON.stringify(Array.isArray(list) ? list : []);
            renderAttachments(Array.isArray(list) ? list : []);
        }

        function renderAttachments(list) {
            if (!attachmentsList) return;
            if (!Array.isArray(list) || !list.length) {
                attachmentsList.innerHTML = '<div class="metis-muted">No existing files attached.</div>';
                return;
            }
            attachmentsList.innerHTML = list.map(function (row, index) {
                var name = String(row && row.name || row && row.url || 'Attachment');
                var url = String(row && row.url || '');
                return '<div class="metis-resources-attachment"><span>' + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(name) + '</a>' : esc(name)) + '</span>' + (canManage ? '<button type="button" class="metis-btn-xs metis-btn-ghost" data-remove-attachment="' + index + '">Remove</button>' : '') + '</div>';
            }).join('');
        }

        function setLogoPreview(url) {
            if (!logoPreview) return;
            if (!url) {
                logoPreview.innerHTML = '<span class="metis-muted">No logo uploaded.</span>';
                return;
            }
            logoPreview.innerHTML = '<img src="' + esc(url) + '" alt="">';
        }

        function initSharedRichEditor(toolbarSelector, editorSelector, hiddenSelector) {
            var toolbar = qs(toolbarSelector);
            var editor = qs(editorSelector);
            var hidden = qs(hiddenSelector);
            if (!toolbar || !editor || !hidden) return;
            if (editor.getAttribute('data-rich-ready') === '1') return;
            editor.setAttribute('data-rich-ready', '1');
            if (window.Metis && Metis.ui && Metis.ui.richText) {
                editor.innerHTML = Metis.ui.richText.normalizeHtml(String(editor.innerHTML || ''));
                Metis.ui.richText.bindIconFallbacks(editor);
            }
            function syncHidden() {
                hidden.value = String(editor.innerHTML || '');
            }
            editor.addEventListener('input', syncHidden);
            toolbar.addEventListener('click', function (event) {
                var button = event.target.closest('[data-rich-cmd],[data-rich-action]');
                if (!button || !(window.Metis && Metis.ui && Metis.ui.richText)) return;
                event.preventDefault();
                Metis.ui.richText.saveSelection(editor);
                if (button.hasAttribute('data-rich-action')) {
                    Metis.ui.richText.applyAction(editor, String(button.getAttribute('data-rich-action') || ''), String(button.getAttribute('data-rich-value') || ''), '');
                } else {
                    Metis.ui.richText.applyCommand(editor, String(button.getAttribute('data-rich-cmd') || ''), String(button.getAttribute('data-rich-value') || ''));
                }
                syncHidden();
            });
        }

        function setRichEditorContent(editorSelector, hiddenSelector, value) {
            var editor = qs(editorSelector);
            var hidden = qs(hiddenSelector);
            if (!editor || !hidden) return;
            var html = String(value || '');
            hidden.value = html;
            editor.innerHTML = html;
            if (window.Metis && Metis.ui && Metis.ui.richText) {
                editor.innerHTML = Metis.ui.richText.normalizeHtml(html);
            }
        }

        function openTypeModal(item) {
            if (!typeForm) return;
            var row = item || { id: 0, name: '', slug: '', sort_order: 0, is_active: 1, intro_html: '', seo_title: '', seo_description: '' };
            typeForm.reset();
            typeForm.elements.id.value = String(row.id || 0);
            typeForm.elements.name.value = String(row.name || '');
            typeForm.elements.slug.value = String(row.slug || '');
            typeForm.elements.sort_order.value = String(row.sort_order || 0);
            typeForm.elements.is_active.checked = Number(row.is_active || 0) === 1;
            typeForm.elements.seo_title.value = String(row.seo_title || '');
            typeForm.elements.seo_description.value = String(row.seo_description || '');
            setRichEditorContent('#metis-resources-type-intro-editor', '#metis-resources-type-intro-html', row.intro_html || '');
            modalOpen('metis-resources-type-modal');
        }

        function openCategoryModal(item) {
            if (!categoryForm) return;
            var row = item || { id: 0, resource_type_id: '', name: '', slug: '', sort_order: 0, is_active: 1, intro_html: '', seo_title: '', seo_description: '' };
            categoryForm.reset();
            fillSelect(categoryForm.elements.resource_type_id, state.type_options || [], String(row.resource_type_id || ''), 'Choose a resource type');
            categoryForm.elements.id.value = String(row.id || 0);
            categoryForm.elements.name.value = String(row.name || '');
            categoryForm.elements.slug.value = String(row.slug || '');
            categoryForm.elements.sort_order.value = String(row.sort_order || 0);
            categoryForm.elements.is_active.checked = Number(row.is_active || 0) === 1;
            categoryForm.elements.seo_title.value = String(row.seo_title || '');
            categoryForm.elements.seo_description.value = String(row.seo_description || '');
            setRichEditorContent('#metis-resources-category-intro-editor', '#metis-resources-category-intro-html', row.intro_html || '');
            modalOpen('metis-resources-category-modal');
        }

        function openTagModal(item) {
            if (!tagForm) return;
            var row = item || { id: 0, resource_type_id: '', name: '', slug: '', sort_order: 0, is_active: 1 };
            tagForm.reset();
            fillSelect(tagForm.elements.resource_type_id, state.type_options || [], String(row.resource_type_id || ''), 'Choose a resource type');
            tagForm.elements.id.value = String(row.id || 0);
            tagForm.elements.name.value = String(row.name || '');
            tagForm.elements.slug.value = String(row.slug || '');
            tagForm.elements.sort_order.value = String(row.sort_order || 0);
            tagForm.elements.is_active.checked = Number(row.is_active || 0) === 1;
            modalOpen('metis-resources-tag-modal');
        }

        function openResourceModal(item) {
            if (!resourceForm) return;
            var row = item || {
                id: 0,
                resource_type_id: '',
                status: 'draft',
                title: '',
                slug: '',
                organization_name: '',
                sort_order: 0,
                is_featured: 0,
                summary: '',
                description_html: '',
                primary_category_id: '',
                website_url: '',
                phone: '',
                email: '',
                existing_logo_token: '',
                logo_media_token: '',
                existing_logo_url: '',
                logo_url: '',
                category_ids: [],
                tag_ids: [],
                attachments: [],
                address_line1: '',
                city: '',
                state_code: '',
                county: '',
                postal_code: '',
                service_radius: '',
                is_online: 0,
                review_due_at: '',
                expires_at: '',
                eligibility_notes: ''
            };
            resourceForm.reset();
            fillSelect(resourceForm.elements.resource_type_id, state.type_options || [], String(row.resource_type_id || ''), 'Choose a resource type');
            resourceForm.elements.id.value = String(row.id || 0);
            resourceForm.elements.status.value = String(row.status || 'draft');
            resourceForm.elements.title.value = String(row.title || '');
            resourceForm.elements.slug.value = String(row.slug || '');
            resourceForm.elements.organization_name.value = String(row.organization_name || '');
            resourceForm.elements.sort_order.value = String(row.sort_order || 0);
            resourceForm.elements.is_featured.checked = Number(row.is_featured || 0) === 1;
            resourceForm.elements.summary.value = String(row.summary || '');
            resourceForm.elements.website_url.value = String(row.website_url || '');
            resourceForm.elements.phone.value = String(row.phone || '');
            resourceForm.elements.email.value = String(row.email || '');
            resourceForm.elements.address_line1.value = String(row.address_line1 || '');
            resourceForm.elements.city.value = String(row.city || '');
            resourceForm.elements.state_code.value = String(row.state_code || '');
            resourceForm.elements.county.value = String(row.county || '');
            resourceForm.elements.postal_code.value = String(row.postal_code || '');
            resourceForm.elements.service_radius.value = String(row.service_radius || '');
            resourceForm.elements.is_online.checked = Number(row.is_online || 0) === 1;
            resourceForm.elements.review_due_at.value = normalizeDateTimeLocal(row.review_due_at || '');
            resourceForm.elements.expires_at.value = normalizeDateTimeLocal(row.expires_at || '');
            resourceForm.elements.eligibility_notes.value = String(row.eligibility_notes || '');
            resourceForm.elements.existing_logo_token.value = String(row.logo_media_token || row.existing_logo_token || '');
            resourceForm.elements.existing_logo_url.value = String(row.logo_url || row.existing_logo_url || '');
            setRichEditorContent('#metis-resources-description-editor', '#metis-resources-description-html', row.description_html || '');
            renderRelationCheckboxes(Number(row.resource_type_id || 0), row.category_ids || [], row.tag_ids || [], row.primary_category_id || '');
            syncAttachmentsField(Array.isArray(row.attachments) ? row.attachments : []);
            setLogoPreview(String(row.logo_url || row.existing_logo_url || ''));
            modalOpen('metis-resources-resource-modal');
        }

        function handleEdit(kind, id) {
            var collection = [];
            if (kind === 'type') collection = state.types || [];
            if (kind === 'category') collection = state.categories || [];
            if (kind === 'tag') collection = state.tags || [];
            if (kind === 'resource') collection = state.resources || [];
            var item = collection.find(function (row) { return Number(row.id) === Number(id); }) || null;
            if (kind === 'type') openTypeModal(item);
            if (kind === 'category') openCategoryModal(item);
            if (kind === 'tag') openTagModal(item);
            if (kind === 'resource') openResourceModal(item);
        }

        function handleDelete(kind, id) {
            var actionMap = {
                type: 'metis_resources_type_delete',
                category: 'metis_resources_category_delete',
                tag: 'metis_resources_tag_delete',
                resource: 'metis_resources_resource_delete'
            };
            var action = actionMap[kind];
            if (!action || !id) return;
            confirmAction('Delete this ' + kind + '?', {
                title: 'Delete ' + kind.charAt(0).toUpperCase() + kind.slice(1),
                confirmLabel: 'Delete',
                cancelLabel: 'Cancel',
                tone: 'danger'
            }).then(function (confirmed) {
                if (!confirmed) return null;
                return requestPost(action, { id: String(id) });
            }).then(function (resp) {
                if (!resp) return;
                updateStateFromResponse(resp);
                toast('success', kind.charAt(0).toUpperCase() + kind.slice(1) + ' deleted.');
            }).catch(function (err) {
                toast('error', err.message || 'Delete failed.');
            });
        }

        root.addEventListener('click', function (event) {
            var openType = event.target.closest('[data-resource-type-open]');
            if (openType) {
                event.preventDefault();
                openTypeModal(null);
                return;
            }
            var openCategory = event.target.closest('[data-resource-category-open]');
            if (openCategory) {
                event.preventDefault();
                openCategoryModal(null);
                return;
            }
            var openTag = event.target.closest('[data-resource-tag-open]');
            if (openTag) {
                event.preventDefault();
                openTagModal(null);
                return;
            }
            var openResource = event.target.closest('[data-resource-open]');
            if (openResource) {
                event.preventDefault();
                openResourceModal(null);
                return;
            }
            var editButton = event.target.closest('[data-edit-kind]');
            if (editButton) {
                event.preventDefault();
                handleEdit(String(editButton.getAttribute('data-edit-kind') || ''), parseInt(String(editButton.getAttribute('data-edit-id') || '0'), 10) || 0);
                return;
            }
            var deleteButton = event.target.closest('[data-delete-kind]');
            if (deleteButton) {
                event.preventDefault();
                handleDelete(String(deleteButton.getAttribute('data-delete-kind') || ''), parseInt(String(deleteButton.getAttribute('data-delete-id') || '0'), 10) || 0);
                return;
            }
        });

        attachmentsList && attachmentsList.addEventListener('click', function (event) {
            var removeButton = event.target.closest('[data-remove-attachment]');
            if (!removeButton) return;
            event.preventDefault();
            var list = [];
            try {
                list = JSON.parse(String(attachmentsField && attachmentsField.value || '[]'));
            } catch (_error) {
                list = [];
            }
            var index = parseInt(String(removeButton.getAttribute('data-remove-attachment') || '-1'), 10);
            if (index < 0) return;
            list.splice(index, 1);
            syncAttachmentsField(list);
        });

        if (resourceForm && resourceForm.elements.resource_type_id) {
            resourceForm.elements.resource_type_id.addEventListener('change', function () {
                renderRelationCheckboxes(parseInt(String(resourceForm.elements.resource_type_id.value || '0'), 10) || 0, [], [], '');
            });
        }

        if (typeForm) {
            typeForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var submitButton = typeForm.querySelector('button[type="submit"]');
                setButtonBusy(submitButton, true, 'Saving…');
                var payload = {
                    type: JSON.stringify({
                        id: parseInt(String(typeForm.elements.id.value || '0'), 10) || 0,
                        name: typeForm.elements.name.value || '',
                        slug: typeForm.elements.slug.value || '',
                        sort_order: parseInt(String(typeForm.elements.sort_order.value || '0'), 10) || 0,
                        is_active: typeForm.elements.is_active.checked ? 1 : 0,
                        intro_html: typeForm.elements.intro_html.value || '',
                        seo_title: typeForm.elements.seo_title.value || '',
                        seo_description: typeForm.elements.seo_description.value || ''
                    })
                };
                requestPost('metis_resources_type_save', payload).then(function (resp) {
                    updateStateFromResponse(resp);
                    modalClose('metis-resources-type-modal');
                    toast('success', 'Resource type saved.');
                }).catch(function (err) {
                    toast('error', err.message || 'Type save failed.');
                }).finally(function () {
                    setButtonBusy(submitButton, false);
                });
            });
        }

        if (categoryForm) {
            categoryForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var submitButton = categoryForm.querySelector('button[type="submit"]');
                setButtonBusy(submitButton, true, 'Saving…');
                var payload = {
                    category: JSON.stringify({
                        id: parseInt(String(categoryForm.elements.id.value || '0'), 10) || 0,
                        resource_type_id: parseInt(String(categoryForm.elements.resource_type_id.value || '0'), 10) || 0,
                        name: categoryForm.elements.name.value || '',
                        slug: categoryForm.elements.slug.value || '',
                        sort_order: parseInt(String(categoryForm.elements.sort_order.value || '0'), 10) || 0,
                        is_active: categoryForm.elements.is_active.checked ? 1 : 0,
                        intro_html: categoryForm.elements.intro_html.value || '',
                        seo_title: categoryForm.elements.seo_title.value || '',
                        seo_description: categoryForm.elements.seo_description.value || ''
                    })
                };
                requestPost('metis_resources_category_save', payload).then(function (resp) {
                    updateStateFromResponse(resp);
                    modalClose('metis-resources-category-modal');
                    toast('success', 'Category saved.');
                }).catch(function (err) {
                    toast('error', err.message || 'Category save failed.');
                }).finally(function () {
                    setButtonBusy(submitButton, false);
                });
            });
        }

        if (tagForm) {
            tagForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var submitButton = tagForm.querySelector('button[type="submit"]');
                setButtonBusy(submitButton, true, 'Saving…');
                var payload = {
                    tag: JSON.stringify({
                        id: parseInt(String(tagForm.elements.id.value || '0'), 10) || 0,
                        resource_type_id: parseInt(String(tagForm.elements.resource_type_id.value || '0'), 10) || 0,
                        name: tagForm.elements.name.value || '',
                        slug: tagForm.elements.slug.value || '',
                        sort_order: parseInt(String(tagForm.elements.sort_order.value || '0'), 10) || 0,
                        is_active: tagForm.elements.is_active.checked ? 1 : 0
                    })
                };
                requestPost('metis_resources_tag_save', payload).then(function (resp) {
                    updateStateFromResponse(resp);
                    modalClose('metis-resources-tag-modal');
                    toast('success', 'Tag saved.');
                }).catch(function (err) {
                    toast('error', err.message || 'Tag save failed.');
                }).finally(function () {
                    setButtonBusy(submitButton, false);
                });
            });
        }

        if (resourceForm) {
            resourceForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var submitButton = resourceForm.querySelector('button[type="submit"]');
                setButtonBusy(submitButton, true, 'Saving…');
                var formData = new FormData(resourceForm);
                var resource = {
                    id: parseInt(String(formData.get('id') || '0'), 10) || 0,
                    resource_type_id: parseInt(String(formData.get('resource_type_id') || '0'), 10) || 0,
                    status: String(formData.get('status') || 'draft'),
                    title: String(formData.get('title') || ''),
                    slug: String(formData.get('slug') || ''),
                    organization_name: String(formData.get('organization_name') || ''),
                    sort_order: parseInt(String(formData.get('sort_order') || '0'), 10) || 0,
                    is_featured: formData.get('is_featured') ? 1 : 0,
                    summary: String(formData.get('summary') || ''),
                    description_html: String(formData.get('description_html') || ''),
                    primary_category_id: parseInt(String(formData.get('primary_category_id') || '0'), 10) || 0,
                    website_url: String(formData.get('website_url') || ''),
                    phone: String(formData.get('phone') || ''),
                    email: String(formData.get('email') || ''),
                    existing_logo_token: String(formData.get('existing_logo_token') || ''),
                    existing_logo_url: String(formData.get('existing_logo_url') || ''),
                    category_ids: formData.getAll('category_ids[]'),
                    tag_ids: formData.getAll('tag_ids[]'),
                    existing_attachments_json: String(formData.get('existing_attachments_json') || '[]'),
                    address_line1: String(formData.get('address_line1') || ''),
                    city: String(formData.get('city') || ''),
                    state_code: String(formData.get('state_code') || ''),
                    county: String(formData.get('county') || ''),
                    postal_code: String(formData.get('postal_code') || ''),
                    service_radius: String(formData.get('service_radius') || ''),
                    is_online: formData.get('is_online') ? 1 : 0,
                    review_due_at: String(formData.get('review_due_at') || '').replace('T', ' '),
                    expires_at: String(formData.get('expires_at') || '').replace('T', ' '),
                    eligibility_notes: String(formData.get('eligibility_notes') || '')
                };
                var body = new FormData();
                body.set('resource', JSON.stringify(resource));
                var logoFile = resourceForm.elements.logo_file && resourceForm.elements.logo_file.files ? resourceForm.elements.logo_file.files[0] : null;
                if (logoFile) {
                    body.append('logo_file', logoFile);
                }
                var fileInput = resourceForm.querySelector('input[name="resource_files[]"]');
                if (fileInput && fileInput.files && fileInput.files.length) {
                    Array.prototype.forEach.call(fileInput.files, function (file) {
                        body.append('resource_files[]', file);
                    });
                }
                requestPostForm('metis_resources_resource_save', body).then(function (resp) {
                    updateStateFromResponse(resp);
                    modalClose('metis-resources-resource-modal');
                    toast('success', 'Resource saved.');
                }).catch(function (err) {
                    toast('error', err.message || 'Resource save failed.');
                }).finally(function () {
                    setButtonBusy(submitButton, false);
                });
            });
        }

        initSharedRichEditor('[data-rich-toolbar="resources-type-intro"]', '#metis-resources-type-intro-editor', '#metis-resources-type-intro-html');
        initSharedRichEditor('[data-rich-toolbar="resources-category-intro"]', '#metis-resources-category-intro-editor', '#metis-resources-category-intro-html');
        initSharedRichEditor('[data-rich-toolbar="resources-description"]', '#metis-resources-description-editor', '#metis-resources-description-html');
        renderAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
