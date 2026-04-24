<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}
?>
<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Media Library</h1>
        <span class="metis-media-header-copy">Manage files used by Website, Newsletter, Popups, and Forms.</span>
    </div>
    <div class="mw-page-header-right">
        <button class="mw-btn mw-btn-primary" id="metis-media-upload-btn" type="button">Upload Files</button>
        <input type="file" id="metis-media-upload-input" multiple style="display:none">
    </div>
</div>

<div class="metis-table-wrap">
    <button type="button" id="metis-media-dropzone" class="metis-media-dropzone" aria-label="Upload files">
        <strong>Drop files here to upload</strong>
        <span>or click to choose files</span>
    </button>
    <div class="metis-media-toolbar">
        <label class="screen-reader-text" for="metis-media-search">Search media by filename</label>
        <input type="text" id="metis-media-search" class="mw-input" placeholder="Search by filename...">
        <label class="screen-reader-text" for="metis-media-filter">Filter media by type</label>
        <select id="metis-media-filter" class="mw-select">
            <option value="">All Types</option>
            <option value="image">Images</option>
            <option value="video">Video</option>
            <option value="audio">Audio</option>
            <option value="application">Documents</option>
            <option value="text">Text</option>
        </select>
        <label class="screen-reader-text" for="metis-media-folder-filter">Filter media by folder</label>
        <input type="text" id="metis-media-folder-filter" class="mw-input" list="metis-media-folder-options" placeholder="Folder filter (e.g. campaigns/2026)">
        <label class="screen-reader-text" for="metis-media-category-filter">Filter media by category</label>
        <input type="text" id="metis-media-category-filter" class="mw-input" list="metis-media-category-options" placeholder="Category filter (e.g. hero)">
        <label class="screen-reader-text" for="metis-media-sort">Sort media</label>
        <select id="metis-media-sort" class="mw-select">
            <option value="created_desc">Newest</option>
            <option value="created_asc">Oldest</option>
            <option value="name_asc">Name A-Z</option>
            <option value="name_desc">Name Z-A</option>
            <option value="size_desc">Largest</option>
            <option value="size_asc">Smallest</option>
        </select>
        <div class="metis-media-view-toggle" role="group" aria-label="View mode">
            <button class="mw-btn mw-btn-ghost mw-btn-sm is-active" id="metis-media-view-grid" type="button" aria-pressed="true">Grid</button>
            <button class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-view-list" type="button" aria-pressed="false">List</button>
        </div>
        <button class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-refresh-btn" type="button">Refresh</button>
    </div>
    <div class="metis-media-toolbar">
        <label class="screen-reader-text" for="metis-media-upload-folder">Default upload folder</label>
        <input type="text" id="metis-media-upload-folder" class="mw-input" list="metis-media-folder-options" placeholder="Upload folder (optional)">
        <label class="screen-reader-text" for="metis-media-upload-category">Default upload category</label>
        <input type="text" id="metis-media-upload-category" class="mw-input" list="metis-media-category-options" placeholder="Upload category (optional)">
        <button class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-new-folder-btn" type="button">New Folder</button>
        <button class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-new-category-btn" type="button">New Category</button>
    </div>
    <datalist id="metis-media-folder-options"></datalist>
    <datalist id="metis-media-category-options"></datalist>
    <div class="metis-media-organizer" aria-label="Media organization">
        <section class="metis-media-organizer-section">
            <div class="metis-media-organizer-head">
                <h2>Folders</h2>
                <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-clear-folder-filter">Clear</button>
            </div>
            <div id="metis-media-folder-list" class="metis-media-organizer-list">
                <span class="metis-media-organizer-empty">No folders</span>
            </div>
        </section>
        <section class="metis-media-organizer-section">
            <div class="metis-media-organizer-head">
                <h2>Categories</h2>
                <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-clear-category-filter">Clear</button>
            </div>
            <div id="metis-media-category-list" class="metis-media-organizer-list">
                <span class="metis-media-organizer-empty">No categories</span>
            </div>
        </section>
    </div>
    <div id="metis-media-grid" class="metis-media-grid" aria-live="polite">
        <div class="metis-media-empty">Loading media...</div>
    </div>
</div>

<div id="metis-media-preview-modal" class="metis-media-preview-modal" aria-hidden="true">
    <div class="metis-media-preview-modal-inner" role="dialog" aria-modal="true" aria-labelledby="metis-media-preview-title">
        <div class="metis-media-preview-modal-head">
            <strong id="metis-media-preview-title">Preview</strong>
            <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-preview-close">Close</button>
        </div>
        <div id="metis-media-preview-body" class="metis-media-preview-modal-body"></div>
    </div>
</div>

<div id="metis-media-organize-modal" class="metis-media-preview-modal" aria-hidden="true">
    <div class="metis-media-preview-modal-inner" role="dialog" aria-modal="true" aria-labelledby="metis-media-organize-title">
        <div class="metis-media-preview-modal-head">
            <strong id="metis-media-organize-title">Organize Media</strong>
            <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-organize-close">Close</button>
        </div>
        <div class="metis-media-preview-modal-body">
            <input type="hidden" id="metis-media-organize-token" value="">
            <div class="metis-media-modal-field">
                <label for="metis-media-organize-folder">Folder</label>
                <input type="text" id="metis-media-organize-folder" class="mw-input" list="metis-media-folder-options" placeholder="campaigns/2026">
            </div>
            <div class="metis-media-modal-field">
                <label for="metis-media-organize-category">Category</label>
                <input type="text" id="metis-media-organize-category" class="mw-input" list="metis-media-category-options" placeholder="hero">
            </div>
            <div class="metis-media-modal-actions">
                <button type="button" class="mw-btn mw-btn-primary" id="metis-media-organize-save">Save</button>
            </div>
        </div>
    </div>
</div>

<div id="metis-media-confirm-modal" class="metis-media-preview-modal" aria-hidden="true">
    <div class="metis-media-preview-modal-inner" role="dialog" aria-modal="true" aria-labelledby="metis-media-confirm-title">
        <div class="metis-media-preview-modal-head">
            <strong id="metis-media-confirm-title">Delete Media</strong>
            <button type="button" class="mw-btn mw-btn-ghost mw-btn-sm" id="metis-media-confirm-close">Close</button>
        </div>
        <div class="metis-media-preview-modal-body">
            <p>This will permanently delete the selected media file.</p>
            <input type="hidden" id="metis-media-delete-token" value="">
            <div class="metis-media-modal-actions">
                <button type="button" class="mw-btn mw-btn-ghost" id="metis-media-delete-cancel">Cancel</button>
                <button type="button" class="mw-btn mw-btn-danger" id="metis-media-delete-confirm">Delete</button>
            </div>
        </div>
    </div>
</div>
