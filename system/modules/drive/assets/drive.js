document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('.metis-drive');
    if (!root) return;

    let ajax = null;
    try {
        ajax = (window.Metis && Metis.request && typeof Metis.request.config === 'function')
            ? Metis.request.config(window.metisDriveAjax || null, 'Drive AJAX not configured.')
            : (window.metisDriveAjax || window.metisAjax || null);
    } catch (_error) {
        return;
    }

    if (!ajax || !ajax.ajax_url || !ajax.nonce) return;

    const ACTIVE_FOLDER_POLL_MS = 5000;

    const canManage = String(root.dataset.canManage || '0') === '1';
    const sharedDriveId = String(root.dataset.sharedDriveId || '').trim();
    const usersHomeDriveId = String(root.dataset.usersHomeDriveId || '').trim();
    const initialUserFolderId = String(root.dataset.initialUserFolderId || '').trim();
    const initialUserFolderName = String(root.dataset.initialUserFolderName || '').trim();
    const initialFolderId = String(root.dataset.initialFolderId || '').trim();
    const driveConfigs = (function () {
        try {
            const parsed = JSON.parse(String(root.dataset.driveConfigs || '[]'));
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }());
    const initialFolderPayload = (function () {
        try {
            const parsed = JSON.parse(String(root.dataset.initialFolderPayload || '{}'));
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }());
    const confirmAction = function (message, options) {
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({ message: message }, options || {}));
        }
        return Promise.resolve(false);
    };

    const rowsEl = document.getElementById('metis-drive-rows');
    const treeEl = document.getElementById('metis-drive-tree');
    const searchEl = document.getElementById('metis-drive-search');
    const pathBarEl = document.getElementById('metis-drive-path-bar');
    const selCountEl = document.getElementById('metis-drive-selection-count');
    const loadingEl = document.getElementById('metis-drive-loading');
    const statusEl = document.getElementById('metis-drive-status');
    const browserEl = document.getElementById('metis-drive-browser');
    const refreshBtn = document.getElementById('metis-drive-refresh');
    const rootBtn = document.getElementById('metis-drive-root');
    const myFolderBtn = document.getElementById('metis-drive-my-folder');
    const upBtn = document.getElementById('metis-drive-up');
    const uploadBtn = document.getElementById('metis-drive-upload');
    const uploadInput = document.getElementById('metis-drive-upload-input');
    const newFolderBtn = document.getElementById('metis-drive-new-folder');
    const newGFileBtn = document.getElementById('metis-drive-new-google-file');
    const actionsBtn = document.getElementById('metis-drive-actions');
    const folderModal = document.getElementById('metis-drive-folder-modal');
    const folderForm = document.getElementById('metis-drive-folder-form');
    const folderNameEl = document.getElementById('metis-drive-folder-name');
    const gFileModal = document.getElementById('metis-drive-google-file-modal');
    const gFileForm = document.getElementById('metis-drive-google-file-form');
    const gFileType = document.getElementById('metis-drive-google-file-type');
    const gFileName = document.getElementById('metis-drive-google-file-name');
    const renameModal = document.getElementById('metis-drive-rename-modal');
    const renameForm = document.getElementById('metis-drive-rename-form');
    const renameIdEl = document.getElementById('metis-drive-rename-id');
    const renameNameEl = document.getElementById('metis-drive-rename-name');

    let currentDriveId = usersHomeDriveId || sharedDriveId;
    let folderId = initialFolderId || initialUserFolderId || currentDriveId || sharedDriveId;
    let folderNameNow = '';
    let parentId = '';
    let ownFolderId = initialUserFolderId || '';
    let ownFolderDriveId = String((initialFolderPayload && (initialFolderPayload.shared_drive_id || initialFolderPayload.drive_id)) || usersHomeDriveId || '').trim();
    let usersRootId = '';
    let selected = new Set();
    let searchTimer = null;
    let dropDepth = 0;
    let loadRequestSeq = 0;
    let activePollTimer = null;
    let activePollInFlight = false;
    let contextMenuEl = null;
    let clipboard = null;
    let dragState = null;
    let searchOverlayEl = null;
    let searchRequestSeq = 0;
    let lastSelectedId = '';

    const folderCache = new Map();
    const treeLoaded = new Map();
    const treeHasChildren = new Map();
    const treeLoading = new Set();
    const treeExpanded = new Set();

    let myFolderState = {
        driveId: ownFolderDriveId,
        folderId: initialUserFolderId || '',
        folderName: initialUserFolderName || 'My Folder',
        payload: initialFolderPayload && typeof initialFolderPayload === 'object' ? initialFolderPayload : null
    };

    const SVG = {
        folder: '<svg class="mds-file-icon mds-file-icon-folder" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>',
        doc: '<svg class="mds-file-icon mds-file-icon-doc" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/></svg>',
        sheet: '<svg class="mds-file-icon mds-file-icon-sheet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>',
        slides: '<svg class="mds-file-icon mds-file-icon-slides" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="4" width="20" height="14" rx="2"/><line x1="8" y1="22" x2="16" y2="22"/><line x1="12" y1="18" x2="12" y2="22"/></svg>',
        form: '<svg class="mds-file-icon mds-file-icon-form" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="12" y2="13"/><circle cx="9" cy="13" r="1" fill="currentColor" stroke="none"/><line x1="9" y1="17" x2="12" y2="17"/><circle cx="9" cy="17" r="1" fill="currentColor" stroke="none"/></svg>',
        pdf: '<svg class="mds-file-icon mds-file-icon-pdf" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 13h1.5a1.5 1.5 0 0 1 0 3H9v-3z"/><path d="M13 13v3"/><path d="M16 13h1"/><path d="M16 15.5h1"/></svg>',
        image: '<svg class="mds-file-icon mds-file-icon-image" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        video: '<svg class="mds-file-icon mds-file-icon-video" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>',
        file: '<svg class="mds-file-icon mds-file-icon-file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        openBtn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        renameBtn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        trashBtn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>'
    };

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function tkey(driveId, itemId) {
        return String(driveId || '') + '::' + String(itemId || '');
    }

    function folderCacheKey(driveId, itemId, search) {
        return tkey(driveId, itemId) + '::' + String(search || '').toLowerCase();
    }

    function nowId() {
        return folderId || currentDriveId || sharedDriveId;
    }

    function setCurrentDrive(id) {
        const value = String(id || '').trim();
        if (value) currentDriveId = value;
    }

    function driveLabel(id) {
        const match = driveConfigs.find(function (drive) {
            return String((drive && drive.drive_id) || '') === String(id || '');
        });
        return String((match && (match.label || match.drive_name)) || id || 'Drive');
    }

    function setStatus(message) {
        if (!statusEl) return;
        const text = String(message || '').trim();
        statusEl.hidden = !text;
        statusEl.textContent = text;
    }

    function setProgress(current, total, label) {
        const count = Math.max(0, parseInt(String(current || 0), 10) || 0);
        const max = Math.max(1, parseInt(String(total || 0), 10) || 1);
        const pct = Math.max(0, Math.min(100, Math.round((count / max) * 100)));
        const bar = loadingEl ? loadingEl.querySelector('span') : null;
        if (loadingEl) {
            loadingEl.classList.add('is-active', 'is-determinate');
            loadingEl.setAttribute('aria-hidden', 'false');
        }
        if (bar) {
            bar.style.width = pct + '%';
        }
        setStatus(`${label || 'Working…'} ${count}/${max}`);
    }

    function clearProgress(options) {
        if (loadingEl) {
            loadingEl.classList.remove('is-determinate');
            if (!(options && options.keepLoading)) {
                loadingEl.classList.remove('is-active');
                loadingEl.setAttribute('aria-hidden', 'true');
            }
        }
        const bar = loadingEl ? loadingEl.querySelector('span') : null;
        if (bar) {
            bar.style.width = '';
        }
        if (!(options && options.preserveStatus)) {
            setStatus('');
        }
    }

    function setBusy(btn, busy, label) {
        if (!btn) return;
        if (busy) {
            if (!btn.dataset.orig) btn.dataset.orig = btn.textContent.trim();
            btn.disabled = true;
            if (label) btn.textContent = label;
            return;
        }
        btn.disabled = false;
        if (btn.dataset.orig) {
            btn.textContent = btn.dataset.orig;
            delete btn.dataset.orig;
        }
    }

    function cacheFolderPayload(data, search) {
        const driveId = String((data && (data.drive_id || data.shared_drive_id)) || currentDriveId || sharedDriveId).trim();
        const targetFolderId = String((data && data.folder_id) || '').trim();
        if (!driveId || !targetFolderId) return;
        folderCache.set(folderCacheKey(driveId, targetFolderId, search), data);
    }

    function getCachedFolderPayload(driveId, targetFolderId, search) {
        return folderCache.get(folderCacheKey(driveId, targetFolderId, search)) || null;
    }

    function treeFoldersFromListPayload(data) {
        const files = Array.isArray(data && data.files) ? data.files : [];
        const parent = String((data && data.folder_id) || '').trim();
        return files.filter(function (file) {
            return String((file && file.mimeType) || '') === 'application/vnd.google-apps.folder';
        }).map(function (file) {
            return {
                id: String((file && file.id) || '').trim(),
                name: String((file && file.name) || 'Folder'),
                parent_id: String(((file && file.parents && file.parents[0]) || parent || '')).trim(),
                has_children: !!(file && file.hasChildren)
            };
        }).filter(function (folder) {
            return folder.id !== '' && folder.id !== usersRootId;
        });
    }

    function treeFoldersFromTreePayload(data) {
        const folders = Array.isArray(data && data.folders) ? data.folders : [];
        return folders.map(function (folder) {
            return {
                id: String((folder && folder.id) || '').trim(),
                name: String((folder && folder.name) || 'Folder'),
                parent_id: String((folder && folder.parent_id) || '').trim(),
                has_children: !!(folder && folder.has_children)
            };
        }).filter(function (folder) {
            return folder.id !== '' && folder.id !== usersRootId;
        });
    }

    function applyTreeChildren(driveId, targetFolderId, folders) {
        const key = tkey(driveId, targetFolderId);
        const list = Array.isArray(folders) ? folders : [];
        treeLoaded.set(key, list);
        treeHasChildren.set(key, list.length > 0);
        list.forEach(function (folder) {
            const childKey = tkey(driveId, folder.id);
            treeHasChildren.set(childKey, !!folder.has_children);
            if (!treeLoaded.has(childKey)) treeLoaded.set(childKey, null);
        });
    }

    function hydrateTreeFromFolderPayload(driveId, targetFolderId, data) {
        if (!data || typeof data !== 'object') return false;
        const folders = treeFoldersFromListPayload(data);
        applyTreeChildren(driveId, targetFolderId, folders);
        return folders.length > 0;
    }

    function rememberMyFolder(data) {
        if (!data || typeof data !== 'object') return;
        const driveId = String(data.drive_id || data.shared_drive_id || myFolderState.driveId || '').trim();
        const targetFolderId = String(data.folder_id || myFolderState.folderId || '').trim();
        if (!driveId || !targetFolderId) return;

        ownFolderDriveId = driveId;
        ownFolderId = targetFolderId;
        myFolderState = {
            driveId: driveId,
            folderId: targetFolderId,
            folderName: String(data.folder_name || myFolderState.folderName || 'My Folder'),
            payload: data
        };
        cacheFolderPayload(data, '');
        hydrateTreeFromFolderPayload(driveId, targetFolderId, data);
        if (!treeHasChildren.has(tkey(driveId, targetFolderId))) {
            treeHasChildren.set(tkey(driveId, targetFolderId), false);
        }
    }

    function currentTreePathKeys() {
        const keys = [];
        const driveId = String(currentDriveId || '').trim();
        let currentId = String(folderId || '').trim();
        let nextParent = String(parentId || '').trim();
        if (!driveId || !currentId) return keys;

        const visited = new Set();
        while (currentId && !visited.has(currentId)) {
            visited.add(currentId);
            keys.push(tkey(driveId, currentId));
            if (currentId === driveId) break;

            let parent = '';
            if (nextParent && nextParent !== currentId) {
                parent = nextParent;
                nextParent = '';
            } else {
                parent = findTreeParentId(driveId, currentId);
            }

            if (!parent || parent === currentId) break;
            currentId = String(parent);
        }

        return keys;
    }

    function persistCurrentPathExpanded() {
        currentTreePathKeys().forEach(function (key) {
            treeExpanded.add(key);
        });
    }

    function updateSelection() {
        const count = selected.size;
        if (selCountEl) selCountEl.textContent = count ? count + ' selected' : '0 selected';
        if (actionsBtn) actionsBtn.disabled = count === 0 || !canManage;
        rowsEl?.querySelectorAll('.metis-drive-row').forEach(function (row) {
            const rowId = String(row.dataset.id || '');
            row.classList.toggle('is-selected', rowId !== '' && selected.has(rowId));
        });
    }

    function renderedRowIds() {
        return Array.from(rowsEl?.querySelectorAll('.metis-drive-row') || []).map(function (row) {
            return String(row.dataset.id || '').trim();
        }).filter(Boolean);
    }

    function clearSelection() {
        selected.clear();
        lastSelectedId = '';
        updateSelection();
    }

    function selectSingle(id) {
        selected.clear();
        if (id) {
            selected.add(id);
            lastSelectedId = id;
        } else {
            lastSelectedId = '';
        }
        updateSelection();
    }

    function toggleSelection(id) {
        if (!id) return;
        if (selected.has(id)) {
            selected.delete(id);
        } else {
            selected.add(id);
        }
        lastSelectedId = id;
        updateSelection();
    }

    function rangeSelect(id) {
        if (!id) return;
        const ids = renderedRowIds();
        const end = ids.indexOf(id);
        if (end === -1) {
            selectSingle(id);
            return;
        }
        const anchorId = lastSelectedId && ids.includes(lastSelectedId) ? lastSelectedId : id;
        const start = ids.indexOf(anchorId);
        const from = Math.min(start, end);
        const to = Math.max(start, end);
        selected.clear();
        ids.slice(from, to + 1).forEach(function (rowId) {
            selected.add(rowId);
        });
        lastSelectedId = id;
        updateSelection();
    }

    function applyRowSelection(id, event) {
        const isMultiToggle = !!(event && (event.ctrlKey || event.metaKey));
        const isRange = !!(event && event.shiftKey);
        if (isRange) {
            rangeSelect(id);
            return;
        }
        if (isMultiToggle) {
            toggleSelection(id);
            return;
        }
        selectSingle(id);
    }

    function ensureRowInSelection(id, event) {
        if (!id) return;
        if (selected.has(id)) return;
        applyRowSelection(id, event);
    }

    function handleRowPointerSelection(event) {
        if (!event || event.button !== 0) return;
        if (event.target.closest('button,a,input')) return;
        const row = event.target.closest('.metis-drive-row');
        if (!row) return;

        const rowId = String(row.dataset.id || '').trim();
        if (!rowId) return;

        const wantsToggle = !!(event.ctrlKey || event.metaKey || event.shiftKey);
        if (wantsToggle || !selected.has(rowId)) {
            applyRowSelection(rowId, event);
        }
    }

    function updatePathBar(label) {
        if (!pathBarEl) return;
        const span = pathBarEl.querySelector('.mds-pathbar-text');
        if (span) span.textContent = '/ ' + (label || 'Root');
    }

    function fmtBytes(value) {
        const n = parseInt(String(value || '0'), 10);
        if (!n || n <= 0) return '—';
        const units = ['B', 'KB', 'MB', 'GB'];
        let idx = 0;
        let size = n;
        while (size >= 1024 && idx < units.length - 1) {
            size /= 1024;
            idx++;
        }
        return (idx === 0 ? String(Math.round(size)) : size.toFixed(1)) + ' ' + units[idx];
    }

    function fmtDate(value) {
        const date = new Date(String(value || ''));
        if (isNaN(date.getTime())) return '—';
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
            ' ' + date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    }

    function friendlyType(mime, name) {
        const m = String(mime || '').toLowerCase();
        const n = String(name || '').toLowerCase();
        if (m === 'application/vnd.google-apps.folder') return 'Folder';
        if (m === 'application/pdf') return 'PDF';
        if (m === 'application/vnd.google-apps.document') return 'Google Doc';
        if (m === 'application/vnd.google-apps.spreadsheet') return 'Google Sheet';
        if (m === 'application/vnd.google-apps.presentation') return 'Google Slides';
        if (m === 'application/vnd.google-apps.form') return 'Google Form';
        if (m.startsWith('image/')) return 'Image';
        if (m.startsWith('video/')) return 'Video';
        if (m.startsWith('audio/')) return 'Audio';
        if (n.endsWith('.doc') || n.endsWith('.docx')) return 'Word Doc';
        if (n.endsWith('.xls') || n.endsWith('.xlsx')) return 'Excel';
        if (n.endsWith('.ppt') || n.endsWith('.pptx')) return 'PowerPoint';
        if (n.endsWith('.csv')) return 'CSV';
        return 'File';
    }

    function fileIcon(mime) {
        const m = String(mime || '').toLowerCase();
        if (m === 'application/vnd.google-apps.folder') return SVG.folder;
        if (m === 'application/vnd.google-apps.document') return SVG.doc;
        if (m === 'application/vnd.google-apps.spreadsheet') return SVG.sheet;
        if (m === 'application/vnd.google-apps.presentation') return SVG.slides;
        if (m === 'application/vnd.google-apps.form') return SVG.form;
        if (m === 'application/pdf') return SVG.pdf;
        if (m.startsWith('image/')) return SVG.image;
        if (m.startsWith('video/')) return SVG.video;
        return SVG.file;
    }

    const openModal = Metis.modal.open;
    const closeModal = Metis.modal.close;

    function post(action, payload) {
        const form = new FormData();
        if (!Object.prototype.hasOwnProperty.call(payload || {}, 'drive_id') && currentDriveId) {
            form.set('drive_id', currentDriveId);
        }
        Object.keys(payload || {}).forEach(function (key) {
            form.set(key, payload[key] == null ? '' : String(payload[key]));
        });
        return Metis.request.postForm(ajax, action, form, 'Drive AJAX not configured.');
    }

    function postForm(action, fd) {
        if (!fd.has('drive_id') && currentDriveId) fd.set('drive_id', currentDriveId);
        return Metis.request.postForm(ajax, action, fd, 'Drive AJAX not configured.');
    }

    function triggerSync(driveId, targetFolderId, force, depth) {
        const form = new FormData();
        form.set('action', 'metis_drive_sync_worker');
        form.set('metis_action_nonce', Metis.ajax.nonceFor('metis_drive_sync_worker', ajax.nonce));
        form.set('nonce', ajax.nonce);
        form.set('drive_id', String(driveId || currentDriveId || sharedDriveId || ''));
        form.set('folder_id', String(targetFolderId || folderId || currentDriveId || sharedDriveId || ''));
        form.set('depth', String(Math.max(0, parseInt(String(depth == null ? 0 : depth), 10) || 0)));
        if (force) form.set('force', '1');

        return fetch(ajax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: form
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            });
        }).catch(function () {
            return null;
        });
    }

    function stopActiveFolderPolling() {
        if (activePollTimer) {
            window.clearTimeout(activePollTimer);
            activePollTimer = null;
        }
    }

    function scheduleActiveFolderPoll(delay) {
        stopActiveFolderPolling();
        if (document.hidden) return;
        if (!currentDriveId || !folderId) return;
        if (String(searchEl ? searchEl.value : '').trim() !== '') return;
        activePollTimer = window.setTimeout(runActiveFolderPoll, Math.max(1000, Number(delay || ACTIVE_FOLDER_POLL_MS)));
    }

    function runActiveFolderPoll() {
        stopActiveFolderPolling();
        if (document.hidden || activePollInFlight) {
            scheduleActiveFolderPoll(ACTIVE_FOLDER_POLL_MS);
            return;
        }

        const pollDriveId = String(currentDriveId || '').trim();
        const pollFolderId = String(folderId || '').trim();
        const pollSearch = String(searchEl ? searchEl.value : '').trim();
        if (!pollDriveId || !pollFolderId || pollSearch !== '') {
            scheduleActiveFolderPoll(ACTIVE_FOLDER_POLL_MS);
            return;
        }

        activePollInFlight = true;
        triggerSync(pollDriveId, pollFolderId, true, 0).then(function () {
            if (document.hidden) return;
            if (String(currentDriveId || '') !== pollDriveId || String(folderId || '') !== pollFolderId) return;
            return load({
                driveId: pollDriveId,
                folderId: pollFolderId,
                skipLoading: true,
                quietErrors: true
            });
        }).finally(function () {
            activePollInFlight = false;
            scheduleActiveFolderPoll(ACTIVE_FOLDER_POLL_MS);
        });
    }

    function clipboardLabel() {
        if (!clipboard || !clipboard.itemId) return '';
        const kind = clipboard.mode === 'cut' ? 'Cut' : 'Copy';
        return `${kind}: ${clipboard.itemName || 'Item'}`;
    }

    function setClipboard(mode, item) {
        if (!item || !item.itemId) {
            clipboard = null;
            return;
        }
        clipboard = {
            mode: mode === 'cut' ? 'cut' : 'copy',
            itemId: String(item.itemId || ''),
            itemName: String(item.itemName || 'Item'),
            isFolder: !!item.isFolder,
            driveId: String(item.driveId || currentDriveId || '')
        };
        setStatus(`${clipboardLabel()} ready to paste.`);
    }

    function clearClipboard(options) {
        clipboard = null;
        if (!(options && options.preserveStatus)) setStatus('');
    }

    function closeContextMenu() {
        if (!contextMenuEl) return;
        contextMenuEl.classList.remove('is-open');
        contextMenuEl.style.left = '-9999px';
        contextMenuEl.style.top = '-9999px';
        contextMenuEl.innerHTML = '';
        delete contextMenuEl.dataset.payload;
    }

    function ensureContextMenu() {
        if (contextMenuEl) return contextMenuEl;
        contextMenuEl = document.createElement('div');
        contextMenuEl.className = 'metis-drive-context-menu';
        root.appendChild(contextMenuEl);

        contextMenuEl.addEventListener('click', function (event) {
            const action = event.target.closest('.metis-drive-context-action');
            if (!action) return;
            let payload = {};
            try {
                payload = JSON.parse(String(contextMenuEl.dataset.payload || '{}'));
            } catch (e) {
                payload = {};
            }
            closeContextMenu();
            handleContextAction(String(action.dataset.action || ''), payload);
        });

        document.addEventListener('click', closeContextMenu);
        document.addEventListener('scroll', closeContextMenu, true);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeContextMenu();
                hideSearchOverlay();
            }
        });

        return contextMenuEl;
    }

    function ensureSearchOverlay() {
        if (searchOverlayEl) return searchOverlayEl;
        const host = document.querySelector('.metis-drive-main');
        if (!host) return null;
        searchOverlayEl = document.createElement('div');
        searchOverlayEl.className = 'metis-drive-search-overlay';
        searchOverlayEl.hidden = true;
        searchOverlayEl.innerHTML = '<div class="metis-drive-search-panel"></div>';
        host.appendChild(searchOverlayEl);

        searchOverlayEl.addEventListener('click', function (event) {
            const row = event.target.closest('.metis-drive-search-result');
            if (!row) {
                if (event.target === searchOverlayEl) hideSearchOverlay();
                return;
            }

            const isFolder = String(row.dataset.folder || '0') === '1';
            const targetId = String(row.dataset.id || '').trim();
            const driveId = String(row.dataset.driveId || currentDriveId).trim();
            const link = String(row.dataset.link || '').trim();

            if (isFolder && targetId) {
                if (searchEl) searchEl.value = '';
                hideSearchOverlay();
                setCurrentDrive(driveId);
                folderId = targetId;
                load();
                return;
            }

            if (link) window.open(link, '_blank', 'noopener');
        });

        return searchOverlayEl;
    }

    function hideSearchOverlay() {
        const overlay = ensureSearchOverlay();
        if (!overlay) return;
        overlay.hidden = true;
        const panel = overlay.querySelector('.metis-drive-search-panel');
        if (panel) panel.innerHTML = '';
    }

    function clearTreeDropTargets() {
        treeEl?.querySelectorAll('.metis-drive-tree-row.is-drop-target').forEach(function (row) {
            row.classList.remove('is-drop-target');
        });
    }

    function renderSearchOverlay(data, term) {
        const overlay = ensureSearchOverlay();
        if (!overlay) return;
        const panel = overlay.querySelector('.metis-drive-search-panel');
        if (!panel) return;

        const files = Array.isArray(data && data.files) ? data.files : [];
        const heading = `Search results for "${esc(term)}"`;
        if (!files.length) {
            panel.innerHTML = `<div class="metis-drive-search-head">${heading}</div><div class="metis-drive-search-empty">No cached matches found.</div>`;
            overlay.hidden = false;
            return;
        }

        panel.innerHTML = `<div class="metis-drive-search-head">${heading}</div>` + files.map(function (file) {
            const id = String(file.id || '');
            const name = esc(file.name || 'Untitled');
            const mime = String(file.mimeType || '');
            const isFolder = mime === 'application/vnd.google-apps.folder';
            const link = esc(file.webViewLink || '');
            const type = esc(friendlyType(mime, file.name || ''));
            const icon = fileIcon(mime);
            return `<button type="button" class="metis-drive-search-result" data-id="${esc(id)}" data-folder="${isFolder ? '1' : '0'}" data-drive-id="${esc(String(file.driveId || currentDriveId || ''))}" data-link="${link}">
                <span class="metis-drive-search-icon">${icon}</span>
                <span class="metis-drive-search-copy">
                    <span class="metis-drive-search-name">${name}</span>
                    <span class="metis-drive-search-type">${type}</span>
                </span>
            </button>`;
        }).join('');
        overlay.hidden = false;
    }

    function runSearch(term) {
        const query = String(term || '').trim();
        if (!query) {
            hideSearchOverlay();
            scheduleActiveFolderPoll(1500);
            return;
        }

        stopActiveFolderPolling();
        const requestId = ++searchRequestSeq;
        setStatus('Searching cached Drive index…');
        post('metis_drive_list', {
            folder_id: String(folderId || currentDriveId || '').trim(),
            drive_id: String(currentDriveId || '').trim(),
            search: query
        }).then(function (data) {
            if (requestId !== searchRequestSeq) return;
            renderSearchOverlay(data, query);
        }).catch(function (err) {
            if (requestId !== searchRequestSeq) return;
            hideSearchOverlay();
            Metis.toast.error((err && err.message) || 'Search failed.');
        }).finally(function () {
            if (requestId !== searchRequestSeq) return;
            setStatus('');
        });
    }

    function openContextMenu(x, y, payload, items) {
        const menu = ensureContextMenu();
        const list = Array.isArray(items) ? items : [];
        if (!list.length) return;
        menu.dataset.payload = JSON.stringify(payload || {});
        menu.innerHTML = list.map(function (item) {
            const disabled = item.disabled ? ' disabled' : '';
            const danger = item.danger ? ' is-danger' : '';
            return `<button type="button" class="metis-drive-context-action${danger}" data-action="${esc(item.action)}"${disabled}>${esc(item.label)}</button>`;
        }).join('');
        menu.classList.add('is-open');
        menu.style.left = '0px';
        menu.style.top = '0px';
        const rect = menu.getBoundingClientRect();
        const margin = 12;
        const left = Math.max(margin, Math.min(x, window.innerWidth - rect.width - margin));
        const top = Math.max(margin, Math.min(y, window.innerHeight - rect.height - margin));
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    }

    function openRenameModal(id, name) {
        if (!canManage) return;
        if (renameIdEl) renameIdEl.value = String(id || '');
        if (renameNameEl) renameNameEl.value = String(name || '');
        openModal(renameModal);
    }

    function trashItems(ids) {
        const itemIds = Array.from(ids || []).filter(Boolean);
        if (!canManage || !itemIds.length) return Promise.resolve(false);
        const label = itemIds.length === 1 ? 'this item' : `${itemIds.length} items`;
        return confirmAction(`Move ${label} to trash?`, {
            title: 'Move To Trash',
            confirmLabel: 'Move To Trash',
            tone: 'danger'
        }).then(function (confirmed) {
            if (!confirmed) return false;

            let chain = Promise.resolve();
            itemIds.forEach(function (id) {
                chain = chain.then(function () {
                    return post('metis_drive_trash', { file_id: id });
                });
            });

            return chain.then(function () {
                selected.forEach(function (id) {
                    if (itemIds.includes(id)) selected.delete(id);
                });
                updateSelection();
                Metis.toast.success(itemIds.length === 1 ? 'Moved to trash.' : `${itemIds.length} item(s) moved to trash.`);
                return load({ skipLoading: true }).then(function () {
                    return true;
                });
            }).catch(function (err) {
                Metis.toast.error((err && err.message) || 'Failed to trash items.');
                return false;
            });
        });
    }

    function moveItemsToFolder(ids, targetFolderId, driveId, options) {
        const itemIds = Array.from(ids || []).filter(Boolean);
        if (!canManage || !itemIds.length || !targetFolderId) return Promise.resolve(false);
        setProgress(0, itemIds.length, itemIds.length === 1 ? 'Moving item…' : 'Moving items…');

        let chain = Promise.resolve();
        itemIds.forEach(function (id, index) {
            chain = chain.then(function () {
                return post('metis_drive_move_item', {
                    file_id: id,
                    drive_id: driveId || currentDriveId,
                    target_parent_id: targetFolderId
                }).then(function (result) {
                    setProgress(index + 1, itemIds.length, itemIds.length === 1 ? 'Moving item…' : 'Moving items…');
                    return result;
                });
            });
        });

        return chain.then(function () {
            Metis.toast.success((options && options.successMessage) || (itemIds.length === 1 ? 'Item moved.' : `${itemIds.length} items moved.`));
            return load({ skipLoading: true }).then(function () {
                return true;
            });
        }).catch(function (err) {
            Metis.toast.error((err && err.message) || 'Failed to move items.');
            return false;
        }).finally(function () {
            clearProgress({ preserveStatus: !!(clipboard && clipboard.itemId) });
            if (clipboard && clipboard.itemId) {
                setStatus(`${clipboardLabel()} ready to paste.`);
            }
        });
    }

    function copyItemsToFolder(ids, targetFolderId, driveId, options) {
        const itemIds = Array.from(ids || []).filter(Boolean);
        if (!canManage || !itemIds.length || !targetFolderId) return Promise.resolve(false);
        setProgress(0, itemIds.length, itemIds.length === 1 ? 'Copying item…' : 'Copying items…');

        let chain = Promise.resolve();
        itemIds.forEach(function (id, index) {
            chain = chain.then(function () {
                return post('metis_drive_copy_item', {
                    file_id: id,
                    drive_id: driveId || currentDriveId,
                    target_parent_id: targetFolderId
                }).then(function (result) {
                    setProgress(index + 1, itemIds.length, itemIds.length === 1 ? 'Copying item…' : 'Copying items…');
                    return result;
                });
            });
        });

        return chain.then(function () {
            Metis.toast.success((options && options.successMessage) || (itemIds.length === 1 ? 'Item copied.' : `${itemIds.length} items copied.`));
            return load({ skipLoading: true }).then(function () {
                return true;
            });
        }).catch(function (err) {
            Metis.toast.error((err && err.message) || 'Failed to copy items.');
            return false;
        }).finally(function () {
            clearProgress({ preserveStatus: !!(clipboard && clipboard.itemId) });
            if (clipboard && clipboard.itemId) {
                setStatus(`${clipboardLabel()} ready to paste.`);
            }
        });
    }

    function pasteClipboardInto(targetFolderId, driveId) {
        if (!clipboard || !clipboard.itemId) return Promise.resolve(false);
        if (clipboard.mode === 'cut') {
            return moveItemsToFolder([clipboard.itemId], targetFolderId, driveId, { successMessage: 'Item moved.' }).then(function (ok) {
                if (ok) clearClipboard();
                return ok;
            });
        }
        return copyItemsToFolder([clipboard.itemId], targetFolderId, driveId, { successMessage: 'Item copied.' }).then(function (ok) {
            if (ok) setStatus(`${clipboardLabel()} ready to paste again.`);
            return ok;
        });
    }

    function createGoogleFile(type) {
        return post('metis_drive_create_google_file', {
            parent_id: folderId,
            drive_id: currentDriveId,
            google_type: type,
            name: ''
        }).then(function (data) {
            Metis.toast.success('File created.');
            const link = String(((data && data.file) || {}).webViewLink || '');
            if (link) window.open(link, '_blank', 'noopener');
            return load({ skipLoading: true });
        }).catch(function (err) {
            Metis.toast.error((err && err.message) || 'Failed to create file.');
        });
    }

    function buildItemContextMenu(row) {
        const rowId = String(row.dataset.id || '').trim();
        const isFolder = String(row.dataset.folder || '0') === '1';
        const name = String(row.dataset.name || 'Item');
        const link = String(row.dataset.link || '').trim();
        const driveId = String(row.dataset.driveId || currentDriveId || '').trim();
        const items = [];

        if (isFolder) {
            items.push({ action: 'open-folder', label: 'Open' });
            items.push({ action: 'paste-into-folder', label: 'Paste', disabled: !(clipboard && clipboard.itemId) });
            items.push({ action: 'cut-item', label: 'Cut' });
            items.push({ action: 'rename-item', label: 'Rename' });
            items.push({ action: 'delete-item', label: 'Delete', danger: true });
            items.push({ action: 'sync-folder', label: 'Force Sync Folder' });
        } else {
            if (link) items.push({ action: 'open-file', label: 'Open' });
            items.push({ action: 'copy-item', label: 'Copy' });
            items.push({ action: 'cut-item', label: 'Cut' });
            items.push({ action: 'rename-item', label: 'Rename' });
            items.push({ action: 'delete-item', label: 'Delete', danger: true });
        }

        return {
            payload: {
                scope: 'item',
                itemId: rowId,
                itemName: name,
                isFolder: isFolder,
                link: link,
                folderId: isFolder ? rowId : folderId,
                driveId: driveId
            },
            items: items
        };
    }

    function buildBlankContextMenu() {
        return {
            payload: {
                scope: 'blank',
                folderId: folderId,
                driveId: currentDriveId
            },
            items: [
                { action: 'paste-here', label: 'Paste', disabled: !(clipboard && clipboard.itemId) },
                { action: 'new-folder', label: 'Add Folder' },
                { action: 'new-google-doc', label: 'Add Google Doc' },
                { action: 'new-google-sheet', label: 'Add Google Sheet' },
                { action: 'new-google-slides', label: 'Add Google Slides' },
                { action: 'new-google-form', label: 'Add Google Form' }
            ]
        };
    }

    function openBlankAreaContextMenu(event) {
        if (!canManage) return;
        const blankMenu = buildBlankContextMenu();
        event.preventDefault();
        openContextMenu(event.clientX, event.clientY, blankMenu.payload, blankMenu.items);
    }

    function handleContextAction(action, payload) {
        const data = payload && typeof payload === 'object' ? payload : {};
        switch (action) {
            case 'open-file':
                if (data.link) window.open(String(data.link), '_blank', 'noopener');
                break;
            case 'open-folder':
                if (data.folderId) {
                    setCurrentDrive(String(data.driveId || currentDriveId));
                    folderId = String(data.folderId);
                    load();
                }
                break;
            case 'copy-item':
                if (data.isFolder) {
                    Metis.toast.error('Folders cannot be copied yet.');
                    break;
                }
                setClipboard('copy', data);
                break;
            case 'cut-item':
                setClipboard('cut', data);
                break;
            case 'rename-item':
                openRenameModal(data.itemId, data.itemName);
                break;
            case 'delete-item':
                trashItems([data.itemId]);
                break;
            case 'paste-into-folder':
            case 'paste-here':
                pasteClipboardInto(String(data.folderId || folderId), String(data.driveId || currentDriveId));
                break;
            case 'new-folder':
                if (folderNameEl) folderNameEl.value = '';
                openModal(folderModal);
                break;
            case 'new-google-doc':
                createGoogleFile('doc');
                break;
            case 'new-google-sheet':
                createGoogleFile('sheet');
                break;
            case 'new-google-slides':
                createGoogleFile('slides');
                break;
            case 'new-google-form':
                createGoogleFile('form');
                break;
            case 'sync-folder':
                if (!data.folderId) break;
                triggerSync(String(data.driveId || currentDriveId), String(data.folderId), true, 2).then(function () {
                    if (String(currentDriveId || '') !== String(data.driveId || currentDriveId) || String(folderId || '') !== String(data.folderId || '')) return;
                    load({ driveId: String(data.driveId || currentDriveId), folderId: String(data.folderId), skipLoading: true, quietErrors: true });
                });
                break;
            default:
                break;
        }
    }

    function isTreeBranch(targetFolderId, driveId) {
        const key = tkey(driveId, targetFolderId);
        if (treeHasChildren.has(key)) return !!treeHasChildren.get(key);
        const children = treeLoaded.get(key);
        return Array.isArray(children) && children.length > 0;
    }

    function findTreeParentId(driveId, childId) {
        let found = '';
        treeLoaded.forEach(function (children, key) {
            if (found || !Array.isArray(children)) return;
            const match = children.some(function (child) {
                return String((child && child.id) || '') === String(childId || '');
            });
            if (match) found = String(key.split('::')[1] || '');
        });
        return found;
    }

    function loadTreeChildren(targetFolderId, driveId, force) {
        const key = tkey(driveId, targetFolderId);
        const cached = treeLoaded.get(key);
        if (!force && Array.isArray(cached)) return Promise.resolve(cached);
        if (treeLoading.has(key)) return Promise.resolve([]);

        treeLoading.add(key);
        renderTree();
        return post('metis_drive_tree_children', {
            folder_id: targetFolderId,
            drive_id: driveId
        }).then(function (data) {
            const folders = treeFoldersFromTreePayload(data);
            applyTreeChildren(driveId, targetFolderId, folders);
            if (!usersRootId && data && data.users_root_id) usersRootId = String(data.users_root_id || '');
            return folders;
        }).catch(function () {
            treeLoaded.set(key, []);
            treeHasChildren.set(key, false);
            return [];
        }).finally(function () {
            treeLoading.delete(key);
            renderTree();
        });
    }

    function hydrateDriveRootChildren(driveId) {
        const key = tkey(driveId, driveId);
        if (Array.isArray(treeLoaded.get(key))) return Promise.resolve(treeLoaded.get(key) || []);
        return loadTreeChildren(driveId, driveId, false);
    }

    function treeNode(label, targetFolderId, depth, active, expanded, hasChildren, driveId) {
        const indent = depth * 16;
        const toggle = hasChildren
            ? `<button type="button" class="metis-drive-tree-toggle" data-folder-id="${esc(targetFolderId)}" data-drive-id="${esc(driveId)}" aria-label="Toggle">${expanded ? '−' : '+'}</button>`
            : '<span class="metis-drive-tree-toggle metis-drive-tree-toggle-empty"></span>';
        return `<div class="metis-drive-tree-row${active ? ' is-active' : ''}" style="--metis-tree-indent:${indent}px;">
            <div class="metis-drive-tree-indent"></div>
            ${toggle}
            <button type="button" class="metis-drive-tree-label" data-folder-id="${esc(targetFolderId)}" data-drive-id="${esc(driveId)}">${esc(label)}</button>
            <span></span>
        </div>`;
    }

    function renderTreeBranch(targetFolderId, label, depth, driveId) {
        const key = tkey(driveId, targetFolderId);
        const active = targetFolderId === nowId() && String(driveId || '') === String(currentDriveId || '');
        const expanded = treeExpanded.has(key);
        const children = treeLoaded.get(key);
        const hasChildren = isTreeBranch(targetFolderId, driveId);
        let html = treeNode(label, targetFolderId, depth, active, expanded, hasChildren, driveId);

        if (expanded && hasChildren) {
            if (children === null) {
                loadTreeChildren(targetFolderId, driveId, false);
            } else if (Array.isArray(children) && children.length) {
                html += '<div class="metis-drive-tree-children">';
                children.forEach(function (child) {
                    if (!child || !child.id || child.id === usersRootId) return;
                    html += renderTreeBranch(String(child.id), String(child.name || 'Folder'), depth + 1, driveId);
                });
                html += '</div>';
            }
        }

        return html;
    }

    function renderTree() {
        if (!treeEl) return;

        if (ownFolderId) {
            const ownDriveId = ownFolderDriveId || usersHomeDriveId || currentDriveId;
            if (!treeLoaded.has(tkey(ownDriveId, ownFolderId))) {
                const ownPayload = getCachedFolderPayload(ownDriveId, ownFolderId, '') || myFolderState.payload || null;
                hydrateTreeFromFolderPayload(ownDriveId, ownFolderId, ownPayload);
                if (!treeLoaded.has(tkey(ownDriveId, ownFolderId))) {
                    treeLoaded.set(tkey(ownDriveId, ownFolderId), null);
                    treeHasChildren.set(tkey(ownDriveId, ownFolderId), false);
                }
            }
        }

        let html = '';
        if (ownFolderId) {
            html += renderTreeBranch(ownFolderId, 'My Folder', 0, ownFolderDriveId || usersHomeDriveId || currentDriveId);
        }

        driveConfigs.forEach(function (drive) {
            const driveId = String((drive && drive.drive_id) || '').trim();
            if (!driveId || Number((drive && drive.is_users_home) || 0)) return;
            if (!treeLoaded.has(tkey(driveId, driveId))) {
                treeLoaded.set(tkey(driveId, driveId), null);
            }
            if (!treeHasChildren.has(tkey(driveId, driveId))) {
                treeHasChildren.set(tkey(driveId, driveId), false);
            }
            html += renderTreeBranch(driveId, String((drive && drive.label) || driveId), 0, driveId);
        });

        treeEl.innerHTML = html;
    }

    function renderRows(files) {
        const list = (Array.isArray(files) ? files : []).filter(function (file) {
            return String((file && file.id) || '') !== usersRootId;
        });
        if (!rowsEl) return;

        if (!list.length) {
            rowsEl.innerHTML = '<div class="metis-drive-empty">This folder is empty.</div>';
            renderTree();
            return;
        }

        rowsEl.innerHTML = list.map(function (file) {
            const id = String(file.id || '');
            const name = esc(file.name || 'Untitled');
            const mime = String(file.mimeType || '');
            const isFolder = mime === 'application/vnd.google-apps.folder';
            const link = esc(file.webViewLink || '');
            const icon = fileIcon(mime);
            const type = esc(friendlyType(mime, file.name || ''));
            const size = isFolder ? '—' : fmtBytes(file.size);
            const date = fmtDate(file.modifiedTime);

            const actionBtns = [];
            if (!isFolder && link) {
                actionBtns.push(`<button type="button" class="mds-row-btn mds-row-btn-open" title="Open" data-link="${link}">${SVG.openBtn}</button>`);
            }
            if (canManage) {
                actionBtns.push(`<button type="button" class="mds-row-btn mds-row-btn-rename metis-drive-rename" title="Rename" data-id="${esc(id)}" data-name="${name}">${SVG.renameBtn}</button>`);
                actionBtns.push(`<button type="button" class="mds-row-btn mds-row-btn-trash metis-drive-trash" title="Move to Trash" data-id="${esc(id)}">${SVG.trashBtn}</button>`);
            }

            return `<div class="metis-drive-row" data-id="${esc(id)}" data-folder="${isFolder ? '1' : '0'}" data-name="${name}" data-drive-id="${esc(currentDriveId)}" data-link="${link}"${canManage ? ' draggable="true"' : ''}>
                <div class="metis-drive-name-cell">${icon}<span>${name}</span></div>
                <div>${type}</div>
                <div>${size}</div>
                <div class="metis-drive-updated-cell">${date}</div>
                <div><div class="metis-drive-actions">${actionBtns.join('')}</div></div>
            </div>`;
        }).join('');

        const folders = list.filter(function (file) {
            return String(file.mimeType || '') === 'application/vnd.google-apps.folder' && String(file.id || '') !== usersRootId;
        }).map(function (file) {
            return {
                id: String(file.id || '').trim(),
                name: String(file.name || 'Folder'),
                parent_id: nowId(),
                has_children: !!file.hasChildren
            };
        });
        applyTreeChildren(currentDriveId, nowId(), folders);
        renderTree();
        updateSelection();
    }

    function applyFolderData(data) {
        selected.clear();
        lastSelectedId = '';
        const driveId = String((data && (data.drive_id || data.shared_drive_id)) || currentDriveId || sharedDriveId).trim();
        setCurrentDrive(driveId);
        folderId = String((data && data.folder_id) || currentDriveId || sharedDriveId).trim();
        folderNameNow = String((data && data.folder_name) || '').trim();
        parentId = String((data && data.parent_id) || '').trim();
        ownFolderId = String((data && data.own_folder_id) || ownFolderId || '').trim();
        if (folderId === ownFolderId) {
            ownFolderDriveId = currentDriveId;
            rememberMyFolder(data);
        }
        usersRootId = String((data && data.users_root_id) || usersRootId || '').trim();
        hydrateTreeFromFolderPayload(currentDriveId, folderId, data);
        persistCurrentPathExpanded();

        const label = String((data && data.shared_drive_label) || driveLabel(currentDriveId));
        if (folderId === ownFolderId) parentId = currentDriveId;
        if (upBtn) upBtn.disabled = !parentId;
        updatePathBar(folderNameNow || label);
        renderRows((data && data.files) || []);
        cacheFolderPayload(data, '');
        if (searchEl && !String(searchEl.value || '').trim()) {
            hideSearchOverlay();
        }
    }

    function applyTreeFolderPreview(driveId, targetFolderId, label) {
        const children = treeLoaded.get(tkey(driveId, targetFolderId));
        if (!Array.isArray(children)) return false;

        setCurrentDrive(driveId);
        folderId = String(targetFolderId || '').trim();
        folderNameNow = String(label || folderNameNow || 'Folder').trim();
        parentId = findTreeParentId(driveId, targetFolderId);
        if (folderId === ownFolderId) parentId = currentDriveId;
        persistCurrentPathExpanded();
        if (upBtn) upBtn.disabled = !parentId;
        updatePathBar(folderNameNow || driveLabel(currentDriveId));
        selected.clear();
        renderRows(children.map(function (child) {
            return {
                id: String(child.id || ''),
                name: String(child.name || 'Folder'),
                mimeType: 'application/vnd.google-apps.folder',
                size: '',
                modifiedTime: '',
                webViewLink: '',
                parents: [String(targetFolderId || '')],
                driveId: String(driveId || ''),
                hasChildren: !!child.has_children
            };
        }));
        return true;
    }

    function load(opts) {
        const options = opts || {};
        stopActiveFolderPolling();
        if (options.driveId) setCurrentDrive(options.driveId);
        if (options.folderId) folderId = String(options.folderId || folderId).trim();

        const requestId = ++loadRequestSeq;
        const requestedDriveId = String(currentDriveId || '').trim();
        const requestedFolderId = String(folderId || '').trim();
        const cached = !options.force ? getCachedFolderPayload(requestedDriveId, requestedFolderId, '') : null;

        if (cached) {
            applyFolderData(cached);
        }
        if (loadingEl) {
            const showLoading = !cached && !options.skipLoading;
            loadingEl.classList.toggle('is-active', showLoading);
            loadingEl.setAttribute('aria-hidden', showLoading ? 'false' : 'true');
        }

        return post('metis_drive_list', {
            folder_id: requestedFolderId,
            drive_id: requestedDriveId,
            search: ''
        }).then(function (data) {
            if (requestId !== loadRequestSeq) return;
            if (String(currentDriveId || '') !== requestedDriveId || String(folderId || '') !== requestedFolderId) return;
            applyFolderData(data);
            scheduleActiveFolderPoll(ACTIVE_FOLDER_POLL_MS);
        }).catch(function (err) {
            if (requestId !== loadRequestSeq) return;
            if (!options.quietErrors) Metis.toast.error((err && err.message) || 'Failed to load files.');
        }).finally(function () {
            if (requestId !== loadRequestSeq) return;
            if (loadingEl) {
                loadingEl.classList.remove('is-active');
                loadingEl.setAttribute('aria-hidden', 'true');
            }
        });
    }

    function openMyFolder(opts) {
        if (!(opts && opts.refresh) && myFolderState.payload && myFolderState.driveId && myFolderState.folderId) {
            setCurrentDrive(myFolderState.driveId);
            folderId = myFolderState.folderId;
            ownFolderId = myFolderState.folderId;
            ownFolderDriveId = myFolderState.driveId;
            folderNameNow = myFolderState.folderName || 'My Folder';
            parentId = String((myFolderState.payload && myFolderState.payload.parent_id) || myFolderState.driveId || '');
            renderTree();
            return load({ driveId: myFolderState.driveId, folderId: myFolderState.folderId, skipLoading: true });
        }

        return post('metis_drive_my_folder', {
            drive_id: ownFolderDriveId || usersHomeDriveId || currentDriveId || sharedDriveId
        }).then(function (data) {
            const driveId = String((data && data.drive_id) || usersHomeDriveId || currentDriveId || sharedDriveId).trim();
            const targetFolderId = String((data && data.folder_id) || '').trim();
            if (!targetFolderId) throw new Error('Your folder could not be resolved.');
            rememberMyFolder(data);
            setCurrentDrive(driveId);
            folderId = targetFolderId;
            ownFolderId = targetFolderId;
            ownFolderDriveId = driveId;
            folderNameNow = String((data && data.folder_name) || 'My Folder');
            parentId = String((data && data.parent_id) || driveId);
            renderTree();
            return load({ driveId: driveId, folderId: targetFolderId, skipLoading: true });
        }).catch(function (err) {
            if (!(opts && opts.silent)) Metis.toast.error((err && err.message) || 'Failed to open your folder.');
            throw err;
        });
    }

    function uploadFiles(fileList, targetFolderId) {
        const files = Array.from(fileList || []).filter(Boolean);
        if (!files.length) return Promise.resolve();
        const destinationFolderId = String(targetFolderId || folderId || '').trim();
        if (!destinationFolderId) return Promise.resolve();

        setProgress(0, files.length, files.length === 1 ? 'Uploading file…' : 'Uploading files…');
        if (uploadBtn) uploadBtn.disabled = true;

        let chain = Promise.resolve();
        files.forEach(function (file, index) {
            chain = chain.then(function () {
                const fd = new FormData();
                fd.set('parent_id', destinationFolderId);
                fd.set('drive_id', currentDriveId);
                fd.set('file', file);
                return postForm('metis_drive_upload_file', fd).then(function (result) {
                    setProgress(index + 1, files.length, files.length === 1 ? 'Uploading file…' : 'Uploading files…');
                    return result;
                });
            });
        });

        return chain.then(function () {
            Metis.toast.success(files.length === 1 ? 'File uploaded.' : `${files.length} files uploaded.`);
            return load({ skipLoading: true });
        }).catch(function (err) {
            Metis.toast.error((err && err.message) || 'Upload failed.');
        }).finally(function () {
            if (uploadBtn) uploadBtn.disabled = false;
            clearProgress();
        });
    }

    rowsEl?.addEventListener('mousedown', function (event) {
        handleRowPointerSelection(event);
    });

    rowsEl?.addEventListener('click', function (event) {
        const openBtn = event.target.closest('.mds-row-btn-open');
        if (openBtn) {
            const link = String(openBtn.dataset.link || '');
            if (link) window.open(link, '_blank', 'noopener');
            return;
        }

        const renameBtn = event.target.closest('.metis-drive-rename');
        if (renameBtn && canManage) {
            openRenameModal(String(renameBtn.dataset.id || ''), String(renameBtn.dataset.name || ''));
            return;
        }

        const trashBtn = event.target.closest('.metis-drive-trash');
        if (trashBtn && canManage) {
            post('metis_drive_trash', { file_id: String(trashBtn.dataset.id || '') })
                .then(function () {
                    Metis.toast.success('Moved to trash.');
                    return load({ skipLoading: true });
                })
                .catch(function (err) {
                    Metis.toast.error((err && err.message) || 'Failed to trash.');
                });
            return;
        }

        if (event.target.closest('button,a,input')) return;
        const row = event.target.closest('.metis-drive-row');
        if (!row) return;
        const rowId = String(row.dataset.id || '').trim();
        if (!rowId) return;
        if (!selected.has(rowId)) {
            applyRowSelection(rowId, event);
        }
    });

    rowsEl?.addEventListener('dblclick', function (event) {
        if (event.target.closest('button,a,input')) return;
        const row = event.target.closest('.metis-drive-row');
        if (!row) return;

        const rowId = String(row.dataset.id || '');
        const isFolder = String(row.dataset.folder || '0') === '1';
        const rowLink = String(row.dataset.link || '');
        if (isFolder && rowId) {
            const rowLabel = String(row.querySelector('.metis-drive-name-cell span')?.textContent || '').trim();
            const cached = getCachedFolderPayload(currentDriveId, rowId, '');
            if (cached) {
                applyFolderData(cached);
            } else {
                applyTreeFolderPreview(currentDriveId, rowId, rowLabel);
            }
            folderId = rowId;
            load({ skipLoading: !!cached });
            return;
        }
        if (!isFolder && rowLink) window.open(rowLink, '_blank', 'noopener');
    });

    rowsEl?.addEventListener('contextmenu', function (event) {
        const row = event.target.closest('.metis-drive-row');
        if (row && canManage) {
            const rowId = String(row.dataset.id || '').trim();
            if (rowId && !selected.has(rowId)) {
                selectSingle(rowId);
            }
            event.preventDefault();
            const menu = buildItemContextMenu(row);
            openContextMenu(event.clientX, event.clientY, menu.payload, menu.items);
            return;
        }

        if (event.target.closest('.metis-drive-empty')) {
            openBlankAreaContextMenu(event);
        }
    });

    treeEl?.addEventListener('click', function (event) {
        const toggle = event.target.closest('.metis-drive-tree-toggle');
        if (toggle) {
            const targetFolderId = String(toggle.dataset.folderId || '').trim();
            let driveId = String(toggle.dataset.driveId || currentDriveId || sharedDriveId).trim();
            if (targetFolderId && ownFolderId && targetFolderId === ownFolderId && (ownFolderDriveId || usersHomeDriveId)) {
                driveId = ownFolderDriveId || usersHomeDriveId;
            }
            if (!targetFolderId) return;
            const key = tkey(driveId, targetFolderId);
            if (treeExpanded.has(key)) {
                treeExpanded.delete(key);
                renderTree();
                return;
            }
            treeExpanded.add(key);
            renderTree();
            loadTreeChildren(targetFolderId, driveId, false);
            return;
        }

        const label = event.target.closest('.metis-drive-tree-label');
        if (!label) return;
        const targetFolderId = String(label.dataset.folderId || '').trim();
        let driveId = String(label.dataset.driveId || currentDriveId || sharedDriveId).trim();
        if (targetFolderId && ownFolderId && targetFolderId === ownFolderId && (ownFolderDriveId || usersHomeDriveId)) {
            driveId = ownFolderDriveId || usersHomeDriveId;
        }
        if (!targetFolderId) return;
        if (ownFolderId && targetFolderId === ownFolderId) {
            openMyFolder({ refresh: false });
            return;
        }

        const cached = getCachedFolderPayload(driveId, targetFolderId, '');
        if (cached) {
            applyFolderData(cached);
        } else {
            applyTreeFolderPreview(driveId, targetFolderId, String(label.textContent || '').trim());
        }
        setCurrentDrive(driveId);
        folderId = targetFolderId;
        load({ skipLoading: !!cached });
    });

    treeEl?.addEventListener('contextmenu', function (event) {
        const label = event.target.closest('.metis-drive-tree-label');
        if (!label) return;
        const targetFolderId = String(label.dataset.folderId || '').trim();
        const driveId = String(label.dataset.driveId || currentDriveId || sharedDriveId).trim();
        const isDriveRoot = targetFolderId === driveId;
        if (!targetFolderId || !canManage) return;
        event.preventDefault();
        openContextMenu(event.clientX, event.clientY, {
            scope: 'item',
            itemId: targetFolderId,
            itemName: String(label.textContent || 'Folder').trim(),
            isFolder: true,
            folderId: targetFolderId,
            driveId: driveId
        }, [
            { action: 'open-folder', label: 'Open' },
            { action: 'paste-into-folder', label: 'Paste', disabled: !(clipboard && clipboard.itemId) },
            { action: 'cut-item', label: 'Cut', disabled: isDriveRoot },
            { action: 'rename-item', label: 'Rename', disabled: isDriveRoot },
            { action: 'delete-item', label: 'Delete', danger: true, disabled: isDriveRoot },
            { action: 'sync-folder', label: 'Force Sync Folder' }
        ]);
    });

    searchEl?.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            runSearch(searchEl ? searchEl.value : '');
        }, 250);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopActiveFolderPolling();
            return;
        }
        scheduleActiveFolderPoll(1500);
    });

    refreshBtn?.addEventListener('click', function () {
        const driveId = String(currentDriveId || '').trim();
        const targetFolderId = String(folderId || '').trim();
        if (!driveId || !targetFolderId) return;
        triggerSync(driveId, targetFolderId, true, 0).then(function () {
            if (String(currentDriveId || '') !== driveId || String(folderId || '') !== targetFolderId) return;
            load({ driveId: driveId, folderId: targetFolderId, skipLoading: true, quietErrors: true });
        });
    });

    rootBtn?.addEventListener('click', function () {
        if (searchEl) searchEl.value = '';
        hideSearchOverlay();
        setCurrentDrive(sharedDriveId);
        folderId = currentDriveId;
        load();
    });

    myFolderBtn?.addEventListener('click', function () {
        if (searchEl) searchEl.value = '';
        hideSearchOverlay();
        openMyFolder({ refresh: false });
    });

    upBtn?.addEventListener('click', function () {
        if (!parentId) return;
        if (searchEl) searchEl.value = '';
        hideSearchOverlay();
        folderId = parentId;
        load();
    });

    actionsBtn?.addEventListener('click', function () {
        if (!canManage || !selected.size) return;
        trashItems(Array.from(selected)).then(function (ok) {
            if (ok) selected.clear();
        });
    });

    if (canManage) {
        document.querySelectorAll('.metis-drive-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeModal(btn.closest('.metis-modal-backdrop'));
            });
        });

        document.querySelectorAll('.metis-modal-backdrop').forEach(function (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) closeModal(modal);
            });
        });

        newFolderBtn?.addEventListener('click', function () {
            if (folderNameEl) folderNameEl.value = '';
            openModal(folderModal);
        });

        newGFileBtn?.addEventListener('click', function () {
            if (gFileName) gFileName.value = '';
            if (gFileType) gFileType.value = 'doc';
            openModal(gFileModal);
        });

        folderForm?.addEventListener('submit', function (event) {
            event.preventDefault();
            const btn = folderForm.querySelector('button[type="submit"]');
            setBusy(btn, true, 'Creating…');
            post('metis_drive_create_folder', {
                parent_id: folderId,
                drive_id: currentDriveId,
                folder_name: folderNameEl ? folderNameEl.value : ''
            }).then(function () {
                closeModal(folderModal);
                Metis.toast.success('Folder created.');
                return load({ skipLoading: true });
            }).catch(function (err) {
                Metis.toast.error((err && err.message) || 'Failed to create folder.');
            }).finally(function () {
                setBusy(btn, false);
            });
        });

        renameForm?.addEventListener('submit', function (event) {
            event.preventDefault();
            post('metis_drive_rename', {
                file_id: renameIdEl ? renameIdEl.value : '',
                drive_id: currentDriveId,
                name: renameNameEl ? renameNameEl.value : ''
            }).then(function () {
                closeModal(renameModal);
                Metis.toast.success('Renamed.');
                return load({ skipLoading: true });
            }).catch(function (err) {
                Metis.toast.error((err && err.message) || 'Failed to rename.');
            });
        });

        gFileForm?.addEventListener('submit', function (event) {
            event.preventDefault();
            const btn = gFileForm.querySelector('button[type="submit"]');
            setBusy(btn, true, 'Creating…');
            post('metis_drive_create_google_file', {
                parent_id: folderId,
                drive_id: currentDriveId,
                google_type: gFileType ? gFileType.value : 'doc',
                name: gFileName ? gFileName.value : ''
            }).then(function (data) {
                closeModal(gFileModal);
                Metis.toast.success('File created.');
                const link = String(((data && data.file) || {}).webViewLink || '');
                if (link) window.open(link, '_blank', 'noopener');
                return load({ skipLoading: true });
            }).catch(function (err) {
                Metis.toast.error((err && err.message) || 'Failed to create file.');
            }).finally(function () {
                setBusy(btn, false);
            });
        });

        uploadBtn?.addEventListener('click', function () {
            uploadInput?.click();
        });

        uploadInput?.addEventListener('change', function () {
            if (!uploadInput.files?.length) return;
            uploadFiles(uploadInput.files).finally(function () {
                uploadInput.value = '';
            });
        });

        rowsEl?.addEventListener('dragstart', function (event) {
            const row = event.target.closest('.metis-drive-row');
            if (!row) return;
            const rowId = String(row.dataset.id || '').trim();
            if (!rowId) return;
            ensureRowInSelection(rowId, event);
            const ids = selected.has(rowId) ? renderedRowIds().filter(function (id) {
                return selected.has(id);
            }) : [rowId];
            if (!ids.length) return;
            dragState = {
                ids: ids,
                sourceFolderId: String(folderId || '').trim(),
                sourceDriveId: String(currentDriveId || '').trim()
            };
            row.classList.add('is-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', ids.join(','));
                event.dataTransfer.setData('application/x-metis-drive-selection', JSON.stringify(ids));
            }
        });

        rowsEl?.addEventListener('click', function (event) {
            if (event.target.closest('.metis-drive-row')) return;
            if (event.target.closest('.metis-drive-search-overlay')) return;
            if (event.target.closest('.metis-drive-context-menu')) return;
            clearSelection();
        });

        rowsEl?.addEventListener('dragend', function (event) {
            event.target.closest('.metis-drive-row')?.classList.remove('is-dragging');
            rowsEl.querySelectorAll('.metis-drive-row.is-drop-target').forEach(function (row) {
                row.classList.remove('is-drop-target');
            });
            clearTreeDropTargets();
            dragState = null;
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            rowsEl?.addEventListener(evt, function (event) {
                const row = event.target.closest('.metis-drive-row');
                if (!row || String(row.dataset.folder || '0') !== '1') return;
                event.preventDefault();
                row.classList.add('is-drop-target');
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
            });
        });

        rowsEl?.addEventListener('dragleave', function (event) {
            const row = event.target.closest('.metis-drive-row');
            if (!row) return;
            if (row.contains(event.relatedTarget)) return;
            row.classList.remove('is-drop-target');
        });

        rowsEl?.addEventListener('drop', function (event) {
            const row = event.target.closest('.metis-drive-row');
            if (!row || String(row.dataset.folder || '0') !== '1') return;
            event.preventDefault();
            event.stopPropagation();
            row.classList.remove('is-drop-target');

            const targetFolderId = String(row.dataset.id || '').trim();
            if (!targetFolderId) return;
            const dt = event.dataTransfer;
            if (dt?.files?.length) {
                uploadFiles(dt.files, targetFolderId);
                return;
            }
            if (!dragState || !Array.isArray(dragState.ids) || !dragState.ids.length) return;
            if (dragState.ids.includes(targetFolderId)) {
                Metis.toast.error('An item cannot be dropped into itself.');
                return;
            }
            moveItemsToFolder(dragState.ids, targetFolderId, currentDriveId, {
                successMessage: dragState.ids.length === 1 ? 'Item moved.' : `${dragState.ids.length} items moved.`
            }).then(function (ok) {
                if (ok) {
                    selected.clear();
                    updateSelection();
                }
            });
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            treeEl?.addEventListener(evt, function (event) {
                const label = event.target.closest('.metis-drive-tree-label');
                if (!label) return;
                const row = label.closest('.metis-drive-tree-row');
                const targetFolderId = String(label.dataset.folderId || '').trim();
                if (!row || !targetFolderId) return;

                const dt = event.dataTransfer;
                const hasFiles = !!(dt && dt.files && dt.files.length);
                const hasSelection = !!(dragState && Array.isArray(dragState.ids) && dragState.ids.length);
                if (!hasFiles && !hasSelection) return;

                event.preventDefault();
                event.stopPropagation();
                clearTreeDropTargets();
                row.classList.add('is-drop-target');
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = hasFiles ? 'copy' : 'move';
                }
            });
        });

        treeEl?.addEventListener('dragleave', function (event) {
            const row = event.target.closest('.metis-drive-tree-row');
            if (!row) return;
            if (row.contains(event.relatedTarget)) return;
            row.classList.remove('is-drop-target');
        });

        treeEl?.addEventListener('drop', function (event) {
            const label = event.target.closest('.metis-drive-tree-label');
            if (!label) return;

            const row = label.closest('.metis-drive-tree-row');
            const targetFolderId = String(label.dataset.folderId || '').trim();
            let driveId = String(label.dataset.driveId || currentDriveId || sharedDriveId).trim();
            if (!row || !targetFolderId) return;
            if (targetFolderId && ownFolderId && targetFolderId === ownFolderId && (ownFolderDriveId || usersHomeDriveId)) {
                driveId = ownFolderDriveId || usersHomeDriveId;
            }

            event.preventDefault();
            event.stopPropagation();
            row.classList.remove('is-drop-target');

            const dt = event.dataTransfer;
            if (dt?.files?.length) {
                const previousDriveId = currentDriveId;
                setCurrentDrive(driveId);
                uploadFiles(dt.files, targetFolderId).finally(function () {
                    setCurrentDrive(previousDriveId);
                });
                return;
            }

            if (!dragState || !Array.isArray(dragState.ids) || !dragState.ids.length) return;
            if (dragState.ids.includes(targetFolderId)) {
                Metis.toast.error('An item cannot be dropped into itself.');
                return;
            }

            moveItemsToFolder(dragState.ids, targetFolderId, driveId, {
                successMessage: dragState.ids.length === 1 ? 'Item moved.' : `${dragState.ids.length} items moved.`
            }).then(function (ok) {
                if (ok) {
                    selected.clear();
                    updateSelection();
                    if (treeExpanded.has(tkey(driveId, targetFolderId))) {
                        loadTreeChildren(targetFolderId, driveId, true);
                    }
                }
            });
        });

        if (browserEl) {
            const blankAreaTargets = [
                document.querySelector('.metis-drive-main'),
                document.querySelector('.metis-drive-table-wrap'),
                rowsEl
            ].filter(Boolean);

            blankAreaTargets.forEach(function (el) {
                el.addEventListener('contextmenu', function (event) {
                    if (event.target.closest('.metis-drive-row')) return;
                    if (event.target.closest('.metis-drive-table-head')) return;
                    if (event.target.closest('.metis-drive-context-menu')) return;
                    openBlankAreaContextMenu(event);
                });
            });

            ['dragenter', 'dragover'].forEach(function (evt) {
                browserEl.addEventListener(evt, function (event) {
                    const dt = event.dataTransfer;
                    if (!dt || !dt.files || !dt.files.length) return;
                    event.preventDefault();
                    event.stopPropagation();
                    dropDepth++;
                    browserEl.classList.add('is-over');
                    setStatus('Drop files to upload to this folder.');
                });
            });

            browserEl.addEventListener('dragleave', function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropDepth = Math.max(0, dropDepth - 1);
                if (dropDepth === 0) {
                    browserEl.classList.remove('is-over');
                    setStatus('');
                }
            });

            browserEl.addEventListener('drop', function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropDepth = 0;
                browserEl.classList.remove('is-over');
                const dt = event.dataTransfer;
                if (dt?.files?.length) uploadFiles(dt.files);
            });
        }
    }

    function bootstrapRootTree() {
        driveConfigs.forEach(function (drive) {
            const driveId = String((drive && drive.drive_id) || '').trim();
            if (!driveId || Number((drive && drive.is_users_home) || 0)) return;
            hydrateDriveRootChildren(driveId);
        });
    }

    if (initialUserFolderId) {
        rememberMyFolder(initialFolderPayload || {
            shared_drive_id: ownFolderDriveId || usersHomeDriveId || currentDriveId || sharedDriveId,
            folder_id: initialUserFolderId,
            folder_name: initialUserFolderName || 'My Folder',
            parent_id: ownFolderDriveId || usersHomeDriveId || currentDriveId || sharedDriveId,
            own_folder_id: initialUserFolderId,
            files: []
        });

        if (initialFolderPayload && String(initialFolderPayload.folder_id || '') === initialUserFolderId) {
            cacheFolderPayload(initialFolderPayload, '');
            applyFolderData(initialFolderPayload);
        } else {
            folderNameNow = initialUserFolderName || 'My Folder';
            parentId = currentDriveId;
        }

        renderTree();
        bootstrapRootTree();
        if (initialFolderId && initialFolderId !== initialUserFolderId) {
            folderId = initialFolderId;
            load({ driveId: ownFolderDriveId || currentDriveId, folderId: initialFolderId, skipLoading: true });
        } else {
            load({ driveId: ownFolderDriveId || currentDriveId, folderId: initialUserFolderId, skipLoading: true });
        }
    } else if (usersHomeDriveId) {
        renderTree();
        bootstrapRootTree();
        openMyFolder({ silent: true }).catch(function () {
            setCurrentDrive(sharedDriveId);
            folderId = initialFolderId || currentDriveId || sharedDriveId;
            renderTree();
            load();
        });
    } else {
        renderTree();
        bootstrapRootTree();
        load();
    }
});
