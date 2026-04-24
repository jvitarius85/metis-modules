<?php
if (!defined('METIS_ROOT')) exit;

function metis_calendar_ajax_verify(bool $manage = false): void {
    unset($manage);
    metis_calendar_ensure_schema();
}

function metis_calendar_selected_configs_from_request(): array {
    $requested_ids = metis_runtime_unslash($_POST['calendar_ids'] ?? []);
    if (!is_array($requested_ids)) {
        $requested_ids = [];
    }

    $selected_settings = !empty($requested_ids)
        ? metis_calendar_settings_by_ids($requested_ids)
        : metis_calendar_setting_rows();

    if (empty($selected_settings)) {
        $selected_settings = [metis_calendar_default_setting()];
    }

    $configs = [];
    foreach ($selected_settings as $setting) {
        $cfg = metis_calendar_setting_config($setting);
        if (!empty($cfg['ok'])) {
            $configs[] = $cfg;
        }
    }

    return $configs;
}

function metis_calendar_register_ajax_controllers(): void {
    $actions = [
        'metis_calendar_list_events' => 'view',
        'metis_calendar_sync_worker' => 'view',
        'metis_calendar_save_event' => 'edit',
        'metis_calendar_delete_event' => 'edit',
    ];

    foreach ($actions as $action => $permission) {
        metis_ajax_register_controller($action, [
            'module' => 'calendar',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action($action),
        ]);
    }
}

metis_calendar_register_ajax_controllers();

metis_ajax_register_handler( 'metis_calendar_list_events', function () {
    metis_calendar_ajax_verify(false);
    metis_calendar_ensure_schema();
    $workspace = metis_calendar_workspace_settings_all();
    if (empty($workspace['ok'])) metis_runtime_send_json_error('Workspace calendar configuration is unavailable.', 400);

    $search = metis_text_clean(metis_runtime_unslash($_POST['search'] ?? ''));
    $start = metis_text_clean(metis_runtime_unslash($_POST['start'] ?? ''));
    $end = metis_text_clean(metis_runtime_unslash($_POST['end'] ?? ''));
    $configs = metis_calendar_selected_configs_from_request();
    if (empty($configs)) {
        metis_runtime_send_json_error('No valid calendars were selected.', 400);
    }

    $cutoff_ts = strtotime('-60 days midnight');
    $start_ts = $start !== '' ? strtotime($start . ' 00:00:00') : false;
    $end_ts = $end !== '' ? strtotime($end . ' 23:59:59') : false;
    if ($start_ts !== false && $end_ts !== false && $start_ts > $end_ts) {
        metis_runtime_send_json_error('From date cannot be later than To date.', 422);
    }
    if ($start_ts === false || $start_ts < $cutoff_ts) {
        $start_ts = $cutoff_ts;
    }
    $items = [];
    $errors = [];
    $calendar_rows = [];
    $selected_calendar_rows = [];
    $successful_calendars = 0;

    foreach ((array) ($workspace['calendars'] ?? []) as $workspace_cfg) {
        $calendar_meta = metis_calendar_cached_calendar_meta($workspace_cfg);
        $calendar_rows[] = [
            'calendar_id' => (string) ($workspace_cfg['calendar_id'] ?? ''),
            'calendar_name' => (string) ($calendar_meta['summary'] ?? $workspace_cfg['calendar_name'] ?? ''),
            'calendar_label' => (string) ($workspace_cfg['calendar_label'] ?? ''),
        ];
    }

    foreach ($configs as $cfg) {
        $calendar_meta = metis_calendar_cached_calendar_meta($cfg);
        $calendar_name = (string) ($calendar_meta['summary'] ?? $cfg['calendar_name'] ?? '');
        $calendar_id = (string) ($cfg['calendar_id'] ?? '');
        $selected_calendar_rows[] = [
            'calendar_id' => $calendar_id,
            'calendar_name' => $calendar_name,
            'calendar_label' => (string) ($cfg['calendar_label'] ?? ''),
        ];

        metis_calendar_mark_requested($calendar_id, $calendar_name);

        $cached_items = metis_calendar_cached_events($cfg, (int) $start_ts, (int) ($end_ts ?: 0), $search);
        $successful_calendars += 1;
        $items = array_merge($items, $cached_items);

        if (empty($cached_items)) {
            $errors[] = [
                'calendar_id' => $calendar_id,
                'calendar_label' => (string) ($cfg['calendar_label'] ?: $cfg['calendar_id']),
                'error' => 'Using cached data. Background sync pending.',
            ];
        }
    }

    usort($items, static function ($a, $b) {
        $a_start = (string) (($a['start']['dateTime'] ?? $a['start']['date'] ?? ''));
        $b_start = (string) (($b['start']['dateTime'] ?? $b['start']['date'] ?? ''));
        $a_ts = strtotime($a_start) ?: 0;
        $b_ts = strtotime($b_start) ?: 0;
        return $a_ts <=> $b_ts;
    });

    $selected_count = count($configs);
    $all_failed = $selected_count > 0 && $successful_calendars === 0;
    $summary_error = $all_failed
        ? implode(' | ', array_map(static function ($row) {
            return trim((string) (($row['calendar_label'] ?? 'Calendar') . ': Failed to load events.'));
        }, $errors))
        : '';

    metis_runtime_send_json_success([
        'calendar_name' => $selected_count === 1 ? (string) ($selected_calendar_rows[0]['calendar_name'] ?? '') : 'Combined Calendars',
        'calendar_id' => $selected_count === 1 ? (string) ($selected_calendar_rows[0]['calendar_id'] ?? '') : '',
        'cutoff_date' => gmdate('Y-m-d', $cutoff_ts),
        'calendars' => $calendar_rows,
        'errors' => $errors,
        'all_failed' => $all_failed,
        'error_summary' => $summary_error,
        'items' => $items,
    ]);
});

metis_ajax_register_handler( 'metis_calendar_sync_worker', function () {
    metis_calendar_ajax_verify(false);
    metis_calendar_ensure_schema();

    $force = !empty($_POST['force']);
    $configs = metis_calendar_selected_configs_from_request();
    if (empty($configs)) {
        metis_runtime_send_json_error('No valid calendars were selected.', 400);
    }

    $results = [];
    foreach ($configs as $cfg) {
        $calendar_id = (string) ($cfg['calendar_id'] ?? '');
        if (!$force && !metis_calendar_sync_needs_refresh($calendar_id, metis_calendar_background_sync_interval())) {
            $results[$calendar_id] = ['ok' => true, 'status' => 'fresh'];
            continue;
        }

        $results[$calendar_id] = metis_calendar_sync_worker($cfg, $force);
    }

    metis_runtime_send_json_success(['results' => $results]);
});

metis_ajax_register_handler( 'metis_calendar_save_event', function () {
    metis_calendar_ajax_verify(true);
    metis_calendar_ensure_schema();
    $calendar_id = metis_text_clean(metis_runtime_unslash($_POST['calendar_id'] ?? ''));
    $selected = $calendar_id !== '' ? metis_calendar_settings_by_ids([$calendar_id]) : [];
    $cfg = !empty($selected) ? metis_calendar_setting_config($selected[0]) : metis_calendar_workspace_settings();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace calendar configuration is unavailable.', 400);

    $event_id = metis_text_clean(metis_runtime_unslash($_POST['event_id'] ?? ''));
    $summary = metis_text_clean(metis_runtime_unslash($_POST['summary'] ?? ''));
    $location = metis_text_clean(metis_runtime_unslash($_POST['location'] ?? ''));
    $description = metis_runtime_kses_post(metis_runtime_unslash($_POST['description'] ?? ''));
    $start_dt = metis_text_clean(metis_runtime_unslash($_POST['start_dt'] ?? ''));
    $end_dt = metis_text_clean(metis_runtime_unslash($_POST['end_dt'] ?? ''));
    $event_type = metis_key_clean(metis_runtime_unslash($_POST['event_type'] ?? 'general'));
    $event_module = metis_key_clean(metis_runtime_unslash($_POST['event_module'] ?? 'general'));

    if ($summary === '' || $start_dt === '' || $end_dt === '') {
        metis_runtime_send_json_error('Summary, start, and end are required.', 422);
    }

    $start_iso = gmdate('c', strtotime($start_dt));
    $end_iso = gmdate('c', strtotime($end_dt));
    if (!$start_iso || !$end_iso) metis_runtime_send_json_error('Invalid start/end date.', 422);

    $payload = [
        'summary' => $summary,
        'location' => $location,
        'description' => strip_tags($description),
        'start' => ['dateTime' => $start_iso],
        'end' => ['dateTime' => $end_iso],
        'extendedProperties' => [
            'private' => [
                'metis_type' => $event_type !== '' ? $event_type : 'general',
                'metis_module' => $event_module !== '' ? $event_module : 'general',
            ],
        ],
    ];

    $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode((string) $cfg['calendar_id']) . '/events';
    if ($event_id !== '') {
        $url = $base . '/' . rawurlencode($event_id);
        $resp = metis_calendar_google_request('PUT', $url, metis_json_encode($payload), $cfg);
    } else {
        $resp = metis_calendar_google_request('POST', $base, metis_json_encode($payload), $cfg);
    }

    if (empty($resp['ok'])) metis_runtime_send_json_error('Failed to save event.', 500);
    $event = (array) ($resp['body'] ?? []);
    $event['calendar_id'] = (string) $cfg['calendar_id'];
    $event['calendar_label'] = (string) ($cfg['calendar_label'] ?? '');
    metis_calendar_store_event((string) $cfg['calendar_id'], $event);
    metis_calendar_update_sync_state((string) $cfg['calendar_id'], [
        'calendar_name' => (string) (metis_calendar_cached_calendar_meta($cfg)['summary'] ?? $cfg['calendar_name'] ?? ''),
        'last_synced_at' => metis_current_time('mysql'),
        'last_requested_at' => metis_current_time('mysql'),
        'sync_status' => 'idle',
        'item_count' => (int) (metis_calendar_sync_state((string) $cfg['calendar_id'])['item_count'] ?? 0),
        'last_error' => '',
    ]);
    metis_runtime_send_json_success(['event' => $event]);
});

metis_ajax_register_handler( 'metis_calendar_delete_event', function () {
    metis_calendar_ajax_verify(true);
    metis_calendar_ensure_schema();
    $calendar_id = metis_text_clean(metis_runtime_unslash($_POST['calendar_id'] ?? ''));
    $selected = $calendar_id !== '' ? metis_calendar_settings_by_ids([$calendar_id]) : [];
    $cfg = !empty($selected) ? metis_calendar_setting_config($selected[0]) : metis_calendar_workspace_settings();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace calendar configuration is unavailable.', 400);

    $event_id = metis_text_clean(metis_runtime_unslash($_POST['event_id'] ?? ''));
    if ($event_id === '') metis_runtime_send_json_error('Event is required.', 422);

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode((string) $cfg['calendar_id']) . '/events/' . rawurlencode($event_id);
    $resp = metis_calendar_google_request('DELETE', $url, null, $cfg);
    if (empty($resp['ok'])) metis_runtime_send_json_error('Failed to delete event.', 500);
    metis_calendar_delete_cached_event((string) $cfg['calendar_id'], $event_id);
    metis_runtime_send_json_success(['event_id' => $event_id]);
});
