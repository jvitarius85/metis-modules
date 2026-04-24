<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}
?>
<div class="metis-module-header">
    <div class="metis-header-content"><h1>Import</h1></div>
</div>

<div style="padding:24px;max-width:760px;">

    <!-- Step indicator -->
    <div id="metis-import-steps" style="display:flex;gap:0;margin-bottom:28px;" role="list" aria-label="Import progress">
        <?php foreach ( [ 1 => 'Upload', 2 => 'Preview', 3 => 'Import', 4 => 'Done' ] as $n => $label ) : ?>
            <div class="metis-import-step" data-step="<?php echo $n; ?>" role="listitem"<?php echo $n === 1 ? ' aria-current="step"' : ''; ?> style="display:flex;align-items:center;flex:1;">
                <div class="metis-step-dot" style="width:28px;height:28px;border-radius:50%;background:var(--metis-border,#e2e6ea);color:#888;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;transition:background .2s,color .2s;"><?php echo $n; ?></div>
                <div style="margin-left:8px;font-size:13px;font-weight:500;color:#888;flex:1;"><?php echo $label; ?></div>
                <?php if ( $n < 4 ) : ?><div style="flex:1;height:2px;background:var(--metis-border,#e2e6ea);margin:0 8px;max-width:40px;"></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Step 1: Upload -->
    <div id="metis-import-step-1">
        <div style="border:2px dashed var(--metis-border,#e2e6ea);border-radius:8px;padding:40px;text-align:center;background:var(--metis-surface,#fff);cursor:pointer;" id="metis-import-drop-zone" tabindex="0" role="button" aria-controls="metis-import-file-input" aria-describedby="metis-import-drop-help">
            <div style="font-size:48px;margin-bottom:16px;opacity:.4;">&#8681;</div>
            <div style="font-size:16px;font-weight:600;margin-bottom:8px;color:var(--metis-text,#1a1f2b);">Upload WXR Export</div>
            <div id="metis-import-drop-help" style="font-size:13px;color:var(--metis-text-muted,#888);margin-bottom:20px;">Drag &amp; drop a <code>.xml</code> file here, or click to browse.<br>Maximum file size: 32 MB.</div>
            <input type="file" id="metis-import-file-input" accept=".xml,.wxr" style="display:none;" onchange="if(window.metisImportHandleFileInput){window.metisImportHandleFileInput(this);}">
            <button class="metis-btn metis-btn-secondary" id="metis-import-choose-file-btn" type="button">Choose File</button>
        </div>
        <div id="metis-import-file-selected" style="display:none;margin-top:12px;padding:12px 16px;background:var(--metis-surface,#fff);border:1px solid var(--metis-border,#e2e6ea);border-radius:6px;align-items:center;gap:12px;" role="status" aria-live="polite">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px;flex-shrink:0;color:var(--metis-primary,#0d6efd);" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span id="metis-import-file-name" style="flex:1;font-size:13px;"></span>
            <button class="metis-btn metis-btn-primary metis-btn-sm" id="metis-import-parse-btn" type="button">Parse File</button>
        </div>
        <div id="metis-import-parsing" style="display:none;margin-top:16px;text-align:center;color:var(--metis-text-muted,#888);font-size:14px;" role="status" aria-live="polite">
            <div style="margin-bottom:8px;">Parsing file…</div>
            <div style="height:4px;background:var(--metis-border,#e2e6ea);border-radius:2px;overflow:hidden;"><div style="height:100%;width:60%;background:var(--metis-primary,#0d6efd);animation:metisProgress 1.2s ease-in-out infinite alternate;border-radius:2px;"></div></div>
        </div>
    </div>

    <!-- Step 2: Preview -->
    <div id="metis-import-step-2" style="display:none;">
        <div id="metis-import-preview-content"></div>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end;">
            <button class="metis-btn metis-btn-ghost" id="metis-import-back-btn" type="button">← Back</button>
            <button class="metis-btn metis-btn-primary" id="metis-import-confirm-btn" type="button">Import Selected</button>
        </div>
    </div>

    <!-- Step 3: Importing -->
    <div id="metis-import-step-3" style="display:none;text-align:center;padding:40px 20px;">
        <div style="font-size:48px;margin-bottom:16px;">⏳</div>
        <div style="font-size:16px;font-weight:600;margin-bottom:8px;">Importing content…</div>
        <div style="font-size:13px;color:var(--metis-text-muted,#888);">Please wait. Do not close this page.</div>
    </div>

    <!-- Step 4: Done -->
    <div id="metis-import-step-4" style="display:none;text-align:center;padding:40px 20px;">
        <div style="font-size:48px;margin-bottom:16px;">✅</div>
        <div style="font-size:18px;font-weight:700;margin-bottom:8px;">Import Complete</div>
        <div id="metis-import-results-summary" style="font-size:13px;color:var(--metis-text-muted,#888);margin-bottom:20px;"></div>
        <div id="metis-import-errors" style="display:none;text-align:left;max-width:500px;margin:0 auto 20px;"></div>
        <div style="display:flex;gap:10px;justify-content:center;">
            <a href="<?php echo metis_escape_url( metis_portal_url( 'website', 'pages' ) ); ?>" class="metis-btn metis-btn-secondary">View Pages</a>
            <a href="<?php echo metis_escape_url( metis_portal_url( 'website', 'posts' ) ); ?>" class="metis-btn metis-btn-secondary">View Posts</a>
            <button class="metis-btn metis-btn-ghost" id="metis-import-restart-btn" type="button">New Import</button>
        </div>
    </div>

</div>

<script>
window.metisImportAjax = window.metisImportAjax || {};
window.metisImportAjax.ajax_url = window.metisImportAjax.ajax_url
    || (window.metisAjax && window.metisAjax.ajax_url)
    || '/metis/api/ajax';
window.metisImportAjax.nonce = window.metisImportAjax.nonce
    || '';
window.metisImportAjax.action_nonces = window.metisImportAjax.action_nonces
    || (window.metisAjax && window.metisAjax.action_nonces)
    || {};

if (typeof window.metisImportHandleFileInput !== 'function') {
    (function() {
        var s = document.createElement('script');
        s.src = <?php echo metis_json_encode( metis_module_asset_url( 'import', 'import.js' ) ); ?>;
        s.defer = true;
        document.head.appendChild(s);
    })();
}
</script>

<style>
.metis-import-step[data-step].is-active .metis-step-dot { background: var(--metis-primary,#0d6efd); color: #fff; }
.metis-import-step[data-step].is-done .metis-step-dot { background: #198754; color: #fff; }
@keyframes metisProgress { from { margin-left: -20%; } to { margin-left: 60%; } }

.metis-import-section { background: var(--metis-surface,#fff); border: 1px solid var(--metis-border,#e2e6ea); border-radius: 8px; padding: 16px 20px; margin-bottom: 14px; }
.metis-import-section-title { font-size: 14px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; }
.metis-import-section-count { background: var(--metis-surface-alt,#f7f8fa); border: 1px solid var(--metis-border,#e2e6ea); border-radius: 12px; font-size: 11px; font-weight: 600; padding: 2px 10px; color: var(--metis-text-muted,#666); }
.metis-import-item-list { max-height: 200px; overflow-y: auto; margin-top: 10px; }
.metis-import-item { padding: 6px 0; border-bottom: 1px solid var(--metis-border-light,#f0f2f4); font-size: 13px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.metis-import-item:last-child { border-bottom: none; }
</style>
