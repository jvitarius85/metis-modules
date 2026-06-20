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
$can_assign = function_exists( 'metis_grandys_stash_can_assign' ) && metis_grandys_stash_can_assign();
$can_comment = function_exists( 'metis_grandys_stash_can_comment' ) && metis_grandys_stash_can_comment();
$can_reply = function_exists( 'metis_grandys_stash_can_reply' ) && metis_grandys_stash_can_reply();
$can_inventory = function_exists( 'metis_grandys_stash_can_inventory' ) && metis_grandys_stash_can_inventory();
$can_settings = function_exists( 'metis_grandys_stash_can_settings' ) && metis_grandys_stash_can_settings();
$can_delete = function_exists( 'metis_grandys_stash_can_delete' ) && metis_grandys_stash_can_delete();
$ticket_detail = \Metis\Modules\GrandyStash\GrandyStashRepository::getTicketDetailData( $ticket_id );
$ticket_detail = is_array( $ticket_detail ) ? $ticket_detail : [];
$ticket_items = is_array( $ticket_detail['items'] ?? null ) ? $ticket_detail['items'] : [];
$ticket_messages = is_array( $ticket_detail['messages'] ?? null ) ? $ticket_detail['messages'] : [];
$ticket_notes = is_array( $ticket_detail['notes'] ?? null ) ? $ticket_detail['notes'] : [];
$ticket_activity = is_array( $ticket_detail['activity'] ?? null ) ? $ticket_detail['activity'] : [];
$organization_group = is_array( $ticket_detail['organization'] ?? null ) ? $ticket_detail['organization'] : null;
$person_group = is_array( $ticket_detail['group'] ?? null ) ? $ticket_detail['group'] : null;
$current_ticket_code = (string) ( $ticket['code'] ?? '' );

$labelize = static function ( $value ): string {
    $text = trim( (string) $value );
    if ( $text === '' ) {
        return '';
    }

    $text = str_replace( [ '_', '-' ], ' ', strtolower( $text ) );
    $parts = preg_split( '/\s+/', $text ) ?: [];
    $parts = array_map( static fn ( $part ) => ucfirst( $part ), $parts );
    return implode( ' ', $parts );
};

$short_date = static function ( $value ): string {
    $text = trim( (string) $value );
    if ( $text === '' ) {
        return '';
    }

    try {
        return ( new DateTimeImmutable( $text ) )->format( 'm/d/y' );
    } catch ( Throwable ) {
        return $text;
    }
};

$render_kv = static function ( string $label, $value ): string {
    $text = trim( (string) $value );
    if ( $text === '' ) {
        return '';
    }

    return '<div class="metis-stash-detail-item"><div class="metis-stash-detail-label">' . metis_escape_html( $label ) . '</div><div class="metis-stash-detail-value">' . metis_escape_html( $text ) . '</div></div>';
};

$render_group_card = static function ( array $config ) use ( $render_kv, $labelize, $short_date, $current_ticket_code, $can_assign ): string {
    $kind = (string) ( $config['kind'] ?? 'group' );
    $title = trim( (string) ( $config['title'] ?? 'Group' ) );
    $code = trim( (string) ( $config['code'] ?? '' ) );
    $tickets = is_array( $config['tickets'] ?? null ) ? $config['tickets'] : [];
    $count = (int) ( $config['count'] ?? count( $tickets ) );
    $fields = is_array( $config['fields'] ?? null ) ? $config['fields'] : [];

    $links = '';
    foreach ( $tickets as $linked_ticket ) {
        $ticket_code = trim( (string) ( $linked_ticket['code'] ?? '' ) );
        $current = $ticket_code === $current_ticket_code ? ' is-current' : '';
        $url = metis_grandys_stash_view_url( $ticket_code );
        $links .= '<a class="metis-stash-linked-ticket' . $current . '" href="' . metis_escape_url( $url ) . '" data-ticket-url="' . metis_escape_attr( $url ) . '">';
        $links .= '<strong>' . metis_escape_html( $ticket_code ) . '</strong>';
        $links .= '<span>' . metis_escape_html( (string) ( $linked_ticket['submit_name'] ?? '' ) ) . '</span>';
        $links .= '<span class="metis-muted">' . metis_escape_html( $labelize( $linked_ticket['type'] ?? 'request' ) ) . ' · ' . metis_escape_html( (string) ( $linked_ticket['status'] ?? 'NEW' ) ) . ' · ' . metis_escape_html( $short_date( $linked_ticket['submitted_at'] ?? '' ) ) . '</span>';
        $links .= '</a>';
    }

    $footer = '';
    if ( $kind === 'group' && $can_assign ) {
        $footer = '<div style="margin-top:8px;"><button class="metis-btn metis-btn-xs metis-btn-ghost" type="button" data-unlink-group="1">Unlink from group</button></div>';
    }

    return '<details class="metis-stash-group-card" open>'
        . '<summary><strong>' . metis_escape_html( $title ) . '</strong><span class="metis-muted">' . metis_escape_html( (string) $count ) . ' tickets</span></summary>'
        . '<div class="metis-stash-group-card-body">'
        . ( $code !== '' ? '<div class="metis-muted" style="margin-bottom:8px;">' . metis_escape_html( $code ) . '</div>' : '' )
        . '<div class="metis-stash-detail-grid">' . implode( '', array_filter( $fields ) ) . '</div>'
        . '<div class="metis-stash-linked-tickets">' . $links . '</div>'
        . $footer
        . '</div></details>';
};

$default_reply_subject = '[%s] Grandy\'s Stash Update';
$default_reply_subject = sprintf( $default_reply_subject, $current_ticket_code !== '' ? $current_ticket_code : 'GST' );
metis_set_page_title( trim( (string) ( $ticket['code'] ?? '' ) ) . ( $submit_name !== '' ? ' - ' . $submit_name : '' ) );
?>

<div class="metis-stash-app metis-stash-ticket-page"
     data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>"
     data-can-assign="<?php echo metis_escape_attr( $can_assign ? '1' : '0' ); ?>"
     data-can-comment="<?php echo metis_escape_attr( $can_comment ? '1' : '0' ); ?>"
     data-can-reply="<?php echo metis_escape_attr( $can_reply ? '1' : '0' ); ?>"
     data-can-inventory="<?php echo metis_escape_attr( $can_inventory ? '1' : '0' ); ?>"
     data-can-delete="<?php echo metis_escape_attr( $can_delete ? '1' : '0' ); ?>"
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

    <?php metis_render_sidebar_layout([
        'class' => 'metis-stash-layout metis-stash-ticket-shell',
        'sidebar' => static function () use ( $can_settings ) { ?>
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
        'content' => static function () use ( $can_assign, $can_comment, $can_reply, $can_inventory, $ticket_id, $assignees, $can_delete, $ticket, $ticket_items, $ticket_messages, $ticket_notes, $ticket_activity, $organization_group, $person_group, $render_kv, $render_group_card, $labelize, $short_date, $default_reply_subject ) { ?>
    <section class="metis-stash-ticket-layout">
        <div class="metis-stash-ticket-main">
            <div id="metis-stash-ticket-header">
                <div class="metis-stash-detail-grid">
                    <?php
                    echo $render_kv( 'Name', $ticket['submit_name'] ?? '' );
                    echo $render_kv( 'Email', $ticket['submit_email'] ?? '' );
                    echo $render_kv( 'Phone', $ticket['submit_phone'] ?? '' );
                    echo $render_kv( 'Organization', $ticket['organization_name'] ?? '' );
                    echo $render_kv( 'Type', $labelize( $ticket['type'] ?? '' ) );
                    echo $render_kv( 'Status', $ticket['status'] ?? '' );
                    echo $render_kv( 'Urgency', $labelize( $ticket['urgency'] ?? '' ) );
                    echo $render_kv( 'Source', $labelize( $ticket['source'] ?? '' ) );
                    echo $render_kv( 'Coordination', $labelize( $ticket['pickup_delivery'] ?? '' ) );
                    echo $render_kv( 'Submitted', $short_date( $ticket['submitted_at'] ?? '' ) );
                    echo $render_kv( 'Closed', $short_date( $ticket['closed_at'] ?? '' ) );
                    ?>
                </div>
                <?php if ( ! empty( $ticket['submit_notes'] ) ) : ?>
                <div style="margin-top:8px;">
                    <strong class="metis-muted" style="font-size:12px;">Submitter notes</strong>
                    <p style="margin:4px 0 0;"><?php echo metis_escape_html( (string) $ticket['submit_notes'] ); ?></p>
                </div>
                <?php endif; ?>
            </div>
            <div class="metis-stash-ticket-section">
                <h3>Items</h3>
                <div id="metis-stash-ticket-items">
                    <?php if ( count( $ticket_items ) === 0 ) : ?>
                    <div class="metis-muted">No line items.</div>
                    <?php else : ?>
                    <table class="metis-premium-table">
                        <thead>
                            <tr class="metis-premium-row metis-premium-header">
                                <th class="metis-premium-cell" scope="col">Item</th>
                                <th class="metis-premium-cell" scope="col">Category</th>
                                <th class="metis-premium-cell" scope="col">Qty</th>
                                <th class="metis-premium-cell" scope="col">Status</th>
                                <?php if ( $can_inventory ) : ?>
                                <th class="metis-premium-cell" scope="col">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $ticket_items as $item ) : ?>
                            <tr class="metis-premium-row">
                                <td class="metis-premium-cell">
                                    <strong><?php echo metis_escape_html( (string) ( $item['item_name'] ?? $item['category'] ?? 'Other' ) ); ?></strong>
                                    <?php if ( ! empty( $item['condition_status'] ) ) : ?>
                                    <div class="metis-muted"><?php echo metis_escape_html( $labelize( $item['condition_status'] ) ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $item['category'] ?? 'Other' ) ); ?></td>
                                <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( (int) ( $item['quantity'] ?? 1 ) ) ); ?></td>
                                <td class="metis-premium-cell"><span class="metis-stash-status-badge metis-stash-status-<?php echo metis_escape_attr( strtolower( (string) ( $item['status'] ?? 'pending' ) ) ); ?>"><?php echo metis_escape_html( (string) ( $item['status'] ?? 'pending' ) ); ?></span></td>
                                <?php if ( $can_inventory ) : ?>
                                <td class="metis-premium-cell">
                                    <div class="metis-stash-item-actions">
                                        <?php if ( ( $item['status'] ?? '' ) !== 'available' ) : ?><button class="metis-btn-xs metis-btn-ghost" type="button" data-item-id="<?php echo metis_escape_attr( (string) (int) ( $item['id'] ?? 0 ) ); ?>" data-item-action="available">Available</button><?php endif; ?>
                                        <?php if ( ( $item['status'] ?? '' ) !== 'fulfilled' ) : ?><button class="metis-btn-xs metis-btn-ghost" type="button" data-item-id="<?php echo metis_escape_attr( (string) (int) ( $item['id'] ?? 0 ) ); ?>" data-item-action="fulfilled">Fulfilled</button><?php endif; ?>
                                        <?php if ( ( $item['status'] ?? '' ) !== 'unavailable' ) : ?><button class="metis-btn-xs metis-btn-ghost" type="button" data-item-id="<?php echo metis_escape_attr( (string) (int) ( $item['id'] ?? 0 ) ); ?>" data-item-action="unavailable">Unavailable</button><?php endif; ?>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
            <div class="metis-stash-ticket-section">
                <h3>Conversation</h3>
                <div id="metis-stash-ticket-conversation">
                    <?php if ( count( $ticket_messages ) === 0 ) : ?>
                    <div class="metis-muted">No email conversation yet.</div>
                    <?php else : ?>
                        <?php foreach ( $ticket_messages as $message ) :
                            $direction = (string) ( $message['direction'] ?? 'inbound' );
                            $status = (string) ( $message['delivery_status'] ?? ( $direction === 'outbound' ? 'sent' : 'received' ) );
                            $author = (string) ( $message['author_label'] ?? $message['sender_email'] ?? 'System' );
                            $recipient = (string) ( $message['recipient_email'] ?? '' );
                            $when = $short_date( $message['timeline_at'] ?? $message['message_at'] ?? $message['sent_at'] ?? $message['received_at'] ?? $message['created_at'] ?? '' );
                            $attachments = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : [];
                        ?>
                        <article class="metis-stash-conversation-entry metis-stash-conversation-<?php echo metis_escape_attr( $direction ); ?>">
                            <div class="metis-stash-conversation-head">
                                <div class="metis-stash-conversation-meta">
                                    <span class="metis-stash-status-badge"><?php echo metis_escape_html( $direction === 'outbound' ? 'Staff Reply' : 'Public Reply' ); ?></span>
                                    <strong><?php echo metis_escape_html( $author ); ?></strong>
                                    <?php if ( $recipient !== '' ) : ?><span class="metis-muted">to <?php echo metis_escape_html( $recipient ); ?></span><?php endif; ?>
                                </div>
                                <div class="metis-muted"><?php echo metis_escape_html( $when ); ?></div>
                            </div>
                            <div class="metis-stash-conversation-subject"><?php echo metis_escape_html( (string) ( $message['subject'] ?? '(No subject)' ) ); ?></div>
                            <div class="metis-stash-conversation-body"><?php echo nl2br( metis_escape_html( (string) ( $message['body_text_display'] ?? '' ) ) ); ?></div>
                            <?php if ( count( $attachments ) > 0 ) : ?>
                            <div class="metis-stash-conversation-attachments">
                                <?php foreach ( $attachments as $attachment ) :
                                    $url = (string) ( $attachment['download_url'] ?? $attachment['media_url'] ?? '' );
                                    if ( $url === '' ) {
                                        continue;
                                    }
                                ?>
                                <a class="metis-stash-conversation-attachment" href="<?php echo metis_escape_url( $url ); ?>" target="_blank" rel="noopener"><?php echo metis_escape_html( (string) ( $attachment['file_name'] ?? $attachment['filename'] ?? 'Attachment' ) ); ?></a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="metis-stash-conversation-foot"><span class="metis-muted">Status: <?php echo metis_escape_html( $labelize( $status ) ); ?></span></div>
                        </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if ( $can_reply ) : ?>
                <div class="metis-stash-reply-form">
                    <label><span>Reply subject</span><input class="metis-input" type="text" id="metis-stash-reply-subject" value="<?php echo metis_escape_attr( $default_reply_subject ); ?>" placeholder="[GST-000123] Grandy's Stash Update"></label>
                    <label><span>Reply message</span><textarea class="metis-input" id="metis-stash-reply-input" placeholder="Type your email reply..." rows="5"></textarea></label>
                    <div class="metis-stash-form-actions">
                        <button type="button" class="metis-btn metis-btn-xs" id="metis-stash-reply-submit">Send reply</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="metis-stash-ticket-section">
                <h3>Notes</h3>
                <div id="metis-stash-ticket-notes">
                    <?php if ( count( $ticket_notes ) === 0 ) : ?>
                    <div class="metis-muted">No notes yet.</div>
                    <?php else : ?>
                        <?php foreach ( $ticket_notes as $note ) : ?>
                        <div class="metis-stash-note-entry">
                            <div class="metis-muted" style="font-size:12px;"><?php echo metis_escape_html( (string) ( $note['author_name'] ?? 'System' ) ); ?> · <?php echo metis_escape_html( $short_date( $note['created_at'] ?? '' ) ); ?></div>
                            <p style="margin:4px 0 0;"><?php echo metis_escape_html( (string) ( $note['content'] ?? '' ) ); ?></p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if ( $can_comment ) : ?>
                <div class="metis-stash-note-form">
                    <textarea class="metis-input" id="metis-stash-note-input" placeholder="Add a note..." rows="2"></textarea>
                    <button type="button" class="metis-btn metis-btn-xs" id="metis-stash-note-submit">Add note</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="metis-stash-ticket-section">
                <h3>Activity</h3>
                <div id="metis-stash-ticket-activity">
                    <?php if ( count( $ticket_activity ) === 0 ) : ?>
                    <div class="metis-muted">No activity.</div>
                    <?php else : ?>
                        <?php foreach ( $ticket_activity as $entry ) : ?>
                        <div class="metis-stash-activity-entry"><span class="metis-stash-status-badge"><?php echo metis_escape_html( $labelize( $entry['action'] ?? 'activity' ) ); ?></span><span class="metis-muted"><?php echo metis_escape_html( (string) ( $entry['detail'] ?? '' ) ); ?><?php echo ! empty( $entry['detail'] ) ? ' · ' : ''; ?><?php echo metis_escape_html( (string) ( $entry['author_name'] ?? 'System' ) ); ?> · <?php echo metis_escape_html( $short_date( $entry['created_at'] ?? '' ) ); ?></span></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <aside class="metis-stash-ticket-sidebar">
            <div class="metis-stash-ticket-section metis-stash-ticket-sidecard" id="metis-stash-ticket-organization-section"<?php echo is_array( $organization_group ) ? '' : ' style="display:none;"'; ?>>
                <h3>Organization Group</h3>
                <div id="metis-stash-ticket-organization">
                    <?php
                    if ( is_array( $organization_group ) ) {
                        echo $render_group_card( [
                            'kind' => 'organization',
                            'title' => $organization_group['name'] ?? $organization_group['domain'] ?? 'Organization',
                            'count' => $organization_group['ticket_count'] ?? count( $organization_group['tickets'] ?? [] ),
                            'code' => $organization_group['code'] ?? '',
                            'fields' => [
                                $render_kv( 'Domain', $organization_group['domain'] ?? '' ),
                                $render_kv( 'Tickets', (string) ( $organization_group['ticket_count'] ?? count( $organization_group['tickets'] ?? [] ) ) ),
                            ],
                            'tickets' => $organization_group['tickets'] ?? [],
                        ] );
                    }
                    ?>
                </div>
            </div>
            <div class="metis-stash-ticket-section metis-stash-ticket-sidecard" id="metis-stash-ticket-group-section"<?php echo is_array( $person_group ) ? '' : ' style="display:none;"'; ?>>
                <h3>Person Group</h3>
                <div id="metis-stash-ticket-group">
                    <?php
                    if ( is_array( $person_group ) ) {
                        echo $render_group_card( [
                            'kind' => 'group',
                            'title' => $person_group['name'] ?? 'Person Group',
                            'count' => $person_group['ticket_count'] ?? count( $person_group['tickets'] ?? [] ),
                            'code' => $person_group['code'] ?? '',
                            'fields' => [
                                $render_kv( 'Email', $person_group['email'] ?? '' ),
                                $render_kv( 'Phone', $person_group['phone'] ?? '' ),
                                $render_kv( 'Tickets', (string) ( $person_group['ticket_count'] ?? count( $person_group['tickets'] ?? [] ) ) ),
                            ],
                            'tickets' => $person_group['tickets'] ?? [],
                        ] );
                    }
                    ?>
                </div>
            </div>
            <?php if ( $can_assign ) : ?>
            <div class="metis-stash-ticket-actions metis-stash-ticket-sidecard">
                <h3>Ticket Actions</h3>
                <form id="metis-stash-ticket-form" class="metis-stash-form" autocomplete="off">
                    <input type="hidden" name="id" value="<?php echo metis_escape_attr( (string) $ticket_id ); ?>">
                    <div class="metis-stash-form-row">
                        <label><span>Status</span>
                            <select class="metis-select" name="status">
                                <option value="NEW"<?php echo ( (string) ( $ticket['status'] ?? '' ) === 'NEW' ) ? ' selected' : ''; ?>>New</option>
                                <option value="REVIEWING"<?php echo ( (string) ( $ticket['status'] ?? '' ) === 'REVIEWING' ) ? ' selected' : ''; ?>>Reviewing</option>
                                <option value="WAITLIST"<?php echo ( (string) ( $ticket['status'] ?? '' ) === 'WAITLIST' ) ? ' selected' : ''; ?>>Waitlist</option>
                                <option value="READY"<?php echo ( (string) ( $ticket['status'] ?? '' ) === 'READY' ) ? ' selected' : ''; ?>>Ready</option>
                                <option value="COMPLETED"<?php echo ( (string) ( $ticket['status'] ?? '' ) === 'COMPLETED' ) ? ' selected' : ''; ?>>Completed</option>
                                <option value="CLOSED"<?php echo ( (string) ( $ticket['status'] ?? '' ) === 'CLOSED' ) ? ' selected' : ''; ?>>Closed</option>
                            </select>
                        </label>
                    </div>
                    <div class="metis-stash-form-row">
                        <label><span>Assigned to</span>
                            <select class="metis-select" name="assigned_to" id="metis-stash-ticket-assignee">
                                <option value="">Unassigned</option>
                                <?php foreach ( $assignees as $a ) : ?>
                                <option value="<?php echo metis_escape_attr( (string) $a['id'] ); ?>"<?php echo ( (int) ( $ticket['assigned_to'] ?? 0 ) === (int) ( $a['id'] ?? 0 ) ) ? ' selected' : ''; ?>><?php echo metis_escape_html( (string) $a['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="metis-stash-form-row">
                        <label><span>Urgency</span>
                            <select class="metis-select" name="urgency">
                                <option value="urgent"<?php echo ( (string) ( $ticket['urgency'] ?? '' ) === 'urgent' ) ? ' selected' : ''; ?>>Urgent</option>
                                <option value="standard"<?php echo ( (string) ( $ticket['urgency'] ?? '' ) === 'standard' ) ? ' selected' : ''; ?>>Standard</option>
                                <option value="flexible"<?php echo ( (string) ( $ticket['urgency'] ?? '' ) === 'flexible' ) ? ' selected' : ''; ?>>Flexible</option>
                            </select>
                        </label>
                    </div>
                    <div class="metis-stash-form-actions">
                        <button type="submit" class="metis-btn">Save ticket</button>
                    </div>
                    <?php if ( $can_delete ) : ?>
                    <div class="metis-stash-form-actions">
                        <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-ticket-delete">Delete ticket</button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        </aside>
    </section>
        <?php },
    ]); ?>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
