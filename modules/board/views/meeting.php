<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_board_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view board meeting details.</div>';
    return;
}
$can_manage = metis_board_can_manage();
$current_person_id = metis_board_current_person_id();

$db = metis_db();

$meetings_table = Metis_Tables::get('board_meetings');
$committees_table = Metis_Tables::get('board_committees');
$decisions_table = Metis_Tables::get('board_decisions');
$actions_table = Metis_Tables::get('board_action_items');
$attendance_table = Metis_Tables::get('board_attendance');
$documents_table = Metis_Tables::get('board_documents');
$agenda_templates_table = Metis_Tables::get('board_agenda_templates');
$decision_templates_table = Metis_Tables::get('board_decision_templates');
$people_table = Metis_Tables::get('people');

$meeting_code = metis_text_clean((string) ($_GET['meeting'] ?? ''));
if ($meeting_code === '') {
    echo '<div class="mw-alert mw-alert-error">Meeting not found.</div>';
    return;
}

$meeting = $db->fetchOne(
    "SELECT m.*, c.name AS committee_name, p.display_name AS created_by_name
     FROM {$meetings_table} m
     LEFT JOIN {$committees_table} c ON c.id = m.committee_id
     LEFT JOIN {$people_table} p ON p.id = m.created_by_person_id
     WHERE m.meeting_code = %s
     LIMIT 1",
    [ $meeting_code ]
);

if (!$meeting) {
    echo '<div class="mw-alert mw-alert-error">Meeting not found.</div>';
    return;
}

$meeting_date_label = ! empty( $meeting['meeting_date'] ) ? date( 'M j, Y', strtotime( $meeting['meeting_date'] ) ) : $meeting_code;
metis_set_page_title( $meeting_date_label );

$meeting_id = (int) ($meeting['id'] ?? 0);
$agenda = json_decode((string) ($meeting['agenda_json'] ?? ''), true);
if (!is_array($agenda)) {
    $agenda = [];
}

$decisions = $db->fetchAll(
    "SELECT d.* FROM {$decisions_table} d WHERE d.meeting_id = %d ORDER BY d.id ASC",
    [ $meeting_id ]
) ?: [];
$decision_seen = [];
$decisions = array_values(array_filter($decisions, static function (array $decision) use (&$decision_seen): bool {
    $title = strtolower(trim((string) ($decision['title'] ?? '')));
    $item = strtolower(trim((string) ($decision['agenda_item_title'] ?? '')));
    if ($title === '') return true;
    $key = $title . '|' . $item;
    if (!isset($decision_seen[$key])) {
        $decision_seen[$key] = true;
        return true;
    }
    $is_pending = strtolower(trim((string) ($decision['outcome'] ?? 'pending'))) === 'pending';
    $has_votes = ((int) ($decision['votes_for'] ?? 0) + (int) ($decision['votes_against'] ?? 0) + (int) ($decision['votes_abstain'] ?? 0)) > 0;
    $has_text = trim((string) ($decision['decision_text'] ?? '')) !== '';
    return (!$is_pending || $has_votes || $has_text);
}));

$actions = $db->fetchAll(
    "SELECT a.*, p.display_name AS owner_name
     FROM {$actions_table} a
     LEFT JOIN {$people_table} p ON p.id = a.owner_person_id
     WHERE a.meeting_id = %d
     ORDER BY (a.status='done') ASC, (a.due_date IS NULL), a.due_date ASC, a.id ASC",
    [ $meeting_id ]
) ?: [];

$attendance = $db->fetchAll(
    "SELECT atn.*, p.display_name, p.email
     FROM {$attendance_table} atn
     INNER JOIN {$people_table} p ON p.id = atn.person_id
     WHERE atn.meeting_id = %d
     ORDER BY p.display_name ASC",
    [ $meeting_id ]
) ?: [];

$attendance_map = [];
foreach ($attendance as $att_row) {
    $attendance_map[(int) ($att_row['person_id'] ?? 0)] = $att_row;
}

$board_people = $db->fetchAll(
    "SELECT id, pid, display_name, email, is_board
     FROM {$people_table}
     WHERE status = 'active' AND (is_board = 1 OR is_staff = 1)
     ORDER BY display_name ASC",
) ?: [];
$voting_members = array_values(array_filter($board_people, static function (array $person): bool {
    return (int) ($person['is_board'] ?? 0) === 1;
}));
$voting_members_json = metis_json_encode(array_map(static function (array $person): array {
    return [
        'id' => (int) ($person['id'] ?? 0),
        'name' => (string) ($person['display_name'] ?? ''),
    ];
}, $voting_members), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($voting_members_json)) {
    $voting_members_json = '[]';
}

$documents = $db->fetchAll(
    "SELECT * FROM {$documents_table}
     WHERE meeting_id = %d
     ORDER BY updated_at DESC, id DESC",
    [ $meeting_id ]
) ?: [];

$agenda_templates = [];
if ($can_manage) {
    $agenda_templates = $db->fetchAll(
        "SELECT id, template_code, name, description, default_items_json, sort_order, is_required
         FROM {$agenda_templates_table}
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC",
    ) ?: [];
}

$decision_templates = [];
if ($can_manage) {
    $decision_templates = $db->fetchAll(
        "SELECT id, template_code, title, description, default_outcome, sort_order
         FROM {$decision_templates_table}
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC",
    ) ?: [];
}

$present_count = 0;
foreach ($attendance as $att_row) {
    $status = (string) ($att_row['status'] ?? 'present');
    if (in_array($status, ['present', 'remote'], true)) {
        $present_count++;
    }
}
$eligible_count = 0;
foreach ($board_people as $person) {
    if ((int) ($person['is_board'] ?? 0) === 1) {
        $eligible_count++;
    }
}
if ($eligible_count < 1) {
    $eligible_count = max(1, count($attendance));
}
$quorum_required = (int) floor($eligible_count / 2) + 1;
$has_quorum = $present_count >= $quorum_required;

$back_url = metis_portal_url('board', 'dashboard');
$meeting_status = (string) ($meeting['status'] ?? 'draft');
$packet_published_at = (string) ($meeting['published_at'] ?? '');
$packet_notes = (string) ($meeting['board_packet_notes'] ?? '');
$packet_prev_minutes_meeting_id = (int) ($meeting['packet_source_minutes_meeting_id'] ?? 0);
$packet_financial_doc_id = (int) ($meeting['packet_financial_document_id'] ?? 0);
$meeting_minutes_html = (string) ($meeting['minutes_html'] ?? '');
$has_agenda = !empty($agenda);
$has_minutes = trim($meeting_minutes_html) !== '';
$has_packet = ($packet_published_at !== '') || !empty($documents);
$meeting_committee_id = (int) ($meeting['committee_id'] ?? 0);
$meeting_date_value = (string) ($meeting['meeting_date'] ?? '');

$prior_meetings = [];
$packet_candidate_docs = [];
if ($can_manage) {
    $prior_meetings = $db->fetchAll(
        "SELECT id, meeting_code, title, meeting_date, minutes_html,
                CASE WHEN committee_id = %d THEN 0 ELSE 1 END AS committee_rank
         FROM {$meetings_table}
         WHERE id <> %d
           AND (%s = '' OR meeting_date < %s)
         ORDER BY committee_rank ASC, meeting_date DESC
         LIMIT 80",
        [ $meeting_committee_id, $meeting_id, $meeting_date_value, $meeting_date_value ]
    ) ?: [];

    if ($packet_prev_minutes_meeting_id < 1) {
        foreach ($prior_meetings as $pm) {
            if (trim((string) ($pm['minutes_html'] ?? '')) === '') {
                continue;
            }
            $packet_prev_minutes_meeting_id = (int) ($pm['id'] ?? 0);
            if ($packet_prev_minutes_meeting_id > 0) {
                break;
            }
        }
    }

    $packet_candidate_docs = $db->fetchAll(
        "SELECT id, meeting_id, title, doc_type, google_file_id, mime_type
         FROM {$documents_table}
         WHERE meeting_id = %d
         ORDER BY updated_at DESC, id DESC",
        [ $meeting_id ]
    ) ?: [];
}

$agenda_snapshot_json = metis_json_encode($agenda, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($agenda_snapshot_json)) {
    $agenda_snapshot_json = '[]';
}
?>

<div class="metis-board-detail"
    data-meeting-id="<?php echo metis_escape_attr((string) $meeting_id); ?>"
    data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
    data-current-person-id="<?php echo metis_escape_attr((string) $current_person_id); ?>"
    data-has-agenda="<?php echo $has_agenda ? '1' : '0'; ?>"
    data-has-minutes="<?php echo $has_minutes ? '1' : '0'; ?>"
    data-has-packet="<?php echo $has_packet ? '1' : '0'; ?>"
    data-vote-members="<?php echo metis_escape_attr($voting_members_json); ?>">
    <a class="mw-btn mw-btn-ghost" href="<?php echo metis_escape_url($back_url); ?>">Back to Board</a>

    <h1 class="mw-page-title" style="margin-top:10px;"><?php echo metis_escape_html((string) ($meeting['title'] ?? '')); ?></h1>
    <p class="mw-subtitle"><?php echo metis_escape_html((string) ($meeting['meeting_code'] ?? '')); ?> · <?php echo metis_escape_html((string) ($meeting['committee_name'] ?? 'Board-wide')); ?> · <?php echo metis_escape_html(metis_board_format_datetime((string) ($meeting['meeting_date'] ?? ''))); ?></p>

    <div id="metis-board-alert" class="mw-alert" style="display:none;"></div>

    <div class="metis-board-stats" style="grid-template-columns: repeat(5, minmax(150px,1fr));">
        <div class="metis-board-stat"><div class="metis-board-stat-label">Status</div><div class="metis-board-stat-value" style="font-size:24px;"><?php echo metis_escape_html($meeting_status); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Packet</div><div class="metis-board-stat-value" style="font-size:24px;"><?php echo $packet_published_at !== '' ? 'Published' : 'Draft'; ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Decisions</div><div class="metis-board-stat-value" style="font-size:24px;"><?php echo metis_escape_html((string) count($decisions)); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Attendance</div><div id="metis-board-kpi-attendance" class="metis-board-stat-value" style="font-size:24px;"><?php echo metis_escape_html((string) $present_count); ?></div></div>
        <div class="metis-board-stat"><div class="metis-board-stat-label">Quorum</div><div id="metis-board-kpi-quorum" class="metis-board-stat-value" style="font-size:24px;"><?php echo $has_quorum ? 'Met' : 'Pending'; ?></div></div>
    </div>
    <section class="mw-premium-wrap metis-board-tabs-wrap">
        <div class="metis-board-workflow-shell">
        <div class="metis-board-tabs" role="tablist" aria-label="Meeting workflow steps">
            <button type="button" class="mw-btn mw-btn-ghost metis-board-tab metis-board-tab-active" data-tab="overview" role="tab" aria-selected="true">1. Setup</button>
            <button type="button" class="mw-btn mw-btn-ghost metis-board-tab" data-tab="agenda" role="tab" aria-selected="false">2. Agenda</button>
            <button type="button" class="mw-btn mw-btn-ghost metis-board-tab" data-tab="minutes" role="tab" aria-selected="false">3. Minutes</button>
            <button type="button" class="mw-btn mw-btn-ghost metis-board-tab" data-tab="packet" role="tab" aria-selected="false">4. Packet</button>
            <button type="button" class="mw-btn mw-btn-ghost metis-board-tab" data-tab="voting" role="tab" aria-selected="false">5. Decisions</button>
            <button type="button" class="mw-btn mw-btn-ghost metis-board-tab" data-tab="attendance" role="tab" aria-selected="false">6. Attendance</button>
            <button type="button" class="mw-btn mw-btn-ghost metis-board-tab" data-tab="actions" role="tab" aria-selected="false">7. Actions</button>
            <div id="metis-board-step-save-state" class="metis-board-step-save-state" aria-live="polite">
                <span class="metis-board-step-save-icon" aria-hidden="true"></span>
                <span class="metis-board-step-save-text">Saved</span>
            </div>
        </div>

        <div class="metis-board-tab-panels">
            <section class="metis-board-tab-panel" data-panel="overview">
                <div class="metis-board-grid-2">
                    <section class="mw-premium-wrap">
                        <h3 class="metis-people-section-title" style="margin:0 0 10px;">Meeting Setup</h3>
                        <?php if ($can_manage) : ?>
                        <form id="metis-board-setup-form" class="metis-contact-form">
                            <div class="metis-contact-field metis-contact-field-full">
                                <label for="metis-board-setup-title">Meeting Title</label>
                                <input id="metis-board-setup-title" class="mw-input" type="text" maxlength="191" value="<?php echo metis_escape_attr((string) ($meeting['title'] ?? '')); ?>" required>
                            </div>
                            <div class="metis-contact-field metis-contact-field-half">
                                <label for="metis-board-setup-date">Meeting Date & Time</label>
                                <input id="metis-board-setup-date" class="mw-input" type="datetime-local" value="<?php echo metis_escape_attr($meeting_date_value !== '' ? metis_runtime_date('Y-m-d\\TH:i', strtotime($meeting_date_value)) : ''); ?>" required>
                            </div>
                            <div class="metis-contact-field metis-contact-field-half">
                                <label for="metis-board-setup-type">Meeting Type</label>
                                <select id="metis-board-setup-type" class="mw-select">
                                    <option value="board" <?php metis_attr_selected((string) ($meeting['meeting_type'] ?? 'board'), 'board'); ?>>Board</option>
                                    <option value="committee" <?php metis_attr_selected((string) ($meeting['meeting_type'] ?? ''), 'committee'); ?>>Committee</option>
                                    <option value="special" <?php metis_attr_selected((string) ($meeting['meeting_type'] ?? ''), 'special'); ?>>Special</option>
                                </select>
                            </div>
                            <div class="metis-contact-field metis-contact-field-half">
                                <label for="metis-board-setup-location">Location</label>
                                <input id="metis-board-setup-location" class="mw-input" type="text" maxlength="191" value="<?php echo metis_escape_attr((string) ($meeting['location'] ?? '')); ?>" placeholder="Meeting location">
                            </div>
                            <div class="metis-contact-field metis-contact-field-half">
                                <label for="metis-board-setup-status">Status</label>
                                <select id="metis-board-setup-status" class="mw-select">
                                    <option value="draft" <?php metis_attr_selected($meeting_status, 'draft'); ?>>Draft</option>
                                    <option value="scheduled" <?php metis_attr_selected($meeting_status, 'scheduled'); ?>>Scheduled</option>
                                    <option value="completed" <?php metis_attr_selected($meeting_status, 'completed'); ?>>Completed</option>
                                    <option value="cancelled" <?php metis_attr_selected($meeting_status, 'cancelled'); ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="metis-contact-actions">
                                <button type="submit" class="mw-btn">Save Setup</button>
                            </div>
                        </form>
                        <?php else : ?>
                        <div class="metis-board-linked-list">
                            <div><strong>Meeting Type:</strong> <?php echo metis_escape_html((string) ($meeting['meeting_type'] ?? 'board')); ?></div>
                            <div><strong>Location:</strong> <?php echo metis_escape_html((string) ($meeting['location'] ?? '—')); ?></div>
                            <div><strong>Status:</strong> <?php echo metis_escape_html($meeting_status); ?></div>
                            <div><strong>Created By:</strong> <?php echo metis_escape_html((string) ($meeting['created_by_name'] ?? '—')); ?></div>
                            <div><strong>Packet Published:</strong> <?php echo metis_escape_html($packet_published_at !== '' ? metis_board_format_datetime($packet_published_at) : 'No'); ?></div>
                        </div>
                        <?php endif; ?>
                    </section>

                    <section class="mw-premium-wrap">
                        <h3 class="metis-people-section-title" style="margin:0 0 10px;">Workspace Links</h3>
                        <div class="metis-board-workspace-link-group">
                            <div class="metis-board-workspace-link-title">Calendar Event</div>
                            <div class="metis-board-workspace-link-value">
                                <span id="metis-board-setup-calendar-name">Not linked</span>
                                <a id="metis-board-setup-calendar-link" href="<?php echo !empty($meeting['google_calendar_html_link']) ? metis_escape_url((string) $meeting['google_calendar_html_link']) : '#'; ?>" target="_blank" rel="noopener" <?php if (empty($meeting['google_calendar_html_link'])) : ?>hidden<?php endif; ?>>Open</a>
                            </div>
                            <?php if ($can_manage) : ?>
                            <div class="metis-board-workspace-link-actions">
                                <select id="metis-board-setup-calendar-select" class="mw-select">
                                    <option value="">Select calendar event</option>
                                </select>
                                <button type="button" class="mw-btn mw-btn-ghost" id="metis-board-setup-calendar-assign">Assign Event</button>
                                <button type="button" class="mw-btn mw-btn-ghost" id="metis-board-setup-calendar-generate">Generate Event</button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="metis-board-workspace-link-group">
                            <div class="metis-board-workspace-link-title">Drive Folder</div>
                            <div class="metis-board-workspace-link-value">
                                <span id="metis-board-setup-folder-name">Not linked</span>
                                <a id="metis-board-setup-folder-link" href="<?php echo !empty($meeting['google_drive_folder_url']) ? metis_escape_url((string) $meeting['google_drive_folder_url']) : '#'; ?>" target="_blank" rel="noopener" <?php if (empty($meeting['google_drive_folder_url'])) : ?>hidden<?php endif; ?>>Open</a>
                            </div>
                            <?php if ($can_manage) : ?>
                            <div class="metis-board-workspace-link-actions">
                                <select id="metis-board-setup-folder-select" class="mw-select">
                                    <option value="">Select drive folder</option>
                                </select>
                                <button type="button" class="mw-btn mw-btn-ghost" id="metis-board-setup-folder-assign">Assign Folder</button>
                                <button type="button" class="mw-btn mw-btn-ghost" id="metis-board-setup-folder-generate">Generate Folder</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </section>

            <section class="metis-board-tab-panel" data-panel="agenda" hidden>
                <?php if ($can_manage) : ?>
                <form id="metis-board-agenda-form" class="metis-contact-form">
                    <div class="metis-contact-field metis-contact-field-full">
                        <div class="metis-board-builder-toolbar">
                            <label>Agenda Builder</label>
                            <div class="metis-board-builder-toolbar-actions">
                                <button type="button" id="metis-board-agenda-add-section" class="mw-btn mw-btn-ghost">Add Section</button>
                                <button type="button" id="metis-board-manage-workflow" class="mw-btn mw-btn-ghost">Manage Workflow Templates</button>
                            </div>
                        </div>
                        <div id="metis-board-agenda-builder" class="metis-board-agenda-builder"></div>
                        <textarea id="metis-board-agenda-json" class="mw-input" rows="2" style="display:none;"><?php echo esc_textarea($agenda_snapshot_json); ?></textarea>
                        <template id="metis-board-agenda-section-template">
                            <div class="metis-board-agenda-section-card" data-index="">
                                <div class="metis-board-agenda-section-head">
                                    <strong class="metis-board-agenda-section-number">Section</strong>
                                    <button type="button" class="mw-btn-xs mw-btn-danger metis-board-agenda-remove">Remove</button>
                                </div>
                                <div class="metis-board-agenda-section-grid">
                                    <div>
                                        <label>Section Template</label>
                                        <select class="mw-select metis-board-agenda-template">
                                            <option value="">Custom section</option>
                                            <?php foreach ($agenda_templates as $tmpl) : ?>
                                                <option value="<?php echo metis_escape_attr((string) ($tmpl['template_code'] ?? '')); ?>" data-name="<?php echo metis_escape_attr((string) ($tmpl['name'] ?? '')); ?>" data-items="<?php echo metis_escape_attr((string) ($tmpl['default_items_json'] ?? '[]')); ?>">
                                                    <?php echo metis_escape_html((string) ($tmpl['name'] ?? '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label>Section Title</label>
                                        <input type="text" class="mw-input metis-board-agenda-title" maxlength="191" placeholder="Section title">
                                    </div>
                                    <div class="metis-board-agenda-grid-full">
                                        <div class="metis-board-agenda-decision-head">
                                            <label style="margin:0;">Decision Points (optional)</label>
                                            <button type="button" class="mw-btn-xs metis-board-agenda-decision-add">Add Decision Point</button>
                                        </div>
                                        <div class="metis-board-agenda-decision-list"></div>
                                    </div>
                                    <div class="metis-board-agenda-grid-full">
                                        <label>Agenda Items (one per line)</label>
                                        <textarea class="mw-input metis-board-agenda-items" rows="4" placeholder="Item 1&#10;Item 2"></textarea>
                                    </div>
                                    <div class="metis-board-agenda-grid-full">
                                        <label>Notes (optional)</label>
                                        <textarea class="mw-input metis-board-agenda-notes" rows="2" placeholder="Section notes"></textarea>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template id="metis-board-agenda-decision-row-template">
                            <div class="metis-board-agenda-decision-row">
                                <select class="mw-select metis-board-agenda-decision-template">
                                    <option value="">Custom decision</option>
                                    <?php foreach ($decision_templates as $dt) : ?>
                                        <option value="<?php echo metis_escape_attr((string) ($dt['template_code'] ?? '')); ?>" data-title="<?php echo metis_escape_attr((string) ($dt['title'] ?? '')); ?>">
                                            <?php echo metis_escape_html((string) ($dt['title'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" class="mw-input metis-board-agenda-decision-title" maxlength="191" placeholder="Decision text shown in packet">
                                <input type="text" class="mw-input metis-board-agenda-decision-item" maxlength="191" placeholder="Agenda item (optional)">
                                <button type="button" class="mw-btn-xs mw-btn-danger metis-board-agenda-decision-remove">Remove</button>
                            </div>
                        </template>
                    </div>
                    <div class="metis-contact-actions">
                        <button type="submit" class="mw-btn">Save Agenda</button>
                        <button type="button" id="metis-board-continue-to-minutes" class="mw-btn mw-btn-ghost">Continue to Minutes</button>
                    </div>
                </form>
                <?php else : ?>
                <div class="metis-board-grid-2">
                    <section class="mw-premium-wrap">
                        <h3 class="metis-people-section-title" style="margin:0 0 10px;">Agenda</h3>
                        <?php if (!empty($agenda)) : ?>
                            <div class="metis-board-agenda-list">
                                <?php foreach ($agenda as $index => $section) :
                                    $section_name = is_array($section) ? (string) ($section['section_name'] ?? ($section['custom_title'] ?? ($section['section'] ?? ('Section ' . ($index + 1))))) : ('Section ' . ($index + 1));
                                    $items = is_array($section) && isset($section['items']) && is_array($section['items']) ? $section['items'] : [];
                                    $decision_label = is_array($section) ? (string) ($section['decision_title'] ?? '') : '';
                                    $decision_points = is_array($section) && isset($section['decision_points']) && is_array($section['decision_points']) ? $section['decision_points'] : [];
                                    $decision_labels = [];
                                    foreach ($decision_points as $dp) {
                                        if (!is_array($dp)) continue;
                                        $dp_label = trim((string) ($dp['decision_title'] ?? ''));
                                        if ($dp_label !== '') $decision_labels[] = $dp_label;
                                    }
                                ?>
                                    <div class="metis-board-agenda-item">
                                        <div class="metis-board-agenda-title"><?php echo metis_escape_html($section_name); ?></div>
                                        <?php if (!empty($items)) : ?><ul><?php foreach ($items as $item) : ?><li><?php echo metis_escape_html((string) $item); ?></li><?php endforeach; ?></ul><?php endif; ?>
                                        <?php if (!empty($decision_labels)) : ?>
                                            <div class="mw-muted" style="margin-top:6px;"><strong>Decision points:</strong></div>
                                            <ul>
                                                <?php foreach ($decision_labels as $dp_label) : ?><li><?php echo metis_escape_html($dp_label); ?></li><?php endforeach; ?>
                                            </ul>
                                        <?php elseif ($decision_label !== '') : ?><div class="mw-muted" style="margin-top:6px;"><strong>Decision point:</strong> <?php echo metis_escape_html($decision_label); ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p class="mw-muted">No agenda items recorded.</p>
                        <?php endif; ?>
                    </section>
                    <section class="mw-premium-wrap">
                        <h3 class="metis-people-section-title" style="margin:0 0 10px;">Minutes</h3>
                        <?php if (!empty($meeting['minutes_html'])) : ?>
                            <div class="metis-board-minutes-body"><?php echo metis_runtime_kses_post((string) $meeting['minutes_html']); ?></div>
                        <?php else : ?>
                            <p class="mw-muted">No minutes published yet.</p>
                        <?php endif; ?>
                    </section>
                </div>
                <?php endif; ?>
            </section>

            <section class="metis-board-tab-panel" data-panel="minutes" hidden>
                <?php if ($can_manage) : ?>
                <form id="metis-board-minutes-form" class="metis-contact-form">
                    <div class="metis-contact-field metis-contact-field-full">
                        <label for="metis-board-detail-minutes-editor">Minutes Draft</label>
                        <div class="metis-board-rich-toolbar" data-rich-toolbar="minutes">
                            <select class="mw-select" data-rich-block>
                                <option value="p">Paragraph</option>
                                <option value="h2">Heading 2</option>
                                <option value="h3">Heading 3</option>
                                <option value="blockquote">Quote</option>
                            </select>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="undo">Undo</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="redo">Redo</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="bold"><strong>B</strong></button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="italic"><em>I</em></button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="underline"><u>U</u></button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="insertUnorderedList">Bullets</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="insertOrderedList">Numbers</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="justifyLeft">Left</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="justifyCenter">Center</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="justifyRight">Right</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-link>Link</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="unlink">Unlink</button>
                        </div>
                        <div id="metis-board-detail-minutes-editor" class="mw-input metis-board-minutes-editor" contenteditable="true" aria-label="Minutes editor"></div>
                        <textarea id="metis-board-detail-minutes-html" class="mw-input" rows="14" style="display:none;"><?php echo esc_textarea($meeting_minutes_html); ?></textarea>
                    </div>
                    <div class="metis-contact-actions">
                        <button type="button" class="mw-btn mw-btn-ghost" id="metis-board-generate-minutes-draft">Populate from Agenda</button>
                        <button type="submit" class="mw-btn">Save Minutes</button>
                        <button type="button" class="mw-btn mw-btn-ghost" data-open-board-tab="packet">Continue to Packet</button>
                    </div>
                </form>
                <?php else : ?>
                <section class="mw-premium-wrap">
                    <h3 class="metis-people-section-title" style="margin:0 0 10px;">Minutes</h3>
                    <?php if (!empty($meeting_minutes_html)) : ?>
                        <div class="metis-board-minutes-body"><?php echo metis_runtime_kses_post($meeting_minutes_html); ?></div>
                    <?php else : ?>
                        <p class="mw-muted">No minutes published yet.</p>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
            </section>

            <section class="metis-board-tab-panel" data-panel="packet" hidden>
                <?php if ($can_manage) : ?>
                <div class="mw-help">Save agenda and minutes first, then generate packet PDFs.</div>
                <div class="metis-board-packet-actions">
                    <button type="button" class="mw-btn mw-btn-ghost" data-open-board-tab="minutes">Back to Minutes</button>
                    <button type="button" id="metis-board-generate-packet" class="mw-btn">Generate Packet PDFs</button>
                    <button type="button" id="metis-board-resend-packet-email" class="mw-btn mw-btn-ghost">Resend Packet Email</button>
                </div>
                <form id="metis-board-packet-form" class="metis-contact-form">
                    <div class="metis-contact-field metis-contact-field-full">
                        <label for="metis-board-packet-notes">Packet Notes</label>
                        <div class="metis-board-rich-toolbar" data-rich-toolbar="packet-notes">
                            <select class="mw-select" data-rich-block>
                                <option value="p">Paragraph</option>
                                <option value="h3">Heading</option>
                                <option value="blockquote">Quote</option>
                            </select>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="undo">Undo</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="redo">Redo</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="bold"><strong>B</strong></button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="italic"><em>I</em></button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="underline"><u>U</u></button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="insertUnorderedList">Bullets</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="insertOrderedList">Numbers</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-link>Link</button>
                            <button type="button" class="mw-btn-xs mw-btn-ghost" data-rich-command="unlink">Unlink</button>
                        </div>
                        <div id="metis-board-packet-notes-editor" class="mw-input metis-board-minutes-editor" contenteditable="true" aria-label="Packet notes editor"></div>
                        <textarea id="metis-board-packet-notes" class="mw-input" rows="4" style="display:none;" placeholder="Optional board packet summary or publication notes."><?php echo esc_textarea($packet_notes); ?></textarea>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-board-packet-prev-minutes">Prior Meeting Minutes for Approval</label>
                        <select id="metis-board-packet-prev-minutes" class="mw-select">
                            <option value="0">None</option>
                            <optgroup label="Prior Meetings">
                            <?php foreach ($prior_meetings as $pm) : ?>
                                <option value="<?php echo metis_escape_attr((string) ($pm['id'] ?? '0')); ?>" <?php metis_attr_selected((int) ($pm['id'] ?? 0), $packet_prev_minutes_meeting_id); ?>>
                                    <?php echo metis_escape_html((string) ($pm['meeting_code'] ?? '')) . ' · ' . metis_escape_html((string) ($pm['title'] ?? 'Meeting')) . ' · ' . metis_escape_html(metis_board_format_datetime((string) ($pm['meeting_date'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php
                            $legacy_minutes_docs = array_values(array_filter($packet_candidate_docs, static function ($pd): bool {
                                $type = strtolower((string) ($pd['doc_type'] ?? ''));
                                return in_array($type, ['minutes', 'minutes_attachment'], true);
                            }));
                            ?>
                            <?php if (!empty($legacy_minutes_docs)) : ?>
                                <optgroup label="Legacy Meeting Docs">
                                    <?php foreach ($legacy_minutes_docs as $ld) : ?>
                                        <option value="<?php echo metis_escape_attr('doc:' . (string) ($ld['id'] ?? '0')); ?>">
                                            <?php echo metis_escape_html((string) ($ld['title'] ?? 'Meeting Minutes')); ?> (<?php echo metis_escape_html(function_exists('metis_board_doc_type_label') ? metis_board_doc_type_label((string) ($ld['doc_type'] ?? '')) : (string) ($ld['doc_type'] ?? 'minutes')); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                        <div class="mw-help">Defaults to the most recent prior meeting with saved minutes.</div>
                    </div>
                    <div class="metis-contact-field metis-contact-field-half">
                        <label for="metis-board-packet-financial-doc">Financial Source</label>
                        <select id="metis-board-packet-financial-doc" class="mw-select">
                            <option value="0">None</option>
                            <?php foreach ($packet_candidate_docs as $pd) :
                                $dt = strtolower((string) ($pd['doc_type'] ?? ''));
                                if (in_array($dt, ['board_packet', 'agenda', 'minutes'], true)) continue;
                            ?>
                                <option value="<?php echo metis_escape_attr((string) ($pd['id'] ?? '0')); ?>" <?php metis_attr_selected((int) ($pd['id'] ?? 0), $packet_financial_doc_id); ?>>
                                    <?php echo metis_escape_html((string) ($pd['title'] ?? 'Document')) . ' (' . metis_escape_html((string) ($pd['doc_type'] ?? 'supporting_doc')) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-contact-field metis-contact-field-full">
                        <label><input type="checkbox" id="metis-board-packet-publish" <?php metis_attr_checked($packet_published_at !== ''); ?>> Mark packet as published</label>
                    </div>
                    <div class="metis-contact-actions">
                        <button type="submit" class="mw-btn">Save Packet Metadata</button>
                    </div>
                </form>
                <?php endif; ?>

                <section class="mw-premium-wrap metis-board-drive-wrap"
                    data-meeting-id="<?php echo metis_escape_attr((string) $meeting_id); ?>"
                    data-folder-id="<?php echo metis_escape_attr((string) ($meeting['google_drive_folder_id'] ?? '')); ?>"
                    data-drive-url="<?php echo metis_escape_url(metis_portal_url('drive', 'dashboard')); ?>"
                    data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>">
                    <div class="metis-board-drive-header">
                        <h3 class="metis-people-section-title" style="margin:0;">Drive Packet Files</h3>
                    </div>
                    <div class="metis-board-drive-toolbar">
                        <input type="text" id="metis-board-drive-search" class="mw-input" placeholder="Search files in current folder">
                        <button type="button" id="metis-board-drive-refresh" class="mw-btn mw-btn-ghost">Refresh</button>
                        <button type="button" id="metis-board-drive-up" class="mw-btn mw-btn-ghost" disabled>Up</button>
                        <?php if ($can_manage) : ?>
                            <button type="button" id="metis-board-drive-upload" class="mw-btn mw-btn-ghost">Upload</button>
                            <input type="file" id="metis-board-drive-upload-input" style="display:none;" />
                        <?php endif; ?>
                    </div>
                    <div class="mw-muted" id="metis-board-drive-path">Folder: loading…</div>
                    <div class="mw-premium-table metis-board-drive-table">
                        <div class="mw-premium-row mw-premium-header">
                            <div class="mw-premium-cell">Name</div>
                            <div class="mw-premium-cell">Type</div>
                            <div class="mw-premium-cell">Modified</div>
                            <div class="mw-premium-cell">Size</div>
                            <div class="mw-premium-cell">Actions</div>
                        </div>
                        <div id="metis-board-drive-rows"></div>
                    </div>
                </section>

                <details style="margin-top:14px;">
                    <summary><strong>Show Linked Documents</strong></summary>
                    <section class="mw-premium-table metis-board-table metis-board-actions-table" style="margin-top:10px;">
                        <div class="mw-premium-row mw-premium-header"><div class="mw-premium-cell">Linked Document</div><div class="mw-premium-cell">Type</div><div class="mw-premium-cell">Link</div><div class="mw-premium-cell">Actions</div></div>
                        <div id="metis-board-linked-doc-rows">
                            <?php foreach ($documents as $doc) : ?>
                                <div class="mw-premium-row">
                                    <div class="mw-premium-cell"><strong><?php echo metis_escape_html((string) ($doc['title'] ?? '')); ?></strong></div>
                                    <div class="mw-premium-cell"><?php echo metis_escape_html(function_exists('metis_board_doc_type_label') ? metis_board_doc_type_label((string) ($doc['doc_type'] ?? '')) : (string) ($doc['doc_type'] ?? '')); ?></div>
                                    <div class="mw-premium-cell"><?php if (!empty($doc['google_drive_url'])) : ?><button type="button" class="mw-btn-xs mw-btn-ghost metis-board-preview-linked-doc" data-link="<?php echo metis_escape_attr((string) $doc['google_drive_url']); ?>">Link</button><?php else : ?><span class="mw-muted">—</span><?php endif; ?></div>
                                    <div class="mw-premium-cell">
                                        <?php if ($can_manage) : ?>
                                            <button type="button" class="mw-btn-xs mw-btn-danger metis-board-unlink-doc" data-document-id="<?php echo metis_escape_attr((string) ($doc['id'] ?? '0')); ?>">Unlink</button>
                                        <?php else : ?>
                                            <span class="mw-muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($documents)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No meeting documents linked.</div></div><?php endif; ?>
                        </div>
                    </section>
                </details>
            </section>

            <section class="metis-board-tab-panel" data-panel="voting" hidden>
                <?php if ($can_manage) : ?>
                <div style="margin-bottom:10px;">
                    <button type="button" id="metis-board-new-decision-inline" class="mw-btn">Add Decision</button>
                </div>
                <?php endif; ?>
                <section class="mw-premium-table metis-board-table">
                    <div class="mw-premium-row mw-premium-header metis-board-decision-header"><div class="mw-premium-cell">Decision</div><div class="mw-premium-cell">Outcome</div><div class="mw-premium-cell">For</div><div class="mw-premium-cell">Against</div><div class="mw-premium-cell">Abstain</div></div>
                    <div id="metis-board-decision-rows">
                    <?php foreach ($decisions as $decision) : ?>
                        <?php
                        $decision_vote_json = (string) ($decision['decision_votes_json'] ?? '');
                        if ($decision_vote_json === '') $decision_vote_json = '{"for":[],"against":[],"abstain":[]}';
                        ?>
                        <div class="mw-premium-row metis-board-decision-row"
                             data-decision-id="<?php echo metis_escape_attr((string) ((int) ($decision['id'] ?? 0))); ?>"
                             data-vote-members="<?php echo metis_escape_attr($voting_members_json); ?>"
                             data-vote-assignments="<?php echo metis_escape_attr($decision_vote_json); ?>">
                            <div class="mw-premium-cell">
                                <strong><?php echo metis_escape_html((string) ($decision['title'] ?? '')); ?></strong>
                                <?php if (!empty($decision['agenda_section_title']) || !empty($decision['agenda_item_title'])) : ?>
                                    <div class="mw-muted">Section: <?php echo metis_escape_html((string) ($decision['agenda_section_title'] ?? '')); ?><?php if (!empty($decision['agenda_item_title'])) : ?> · Item: <?php echo metis_escape_html((string) ($decision['agenda_item_title'] ?? '')); ?><?php endif; ?></div>
                                <?php endif; ?>
                                <?php if (!empty($decision['decision_text'])) : ?><div class="mw-muted"><?php echo metis_escape_html((static function (string $text, int $num_words = 22, string $more = '...'): string {
    $words = preg_split('/\s+/', trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)) ?? '')) ?: [];
    if (count($words) <= $num_words) {
        return implode(' ', $words);
    }

    return implode(' ', array_slice($words, 0, $num_words)) . $more;
})((string) $decision['decision_text'])); ?></div><?php endif; ?>
                            </div>
                            <div class="mw-premium-cell">
                                <?php if ($can_manage) : ?>
                                <select class="mw-select metis-board-decision-outcome" style="max-width:160px;" disabled>
                                    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $outcome_key => $outcome_label) : ?>
                                    <option value="<?php echo metis_escape_attr($outcome_key); ?>" <?php metis_attr_selected((string) ($decision['outcome'] ?? 'pending'), $outcome_key); ?>><?php echo metis_escape_html($outcome_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else : ?>
                                <span class="mw-chip"><?php echo metis_escape_html((string) ($decision['outcome'] ?? 'pending')); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="mw-premium-cell"><span class="metis-board-decision-count metis-board-decision-for" data-count="<?php echo metis_escape_attr((string) ((int) ($decision['votes_for'] ?? 0))); ?>"><?php echo metis_escape_html((string) ((int) ($decision['votes_for'] ?? 0))); ?></span></div>
                            <div class="mw-premium-cell"><span class="metis-board-decision-count metis-board-decision-against" data-count="<?php echo metis_escape_attr((string) ((int) ($decision['votes_against'] ?? 0))); ?>"><?php echo metis_escape_html((string) ((int) ($decision['votes_against'] ?? 0))); ?></span></div>
                            <div class="mw-premium-cell"><span class="metis-board-decision-count metis-board-decision-abstain" data-count="<?php echo metis_escape_attr((string) ((int) ($decision['votes_abstain'] ?? 0))); ?>"><?php echo metis_escape_html((string) ((int) ($decision['votes_abstain'] ?? 0))); ?></span></div>
                            <?php if ($can_manage && !empty($voting_members)) : ?>
                            <div class="mw-premium-cell metis-board-decision-vote-panel">
                                <details class="metis-board-vote-collapse">
                                    <summary>Member Voting</summary>
                                    <div class="metis-board-vote-dnd" data-vote-dnd>
                                        <section class="metis-board-vote-col">
                                            <h5>Board Members</h5>
                                            <div class="metis-board-vote-dropzone" data-vote-bucket="available"></div>
                                        </section>
                                        <section class="metis-board-vote-col">
                                            <h5>For</h5>
                                            <div class="metis-board-vote-dropzone" data-vote-bucket="for"></div>
                                        </section>
                                        <section class="metis-board-vote-col">
                                            <h5>Against</h5>
                                            <div class="metis-board-vote-dropzone" data-vote-bucket="against"></div>
                                        </section>
                                        <section class="metis-board-vote-col">
                                            <h5>Abstain</h5>
                                            <div class="metis-board-vote-dropzone" data-vote-bucket="abstain"></div>
                                        </section>
                                    </div>
                                    <div class="mw-help">Drag board members into vote columns. Votes save automatically.</div>
                                </details>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($decisions)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No decisions recorded.</div></div><?php endif; ?>
                    </div>
                </section>
            </section>

            <section class="metis-board-tab-panel" data-panel="attendance" hidden>
                <div class="metis-board-grid-2">
                    <section class="mw-premium-wrap">
                        <h3 class="metis-people-section-title" style="margin:0 0 10px;">Quorum Summary</h3>
                        <div class="metis-board-linked-list">
                            <div><strong>Eligible Voting Members:</strong> <span id="metis-board-eligible-count"><?php echo metis_escape_html((string) $eligible_count); ?></span></div>
                            <div><strong>Present or Remote:</strong> <span id="metis-board-present-count"><?php echo metis_escape_html((string) $present_count); ?></span></div>
                            <div><strong>Required for Quorum:</strong> <span id="metis-board-required-count"><?php echo metis_escape_html((string) $quorum_required); ?></span></div>
                            <div><strong>Quorum Status:</strong> <span id="metis-board-quorum-status" class="mw-chip <?php echo $has_quorum ? 'mw-chip-success' : ''; ?>"><?php echo $has_quorum ? 'Met' : 'Pending'; ?></span></div>
                        </div>
                    </section>
                    <section class="mw-premium-wrap">
                        <h3 class="metis-people-section-title" style="margin:0 0 10px;">Attendance Controls</h3>
                        <?php if ($can_manage) : ?>
                            <div class="metis-board-linked-list">
                                <div><label><input type="checkbox" id="metis-board-attendance-lock" <?php metis_attr_checked((int) ($meeting['attendance_locked'] ?? 0) === 1); ?>> Lock attendance records</label></div>
                                <div class="mw-muted">When locked, attendance cannot be edited by board managers.</div>
                            </div>
                        <?php else : ?>
                            <p class="mw-muted">Attendance is <?php echo (int) ($meeting['attendance_locked'] ?? 0) === 1 ? 'locked' : 'open'; ?>.</p>
                        <?php endif; ?>
                    </section>
                </div>

                <section class="mw-premium-table metis-board-table" style="margin-top:14px;">
                    <div class="mw-premium-row mw-premium-header metis-board-attendance-header"><div class="mw-premium-cell">Member</div><div class="mw-premium-cell">Role</div><div class="mw-premium-cell">Status</div><div class="mw-premium-cell">Notes</div></div>
                    <?php foreach ($board_people as $person) :
                        $pid = (int) ($person['id'] ?? 0);
                        $att = $attendance_map[$pid] ?? null;
                        $att_status = is_array($att) ? (string) ($att['status'] ?? 'absent') : 'absent';
                        $att_role = is_array($att) ? (string) ($att['role_label'] ?? '') : (((int) ($person['is_board'] ?? 0) === 1) ? 'Board Member' : 'Staff');
                        $att_notes = is_array($att) ? (string) ($att['notes'] ?? '') : '';
                    ?>
                    <div class="mw-premium-row metis-board-attendance-row" data-person-id="<?php echo metis_escape_attr((string) $pid); ?>">
                        <div class="mw-premium-cell"><strong><?php echo metis_escape_html((string) ($person['display_name'] ?? '')); ?></strong><div class="mw-muted"><?php echo metis_escape_html((string) ($person['email'] ?? '')); ?></div></div>
                        <div class="mw-premium-cell"><?php if ($can_manage) : ?><input class="mw-input metis-board-attendance-role" type="text" value="<?php echo metis_escape_attr($att_role); ?>" maxlength="64" style="max-width:180px;"><?php else : ?><?php echo metis_escape_html($att_role !== '' ? $att_role : '—'); ?><?php endif; ?></div>
                        <div class="mw-premium-cell">
                            <?php if ($can_manage) : ?>
                            <select class="mw-select metis-board-attendance-status" style="max-width:160px;">
                                <?php foreach (['present' => 'Present', 'remote' => 'Remote', 'absent' => 'Absent', 'excused' => 'Excused'] as $status_key => $status_label) : ?>
                                <option value="<?php echo metis_escape_attr($status_key); ?>" <?php metis_attr_selected($att_status, $status_key); ?>><?php echo metis_escape_html($status_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else : ?>
                            <span class="mw-chip"><?php echo metis_escape_html($att_status); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="mw-premium-cell"><?php if ($can_manage) : ?><input class="mw-input metis-board-attendance-notes" type="text" value="<?php echo metis_escape_attr($att_notes); ?>" maxlength="255"><?php else : ?><?php echo metis_escape_html($att_notes !== '' ? $att_notes : '—'); ?><?php endif; ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($board_people)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No eligible board/staff members found.</div></div><?php endif; ?>
                </section>
            </section>

            <section class="metis-board-tab-panel" data-panel="actions" hidden>
                <?php if ($can_manage) : ?><div style="margin-bottom:10px;"><button type="button" id="metis-board-new-action-inline" class="mw-btn">Add Action Item</button></div><?php endif; ?>
                <section class="mw-premium-table metis-board-table metis-board-actions-table">
                    <div class="mw-premium-row mw-premium-header"><div class="mw-premium-cell">Action Item</div><div class="mw-premium-cell">Owner</div><div class="mw-premium-cell">Due</div><div class="mw-premium-cell">Status</div><div class="mw-premium-cell">Action</div></div>
                    <div id="metis-board-action-rows">
                    <?php foreach ($actions as $action) : ?>
                        <?php $can_resolve_action = $can_manage || ($current_person_id > 0 && (int) ($action['owner_person_id'] ?? 0) === $current_person_id); ?>
                        <div class="mw-premium-row"
                            data-action-id="<?php echo metis_escape_attr((string) ((int) ($action['id'] ?? 0))); ?>"
                            data-owner-person-id="<?php echo metis_escape_attr((string) ((int) ($action['owner_person_id'] ?? 0))); ?>">
                            <div class="mw-premium-cell"><strong><?php echo metis_escape_html((string) ($action['title'] ?? '')); ?></strong></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html((string) ($action['owner_name'] ?? 'Unassigned')); ?></div>
                            <div class="mw-premium-cell"><?php echo metis_escape_html(!empty($action['due_date']) ? metis_runtime_date('M j, Y', strtotime((string) $action['due_date'])) : '—'); ?></div>
                            <div class="mw-premium-cell"><span class="mw-chip"><?php echo metis_escape_html((string) ($action['status'] ?? 'open')); ?></span></div>
                            <div class="mw-premium-cell metis-board-actions-cell">
                                <?php if ($can_resolve_action && (string) ($action['status'] ?? 'open') !== 'done') : ?>
                                    <button type="button" class="mw-btn-xs mw-btn-ghost metis-board-resolve-action" data-action-id="<?php echo metis_escape_attr((string) ((int) ($action['id'] ?? 0))); ?>">Resolve</button>
                                <?php else : ?>
                                    <span class="mw-muted">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($actions)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No action items for this meeting.</div></div><?php endif; ?>
                    </div>
                </section>
            </section>
        </div>
        </div>
    </section>
</div>

<?php if ($can_manage) : ?>
<div id="metis-board-decision-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner">
        <h2 class="metis-contacts-modal-title">Board Decision</h2>
        <form id="metis-board-decision-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-board-decision-meeting">Meeting</label>
                <select id="metis-board-decision-meeting" class="mw-select" required>
                    <option value="<?php echo metis_escape_attr((string) $meeting_id); ?>"><?php echo metis_escape_html((string) ($meeting['meeting_code'] ?? '') . ' - ' . (string) ($meeting['title'] ?? '')); ?></option>
                </select>
            </div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-title">Title</label><input id="metis-board-decision-title" class="mw-input" type="text" maxlength="191" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-section">Agenda Section</label><input id="metis-board-decision-section" class="mw-input" type="text" maxlength="191" placeholder="Optional"></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-item">Agenda Item</label><input id="metis-board-decision-item" class="mw-input" type="text" maxlength="191" placeholder="Optional"></div>
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
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-action-meeting">Meeting</label><select id="metis-board-action-meeting" class="mw-select"><option value="<?php echo metis_escape_attr((string) $meeting_id); ?>"><?php echo metis_escape_html((string) ($meeting['meeting_code'] ?? '') . ' - ' . (string) ($meeting['title'] ?? '')); ?></option></select></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-action-owner">Owner</label><select id="metis-board-action-owner" class="mw-select"><option value="">Unassigned</option><?php foreach ($board_people as $bp) : ?><option value="<?php echo metis_escape_attr((string) ((int) ($bp['id'] ?? 0))); ?>"><?php echo metis_escape_html((string) ($bp['display_name'] ?? '')); ?></option><?php endforeach; ?></select></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-action-title">Title</label><input id="metis-board-action-title" class="mw-input" type="text" maxlength="191" required></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-action-description">Description</label><textarea id="metis-board-action-description" class="mw-input" rows="5"></textarea></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-action-due">Due Date</label><input id="metis-board-action-due" class="mw-input" type="date"></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-action-priority">Priority</label><select id="metis-board-action-priority" class="mw-select"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="critical">Critical</option></select></div>
            <div class="metis-contact-field metis-contact-field-third"><label for="metis-board-action-status">Status</label><select id="metis-board-action-status" class="mw-select"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="blocked">Blocked</option><option value="done">Done</option></select></div>
            <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button><button type="submit" class="mw-btn">Save Action</button></div>
        </form>
    </div>
</div>

<div class="metis-contacts-modal" id="metis-board-drive-folder-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:560px;">
        <h3 class="metis-contacts-modal-title">Create Drive Folder</h3>
        <form class="metis-contact-form" id="metis-board-drive-folder-form">
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-board-drive-folder-name">Folder Name</label>
                <input id="metis-board-drive-folder-name" class="mw-input" type="text" required />
            </div>
            <div class="metis-contact-field metis-contact-field-full">
                <label><input type="checkbox" id="metis-board-drive-folder-set-meeting" checked> Set as meeting folder</label>
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button>
                <button type="submit" class="mw-btn">Create Folder</button>
            </div>
        </form>
    </div>
</div>

<div class="metis-contacts-modal" id="metis-board-doc-meta-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:640px;">
        <h3 class="metis-contacts-modal-title">Add Packet Document</h3>
        <form class="metis-contact-form" id="metis-board-doc-meta-form">
            <div class="metis-contact-field metis-contact-field-full" id="metis-board-doc-meta-file-wrap">
                <label for="metis-board-doc-meta-file">Document</label>
                <input id="metis-board-doc-meta-file" class="mw-input" type="file" />
            </div>
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-board-doc-meta-title">Item Name</label>
                <input id="metis-board-doc-meta-title" class="mw-input" type="text" maxlength="191" required />
            </div>
            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-board-doc-meta-type">Item Type</label>
                <select id="metis-board-doc-meta-type" class="mw-select">
                    <option value="supporting_doc">Supporting Doc</option>
                    <option value="financial_report">Financial Report</option>
                    <option value="agenda_attachment">Agenda Attachment</option>
                    <option value="minutes_attachment">Minutes Attachment</option>
                    <option value="policy">Policy</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Cancel</button>
                <button type="submit" class="mw-btn" id="metis-board-doc-meta-submit">Save Document</button>
            </div>
        </form>
    </div>
</div>

<div class="metis-contacts-modal" id="metis-board-drive-preview-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:1080px;">
        <h3 class="metis-contacts-modal-title">Document Preview</h3>
        <iframe id="metis-board-drive-preview-frame" src="about:blank" style="width:100%;height:70vh;border:1px solid #d8deea;border-radius:8px;background:#fff;" title="Document preview"></iframe>
        <div class="metis-contact-actions">
            <a id="metis-board-drive-preview-open" class="mw-btn mw-btn-ghost" href="#" target="_blank" rel="noopener">Open in new tab</a>
            <a id="metis-board-drive-preview-download" class="mw-btn mw-btn-ghost" href="#" target="_blank" rel="noopener">Download</a>
            <button type="button" class="mw-btn metis-board-cancel">Close</button>
        </div>
    </div>
</div>

<div class="metis-contacts-modal" id="metis-board-workflow-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:1080px;">
        <h3 class="metis-contacts-modal-title">Workflow Templates</h3>
        <div class="metis-board-workflow-modal-tabs" role="tablist" aria-label="Workflow template groups">
            <button type="button" class="mw-btn mw-btn-ghost metis-board-workflow-tab-btn is-active" data-workflow-tab="agenda">Agenda Sections</button>
            <button type="button" class="mw-btn mw-btn-ghost metis-board-workflow-tab-btn" data-workflow-tab="decision">Decision Templates</button>
        </div>
        <div class="metis-board-workflow-modal-panels">
            <section class="mw-premium-wrap metis-board-workflow-tab-panel" data-workflow-panel="agenda">
                <h4 class="metis-people-section-title" style="margin:0 0 8px;">Agenda Sections</h4>
                <div class="mw-help" style="margin-bottom:8px;">Define reusable meeting sections used in agenda builder.</div>
                <div id="metis-board-agenda-template-list" class="metis-board-template-list"></div>
                <form id="metis-board-agenda-template-form" class="metis-contact-form" style="margin-top:10px;">
                    <input type="hidden" id="metis-board-agenda-template-id" value="0">
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-agenda-template-name">Name</label><input id="metis-board-agenda-template-name" class="mw-input" type="text" maxlength="191" required></div>
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-agenda-template-sort">Sort Order</label><input id="metis-board-agenda-template-sort" class="mw-input" type="number" min="0" value="0"></div>
                    <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-agenda-template-items">Default Items (one per line)</label><textarea id="metis-board-agenda-template-items" class="mw-input" rows="3"></textarea></div>
                    <div class="metis-contact-field metis-contact-field-full"><label><input id="metis-board-agenda-template-required" type="checkbox"> Required in new meetings</label></div>
                    <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost" id="metis-board-agenda-template-reset">Clear</button><button type="submit" class="mw-btn">Save Section</button></div>
                </form>
            </section>
            <section class="mw-premium-wrap metis-board-workflow-tab-panel" data-workflow-panel="decision" hidden>
                <h4 class="metis-people-section-title" style="margin:0 0 8px;">Decision Templates</h4>
                <div class="mw-help" style="margin-bottom:8px;">Typical board decision points available in agenda builder.</div>
                <div id="metis-board-decision-template-list" class="metis-board-template-list"></div>
                <form id="metis-board-decision-template-form" class="metis-contact-form" style="margin-top:10px;">
                    <input type="hidden" id="metis-board-decision-template-id" value="0">
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-template-name">Title</label><input id="metis-board-decision-template-name" class="mw-input" type="text" maxlength="191" required></div>
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-board-decision-template-sort">Sort Order</label><input id="metis-board-decision-template-sort" class="mw-input" type="number" min="0" value="0"></div>
                    <div class="metis-contact-field metis-contact-field-full"><label for="metis-board-decision-template-description">Description</label><textarea id="metis-board-decision-template-description" class="mw-input" rows="3"></textarea></div>
                    <div class="metis-contact-actions"><button type="button" class="mw-btn mw-btn-ghost" id="metis-board-decision-template-reset">Clear</button><button type="submit" class="mw-btn">Save Decision Template</button></div>
                </form>
            </section>
        </div>
        <div class="metis-contact-actions">
            <button type="button" class="mw-btn mw-btn-ghost metis-board-cancel">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>
