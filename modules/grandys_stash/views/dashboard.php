<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$state = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
$stats = $state['stats'] ?? [];
$tickets = $state['tickets'] ?? [];
$assignees = $state['assignees'] ?? [];
$can_manage = metis_grandys_stash_can_manage();
$can_create = function_exists( 'metis_grandys_stash_can_create' ) && metis_grandys_stash_can_create();
$can_settings = function_exists( 'metis_grandys_stash_can_settings' ) && metis_grandys_stash_can_settings();
$can_bulk_delete = function_exists( 'metis_grandys_stash_is_system_admin' ) && metis_grandys_stash_is_system_admin();
?>

<div class="metis-stash-app"
     data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>"
     data-can-create="<?php echo metis_escape_attr( $can_create ? '1' : '0' ); ?>"
     data-can-bulk-delete="<?php echo metis_escape_attr( $can_bulk_delete ? '1' : '0' ); ?>"
     data-current-person-id="<?php echo metis_escape_attr( (string) ( function_exists( 'metis_auth_current_person_id' ) ? (int) metis_auth_current_person_id() : 0 ) ); ?>"
     data-view-base-url="<?php echo metis_escape_attr( metis_grandys_stash_view_url() ); ?>">

    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( "Grandy's Stash" ) ); ?></h1>
    <p class="metis-subtitle">Manage supply requests and donation offers.</p>
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
        'sidebar' => static function () use ( $can_create, $can_settings, $can_bulk_delete ) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <input id="metis-stash-search" class="metis-input" type="text" placeholder="Name, code, email, org, or item">
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Sort</div>
                <select id="metis-stash-sort" class="metis-select">
                    <option value="submitted_desc">Newest First</option>
                    <option value="submitted_asc">Oldest First</option>
                    <option value="updated_desc">Recently Updated</option>
                    <option value="name_asc">Name A-Z</option>
                    <option value="name_desc">Name Z-A</option>
                    <option value="code_desc">Code High-Low</option>
                    <option value="code_asc">Code Low-High</option>
                </select>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Filter</div>
                <button type="button" class="metis-btn metis-btn-xs metis-stash-sidebar-filter is-active" data-filter="action">Needs Action</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-sidebar-filter" data-filter="waitlist">Waitlist</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-sidebar-filter" data-filter="mine">Assigned to Me</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-sidebar-filter" data-filter="recent">Recently Updated</button>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost metis-stash-sidebar-filter" data-filter="all">All Tickets</button>
            </div>
            <?php if ( $can_create ) : ?>
            <div class="metis-list-sidebar-actions">
                <button type="button" class="metis-btn metis-btn-xs" id="metis-stash-new-ticket-open">+ New Ticket</button>
            </div>
            <?php endif; ?>
            <?php if ( $can_bulk_delete ) : ?>
            <div class="metis-list-sidebar-actions">
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-bulk-delete" disabled>Delete Selected</button>
            </div>
            <?php endif; ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <nav class="metis-list-sidebar-nav" aria-label="Grandy's Stash navigation">
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="metis-list-sidebar-nav-item is-active">Inbox</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/groups/' ); ?>" class="metis-list-sidebar-nav-item">People Groups</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/organizations/' ); ?>" class="metis-list-sidebar-nav-item">Organizations</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-list-sidebar-nav-item">Reports</a>
                    <?php if ( $can_settings ) : ?>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="metis-list-sidebar-nav-item">Settings</a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php },
        'content' => static function () use ( $tickets, $can_manage, $assignees, $can_bulk_delete ) { ?>

<table class="metis-premium-table metis-stash-table <?php echo $can_manage ? 'metis-stash-table--manageable' : 'metis-stash-table--readonly'; ?>">
    <thead>
        <tr class="metis-premium-row metis-premium-header">
            <?php if ( $can_bulk_delete ) : ?>
            <th class="metis-premium-cell metis-stash-select-cell" scope="col">
                <input type="checkbox" id="metis-stash-select-all" aria-label="Select all tickets">
            </th>
            <?php endif; ?>
            <th class="metis-premium-cell" scope="col">Code</th>
            <th class="metis-premium-cell" scope="col">Name</th>
            <th class="metis-premium-cell" scope="col">Type</th>
            <th class="metis-premium-cell" scope="col">Status</th>
            <th class="metis-premium-cell" scope="col">Urgency</th>
            <th class="metis-premium-cell" scope="col">Assigned</th>
            <th class="metis-premium-cell" scope="col">Items</th>
            <th class="metis-premium-cell" scope="col">Date</th>
            <?php if ( $can_manage ) : ?><th class="metis-premium-cell" scope="col">Actions</th><?php endif; ?>
        </tr>
    </thead>
    <tbody id="metis-stash-rows">
        <?php foreach ( $tickets as $t ):
            $type_label = $t['type'] === 'donation' ? 'Donation' : 'Request';
            $status = (string) ($t['status'] ?? 'NEW');
            $raw_name = (string) ($t['submit_name'] ?? 'Unknown');
            $raw_code = (string) ($t['code'] ?? '');
            $raw_email = (string) ($t['submit_email'] ?? '');
            $raw_group_name = (string) ($t['group_name'] ?? '');
            $raw_group_email = (string) ($t['group_email'] ?? '');
            $raw_org_name = (string) ( $t['organization_label'] ?? $t['organization_name'] ?? '' );
            $raw_org_domain = strtolower( trim( (string) ( $t['organization_domain'] ?? '' ) ) );
            $group_id = (int) ( $t['group_id'] ?? 0 );
            $organization_id = (int) ( $t['organization_id'] ?? 0 );
            $category_slugs = strtolower( trim( (string) ( $t['category_slugs'] ?? '' ) ) );
            $category_labels = (string) ( $t['category_labels'] ?? '' );
            $organization_key = $raw_org_domain !== ''
                ? 'domain:' . $raw_org_domain
                : ( $organization_id > 0
                    ? 'org:' . $organization_id
                    : ( trim( $raw_org_name ) !== ''
                        ? 'name:' . strtolower( trim( $raw_org_name ) )
                        : 'independent' ) );
            $group_key = $group_id > 0
                ? 'group:' . $group_id
                : ( trim( $raw_email ) !== ''
                    ? 'email:' . strtolower( trim( $raw_email ) )
                    : 'name:' . strtolower( trim( $raw_name ) ) );
            $name = metis_escape_html( $raw_name );
            $code = metis_escape_html( $raw_code );
            $urgency = ucfirst( (string) ($t['urgency'] ?? 'standard') );
            $assigned = metis_escape_html( (string) ($t['assigned_name'] ?? '—') );
            $item_count = (int) ($t['item_count'] ?? 0);
            $date = ! empty( $t['submitted_at'] ) ? metis_runtime_format_date( (string) $t['submitted_at'] ) : '';
            $search_blob = strtolower( implode( ' ', [
                $raw_code,
                $raw_name,
                $type_label,
                $status,
                (string) ($t['assigned_name'] ?? ''),
                $raw_email,
                (string) ($t['items_summary'] ?? ''),
                $raw_group_name,
                $raw_group_email,
                $raw_org_name,
                $raw_org_domain,
                $category_labels,
                $category_slugs,
            ] ) );
            $ticket_url = metis_grandys_stash_view_url( (string) ( $t['code'] ?? '' ) );
        ?>
        <tr class="metis-premium-row metis-stash-row"
             data-id="<?php echo metis_escape_attr( (string) $t['id'] ); ?>"
             data-ticket-url="<?php echo metis_escape_attr( $ticket_url ); ?>"
             data-status="<?php echo metis_escape_attr( $status ); ?>"
             data-type="<?php echo metis_escape_attr( (string) $t['type'] ); ?>"
             data-assigned="<?php echo metis_escape_attr( (string) ($t['assigned_to'] ?? '') ); ?>"
             data-name="<?php echo metis_escape_attr( strtolower( trim( $raw_name ) ) ); ?>"
             data-code="<?php echo metis_escape_attr( strtolower( trim( $raw_code ) ) ); ?>"
             data-submitted-at="<?php echo metis_escape_attr( (string) ( strtotime( (string) ( $t['submitted_at'] ?? '' ) ) ?: 0 ) ); ?>"
             data-updated-at="<?php echo metis_escape_attr( (string) ( strtotime( (string) ( $t['updated_at'] ?? '' ) ) ?: 0 ) ); ?>"
             data-person-key="<?php echo metis_escape_attr( $group_key ); ?>"
             data-organization-key="<?php echo metis_escape_attr( $organization_key ); ?>"
             data-category-slugs="<?php echo metis_escape_attr( $category_slugs ); ?>"
             data-search="<?php echo metis_escape_attr( $search_blob ); ?>">
            <?php if ( $can_bulk_delete ) : ?>
            <td class="metis-premium-cell metis-stash-select-cell">
                <input type="checkbox" class="metis-stash-ticket-select" value="<?php echo metis_escape_attr( (string) $t['id'] ); ?>" aria-label="Select <?php echo $code; ?>">
            </td>
            <?php endif; ?>
            <td class="metis-premium-cell"><strong><?php echo $code; ?></strong></td>
            <td class="metis-premium-cell"><?php echo $name; ?></td>
            <td class="metis-premium-cell"><span class="metis-stash-type-badge metis-stash-type-<?php echo metis_escape_attr( $t['type'] ); ?>"><?php echo metis_escape_html( $type_label ); ?></span></td>
            <td class="metis-premium-cell"><span class="metis-stash-status-badge metis-stash-status-<?php echo metis_escape_attr( strtolower( $status ) ); ?>"><?php echo metis_escape_html( $status ); ?></span></td>
            <td class="metis-premium-cell"><?php echo metis_escape_html( $urgency ); ?></td>
            <td class="metis-premium-cell"><?php echo $assigned; ?></td>
            <td class="metis-premium-cell"><?php echo $item_count; ?></td>
            <td class="metis-premium-cell"><?php echo metis_escape_html( $date ); ?></td>
            <?php if ( $can_manage ) : ?><td class="metis-premium-cell"><a class="metis-btn-xs" href="<?php echo metis_escape_url( $ticket_url ); ?>" data-ticket-url="<?php echo metis_escape_attr( $ticket_url ); ?>">Review</a></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

        <?php },
    ]); ?>
    <!-- New ticket modal -->
    <?php if ( $can_create ) : ?>
    <div class="metis-stash-modal" id="metis-stash-new-ticket-modal" aria-hidden="true">
        <div class="metis-stash-modal-dialog">
            <div class="metis-stash-modal-head">
                <h2>New Ticket</h2>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-close-modal="metis-stash-new-ticket-modal">Close</button>
            </div>
            <form id="metis-stash-new-ticket-form" class="metis-stash-form" autocomplete="off">
                <div class="metis-stash-form-row">
                    <label><span>Type</span>
                        <select class="metis-select" name="type">
                            <option value="request">Request</option>
                            <option value="donation">Donation</option>
                        </select>
                    </label>
                    <label><span>Urgency</span>
                        <select class="metis-select" name="urgency">
                            <option value="standard">Standard</option>
                            <option value="urgent">Urgent</option>
                            <option value="flexible">Flexible</option>
                        </select>
                    </label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>First name</span><input class="metis-input" type="text" name="first_name" required></label>
                    <label><span>Last name</span><input class="metis-input" type="text" name="last_name"></label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>Email</span><input class="metis-input" type="email" name="email"></label>
                    <label><span>Phone</span><input class="metis-input" type="text" name="phone"></label>
                </div>
                <div class="metis-stash-form-row">
                    <label><span>Source</span>
                        <select class="metis-select" name="source">
                            <option value="staff">Staff entry</option>
                            <option value="phone">Phone</option>
                            <option value="walk-in">Walk-in</option>
                            <option value="email">Email</option>
                            <option value="web">Web form</option>
                        </select>
                    </label>
                    <label><span>Coordination</span>
                        <select class="metis-select" name="pickup_delivery">
                            <option value="">Not set</option>
                            <option value="pickup">Pick up</option>
                            <option value="delivery">Delivery</option>
                            <option value="dropoff">Drop off</option>
                            <option value="discuss">Discuss</option>
                        </select>
                    </label>
                </div>
                <label><span>Items (comma-separated)</span><input class="metis-input" type="text" name="items" placeholder="e.g. Wheelchair, Walker, Shower chair"></label>
                <label><span>Notes</span><textarea class="metis-input" name="notes" rows="3"></textarea></label>
                <div class="metis-stash-form-actions">
                    <button type="submit" class="metis-btn">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
