# Calendar

Manage Google Calendar events from Metis.

## Routes

- Base route: `/calendar`
- `/calendar/dashboard` -> `dashboard.php`

## UI Components

- **Dashboard** template: `dashboard.php`

## APIs

- `metis_calendar_delete_event`
- `metis_calendar_list_events`
- `metis_calendar_save_event`
- `metis_calendar_sync_worker`

## Database Tables Used

- `calendar_events` (`metis_calendar_events`)
- `calendar_sync_state` (`metis_calendar_sync_state`)

## Assets and Extension Hooks

- CSS: `calendar.css`
- JS: `calendar.js`
- Registered help topics: `calendar.dashboard`
