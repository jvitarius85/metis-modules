document.addEventListener('DOMContentLoaded', function () {
    function refreshSettingsColorBindingSelects(scope) {
        if (!(window.Metis && Metis.ui && Metis.ui.select && typeof Metis.ui.select.refresh === 'function')) {
            return;
        }

        var root = scope || document;
        var selects = Array.prototype.slice.call(root.querySelectorAll('[data-settings-custom-color-select]'));
        if (root instanceof HTMLSelectElement && root.hasAttribute('data-settings-custom-color-select')) {
            selects.unshift(root);
        }

        selects.forEach(function (select) {
            if (!(select instanceof HTMLSelectElement)) return;
            select.dataset.metisUiSelect = '1';
            select.dataset.metisSelectTriggerClass = select.classList.contains('metis-input-sm')
                ? 'metis-input metis-input-sm'
                : 'metis-input';
            select.dataset.metisSelectVariant = 'theme-binding';
            window.Metis.ui.select.refresh(select);
        });
    }

    function syncSettingsCustomColorControl(key, refreshSelect) {
        var input = document.querySelector('[data-settings-custom-color="' + key + '"]');
        var select = document.querySelector('[data-settings-custom-color-select="' + key + '"]');
        var dot = document.querySelector('[data-settings-custom-color-dot="' + key + '"]');
        if (!input || !select || !dot) return;

        var selectedOption = select.options[select.selectedIndex] || null;
        var isCustom = String(select.value || '') === '';
        var color = isCustom
            ? String(input.value || '#ffffff')
            : String((selectedOption && selectedOption.dataset && selectedOption.dataset.metisSelectColor) || input.value || '#ffffff');

        if (select.options.length > 0) {
            select.options[0].dataset.metisSelectColor = String(input.value || '#ffffff');
        }

        dot.style.background = color;
        dot.title = isCustom ? 'Choose a custom color' : 'Switch to custom color';

        if (refreshSelect) {
            refreshSettingsColorBindingSelects(select);
        }
    }

    function navigate(url, options) {
        var target = String(url || '').trim();
        if (!target) return false;
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            return options && options.replace
                ? Metis.navigation.replace(target)
                : Metis.navigation.go(target);
        }
        if (options && options.replace) {
            window.location.replace(target);
            return true;
        }
        window.location.assign(target);
        return true;
    }

    function initSettingsMediaPicker() {
        var pickerButtons = document.querySelectorAll('[data-settings-media-pick]');
        if (!pickerButtons.length) return;

        var modal = document.createElement('div');
        modal.id = 'metis-settings-media-modal';
        modal.className = 'metis-modal-backdrop metis-settings-media-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = [
            '<div class="metis-settings-media-modal__inner" role="dialog" aria-modal="true" aria-label="Select media">',
            '  <div class="metis-settings-media-modal__head">',
            '    <strong>Select Media</strong>',
            '    <button type="button" class="metis-btn metis-btn-ghost metis-btn-sm" data-settings-media-close data-modal-close="metis-settings-media-modal">Close</button>',
            '  </div>',
            '  <div class="metis-settings-media-modal__toolbar">',
            '    <input type="text" class="metis-input" placeholder="Search by filename" data-settings-media-search>',
            '    <button type="button" class="metis-btn metis-btn-ghost metis-btn-sm" data-settings-media-refresh>Refresh</button>',
            '  </div>',
            '  <div class="metis-settings-media-modal__grid" data-settings-media-grid>',
            '    <div class="metis-settings-media-modal__empty">Loading media...</div>',
            '  </div>',
            '</div>'
        ].join('');
        document.body.appendChild(modal);
        if (window.Metis && Metis.ui && Metis.ui.modal) {
            Metis.ui.modal.init(modal);
        }

        var activeKey = '';
        var searchEl = modal.querySelector('[data-settings-media-search]');
        var gridEl = modal.querySelector('[data-settings-media-grid]');

        function esc(value) {
            return String(value || '').replace(/[&<>"']/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
            });
        }

        function getField(key, attr) {
            return document.querySelector('[data-settings-media-' + attr + '="' + key + '"]');
        }

        function renderItems(items) {
            if (!Array.isArray(items) || !items.length) {
                gridEl.innerHTML = '<div class="metis-settings-media-modal__empty">No media files found.</div>';
                return;
            }
            gridEl.innerHTML = items.map(function (item) {
                var token = String(item.token || '');
                var name = String(item.file_name || 'Media file');
                var mime = String(item.mime_type || '');
                var url = String(item.url || '');
                var thumb = mime.indexOf('image/') === 0
                    ? '<img src="' + esc(url) + '" alt="' + esc(name) + '">'
                    : '<div class="metis-settings-media-modal__thumb-fallback">' + esc(mime || 'file') + '</div>';
                return [
                    '<button type="button" class="metis-settings-media-modal__item" data-settings-media-select',
                    ' data-token="' + esc(token) + '"',
                    ' data-url="' + esc(url) + '"',
                    ' data-name="' + esc(name) + '"',
                    ' data-mime="' + esc(mime) + '">',
                    '  <span class="metis-settings-media-modal__thumb">' + thumb + '</span>',
                    '  <span class="metis-settings-media-modal__meta">',
                    '    <strong>' + esc(name) + '</strong>',
                    '    <small>' + esc(mime || 'unknown') + '</small>',
                    '  </span>',
                    '</button>'
                ].join('');
            }).join('');
        }

        function loadItems() {
            var action = 'metis_media_library_list';
            var body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));
            body.append('type', 'image');
            body.append('limit', '60');
            body.append('search', (searchEl.value || '').trim());

            Metis.request.postForm(window.metisAjax || null, action, body, 'Media library unavailable.').then(function (data) {
                renderItems((data && data.items) || []);
            }).catch(function () {
                gridEl.innerHTML = '<div class="metis-settings-media-modal__empty">Failed to load media.</div>';
            });
        }

        function openFor(key) {
            activeKey = key;
            if (window.Metis && Metis.ui && Metis.ui.modal) {
                Metis.ui.modal.form('metis-settings-media-modal');
            }
            if (searchEl) {
                searchEl.value = '';
                window.setTimeout(function () {
                    searchEl.focus();
                }, 0);
            }
            loadItems();
        }

        function closeModal() {
            if (window.Metis && Metis.ui && Metis.ui.modal) {
                Metis.ui.modal.close('metis-settings-media-modal');
            }
            activeKey = '';
        }

        pickerButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var key = String(button.getAttribute('data-settings-media-pick') || '');
                if (!key) return;
                openFor(key);
            });
        });

        document.querySelectorAll('[data-settings-media-clear]').forEach(function (button) {
            button.addEventListener('click', function () {
                var key = String(button.getAttribute('data-settings-media-clear') || '');
                if (!key) return;
                var tokenEl = getField(key, 'token');
                var urlEl = getField(key, 'url');
                var fileEl = getField(key, 'filename');
                var mimeValEl = getField(key, 'mimevalue');
                var previewEl = getField(key, 'preview');
                var wrapEl = getField(key, 'preview-wrap');
                var nameEl = getField(key, 'name');
                var mimeEl = getField(key, 'mime');
                var emptyEl = getField(key, 'empty');
                if (tokenEl) tokenEl.value = '';
                if (urlEl) urlEl.value = '';
                if (fileEl) fileEl.value = '';
                if (mimeValEl) mimeValEl.value = '';
                if (previewEl) previewEl.setAttribute('src', '');
                if (nameEl) nameEl.textContent = '';
                if (mimeEl) mimeEl.textContent = '';
                if (wrapEl) wrapEl.style.display = 'none';
                if (emptyEl) emptyEl.style.display = '';
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-settings-media-close]')) {
                closeModal();
                return;
            }
            if (event.target.closest('[data-settings-media-refresh]')) {
                loadItems();
                return;
            }
            var item = event.target.closest('[data-settings-media-select]');
            if (!item || !activeKey) return;

            var token = String(item.getAttribute('data-token') || '');
            var url = String(item.getAttribute('data-url') || '');
            var name = String(item.getAttribute('data-name') || '');
            var mime = String(item.getAttribute('data-mime') || '');
            if (!url) return;

            var tokenEl = getField(activeKey, 'token');
            var urlEl = getField(activeKey, 'url');
            var fileEl = getField(activeKey, 'filename');
            var mimeValEl = getField(activeKey, 'mimevalue');
            var previewEl = getField(activeKey, 'preview');
            var wrapEl = getField(activeKey, 'preview-wrap');
            var nameEl = getField(activeKey, 'name');
            var mimeEl = getField(activeKey, 'mime');
            var emptyEl = getField(activeKey, 'empty');

            if (tokenEl) tokenEl.value = token;
            if (urlEl) urlEl.value = url;
            if (fileEl) fileEl.value = name;
            if (mimeValEl) mimeValEl.value = mime;
            if (previewEl) previewEl.setAttribute('src', url);
            if (nameEl) nameEl.textContent = name || 'Selected file';
            if (mimeEl) mimeEl.textContent = mime ? mime.replace('image/', '').replace('vnd.microsoft.', '').toUpperCase() : '';
            if (wrapEl) wrapEl.style.display = '';
            if (emptyEl) emptyEl.style.display = 'none';

            closeModal();
        });

        if (searchEl) {
            searchEl.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    loadItems();
                }
            });
        }
    }

    initSettingsMediaPicker();
    refreshSettingsColorBindingSelects(document);
    document.querySelectorAll('[data-settings-custom-color-select]').forEach(function (select) {
        var key = String(select.getAttribute('data-settings-custom-color-select') || '').trim();
        if (key) {
            syncSettingsCustomColorControl(key, true);
        }
    });

    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-settings-custom-color-select]');
        if (!select) return;
        var key = String(select.getAttribute('data-settings-custom-color-select') || '').trim();
        if (!key) return;
        syncSettingsCustomColorControl(key, true);
    });

    document.addEventListener('input', function (event) {
        var input = event.target.closest('[data-settings-custom-color]');
        if (!input) return;
        var key = String(input.getAttribute('data-settings-custom-color') || '').trim();
        if (!key) return;
        syncSettingsCustomColorControl(key, true);
    });

    document.addEventListener('click', function (event) {
        var dot = event.target.closest('[data-settings-custom-color-dot]');
        if (!dot) return;
        var key = String(dot.getAttribute('data-settings-custom-color-dot') || '').trim();
        if (!key) return;

        var input = document.querySelector('[data-settings-custom-color="' + key + '"]');
        var select = document.querySelector('[data-settings-custom-color-select="' + key + '"]');
        if (!(input instanceof HTMLInputElement) || !(select instanceof HTMLSelectElement)) return;

        if (String(select.value || '') !== '') {
            select.value = '';
            syncSettingsCustomColorControl(key, true);
        }
        input.click();
    });

    function upsertCardDavNotice(form, notice) {
        if (!form) return;

        const existing = form.querySelector('[data-carddav-token-notice]');
        if (existing) {
            existing.remove();
        }

        if (!notice || !notice.token) {
            return;
        }

        const usernameEl = Array.from(form.querySelectorAll('label')).find(function (label) {
            return String(label.textContent || '').trim().toLowerCase() === 'username';
        });
        const afterNode = usernameEl ? usernameEl.closest('.metis-field') : null;
        const noticeEl = document.createElement('div');
        noticeEl.className = 'metis-callout metis-callout-warning';
        noticeEl.setAttribute('data-carddav-token-notice', '1');
        noticeEl.innerHTML =
            'New CardDAV token for <strong>' +
            String(notice.label || 'CardDAV device')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;') +
            '</strong>: <code>' +
            String(notice.token)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;') +
            '</code>';

        if (afterNode && afterNode.parentNode) {
            afterNode.parentNode.insertBefore(noticeEl, afterNode.nextSibling);
        } else {
            form.prepend(noticeEl);
        }
    }

    const showToast = function (type, message, options) {
        return Metis.util.notify(message, type, options);
    };
    const confirmAction = function (message, options) {
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({ message: message }, options || {}));
        }
        return Promise.resolve(false);
    };

    document.querySelectorAll('[data-copy-target]').forEach(function (copyBtn) {
        copyBtn.addEventListener('click', function () {
            const targetId = String(copyBtn.getAttribute('data-copy-target') || '');
            const valueEl = targetId ? document.getElementById(targetId) : null;
            if (!valueEl) return;

            const value = String(valueEl.textContent || '').trim();
            navigator.clipboard.writeText(value).then(() => {
                copyBtn.textContent = 'Copied!';
                setTimeout(() => { copyBtn.textContent = 'Copy'; }, 2000);
            }).catch(() => {});
        });
    });

    document.querySelectorAll('[data-theme-color-input]').forEach(function (picker) {
        picker.addEventListener('input', function () {
            const targetId = String(picker.getAttribute('data-theme-color-input') || '');
            const textInput = targetId ? document.getElementById(targetId) : null;
            if (textInput) {
                textInput.value = String(picker.value || '').toUpperCase();
            }
        });
    });

    document.querySelectorAll('[data-theme-color-text]').forEach(function (textInput) {
        textInput.addEventListener('input', function () {
            const value = String(textInput.value || '').trim();
            const row = textInput.closest('.metis-theme-input-row');
            const picker = row ? row.querySelector('[data-theme-color-input]') : null;
            if (picker && /^#[0-9a-fA-F]{6}$/.test(value)) {
                picker.value = value;
            }
        });
    });

    (function initMenuEditor() {
        const root = document.querySelector('[data-menu-structure]');
        if (!root) return;

        const rootDropzone = root.querySelector('[data-menu-dropzone="root"]');
        const iconLibraryEl = document.getElementById('metis-menu-icon-library-json');
        const iconPanel = document.querySelector('[data-menu-icon-panel]');
        let activeIconField = null;
        let iconLibrary = [];
        let menuUidCounter = 1;
        const iconCategoryLabels = {
            all: 'All',
            accessibility: 'Accessibility',
            activities: 'Activities',
            actions: 'Actions',
            analytics: 'Analytics',
            animals: 'Animals',
            brands: 'Brands',
            communication: 'Comms',
            donations: 'Donations',
            editor: 'Editor',
            files: 'Files',
            finance: 'Finance',
            'food-drinks': 'Food & Drinks',
            general: 'General',
            health: 'Health',
            infrastructure: 'Infra',
            layout: 'Layout',
            people: 'People',
            security: 'Security',
            status: 'Status',
            ui: 'UI',
            
        };

        if (iconLibraryEl) {
            try {
                const parsed = JSON.parse(String(iconLibraryEl.textContent || '[]'));
                if (Array.isArray(parsed)) {
                    iconLibrary = parsed.filter(function (entry) {
                        if (!entry || typeof entry.key !== 'string') return false;
                        const hasSvg = typeof entry.svg === 'string' && entry.svg.trim() !== '';
                        const hasUrl = typeof entry.url === 'string' && entry.url.trim() !== '';
                        return hasSvg || hasUrl;
                    }).map(function (entry) {
                        const normalized = Object.assign({}, entry);
                        normalized.category = String(entry.category || 'general').trim().toLowerCase();
                        if (!normalized.category) normalized.category = 'general';
                        normalized._search = (
                            String(normalized.label || '') + ' ' +
                            String(normalized.key || '') + ' ' +
                            String(normalized.category || '')
                        ).toLowerCase();
                        return normalized;
                    });
                }
            } catch (_error) {
                iconLibrary = [];
            }
        }

        const getLibraryIcon = function (key) {
            const lookup = String(key || '').trim();
            if (!lookup) return null;
            return iconLibrary.find(function (entry) {
                return String(entry.key || '') === lookup;
            }) || null;
        };

        const iconMarkupFromLibrary = function (entry) {
            if (!entry) return '';
            const svg = String(entry.svg || '').trim();
            if (svg) return svg;
            const url = String(entry.url || '').trim();
            if (!url) return '';
            const label = String(entry.label || entry.key || 'Icon');
            return '<img src="' + Metis.util.escapeHtml(url) + '" alt="' + Metis.util.escapeHtml(label) + '" loading="lazy" decoding="async">';
        };

        const getItemChildren = function (item) {
            const childrenRoot = item ? item.querySelector(':scope > [data-menu-dropzone="children"]') : null;
            if (!childrenRoot) return [];
            return Array.from(childrenRoot.querySelectorAll(':scope > .metis-menu-item'));
        };

        const ensureUid = function (item) {
            if (!item) return '';
            let uid = String(item.getAttribute('data-menu-uid') || '').trim();
            if (!uid) {
                uid = 'menu_' + String(menuUidCounter++);
                item.setAttribute('data-menu-uid', uid);
            }
            return uid;
        };

        const ensureItemIsChildClass = function (item) {
            if (!item) return;
            const parentZone = item.parentElement;
            if (parentZone && parentZone.getAttribute('data-menu-dropzone') === 'children') {
                item.classList.add('metis-menu-item-child');
                return;
            }
            item.classList.remove('metis-menu-item-child');
        };

        const updateGroupClass = function (item, moduleKey) {
            const isGroup = String(moduleKey || '').startsWith('group:');
            if (isGroup) {
                item.classList.add('is-group');
                item.setAttribute('data-menu-is-group', '1');
            } else {
                item.classList.remove('is-group');
                item.setAttribute('data-menu-is-group', '0');
            }
        };

        const syncItemControls = function (item) {
            if (!item) return;
            const moveTop = item.querySelector('[data-menu-move-top]');
            if (!moveTop) return;
            const isChild = item.classList.contains('metis-menu-item-child');
            moveTop.style.display = isChild ? 'inline-flex' : 'none';
        };

        const ensureItemControls = function (item) {
            if (!item) return;
            const handle = item.querySelector('.metis-menu-item-handle');
            if (handle && !handle.querySelector('[data-menu-move-up]')) {
                const controls = document.createElement('div');
                controls.className = 'metis-menu-reorder-controls';
                controls.innerHTML = '<button type="button" class="metis-menu-reorder-btn" data-menu-move-up title="Move Up">↑</button><button type="button" class="metis-menu-reorder-btn" data-menu-move-down title="Move Down">↓</button>';
                handle.appendChild(controls);
            }

            const fields = item.querySelector('.metis-menu-item-fields');
            if (fields && !fields.querySelector('[data-menu-parent-select]')) {
                const row = document.createElement('div');
                row.className = 'metis-menu-field-row';
                row.innerHTML = '<label>Parent</label><select class="metis-input" data-menu-parent-select></select>';
                fields.appendChild(row);
            }
        };

        const isGroupItem = function (item) {
            if (!item) return false;
            const moduleKeyInput = item.querySelector('[data-menu-module-key]');
            const moduleKey = String(moduleKeyInput ? moduleKeyInput.value : '').trim();
            return moduleKey.startsWith('group:');
        };

        const syncGroupState = function (item) {
            if (!item) return;
            const handle = item.querySelector('.metis-menu-item-handle');
            const childrenZone = item.querySelector(':scope > [data-menu-dropzone="children"]');
            const isGroup = isGroupItem(item);
            const childrenCount = childrenZone ? childrenZone.querySelectorAll(':scope > .metis-menu-item').length : 0;

            if (handle) {
                let badge = handle.querySelector('[data-menu-group-badge]');
                let toggle = handle.querySelector('[data-menu-group-toggle]');

                if (isGroup) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'metis-menu-group-badge';
                        badge.setAttribute('data-menu-group-badge', '1');
                        badge.textContent = 'Group';
                        handle.insertBefore(badge, handle.firstChild);
                    }

                    if (!toggle) {
                        toggle = document.createElement('button');
                        toggle.type = 'button';
                        toggle.className = 'metis-menu-group-toggle';
                        toggle.setAttribute('data-menu-group-toggle', '1');
                        handle.appendChild(toggle);
                    }

                    const isCollapsed = String(item.getAttribute('data-menu-collapsed') || '') === '1';
                    toggle.textContent = isCollapsed ? '▸' : '▾';
                    toggle.setAttribute('title', isCollapsed ? 'Expand Group' : 'Collapse Group');
                    toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    toggle.disabled = childrenCount < 1;
                    item.classList.toggle('is-collapsed', isCollapsed);
                } else {
                    if (badge) badge.remove();
                    if (toggle) toggle.remove();
                    item.classList.remove('is-collapsed');
                }
            }

            if (childrenZone) {
                if (!isGroup || childrenCount < 1) {
                    item.classList.remove('has-children');
                    childrenZone.style.display = 'none';
                    return;
                }

                item.classList.add('has-children');
                const collapsed = String(item.getAttribute('data-menu-collapsed') || '') === '1';
                childrenZone.style.display = collapsed ? 'none' : 'flex';
            }
        };

        const getTopLevelItems = function () {
            if (!rootDropzone) return [];
            return Array.from(rootDropzone.querySelectorAll(':scope > .metis-menu-item'));
        };

        const getTopLevelGroups = function () {
            return getTopLevelItems().filter(function (item) {
                const moduleKeyInput = item.querySelector('[data-menu-module-key]');
                const moduleKey = String(moduleKeyInput ? moduleKeyInput.value : '').trim();
                return moduleKey.startsWith('group:');
            });
        };

        const parentUidForItem = function (item) {
            const parentZone = item ? item.parentElement : null;
            if (!parentZone || parentZone.getAttribute('data-menu-dropzone') !== 'children') {
                return '';
            }
            const parentItem = parentZone.closest('[data-menu-item]');
            return parentItem ? ensureUid(parentItem) : '';
        };

        const rebuildParentOptions = function () {
            const groups = getTopLevelGroups();
            root.querySelectorAll('[data-menu-item]').forEach(function (item) {
                ensureUid(item);
                ensureItemIsChildClass(item);
                const select = item.querySelector('[data-menu-parent-select]');
                if (!select) return;

                const moduleKeyInput = item.querySelector('[data-menu-module-key]');
                const moduleKey = String(moduleKeyInput ? moduleKeyInput.value : '').trim();
                const isGroup = moduleKey.startsWith('group:');
                const currentParentUid = parentUidForItem(item);
                const ownUid = ensureUid(item);

                select.innerHTML = '';
                const topOpt = document.createElement('option');
                topOpt.value = '';
                topOpt.textContent = 'Top Level';
                select.appendChild(topOpt);

                if (!isGroup) {
                    groups.forEach(function (groupItem) {
                        const groupUid = ensureUid(groupItem);
                        if (groupUid === ownUid) return;
                        const labelInput = groupItem.querySelector('[data-menu-label]');
                        const label = String(labelInput ? labelInput.value : '').trim() || 'Group';
                        const opt = document.createElement('option');
                        opt.value = groupUid;
                        opt.textContent = label;
                        select.appendChild(opt);
                    });
                }

                select.value = currentParentUid;
                if (isGroup) {
                    select.value = '';
                    select.disabled = true;
                } else {
                    const isLocked = String(item.getAttribute('data-menu-locked') || '') === '1';
                    select.disabled = isLocked;
                }

                syncGroupState(item);
            });
        };

        const moveItemToParent = function (item, parentUid) {
            if (!item || !rootDropzone) return;
            const moduleKeyInput = item.querySelector('[data-menu-module-key]');
            const moduleKey = String(moduleKeyInput ? moduleKeyInput.value : '').trim();
            if (moduleKey.startsWith('group:')) {
                rootDropzone.appendChild(item);
                ensureItemIsChildClass(item);
                syncItemControls(item);
                rebuildParentOptions();
                return;
            }

            if (!parentUid) {
                rootDropzone.appendChild(item);
                ensureItemIsChildClass(item);
                syncItemControls(item);
                rebuildParentOptions();
                return;
            }

            const parentGroup = root.querySelector('[data-menu-item][data-menu-uid="' + parentUid + '"]');
            const childZone = parentGroup ? parentGroup.querySelector(':scope > [data-menu-dropzone="children"]') : null;
            if (!parentGroup || !childZone) {
                rootDropzone.appendChild(item);
            } else {
                childZone.appendChild(item);
            }
            ensureItemIsChildClass(item);
            syncItemControls(item);
            rebuildParentOptions();
        };

        const applyIconFieldState = function (fieldRoot) {
            if (!fieldRoot) return;

            const iconInput = fieldRoot.querySelector('[data-menu-icon]');
            const tokenEl = fieldRoot.querySelector('[data-menu-icon-token]');
            const previewEl = fieldRoot.querySelector('[data-menu-icon-preview]');
            const raw = String(iconInput ? iconInput.value : '').trim();
            const libraryIcon = getLibraryIcon(raw);
            const tokenLabel = libraryIcon ? String(libraryIcon.label || raw) : (raw === '' ? 'No icon selected' : 'Custom icon');

            if (tokenEl) {
                tokenEl.textContent = tokenLabel;
            }

            if (previewEl) {
                if (libraryIcon) {
                    previewEl.innerHTML = iconMarkupFromLibrary(libraryIcon);
                } else if (raw.charAt(0) === '<') {
                    previewEl.innerHTML = (window.Metis && Metis.util && typeof Metis.util.sanitizeHtmlFragment === 'function')
                        ? Metis.util.sanitizeHtmlFragment(raw)
                        : '';
                } else if (/^\/?svg\/[a-z0-9_-]+\/?$/i.test(raw)) {
                    const clean = raw.replace(/^\/+/, '').replace(/\/+$/, '');
                    previewEl.innerHTML = '<img src="/' + Metis.util.escapeHtml(clean) + '/" alt="Icon" loading="lazy" decoding="async">';
                } else {
                    previewEl.innerHTML = '';
                }
            }
        };

        const closeIconPanel = function () {
            if (!iconPanel) return;
            iconPanel.hidden = true;
            iconPanel.innerHTML = '';
            activeIconField = null;
        };

        const openIconPanel = function (fieldRoot, anchorButton) {
            if (!iconPanel || !fieldRoot || !anchorButton) return;
            activeIconField = fieldRoot;
            iconPanel.innerHTML = '';
            iconPanel.classList.add('has-tabs');

            const toolbar = document.createElement('div');
            toolbar.className = 'metis-menu-icon-toolbar';

            const tabs = document.createElement('div');
            tabs.className = 'metis-menu-icon-tabs';
            toolbar.appendChild(tabs);

            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'metis-menu-icon-search';
            search.placeholder = 'Search icons...';
            search.setAttribute('aria-label', 'Search icons');
            toolbar.appendChild(search);

            iconPanel.appendChild(toolbar);

            const grid = document.createElement('div');
            grid.className = 'metis-menu-icon-grid';
            iconPanel.appendChild(grid);

            const categories = ['all'];
            iconLibrary.forEach(function (entry) {
                const category = String(entry.category || 'general');
                if (categories.indexOf(category) === -1) categories.push(category);
            });
            const sortedCategories = categories.slice(1).sort(function (a, b) {
                const aLabel = String(iconCategoryLabels[a] || a).toLowerCase();
                const bLabel = String(iconCategoryLabels[b] || b).toLowerCase();
                return aLabel.localeCompare(bLabel);
            });
            categories.splice(0, categories.length, 'all', ...sortedCategories);

            let activeCategory = 'all';
            let searchTerm = '';
            const renderTabButtons = function () {
                tabs.innerHTML = '';
                categories.forEach(function (category) {
                    const tab = document.createElement('button');
                    tab.type = 'button';
                    tab.className = 'metis-menu-icon-tab' + (category === activeCategory ? ' is-active' : '');
                    tab.setAttribute('data-menu-icon-tab', category);
                    tab.textContent = iconCategoryLabels[category] || category;
                    tabs.appendChild(tab);
                });
            };

            const renderGrid = function () {
                grid.innerHTML = '';
                let visibleCount = 0;
                iconLibrary.forEach(function (entry) {
                    const category = String(entry.category || 'general');
                    if (activeCategory !== 'all' && category !== activeCategory) return;

                    if (searchTerm) {
                        const haystack = String(entry._search || '');
                        if (haystack.indexOf(searchTerm) === -1) return;
                    }

                    const key = String(entry.key || '');
                    const label = String(entry.label || key);
                    const mark = iconMarkupFromLibrary(entry);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'metis-menu-icon-option';
                    button.setAttribute('data-menu-icon-option', key);
                    button.innerHTML = '<span class="metis-menu-icon-option-mark">' + mark + '</span><span class="metis-menu-icon-option-label">' + Metis.util.escapeHtml(label) + '</span>';
                    grid.appendChild(button);
                    visibleCount += 1;
                });

                if (activeCategory === 'all' && !searchTerm) {
                    const clearBtn = document.createElement('button');
                    clearBtn.type = 'button';
                    clearBtn.className = 'metis-menu-icon-option';
                    clearBtn.setAttribute('data-menu-icon-option', '');
                    clearBtn.innerHTML = '<span class="metis-menu-icon-option-mark"></span><span class="metis-menu-icon-option-label">No Icon</span>';
                    grid.appendChild(clearBtn);
                }

                if (visibleCount === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'metis-menu-icon-empty';
                    empty.textContent = 'No icons match your search.';
                    grid.appendChild(empty);
                }
            };

            renderTabButtons();
            renderGrid();

            const rect = anchorButton.getBoundingClientRect();
            const scrollY = window.scrollY || document.documentElement.scrollTop || 0;
            const scrollX = window.scrollX || document.documentElement.scrollLeft || 0;
            const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 1200;
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 800;

            // First paint, then clamp the panel to stay fully inside viewport.
            iconPanel.style.top = (rect.bottom + 8 + scrollY) + 'px';
            iconPanel.style.left = (Math.max(12, rect.left + scrollX)) + 'px';
            iconPanel.hidden = false;

            window.requestAnimationFrame(function () {
                if (iconPanel.hidden) return;
                const panelRect = iconPanel.getBoundingClientRect();
                const panelWidth = panelRect.width || 560;
                const panelHeight = panelRect.height || 320;
                let left = rect.left + scrollX;
                let top = rect.bottom + 8 + scrollY;

                const maxLeft = scrollX + viewportWidth - panelWidth - 12;
                if (left > maxLeft) {
                    left = Math.max(scrollX + 12, maxLeft);
                }

                const overflowBottom = top + panelHeight > scrollY + viewportHeight - 12;
                if (overflowBottom) {
                    top = rect.top + scrollY - panelHeight - 8;
                    if (top < scrollY + 12) {
                        top = scrollY + 12;
                    }
                }

                iconPanel.style.left = left + 'px';
                iconPanel.style.top = top + 'px';
            });

            tabs.addEventListener('click', function (event) {
                const tab = event.target.closest('[data-menu-icon-tab]');
                if (!tab) return;
                event.preventDefault();
                event.stopPropagation();
                activeCategory = String(tab.getAttribute('data-menu-icon-tab') || 'all');
                tabs.querySelectorAll('[data-menu-icon-tab]').forEach(function (node) {
                    node.classList.toggle('is-active', node === tab);
                });
                renderGrid();
            });

            search.addEventListener('input', function (event) {
                searchTerm = String(event.target && event.target.value ? event.target.value : '').trim().toLowerCase();
                renderGrid();
            });

            window.requestAnimationFrame(function () {
                search.focus({ preventScroll: true });
            });
        };

        const buildMenuItem = function (payload) {
            const label = String(payload.label || 'Item');
            const icon = String(payload.icon || '');
            const route = String(payload.route || '');
            const permission = String(payload.permissions_required || '');
            const moduleKey = String(payload.module_key || '');
            const isGroup = moduleKey.startsWith('group:');

            const item = document.createElement('li');
            item.className = 'metis-menu-item' + (isGroup ? ' is-group' : '');
            item.setAttribute('data-menu-item', '');
            item.setAttribute('data-menu-is-group', isGroup ? '1' : '0');
            item.setAttribute('data-item-id', '0');

            item.innerHTML =
                '<div class="metis-menu-item-card">' +
                    '<div class="metis-menu-item-handle" title="Drag to reorder">↕<button type="button" class="metis-menu-item-ungroup" data-menu-move-top title="Move To Top Level">↰</button></div>' +
                    '<div class="metis-menu-item-fields">' +
                        '<div class="metis-menu-field-row"><label>Label</label><input class="metis-input" type="text" data-menu-label value="' + Metis.util.escapeHtml(label) + '"></div>' +
                        '<div class="metis-menu-field-row"><label>Icon</label><input type="hidden" data-menu-icon value="' + Metis.util.escapeHtml(icon) + '"><div class="metis-menu-icon-picker-row"><div class="metis-menu-icon-preview" data-menu-icon-preview></div><button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-menu-icon-picker-toggle>Choose Icon</button><button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" data-menu-icon-clear>Clear</button><span class="metis-menu-icon-token" data-menu-icon-token></span></div></div>' +
                        '<div class="metis-menu-field-row"><label>Visible</label><input type="checkbox" data-menu-visible checked></div>' +
                    '</div>' +
                '</div>' +
                '<input type="hidden" data-menu-route value="' + Metis.util.escapeHtml(route) + '">' +
                '<input type="hidden" data-menu-permission value="' + Metis.util.escapeHtml(permission) + '">' +
                '<input type="hidden" data-menu-module-key value="' + Metis.util.escapeHtml(moduleKey) + '">' +
                '<ul class="metis-menu-level metis-menu-children" data-menu-dropzone="children"></ul>';

            ensureUid(item);
            return item;
        };

        const nextCustomGroupKey = function () {
            const used = new Set();
            root.querySelectorAll('[data-menu-module-key]').forEach(function (input) {
                used.add(String(input.value || '').trim().toLowerCase());
            });

            let index = 1;
            while (used.has('group:custom_' + index)) {
                index += 1;
            }

            return 'group:custom_' + index;
        };

        const bindItem = function (item) {
            if (!item || item._metisMenuBound) return;
            item._metisMenuBound = true;
            item.setAttribute('draggable', 'false');
            ensureUid(item);
            ensureItemControls(item);
            ensureItemIsChildClass(item);
            syncGroupState(item);
            const handle = item.querySelector('.metis-menu-item-handle');
            if (handle) {
                handle.setAttribute('title', 'Use explicit controls to reorder');
            }
            syncItemControls(item);

            item.querySelectorAll('[data-menu-icon-picker-toggle], [data-menu-icon-clear]').forEach(function (btn) {
                btn.addEventListener('dragstart', function (event) { event.preventDefault(); });
            });
            const fieldRoot = item.querySelector('.metis-menu-field-row [data-menu-icon]') ? item.querySelector('.metis-menu-field-row [data-menu-icon]').closest('.metis-menu-field-row') : null;
            applyIconFieldState(fieldRoot);
        };

        const bindItemDropTarget = function () {};

        const bindDropzone = function () {};

        root.querySelectorAll('[data-menu-item]').forEach(bindItem);
        root.querySelectorAll('[data-menu-dropzone]').forEach(bindDropzone);
        root.querySelectorAll('[data-menu-item]').forEach(bindItemDropTarget);
        rebuildParentOptions();

        document.addEventListener('click', function (event) {
            const addButton = event.target.closest('[data-menu-add-unassigned]');
            if (!addButton || !rootDropzone) return;

            const item = buildMenuItem({
                label: String(addButton.getAttribute('data-menu-module-label') || 'Module'),
                icon: String(addButton.getAttribute('data-menu-module-icon') || ''),
                route: String(addButton.getAttribute('data-menu-module-route') || ''),
                permissions_required: String(addButton.getAttribute('data-menu-module-permission') || ''),
                module_key: String(addButton.getAttribute('data-menu-module-key') || '')
            });

            rootDropzone.appendChild(item);
            bindItem(item);
            bindDropzone(item.querySelector('[data-menu-dropzone="children"]'));
            bindItemDropTarget(item);
            addButton.remove();
            rebuildParentOptions();
        });

        document.addEventListener('click', function (event) {
            const addGroupButton = event.target.closest('[data-menu-add-group]');
            if (!addGroupButton || !rootDropzone) return;

            const moduleKey = nextCustomGroupKey();
            const item = buildMenuItem({
                label: 'New Group',
                icon: '',
                route: '',
                permissions_required: '',
                module_key: moduleKey
            });

            rootDropzone.appendChild(item);
            bindItem(item);
            bindDropzone(item.querySelector('[data-menu-dropzone="children"]'));
            bindItemDropTarget(item);

            const labelInput = item.querySelector('[data-menu-label]');
            if (labelInput) {
                labelInput.focus();
                labelInput.select();
            }
            rebuildParentOptions();
        });

        document.addEventListener('click', function (event) {
            const groupToggle = event.target.closest('[data-menu-group-toggle]');
            if (groupToggle) {
                const item = groupToggle.closest('[data-menu-item]');
                if (!item || !isGroupItem(item)) return;
                const collapsed = String(item.getAttribute('data-menu-collapsed') || '') === '1';
                item.setAttribute('data-menu-collapsed', collapsed ? '0' : '1');
                syncGroupState(item);
                return;
            }

            const up = event.target.closest('[data-menu-move-up]');
            if (up) {
                const item = up.closest('[data-menu-item]');
                if (!item) return;
                const prev = item.previousElementSibling;
                if (prev && prev.classList.contains('metis-menu-item')) {
                    item.parentElement.insertBefore(item, prev);
                }
                rebuildParentOptions();
                return;
            }

            const down = event.target.closest('[data-menu-move-down]');
            if (down) {
                const item = down.closest('[data-menu-item]');
                if (!item) return;
                const next = item.nextElementSibling;
                if (next && next.classList.contains('metis-menu-item')) {
                    item.parentElement.insertBefore(next, item);
                }
                rebuildParentOptions();
                return;
            }

            const moveTop = event.target.closest('[data-menu-move-top]');
            if (moveTop && rootDropzone) {
                const item = moveTop.closest('[data-menu-item]');
                if (item) {
                    rootDropzone.appendChild(item);
                    item.classList.remove('metis-menu-item-child');
                    syncItemControls(item);
                    rebuildParentOptions();
                }
                return;
            }

            const toggle = event.target.closest('[data-menu-icon-picker-toggle]');
            if (toggle) {
                const fieldRoot = toggle.closest('.metis-menu-field-row');
                if (!fieldRoot) return;
                if (!iconPanel) return;
                if (!iconPanel.hidden && activeIconField === fieldRoot) {
                    closeIconPanel();
                    return;
                }
                openIconPanel(fieldRoot, toggle);
                return;
            }

            const clear = event.target.closest('[data-menu-icon-clear]');
            if (clear) {
                const fieldRoot = clear.closest('.metis-menu-field-row');
                const input = fieldRoot ? fieldRoot.querySelector('[data-menu-icon]') : null;
                if (input) input.value = '';
                applyIconFieldState(fieldRoot);
                closeIconPanel();
                return;
            }

            const option = event.target.closest('[data-menu-icon-option]');
            if (option && iconPanel && !iconPanel.hidden) {
                const value = String(option.getAttribute('data-menu-icon-option') || '');
                const input = activeIconField ? activeIconField.querySelector('[data-menu-icon]') : null;
                if (input) input.value = value;
                applyIconFieldState(activeIconField);
                closeIconPanel();
                return;
            }

            if (iconPanel && !iconPanel.hidden && !event.target.closest('[data-menu-icon-panel]')) {
                closeIconPanel();
            }
        });

        document.addEventListener('change', function (event) {
            const parentSelect = event.target.closest('[data-menu-parent-select]');
            if (!parentSelect) return;
            const item = parentSelect.closest('[data-menu-item]');
            if (!item) return;
            moveItemToParent(item, String(parentSelect.value || ''));
        });

        document.addEventListener('input', function (event) {
            const labelInput = event.target.closest('[data-menu-label]');
            if (!labelInput) return;
            rebuildParentOptions();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeIconPanel();
            }
        });
    }());

    const collectMenuState = function () {
        const root = document.querySelector('[data-menu-dropzone="root"]');
        if (!root) return [];

        const parseItem = function (item) {
            const parsedId = parseInt(String(item.getAttribute('data-item-id') || '0'), 10);
            const id = Number.isFinite(parsedId) ? parsedId : 0;
            const labelInput = item.querySelector('[data-menu-label]');
            const iconInput = item.querySelector('[data-menu-icon]');
            const visibleInput = item.querySelector('[data-menu-visible]');
            const routeInput = item.querySelector('[data-menu-route]');
            const permissionInput = item.querySelector('[data-menu-permission]');
            const moduleKeyInput = item.querySelector('[data-menu-module-key]');
            const childrenRoot = item.querySelector(':scope > [data-menu-dropzone="children"]');
            const children = [];

            if (childrenRoot) {
                childrenRoot.querySelectorAll(':scope > .metis-menu-item').forEach(function (child) {
                    children.push(parseItem(child));
                });
            }

            return {
                id: id,
                label: String(labelInput ? labelInput.value : '').trim(),
                icon: String(iconInput ? iconInput.value : ''),
                is_visible: visibleInput && visibleInput.checked ? 1 : 0,
                route: String(routeInput ? routeInput.value : ''),
                permissions_required: String(permissionInput ? permissionInput.value : ''),
                module_key: String(moduleKeyInput ? moduleKeyInput.value : ''),
                children: children
            };
        };

        const result = [];
        root.querySelectorAll(':scope > .metis-menu-item').forEach(function (item) {
            result.push(parseItem(item));
        });

        return result;
    };

    document.querySelectorAll('[data-metis-settings-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const explicitSection = String(form.getAttribute('data-settings-section') || '').trim();
            const sectionMatch = window.location.pathname.match(/\/settings(?:\/([^/]+))?(?:\/([^/]+))?\/?$/);
            const section = explicitSection || (sectionMatch && sectionMatch[2] ? String(sectionMatch[2]) : (sectionMatch && sectionMatch[1] ? String(sectionMatch[1]) : 'general'));
            const submitBtn = event.submitter || form.querySelector('button[type="submit"]');
            const originalLabel = submitBtn ? submitBtn.textContent : '';
            const navigationInput = form.querySelector('#metis-navigation-structure');
            if (navigationInput) {
                navigationInput.value = JSON.stringify(collectMenuState());
            }
            const body = new FormData(form);
            if (event.submitter && event.submitter.name) {
                body.append(String(event.submitter.name), String(event.submitter.value || '1'));
            }
            const action = 'metis_settings_save_section';
            body.append('action', action);
            body.append('settings_section', section);
            if (!body.has('nonce') && window.metisAjax && window.metisAjax.nonce) {
                body.append('nonce', window.metisAjax.nonce);
            }
            if (!body.has('metis_action_nonce') && window.metisAjax && window.metisAjax.nonce) {
                body.append('metis_action_nonce', Metis.ajax.nonceFor(action, window.metisAjax.nonce));
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Settings saved.'));
                if (data.redirect_url) {
                    window.setTimeout(function () {
                        navigate(String(data.redirect_url));
                    }, 300);
                }
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Save failed.');
            }).finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                }
            });
        });
    });

    document.querySelectorAll('[data-cache-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            const actionType = String(button.getAttribute('data-cache-action') || '');
            const cacheGroup = String(button.getAttribute('data-cache-group') || '');
            const statusEl = document.querySelector('[data-cache-status]');
            let action = '';
            const body = new FormData();

            if (actionType === 'clear_all') {
                action = 'metis_settings_clear_cache';
            } else if (actionType === 'clear_group') {
                action = 'metis_settings_clear_cache_group';
                body.append('group', cacheGroup);
            } else if (actionType === 'rebuild') {
                action = 'metis_settings_rebuild_cache';
            }

            if (!action) return;

            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = actionType === 'rebuild' ? 'Rebuilding...' : 'Clearing...';
            if (statusEl) {
                statusEl.textContent = actionType === 'rebuild' ? 'Rebuilding system caches...' : 'Clearing cache...';
            }

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                const message = String(data.message || (actionType === 'rebuild' ? 'System caches rebuilt.' : 'Cache cleared.'));
                showToast('success', message);
                if (statusEl) {
                    statusEl.textContent = message;
                }
            }).catch(function (error) {
                const message = error && error.message ? error.message : 'Cache operation failed.';
                showToast('error', message);
                if (statusEl) {
                    statusEl.textContent = message;
                }
            }).finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
        });
    });

    const driveSyncBtn = document.querySelector('[data-drive-sync-now]');
    if (driveSyncBtn) {
        driveSyncBtn.addEventListener('click', function () {
            const action = 'metis_drive_sync_now';
            const statusEl = document.querySelector('[data-drive-sync-status]');
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = driveSyncBtn.textContent;
            driveSyncBtn.disabled = true;
            driveSyncBtn.textContent = 'Queueing...';
            if (statusEl) {
                statusEl.textContent = 'Queueing Drive sync...';
            }

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                const message = String(data.message || 'Drive sync queued.');
                showToast('success', message);
                if (statusEl) {
                    statusEl.textContent = message;
                }
            }).catch(function (error) {
                const message = error && error.message ? error.message : 'Drive sync queue failed.';
                showToast('error', message);
                if (statusEl) {
                    statusEl.textContent = message;
                }
            }).finally(function () {
                driveSyncBtn.disabled = false;
                driveSyncBtn.textContent = originalLabel;
            });
        });
    }

    const backupRunBtn = document.querySelector('[data-backup-run-now]');
    const backupHistoryRoot = document.querySelector('[data-backup-history-root]');
    const backupHistoryRefreshBtn = document.querySelector('[data-backup-history-refresh]');
    const backupHistoryStatusEl = document.querySelector('[data-backup-history-status]');
    const backupHistoryBody = document.querySelector('[data-backup-history-body]');
    const backupStatusAlert = document.querySelector('[data-backup-status-alert]');
    const backupLiveStatus = document.querySelector('[data-backup-live-status]');
    let backupPollTimer = null;
    let backupPollInFlight = false;

    function backupStageLabel(stage) {
        const normalized = String(stage || '').trim().toLowerCase();
        const labels = {
            initializing: 'Initializing',
            health_check: 'Health check',
            health_check_passed: 'Health check complete',
            database_snapshot: 'Database snapshot',
            component_archives: 'Archiving files',
            metadata: 'Writing metadata',
            full_archive: 'Building full archive',
            local_generation: 'Generating local archive',
            local_generation_complete: 'Local archive complete',
            drive_upload_pending: 'Preparing upload',
            verify: 'Verifying archive',
            verification_passed: 'Verification complete',
            drive_folder: 'Preparing Drive folder',
            drive_upload_metadata: 'Uploading metadata',
            drive_upload_full: 'Uploading archive',
            completed: 'Complete',
            failed_after_local_artifact: 'Failed after local archive',
            stale_after_local_artifact: 'Stale after local archive',
            health_check_failed: 'Health check failed',
            local_generation_failed: 'Local generation failed',
            verification_failed: 'Verification failed',
            upload_failed: 'Upload failed'
        };
        return labels[normalized] || ucfirst(normalized.replace(/_/g, ' ') || 'Pending');
    }

    function backupProgressPercent(run) {
        const status = String((run && run.status) || '').trim().toLowerCase();
        if (status === 'success') return 100;
        if (status === 'failed') return 100;

        const stage = String((run && run.progress_stage) || '').trim().toLowerCase();
        const progress = {
            initializing: 5,
            health_check: 10,
            health_check_passed: 20,
            database_snapshot: 30,
            component_archives: 45,
            metadata: 55,
            full_archive: 65,
            local_generation: 40,
            local_generation_complete: 70,
            drive_upload_pending: 72,
            verify: 78,
            verification_passed: 84,
            drive_folder: 88,
            drive_upload_metadata: 91,
            drive_upload_full: 96
        };
        return Math.max(0, Math.min(100, progress[stage] || (status === 'running' ? 8 : 0)));
    }

    function backupHasActiveRun(runs) {
        return Array.isArray(runs) && runs.some(function (run) {
            const status = String((run && run.status) || '').trim().toLowerCase();
            return status === 'running' || status === 'queued';
        });
    }

    function renderBackupLiveStatus(runs, pauseStatus) {
        if (!backupLiveStatus) return;

        const list = Array.isArray(runs) ? runs : [];
        const activeRun = list.find(function (run) {
            const status = String((run && run.status) || '').trim().toLowerCase();
            return status === 'running' || status === 'queued';
        });
        const latestRun = activeRun || list[0] || null;
        const paused = !!(pauseStatus && pauseStatus.paused);

        if (!latestRun && !paused) {
            backupLiveStatus.hidden = true;
            backupLiveStatus.innerHTML = '';
            return;
        }

        const status = paused ? 'paused' : String((latestRun && latestRun.status) || 'unknown').trim().toLowerCase();
        const runUuid = String((latestRun && latestRun.run_uuid) || '').trim();
        const stage = paused ? 'paused' : String((latestRun && latestRun.progress_stage) || '').trim();
        const message = paused
            ? 'Scheduled backups are paused.'
            : String((latestRun && (latestRun.progress_message || latestRun.last_error)) || '').trim();
        const updatedAt = String((latestRun && (latestRun.progress_updated_at_display || latestRun.updated_at_display || latestRun.started_at_display || latestRun.progress_updated_at || latestRun.updated_at || latestRun.started_at)) || '').trim();
        const percent = paused ? 0 : backupProgressPercent(latestRun);
        const safeStatus = status.replace(/[^a-z0-9_-]/g, '') || 'unknown';

        backupLiveStatus.hidden = false;
        backupLiveStatus.innerHTML = [
            '<div class="metis-backup-live-header">',
            '  <div>',
            '    <strong>' + escapeHtml(activeRun ? 'Current Backup' : (paused ? 'Backup Paused' : 'Latest Backup')) + '</strong>',
            '    <div class="metis-help">' + escapeHtml(runUuid || '-') + '</div>',
            '  </div>',
            '  <span class="metis-status-chip is-' + escapeHtml(safeStatus) + '">' + escapeHtml(ucfirst(status || 'unknown')) + '</span>',
            '</div>',
            '<div class="metis-backup-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + escapeHtml(String(percent)) + '">',
            '  <div class="metis-backup-progress-fill is-' + escapeHtml(safeStatus) + '" style="width:' + escapeHtml(String(percent)) + '%;"></div>',
            '</div>',
            '<div class="metis-backup-live-meta">',
            '  <span>' + escapeHtml(backupStageLabel(stage)) + '</span>',
            '  <span>' + escapeHtml(String(percent)) + '%</span>',
            '</div>',
            message ? '<div class="metis-help">' + escapeHtml(message) + '</div>' : '',
            updatedAt ? '<div class="metis-help">Last activity ' + escapeHtml(updatedAt) + '</div>' : ''
        ].join('');
    }

    function bindBackupRestoreButtons(scope) {
        const root = scope || document;
        root.querySelectorAll('[data-backup-restore-run]').forEach(function (button) {
            if (button.getAttribute('data-bound-backup-restore') === '1') return;
            button.setAttribute('data-bound-backup-restore', '1');
            button.addEventListener('click', function () {
                const runUuid = String(button.getAttribute('data-backup-restore-run') || '').trim();
                if (!runUuid) return;
                confirmAction('Restore backup ' + runUuid + '? This will overwrite the current database and files.', {
                    title: 'Restore Backup',
                    confirmLabel: 'Restore',
                    tone: 'danger'
                }).then(function (confirmed) {
                    if (!confirmed) return;
                    const action = 'metis_backup_restore_run';
                    const body = new FormData();
                    body.append('action', action);
                    body.append('run_uuid', runUuid);
                    body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
                    body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

                    const originalLabel = button.textContent;
                    button.disabled = true;
                    button.textContent = 'Queueing...';

                    Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                        showToast('success', String(data.message || 'Restore queued.'));
                        if (backupHistoryStatusEl) {
                            backupHistoryStatusEl.textContent = String(data.message || 'Restore queued.');
                        }
                    }).catch(function (error) {
                        showToast('error', error && error.message ? error.message : 'Restore failed.');
                    }).finally(function () {
                        button.disabled = false;
                        button.textContent = originalLabel;
                    });
                });
            });
        });
    }

    function renderBackupHistoryRows(runs) {
        if (!Array.isArray(runs) || !runs.length) {
            return '<tr><td colspan="6"><span class="metis-help">No backup runs have been recorded yet.</span></td></tr>';
        }

        return runs.map(function (run) {
            const runUuid = String((run && run.run_uuid) || '').trim();
            const status = String((run && run.status) || 'unknown').trim().toLowerCase();
            const environment = String((run && run.environment) || '').trim();
            const completedAt = String((run && (run.completed_at_display || run.completed_at)) || '').trim();
            const updatedAt = String((run && (run.progress_updated_at_display || run.updated_at_display || run.progress_updated_at || run.updated_at)) || '').trim();
            const driveFolderId = String((run && run.drive_folder_id) || '').trim();
            const fullLink = String((run && run.full_link) || '').trim();
            const localArtifactAvailable = !!(run && run.local_artifact_available);
            const progressStage = String((run && run.progress_stage) || '').trim();
            const progressMessage = String((run && run.progress_message) || '').trim();
            const lastError = String((run && run.last_error) || '').trim();
            const canRestore = status === 'success' && runUuid !== '';

            const driveCell = fullLink
                ? '<a class="metis-btn metis-btn-xs metis-btn-ghost metis-backup-archive-link" href="' + escapeHtml(fullLink) + '" target="_blank" rel="noopener noreferrer">Open</a>'
                : (driveFolderId ? '<code class="metis-backup-drive-code">' + escapeHtml(driveFolderId) + '</code>' : '<span class="metis-backup-archive-state">' + (localArtifactAvailable ? 'Local only' : 'Not uploaded') + '</span>');

            const restoreButton = canRestore
                ? '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-backup-restore-run="' + escapeHtml(runUuid) + '">Restore</button>'
                : '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" disabled>Restore</button>';

            const errorNote = lastError ? '<div class="metis-backup-row-detail is-error">' + escapeHtml(lastError) + '</div>' : '';
            const progressNote = progressMessage && !lastError
                ? '<div class="metis-backup-row-detail">' + escapeHtml(progressMessage) + (progressStage ? ' <code class="metis-backup-stage-code">' + escapeHtml(progressStage) + '</code>' : '') + '</div>'
                : '';
            const completedCell = completedAt || (updatedAt ? 'Last activity ' + updatedAt : 'In progress');
            const safeStatus = status.replace(/[^a-z0-9_-]/g, '') || 'unknown';

            return [
                '<tr class="metis-backup-history-row is-' + escapeHtml(safeStatus) + '">',
                '  <td class="metis-backup-history-cell metis-backup-run-cell"><span class="metis-backup-run-id">' + escapeHtml(runUuid || '-') + '</span>' + errorNote + progressNote + '</td>',
                '  <td class="metis-backup-history-cell"><span class="metis-status-chip is-' + escapeHtml(safeStatus) + '">' + escapeHtml(ucfirst(status || 'unknown')) + '</span></td>',
                '  <td class="metis-backup-history-cell"><span class="metis-backup-env-chip">' + escapeHtml(environment || '-') + '</span></td>',
                '  <td class="metis-backup-history-cell"><span class="metis-backup-activity">' + escapeHtml(completedCell) + '</span></td>',
                '  <td class="metis-backup-history-cell">' + driveCell + '</td>',
                '  <td class="metis-backup-history-cell metis-backup-history-actions">' + restoreButton + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function renderBackupStatusAlert(runs, pauseStatus) {
        if (!backupStatusAlert) return;

        const paused = !!(pauseStatus && pauseStatus.paused);
        const failedRun = Array.isArray(runs)
            ? runs.find(function (run) { return String((run && run.last_error) || '').trim() !== ''; })
            : null;
        const message = paused
            ? 'Scheduled backups are paused because: ' + String((pauseStatus && pauseStatus.reason) || 'manual repair is required.')
            : (failedRun ? String(failedRun.last_error || '') : '');

        if (!message) {
            backupStatusAlert.hidden = true;
            backupStatusAlert.textContent = '';
            backupStatusAlert.className = 'metis-backup-alert';
            return;
        }

        backupStatusAlert.hidden = false;
        backupStatusAlert.className = 'metis-backup-alert ' + (paused ? 'is-warning' : 'is-error');
        backupStatusAlert.textContent = message;
    }

    function loadBackupHistory(options) {
        if (!backupHistoryBody) {
            return Promise.resolve(null);
        }
        const silent = !!(options && options.silent);

        const action = 'metis_backup_history_snapshot';
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
        body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

        if (backupHistoryRefreshBtn && !silent) {
            backupHistoryRefreshBtn.disabled = true;
        }
        if (backupHistoryStatusEl && !silent) {
            backupHistoryStatusEl.textContent = 'Loading history...';
        }

        return Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
            const runs = Array.isArray(data && data.runs ? data.runs : []) ? data.runs : [];
            renderBackupStatusAlert(runs, data && data.pause_status ? data.pause_status : null);
            renderBackupLiveStatus(runs, data && data.pause_status ? data.pause_status : null);
            backupHistoryBody.innerHTML = renderBackupHistoryRows(runs);
            bindBackupRestoreButtons(backupHistoryRoot || document);
            if (backupHistoryStatusEl) {
                backupHistoryStatusEl.textContent = String(data && data.message ? data.message : 'Backup history loaded.');
            }
            scheduleBackupLivePoll(backupHasActiveRun(runs) ? 5000 : 30000);
            return data;
        }).catch(function (error) {
            const message = error && error.message ? error.message : 'Backup history load failed.';
            if (!silent) {
                backupHistoryBody.innerHTML = '<tr><td colspan="6"><span class="metis-help" style="color:#b91c1c;">' + escapeHtml(message) + '</span></td></tr>';
            }
            if (backupHistoryStatusEl && !silent) {
                backupHistoryStatusEl.textContent = message;
            }
        }).finally(function () {
            if (backupHistoryRefreshBtn && !silent) {
                backupHistoryRefreshBtn.disabled = false;
            }
        });
    }

    function scheduleBackupLivePoll(delay) {
        if (!backupHistoryRoot) return;
        if (backupPollTimer) {
            window.clearTimeout(backupPollTimer);
        }
        backupPollTimer = window.setTimeout(function () {
            if (backupPollInFlight) {
                scheduleBackupLivePoll(5000);
                return;
            }
            backupPollInFlight = true;
            loadBackupHistory({ silent: true }).finally(function () {
                backupPollInFlight = false;
            });
        }, Math.max(3000, Number(delay) || 10000));
    }

    if (backupRunBtn) {
        backupRunBtn.addEventListener('click', function () {
            const action = 'metis_backup_run_now';
            const body = new FormData();
            const statusEl = document.querySelector('[data-backup-action-status]');
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = backupRunBtn.textContent;
            backupRunBtn.disabled = true;
            backupRunBtn.textContent = 'Queueing...';
            if (statusEl) statusEl.textContent = 'Queueing backup...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                const message = String(data.message || 'Backup queued.');
                showToast('success', message);
                if (statusEl) statusEl.textContent = message;
                window.setTimeout(function () {
                    loadBackupHistory();
                    scheduleBackupLivePoll(3000);
                }, 350);
            }).catch(function (error) {
                const message = error && error.message ? error.message : 'Backup failed.';
                showToast('error', message);
                if (statusEl) statusEl.textContent = message;
            }).finally(function () {
                backupRunBtn.disabled = false;
                backupRunBtn.textContent = originalLabel;
            });
        });
    }

    if (backupHistoryRefreshBtn) {
        backupHistoryRefreshBtn.addEventListener('click', loadBackupHistory);
    }
    bindBackupRestoreButtons();
    if (backupHistoryRoot) {
        loadBackupHistory();
    }

    const schedulerRoot = document.querySelector('[data-scheduler-live-root]');
    const financeModeRoot = document.querySelector('[data-finance-mode-root]');
    let schedulerPollTimer = null;
    let schedulerRefreshInFlight = false;
    let financeRefreshInFlight = false;

    function schedulerNonce(action) {
        const fallback = (window.metisAjax && window.metisAjax.nonce) || '';
        return Metis.ajax.nonceFor(action, fallback);
    }

    function schedulerCsrfAction(action) {
        const requestedAction = String(action || '').trim();
        return requestedAction ? ('metis_ajax:' + requestedAction) : '';
    }

    function schedulerAuthRejected(error) {
        const message = String((error && error.message) || '').toLowerCase();
        return message.indexOf('authentication is required') !== -1
            || message.indexOf('invalid session') !== -1
            || message.indexOf('session expired') !== -1;
    }

    function stopSchedulerPolling() {
        if (schedulerPollTimer) {
            window.clearInterval(schedulerPollTimer);
            schedulerPollTimer = null;
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
        });
    }

    function ucfirst(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    function postSchedulerUpdate(payload) {
        const action = 'metis_scheduler_update_task_settings';
        const body = new FormData();
        body.append('action', action);
        Object.keys(payload).forEach(function (key) {
            body.append(key, String(payload[key]));
        });
        body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
        body.append('metis_action_nonce', schedulerNonce(action));
        body.append('metis_csrf_action', schedulerCsrfAction(action));
        return Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.');
    }

    function fetchSchedulerSnapshot() {
        const action = 'metis_scheduler_status_snapshot';
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
        body.append('metis_action_nonce', schedulerNonce(action));
        body.append('metis_csrf_action', schedulerCsrfAction(action));
        return Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.');
    }

    function financeStatusAction() {
        if (!financeModeRoot) return '';
        return String(financeModeRoot.getAttribute('data-finance-status-action') || '').trim();
    }

    function financeSwitchStatusAction() {
        if (!financeModeRoot) return '';
        return String(financeModeRoot.getAttribute('data-finance-switch-status-action') || '').trim();
    }

    function financeScheduleAction() {
        if (!financeModeRoot) return '';
        return String(financeModeRoot.getAttribute('data-finance-schedule-action') || '').trim();
    }

    function financeScheduleNonce() {
        if (!financeModeRoot) return '';
        const nonce = String(financeModeRoot.getAttribute('data-finance-schedule-nonce') || '').trim();
        if (nonce) return nonce;
        return (window.metisAjax && window.metisAjax.nonce) || '';
    }

    function financeAjaxConfig() {
        if (window.Metis && Metis.request && typeof Metis.request.config === 'function') {
            return Metis.request.config(window.metisAjax || null, 'Settings AJAX not configured.');
        }
        return window.metisAjax || null;
    }

    function financeActionNonce(action) {
        const requestedAction = String(action || '').trim();
        const fallbackNonce = financeScheduleNonce() || ((window.metisAjax && window.metisAjax.nonce) || '');
        const scheduleAction = financeScheduleAction();

        if (requestedAction && scheduleAction && requestedAction === scheduleAction && financeScheduleNonce()) {
            return financeScheduleNonce();
        }

        if (window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function') {
            return Metis.ajax.nonceFor(requestedAction, fallbackNonce);
        }

        return fallbackNonce;
    }

    function parseFinanceJsonResponse(response) {
        return response.text().then(function (raw) {
            let json = null;
            try {
                json = raw ? JSON.parse(raw) : {};
            } catch (error) {
                throw new Error('Finance API returned an invalid response.');
            }

            if (!response.ok || !json || json.success !== true) {
                const message = json && json.data && json.data.message
                    ? String(json.data.message)
                    : 'Finance request failed.';
                throw new Error(message);
            }

            return json.data || {};
        });
    }

    function financeRequest(action, payload) {
        if (!action) {
            return Promise.reject(new Error('Finance action is not configured.'));
        }

        const ajaxConfig = financeAjaxConfig();
        const endpoint = ajaxConfig && (ajaxConfig.ajax_url || ajaxConfig.url)
            ? String(ajaxConfig.ajax_url || ajaxConfig.url).trim()
            : (typeof metisResolveAjaxUrl === 'function' ? metisResolveAjaxUrl() : '');
        const baseNonce = ajaxConfig && ajaxConfig.nonce ? String(ajaxConfig.nonce).trim() : '';
        const nonce = financeActionNonce(action);

        if (!endpoint) {
            return Promise.reject(new Error('Finance AJAX endpoint is not configured.'));
        }

        const body = new FormData();
        body.append('action', action);
        if (baseNonce) {
            body.append('nonce', baseNonce);
        }
        if (nonce) {
            body.append('metis_action_nonce', nonce);
        }
        const data = payload && typeof payload === 'object' ? payload : {};
        Object.keys(data).forEach(function (key) {
            body.append(key, String(data[key]));
        });

        return fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(nonce || baseNonce),
            body: body
        }).then(parseFinanceJsonResponse);
    }

    function formatFinanceDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return '-';
        if (window.Metis && Metis.time && typeof Metis.time.format === 'function') {
            return Metis.time.format(raw, { empty: raw }) || raw;
        }
        const parsed = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return raw;
        return parsed.toLocaleString();
    }

    function applyFinanceModeStatus(payload) {
        if (!financeModeRoot || !payload || typeof payload !== 'object') return;

        const switchPayload = payload.switch && typeof payload.switch === 'object'
            ? payload.switch
            : payload;

        const currentMode = String(
            payload.current_mode || switchPayload.current_mode || 'finance'
        ).toUpperCase();
        const switchStatus = String(switchPayload.switch_status || 'idle');
        const pending = switchPayload.pending_switch && typeof switchPayload.pending_switch === 'object'
            ? switchPayload.pending_switch
            : null;

        const currentModeEl = financeModeRoot.querySelector('[data-finance-current-mode="1"]');
        if (currentModeEl) {
            currentModeEl.textContent = currentMode;
        }

        const switchStatusEl = financeModeRoot.querySelector('[data-finance-switch-status="1"]');
        if (switchStatusEl) {
            switchStatusEl.textContent = ucfirst(switchStatus);
        }

        const badgeEl = financeModeRoot.querySelector('[data-finance-switch-status-badge="1"]');
        if (badgeEl) {
            badgeEl.textContent = ucfirst(switchStatus);
        }

        const pendingWrap = financeModeRoot.querySelector('[data-finance-pending-wrap="1"]');
        const pendingTarget = financeModeRoot.querySelector('[data-finance-pending-target="1"]');
        const pendingEffective = financeModeRoot.querySelector('[data-finance-pending-effective="1"]');
        const pendingQueue = financeModeRoot.querySelector('[data-finance-pending-queue="1"]');

        if (!pending) {
            if (pendingWrap) pendingWrap.style.display = 'none';
            return;
        }

        if (pendingWrap) pendingWrap.style.display = '';
        if (pendingTarget) pendingTarget.textContent = String(pending.target_mode || '').toUpperCase();
        if (pendingEffective) pendingEffective.textContent = formatFinanceDate(pending.effective_at || '');
        if (pendingQueue) pendingQueue.textContent = String(pending.queue_job_code || '-');
    }

    function refreshFinanceModeStatus() {
        if (!financeModeRoot || financeRefreshInFlight) {
            return Promise.resolve(null);
        }

        const statusAction = financeStatusAction();
        const switchAction = financeSwitchStatusAction();
        const action = switchAction || statusAction;
        if (!action) {
            return Promise.resolve(null);
        }

        financeRefreshInFlight = true;
        return financeRequest(action, null).then(function (data) {
            applyFinanceModeStatus(data || {});
            return data;
        }).catch(function () {
            return null;
        }).finally(function () {
            financeRefreshInFlight = false;
        });
    }

    function renderSchedulerHistoryRows(jobs) {
        if (!Array.isArray(jobs) || jobs.length === 0) {
            return [
                '<tr class="metis-premium-row">',
                '  <td class="metis-premium-cell"><strong>No queued cron job history yet.</strong></td>',
                '  <td class="metis-premium-cell">-</td>',
                '  <td class="metis-premium-cell">-</td>',
                '  <td class="metis-premium-cell">-</td>',
                '  <td class="metis-premium-cell">-</td>',
                '  <td class="metis-premium-cell">-</td>',
                '</tr>'
            ].join('');
        }

        return jobs.map(function (row) {
            const status = String((row && row.status) || 'unknown').toLowerCase();
            const safeStatus = status.replace(/[^a-z0-9_-]/g, '');
            const taskLabel = String((row && (row.task_label || row.task || row.label)) || 'Cron Task');
            const jobCode = String((row && row.job_code) || '');
            const started = String((row && row.started_at_display) || 'Pending');
            const finished = String((row && row.finished_at_display) || '-');
            const association = String((row && row.association) || 'Scheduled cron callback');
            const queueName = ucfirst(String((row && row.queue_name) || 'system'));
            const failed = String((row && row.last_error) || '') !== '';
            const jobDetail = failed
                ? '<div class="metis-help" style="color:#b91c1c;">Job failed. Review logs for details.</div>'
                : '<span class="metis-help">' + escapeHtml(queueName) + ' queue</span>';

            return [
                '<tr class="metis-premium-row">',
                '  <td class="metis-premium-cell"><div class="metis-scheduler-history-task"><strong>' + escapeHtml(taskLabel) + '</strong><code>' + escapeHtml(jobCode) + '</code></div></td>',
                '  <td class="metis-premium-cell">' + escapeHtml(association) + '</td>',
                '  <td class="metis-premium-cell"><span class="metis-status-chip is-' + safeStatus + '">' + escapeHtml(ucfirst(status || 'unknown')) + '</span></td>',
                '  <td class="metis-premium-cell">' + escapeHtml(started) + '</td>',
                '  <td class="metis-premium-cell">' + escapeHtml(finished) + '</td>',
                '  <td class="metis-premium-cell">' + jobDetail + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function applySchedulerSnapshot(snapshot) {
        if (!snapshot || typeof snapshot !== 'object') return;

        const queue = (snapshot.queue_summary && typeof snapshot.queue_summary === 'object') ? snapshot.queue_summary : {};
        Object.keys(queue).forEach(function (bucket) {
            const statuses = queue[bucket] && typeof queue[bucket] === 'object' ? queue[bucket] : {};
            Object.keys(statuses).forEach(function (status) {
                const target = document.querySelector('[data-scheduler-queue="' + bucket + ':' + status + '"]');
                if (target) {
                    target.textContent = String(statuses[status] == null ? 0 : statuses[status]);
                }
            });
        });

        const tasks = Array.isArray(snapshot.task_rows) ? snapshot.task_rows : [];
        tasks.forEach(function (task) {
            const slug = String((task && task.slug) || '').trim();
            if (!slug) return;
            const row = document.querySelector('[data-cron-task-row="' + slug + '"]');
            if (!row) return;

            const enabled = !!(task && task.enabled);
            row.setAttribute('data-cron-task-enabled', enabled ? '1' : '0');
            row.classList.toggle('is-enabled', enabled);
            row.classList.toggle('is-disabled', !enabled);

            const stateEl = document.querySelector('[data-cron-task-state="' + slug + '"]');
            if (stateEl) {
                stateEl.textContent = ucfirst(String((task && task.last_status) || 'never'));
            }

            const runEl = document.querySelector('[data-cron-task-last-run="' + slug + '"]');
            if (runEl) {
                runEl.textContent = String((task && task.last_finished_at_display) || 'Never');
            }

            const intervalInput = document.querySelector('[data-cron-task-interval="' + slug + '"]');
            if (intervalInput && document.activeElement !== intervalInput && task && task.interval_minutes) {
                intervalInput.value = String(task.interval_minutes);
            }
        });

        const historyBody = document.querySelector('[data-scheduler-history-body="1"]');
        if (historyBody) {
            historyBody.innerHTML = renderSchedulerHistoryRows(Array.isArray(snapshot.recent_jobs) ? snapshot.recent_jobs : []);
        }
    }

    function refreshSchedulerSnapshot() {
        if (!schedulerRoot || schedulerRefreshInFlight) {
            return Promise.resolve(null);
        }
        schedulerRefreshInFlight = true;
        return fetchSchedulerSnapshot().then(function (data) {
            applySchedulerSnapshot(data || {});
            return data;
        }).catch(function (error) {
            if (schedulerAuthRejected(error)) {
                stopSchedulerPolling();
            }
            return null;
        }).finally(function () {
            schedulerRefreshInFlight = false;
        });
    }

    if (schedulerRoot || financeModeRoot) {
        refreshSchedulerSnapshot();
        refreshFinanceModeStatus();
        schedulerPollTimer = window.setInterval(function () {
            refreshSchedulerSnapshot();
            refreshFinanceModeStatus();
        }, 10000);
        window.addEventListener('beforeunload', function () {
            if (schedulerPollTimer) {
                window.clearInterval(schedulerPollTimer);
                schedulerPollTimer = null;
            }
        });
    }

    document.querySelectorAll('[data-cron-task-row]').forEach(function (row) {
        row.addEventListener('dblclick', function (event) {
            if (!event.target.closest('[data-cron-task-row]')) return;
            if (event.target.closest('input, button, textarea, select, label, a')) return;

            const taskSlug = String(row.getAttribute('data-cron-task-row') || '').trim();
            if (!taskSlug) return;

            const isEnabled = row.getAttribute('data-cron-task-enabled') === '1';
            row.classList.add('is-saving');

            postSchedulerUpdate({
                task_slug: taskSlug,
                enabled: isEnabled ? '0' : '1',
            }).then(function (data) {
                const enabled = !!(data && data.task && data.task.enabled);
                row.setAttribute('data-cron-task-enabled', enabled ? '1' : '0');
                row.classList.toggle('is-enabled', enabled);
                row.classList.toggle('is-disabled', !enabled);
                showToast('success', enabled ? 'Task enabled.' : 'Task disabled.');
                return refreshSchedulerSnapshot();
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Task update failed.');
            }).finally(function () {
                row.classList.remove('is-saving');
            });
        });
    });

    document.querySelectorAll('[data-cron-task-interval]').forEach(function (input) {
        let lastSaved = String(input.value || '').trim();

        function saveInterval() {
            const taskSlug = String(input.getAttribute('data-cron-task-interval') || '').trim();
            const nextValue = String(input.value || '').trim();
            if (!taskSlug || nextValue === '' || nextValue === lastSaved) return;

            const row = input.closest('[data-cron-task-row]');
            if (row) row.classList.add('is-saving');

            postSchedulerUpdate({
                task_slug: taskSlug,
                interval_minutes: nextValue,
            }).then(function (data) {
                if (data && data.task && data.task.interval_minutes) {
                    input.value = String(data.task.interval_minutes);
                    lastSaved = String(data.task.interval_minutes);
                } else {
                    lastSaved = nextValue;
                }
                showToast('success', 'Cadence saved.');
                return refreshSchedulerSnapshot();
            }).catch(function (error) {
                input.value = lastSaved;
                showToast('error', error && error.message ? error.message : 'Cadence update failed.');
            }).finally(function () {
                if (row) row.classList.remove('is-saving');
            });
        }

        input.addEventListener('change', saveInterval);
        input.addEventListener('blur', saveInterval);
    });

    document.querySelectorAll('[data-cron-run-now]').forEach(function (button) {
        button.addEventListener('click', function () {
            const taskSlug = String(button.getAttribute('data-cron-run-now') || '').trim();
            if (!taskSlug) return;

            const action = 'metis_scheduler_run_task_now';
            const body = new FormData();
            body.append('action', action);
            body.append('task_slug', taskSlug);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', schedulerNonce(action));
            body.append('metis_csrf_action', schedulerCsrfAction(action));

            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Queueing...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Task queued.'));
                window.setTimeout(refreshSchedulerSnapshot, 350);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Task queue failed.');
            }).finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
        });
    });

    const financeScheduleForm = document.querySelector('[data-finance-schedule-form="1"]');
    if (financeScheduleForm && financeModeRoot) {
        financeScheduleForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const action = financeScheduleAction();
            if (!action) {
                showToast('error', 'Finance schedule action is not configured.');
                return;
            }

            const submitBtn = financeScheduleForm.querySelector('[data-finance-schedule-submit="1"]');
            const originalLabel = submitBtn ? submitBtn.textContent : 'Schedule Mode Switch';
            const targetMode = String((financeScheduleForm.querySelector('[name="target_mode"]') || {}).value || '').trim();
            const effectiveAt = String((financeScheduleForm.querySelector('[name="effective_at"]') || {}).value || '').trim();

            if (!targetMode || !effectiveAt) {
                showToast('error', 'Target mode and effective date/time are required.');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Scheduling...';
            }

            financeRequest(action, {
                target_mode: targetMode,
                effective_at: effectiveAt,
                nonce: financeScheduleNonce(),
                metis_action_nonce: financeScheduleNonce()
            }).then(function (data) {
                showToast('success', String((data && data.message) || 'Mode switch scheduled.'));
                return refreshFinanceModeStatus();
            }).then(function () {
                return refreshSchedulerSnapshot();
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Mode switch schedule failed.');
            }).finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                }
            });
        });
    }

    const buildBaselineBtn = document.querySelector('[data-integrity-build-baseline]');
    if (buildBaselineBtn) {
        buildBaselineBtn.addEventListener('click', function () {
            const action = 'metis_scheduler_build_integrity_baseline';
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = buildBaselineBtn.textContent;
            buildBaselineBtn.disabled = true;
            buildBaselineBtn.textContent = 'Queueing...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Baseline build queued.'));
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Baseline build failed.');
            }).finally(function () {
                buildBaselineBtn.disabled = false;
                buildBaselineBtn.textContent = originalLabel;
            });
        });
    }

    function settingsLiveRoot() {
        return document.querySelector('[data-settings-live-root]');
    }

    function settingsLiveFeedback() {
        const root = settingsLiveRoot();
        return root ? root.querySelector('[data-settings-live-feedback]') : null;
    }

    function setSettingsLiveFeedback(message, variant) {
        const target = settingsLiveFeedback();
        if (!target) return;
        if (!message) {
            target.innerHTML = '';
            return;
        }

        const tone = variant === 'error' ? ' style="color:#b91c1c;"' : (variant === 'warning' ? ' style="color:#92400e;"' : '');
        target.innerHTML = '<p class="metis-help"' + tone + '>' + escapeHtml(String(message)) + '</p>';
    }

    function refreshSettingsLiveRoot() {
        const currentRoot = settingsLiveRoot();
        const rootName = currentRoot ? String(currentRoot.getAttribute('data-settings-live-root') || '').trim() : '';
        if (!currentRoot || !rootName) {
            return Promise.resolve();
        }

        return fetch(window.location.href, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Unable to refresh the page state.');
            }
            return response.text();
        }).then(function (html) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const replacement = doc.querySelector('[data-settings-live-root="' + rootName + '"]');
            if (!replacement || !currentRoot.parentNode) {
                throw new Error('Updated page fragments were not available.');
            }

            currentRoot.replaceWith(replacement);
            bindSettingsAsyncActions(document);
        });
    }

    function beginSettingsAsyncButton(button, loadingLabel) {
        const originalText = String(button.textContent || '').trim();
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        if (button.classList.contains('metis-module-action') || button.classList.contains('metis-module-refresh')) {
            button.classList.add('is-loading');
        } else if (loadingLabel) {
            button.textContent = loadingLabel;
        }
        return function endSettingsAsyncButton() {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.classList.remove('is-loading');
            if (!(button.classList.contains('metis-module-action') || button.classList.contains('metis-module-refresh')) && loadingLabel) {
                button.textContent = originalText;
            }
        };
    }

    function settingsNonce(action) {
        return Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || '');
    }

    function postSettingsAction(action, body) {
        body.append('action', action);
        body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
        body.append('metis_action_nonce', settingsNonce(action));
        return Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.');
    }

    function moduleActionPrompt(kind, label) {
        if (kind === 'update') {
            return {
                message: 'Update ' + label + ' now?',
                title: 'Update Module',
                confirmLabel: 'Update'
            };
        }
        if (kind === 'reinstall') {
            return {
                message: 'Reinstall ' + label + ' now?',
                title: 'Reinstall Module',
                confirmLabel: 'Reinstall'
            };
        }
        return {
            message: 'Install ' + label + ' now?',
            title: 'Install Module',
            confirmLabel: 'Install'
        };
    }

    function bindSettingsAsyncActions(scope) {
        (scope || document).querySelectorAll('[data-release-check-updates]').forEach(function (button) {
            if (button.dataset.metisBound === '1') return;
            button.dataset.metisBound = '1';
            button.addEventListener('click', function () {
                const endLoading = beginSettingsAsyncButton(button, 'Refreshing...');
                setSettingsLiveFeedback('Refreshing module registry and update metadata...', '');

                postSettingsAction('metis_release_check_updates', new FormData()).then(function (data) {
                    return refreshSettingsLiveRoot().then(function () {
                        setSettingsLiveFeedback('', '');
                        showToast('success', String(data.message || 'Release metadata refreshed.'));
                    });
                }).catch(function (error) {
                    setSettingsLiveFeedback(error && error.message ? error.message : 'Release refresh failed.', 'error');
                    showToast('error', error && error.message ? error.message : 'Release refresh failed.');
                }).finally(function () {
                    endLoading();
                });
            });
        });

        (scope || document).querySelectorAll('[data-module-install-id]').forEach(function (button) {
            if (button.dataset.metisBound === '1') return;
            button.dataset.metisBound = '1';
            button.addEventListener('click', function () {
                const moduleId = String(button.getAttribute('data-module-install-id') || '').trim();
                const moduleName = String(button.getAttribute('data-module-install-name') || moduleId).trim();
                const moduleVersion = String(button.getAttribute('data-module-install-version') || '').trim();
                const actionKind = String(button.getAttribute('data-module-action-kind') || 'install').trim() || 'install';
                if (!moduleId) return;

                const label = moduleVersion ? (moduleName + ' ' + moduleVersion) : moduleName;
                const prompt = moduleActionPrompt(actionKind, label);
                confirmAction(prompt.message, {
                    title: prompt.title,
                    confirmLabel: prompt.confirmLabel
                }).then(function (confirmed) {
                    if (!confirmed) return;

                    const endLoading = beginSettingsAsyncButton(button);
                    setSettingsLiveFeedback(prompt.confirmLabel + 'ing ' + label + '...', '');

                    const body = new FormData();
                    body.append('module_id', moduleId);

                    postSettingsAction('metis_module_install_now', body).then(function (data) {
                        return refreshSettingsLiveRoot().then(function () {
                            setSettingsLiveFeedback('', '');
                            showToast('success', String((data && data.message) || 'Module installed.'));
                        });
                    }).catch(function (error) {
                        setSettingsLiveFeedback(error && error.message ? error.message : 'Module action failed.', 'error');
                        showToast('error', error && error.message ? error.message : 'Module installation failed.');
                    }).finally(function () {
                        endLoading();
                    });
                });
            });
        });

        (scope || document).querySelectorAll('[data-module-update-all]').forEach(function (button) {
            if (button.dataset.metisBound === '1') return;
            button.dataset.metisBound = '1';
            button.addEventListener('click', function () {
                confirmAction('Install every available module update now?', {
                    title: 'Update All Modules',
                    confirmLabel: 'Update All'
                }).then(function (confirmed) {
                    if (!confirmed) return;

                    const endLoading = beginSettingsAsyncButton(button);
                    setSettingsLiveFeedback('Installing available module updates...', '');

                    postSettingsAction('metis_module_install_all_updates', new FormData()).then(function (data) {
                        return refreshSettingsLiveRoot().then(function () {
                            setSettingsLiveFeedback('', '');
                            showToast('success', String((data && data.message) || 'Module updates applied.'));
                        });
                    }).catch(function (error) {
                        setSettingsLiveFeedback(error && error.message ? error.message : 'Module updates failed.', 'error');
                        showToast('error', error && error.message ? error.message : 'Module updates failed.');
                    }).finally(function () {
                        endLoading();
                    });
                });
            });
        });
    }

    bindSettingsAsyncActions(document);

    function releaseProgressToken() {
        const source = new Uint8Array(16);
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            window.crypto.getRandomValues(source);
            return Array.from(source).map(function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        }
        return String(Date.now()) + String(Math.random()).replace(/\D/g, '').slice(0, 18);
    }

    function releaseProgressPanel(button, tag) {
        let panel = document.querySelector('[data-release-progress-panel]');
        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'metis-release-progress';
            panel.setAttribute('data-release-progress-panel', '1');
            panel.innerHTML = [
                '<div class="metis-release-progress__head">',
                '  <strong data-release-progress-title>Release Update</strong>',
                '  <span data-release-progress-percent>0%</span>',
                '</div>',
                '<div class="metis-release-progress__bar" aria-hidden="true"><span data-release-progress-bar></span></div>',
                '<div class="metis-release-progress__status" data-release-progress-status>Preparing update...</div>'
            ].join('');
            const actions = button.closest('.metis-settings-actions');
            if (actions && actions.parentNode) {
                actions.parentNode.insertBefore(panel, actions.nextSibling);
            } else {
                button.insertAdjacentElement('afterend', panel);
            }
        }

        const title = panel.querySelector('[data-release-progress-title]');
        if (title) title.textContent = 'Release Update ' + tag;
        panel.classList.remove('is-complete', 'is-failed');
        panel.hidden = false;
        return panel;
    }

    function updateReleaseProgressPanel(panel, progress) {
        if (!panel) return;
        const percent = Math.max(0, Math.min(100, parseInt((progress && progress.percent) || 0, 10) || 0));
        const status = String((progress && progress.message) || 'Running update...');
        const bar = panel.querySelector('[data-release-progress-bar]');
        const percentEl = panel.querySelector('[data-release-progress-percent]');
        const statusEl = panel.querySelector('[data-release-progress-status]');
        if (bar) bar.style.width = percent + '%';
        if (percentEl) percentEl.textContent = percent + '%';
        if (statusEl) statusEl.textContent = status;
        panel.classList.toggle('is-complete', String((progress && progress.stage) || '') === 'complete');
        panel.classList.toggle('is-failed', String((progress && progress.stage) || '') === 'failed');
    }

    function currentReleaseProgressPercent(panel) {
        if (!panel) return 1;
        const percentEl = panel.querySelector('[data-release-progress-percent]');
        const percent = parseInt(percentEl ? percentEl.textContent : '', 10);
        return Number.isFinite(percent) ? Math.max(1, Math.min(99, percent)) : 1;
    }

    const releaseProgressInitialDelay = 250;
    const releaseProgressPollDelay = 1250;
    const releaseProgressMaxDelay = 6000;

    function pollReleaseProgress(token, panel, stopWhenDone) {
        const action = 'metis_release_apply_progress';
        const body = new FormData();
        body.append('action', action);
        body.append('progress_token', token);
        body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
        body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

        return Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
            const progress = data && data.progress ? data.progress : {};
            updateReleaseProgressPanel(panel, progress);
            return { ok: true, done: !!(stopWhenDone && progress && progress.done) };
        }).catch(function (error) {
            return { ok: false, done: false, message: error && error.message ? String(error.message) : 'Progress update failed.' };
        });
    }

    function startReleaseProgressPolling(token, panel) {
        let active = true;
        let timer = null;
        let inFlight = false;
        let failures = 0;

        const schedule = function (delay) {
            if (!active) return;
            window.clearTimeout(timer);
            timer = window.setTimeout(run, delay);
        };

        const run = function () {
            if (!active || inFlight) return;
            inFlight = true;
            pollReleaseProgress(token, panel, true).then(function (result) {
                if (!active) return;
                if (result && result.done) {
                    active = false;
                    window.clearTimeout(timer);
                    return;
                }
                if (result && result.ok) {
                    failures = 0;
                    schedule(releaseProgressPollDelay);
                    return;
                }
                failures += 1;
                schedule(Math.min(releaseProgressMaxDelay, releaseProgressPollDelay * (failures + 1)));
            }).finally(function () {
                inFlight = false;
            });
        };

        schedule(releaseProgressInitialDelay);
        return function () {
            active = false;
            window.clearTimeout(timer);
        };
    }

    document.querySelectorAll('[data-release-apply-tag]').forEach(function (button) {
        button.addEventListener('click', function () {
            const tag = String(button.getAttribute('data-release-apply-tag') || '').trim();
            if (!tag) return;
            confirmAction('Apply trusted release ' + tag + ' now? Metis will run integrity checks, create a backup, and update this installation directly.', {
                title: 'Apply Release',
                confirmLabel: 'Apply Release'
            }).then(function (confirmed) {
                if (!confirmed) return;
                const action = 'metis_release_apply_now';
                const token = releaseProgressToken();
                const panel = releaseProgressPanel(button, tag);
                const body = new FormData();
                body.append('action', action);
                body.append('tag', tag);
                body.append('progress_token', token);
                body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
                body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

                const originalLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Applying...';
                updateReleaseProgressPanel(panel, { percent: 1, message: 'Starting release update.' });

                const stopProgressPolling = startReleaseProgressPolling(token, panel);

                Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                    const result = data && data.release_result ? data.release_result : {};
                    const progress = data && data.progress ? data.progress : {
                        stage: result.ok ? 'complete' : 'failed',
                        percent: result.ok ? 100 : currentReleaseProgressPercent(panel),
                        message: String(data.message || (result.ok ? 'Release update completed.' : 'Release update failed.'))
                    };
                    updateReleaseProgressPanel(panel, progress);
                    if (result.ok) {
                        showToast('success', String(data.message || 'Release update completed.'));
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 1200);
                    } else {
                        showToast('error', String(data.message || result.message || 'Release update failed.'));
                    }
                }).catch(function (error) {
                    updateReleaseProgressPanel(panel, { stage: 'failed', percent: currentReleaseProgressPercent(panel), message: error && error.message ? error.message : 'Release update failed.' });
                    showToast('error', error && error.message ? error.message : 'Release update failed.');
                }).finally(function () {
                    stopProgressPolling();
                    window.setTimeout(function () {
                        pollReleaseProgress(token, panel, false);
                    }, 500);
                    button.disabled = false;
                    button.textContent = originalLabel;
                });
            });
        });
    });

    const releaseRollbackBtn = document.querySelector('[data-release-rollback]');
    if (releaseRollbackBtn) {
        releaseRollbackBtn.addEventListener('click', function () {
            confirmAction('Rollback to the previous trusted release? Metis will create a backup first.', {
                title: 'Rollback Release',
                confirmLabel: 'Rollback',
                tone: 'danger'
            }).then(function (confirmed) {
                if (!confirmed) return;
                const action = 'metis_release_rollback';
                const body = new FormData();
                body.append('action', action);
                body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
                body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

                const originalLabel = releaseRollbackBtn.textContent;
                releaseRollbackBtn.disabled = true;
                releaseRollbackBtn.textContent = 'Queueing...';

                Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                    showToast('success', String(data.message || 'Rollback queued.'));
                }).catch(function (error) {
                    showToast('error', error && error.message ? error.message : 'Rollback failed.');
                }).finally(function () {
                    releaseRollbackBtn.disabled = false;
                    releaseRollbackBtn.textContent = originalLabel;
                });
            });
        });
    }

    const checkerRoot = document.querySelector('[data-settings-checker-root]');
    if (checkerRoot) {
        const refreshBtn = checkerRoot.querySelector('[data-settings-checker-refresh]');
        const remediateBtn = checkerRoot.querySelector('[data-settings-checker-remediate]');
        const permissionPlanBtn = checkerRoot.querySelector('[data-settings-checker-permission-plan]');
        const statusEl = checkerRoot.querySelector('[data-settings-checker-status]');
        const scoreEl = checkerRoot.querySelector('[data-checker-score]');
        const generatedEl = checkerRoot.querySelector('[data-checker-generated]');
        const passEl = checkerRoot.querySelector('[data-checker-count="pass"]');
        const warnEl = checkerRoot.querySelector('[data-checker-count="warn"]');
        const failEl = checkerRoot.querySelector('[data-checker-count="fail"]');
        const tableEl = checkerRoot.querySelector('[data-settings-checker-results]');
        const kpiRoot = checkerRoot.querySelector('[data-settings-checker-kpis]');
        const tabsRoot = checkerRoot.querySelector('[data-settings-checker-tabs]');
        const tabCountAllEl = checkerRoot.querySelector('[data-checker-tab-count="all"]');
        const tabCountPassEl = checkerRoot.querySelector('[data-checker-tab-count="pass"]');
        const tabCountWarnEl = checkerRoot.querySelector('[data-checker-tab-count="warn"]');
        const tabCountFailEl = checkerRoot.querySelector('[data-checker-tab-count="fail"]');
        let checkerFilter = 'all';

        const renderCheckerRows = function (checks) {
            if (!Array.isArray(checks) || checks.length === 0) {
                return '<tr class="metis-premium-row"><td class="metis-premium-cell" colspan="5">No checks available.</td></tr>';
            }

            return checks.map(function (check) {
                const statusRaw = String((check && check.status) || 'warn').toLowerCase();
                const status = ['pass', 'warn', 'fail'].indexOf(statusRaw) >= 0 ? statusRaw : 'warn';
                const category = String((check && check.category) || 'general');
                const title = String((check && check.title) || 'Check');
                const message = String((check && check.message) || '');
                const recommendation = String((check && check.recommendation) || '');

                return [
                    '<tr class="metis-premium-row is-' + escapeHtml(status) + '" data-checker-status="' + escapeHtml(status) + '">',
                    '  <td class="metis-premium-cell metis-checker-status-cell"><strong class="metis-checker-status metis-checker-status-' + escapeHtml(status) + '">' + escapeHtml(status.toUpperCase()) + '</strong></td>',
                    '  <td class="metis-premium-cell metis-checker-category-cell">' + escapeHtml(ucfirst(category)) + '</td>',
                    '  <td class="metis-premium-cell metis-checker-check-cell">' + escapeHtml(title) + '</td>',
                    '  <td class="metis-premium-cell metis-checker-finding-cell">' + escapeHtml(message) + '</td>',
                    '  <td class="metis-premium-cell metis-checker-recommendation-cell">' + escapeHtml(recommendation || '-') + '</td>',
                    '</tr>'
                ].join('');
            }).join('');
        };

        const applyCheckerFilter = function () {
            if (!tableEl) return;
            const rows = Array.prototype.slice.call(tableEl.querySelectorAll('.metis-premium-row[data-checker-status]'));
            let visibleCount = 0;
            rows.forEach(function (row) {
                const status = String(row.getAttribute('data-checker-status') || '').toLowerCase();
                const shouldShow = checkerFilter === 'all' || status === checkerFilter;
                row.style.display = shouldShow ? '' : 'none';
                if (shouldShow) visibleCount += 1;
            });

            let emptyRow = tableEl.querySelector('[data-checker-empty-row]');
            if (visibleCount === 0) {
                if (!emptyRow) {
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'metis-premium-row';
                    emptyRow.setAttribute('data-checker-empty-row', '1');
                    emptyRow.innerHTML = '<td class="metis-premium-cell" colspan="5">No checks in this tab.</td>';
                    tableEl.appendChild(emptyRow);
                }
            } else if (emptyRow) {
                emptyRow.remove();
            }
        };

        const setCheckerFilter = function (filter) {
            checkerFilter = ['all', 'pass', 'warn', 'fail'].indexOf(filter) >= 0 ? filter : 'all';
            if (tabsRoot) {
                tabsRoot.querySelectorAll('[data-checker-filter]').forEach(function (btn) {
                    const isActive = String(btn.getAttribute('data-checker-filter') || '') === checkerFilter;
                    btn.classList.toggle('is-active', isActive);
                });
            }
            applyCheckerFilter();
        };

        const renderCheckerReport = function (report) {
            if (!report || typeof report !== 'object') return;
            const counts = (report.status_counts && typeof report.status_counts === 'object') ? report.status_counts : {};
            const checks = Array.isArray(report.checks) ? report.checks : [];
            const kpis = Array.isArray(report.kpis) ? report.kpis : [];

            if (scoreEl) scoreEl.textContent = String((report.score == null ? 0 : report.score)) + '/100';
            if (generatedEl) generatedEl.textContent = String(report.generated_at_display || report.generated_at || '-');
            if (passEl) passEl.textContent = String(counts.pass == null ? 0 : counts.pass);
            if (warnEl) warnEl.textContent = String(counts.warn == null ? 0 : counts.warn);
            if (failEl) failEl.textContent = String(counts.fail == null ? 0 : counts.fail);
            if (tabCountAllEl) tabCountAllEl.textContent = String(checks.length);
            if (tabCountPassEl) tabCountPassEl.textContent = String(counts.pass == null ? 0 : counts.pass);
            if (tabCountWarnEl) tabCountWarnEl.textContent = String(counts.warn == null ? 0 : counts.warn);
            if (tabCountFailEl) tabCountFailEl.textContent = String(counts.fail == null ? 0 : counts.fail);

            if (kpiRoot) {
                if (!kpis.length) {
                    kpiRoot.innerHTML = '';
                } else {
                    kpiRoot.innerHTML = kpis.map(function (kpi) {
                        const toneRaw = String((kpi && kpi.tone) || 'neutral').toLowerCase();
                        const tone = ['neutral', 'good', 'warn', 'bad'].indexOf(toneRaw) >= 0 ? toneRaw : 'neutral';
                        const label = String((kpi && kpi.label) || '');
                        const value = String((kpi && kpi.value) || '');
                        const hint = String((kpi && kpi.hint) || '');
                        return [
                            '<div class="metis-checker-kpi-card is-' + escapeHtml(tone) + '">',
                            '  <div class="metis-checker-kpi-label">' + escapeHtml(label) + '</div>',
                            '  <div class="metis-checker-kpi-value">' + escapeHtml(value) + '</div>',
                            '  <div class="metis-checker-kpi-hint">' + escapeHtml(hint) + '</div>',
                            '</div>'
                        ].join('');
                    }).join('');
                }
            }

            if (tableEl) {
                tableEl.innerHTML = renderCheckerRows(checks);
            }

            setCheckerFilter(checkerFilter);
        };

        if (tabsRoot) {
            tabsRoot.querySelectorAll('[data-checker-filter]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setCheckerFilter(String(btn.getAttribute('data-checker-filter') || 'all').toLowerCase());
                });
            });
            setCheckerFilter('all');
        }

        const requestCheckerSnapshot = function (silent) {
            const action = 'metis_settings_checker_snapshot';
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = refreshBtn ? refreshBtn.textContent : 'Run Checker';
            if (refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.textContent = 'Running...';
            }
            if (statusEl) statusEl.textContent = 'Running checker...';

            return Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                const report = data && data.report ? data.report : null;
                renderCheckerReport(report);
                if (statusEl) {
                    statusEl.textContent = String(data && data.message ? data.message : 'Checker report generated.');
                }
                if (!silent) {
                    showToast('success', 'Checker report updated.');
                }
            }).catch(function (error) {
                const message = error && error.message ? error.message : 'Checker run failed.';
                if (statusEl) statusEl.textContent = message;
                showToast('error', message);
            }).finally(function () {
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.textContent = originalLabel;
                }
            });
        };

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                requestCheckerSnapshot(false);
            });
        }

        if (remediateBtn) {
            remediateBtn.addEventListener('click', function () {
                const action = 'metis_settings_checker_remediate';
                const body = new FormData();
                body.append('action', action);
                body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
                body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

                const originalLabel = remediateBtn.textContent;
                remediateBtn.disabled = true;
                remediateBtn.textContent = 'Remediating...';
                if (statusEl) statusEl.textContent = 'Running automatic remediation...';

                Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                    const report = data && data.report ? data.report : null;
                    const actions = Array.isArray(data && data.actions ? data.actions : []) ? data.actions : [];
                    const failedActions = Array.isArray(data && data.failed_actions ? data.failed_actions : []) ? data.failed_actions : [];
                    const warnings = Array.isArray(data && data.warnings ? data.warnings : []) ? data.warnings : [];
                    const actionLabels = {
                        'cache.rebuild': 'Rebuilt system cache',
                        'queue.drain': 'Queued backlog cleanup',
                        'scheduler.enable_critical_tasks': 'Re-enabled critical scheduler tasks',
                        'scheduler.run_stale_tasks': 'Queued stale scheduler tasks to run now',
                        'backup.run': 'Queued immediate backup',
                        'release.check': 'Queued update check',
                        'filesystem.normalize_runtime_permissions': 'Normalized runtime folder permissions',
                        'scheduler.secret.generate': 'Generated scheduler shared secret'
                    };
                    const humanAction = function (name) {
                        const raw = String(name || '').trim();
                        if (!raw) return '';
                        return actionLabels[raw] || raw.replace(/[._-]+/g, ' ').replace(/\s+/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
                    };
                    const humanFailure = function (actionName, message) {
                        const readableAction = humanAction(actionName);
                        let text = String(message || '').trim();
                        if (!text) return readableAction;
                        text = text
                            .replace(/^Could not queue stale cron task "([^"]+)"\.$/i, 'Could not queue stale scheduler task "$1".')
                            .replace(/^Queue drain command could not be queued\.$/i, 'Could not queue backlog cleanup.')
                            .replace(/^Backup run could not be queued\.$/i, 'Could not queue an immediate backup.')
                            .replace(/^Release check could not be queued\.$/i, 'Could not queue the update check.')
                            .replace(/^Could not create runtime directory "([^"]+)"\.$/i, 'Could not create runtime directory "$1".')
                            .replace(/^Could not change permissions for "([^"]+)"\.$/i, 'Could not update permissions for "$1".')
                            .replace(/^Runtime directory "([^"]+)" is still not writable\.$/i, 'Runtime directory "$1" is still not writable.')
                            .replace(/^([A-Za-z\-]+) remediation failed:\s*/i, '');

                        return readableAction ? (readableAction + ': ' + text) : text;
                    };
                    renderCheckerReport(report);
                    if (statusEl) {
                        const actionList = actions.map(function (item) {
                            return humanAction((item && item.action) || '');
                        }).filter(Boolean);
                        const failureList = failedActions.map(function (item) {
                            const actionName = String((item && item.action) || '').trim();
                            const message = String((item && item.message) || '').trim();
                            if (!actionName && !message) return '';
                            return humanFailure(actionName, message || actionName);
                        }).filter(Boolean);
                        if (!failureList.length && warnings.length) {
                            warnings.forEach(function (item) {
                                const msg = String(item || '').trim();
                                if (msg) failureList.push(humanFailure('', msg));
                            });
                        }

                        const header = actions.length > 0
                            ? ('Remediation executed ' + String(actions.length) + ' action' + (actions.length === 1 ? '' : 's') + '.')
                            : 'No automatic remediation actions were available.';
                        const successHtml = actionList.length
                            ? ('<strong>Successful actions:</strong><ul class="metis-checker-remediation-list">' + actionList.map(function (name) {
                                return '<li>' + escapeHtml(name) + '</li>';
                            }).join('') + '</ul>')
                            : '<strong>Successful actions:</strong> None.';
                        const failedHtml = failureList.length
                            ? ('<strong>Failed actions:</strong><ul class="metis-checker-remediation-list is-failed">' + failureList.map(function (entry) {
                                return '<li>' + escapeHtml(entry) + '</li>';
                            }).join('') + '</ul>')
                            : '<strong>Failed actions:</strong> None.';

                        statusEl.innerHTML = '<div class="metis-checker-remediation-summary"><div>' + escapeHtml(header) + '</div><div>' + successHtml + '</div><div>' + failedHtml + '</div></div>';
                    }
                    if (warnings.length) {
                        const firstFailure = failedActions.length
                            ? humanFailure((failedActions[0] && failedActions[0].action) || '', (failedActions[0] && failedActions[0].message) || '')
                            : humanFailure('', String(warnings[0] || 'Manual follow-up required.'));
                        showToast('error', 'Remediation completed with failed actions: ' + firstFailure);
                    } else {
                        showToast('success', 'Automatic remediation completed.');
                    }
                }).catch(function (error) {
                    const message = error && error.message ? error.message : 'Remediation failed.';
                    if (statusEl) statusEl.textContent = message;
                    showToast('error', message);
                }).finally(function () {
                    remediateBtn.disabled = false;
                    remediateBtn.textContent = originalLabel;
                });
            });
        }

        if (permissionPlanBtn) {
            permissionPlanBtn.addEventListener('click', function () {
                const action = 'metis_settings_checker_permission_plan';
                const body = new FormData();
                body.append('action', action);
                body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
                body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

                const originalLabel = permissionPlanBtn.textContent;
                permissionPlanBtn.disabled = true;
                permissionPlanBtn.textContent = 'Generating...';
                if (statusEl) statusEl.textContent = 'Generating manual permission plan...';

                Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                    const targets = Array.isArray(data && data.targets ? data.targets : []) ? data.targets : [];
                    const commands = Array.isArray(data && data.commands ? data.commands : []) ? data.commands : [];
                    const notes = Array.isArray(data && data.notes ? data.notes : []) ? data.notes : [];

                    if (statusEl) {
                        const targetHtml = targets.length
                            ? ('<strong>Targets:</strong><ul class="metis-checker-remediation-list">' + targets.map(function (target) {
                                const label = String((target && target.label) || 'path');
                                const exists = !!(target && target.exists);
                                const currentMode = String((target && target.current_mode) || 'unknown');
                                const recommendation = String((target && target.recommended_mode) || '');
                                return '<li><strong>' + escapeHtml(label) + '</strong>: ' + (exists ? 'exists' : 'missing') + ', current mode ' + escapeHtml(currentMode) + ', recommended ' + escapeHtml(recommendation) + '.</li>';
                            }).join('') + '</ul>')
                            : '<strong>Targets:</strong> None returned.';

                        const commandsHtml = commands.length
                            ? ('<strong>Manual commands:</strong><pre class="metis-checker-command-plan">' + escapeHtml(commands.join('\n')) + '</pre>')
                            : '<strong>Manual commands:</strong> None returned.';

                        const notesHtml = notes.length
                            ? ('<strong>Notes:</strong><ul class="metis-checker-remediation-list">' + notes.map(function (note) {
                                return '<li>' + escapeHtml(String(note || '')) + '</li>';
                            }).join('') + '</ul>')
                            : '';

                        statusEl.innerHTML = '<div class="metis-checker-remediation-summary"><div>' + escapeHtml(String(data && data.message ? data.message : 'Manual permission plan generated.')) + '</div><div>' + targetHtml + '</div><div>' + commandsHtml + '</div><div>' + notesHtml + '</div></div>';
                    }

                    showToast('success', 'Manual permission plan generated.');
                }).catch(function (error) {
                    const message = error && error.message ? error.message : 'Permission plan generation failed.';
                    if (statusEl) statusEl.textContent = message;
                    showToast('error', message);
                }).finally(function () {
                    permissionPlanBtn.disabled = false;
                    permissionPlanBtn.textContent = originalLabel;
                });
            });
        }

        requestCheckerSnapshot(true);
    }

    const operationsCommandInput = document.querySelector('[data-operations-command-input]');
    const operationsCommandSubmit = document.querySelector('[data-operations-command-submit]');
    const operationsCommandStatus = document.querySelector('[data-operations-command-status]');

    if (operationsCommandInput && operationsCommandSubmit) {
        const submitOperationsCommand = function () {
            const command = String(operationsCommandInput.value || '').trim();
            if (!command) {
                showToast('error', 'Enter an approved command.');
                return;
            }

            const action = 'metis_operations_queue_command';
            const body = new FormData();
            body.append('action', action);
            body.append('command', command);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = operationsCommandSubmit.textContent;
            operationsCommandSubmit.disabled = true;
            operationsCommandSubmit.textContent = 'Queueing...';
            if (operationsCommandStatus) {
                operationsCommandStatus.textContent = 'Queueing command...';
            }

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                const message = String(data.message || 'Command queued.');
                showToast('success', message);
                if (operationsCommandStatus) {
                    operationsCommandStatus.textContent = message;
                }
            }).catch(function (error) {
                const message = error && error.message ? error.message : 'Command queue failed.';
                showToast('error', message);
                if (operationsCommandStatus) {
                    operationsCommandStatus.textContent = message;
                }
            }).finally(function () {
                operationsCommandSubmit.disabled = false;
                operationsCommandSubmit.textContent = originalLabel;
            });
        };

        operationsCommandSubmit.addEventListener('click', submitOperationsCommand);
        operationsCommandInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitOperationsCommand();
            }
        });
    }
});
