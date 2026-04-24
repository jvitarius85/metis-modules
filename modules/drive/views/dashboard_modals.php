<?php if (!empty($cfg['ok']) && $can_manage) : ?>
<div class="metis-contacts-modal" id="metis-drive-folder-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:480px;">
        <h3 class="metis-contacts-modal-title">New Folder</h3>
        <form id="metis-drive-folder-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-drive-folder-name">Folder Name</label>
                <input id="metis-drive-folder-name" class="mw-input" type="text" required autocomplete="off">
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-drive-cancel">Cancel</button>
                <button type="submit" class="mw-btn">Create</button>
            </div>
        </form>
    </div>
</div>

<div class="metis-contacts-modal" id="metis-drive-google-file-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:520px;">
        <h3 class="metis-contacts-modal-title">New Google File</h3>
        <form id="metis-drive-google-file-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-drive-google-file-type">Type</label>
                <select id="metis-drive-google-file-type" class="mw-select" required>
                    <option value="doc">Google Doc</option>
                    <option value="sheet">Google Sheet</option>
                    <option value="slides">Google Slides</option>
                    <option value="form">Google Form</option>
                </select>
            </div>
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-drive-google-file-name">Name <span style="font-weight:400;color:var(--mw-text-muted)">(optional)</span></label>
                <input id="metis-drive-google-file-name" class="mw-input" type="text" placeholder="Leave blank for auto-name" autocomplete="off">
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-drive-cancel">Cancel</button>
                <button type="submit" class="mw-btn">Create</button>
            </div>
        </form>
    </div>
</div>

<div class="metis-contacts-modal" id="metis-drive-rename-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:480px;">
        <h3 class="metis-contacts-modal-title">Rename</h3>
        <form id="metis-drive-rename-form" class="metis-contact-form">
            <input type="hidden" id="metis-drive-rename-id" />
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-drive-rename-name">New Name</label>
                <input id="metis-drive-rename-name" class="mw-input" type="text" required autocomplete="off">
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-drive-cancel">Cancel</button>
                <button type="submit" class="mw-btn">Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
