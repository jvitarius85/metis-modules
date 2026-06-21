<?php
if (!defined('METIS_ROOT')) exit;
if (!metis_calendar_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Calendar.</div>';
    return;
}
$workspace = metis_calendar_workspace_settings_all();
$can_manage = metis_calendar_can_manage();
$calendar_rows = [];
if (!empty($workspace['ok'])) {
    foreach ((array) ($workspace['calendars'] ?? []) as $cfg) {
        $calendar_meta = metis_calendar_cached_calendar_meta($cfg);
        $calendar_rows[] = [
            'calendar_id' => (string) ($cfg['calendar_id'] ?? ''),
            'calendar_name' => (string) ($calendar_meta['summary'] ?? $cfg['calendar_name'] ?? ''),
            'calendar_label' => (string) ($cfg['calendar_label'] ?: ($calendar_meta['summary'] ?? $cfg['calendar_name'] ?? $cfg['calendar_id'] ?? 'Calendar')),
        ];
    }
}
?>

<div class="metis-calendar"
     data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
     data-calendars="<?php echo metis_escape_attr(metis_json_encode($calendar_rows)); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Calendar' ) ); ?></h1>
    <p class="metis-subtitle">Manage organization events from Metis using the configured Google Calendar.</p>
    <div id="metis-calendar-alert" class="metis-alert" role="status" aria-live="polite" style="display:none;"></div>

    <?php if (empty($workspace['ok'])) : ?>
        <div class="metis-alert metis-alert-error"><?php echo metis_escape_html('Calendar integration is not configured.'); ?></div>
    <?php else : ?>
        <div class="metis-stats-row metis-calendar-stats">
            <article class="metis-stat-card metis-calendar-stat">
                <div class="metis-stat-label metis-calendar-stat-label">Calendars</div>
                <div class="metis-stat-value metis-calendar-stat-value" id="metis-calendar-name"><?php echo metis_escape_html(count($calendar_rows) === 1 ? (string) ($calendar_rows[0]['calendar_label'] ?? 'Calendar') : ('All ' . count($calendar_rows))); ?></div>
                <div class="metis-stat-sub metis-muted" id="metis-calendar-id"><?php echo metis_escape_html(count($calendar_rows) === 1 ? ((string) ($calendar_rows[0]['calendar_name'] ?? '')) : 'Combined calendar view'); ?></div>
            </article>
            <article class="metis-stat-card metis-calendar-stat"><div class="metis-stat-label metis-calendar-stat-label">Visible Events</div><div class="metis-stat-value metis-calendar-stat-value" id="metis-calendar-count">0</div></article>
            <article class="metis-stat-card metis-calendar-stat"><div class="metis-stat-label metis-calendar-stat-label">Upcoming (7d)</div><div class="metis-stat-value metis-calendar-stat-value" id="metis-calendar-upcoming">0</div></article>
        </div>

        <div class="metis-toolbar">
            <div class="metis-toolbar-left metis-calendar-toolbar-left">
                <div class="metis-field"><label for="metis-calendar-search">Search</label><input id="metis-calendar-search" class="metis-input" type="text" placeholder="Title or description"></div>
                <div class="metis-calendar-month-nav">
                    <button type="button" id="metis-calendar-prev" class="metis-btn metis-btn-ghost">Prev</button>
                    <div id="metis-calendar-month-label" class="metis-calendar-month-label">Loading...</div>
                    <button type="button" id="metis-calendar-next" class="metis-btn metis-btn-ghost">Next</button>
                </div>
                <div class="metis-calendar-view-switch" role="tablist" aria-label="Calendar Views">
                    <button type="button" id="metis-calendar-view-month" class="metis-btn metis-btn-ghost is-active" role="tab" aria-selected="true" aria-controls="metis-calendar-grid" tabindex="0">Month</button>
                    <button type="button" id="metis-calendar-view-week" class="metis-btn metis-btn-ghost" role="tab" aria-selected="false" aria-controls="metis-calendar-timeview" tabindex="-1">Week</button>
                    <button type="button" id="metis-calendar-view-day" class="metis-btn metis-btn-ghost" role="tab" aria-selected="false" aria-controls="metis-calendar-timeview" tabindex="-1">Day</button>
                </div>
                <div class="metis-calendar-filters" id="metis-calendar-filters">
                    <?php foreach ($calendar_rows as $row) : ?>
                        <button
                            type="button"
                            class="metis-calendar-filter-chip is-active"
                            data-calendar-id="<?php echo metis_escape_attr((string) $row['calendar_id']); ?>"
                            title="<?php echo metis_escape_attr((string) ($row['calendar_name'] ?: $row['calendar_label'])); ?>">
                            <?php echo metis_escape_html((string) $row['calendar_label']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="metis-calendar-cutoff-note">Events older than 60 days are hidden.</div>
            </div>
            <div class="metis-toolbar-right">
                <button type="button" id="metis-calendar-today" class="metis-btn metis-btn-ghost">Today</button>
                <button type="button" id="metis-calendar-refresh" class="metis-btn metis-btn-ghost">Refresh</button>
                <?php if ($can_manage) : ?><button type="button" id="metis-calendar-new" class="metis-btn">New Event</button><?php endif; ?>
            </div>
        </div>

        <div class="metis-calendar-shell">
            <div class="metis-calendar-board">
                <div class="metis-calendar-weekdays">
                    <div>Sun</div>
                    <div>Mon</div>
                    <div>Tue</div>
                    <div>Wed</div>
                    <div>Thu</div>
                    <div>Fri</div>
                    <div>Sat</div>
                </div>
                <div id="metis-calendar-grid" class="metis-calendar-grid" role="grid" aria-labelledby="metis-calendar-month-label"></div>
                <div id="metis-calendar-timeview" class="metis-calendar-timeview" style="display:none;"></div>
            </div>
            <aside class="metis-calendar-sidebar">
                <div class="metis-calendar-sidebar-header">
                    <h3 id="metis-calendar-selected-label">Selected Day</h3>
                    <div id="metis-calendar-selected-count" class="metis-muted">0 events</div>
                </div>
                <div id="metis-calendar-selected-events" class="metis-calendar-selected-events" aria-live="polite"></div>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($workspace['ok'])) : ?>
<div class="metis-modal-backdrop" id="metis-calendar-detail-modal" aria-hidden="true" hidden>
    <div class="metis-modal metis-calendar-detail-modal-inner" role="dialog" aria-modal="true" aria-labelledby="metis-calendar-detail-title">
        <div class="metis-calendar-detail-head">
            <div>
                <div id="metis-calendar-detail-badge" class="metis-chip">Event</div>
                <h3 class="metis-modal-title" id="metis-calendar-detail-title">Event Details</h3>
            </div>
            <button type="button" class="metis-btn metis-btn-ghost metis-calendar-detail-close">Close</button>
        </div>
        <div class="metis-calendar-detail-body">
            <div class="metis-calendar-detail-row">
                <div class="metis-calendar-detail-label">When</div>
                <div id="metis-calendar-detail-when" class="metis-calendar-detail-value">-</div>
            </div>
            <div class="metis-calendar-detail-row">
                <div class="metis-calendar-detail-label">Calendar</div>
                <div id="metis-calendar-detail-calendar" class="metis-calendar-detail-value">-</div>
            </div>
            <div class="metis-calendar-detail-row">
                <div class="metis-calendar-detail-label">Location</div>
                <div id="metis-calendar-detail-location" class="metis-calendar-detail-value">-</div>
            </div>
            <div class="metis-calendar-detail-row">
                <div class="metis-calendar-detail-label">Description</div>
                <div id="metis-calendar-detail-description" class="metis-calendar-detail-value">-</div>
            </div>
        </div>
        <div class="metis-form-actions">
            <a id="metis-calendar-detail-open" class="metis-btn metis-btn-ghost" href="#" target="_blank" rel="noopener" style="display:none;">Open in Google Calendar</a>
            <?php if ($can_manage) : ?><button type="button" class="metis-btn" id="metis-calendar-detail-edit">Edit Event</button><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($workspace['ok']) && $can_manage) : ?>
<div class="metis-modal-backdrop" id="metis-calendar-event-modal" aria-hidden="true" hidden>
    <div class="metis-modal" style="max-width:760px;" role="dialog" aria-modal="true" aria-labelledby="metis-calendar-event-title">
        <h3 class="metis-modal-title" id="metis-calendar-event-title">New Event</h3>
        <form id="metis-calendar-event-form" class="metis-form-grid">
            <input type="hidden" id="metis-calendar-event-id">
            <div class="metis-field metis-field-half">
                <label for="metis-calendar-event-calendar">Calendar</label>
                <select id="metis-calendar-event-calendar" class="metis-input">
                    <?php foreach ($calendar_rows as $row) : ?>
                        <option value="<?php echo metis_escape_attr((string) $row['calendar_id']); ?>"><?php echo metis_escape_html((string) $row['calendar_label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="metis-field metis-field-full"><label for="metis-calendar-event-summary">Title</label><input id="metis-calendar-event-summary" class="metis-input" type="text" required></div>
            <div class="metis-field metis-field-half"><label for="metis-calendar-event-start">Start</label><input id="metis-calendar-event-start" class="metis-input" type="datetime-local" required></div>
            <div class="metis-field metis-field-half"><label for="metis-calendar-event-end">End</label><input id="metis-calendar-event-end" class="metis-input" type="datetime-local" required></div>
            <div class="metis-field metis-field-half">
                <label for="metis-calendar-event-type">Event Type</label>
                <select id="metis-calendar-event-type" class="metis-input">
                    <option value="general">General</option>
                    <option value="meeting">Meeting</option>
                    <option value="deadline">Deadline</option>
                    <option value="task">Task</option>
                    <option value="public">Public</option>
                </select>
            </div>
            <div class="metis-field metis-field-half">
                <label for="metis-calendar-event-module">Module</label>
                <select id="metis-calendar-event-module" class="metis-input">
                    <option value="general">General</option>
                    <option value="board">Board</option>
                    <option value="calendar">Calendar</option>
                    <option value="contacts">Contacts</option>
                    <option value="newsletter">Newsletter</option>
                    <option value="people">People</option>
                    <option value="portal">Portal</option>
                </select>
            </div>
            <div class="metis-field metis-field-full"><label for="metis-calendar-event-location">Location</label><input id="metis-calendar-event-location" class="metis-input" type="text"></div>
            <div class="metis-field metis-field-full"><label for="metis-calendar-event-description">Description</label><textarea id="metis-calendar-event-description" class="metis-input" rows="5"></textarea></div>
            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-danger" id="metis-calendar-delete" style="display:none;">Delete</button>
                <button type="button" class="metis-btn metis-btn-ghost metis-calendar-cancel">Cancel</button>
                <button type="submit" class="metis-btn">Save Event</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
