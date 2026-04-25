<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}
?>
<div id="metis-webparts-view" class="metis-config-view">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <h1 class="metis-page-title">Web Parts</h1>
            <p class="metis-subtitle">Manage reusable components attached to templates, pages, and posts.</p>
        </div>
        <div class="metis-page-header-right">
            <select id="metis-webpart-status-filter" class="metis-input metis-input-sm">
                <option value="">All Statuses</option>
                <option value="published">Published</option>
                <option value="draft">Draft</option>
            </select>
            <button class="metis-btn metis-btn-primary" id="metis-webpart-create-btn">New Web Part</button>
        </div>
    </div>

    <div class="metis-table-wrap">
        <table class="metis-premium-table metis-webparts-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Name</th>
                    <th class="metis-premium-cell" scope="col">Type</th>
                    <th class="metis-premium-cell" scope="col">Target</th>
                    <th class="metis-premium-cell" scope="col">Region / Slot</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody id="metis-webpart-table-body"></tbody>
        </table>

        <div id="metis-webpart-empty-state" class="metis-empty-state" hidden>
            <div class="metis-empty-state-icon">&#129525;</div>
            <h2>No web parts yet</h2>
            <p>Create reusable blocks and attach them to template, page, or post regions.</p>
            <button class="metis-btn metis-btn-primary" id="metis-webpart-create-btn-empty">New Web Part</button>
        </div>
    </div>

    <div id="metis-webpart-modal" class="metis-modal-overlay" hidden role="dialog" aria-modal="true" aria-label="Web Part Editor">
        <div class="metis-modal metis-config-modal">
            <div class="metis-modal-header">
                <h2 class="metis-modal-title" id="metis-webpart-modal-title">New Web Part</h2>
                <button class="metis-modal-close" id="metis-webpart-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="metis-modal-body metis-config-modal-body">
                <div class="metis-form-grid metis-form-grid-3">
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-name">Name</label>
                        <input id="metis-webpart-name" class="metis-input" type="text" placeholder="Web part name">
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-type">Part Type</label>
                        <select id="metis-webpart-type" class="metis-input">
                            <option value="custom">Custom</option>
                            <option value="banner">Banner</option>
                            <option value="form_embed">Form Embed</option>
                            <option value="donation_progress">Donation Progress</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-status">Status</label>
                        <select id="metis-webpart-status" class="metis-input">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-render-mode">Render Mode</label>
                        <select id="metis-webpart-render-mode" class="metis-input">
                            <option value="blocks">Blocks</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-target-scope">Target Scope</label>
                        <select id="metis-webpart-target-scope" class="metis-input">
                            <option value="site">Site</option>
                            <option value="template">Template</option>
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-target-ref">Target Ref</label>
                        <input id="metis-webpart-target-ref" class="metis-input" type="text" placeholder="template key, slug, code, or id">
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-region">Region</label>
                        <select id="metis-webpart-region" class="metis-input">
                            <option value="main">Main</option>
                            <option value="header">Header</option>
                            <option value="footer">Footer</option>
                            <option value="sidebar">Sidebar</option>
                            <option value="banners">Banners</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-slot">Slot</label>
                        <select id="metis-webpart-slot" class="metis-input">
                            <option value="append">Append</option>
                            <option value="prepend">Prepend</option>
                            <option value="before">Before</option>
                            <option value="after">After</option>
                        </select>
                    </div>
                <div class="metis-field">
                    <label class="metis-label" for="metis-webpart-sort-order">Sort Order</label>
                    <input id="metis-webpart-sort-order" class="metis-input" type="number" step="1" value="0">
                </div>
                </div>

                <div class="metis-field">
                    <label class="metis-label">Web Part Builder</label>
                    <div class="metis-webpart-builder-wrap">
                        <div id="metis-webpart-builder-canvas"></div>
                    </div>
                </div>
                <div class="metis-field">
                    <label class="metis-label">Visibility Rules</label>
                    <label class="metis-inline-toggle"><input id="metis-webpart-site-wide" type="checkbox" checked> <span>Site-wide</span></label>
                    <div class="metis-form-grid metis-form-grid-3">
                        <div class="metis-field">
                            <label class="metis-label" for="metis-webpart-visibility-paths">Paths (comma separated)</label>
                            <input id="metis-webpart-visibility-paths" class="metis-input" type="text" placeholder="/,/about">
                        </div>
                        <div class="metis-field">
                            <label class="metis-label" for="metis-webpart-visibility-slugs">Slugs (comma separated)</label>
                            <input id="metis-webpart-visibility-slugs" class="metis-input" type="text" placeholder="home,about">
                        </div>
                        <div class="metis-field">
                            <label class="metis-label" for="metis-webpart-visibility-types">Content Types</label>
                            <input id="metis-webpart-visibility-types" class="metis-input" type="text" placeholder="page,post">
                        </div>
                    </div>
                </div>
                <input id="metis-webpart-id" type="hidden" value="">
            </div>
            <div class="metis-modal-footer">
                <button class="metis-btn metis-btn-ghost" id="metis-webpart-cancel-btn">Cancel</button>
                <button class="metis-btn metis-btn-primary" id="metis-webpart-save-btn">Save Web Part</button>
            </div>
        </div>
    </div>
</div>
