<?php
if (!defined('METIS_ROOT')) exit;
if (!metis_drive_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Drive.</div>';
    return;
}
$cfg = metis_drive_workspace_settings();
$can_manage = metis_drive_can_manage();
$initial_folder_id = isset($_GET['folder_id']) ? metis_text_clean(metis_runtime_unslash($_GET['folder_id'])) : '';
$drive_configs = metis_drive_configured_drives();
$users_home_drive_id = '';
$initial_user_folder_id = '';
$initial_user_folder_name = '';
$initial_folder_payload = [];
$resolved_user_folder = function_exists('metis_drive_resolve_user_folder_context')
    ? metis_drive_resolve_user_folder_context()
    : ['ok' => false];
if (!empty($resolved_user_folder['ok'])) {
    $users_home_cfg = (array) ($resolved_user_folder['cfg'] ?? []);
    $user_folder = (array) ($resolved_user_folder['mapping'] ?? []);
    $users_home_drive_id = (string) ($users_home_cfg['shared_drive_id'] ?? '');
    $initial_user_folder_id = (string) ($user_folder['folder_id'] ?? '');
    $initial_user_folder_name = (string) ($user_folder['folder_name'] ?? '');
    if (
        $users_home_drive_id !== ''
        && $initial_user_folder_id !== ''
        && function_exists('metis_drive_cached_folder_children')
        && function_exists('metis_drive_sync_state')
    ) {
        $initial_sync = metis_drive_sync_state($users_home_drive_id, $initial_user_folder_id);
        $initial_folder_payload = [
            'shared_drive_id' => $users_home_drive_id,
            'shared_drive_name' => (string) ($users_home_cfg['shared_drive_name'] ?? $users_home_cfg['shared_drive_label'] ?? ''),
            'shared_drive_label' => (string) ($users_home_cfg['shared_drive_label'] ?? $users_home_cfg['shared_drive_name'] ?? ''),
            'folder_id' => $initial_user_folder_id,
            'folder_name' => $initial_user_folder_name,
            'parent_id' => (string) ($user_folder['parent_folder_id'] ?? $users_home_drive_id),
            'own_folder_id' => $initial_user_folder_id,
            'users_root_id' => '',
            'cache' => [
                'status' => (string) ($initial_sync['sync_status'] ?? 'idle'),
                'last_synced_at' => (string) ($initial_sync['last_synced_at'] ?? ''),
                'is_cold' => empty($initial_sync['last_synced_at']),
            ],
            'files' => metis_drive_cached_folder_children($users_home_drive_id, $initial_user_folder_id, '', false),
        ];
    }
}
?>

<div class="metis-drive"
     data-shared-drive-id="<?php echo metis_escape_attr((string) ($cfg['shared_drive_id'] ?? '')); ?>"
     data-drive-configs="<?php echo metis_escape_attr(metis_json_encode($drive_configs)); ?>"
     data-users-home-drive-id="<?php echo metis_escape_attr($users_home_drive_id); ?>"
     data-initial-user-folder-id="<?php echo metis_escape_attr($initial_user_folder_id); ?>"
     data-initial-user-folder-name="<?php echo metis_escape_attr($initial_user_folder_name); ?>"
     data-initial-folder-payload="<?php echo metis_escape_attr(metis_json_encode($initial_folder_payload)); ?>"
     data-initial-folder-id="<?php echo metis_escape_attr($initial_folder_id); ?>"
     data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>">

    <div id="metis-drive-alert" class="metis-alert" style="display:none;"></div>

    <?php if (empty($cfg['ok'])) : ?>
        <div class="metis-alert metis-alert-error"><?php echo metis_escape_html('Drive integration is not configured.'); ?></div>
    <?php else : ?>

        <!-- Toolbar -->
        <div class="mds-toolbar">
            <div class="mds-toolbar-search">
                <span class="mds-search-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
                <input id="metis-drive-search" class="mds-search-input" type="text" placeholder="Search files…" autocomplete="off">
            </div>

            <div class="mds-toolbar-btns">
                <button type="button" id="metis-drive-my-folder" class="mds-btn" title="My Folder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="10" x2="12" y2="16"/><line x1="9" y1="13" x2="15" y2="13"/></svg>
                    <span>My Folder</span>
                </button>
                <button type="button" id="metis-drive-root" class="mds-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span>Home</span>
                </button>
                <button type="button" id="metis-drive-up" class="mds-btn" title="Go Up" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
                    <span>Up</span>
                </button>
                <button type="button" id="metis-drive-refresh" class="mds-btn mds-btn-icon" title="Refresh">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                </button>
                <?php if ($can_manage) : ?>
                <div class="mds-divider"></div>
                <button type="button" id="metis-drive-new-folder" class="mds-btn" title="New Folder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="10" x2="12" y2="16"/><line x1="9" y1="13" x2="15" y2="13"/></svg>
                    <span>New Folder</span>
                </button>
                <button type="button" id="metis-drive-new-google-file" class="mds-btn" title="New Google File">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    <span>New File</span>
                </button>
                <button type="button" id="metis-drive-upload" class="mds-btn mds-btn-primary" title="Upload">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                    <span>Upload</span>
                </button>
                <input type="file" id="metis-drive-upload-input" style="display:none;" multiple />
                <?php endif; ?>
                <div class="mds-divider"></div>
                <button type="button" id="metis-drive-actions" class="mds-btn mds-btn-danger" disabled title="Bulk Actions">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    <span id="metis-drive-selection-count">0 selected</span>
                </button>
            </div>
        </div>

        <!-- Breadcrumb path bar -->
        <div class="mds-pathbar" id="metis-drive-path-bar">
            <svg class="mds-pathbar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            <span class="mds-pathbar-text">/</span>
        </div>

        <!-- Loading bar -->
        <div class="metis-drive-loading" id="metis-drive-loading" aria-hidden="true"><span></span></div>
        <div class="metis-drive-status" id="metis-drive-status" aria-live="polite" hidden></div>

        <!-- Browser split -->
        <div class="metis-drive-browser" id="metis-drive-browser">
            <div class="metis-drive-split">
                <aside class="metis-drive-tree">
                    <div class="metis-drive-tree-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        Drives
                    </div>
                    <div id="metis-drive-tree"></div>
                </aside>
                <section class="metis-drive-main">
                    <div class="metis-drive-table-wrap">
                        <div class="metis-drive-table-head">
                            <div>Name</div>
                            <div>Type</div>
                            <div>Size</div>
                            <div>Modified</div>
                            <div>Actions</div>
                        </div>
                        <div id="metis-drive-rows" class="metis-drive-rows"></div>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/dashboard_modals.php'; ?>
