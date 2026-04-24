<?php
if (!defined('METIS_ROOT')) exit;

require_once dirname( __DIR__ ) . '/includes/dashboard_data.php';

if (!metis_board_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view board.</div>';
    return;
}

$db = metis_db();

$can_manage = metis_board_can_manage();
$current_person_id = metis_board_current_person_id();
$meetings_table = Metis_Tables::get('board_meetings');
$committees_table = Metis_Tables::get('board_committees');
$decisions_table = Metis_Tables::get('board_decisions');
$actions_table = Metis_Tables::get('board_action_items');
$attendance_table = Metis_Tables::get('board_attendance');
$compliance_table = Metis_Tables::get('board_compliance');
$announcements_table = Metis_Tables::get('board_announcements');
$documents_table = Metis_Tables::get('board_documents');
$people_table = Metis_Tables::get('people');
$newsletter_lists_table = Metis_Tables::get('newsletter_lists');
$has_newsletter_lists = $can_manage && function_exists('metis_board_table_exists') && metis_board_table_exists($newsletter_lists_table);

$rows = metis_board_fetch_dashboard_meetings(300);

$committees = metis_board_fetch_dashboard_committees($can_manage);

$newsletter_lists = [];
if ($can_manage && $has_newsletter_lists) {
    $newsletter_lists = metis_board_fetch_dashboard_newsletter_lists();
}

$open_actions = metis_board_fetch_dashboard_open_actions(12);

$recent_announcements = metis_board_fetch_dashboard_announcements(10);

$board_people = [];
if ($can_manage) {
    $board_people = metis_board_fetch_dashboard_people_options();
}

$meeting_options = [];
if ($can_manage) {
    $meeting_options = metis_board_fetch_dashboard_meeting_options(500);
}

$kpis = metis_board_fetch_dashboard_kpis();
$total_meetings = (int) ( $kpis['total_meetings'] ?? 0 );
$upcoming_meetings = (int) ( $kpis['upcoming_meetings'] ?? 0 );
$open_action_count = (int) ( $kpis['open_action_count'] ?? 0 );
$committee_count = (int) ( $kpis['committee_count'] ?? 0 );
$compliance_overdue = (int) ( $kpis['compliance_overdue'] ?? 0 );
$decision_count = (int) ( $kpis['decision_count'] ?? 0 );
?>

<div class="metis-board"
    data-can-manage="<?php echo metis_escape_attr($can_manage ? '1' : '0'); ?>"
    data-current-person-id="<?php echo metis_escape_attr((string) $current_person_id); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Board' ) ); ?></h1>
    <p class="metis-subtitle">Meetings and governance actions.</p>

    <div class="kpi-card-grid">
        <div class="kpi-card"><div class="kpi-label">Total Meetings</div><div class="kpi-value"><?php echo metis_escape_html(metis_number_format($total_meetings)); ?></div></div>
        <div class="kpi-card"><div class="kpi-label">Upcoming</div><div class="kpi-value"><?php echo metis_escape_html(metis_number_format($upcoming_meetings)); ?></div></div>
        <div class="kpi-card"><div class="kpi-label">Open Actions</div><div class="kpi-value"><?php echo metis_escape_html(metis_number_format($open_action_count)); ?></div></div>
        <div class="kpi-card"><div class="kpi-label">Committees</div><div class="kpi-value"><?php echo metis_escape_html(metis_number_format($committee_count)); ?></div></div>
        <div class="kpi-card"><div class="kpi-label">Compliance Overdue</div><div class="kpi-value"><?php echo metis_escape_html(metis_number_format($compliance_overdue)); ?></div></div>
    </div>

    <div id="metis-board-alert" class="metis-alert" style="display:none;"></div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ($can_manage) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <input id="metis-board-search" class="metis-input" type="text" placeholder="Code, title, committee, type…">
            </div>
            <?php if ($can_manage) : ?>
            <div class="metis-list-sidebar-actions">
                <button id="metis-board-new-meeting" class="metis-btn metis-btn-xs" type="button">Add Meeting</button>
                <div class="metis-board-sidebar-group" aria-label="More board actions">
                    <button id="metis-board-new-committee" class="metis-btn metis-btn-xs metis-btn-ghost" type="button">Add Committee</button>
                    <button id="metis-board-new-decision" class="metis-btn metis-btn-xs metis-btn-ghost" type="button">Record Decision</button>
                    <button id="metis-board-new-action" class="metis-btn metis-btn-xs metis-btn-ghost" type="button">Add Action Item</button>
                    <button id="metis-board-new-announcement" class="metis-btn metis-btn-xs metis-btn-ghost" type="button">Announcement</button>
                </div>
            </div>
            <?php endif; ?>
        <?php },
        'content' => static function () use ($can_manage, $rows) { ?>
    <table class="metis-premium-table metis-board-table metis-board-table-main <?php echo $can_manage ? 'metis-board-table-main--manageable' : 'metis-board-table-main--readonly'; ?>">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell metis-sortable" scope="col" data-sort-key="title">Meeting</th>
                <th class="metis-premium-cell metis-sortable" scope="col" data-sort-key="committee">Committee</th>
                <th class="metis-premium-cell metis-sortable metis-sort-active metis-sort-desc" scope="col" data-sort-key="date">Date</th>
                <th class="metis-premium-cell" scope="col">Type</th>
                <th class="metis-premium-cell" scope="col">Status</th>
                <th class="metis-premium-cell" scope="col">Open Actions</th>
                <?php if ($can_manage) : ?><th class="metis-premium-cell" scope="col">Manage</th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="metis-board-meeting-rows">
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
            <tr class="metis-premium-row metis-board-row <?php echo (string) ($row['status'] ?? '') === 'draft' ? 'metis-board-row-draft' : ''; ?>"
                 data-id="<?php echo metis_escape_attr((string) ((int) ($row['id'] ?? 0))); ?>"
                 data-search="<?php echo metis_escape_attr($search_blob); ?>"
                 data-title-sort="<?php echo metis_escape_attr(strtolower((string) ($row['title'] ?? ''))); ?>"
                 data-committee-sort="<?php echo metis_escape_attr(strtolower((string) ($row['committee_name'] ?? ''))); ?>"
                 data-date-sort="<?php echo metis_escape_attr(strtotime((string) ($row['meeting_date'] ?? '')) ?: 0); ?>"
                 data-href="<?php echo metis_escape_url($row_href); ?>"
                 data-meeting-json="<?php echo metis_escape_attr(metis_json_encode($row)); ?>">
                <td class="metis-premium-cell">
                    <div><strong><?php echo metis_escape_html((string) ($row['title'] ?? '')); ?></strong></div>
                    <div class="metis-muted"><?php echo metis_escape_html($meeting_code); ?></div>
                </td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($row['committee_name'] ?? '—')); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html(metis_board_format_datetime((string) ($row['meeting_date'] ?? ''))); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($row['meeting_type'] ?? 'board')); ?></td>
                <td class="metis-premium-cell"><span class="metis-chip"><?php echo metis_escape_html((string) ($row['status'] ?? 'draft')); ?></span></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($row['open_actions'] ?? 0))); ?></td>
                <?php if ($can_manage) : ?>
                    <td class="metis-premium-cell metis-board-actions-cell">
                        <button type="button" class="metis-btn-xs metis-board-edit-meeting">Edit</button>
                    </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="<?php echo $can_manage ? '7' : '6'; ?>">No meetings yet.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <div class="metis-pagination">
        <button id="metis-board-prev" type="button" class="metis-btn-xs">Prev</button>
        <span id="metis-board-page" class="metis-muted">Page 1 of 1</span>
        <button id="metis-board-next" type="button" class="metis-btn-xs">Next</button>
    </div>
        <?php },
    ]); ?>

    <section class="metis-premium-wrap metis-board-dashboard-section">
        <header class="metis-board-dashboard-section__head">
            <h2>Action Queue</h2>
        </header>
        <table class="metis-premium-table metis-board-table metis-board-actions-table metis-board-dashboard-section__body">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Open Action</th>
                    <th class="metis-premium-cell" scope="col">Owner</th>
                    <th class="metis-premium-cell" scope="col">Due</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell" scope="col">Action</th>
                </tr>
            </thead>
            <tbody id="metis-board-dashboard-action-rows">
            <?php foreach ($open_actions as $action) : ?>
                <?php $can_resolve_action = $can_manage || ($current_person_id > 0 && (int) ($action['owner_person_id'] ?? 0) === $current_person_id); ?>
                <tr class="metis-premium-row"
                    data-action-id="<?php echo metis_escape_attr((string) ((int) ($action['id'] ?? 0))); ?>"
                    data-owner-person-id="<?php echo metis_escape_attr((string) ((int) ($action['owner_person_id'] ?? 0))); ?>">
                    <td class="metis-premium-cell"><strong><?php echo metis_escape_html((string) ($action['title'] ?? '')); ?></strong><div class="metis-muted"><?php echo metis_escape_html((string) ($action['meeting_code'] ?? '')); ?></div></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($action['owner_name'] ?? 'Unassigned')); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html(!empty($action['due_date']) ? metis_runtime_date('M j, Y', strtotime((string) $action['due_date'])) : '—'); ?></td>
                    <td class="metis-premium-cell"><span class="metis-chip"><?php echo metis_escape_html((string) ($action['status'] ?? 'open')); ?></span></td>
                    <td class="metis-premium-cell metis-board-actions-cell">
                        <?php if ($can_resolve_action) : ?>
                            <button type="button" class="metis-btn-xs metis-btn-ghost metis-board-resolve-action" data-action-id="<?php echo metis_escape_attr((string) ((int) ($action['id'] ?? 0))); ?>">Resolve</button>
                        <?php else : ?>
                            <span class="metis-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($open_actions)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No open action items.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="metis-premium-wrap metis-board-dashboard-section">
        <header class="metis-board-dashboard-section__head">
            <h2>Committees</h2>
        </header>
        <table class="metis-premium-table metis-board-table metis-board-committees-table metis-board-dashboard-section__body<?php echo $can_manage ? '' : ' metis-board-table--readonly'; ?>">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Committee</th>
                    <th class="metis-premium-cell" scope="col">Chair</th>
                    <th class="metis-premium-cell" scope="col">Meetings</th>
                    <?php if ($can_manage) : ?><th class="metis-premium-cell" scope="col">Manage</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="metis-board-committee-rows">
            <?php foreach ($committees as $committee) : ?>
                <tr class="metis-premium-row" data-committee-json="<?php echo metis_escape_attr(metis_json_encode($committee)); ?>">
                    <td class="metis-premium-cell"><strong><?php echo metis_escape_html((string) ($committee['name'] ?? '')); ?></strong><div class="metis-muted"><?php echo metis_escape_html((string) ($committee['committee_code'] ?? '')); ?></div></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($committee['chair_name'] ?? '—')); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($committee['meeting_count'] ?? 0))); ?></td>
                    <?php if ($can_manage) : ?><td class="metis-premium-cell"><button type="button" class="metis-btn-xs metis-board-edit-committee">Edit</button></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($committees)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="<?php echo $can_manage ? '4' : '3'; ?>">No committees yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="metis-premium-wrap metis-board-dashboard-section">
        <header class="metis-board-dashboard-section__head">
            <h2>Announcements</h2>
        </header>
        <table class="metis-premium-table metis-board-table metis-board-announcements-table metis-board-dashboard-section__body">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Announcement</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell" scope="col">Publish At</th>
                    <th class="metis-premium-cell" scope="col">Updated</th>
                </tr>
            </thead>
            <tbody id="metis-board-announcement-rows">
            <?php foreach ($recent_announcements as $an) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell"><strong><?php echo metis_escape_html((string) ($an['title'] ?? '')); ?></strong><div class="metis-muted"><?php echo metis_escape_html((string) ($an['announcement_code'] ?? '')); ?></div></td>
                    <td class="metis-premium-cell"><span class="metis-chip"><?php echo metis_escape_html((string) ($an['status'] ?? 'draft')); ?></span></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html(!empty($an['publish_at']) ? metis_board_format_datetime((string) $an['publish_at']) : '—'); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html(metis_board_format_datetime((string) ($an['updated_at'] ?? ''))); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_announcements)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="4">No announcements yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<?php if ($can_manage) : ?>
<div id="metis-board-meeting-modal" class="metis-modal-backdrop" aria-hidden="true">
    <div class="metis-modal">
        <h2 class="metis-modal-title">Meeting</h2>
        <form id="metis-board-meeting-form" class="metis-form-grid">
            <input type="hidden" id="metis-board-meeting-id" value="0">
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-title">Title</label><input id="metis-board-meeting-title" class="metis-input" type="text" maxlength="191" required></div>
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-committee">Committee</label><select id="metis-board-meeting-committee" class="metis-select"><option value="">Board-wide</option><?php foreach ($committees as $committee) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($committee['id'] ?? 0))); ?>"><?php echo metis_escape_html((string) ($committee['name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-date">Meeting Date</label><input id="metis-board-meeting-date" class="metis-input" type="datetime-local" required></div>
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-type">Type</label><select id="metis-board-meeting-type" class="metis-select"><option value="board">Board</option><option value="committee">Committee</option><option value="special">Special Session</option></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-location">Location</label><input id="metis-board-meeting-location" class="metis-input" type="text" maxlength="191"></div>
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-status">Status</label><select id="metis-board-meeting-status" class="metis-select"><option value="draft">Draft</option><option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-calendar">Google Calendar Event ID</label><input id="metis-board-meeting-calendar" class="metis-input" type="text" maxlength="191"></div>
            <div class="metis-field metis-field-half"><label for="metis-board-meeting-drive">Google Drive Folder ID</label><input id="metis-board-meeting-drive" class="metis-input" type="text" maxlength="191"></div>
            <div class="metis-field metis-field-full"><label for="metis-board-meeting-agenda">Agenda JSON</label><textarea id="metis-board-meeting-agenda" class="metis-input" rows="6" placeholder='[{"section":"Call to Order","items":["Approve prior minutes"]}]'></textarea></div>
            <div class="metis-field metis-field-full"><label for="metis-board-meeting-minutes">Minutes (HTML)</label><textarea id="metis-board-meeting-minutes" class="metis-input" rows="6"></textarea></div>
            <div class="metis-form-actions"><button type="button" class="metis-btn metis-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="metis-btn">Save Meeting</button></div>
        </form>
    </div>
</div>

<div id="metis-board-committee-modal" class="metis-modal-backdrop" aria-hidden="true">
    <div class="metis-modal">
        <h2 class="metis-modal-title">Committee</h2>
        <form id="metis-board-committee-form" class="metis-form-grid">
            <input type="hidden" id="metis-board-committee-id" value="0">
            <div class="metis-field metis-field-half"><label for="metis-board-committee-name">Name</label><input id="metis-board-committee-name" class="metis-input" type="text" maxlength="191" required></div>
            <div class="metis-field metis-field-half"><label for="metis-board-committee-chair">Chair</label><select id="metis-board-committee-chair" class="metis-select"><option value="">None</option><?php foreach ($board_people as $bp) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($bp['id'] ?? 0))); ?>"><?php echo metis_escape_html((string) ($bp['display_name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-committee-newsletter-list">Newsletter List</label><select id="metis-board-committee-newsletter-list" class="metis-select"><option value="">None</option><?php foreach ($newsletter_lists as $list) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($list['id'] ?? 0))); ?>"><?php echo metis_escape_html((string) ($list['name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-full"><label for="metis-board-committee-description">Description</label><textarea id="metis-board-committee-description" class="metis-input" rows="5"></textarea></div>
            <div class="metis-field metis-field-half"><label for="metis-board-committee-active">Status</label><select id="metis-board-committee-active" class="metis-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="metis-form-actions"><button type="button" class="metis-btn metis-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="metis-btn">Save Committee</button></div>
        </form>
    </div>
</div>

<div id="metis-board-decision-modal" class="metis-modal-backdrop" aria-hidden="true">
    <div class="metis-modal">
        <h2 class="metis-modal-title">Board Decision</h2>
        <form id="metis-board-decision-form" class="metis-form-grid">
            <div class="metis-field metis-field-half"><label for="metis-board-decision-meeting">Meeting</label><select id="metis-board-decision-meeting" class="metis-select" required><option value="">Select meeting</option><?php foreach ($meeting_options as $mo) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($mo['id'] ?? 0))); ?>"><?php echo metis_escape_html((string) ($mo['meeting_code'] ?? '') . ' - ' . (string) ($mo['title'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-decision-title">Title</label><input id="metis-board-decision-title" class="metis-input" type="text" maxlength="191" required></div>
            <div class="metis-field metis-field-full"><label for="metis-board-decision-text">Decision Text</label><textarea id="metis-board-decision-text" class="metis-input" rows="5"></textarea></div>
            <div class="metis-field metis-field-third"><label for="metis-board-decision-for">Votes For</label><input id="metis-board-decision-for" class="metis-input" type="number" min="0" value="0"></div>
            <div class="metis-field metis-field-third"><label for="metis-board-decision-against">Votes Against</label><input id="metis-board-decision-against" class="metis-input" type="number" min="0" value="0"></div>
            <div class="metis-field metis-field-third"><label for="metis-board-decision-abstain">Abstain</label><input id="metis-board-decision-abstain" class="metis-input" type="number" min="0" value="0"></div>
            <div class="metis-field metis-field-half"><label for="metis-board-decision-outcome">Outcome</label><select id="metis-board-decision-outcome" class="metis-select"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="tabled">Tabled</option></select></div>
            <div class="metis-form-actions"><button type="button" class="metis-btn metis-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="metis-btn">Save Decision</button></div>
        </form>
    </div>
</div>

<div id="metis-board-action-modal" class="metis-modal-backdrop" aria-hidden="true">
    <div class="metis-modal">
        <h2 class="metis-modal-title">Action Item</h2>
        <form id="metis-board-action-form" class="metis-form-grid">
            <div class="metis-field metis-field-half"><label for="metis-board-action-meeting">Meeting</label><select id="metis-board-action-meeting" class="metis-select"><option value="">None</option><?php foreach ($meeting_options as $mo) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($mo['id'] ?? 0))); ?>"><?php echo metis_escape_html((string) ($mo['meeting_code'] ?? '') . ' - ' . (string) ($mo['title'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-action-owner">Owner</label><select id="metis-board-action-owner" class="metis-select"><option value="">Unassigned</option><?php foreach ($board_people as $bp) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($bp['id'] ?? 0))); ?>"><?php echo metis_escape_html((string) ($bp['display_name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-full"><label for="metis-board-action-title">Title</label><input id="metis-board-action-title" class="metis-input" type="text" maxlength="191" required></div>
            <div class="metis-field metis-field-full"><label for="metis-board-action-description">Description</label><textarea id="metis-board-action-description" class="metis-input" rows="5"></textarea></div>
            <div class="metis-field metis-field-third"><label for="metis-board-action-due">Due Date</label><input id="metis-board-action-due" class="metis-input" type="date"></div>
            <div class="metis-field metis-field-third"><label for="metis-board-action-priority">Priority</label><select id="metis-board-action-priority" class="metis-select"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="critical">Critical</option></select></div>
            <div class="metis-field metis-field-third"><label for="metis-board-action-status">Status</label><select id="metis-board-action-status" class="metis-select"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="blocked">Blocked</option><option value="done">Done</option></select></div>
            <div class="metis-form-actions"><button type="button" class="metis-btn metis-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="metis-btn">Save Action</button></div>
        </form>
    </div>
</div>

<div id="metis-board-announcement-modal" class="metis-modal-backdrop" aria-hidden="true">
    <div class="metis-modal">
        <h2 class="metis-modal-title">Announcement</h2>
        <form id="metis-board-announcement-form" class="metis-form-grid">
            <div class="metis-field metis-field-half"><label for="metis-board-announcement-title">Title</label><input id="metis-board-announcement-title" class="metis-input" type="text" maxlength="191" required></div>
            <div class="metis-field metis-field-half"><label for="metis-board-announcement-status">Status</label><select id="metis-board-announcement-status" class="metis-select"><option value="draft">Draft</option><option value="published">Published</option></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-announcement-publish">Publish At</label><input id="metis-board-announcement-publish" class="metis-input" type="datetime-local"></div>
            <div class="metis-field metis-field-full"><label for="metis-board-announcement-body">Body (HTML)</label><textarea id="metis-board-announcement-body" class="metis-input" rows="6"></textarea></div>
            <div class="metis-form-actions"><button type="button" class="metis-btn metis-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="metis-btn">Save Announcement</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
