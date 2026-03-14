<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_forms_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view forms.</div>';
    return;
}

metis_forms_ensure_schema();

$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
$form = $form_id > 0 ? \Metis\Modules\Forms\Repository::getFormById( $form_id ) : null;

if ( ! $form ) {
    echo '<div class="mw-alert mw-alert-error">Form not found.</div>';
    return;
}

metis_set_page_title( 'Settings' );
?>
<div class="metis-forms-settings-page" data-settings-view="1" data-can-manage="<?php echo esc_attr( metis_forms_can_manage() ? '1' : '0' ); ?>">
    <div id="metis-forms-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-forms-detail-head">
        <div>
            <h1 class="mw-page-title"><?php echo esc_html( (string) $form['name'] ); ?> Settings</h1>
            <p class="mw-subtitle">Use the left menu to jump between sections. Each section opens in a focused editor so the page stays easy to scan.</p>
        </div>
        <div class="metis-forms-detail-actions">
            <?php if ( metis_forms_can_manage() ) : ?>
                <button type="button" class="mw-btn mw-btn-xs" id="metis-forms-save-settings">Save settings</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="mw-list-layout metis-forms-settings-shell">
        <aside class="mw-list-sidebar">
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Forms</div>
                <nav class="mw-list-sidebar-nav">
                    <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_base_url() ); ?>">All forms</a>
                    <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_detail_url( (int) $form['id'] ) ); ?>">Overview</a>
                    <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_build_url( (int) $form['id'] ) ); ?>">Builder</a>
                    <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url( metis_forms_entries_url( (int) $form['id'] ) ); ?>">Entries</a>
                    <a class="mw-list-sidebar-nav-item is-active" href="<?php echo esc_url( metis_forms_settings_url( (int) $form['id'] ) ); ?>">Settings</a>
                </nav>
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Settings</div>
                <nav class="mw-list-sidebar-nav">
                    <button type="button" class="mw-list-sidebar-nav-item is-active" data-settings-nav="confirmation">Confirmation</button>
                    <button type="button" class="mw-list-sidebar-nav-item" data-settings-nav="submitter">Submitter Email</button>
                    <button type="button" class="mw-list-sidebar-nav-item" data-settings-nav="receiver">Receiver Alerts</button>
                    <button type="button" class="mw-list-sidebar-nav-item" data-settings-nav="routing">Routing Rules</button>
                    <button type="button" class="mw-list-sidebar-nav-item" data-settings-nav="payments">Payments</button>
                    <button type="button" class="mw-list-sidebar-nav-item" data-settings-nav="style">Public Style</button>
                </nav>
            </div>
        </aside>

        <div class="mw-list-content">
            <div class="metis-forms-detail-grid">
                <section class="metis-forms-detail-card" data-settings-card="confirmation">
                    <div class="metis-forms-card-head">
                        <h2>Confirmation</h2>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-open-settings-section="confirmation">Edit</button>
                    </div>
                    <p>Set the public description, thank-you message, and any follow-up webhook.</p>
                    <div class="mw-muted">Merge tags and rich formatting are supported in the confirmation editor.</div>
                </section>

                <section class="metis-forms-detail-card" data-settings-card="submitter">
                    <div class="metis-forms-card-head">
                        <h2>Submitter Email</h2>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-open-settings-section="submitter">Edit</button>
                    </div>
                    <p>Send a confirmation email to the person who completed the form.</p>
                    <div class="mw-muted">Choose the email field, insert merge tags, and format the message visually.</div>
                </section>

                <section class="metis-forms-detail-card" data-settings-card="receiver">
                    <div class="metis-forms-card-head">
                        <h2>Receiver Alerts</h2>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-open-settings-section="receiver">Edit</button>
                    </div>
                    <p>Configure the internal notification emails sent to your team.</p>
                    <div class="mw-muted">Use merge tags and rich formatting for cleaner messages.</div>
                </section>

                <section class="metis-forms-detail-card" data-settings-card="routing">
                    <div class="metis-forms-card-head">
                        <h2>Routing Rules</h2>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-open-settings-section="routing">Edit</button>
                    </div>
                    <p>Route receiver alerts based on the answers selected in the form.</p>
                    <div class="mw-muted">Choose the field, define the match, and pick who gets notified.</div>
                </section>

                <section class="metis-forms-detail-card" data-settings-card="payments">
                    <div class="metis-forms-card-head">
                        <h2>Payments</h2>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-open-settings-section="payments">Edit</button>
                    </div>
                    <p>Stripe settings only appear when the form includes a Stripe payment field.</p>
                    <div class="mw-muted">Use the builder to add a Stripe payment field if this form needs payment collection.</div>
                </section>

                <section class="metis-forms-detail-card" data-settings-card="style">
                    <div class="metis-forms-card-head">
                        <h2>Public Style</h2>
                        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-open-settings-section="style">Edit</button>
                    </div>
                    <p>Control the button, field, and card styling for the public form.</p>
                    <div class="mw-muted">Preview changes before saving.</div>
                </section>
            </div>

            <div class="metis-forms-settings-preview">
                <div class="metis-forms-style-preview" id="metis-forms-style-preview">
                    <div class="metis-forms-style-preview-card">
                        <label>Preview field</label>
                        <input class="mw-input" type="text" value="Sample response" readonly>
                        <button type="button" class="mw-btn">Preview button</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="metis-forms-settings-editors" hidden>
        <section class="metis-forms-settings-editor" data-settings-section="confirmation">
            <h2 class="metis-contacts-modal-title">Confirmation</h2>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-description">Description</label>
                <textarea id="metis-forms-description" class="mw-input"></textarea>
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-confirmation">Confirmation message</label>
                <div class="metis-forms-rich-toolbar" data-rich-toolbar>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="bold">Bold</button>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="italic">Italic</button>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="insertUnorderedList">Bullets</button>
                </div>
                <div class="metis-forms-merge-tags" data-merge-tags="confirmation"></div>
                <div id="metis-forms-confirmation-editor" class="metis-forms-rich-editor" contenteditable="true" data-rich-editor="confirmation"></div>
                <textarea id="metis-forms-confirmation" class="mw-input metis-forms-rich-input" hidden></textarea>
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-webhook">Webhook URL</label>
                <input id="metis-forms-webhook" class="mw-input" type="url">
            </div>
        </section>

        <section class="metis-forms-settings-editor" data-settings-section="submitter">
            <h2 class="metis-contacts-modal-title">Submitter Email</h2>
            <div class="metis-forms-inline-checks">
                <label><input id="metis-forms-submitter-enabled" type="checkbox"> Send a confirmation email</label>
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-submitter-recipient">Email field</label>
                <select id="metis-forms-submitter-recipient" class="mw-select"></select>
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-submitter-subject">Subject</label>
                <input id="metis-forms-submitter-subject" class="mw-input" type="text">
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-submitter-message">Message</label>
                <div class="metis-forms-rich-toolbar" data-rich-toolbar>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="bold">Bold</button>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="italic">Italic</button>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="insertUnorderedList">Bullets</button>
                </div>
                <div class="metis-forms-merge-tags" data-merge-tags="submitter"></div>
                <div id="metis-forms-submitter-message-editor" class="metis-forms-rich-editor" contenteditable="true" data-rich-editor="submitter_message"></div>
                <textarea id="metis-forms-submitter-message" class="mw-input metis-forms-rich-input" hidden></textarea>
            </div>
        </section>

        <section class="metis-forms-settings-editor" data-settings-section="receiver">
            <h2 class="metis-contacts-modal-title">Receiver Alerts</h2>
            <div class="metis-forms-inline-checks">
                <label><input id="metis-forms-receiver-enabled" type="checkbox"> Send internal alerts</label>
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-receiver-emails">Default recipient emails</label>
                <input id="metis-forms-receiver-emails" class="mw-input" type="text" placeholder="ops@example.org, team@example.org">
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-receiver-subject">Subject</label>
                <input id="metis-forms-receiver-subject" class="mw-input" type="text">
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-receiver-message">Message</label>
                <div class="metis-forms-rich-toolbar" data-rich-toolbar>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="bold">Bold</button>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="italic">Italic</button>
                    <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-rich-command="insertUnorderedList">Bullets</button>
                </div>
                <div class="metis-forms-merge-tags" data-merge-tags="receiver"></div>
                <div id="metis-forms-receiver-message-editor" class="metis-forms-rich-editor" contenteditable="true" data-rich-editor="receiver_message"></div>
                <textarea id="metis-forms-receiver-message" class="mw-input metis-forms-rich-input" hidden></textarea>
            </div>
        </section>

        <section class="metis-forms-settings-editor" data-settings-section="routing">
            <h2 class="metis-contacts-modal-title">Routing Rules</h2>
            <p class="mw-muted">Route receiver notifications by choosing a field, a match, and who should be notified.</p>
            <div id="metis-forms-rule-list" class="metis-forms-rule-list"></div>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="metis-forms-add-rule">Add routing rule</button>
        </section>

        <section class="metis-forms-settings-editor" data-settings-section="payments">
            <h2 class="metis-contacts-modal-title">Payments</h2>
            <div class="metis-forms-payment-empty" id="metis-forms-payment-empty">
                <p class="mw-muted">This form does not currently have a Stripe payment field. Add one in the builder to unlock payment settings here.</p>
            </div>
            <div id="metis-forms-payment-settings">
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-currency">Currency</label>
                    <input id="metis-forms-currency" class="mw-input" type="text">
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-total-source">Total source</label>
                    <select id="metis-forms-total-source" class="mw-select">
                        <option value="calculated">Calculated from priced fields</option>
                        <option value="field_value">Use a single number field</option>
                    </select>
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-total-field-key">Number field for total</label>
                    <select id="metis-forms-total-field-key" class="mw-select"></select>
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-discounts">Discounts</label>
                    <textarea id="metis-forms-discounts" class="mw-input" placeholder="CODE|fixed|10&#10;EARLY|percent|15"></textarea>
                </div>
                <div class="metis-forms-inline-checks">
                    <label><input id="metis-forms-allow-discount" type="checkbox"> Show discount code field</label>
                    <label><input id="metis-forms-fee-enabled" type="checkbox"> Add a processing fee</label>
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-fee-mode">Fee handling</label>
                    <select id="metis-forms-fee-mode" class="mw-select">
                        <option value="pass_through">Charge the payer</option>
                        <option value="absorb">Absorb the fee</option>
                    </select>
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-fee-percent">Percent</label>
                    <input id="metis-forms-fee-percent" class="mw-input" type="number" min="0" step="0.01">
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-fee-fixed">Fixed amount</label>
                    <input id="metis-forms-fee-fixed" class="mw-input" type="number" min="0" step="0.01">
                </div>
                <div class="metis-forms-inspector-section">
                    <label for="metis-forms-fee-apply-to">Fee base</label>
                    <select id="metis-forms-fee-apply-to" class="mw-select">
                        <option value="net">After discounts</option>
                        <option value="subtotal">Before discounts</option>
                    </select>
                </div>
            </div>
            <input id="metis-forms-payments-enabled" type="checkbox" hidden>
        </section>

        <section class="metis-forms-settings-editor" data-settings-section="style">
            <h2 class="metis-contacts-modal-title">Public Style</h2>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-design-accent">Accent color</label>
                <input id="metis-forms-design-accent" class="mw-input" type="color">
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-design-button-bg">Button background</label>
                <input id="metis-forms-design-button-bg" class="mw-input" type="color">
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-design-button-text">Button text</label>
                <input id="metis-forms-design-button-text" class="mw-input" type="color">
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-design-field-radius">Field rounding</label>
                <select id="metis-forms-design-field-radius" class="mw-select">
                    <option value="10">Compact</option>
                    <option value="14">Balanced</option>
                    <option value="20">Rounded</option>
                </select>
            </div>
            <div class="metis-forms-inspector-section">
                <label for="metis-forms-design-surface-style">Field style</label>
                <select id="metis-forms-design-surface-style" class="mw-select">
                    <option value="clean">Clean</option>
                    <option value="soft">Soft</option>
                    <option value="outline">Outline</option>
                </select>
            </div>
        </section>
    </div>

    <div class="metis-contacts-modal" id="metis-forms-settings-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner" style="max-width:900px;">
            <div class="metis-forms-modal-head">
                <div>
                    <h2 class="metis-contacts-modal-title" id="metis-forms-settings-modal-title">Settings</h2>
                    <p class="mw-muted">Use merge tags to personalize messages and save when you are done.</p>
                </div>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-close-modal="metis-forms-settings-modal">Close</button>
            </div>
            <div id="metis-forms-settings-modal-body"></div>
        </div>
    </div>

    <script id="metis-forms-admin-data" type="application/json"><?php
        echo metis_json_encode( [
            'mode' => 'settings',
            'selected' => $form,
        ] );
    ?></script>
</div>
