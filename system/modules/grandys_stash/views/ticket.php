<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$ticket_code = strtoupper( trim( (string) metis_get_query_var( 'metis_grandys_stash_ticket_code', '' ) ) );
$ticket = $ticket_code !== '' ? \Metis\Modules\GrandyStash\GrandyStashRepository::findTicketByCode( $ticket_code ) : null;
$state = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
$assignees = $state['assignees'] ?? [];
$can_manage = metis_grandys_stash_can_manage();

if ( ! is_array( $ticket ) ) {
    metis_set_page_title( 'Ticket not found' );
    echo '<div class="metis-alert metis-alert-error">Ticket not found.</div>';
    return;
}

$ticket_id = (int) ( $ticket['id'] ?? 0 );
$submit_name = trim( (string) ( $ticket['submit_name'] ?? '' ) );
metis_set_page_title( trim( (string) ( $ticket['code'] ?? '' ) ) . ( $submit_name !== '' ? ' - ' . $submit_name : '' ) );
?>

<div class="metis-stash-app metis-stash-ticket-page"
     data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>"
     data-ticket-page="1"
     data-ticket-id="<?php echo metis_escape_attr( (string) $ticket_id ); ?>"
     data-view-base-url="<?php echo metis_escape_attr( metis_grandys_stash_view_url() ); ?>">

    <div class="metis-stash-ticket-page-head">
        <div>
            <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>">Back to Inbox</a>
            <h1 class="metis-page-title"><?php echo metis_escape_html( (string) ( $ticket['code'] ?? 'Ticket' ) ); ?></h1>
            <p class="metis-subtitle"><?php echo metis_escape_html( $submit_name !== '' ? $submit_name : 'Review ticket details and complete next actions.' ); ?></p>
        </div>
    </div>

    <div id="metis-stash-alert" class="metis-alert" style="display:none;"></div>

    <section class="metis-stash-ticket-layout">
        <div class="metis-stash-ticket-main">
            <div id="metis-stash-ticket-header"></div>
            <div class="metis-stash-ticket-section">
                <h3>Items</h3>
                <div id="metis-stash-ticket-items"></div>
            </div>
            <div class="metis-stash-ticket-section">
                <h3>Conversation</h3>
                <div id="metis-stash-ticket-conversation"></div>
                <?php if ( $can_manage ) : ?>
                <div class="metis-stash-reply-form">
                    <label><span>Reply subject</span><input class="metis-input" type="text" id="metis-stash-reply-subject" placeholder="[GST-000123] Grandy's Stash Update"></label>
                    <label><span>Reply message</span><textarea class="metis-input" id="metis-stash-reply-input" placeholder="Type your email reply..." rows="5"></textarea></label>
                    <div class="metis-stash-form-actions">
                        <button type="button" class="metis-btn metis-btn-xs" id="metis-stash-reply-submit">Send reply</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="metis-stash-ticket-section">
                <h3>Notes</h3>
                <div id="metis-stash-ticket-notes"></div>
                <?php if ( $can_manage ) : ?>
                <div class="metis-stash-note-form">
                    <textarea class="metis-input" id="metis-stash-note-input" placeholder="Add a note..." rows="2"></textarea>
                    <button type="button" class="metis-btn metis-btn-xs" id="metis-stash-note-submit">Add note</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="metis-stash-ticket-section">
                <h3>Activity</h3>
                <div id="metis-stash-ticket-activity"></div>
            </div>
        </div>

        <aside class="metis-stash-ticket-sidebar">
            <div class="metis-stash-ticket-section metis-stash-ticket-sidecard" id="metis-stash-ticket-group-section" style="display:none;">
                <h3>Person Group</h3>
                <div id="metis-stash-ticket-group"></div>
            </div>
            <?php if ( $can_manage ) : ?>
            <div class="metis-stash-ticket-actions metis-stash-ticket-sidecard">
                <h3>Ticket Actions</h3>
                <form id="metis-stash-ticket-form" class="metis-stash-form" autocomplete="off">
                    <input type="hidden" name="id" value="<?php echo metis_escape_attr( (string) $ticket_id ); ?>">
                    <div class="metis-stash-form-row">
                        <label><span>Status</span>
                            <select class="metis-select" name="status">
                                <option value="NEW">New</option>
                                <option value="REVIEWING">Reviewing</option>
                                <option value="WAITLIST">Waitlist</option>
                                <option value="READY">Ready</option>
                                <option value="COMPLETED">Completed</option>
                                <option value="CLOSED">Closed</option>
                            </select>
                        </label>
                    </div>
                    <div class="metis-stash-form-row">
                        <label><span>Assigned to</span>
                            <select class="metis-select" name="assigned_to" id="metis-stash-ticket-assignee">
                                <option value="">Unassigned</option>
                                <?php foreach ( $assignees as $a ) : ?>
                                <option value="<?php echo metis_escape_attr( (string) $a['id'] ); ?>"><?php echo metis_escape_html( (string) $a['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="metis-stash-form-row">
                        <label><span>Urgency</span>
                            <select class="metis-select" name="urgency">
                                <option value="urgent">Urgent</option>
                                <option value="standard">Standard</option>
                                <option value="flexible">Flexible</option>
                            </select>
                        </label>
                    </div>
                    <div class="metis-stash-form-actions">
                        <button type="submit" class="metis-btn">Save ticket</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </aside>
    </section>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
