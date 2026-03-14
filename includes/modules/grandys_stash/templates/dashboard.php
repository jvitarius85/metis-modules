<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStashRepository::dashboardData();
?>
<div class="metis-stash-app" data-can-manage="<?php echo esc_attr( metis_grandys_stash_can_manage() ? '1' : '0' ); ?>">
    <div id="metis-stash-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-stash-hero">
        <div>
            <h1 class="mw-page-title">Grandy's Stash</h1>
            <p class="mw-subtitle">Track community equipment intake, recipient distribution, and donation review without routing staff through the generic form builder.</p>
        </div>
        <div class="metis-stash-hero-actions">
            <button type="button" class="mw-btn mw-btn-ghost" id="metis-stash-refresh">Refresh</button>
            <?php if ( metis_grandys_stash_can_manage() ) : ?>
                <button type="button" class="mw-btn" id="metis-stash-new-item">Add equipment</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="metis-stash-kpis" id="metis-stash-kpis"></div>

    <div class="metis-stash-toolbar">
        <div class="metis-stash-toolbar-group">
            <strong>Queue</strong>
            <div id="metis-stash-case-filters" class="metis-stash-filters"></div>
        </div>
        <div class="metis-stash-toolbar-group">
            <strong>Inventory</strong>
            <div id="metis-stash-item-filters" class="metis-stash-filters"></div>
        </div>
        <div class="metis-stash-toolbar-group">
            <strong>Assignments</strong>
            <div id="metis-stash-distribution-filters" class="metis-stash-filters"></div>
        </div>
    </div>

    <div class="metis-stash-grid">
        <section class="metis-stash-card">
            <div class="metis-stash-card-head">
                <div>
                    <h2>Intake inbox</h2>
                    <p>Review requests and donation offers like tickets, then move them through ready, fulfilled, or closed.</p>
                </div>
            </div>
            <div id="metis-stash-cases" class="metis-stash-list"></div>
        </section>

        <section class="metis-stash-card">
            <div class="metis-stash-card-head">
                <div>
                    <h2>Assigned and active</h2>
                    <p>Track recipient assignments and scheduled handoffs without losing the equipment history.</p>
                </div>
            </div>
            <div id="metis-stash-distributions" class="metis-stash-list"></div>
        </section>

        <section class="metis-stash-card">
            <div class="metis-stash-card-head">
                <div>
                    <h2>Inventory</h2>
                    <p>Each item carries a unique identifier, category, condition, and operational status.</p>
                </div>
            </div>
            <div id="metis-stash-items" class="metis-stash-list"></div>
        </section>
    </div>

    <div class="metis-stash-modal" id="metis-stash-item-modal" aria-hidden="true">
        <div class="metis-stash-modal-dialog">
            <div class="metis-stash-modal-head">
                <h2>Equipment record</h2>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-close-modal="metis-stash-item-modal">Close</button>
            </div>
            <form id="metis-stash-item-form" class="metis-stash-form" autocomplete="off">
                <input type="hidden" name="id">
                <label><span>Category</span>
                    <select class="mw-select" name="category" id="metis-stash-item-category" autocomplete="off"></select>
                </label>
                <label><span>Item</span>
                    <input class="mw-input" type="text" name="grandy_stash_item_name" id="metis-stash-item-name" list="metis-stash-item-options" required placeholder="Search this category or enter a new item" autocomplete="section-grandys-stash off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <datalist id="metis-stash-item-options"></datalist>
                </label>
                <div class="metis-stash-form-row">
                    <label><span>Condition</span>
                        <select class="mw-select" name="condition_status" autocomplete="off">
                            <option value="excellent">Excellent</option>
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="repair">Needs repair</option>
                        </select>
                    </label>
                    <label><span>Status</span>
                        <select class="mw-select" name="status" autocomplete="off">
                            <option value="available">Available</option>
                            <option value="assigned">Assigned</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="intake_review">Intake review</option>
                        </select>
                    </label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>Storage location</span><input class="mw-input" type="text" name="storage_location" autocomplete="off"></label>
                    <label><span>Serial / tag</span><input class="mw-input" type="text" name="serial_number" autocomplete="off"></label>
                </div>
                <label><span>Notes</span><textarea class="mw-input" name="notes" autocomplete="off"></textarea></label>
                <div class="metis-stash-form-actions">
                    <button type="submit" class="mw-btn">Save equipment</button>
                </div>
            </form>
        </div>
    </div>

    <div class="metis-stash-modal" id="metis-stash-case-modal" aria-hidden="true">
        <div class="metis-stash-modal-dialog metis-stash-modal-wide">
            <div class="metis-stash-modal-head">
                <h2>Case review</h2>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-close-modal="metis-stash-case-modal">Close</button>
            </div>
            <form id="metis-stash-case-form" class="metis-stash-form">
                <input type="hidden" name="id">
                <div class="metis-stash-form-row">
                    <label><span>Status</span>
                        <select class="mw-select" name="status">
                            <option value="new">New</option>
                            <option value="review">Review</option>
                            <option value="ready">Ready</option>
                            <option value="fulfilled">Fulfilled</option>
                            <option value="closed">Closed</option>
                        </select>
                    </label>
                    <label><span>Urgency</span>
                        <select class="mw-select" name="urgency">
                            <option value="urgent">Urgent</option>
                            <option value="standard">Standard</option>
                            <option value="flexible">Flexible</option>
                        </select>
                    </label>
                    <label><span>Coordination</span>
                        <select class="mw-select" name="pickup_delivery">
                            <option value="">Not set</option>
                            <option value="pickup">Pick up</option>
                            <option value="delivery">Delivery</option>
                            <option value="dropoff">Drop off</option>
                            <option value="discuss">Discuss</option>
                        </select>
                    </label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>Ticket type</span><input class="mw-input" type="text" name="intake_type_display" readonly></label>
                    <label><span>Assigned to</span><select class="mw-select" name="assignee_user_id" id="metis-stash-case-assignee"></select></label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>First name</span><input class="mw-input" type="text" name="contact_first_name"></label>
                    <label><span>Last name</span><input class="mw-input" type="text" name="contact_last_name"></label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>Email</span><input class="mw-input" type="email" name="contact_email"></label>
                    <label><span>Phone</span><input class="mw-input" type="text" name="contact_phone"></label>
                </div>
                <label><span>Schedule</span><input class="mw-input" type="datetime-local" name="scheduled_for"></label>
                <div class="metis-stash-submission" id="metis-stash-case-submission"></div>
                <label><span>Program notes</span><textarea class="mw-input" name="notes"></textarea></label>
                <label><span>Internal notes</span><textarea class="mw-input" name="internal_notes"></textarea></label>

                <div class="metis-stash-assignment">
                    <h3>Assign equipment</h3>
                    <div class="metis-stash-form-row">
                        <label><span>Available item</span><select class="mw-select" name="item_id" id="metis-stash-assignment-item"></select></label>
                        <label><span>Assignment status</span>
                            <select class="mw-select" name="assignment_status">
                                <option value="assigned">Assigned</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="completed">Completed</option>
                            </select>
                        </label>
                    </div>
                    <div class="metis-stash-form-actions">
                        <button type="button" class="mw-btn mw-btn-ghost" id="metis-stash-assign">Assign selected item</button>
                    </div>
                </div>

                <div class="metis-stash-form-actions">
                    <button type="submit" class="mw-btn">Save case</button>
                </div>
            </form>
        </div>
    </div>

    <script id="metis-stash-boot" type="application/json"><?php echo wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
