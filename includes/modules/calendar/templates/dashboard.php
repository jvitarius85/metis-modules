<?php
if (!defined('ABSPATH')) exit;
if (!metis_calendar_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Calendar.</div>';
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
     data-calendars="<?php echo esc_attr(metis_json_encode($calendar_rows)); ?>">
    <h1 class="mw-page-title">Calendar</h1>
    <p class="mw-subtitle">Manage organization events from Metis using the configured Google Calendar.</p>
    <div id="metis-calendar-alert" class="mw-alert" style="display:none;"></div>

    <?php if (empty($workspace['ok'])) : ?>
        <div class="mw-alert mw-alert-error"><?php echo esc_html((string) ($workspace['error'] ?? 'Calendar is not configured.')); ?></div>
    <?php else : ?>
        <div class="metis-calendar-stats">
            <article class="metis-calendar-stat">
                <div class="metis-calendar-stat-label">Calendars</div>
                <div class="metis-calendar-stat-value" id="metis-calendar-name" style="font-size:20px;"><?php echo esc_html(count($calendar_rows) === 1 ? (string) ($calendar_rows[0]['calendar_label'] ?? 'Calendar') : ('All ' . count($calendar_rows))); ?></div>
                <div class="mw-muted" id="metis-calendar-id" style="margin-top:6px;font-size:14px;"><?php echo esc_html(count($calendar_rows) === 1 ? ((string) ($calendar_rows[0]['calendar_name'] ?? '')) : 'Combined calendar view'); ?></div>
            </article>
            <article class="metis-calendar-stat"><div class="metis-calendar-stat-label">Visible Events</div><div class="metis-calendar-stat-value" id="metis-calendar-count">0</div></article>
            <article class="metis-calendar-stat"><div class="metis-calendar-stat-label">Upcoming (7d)</div><div class="metis-calendar-stat-value" id="metis-calendar-upcoming">0</div></article>
        </div>

        <div class="metis-contacts-toolbar">
            <div class="metis-contacts-toolbar-left metis-calendar-toolbar-left">
                <div class="metis-contact-field"><label for="metis-calendar-search">Search</label><input id="metis-calendar-search" class="mw-input" type="text" placeholder="Title or description"></div>
                <div class="metis-calendar-month-nav">
                    <button type="button" id="metis-calendar-prev" class="mw-btn mw-btn-ghost">Prev</button>
                    <div id="metis-calendar-month-label" class="metis-calendar-month-label">Loading...</div>
                    <button type="button" id="metis-calendar-next" class="mw-btn mw-btn-ghost">Next</button>
                </div>
                <div class="metis-calendar-view-switch" role="tablist" aria-label="Calendar Views">
                    <button type="button" id="metis-calendar-view-month" class="mw-btn mw-btn-ghost is-active">Month</button>
                    <button type="button" id="metis-calendar-view-week" class="mw-btn mw-btn-ghost">Week</button>
                    <button type="button" id="metis-calendar-view-day" class="mw-btn mw-btn-ghost">Day</button>
                </div>
                <div class="metis-calendar-filters" id="metis-calendar-filters">
                    <?php foreach ($calendar_rows as $row) : ?>
                        <button
                            type="button"
                            class="metis-calendar-filter-chip is-active"
                            data-calendar-id="<?php echo esc_attr((string) $row['calendar_id']); ?>"
                            title="<?php echo esc_attr((string) ($row['calendar_name'] ?: $row['calendar_label'])); ?>">
                            <?php echo esc_html((string) $row['calendar_label']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="metis-calendar-cutoff-note">Events older than 60 days are hidden.</div>
            </div>
            <div class="metis-contacts-toolbar-right">
                <button type="button" id="metis-calendar-today" class="mw-btn mw-btn-ghost">Today</button>
                <button type="button" id="metis-calendar-refresh" class="mw-btn mw-btn-ghost">Refresh</button>
                <?php if ($can_manage) : ?><button type="button" id="metis-calendar-new" class="mw-btn">New Event</button><?php endif; ?>
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
                <div id="metis-calendar-grid" class="metis-calendar-grid"></div>
                <div id="metis-calendar-timeview" class="metis-calendar-timeview" style="display:none;"></div>
            </div>
            <aside class="metis-calendar-sidebar">
                <div class="metis-calendar-sidebar-header">
                    <h3 id="metis-calendar-selected-label">Selected Day</h3>
                    <div id="metis-calendar-selected-count" class="mw-muted">0 events</div>
                </div>
                <div id="metis-calendar-selected-events" class="metis-calendar-selected-events"></div>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($workspace['ok'])) : ?>
<div class="metis-contacts-modal" id="metis-calendar-detail-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner metis-calendar-detail-modal-inner">
        <div class="metis-calendar-detail-head">
            <div>
                <div id="metis-calendar-detail-badge" class="mw-chip">Event</div>
                <h3 class="metis-contacts-modal-title" id="metis-calendar-detail-title">Event Details</h3>
            </div>
            <button type="button" class="mw-btn mw-btn-ghost metis-calendar-detail-close">Close</button>
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
        <div class="metis-contact-actions">
            <a id="metis-calendar-detail-open" class="mw-btn mw-btn-ghost" href="#" target="_blank" rel="noopener" style="display:none;">Open in Google Calendar</a>
            <?php if ($can_manage) : ?><button type="button" class="mw-btn" id="metis-calendar-detail-edit">Edit Event</button><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($workspace['ok']) && $can_manage) : ?>
<div class="metis-contacts-modal" id="metis-calendar-event-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:760px;">
        <h3 class="metis-contacts-modal-title" id="metis-calendar-event-title">New Event</h3>
        <form id="metis-calendar-event-form" class="metis-contact-form">
            <input type="hidden" id="metis-calendar-event-id">
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-calendar-event-calendar">Calendar</label>
                <select id="metis-calendar-event-calendar" class="mw-input">
                    <?php foreach ($calendar_rows as $row) : ?>
                        <option value="<?php echo esc_attr((string) $row['calendar_id']); ?>"><?php echo esc_html((string) $row['calendar_label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-calendar-event-summary">Title</label><input id="metis-calendar-event-summary" class="mw-input" type="text" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-calendar-event-start">Start</label><input id="metis-calendar-event-start" class="mw-input" type="datetime-local" required></div>
            <div class="metis-contact-field metis-contact-field-half"><label for="metis-calendar-event-end">End</label><input id="metis-calendar-event-end" class="mw-input" type="datetime-local" required></div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-calendar-event-type">Event Type</label>
                <select id="metis-calendar-event-type" class="mw-input">
                    <option value="general">General</option>
                    <option value="meeting">Meeting</option>
                    <option value="deadline">Deadline</option>
                    <option value="task">Task</option>
                    <option value="public">Public</option>
                </select>
            </div>
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-calendar-event-module">Module</label>
                <select id="metis-calendar-event-module" class="mw-input">
                    <option value="general">General</option>
                    <option value="board">Board</option>
                    <option value="calendar">Calendar</option>
                    <option value="contacts">Contacts</option>
                    <option value="newsletter">Newsletter</option>
                    <option value="people">People</option>
                    <option value="portal">Portal</option>
                </select>
            </div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-calendar-event-location">Location</label><input id="metis-calendar-event-location" class="mw-input" type="text"></div>
            <div class="metis-contact-field metis-contact-field-full"><label for="metis-calendar-event-description">Description</label><textarea id="metis-calendar-event-description" class="mw-input" rows="5"></textarea></div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-danger" id="metis-calendar-delete" style="display:none;">Delete</button>
                <button type="button" class="mw-btn mw-btn-ghost metis-calendar-cancel">Cancel</button>
                <button type="submit" class="mw-btn">Save Event</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
