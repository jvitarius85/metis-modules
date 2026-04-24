# Board Administration

Plan meetings, track decisions, and keep board work organized.

## Routes

- Base route: `/board`
- `/board/dashboard` -> `dashboard.php`
- `/board/meeting` -> `meeting.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Meeting** template: `meeting.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- `board_action_items` (`metis_board_action_items`)
- `board_agenda_templates` (`metis_board_agenda_templates`)
- `board_announcements` (`metis_board_announcements`)
- `board_attendance` (`metis_board_attendance`)
- `board_committees` (`metis_board_committees`)
- `board_compliance` (`metis_board_compliance`)
- `board_decision_templates` (`metis_board_decision_templates`)
- `board_decisions` (`metis_board_decisions`)
- `board_documents` (`metis_board_documents`)
- `board_meetings` (`metis_board_meetings`)
- `calendar_events` (`metis_calendar_events`)
- `drive_items` (`metis_drive_items`)
- `people` (`metis_people`)

## Assets and Extension Hooks

- CSS: `board.css`
- JS: `board.js`
- Registered help topics: `board.dashboard`, `board.meeting`
