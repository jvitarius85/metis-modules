<?php
if (!defined('ABSPATH')) exit;

if (!metis_board_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view board.</div>';
    return;
}

metis_board_ensure_schema();
global $wpdb;

$can_manage = metis_board_can_manage();
$meetings_table = Metis_Tables::get('board_meetings');
$committees_table = Metis_Tables::get('board_committees');
$decisions_table = Metis_Tables::get('board_decisions');
$actions_table = Metis_Tables::get('board_action_items');
$attendance_table = Metis_Tables::get('board_attendance');
$compliance_table = Metis_Tables::get('board_compliance');
$announcements_table = Metis_Tables::get('board_announcements');
$documents_table = Metis_Tables::get('board_documents');
$people_table = Metis_Tables::get('people');

$rows = $wpdb->get_results(
    "SELECT m.id, m.meeting_code, m.title, m.meeting_date, m.meeting_type, m.location, m.status, m.updated_at,
            m.google_calendar_event_id, m.google_drive_folder_id,
            c.name AS committee_name,
            (SELECT COUNT(*) FROM {$actions_table} a WHERE a.meeting_id = m.id AND a.status <> 'done') AS open_actions,
            (SELECT COUNT(*) FROM {$decisions_table} d WHERE d.meeting_id = m.id) AS decisions_count
     FROM {$meetings_table} m
     LEFT JOIN {$committees_table} c ON c.id = m.committee_id
     ORDER BY m.meeting_date DESC, m.id DESC
     LIMIT 300",
    ARRAY_A
) ?: [];

$committees = $wpdb->get_results(
    "SELECT c.id, c.committee_code, c.name, c.description, c.chair_person_id, c.is_active, c.updated_at,
            p.display_name AS chair_name,
            (SELECT COUNT(*) FROM {$meetings_table} m WHERE m.committee_id = c.id) AS meeting_count
     FROM {$committees_table} c
     LEFT JOIN {$people_table} p ON p.id = c.chair_person_id
     ORDER BY c.name ASC",
    ARRAY_A
) ?: [];

$open_actions = $wpdb->get_results(
    "SELECT a.id, a.action_code, a.title, a.status, a.priority, a.due_date,
            m.meeting_code, m.title AS meeting_title,
            p.display_name AS owner_name
     FROM {$actions_table} a
     LEFT JOIN {$meetings_table} m ON m.id = a.meeting_id
     LEFT JOIN {$people_table} p ON p.id = a.owner_person_id
     WHERE a.status <> 'done'
     ORDER BY (a.due_date IS NULL), a.due_date ASC, a.updated_at DESC
     LIMIT 12",
    ARRAY_A
) ?: [];

$recent_announcements = $wpdb->get_results(
    "SELECT id, announcement_code, title, status, publish_at, updated_at
     FROM {$announcements_table}
     ORDER BY updated_at DESC
     LIMIT 10",
    ARRAY_A
) ?: [];

$board_people = $wpdb->get_results(
    "SELECT id, pid, display_name, email
     FROM {$people_table}
     WHERE status = 'active' AND (is_board = 1 OR is_staff = 1)
     ORDER BY display_name ASC",
    ARRAY_A
) ?: [];

$meeting_options = $wpdb->get_results(
    "SELECT id, meeting_code, title, meeting_date
     FROM {$meetings_table}
     ORDER BY meeting_date DESC
     LIMIT 500",
    ARRAY_A
) ?: [];

$now = current_time('mysql');
$total_meetings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meetings_table}");
$upcoming_meetings = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$meetings_table} WHERE meeting_date >= %s AND status IN ('scheduled','draft')", $now));
$open_action_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$actions_table} WHERE status <> 'done'");
$decision_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$decisions_table}");
$committee_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$committees_table} WHERE is_active = 1");
$compliance_overdue = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$compliance_table} WHERE status <> 'completed' AND due_date IS NOT NULL AND due_date < %s", current_time('Y-m-d')));
$calendar_linked = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meetings_table} WHERE google_calendar_event_id IS NOT NULL AND google_calendar_event_id <> ''");
$drive_linked = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$meetings_table} WHERE google_drive_folder_id IS NOT NULL AND google_drive_folder_id <> ''");
$document_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$documents_table} WHERE status = 'active'");
?>

<div class="metis-board" data-can-manage="<?php echo esc_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="mw-page-title">Board</h1>
    <p class="mw-subtitle">Governance hub for meetings, agendas, minutes, votes, action items, committees, and compliance.</p>

    <div class="metis-board-stats">
        <div class="metis-board-stat"><div class="metis-board-stat-label">Total Meetings</div><div class="metis-board-stat-value"><?php echo esc_html(number_format_i18n($total_meetings)); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Upcoming</div><div class="metis-board-stat-value"><?php echo esc_html(number_format_i18n($upcoming_meetings)); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Open Actions</div><div class="metis-board-stat-value"><?php echo esc_html(number_format_i18n($open_action_count)); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Decisions</div><div class="metis-board-stat-value"><?php echo esc_html(number_format_i18n($decision_count)); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Committees</div><div class="metis-board-stat-value"><?php echo esc_html(number_format_i18n($committee_count)); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Compliance Overdue</div><div class="metis-board-stat-value"><?php echo esc_html(number_format_i18n($compliance_overdue)); ?></div></div>
    </div>

    <section class="mw-premium-wrap metis-board-integrations">
        <h3 class="metis-people-section-title" style="margin:0 0 8px;">Workspace Integrations</h3>
        <div class="metis-board-integration-grid">
            <div class="metis-board-integration-item"><strong>Calendar Linked Meetings</strong><span><?php echo esc_html(number_format_i18n($calendar_linked)); ?></span></div>
            <div class="metis-board-integration-item"><strong>Drive Linked Meetings</strong><span><?php echo esc_html(number_format_i18n($drive_linked)); ?></span></div>
            <div class="metis-board-integration-item"><strong>Board Documents</strong><span><?php echo esc_html(number_format_i18n($document_count)); ?></span></div>
        </div>
        <p class="mw-muted" style="margin:8px 0 0;">Meetings store Google Calendar event IDs and Shared Drive folder IDs so Calendar and Drive modules can sync bi-directionally.</p>
    </section>

    <div id="metis-board-alert" class="mw-alert" style="display:none;"></div>

    <div class="mw-list-layout">

    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Search</div>
            <input id="metis-board-search" class="mw-input" type="text" placeholder="Code, title, committee, type…">
        </div>
        <?php if ($can_manage) : ?>
        <div class="mw-list-sidebar-actions">
            <button id="metis-board-new-meeting" class="mw-btn mw-btn-xs" type="button">Add Meeting</button>
            <button id="metis-board-new-committee" class="mw-btn mw-btn-xs mw-btn-ghost" type="button">Add Committee</button>
            <button id="metis-board-new-decision" class="mw-btn mw-btn-xs mw-btn-ghost" type="button">Record Decision</button>
            <button id="metis-board-new-action" class="mw-btn mw-btn-xs mw-btn-ghost" type="button">Add Action Item</button>
            <button id="metis-board-new-announcement" class="mw-btn mw-btn-xs mw-btn-ghost" type="button">Announcement</button>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="mw-list-content">
    <div class="mw-premium-table metis-board-table metis-board-table-main">
        <div class="mw-premium-row mw-premium-header">
            <div class="mw-premium-cell mw-sortable" data-sort-key="title">Meeting ▾</div>
            <div class="mw-premium-cell mw-sortable" data-sort-key="committee">Committee ▾</div>
            <div class="mw-premium-cell mw-sortable" data-sort-key="date">Date ▾</div>
            <div class="mw-premium-cell">Type</div>
            <div class="mw-premium-cell">Status</div>
            <div class="mw-premium-cell">Actions Open</div>
            <?php if ($can_manage) : ?><div class="mw-premium-cell">Manage</div><?php endif; ?>
        </div>
        <div id="metis-board-meeting-rows">
            <?php foreach ($rows as $row) :
                $meeting_code = (string) ($row['meeting_code'] ?? '');
                $row_href = $meeting_code !== '' ? metis_board_meeting_url($meeting_code) : '';
                $search_blob = strtolower(trim(implode(' ', [
                    (string) ($row['meeting_code'] ?? ''),
                    (string) ($row['title'] ?? ''),
                    (string) ($row['committee_name'] ?? ''),
                    (string) ($row['meeting_type'] ?? ''),
                    (string) ($row['location'] ?? ''),
                    (string) ($row['status'] ?? ''),
                ])));
            ?>
            <div class="mw-premium-row metis-board-row <?php echo (string) ($row['status'] ?? '') === 'draft' ? 'metis-board-row-draft' : ''; ?>"
                 data-id="<?php echo esc_attr((string) ((int) ($row['id'] ?? 0))); ?>"
                 data-search="<?php echo esc_attr($search_blob); ?>"
                 data-title-sort="<?php echo esc_attr(strtolower((string) ($row['title'] ?? ''))); ?>"
                 data-committee-sort="<?php echo esc_attr(strtolower((string) ($row['committee_name'] ?? ''))); ?>"
                 data-date-sort="<?php echo esc_attr(strtotime((string) ($row['meeting_date'] ?? '')) ?: 0); ?>"
                 data-href="<?php echo esc_url($row_href); ?>"
                 data-meeting-json="<?php echo esc_attr(metis_json_encode($row)); ?>">
                <div class="mw-premium-cell">
                    <div><strong><?php echo esc_html((string) ($row['title'] ?? '')); ?></strong></div>
                    <div class="mw-muted"><?php echo esc_html($meeting_code); ?></div>
                </div>
                <div class="mw-premium-cell"><?php echo esc_html((string) ($row['committee_name'] ?? '—')); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html(metis_board_format_datetime((string) ($row['meeting_date'] ?? ''))); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html((string) ($row['meeting_type'] ?? 'board')); ?></div>
                <div class="mw-premium-cell"><span class="mw-chip"><?php echo esc_html((string) ($row['status'] ?? 'draft')); ?></span></div>
                <div class="mw-premium-cell"><?php echo esc_html((string) ((int) ($row['open_actions'] ?? 0))); ?></div>
                <?php if ($can_manage) : ?>
                    <div class="mw-premium-cell metis-board-actions-cell">
                        <button type="button" class="mw-btn-xs metis-board-edit-meeting">Edit</button>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No meetings yet.</div></div><?php endif; ?>
        </div>
    </div>

    <div class="mw-pagination">
        <button id="metis-board-prev" type="button" class="mw-btn-xs">Prev</button>
        <span id="metis-board-page" class="mw-muted">Page 1 of 1</span>
        <button id="metis-board-next" type="button" class="mw-btn-xs">Next</button>
    </div>

    </div><!-- /mw-list-content -->
    </div><!-- /mw-list-layout -->

    <div class="metis-board-grid-2">
        <section class="mw-premium-table metis-board-table metis-board-actions-table">
            <div class="mw-premium-header">
                <div class="mw-premium-cell">Open Action</div>
                <div class="mw-premium-cell">Owner</div>
                <div class="mw-premium-cell">Due</div>
                <div class="mw-premium-cell">Status</div>
            </div>
            <?php foreach ($open_actions as $action) : ?>
                <div class="mw-premium-row">
                    <div class="mw-premium-cell"><strong><?php echo esc_html((string) ($action['title'] ?? '')); ?></strong><div class="mw-muted"><?php echo esc_html((string) ($action['meeting_code'] ?? '')); ?></div></div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ($action['owner_name'] ?? 'Unassigned')); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(!empty($action['due_date']) ? metis_date('M j, Y', strtotime((string) $action['due_date'])) : '—'); ?></div>
                    <div class="mw-premium-cell"><span class="mw-chip"><?php echo esc_html((string) ($action['status'] ?? 'open')); ?></span></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($open_actions)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No open action items.</div></div><?php endif; ?>
        </section>

        <section class="mw-premium-table metis-board-table metis-board-committees-table">
            <div class="mw-premium-header">
                <div class="mw-premium-cell">Committee</div>
                <div class="mw-premium-cell">Chair</div>
                <div class="mw-premium-cell">Meetings</div>
                <?php if ($can_manage) : ?><div class="mw-premium-cell">Manage</div><?php endif; ?>
            </div>
            <?php foreach ($committees as $committee) : ?>
                <div class="mw-premium-row" data-committee-json="<?php echo esc_attr(metis_json_encode($committee)); ?>">
                    <div class="mw-premium-cell"><strong><?php echo esc_html((string) ($committee['name'] ?? '')); ?></strong><div class="mw-muted"><?php echo esc_html((string) ($committee['committee_code'] ?? '')); ?></div></div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ($committee['chair_name'] ?? '—')); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ((int) ($committee['meeting_count'] ?? 0))); ?></div>
                    <?php if ($can_manage) : ?><div class="mw-premium-cell"><button type="button" class="mw-btn-xs metis-board-edit-committee">Edit</button></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($committees)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No committees yet.</div></div><?php endif; ?>
        </section>
    </div>

    <section class="mw-premium-table metis-board-table metis-board-announcements-table">
        <div class="mw-premium-header">
            <div class="mw-premium-cell">Announcement</div>
            <div class="mw-premium-cell">Status</div>
            <div class="mw-premium-cell">Publish At</div>
            <div class="mw-premium-cell">Updated</div>
        </div>
        <?php foreach ($recent_announcements as $an) : ?>
            <div class="mw-premium-row">
                <div class="mw-premium-cell"><strong><?php echo esc_html((string) ($an['title'] ?? '')); ?></strong><div class="mw-muted"><?php echo esc_html((string) ($an['announcement_code'] ?? '')); ?></div></div>
                <div class="mw-premium-cell"><span class="mw-chip"><?php echo esc_html((string) ($an['status'] ?? 'draft')); ?></span></div>
                <div class="mw-premium-cell"><?php echo esc_html(!empty($an['publish_at']) ? metis_board_format_datetime((string) $an['publish_at']) : '—'); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html(metis_board_format_datetime((string) ($an['updated_at'] ?? ''))); ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($recent_announcements)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No announcements yet.</div></div><?php endif; ?>
    </section>
</div>

<?php if ($can_manage) : ?>
<div id="metis-board-meeting-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner">
        <h2 class="metis-contacts-modal-title">Meeting</h2>
        <form id="metis-board-meeting-form" class="metis-contact-form">
            <input type="hidden" id="metis-board-meeting-id" value="0">
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-title">Title</label><input id="metis-board-meeting-title" class="mw-input" type="text" maxlength="191" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-committee">Committee</label><select id="metis-board-meeting-committee" class="mw-select"><option value="">Board-wide</option><?php foreach ($committees as $committee) : ?><option value="<?php echo esc_attr((string) ((int) ($committee['id'] ?? 0))); ?>"><?php echo esc_html((string) ($committee['name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-date">Meeting Date</label><input id="metis-board-meeting-date" class="mw-input" type="datetime-local" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-type">Type</label><select id="metis-board-meeting-type" class="mw-select"><option value="board">Board</option><option value="committee">Committee</option><option value="special">Special Session</option></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-location">Location</label><input id="metis-board-meeting-location" class="mw-input" type="text" maxlength="191"></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-status">Status</label><select id="metis-board-meeting-status" class="mw-select"><option value="draft">Draft</option><option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-calendar">Google Calendar Event ID</label><input id="metis-board-meeting-calendar" class="mw-input" type="text" maxlength="191"></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-meeting-drive">Google Drive Folder ID</label><input id="metis-board-meeting-drive" class="mw-input" type="text" maxlength="191"></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-meeting-agenda">Agenda JSON</label><textarea id="metis-board-meeting-agenda" class="mw-input" rows="6" placeholder='[{"section":"Call to Order","items":["Approve prior minutes"]}]'></textarea></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-meeting-minutes">Minutes (HTML)</label><textarea id="metis-board-meeting-minutes" class="mw-input" rows="6"></textarea></div>
            <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="mw-btn">Save Meeting</button></div>
        </form>
    </div>
</div>

<div id="metis-board-committee-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner">
        <h2 class="metis-contacts-modal-title">Committee</h2>
        <form id="metis-board-committee-form" class="metis-contact-form">
            <input type="hidden" id="metis-board-committee-id" value="0">
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-committee-name">Name</label><input id="metis-board-committee-name" class="mw-input" type="text" maxlength="191" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-committee-chair">Chair</label><select id="metis-board-committee-chair" class="mw-select"><option value="">None</option><?php foreach ($board_people as $bp) : ?><option value="<?php echo esc_attr((string) ((int) ($bp['id'] ?? 0))); ?>"><?php echo esc_html((string) ($bp['display_name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-committee-description">Description</label><textarea id="metis-board-committee-description" class="mw-input" rows="5"></textarea></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-committee-active">Status</label><select id="metis-board-committee-active" class="mw-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="mw-btn">Save Committee</button></div>
        </form>
    </div>
</div>

<div id="metis-board-decision-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner">
        <h2 class="metis-contacts-modal-title">Board Decision</h2>
        <form id="metis-board-decision-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-meeting">Meeting</label><select id="metis-board-decision-meeting" class="mw-select" required><option value="">Select meeting</option><?php foreach ($meeting_options as $mo) : ?><option value="<?php echo esc_attr((string) ((int) ($mo['id'] ?? 0))); ?>"><?php echo esc_html((string) ($mo['meeting_code'] ?? '') . ' - ' . (string) ($mo['title'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-title">Title</label><input id="metis-board-decision-title" class="mw-input" type="text" maxlength="191" required></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-decision-text">Decision Text</label><textarea id="metis-board-decision-text" class="mw-input" rows="5"></textarea></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-decision-for">Votes For</label><input id="metis-board-decision-for" class="mw-input" type="number" min="0" value="0"></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-decision-against">Votes Against</label><input id="metis-board-decision-against" class="mw-input" type="number" min="0" value="0"></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-decision-abstain">Abstain</label><input id="metis-board-decision-abstain" class="mw-input" type="number" min="0" value="0"></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-outcome">Outcome</label><select id="metis-board-decision-outcome" class="mw-select"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="tabled">Tabled</option></select></div>
            <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="mw-btn">Save Decision</button></div>
        </form>
    </div>
</div>

<div id="metis-board-action-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner">
        <h2 class="metis-contacts-modal-title">Action Item</h2>
        <form id="metis-board-action-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-action-meeting">Meeting</label><select id="metis-board-action-meeting" class="mw-select"><option value="">None</option><?php foreach ($meeting_options as $mo) : ?><option value="<?php echo esc_attr((string) ((int) ($mo['id'] ?? 0))); ?>"><?php echo esc_html((string) ($mo['meeting_code'] ?? '') . ' - ' . (string) ($mo['title'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-action-owner">Owner</label><select id="metis-board-action-owner" class="mw-select"><option value="">Unassigned</option><?php foreach ($board_people as $bp) : ?><option value="<?php echo esc_attr((string) ((int) ($bp['id'] ?? 0))); ?>"><?php echo esc_html((string) ($bp['display_name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-action-title">Title</label><input id="metis-board-action-title" class="mw-input" type="text" maxlength="191" required></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-action-description">Description</label><textarea id="metis-board-action-description" class="mw-input" rows="5"></textarea></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-action-due">Due Date</label><input id="metis-board-action-due" class="mw-input" type="date"></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-action-priority">Priority</label><select id="metis-board-action-priority" class="mw-select"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="critical">Critical</option></select></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-action-status">Status</label><select id="metis-board-action-status" class="mw-select"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="blocked">Blocked</option><option value="done">Done</option></select></div>
            <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="mw-btn">Save Action</button></div>
        </form>
    </div>
</div>

<div id="metis-board-announcement-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner">
        <h2 class="metis-contacts-modal-title">Announcement</h2>
        <form id="metis-board-announcement-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-announcement-title">Title</label><input id="metis-board-announcement-title" class="mw-input" type="text" maxlength="191" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-announcement-status">Status</label><select id="metis-board-announcement-status" class="mw-select"><option value="draft">Draft</option><option value="published">Published</option></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-announcement-publish">Publish At</label><input id="metis-board-announcement-publish" class="mw-input" type="datetime-local"></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-announcement-body">Body (HTML)</label><textarea id="metis-board-announcement-body" class="mw-input" rows="6"></textarea></div>
            <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="mw-btn">Save Announcement</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
