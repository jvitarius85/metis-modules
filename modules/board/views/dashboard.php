<?php
if (!defined('METIS_ROOT')) exit;

require_once dirname( __DIR__ ) . '/includes/dashboard_data.php';
require_once dirname( __DIR__, 2 ) . '/BylawsFormatter.php';

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
$bylaws = metis_board_fetch_dashboard_bylaws();
$bylaws_history = metis_board_fetch_dashboard_bylaws_history(20);

$board_people = [];
if ($can_manage) {
    $board_people = metis_board_fetch_dashboard_people_options();
}

$meeting_options = [];
if ($can_manage) {
    $meeting_options = metis_board_fetch_dashboard_meeting_options(500);
}

$bylaws_decision_options = [];
$bylaws_action_options = [];
if ($can_manage) {
    $bylaws_decision_options = metis_board_fetch_dashboard_bylaws_decision_options(500);
    $bylaws_action_options = metis_board_fetch_dashboard_bylaws_action_options(500);
}

$kpis = metis_board_fetch_dashboard_kpis();
$total_meetings = (int) ( $kpis['total_meetings'] ?? 0 );
$upcoming_meetings = (int) ( $kpis['upcoming_meetings'] ?? 0 );
$open_action_count = (int) ( $kpis['open_action_count'] ?? 0 );
$committee_count = (int) ( $kpis['committee_count'] ?? 0 );
$compliance_overdue = (int) ( $kpis['compliance_overdue'] ?? 0 );
$decision_count = (int) ( $kpis['decision_count'] ?? 0 );
$bylaws_id = (int) ( $bylaws['id'] ?? 0 );
$bylaws_title = (string) ( $bylaws['title'] ?? 'Bylaws' );
$bylaws_source_text = (string) ( $bylaws['source_text'] ?? '' );
$bylaws_formatted_html = (string) ( $bylaws['formatted_html'] ?? '' );
$bylaws_signed_pdf_url = (string) ( $bylaws['signed_pdf_url'] ?? '' );
$bylaws_signed_pdf_file_id = (string) ( $bylaws['signed_pdf_file_id'] ?? '' );
$bylaws_signed_pdf_title = (string) ( $bylaws['signed_pdf_title'] ?? 'Signed bylaws PDF' );
$bylaws_effective_date = (string) ( $bylaws['effective_date'] ?? '' );
$bylaws_approved_at = (string) ( $bylaws['approved_at'] ?? '' );
$bylaws_updated_at = (string) ( $bylaws['updated_at'] ?? '' );
$bylaws_status = (string) ( $bylaws['status'] ?? '' );
$bylaws_approval_stage = (string) ( $bylaws['approval_stage'] ?? $bylaws_status );
$bylaws_meeting_id = (int) ( $bylaws['meeting_id'] ?? 0 );
$bylaws_decision_id = (int) ( $bylaws['decision_id'] ?? 0 );
$bylaws_action_item_id = (int) ( $bylaws['action_item_id'] ?? 0 );
$bylaws_secretary_label = (string) ( $bylaws['secretary_signature_name'] ?? '' );
$bylaws_president_label = (string) ( $bylaws['president_signature_name'] ?? '' );
$bylaws_effective_label = $bylaws_effective_date !== '' && function_exists('metis_runtime_format_date') ? metis_runtime_format_date($bylaws_effective_date, null, null, null, '—') : ($bylaws_effective_date !== '' ? $bylaws_effective_date : '—');
$bylaws_approved_label = $bylaws_approved_at !== '' ? metis_board_format_datetime($bylaws_approved_at) : '—';
$bylaws_updated_label = $bylaws_updated_at !== '' ? metis_board_format_datetime($bylaws_updated_at) : '—';
$bylaws_approved_input = $bylaws_approved_at !== '' ? str_replace(' ', 'T', substr($bylaws_approved_at, 0, 16)) : '';
if ($bylaws_source_text !== '') {
    $bylaws_rendered = \Metis\Modules\Board\BylawsFormatter::format($bylaws_source_text, $bylaws_title);
    $bylaws_formatted_html = (string) ($bylaws_rendered['html'] ?? $bylaws_formatted_html);
}
$bylaws_version = (int) ( $bylaws['version_number'] ?? 1 );
$bylaws_change_summary = (string) ( $bylaws['change_summary'] ?? '' );
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
                    <td class="metis-premium-cell"><?php echo metis_escape_html(!empty($action['due_date']) ? metis_runtime_format_date((string) $action['due_date'], null, null, null, '—') : '—'); ?></td>
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

    <section class="metis-premium-wrap metis-board-dashboard-section metis-board-bylaws-section">
        <header class="metis-board-dashboard-section__head">
            <div>
                <h2>Bylaws</h2>
                <div id="metis-board-bylaws-meta" class="metis-board-bylaws-meta">
                    <span>Version: <strong><?php echo metis_escape_html((string) $bylaws_version); ?></strong></span>
                    <span>Workflow: <strong><?php echo metis_escape_html($bylaws_approval_stage !== '' ? ucwords(str_replace('_', ' ', $bylaws_approval_stage)) : '—'); ?></strong></span>
                    <span>Effective: <strong><?php echo metis_escape_html($bylaws_effective_label); ?></strong></span>
                    <span>Approved: <strong><?php echo metis_escape_html($bylaws_approved_label); ?></strong></span>
                    <span>Updated: <strong><?php echo metis_escape_html($bylaws_updated_label); ?></strong></span>
                </div>
            </div>
            <div class="metis-board-bylaws-actions">
                <a id="metis-board-bylaws-signed-link"
                   class="metis-btn metis-btn-ghost metis-btn-xs"
                   href="<?php echo metis_escape_url($bylaws_signed_pdf_url); ?>"
                   target="_blank"
                   rel="noopener"
                   <?php if ($bylaws_signed_pdf_url === '') : ?>hidden<?php endif; ?>>
                    Open Signed PDF
                </a>
                <?php if ($can_manage) : ?>
                    <button id="metis-board-edit-bylaws" class="metis-btn metis-btn-xs" type="button"><?php echo $bylaws_id > 0 ? 'Edit Bylaws' : 'Add Bylaws'; ?></button>
                <?php endif; ?>
            </div>
        </header>
        <div id="metis-board-bylaws-display" class="metis-board-bylaws-display">
            <?php if ($bylaws_formatted_html !== '') : ?>
                <div class="metis-board-bylaws-summary">
                    <strong><?php echo metis_escape_html($bylaws_title); ?></strong>
                    <span class="metis-muted">Current approved bylaws version <?php echo metis_escape_html((string) $bylaws_version); ?>.</span>
                    <button id="metis-board-view-bylaws" class="metis-btn metis-btn-ghost metis-btn-xs" type="button">View Bylaws</button>
                </div>
            <?php else : ?>
                <div class="metis-empty-state">No bylaws have been saved yet.</div>
            <?php endif; ?>
        </div>
        <?php if (!empty($bylaws_history)) : ?>
            <div id="metis-board-bylaws-history" class="metis-board-bylaws-history">
                <h3>Approved Bylaws History</h3>
                <div class="metis-board-bylaws-history-list">
                    <?php foreach ($bylaws_history as $history_row) :
                        $history_status = (string) ($history_row['status'] ?? '');
                        $history_url = (string) ($history_row['signed_pdf_url'] ?? '');
                        $history_effective = (string) ($history_row['effective_date'] ?? '');
                        $history_approved = (string) ($history_row['approved_at'] ?? '');
                        $history_updated = (string) ($history_row['updated_at'] ?? '');
                        $history_effective_label = $history_effective !== '' && function_exists('metis_runtime_format_date') ? metis_runtime_format_date($history_effective, null, null, null, '—') : ($history_effective !== '' ? $history_effective : '—');
                        $history_approved_label = $history_approved !== '' ? metis_board_format_datetime($history_approved) : '—';
                        $history_updated_label = $history_updated !== '' ? metis_board_format_datetime($history_updated) : '—';
                    ?>
                        <div class="metis-board-bylaws-history-row">
                            <div>
                                <strong><?php echo metis_escape_html((string) ($history_row['title'] ?? 'Bylaws')); ?></strong>
                                <span class="metis-muted">Version <?php echo metis_escape_html((string) ((int) ($history_row['version_number'] ?? 1))); ?> · <?php echo $history_status === 'active' ? 'Current' : 'Superseded'; ?></span>
                                <?php if (!empty($history_row['change_summary'])) : ?>
                                    <div class="metis-muted"><?php echo metis_escape_html((string) $history_row['change_summary']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="metis-board-bylaws-history-meta">
                                <span>Effective <?php echo metis_escape_html($history_effective_label); ?></span>
                                <span>Approved <?php echo metis_escape_html($history_approved_label); ?></span>
                                <span>Updated <?php echo metis_escape_html($history_updated_label); ?></span>
                            </div>
                            <?php if ($history_url !== '') : ?>
                                <a class="metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url($history_url); ?>" target="_blank" rel="noopener">PDF</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
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

<div id="metis-board-bylaws-view-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-board-bylaws-view-modal" role="dialog" aria-modal="true" aria-labelledby="metis-board-bylaws-view-title">
        <div class="metis-board-bylaws-view-head">
            <div>
                <h2 id="metis-board-bylaws-view-title" class="metis-modal-title"><?php echo metis_escape_html($bylaws_title); ?></h2>
                <div id="metis-board-bylaws-view-meta" class="metis-board-bylaws-meta">
                    <span>Effective: <strong><?php echo metis_escape_html($bylaws_effective_label); ?></strong></span>
                    <span>Approved: <strong><?php echo metis_escape_html($bylaws_approved_label); ?></strong></span>
                    <span>Updated: <strong><?php echo metis_escape_html($bylaws_updated_label); ?></strong></span>
                </div>
            </div>
            <button type="button" class="metis-btn metis-btn-ghost metis-btn-xs metis-board-cancel">Close</button>
        </div>
        <div class="metis-board-bylaws-searchbar">
            <input id="metis-board-bylaws-search" class="metis-input" type="search" placeholder="Search bylaws">
            <button id="metis-board-bylaws-search-prev" class="metis-btn-xs metis-btn-ghost" type="button">Prev</button>
            <button id="metis-board-bylaws-search-next" class="metis-btn-xs metis-btn-ghost" type="button">Next</button>
            <span id="metis-board-bylaws-search-count" class="metis-muted">0 matches</span>
        </div>
        <div class="metis-board-bylaws-reader-layout">
            <nav id="metis-board-bylaws-toc" class="metis-board-bylaws-toc" aria-label="Bylaws table of contents"></nav>
            <div id="metis-board-bylaws-reader" class="metis-board-bylaws-reader">
                <?php if ($bylaws_formatted_html !== '') : ?>
                    <?php echo metis_runtime_kses_post($bylaws_formatted_html); ?>
                <?php else : ?>
                    <div class="metis-empty-state">No bylaws have been saved yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($can_manage) : ?>
<div id="metis-board-meeting-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
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

<div id="metis-board-committee-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
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

<div id="metis-board-decision-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
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

<div id="metis-board-action-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
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

<div id="metis-board-announcement-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
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

<div id="metis-board-bylaws-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-board-bylaws-modal">
        <h2 class="metis-modal-title">Board Bylaws</h2>
        <form id="metis-board-bylaws-form" class="metis-form-grid">
            <input type="hidden" id="metis-board-bylaws-id" value="<?php echo metis_escape_attr((string) $bylaws_id); ?>">
            <div class="metis-field metis-field-half"><label for="metis-board-bylaws-title">Title</label><input id="metis-board-bylaws-title" class="metis-input" type="text" maxlength="191" value="<?php echo metis_escape_attr($bylaws_title); ?>" required></div>
            <div class="metis-field metis-field-quarter"><label for="metis-board-bylaws-effective">Effective Date</label><input id="metis-board-bylaws-effective" class="metis-input" type="date" value="<?php echo metis_escape_attr($bylaws_effective_date); ?>"></div>
            <div class="metis-field metis-field-quarter"><label for="metis-board-bylaws-stage">Approval Stage</label><input id="metis-board-bylaws-stage" class="metis-input" type="text" value="<?php echo metis_escape_attr($bylaws_approval_stage !== '' ? ucwords(str_replace('_', ' ', $bylaws_approval_stage)) : 'Draft'); ?>" readonly></div>
            <div class="metis-field metis-field-third"><label for="metis-board-bylaws-meeting">Board Meeting</label><select id="metis-board-bylaws-meeting" class="metis-select"><option value="">Select meeting</option><?php foreach ($meeting_options as $mo) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($mo['id'] ?? 0))); ?>" <?php metis_attr_selected((string) $bylaws_meeting_id, (string) ((int) ($mo['id'] ?? 0))); ?>><?php echo metis_escape_html(trim((string) ($mo['meeting_code'] ?? '') . ' - ' . (string) ($mo['title'] ?? ''))); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-third"><label for="metis-board-bylaws-decision">Approved Board Vote</label><select id="metis-board-bylaws-decision" class="metis-select"><option value="">Select approved decision</option><?php foreach ($bylaws_decision_options as $decision) : $decision_id = (int) ($decision['id'] ?? 0); $decision_passed = (int) ($decision['passed'] ?? 0) === 1; ?><option value="<?php echo metis_escape_attr((string) $decision_id); ?>" <?php metis_attr_selected((string) $bylaws_decision_id, (string) $decision_id); ?>><?php echo metis_escape_html(trim((string) ($decision['decision_code'] ?? '') . ' - ' . (string) ($decision['title'] ?? '') . ($decision_passed ? ' (approved)' : ' (not approved)'))); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-third"><label for="metis-board-bylaws-action">Meeting Action Item</label><select id="metis-board-bylaws-action" class="metis-select"><option value="">Select action item</option><?php foreach ($bylaws_action_options as $action) : $action_id = (int) ($action['id'] ?? 0); ?><option value="<?php echo metis_escape_attr((string) $action_id); ?>" <?php metis_attr_selected((string) $bylaws_action_item_id, (string) $action_id); ?>><?php echo metis_escape_html(trim((string) ($action['action_code'] ?? '') . ' - ' . (string) ($action['title'] ?? '') . ' (' . (string) ($action['status'] ?? 'open') . ')')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-field metis-field-half"><label for="metis-board-bylaws-pdf-url">Signed PDF Link</label><div class="metis-board-bylaws-pdf-picker"><input id="metis-board-bylaws-pdf-url" class="metis-input" type="url" maxlength="255" value="<?php echo metis_escape_attr($bylaws_signed_pdf_url); ?>"><button type="button" id="metis-board-browse-bylaws-pdf" class="metis-btn-xs metis-btn-ghost">Browse</button></div></div>
            <div class="metis-field metis-field-quarter"><label for="metis-board-bylaws-pdf-id">PDF File ID</label><input id="metis-board-bylaws-pdf-id" class="metis-input" type="text" maxlength="191" value="<?php echo metis_escape_attr($bylaws_signed_pdf_file_id); ?>"></div>
            <div class="metis-field metis-field-quarter"><label for="metis-board-bylaws-pdf-title">PDF Label</label><input id="metis-board-bylaws-pdf-title" class="metis-input" type="text" maxlength="191" value="<?php echo metis_escape_attr($bylaws_signed_pdf_title); ?>"></div>
            <div class="metis-field metis-field-half"><label>Secretary Certification</label><div class="metis-muted" id="metis-board-bylaws-secretary-status"><?php echo metis_escape_html($bylaws_secretary_label !== '' ? $bylaws_secretary_label : 'Not certified'); ?></div></div>
            <div class="metis-field metis-field-half"><label>President Approval</label><div class="metis-muted" id="metis-board-bylaws-president-status"><?php echo metis_escape_html($bylaws_president_label !== '' ? $bylaws_president_label : 'Not approved'); ?></div></div>
            <div class="metis-field metis-field-full"><label for="metis-board-bylaws-change-summary">Approval Change Summary</label><textarea id="metis-board-bylaws-change-summary" class="metis-input" rows="3" placeholder="Summarize the approved changes for the audit history."><?php echo metis_escape_html($bylaws_change_summary); ?></textarea></div>
            <div class="metis-field metis-field-full"><label for="metis-board-bylaws-source">Pasted Bylaws Text</label><textarea id="metis-board-bylaws-source" class="metis-input metis-board-bylaws-source" rows="14" required><?php echo metis_escape_html($bylaws_source_text); ?></textarea></div>
            <div class="metis-field metis-field-full">
                <div class="metis-form-actions metis-form-actions-between">
                    <button type="button" id="metis-board-format-bylaws" class="metis-btn metis-btn-ghost">Auto-format Preview</button>
                    <div>
                        <button type="button" class="metis-btn metis-btn-ghost metis-board-cancel">Cancel</button>
                        <button type="button" id="metis-board-secretary-certify-bylaws" class="metis-btn metis-btn-ghost">Secretary Certify</button>
                        <button type="button" id="metis-board-president-approve-bylaws" class="metis-btn metis-btn-ghost">President Approve</button>
                        <button type="submit" class="metis-btn">Save Draft</button>
                    </div>
                </div>
            </div>
            <div class="metis-field metis-field-full">
                <div id="metis-board-bylaws-format-preview" class="metis-board-bylaws-format-preview">
                    <?php if ($bylaws_formatted_html !== '') : ?>
                        <?php echo metis_runtime_kses_post($bylaws_formatted_html); ?>
                    <?php else : ?>
                        <div class="metis-muted">Formatted preview will appear here.</div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="metis-board-bylaws-pdf-browser-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal">
        <h2 class="metis-modal-title">Select Signed PDF</h2>
        <p class="metis-muted">When Google Drive is enabled, this lists synced Drive PDFs. Otherwise it lists uploaded board PDFs.</p>
        <div id="metis-board-bylaws-pdf-options" class="metis-board-bylaws-pdf-options">
            <div class="metis-muted">PDF options will load when opened.</div>
        </div>
        <div class="metis-form-actions">
            <button type="button" class="metis-btn metis-btn-ghost metis-board-cancel">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>
