<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
$stats = $state['stats'] ?? [];
$tickets = $state['tickets'] ?? [];
$assignees = $state['assignees'] ?? [];
$can_manage = metis_grandys_stash_can_manage();
?>

<div class="metis-stash-app"
     data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>"
     data-view-base-url="<?php echo metis_escape_attr( metis_grandys_stash_view_url() ); ?>">

    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_label( "Grandy's Stash" ) ); ?></h1>
    <p class="mw-subtitle">Manage supply requests and donation offers.</p>
    <div id="metis-stash-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-people-stats metis-stash-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Needs Action</div><div class="metis-people-stat-value"><?php echo (int)($stats['new_tickets'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Reviewing</div><div class="metis-people-stat-value"><?php echo (int)($stats['reviewing'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Waitlist</div><div class="metis-people-stat-value"><?php echo (int)($stats['waitlist'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Ready</div><div class="metis-people-stat-value"><?php echo (int)($stats['ready'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Completed</div><div class="metis-people-stat-value"><?php echo (int)($stats['completed'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Requests</div><div class="metis-people-stat-value"><?php echo (int)($stats['requests'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Donations</div><div class="metis-people-stat-value"><?php echo (int)($stats['donations'] ?? 0); ?></div></article>
    </div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ( $can_manage ) { ?>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Search</div>
                <input id="metis-stash-search" class="mw-input" type="text" placeholder="Name, code, or email">
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Filter</div>
                <button type="button" class="mw-btn mw-btn-xs metis-stash-sidebar-filter is-active" data-filter="action">Needs Action</button>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost metis-stash-sidebar-filter" data-filter="waitlist">Waitlist</button>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost metis-stash-sidebar-filter" data-filter="mine">Assigned to Me</button>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost metis-stash-sidebar-filter" data-filter="recent">Recently Updated</button>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost metis-stash-sidebar-filter" data-filter="all">All Tickets</button>
            </div>
            <?php if ( $can_manage ) : ?>
            <div class="mw-list-sidebar-actions">
                <button type="button" class="mw-btn mw-btn-xs" id="metis-stash-new-ticket-open">+ New Ticket</button>
            </div>
            <?php endif; ?>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Navigation</div>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="mw-btn mw-btn-xs">Inbox</a>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Reports</a>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Settings</a>
            </div>
        <?php },
        'content' => static function () use ( $tickets, $can_manage, $assignees ) { ?>

    <section class="mw-premium-table metis-stash-table">
        <div class="mw-premium-header">
            <div class="mw-premium-cell">Code</div>
            <div class="mw-premium-cell">Name</div>
            <div class="mw-premium-cell">Type</div>
            <div class="mw-premium-cell">Status</div>
            <div class="mw-premium-cell">Urgency</div>
            <div class="mw-premium-cell">Assigned</div>
            <div class="mw-premium-cell">Items</div>
            <div class="mw-premium-cell">Date</div>
            <?php if ( $can_manage ) : ?><div class="mw-premium-cell">Actions</div><?php endif; ?>
        </div>
        <div id="metis-stash-rows">
        <?php foreach ( $tickets as $t ):
            $type_label = $t['type'] === 'donation' ? 'Donation' : 'Request';
            $status = (string) ($t['status'] ?? 'NEW');
            $name = metis_escape_html( (string) ($t['submit_name'] ?? 'Unknown') );
            $code = metis_escape_html( (string) ($t['code'] ?? '') );
            $urgency = ucfirst( (string) ($t['urgency'] ?? 'standard') );
            $assigned = metis_escape_html( (string) ($t['assigned_name'] ?? '—') );
            $item_count = (int) ($t['item_count'] ?? 0);
            $date = ! empty( $t['submitted_at'] ) ? date( 'M j, Y', strtotime( (string) $t['submitted_at'] ) ) : '';
            $group_code = ! empty( $t['group_code'] ) ? (string) $t['group_code'] : '';
            $search_blob = strtolower( implode( ' ', [ $code, $name, $type_label, $status, $assigned, $group_code, (string)($t['submit_email'] ?? ''), (string)($t['items_summary'] ?? '') ] ) );
        ?>
            <div class="mw-premium-row metis-stash-row"
                 data-id="<?php echo metis_escape_attr( (string) $t['id'] ); ?>"
                 data-status="<?php echo metis_escape_attr( $status ); ?>"
                 data-type="<?php echo metis_escape_attr( (string) $t['type'] ); ?>"
                 data-assigned="<?php echo metis_escape_attr( (string) ($t['assigned_to'] ?? '') ); ?>"
                 data-search="<?php echo metis_escape_attr( $search_blob ); ?>">
                <div class="mw-premium-cell"><strong><?php echo $code; ?></strong><?php if ( $group_code ) : ?><div class="mw-muted"><?php echo metis_escape_html( $group_code ); ?></div><?php endif; ?></div>
                <div class="mw-premium-cell"><?php echo $name; ?></div>
                <div class="mw-premium-cell"><span class="metis-stash-type-badge metis-stash-type-<?php echo metis_escape_attr( $t['type'] ); ?>"><?php echo metis_escape_html( $type_label ); ?></span></div>
                <div class="mw-premium-cell"><span class="metis-stash-status-badge metis-stash-status-<?php echo metis_escape_attr( strtolower( $status ) ); ?>"><?php echo metis_escape_html( $status ); ?></span></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html( $urgency ); ?></div>
                <div class="mw-premium-cell"><?php echo $assigned; ?></div>
                <div class="mw-premium-cell"><?php echo $item_count; ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html( $date ); ?></div>
                <?php if ( $can_manage ) : ?><div class="mw-premium-cell"><a class="mw-btn-xs" href="<?php echo metis_escape_url( metis_grandys_stash_view_url( (string) ( $t['code'] ?? '' ) ) ); ?>" data-ticket-url="<?php echo metis_escape_attr( metis_grandys_stash_view_url( (string) ( $t['code'] ?? '' ) ) ); ?>">Review</a></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </section>

        <?php },
    ]); ?>
    <!-- New ticket modal -->
    <?php if ( $can_manage ) : ?>
    <div class="metis-stash-modal" id="metis-stash-new-ticket-modal" aria-hidden="true">
        <div class="metis-stash-modal-dialog">
            <div class="metis-stash-modal-head">
                <h2>New Ticket</h2>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-close-modal="metis-stash-new-ticket-modal">Close</button>
            </div>
            <form id="metis-stash-new-ticket-form" class="metis-stash-form" autocomplete="off">
                <div class="metis-stash-form-row">
                    <label><span>Type</span>
                        <select class="mw-select" name="type">
                            <option value="request">Request</option>
                            <option value="donation">Donation</option>
                        </select>
                    </label>
                    <label><span>Urgency</span>
                        <select class="mw-select" name="urgency">
                            <option value="standard">Standard</option>
                            <option value="urgent">Urgent</option>
                            <option value="flexible">Flexible</option>
                        </select>
                    </label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>First name</span><input class="mw-input" type="text" name="first_name" required></label>
                    <label><span>Last name</span><input class="mw-input" type="text" name="last_name"></label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>Email</span><input class="mw-input" type="email" name="email"></label>
                    <label><span>Phone</span><input class="mw-input" type="text" name="phone"></label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>Source</span>
                        <select class="mw-select" name="source">
                            <option value="staff">Staff entry</option>
                            <option value="phone">Phone</option>
                            <option value="walk-in">Walk-in</option>
                            <option value="email">Email</option>
                            <option value="web">Web form</option>
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
                <label><span>Items (comma-separated)</span><input class="mw-input" type="text" name="items" placeholder="e.g. Wheelchair, Walker, Shower chair"></label>
                <label><span>Notes</span><textarea class="mw-input" name="notes" rows="3"></textarea></label>
                <div class="metis-stash-form-actions">
                    <button type="submit" class="mw-btn">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
