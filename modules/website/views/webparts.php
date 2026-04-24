<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}
?>
<div id="metis-webparts-view" class="metis-config-view">
    <div class="mw-page-header">
        <div class="mw-page-header-left">
            <h1 class="mw-page-title">Web Parts</h1>
            <p class="mw-subtitle">Manage reusable components attached to templates, pages, and posts.</p>
        </div>
        <div class="mw-page-header-right">
            <select id="metis-webpart-status-filter" class="mw-input mw-input-sm">
                <option value="">All Statuses</option>
                <option value="published">Published</option>
                <option value="draft">Draft</option>
            </select>
            <button class="mw-btn mw-btn-primary" id="metis-webpart-create-btn">New Web Part</button>
        </div>
    </div>

    <div class="metis-table-wrap">
        <div class="mw-premium-table metis-webparts-table">
            <div class="mw-premium-row mw-premium-header">
                <div class="mw-premium-cell">Name</div>
                <div class="mw-premium-cell">Type</div>
                <div class="mw-premium-cell">Target</div>
                <div class="mw-premium-cell">Region / Slot</div>
                <div class="mw-premium-cell">Status</div>
                <div class="mw-premium-cell mw-col-right">Actions</div>
            </div>
            <div id="metis-webpart-table-body"></div>
        </div>

        <div id="metis-webpart-empty-state" class="metis-empty-state" hidden>
            <div class="metis-empty-state-icon">&#129525;</div>
            <h2>No web parts yet</h2>
            <p>Create reusable blocks and attach them to template, page, or post regions.</p>
            <button class="mw-btn mw-btn-primary" id="metis-webpart-create-btn-empty">New Web Part</button>
        </div>
    </div>

    <div id="metis-webpart-modal" class="mw-modal-overlay" hidden role="dialog" aria-modal="true" aria-label="Web Part Editor">
        <div class="mw-modal metis-config-modal">
            <div class="mw-modal-header">
                <h2 class="mw-modal-title" id="metis-webpart-modal-title">New Web Part</h2>
                <button class="mw-modal-close" id="metis-webpart-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="mw-modal-body metis-config-modal-body">
                <div class="metis-form-grid metis-form-grid-3">
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-name">Name</label>
                        <input id="metis-webpart-name" class="mw-input" type="text" placeholder="Web part name">
                    </div>
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-type">Part Type</label>
                        <select id="metis-webpart-type" class="mw-input">
                            <option value="custom">Custom</option>
                            <option value="banner">Banner</option>
                            <option value="form_embed">Form Embed</option>
                            <option value="donation_progress">Donation Progress</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-status">Status</label>
                        <select id="metis-webpart-status" class="mw-input">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-render-mode">Render Mode</label>
                        <select id="metis-webpart-render-mode" class="mw-input">
                            <option value="blocks">Blocks</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-target-scope">Target Scope</label>
                        <select id="metis-webpart-target-scope" class="mw-input">
                            <option value="site">Site</option>
                            <option value="template">Template</option>
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-target-ref">Target Ref</label>
                        <input id="metis-webpart-target-ref" class="mw-input" type="text" placeholder="template key, slug, code, or id">
                    </div>
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-region">Region</label>
                        <select id="metis-webpart-region" class="mw-input">
                            <option value="main">Main</option>
                            <option value="header">Header</option>
                            <option value="footer">Footer</option>
                            <option value="sidebar">Sidebar</option>
                            <option value="banners">Banners</option>
                        </select>
                    </div>
                    <div class="mw-field">
                        <label class="mw-label" for="metis-webpart-slot">Slot</label>
                        <select id="metis-webpart-slot" class="mw-input">
                            <option value="append">Append</option>
                            <option value="prepend">Prepend</option>
                            <option value="before">Before</option>
                            <option value="after">After</option>
                        </select>
                    </div>
                <div class="mw-field">
                    <label class="mw-label" for="metis-webpart-sort-order">Sort Order</label>
                    <input id="metis-webpart-sort-order" class="mw-input" type="number" step="1" value="0">
                </div>
                </div>

                <div class="mw-field">
                    <label class="mw-label">Web Part Builder</label>
                    <div class="metis-webpart-builder-wrap">
                        <div id="metis-webpart-builder-canvas"></div>
                    </div>
                </div>
                <div class="mw-field">
                    <label class="mw-label">Visibility Rules</label>
                    <label class="metis-inline-toggle"><input id="metis-webpart-site-wide" type="checkbox" checked> <span>Site-wide</span></label>
                    <div class="metis-form-grid metis-form-grid-3">
                        <div class="mw-field">
                            <label class="mw-label" for="metis-webpart-visibility-paths">Paths (comma separated)</label>
                            <input id="metis-webpart-visibility-paths" class="mw-input" type="text" placeholder="/,/about">
                        </div>
                        <div class="mw-field">
                            <label class="mw-label" for="metis-webpart-visibility-slugs">Slugs (comma separated)</label>
                            <input id="metis-webpart-visibility-slugs" class="mw-input" type="text" placeholder="home,about">
                        </div>
                        <div class="mw-field">
                            <label class="mw-label" for="metis-webpart-visibility-types">Content Types</label>
                            <input id="metis-webpart-visibility-types" class="mw-input" type="text" placeholder="page,post">
                        </div>
                    </div>
                </div>
                <input id="metis-webpart-id" type="hidden" value="">
            </div>
            <div class="mw-modal-footer">
                <button class="mw-btn mw-btn-ghost" id="metis-webpart-cancel-btn">Cancel</button>
                <button class="mw-btn mw-btn-primary" id="metis-webpart-save-btn">Save Web Part</button>
            </div>
        </div>
    </div>
</div>
