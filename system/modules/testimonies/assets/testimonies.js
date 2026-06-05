(function () {
    function init() {
    var root = document.querySelector('.metis-testimonies');
    if (!root) return;
    if (root.getAttribute('data-testimonies-initialized') === '1') return;
    root.setAttribute('data-testimonies-initialized', '1');

    function parseJsonAttr(name, fallback) {
        try {
            var raw = root.getAttribute(name) || '';
            return raw ? JSON.parse(raw) : fallback;
        } catch (_err) {
            return fallback;
        }
    }

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
    function show(el) {
        if (!el) return;
        if (window.Metis && Metis.modal && typeof Metis.modal.open === 'function') {
            Metis.modal.open(el);
            return;
        }
        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        el.classList.add('is-open', 'metis-open');
    }
    function hide(el) {
        if (!el) return;
        if (window.Metis && Metis.modal && typeof Metis.modal.close === 'function') {
            Metis.modal.close(el);
            return;
        }
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        el.classList.remove('is-open', 'metis-open');
    }
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var canManage = root.getAttribute('data-can-manage') === '1';
    var canDelete = root.getAttribute('data-can-delete') === '1';
    var categories = parseJsonAttr('data-category-options', []);
    var testimonies = parseJsonAttr('data-testimony-items', []);
    var categoryItems = parseJsonAttr('data-category-items', []);
    var ajax = window.metisTestimoniesAjax || null;
    var testimonyModal = qs('#metis-testimony-modal');
    var testimonyForm = qs('#metis-testimony-form');
    var categoryModal = qs('#metis-testimony-category-modal');
    var categoryForm = qs('#metis-testimony-category-form');

    function toast(type, message) {
        var kind = String(type || 'info');
        var text = String(message || '');
        if (window.Metis && Metis.ui && Metis.ui.toast && typeof Metis.ui.toast[kind] === 'function') {
            Metis.ui.toast[kind](text);
            return;
        }
        if (window.Metis && Metis.toast && typeof Metis.toast[kind] === 'function') {
            Metis.toast[kind](text);
            return;
        }
        if (window.Metis && Metis.util && typeof Metis.util.notify === 'function') {
            Metis.util.notify(text, kind);
        }
    }

    function confirmAction(message, options) {
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({}, options || {}, {
                message: String(message || 'Are you sure?')
            }));
        }
        return Promise.resolve(false);
    }

    function post(action, payload) {
        if (!(window.Metis && Metis.request && typeof Metis.request.post === 'function')) {
            return Promise.reject(new Error('Testimonies request service is not available.'));
        }
        return Metis.request.post(ajax, action, payload || {}, 'Testimonies AJAX not configured.');
    }

    function categoryCheckboxes(selectedIds) {
        selectedIds = Array.isArray(selectedIds) ? selectedIds.map(function (id) { return String(id); }) : [];
        return categories.map(function (row) {
            var value = String(row && row.value || '');
            return '<label class="metis-se-check-label"><input type="checkbox" name="category_ids[]" value="' + esc(value) + '"' + (selectedIds.indexOf(value) !== -1 ? ' checked' : '') + '> ' + esc(row && row.label || value) + '</label>';
        }).join('');
    }

    function openTestimonyModal(item) {
        if (!testimonyForm) return;
        var data = item || { id: 0, speaker_name: '', speaker_title: '', speaker_company: '', quote_text: '', source_notes: '', status: 'draft', sort_order: 0, is_featured: false, category_ids: [] };
        testimonyForm.reset();
        testimonyForm.elements.id.value = String(data.id || 0);
        testimonyForm.elements.speaker_name.value = data.speaker_name || '';
        testimonyForm.elements.speaker_title.value = data.speaker_title || '';
        testimonyForm.elements.speaker_company.value = data.speaker_company || '';
        testimonyForm.elements.quote_text.value = data.quote_text || '';
        testimonyForm.elements.source_notes.value = data.source_notes || '';
        testimonyForm.elements.status.value = data.status || 'draft';
        testimonyForm.elements.sort_order.value = String(data.sort_order || 0);
        testimonyForm.elements.is_featured.checked = !!data.is_featured;
        qs('#metis-testimony-category-checkboxes').innerHTML = categoryCheckboxes(data.category_ids || []);
        show(testimonyModal);
    }

    function openCategoryModal(item) {
        if (!categoryForm) return;
        var data = item || { id: 0, name: '', slug: '', sort_order: 0, is_active: true };
        categoryForm.reset();
        categoryForm.elements.id.value = String(data.id || 0);
        categoryForm.elements.name.value = data.name || '';
        categoryForm.elements.slug.value = data.slug || '';
        categoryForm.elements.sort_order.value = String(data.sort_order || 0);
        categoryForm.elements.is_active.checked = !!data.is_active;
        show(categoryModal);
    }

    function bindActions() {
        qsa('[data-testimony-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-testimony-edit') || '0', 10) || 0;
                openTestimonyModal(testimonies.find(function (row) { return Number(row.id) === id; }) || null);
            });
        });
        qsa('[data-category-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-category-edit') || '0', 10) || 0;
                openCategoryModal(categoryItems.find(function (row) { return Number(row.id) === id; }) || null);
            });
        });
        if (canDelete) {
            qsa('[data-testimony-delete]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-testimony-delete') || '0', 10) || 0;
                    if (!id) return;
                    confirmAction('Delete this testimony?', {
                        title: 'Delete Testimony',
                        confirmLabel: 'Delete',
                        cancelLabel: 'Cancel',
                        tone: 'danger'
                    }).then(function (confirmed) {
                        if (!confirmed) return null;
                        return post('metis_testimonies_delete', { testimony_id: String(id) });
                    }).then(function (resp) {
                        if (!resp) return;
                        toast('success', 'Testimony deleted.');
                        window.location.reload();
                    }).catch(function (err) { toast('error', err.message || 'Delete failed.'); });
                });
            });
            qsa('[data-category-delete]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-category-delete') || '0', 10) || 0;
                    if (!id) return;
                    confirmAction('Delete this category?', {
                        title: 'Delete Category',
                        confirmLabel: 'Delete',
                        cancelLabel: 'Cancel',
                        tone: 'danger'
                    }).then(function (confirmed) {
                        if (!confirmed) return null;
                        return post('metis_testimony_categories_delete', { category_id: String(id) });
                    }).then(function (resp) {
                        if (!resp) return;
                        toast('success', 'Category deleted.');
                        window.location.reload();
                    }).catch(function (err) { toast('error', err.message || 'Delete failed.'); });
                });
            });
        }
    }

    if (canManage) {
        var openTestimony = qs('#metis-testimony-create-open');
        var openTestimonyEmpty = qs('#metis-testimony-create-open-empty');
        var openCategory = qs('#metis-testimony-category-open');
        var openCategoryEmpty = qs('#metis-testimony-category-open-empty');
        if (openTestimony) openTestimony.addEventListener('click', function () { openTestimonyModal(null); });
        if (openTestimonyEmpty) openTestimonyEmpty.addEventListener('click', function () { openTestimonyModal(null); });
        if (openCategory) openCategory.addEventListener('click', function () { openCategoryModal(null); });
        if (openCategoryEmpty) openCategoryEmpty.addEventListener('click', function () { openCategoryModal(null); });
        qs('#metis-testimony-cancel') && qs('#metis-testimony-cancel').addEventListener('click', function () { hide(testimonyModal); });
        qs('#metis-testimony-category-cancel') && qs('#metis-testimony-category-cancel').addEventListener('click', function () { hide(categoryModal); });

        testimonyForm && testimonyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(testimonyForm);
            var payload = {
                testimony: JSON.stringify({
                    id: parseInt(fd.get('id') || '0', 10) || 0,
                    speaker_name: fd.get('speaker_name') || '',
                    speaker_title: fd.get('speaker_title') || '',
                    speaker_company: fd.get('speaker_company') || '',
                    quote_text: fd.get('quote_text') || '',
                    source_notes: fd.get('source_notes') || '',
                    status: fd.get('status') || 'draft',
                    sort_order: parseInt(fd.get('sort_order') || '0', 10) || 0,
                    is_featured: fd.get('is_featured') ? 1 : 0,
                    category_ids: fd.getAll('category_ids[]')
                })
            };
            post('metis_testimonies_save', payload).then(function (resp) {
                toast('success', 'Testimony saved.');
                window.location.reload();
            }).catch(function (err) { toast('error', err.message || 'Save failed.'); });
        });

        categoryForm && categoryForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(categoryForm);
            var payload = {
                category: JSON.stringify({
                    id: parseInt(fd.get('id') || '0', 10) || 0,
                    name: fd.get('name') || '',
                    slug: fd.get('slug') || '',
                    sort_order: parseInt(fd.get('sort_order') || '0', 10) || 0,
                    is_active: fd.get('is_active') ? 1 : 0
                })
            };
            post('metis_testimony_categories_save', payload).then(function (resp) {
                toast('success', 'Category saved.');
                window.location.reload();
            }).catch(function (err) { toast('error', err.message || 'Save failed.'); });
        });
    }

    bindActions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
