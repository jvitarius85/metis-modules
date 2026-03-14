<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_forms_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view forms.</div>';
    return;
}

metis_forms_ensure_schema();

$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
$selected = $form_id > 0 ? \Metis\Modules\Forms\Repository::getFormById( $form_id ) : null;
if ( $selected ) {
    metis_set_page_title( 'Builder' );
}
?>
<div class="metis-forms-builder-app" data-can-manage="<?php echo esc_attr( metis_forms_can_manage() ? '1' : '0' ); ?>" data-can-delete="<?php echo esc_attr( metis_forms_can_delete() ? '1' : '0' ); ?>" data-builder-view="1" data-forms-home-url="<?php echo esc_url( metis_forms_base_url() ); ?>">
    <div id="metis-forms-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-forms-builder-topbar">
        <div>
            <h1 class="mw-page-title"><?php echo esc_html( $selected ? (string) $selected['name'] : 'New form' ); ?></h1>
            <p class="mw-subtitle">Build the form on the canvas, then open field settings from the cog on each block.</p>
        </div>
        <div class="metis-forms-detail-actions">
            <?php if ( metis_forms_can_manage() ) : ?>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-duplicate">Duplicate</button>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-publish">Publish</button>
                <button type="button" class="mw-btn mw-btn-xs" id="metis-forms-save">Save</button>
                <?php if ( metis_forms_can_delete() && $selected ) : ?>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-delete">Delete</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="mw-list-layout metis-forms-builder-shell">
        <aside class="mw-list-sidebar">
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Forms</div>
                <nav class="mw-list-sidebar-nav">
                    <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_base_url() ); ?>">All forms</a>
                    <?php if ( $selected ) : ?>
                        <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_detail_url( (int) $selected['id'] ) ); ?>">Overview</a>
                        <a class="mw-list-sidebar-nav-item is-active" href="<?php echo esc_url( metis_forms_build_url( (int) $selected['id'] ) ); ?>">Builder</a>
                        <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_entries_url( (int) $selected['id'] ) ); ?>">Entries</a>
                        <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_settings_url( (int) $selected['id'] ) ); ?>">Settings</a>
                    <?php else : ?>
                        <span class="mw-list-sidebar-nav-item is-active">Builder</span>
                    <?php endif; ?>
                </nav>
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Field library</div>
                <p class="mw-muted">Drag fields into the canvas. Use the cog on a field to adjust its settings in a modal.</p>
                <nav class="mw-list-sidebar-nav metis-forms-library-nav" id="metis-forms-palette"></nav>
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Remove fields</div>
                <div class="metis-forms-trash-zone" id="metis-forms-trash-zone">
                    <strong>Delete field</strong>
                    <small>Drag a field here to remove it, or right-click the field in the canvas.</small>
                </div>
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Form settings</div>
                <p class="mw-muted">Notifications, confirmations, Stripe totals, and styling live on the dedicated settings page.</p>
                <?php if ( $selected ) : ?>
                    <a class="mw-btn mw-btn-xs" href="<?php echo esc_url( metis_forms_settings_url( (int) $selected['id'] ) ); ?>">Open settings</a>
                <?php else : ?>
                    <p class="mw-muted">Save this form first to unlock the settings page.</p>
                <?php endif; ?>
            </div>
        </aside>

        <div class="mw-list-content">
            <section class="metis-forms-canvas-stage">
                <div class="metis-forms-builder-meta">
                    <div class="metis-forms-meta-group">
                        <label for="metis-forms-name">Form name</label>
                        <input id="metis-forms-name" class="mw-input" type="text">
                    </div>
                    <div class="metis-forms-meta-group">
                        <label for="metis-forms-slug">Slug</label>
                        <input id="metis-forms-slug" class="mw-input" type="text">
                    </div>
                    <div class="metis-forms-meta-group">
                        <label for="metis-forms-status">Status</label>
                        <select id="metis-forms-status" class="mw-select">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="metis-forms-canvas-head">
                    <div>
                        <div class="metis-forms-sidebar-label">Canvas</div>
                        <div class="mw-muted">Drop fields here, drag to reorder, and use the cog to edit field settings.</div>
                    </div>
                    <a href="#" id="metis-forms-public-link" target="_blank" rel="noopener">Open public form</a>
                </div>
                <div class="metis-forms-canvas-dropzone" id="metis-forms-canvas-list"></div>
                <div class="metis-forms-version-strip">
                    <div class="metis-forms-sidebar-label">Versions</div>
                    <div id="metis-forms-versions" class="metis-forms-version-list"></div>
                </div>
            </section>
        </div>
    </div>

    <div class="metis-contacts-modal" id="metis-forms-field-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner" style="max-width:880px;">
            <div class="metis-forms-modal-head">
                <div>
                    <h2 class="metis-contacts-modal-title">Field settings</h2>
                    <p id="metis-forms-inspector-empty" class="mw-muted">Select a field from the canvas to edit it.</p>
                </div>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-close-modal="metis-forms-field-modal">Close</button>
            </div>
            <div id="metis-forms-inspector-panel" style="display:none;">
                <div class="metis-forms-sidebar-label">Field inspector</div>
                <p id="metis-forms-field-type-note" class="mw-muted"></p>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-field-label">Label</label>
                    <input id="metis-forms-field-label" class="mw-input" type="text">
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-field-key">Key</label>
                    <input id="metis-forms-field-key" class="mw-input" type="text">
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-field-help">Help text</label>
                    <textarea id="metis-forms-field-help" class="mw-input"></textarea>
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="placeholder">
                    <label for="metis-forms-field-placeholder">Placeholder</label>
                    <input id="metis-forms-field-placeholder" class="mw-input" type="text">
                </div>
                <div class="metis-forms-inline-checks">
                    <label><input id="metis-forms-field-required" type="checkbox"> Required</label>
                    <label><input id="metis-forms-field-half" type="checkbox"> Half width</label>
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="choice">
                    <label for="metis-forms-field-options">Static options</label>
                    <textarea id="metis-forms-field-options" class="mw-input" placeholder="One per line&#10;Label|value|category"></textarea>
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="choice">
                    <label for="metis-forms-field-source">Dynamic source</label>
                    <select id="metis-forms-field-source" class="mw-select">
                        <option value="">Static options</option>
                        <option value="contacts">Contacts</option>
                        <option value="campaigns">Campaigns</option>
                        <option value="events">Events</option>
                        <option value="grandys_stash_categories">Grandy's Stash categories</option>
                        <option value="grandys_stash_items">Grandy's Stash items</option>
                        <option value="custom">Custom dataset</option>
                    </select>
                    <textarea id="metis-forms-field-source-items" class="mw-input" placeholder="Custom dataset&#10;Label|value|category"></textarea>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-preview-source">Preview source</button>
                    <div id="metis-forms-source-preview" class="mw-muted"></div>
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="select-enhancements">
                    <label><input id="metis-forms-field-searchable" type="checkbox"> Let users type to narrow this dropdown</label>
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="select-enhancements">
                    <label for="metis-forms-field-depends-on">Filter choices from another field</label>
                    <select id="metis-forms-field-depends-on" class="mw-select"></select>
                    <p class="mw-muted">Use the third column in options (`Label|value|category`) to match the parent field value.</p>
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="formatting">
                    <label for="metis-forms-field-format">Formatting</label>
                    <select id="metis-forms-field-format" class="mw-select">
                        <option value="">None</option>
                        <option value="phone_us">US phone</option>
                        <option value="ssn">SSN</option>
                        <option value="zip">ZIP code</option>
                        <option value="uppercase">Uppercase</option>
                        <option value="currency">Currency</option>
                        <option value="integer">Integer</option>
                    </select>
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="length">
                    <label for="metis-forms-field-min-length">Min length</label>
                    <input id="metis-forms-field-min-length" class="mw-input" type="number" min="0">
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="length">
                    <label for="metis-forms-field-max-length">Max length</label>
                    <input id="metis-forms-field-max-length" class="mw-input" type="number" min="0">
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="numberish">
                    <label for="metis-forms-field-min">Minimum value</label>
                    <input id="metis-forms-field-min" class="mw-input" type="text">
                </div>
                <div class="metis-forms-inspector-section" data-inspector-for="numberish">
                    <label for="metis-forms-field-max">Maximum value</label>
                    <input id="metis-forms-field-max" class="mw-input" type="text">
                </div>
                <div class="metis-forms-detail-card" data-inspector-for="conditions">
                    <h2>Visibility rules</h2>
                    <p class="mw-muted">Show this field only when another answer matches the rule.</p>
                    <div id="metis-forms-condition-list" class="metis-forms-rule-list"></div>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-add-condition">Add visibility rule</button>
                </div>
                <div class="metis-forms-detail-card" data-inspector-for="pricing">
                    <h2>Pricing</h2>
                    <div class="metis-forms-inline-checks">
                        <label><input id="metis-forms-pricing-enabled" type="checkbox"> Include in totals</label>
                    </div>
                    <div class="metis-forms-inspector-section">
                        <label for="metis-forms-pricing-type">Pricing model</label>
                        <select id="metis-forms-pricing-type" class="mw-select">
                            <option value="fixed">Fixed amount</option>
                            <option value="quantity">Amount x entered quantity</option>
                            <option value="choice">Amount by selected option</option>
                        </select>
                    </div>
                    <div class="metis-forms-inspector-section" data-pricing-scope="fixed quantity">
                        <label for="metis-forms-pricing-amount">Amount</label>
                        <input id="metis-forms-pricing-amount" class="mw-input" type="number" min="0" step="0.01">
                    </div>
                    <div class="metis-forms-inspector-section" data-pricing-scope="choice">
                        <label>Option amounts</label>
                        <div id="metis-forms-pricing-choice-list" class="metis-forms-rule-list"></div>
                    </div>
                </div>
                <div class="metis-forms-detail-card" data-inspector-for="repeaters">
                    <h2>Repeating group</h2>
                    <div class="metis-forms-inspector-section">
                        <label for="metis-forms-repeat-limit">Maximum rows</label>
                        <input id="metis-forms-repeat-limit" class="mw-input" type="number" min="1" step="1">
                    </div>
                    <div id="metis-forms-subfield-list" class="metis-forms-rule-list"></div>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-add-subfield">Add subfield</button>
                </div>
                <div class="metis-forms-inspector-section">
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-delete-field">Remove field</button>
                </div>
            </div>
        </div>
    </div>

    <script id="metis-forms-admin-data" type="application/json"><?php
        echo metis_json_encode( [
            'mode' => 'builder',
            'selected' => $selected,
            'field_library' => [
                [ 'type' => 'text', 'label' => 'Text' ],
                [ 'type' => 'email', 'label' => 'Email' ],
                [ 'type' => 'number', 'label' => 'Number' ],
                [ 'type' => 'textarea', 'label' => 'Textarea' ],
                [ 'type' => 'select', 'label' => 'Dropdown' ],
                [ 'type' => 'checkbox', 'label' => 'Checkboxes' ],
                [ 'type' => 'radio', 'label' => 'Radio Group' ],
                [ 'type' => 'file', 'label' => 'File Upload' ],
                [ 'type' => 'date', 'label' => 'Date' ],
                [ 'type' => 'repeater', 'label' => 'Repeating Group' ],
                [ 'type' => 'payment', 'label' => 'Stripe Payment' ],
            ],
        ] );
    ?></script>
</div>
