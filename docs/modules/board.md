# Board

Board governance portal for meetings, decisions, actions, committees, and compliance.

## Routes

- Base route: `/board`
- `/board/dashboard` -> `dashboard.php`
- `/board/meeting` -> `meeting.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Meeting** template: `meeting.php`

## APIs

- `metis_board_delete_agenda_template`
- `metis_board_delete_decision_template`
- `metis_board_drive_create_folder`
- `metis_board_drive_link_document`
- `metis_board_drive_list`
- `metis_board_drive_set_meeting_folder`
- `metis_board_drive_unlink_document`
- `metis_board_drive_upload`
- `metis_board_generate_packet_pdf`
- `metis_board_get_meeting_documents`
- `metis_board_get_workflow_templates`
- `metis_board_prepare_meeting_workspace`
- `metis_board_save_action_item`
- `metis_board_save_agenda_template`
- `metis_board_save_announcement`
- `metis_board_save_committee`
- `metis_board_save_decision`
- `metis_board_save_decision_template`
- `metis_board_save_meeting`
- `metis_board_sync_decision_points`
- `metis_board_update_decision`
- `metis_board_update_meeting_detail`
- `metis_board_upsert_attendance`

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
- `people` (`metis_people`)

## Assets and Extension Hooks

- CSS: `board.css`
- JS: `board.js`
- Registered help topics: `board.dashboard`, `board.meeting`
