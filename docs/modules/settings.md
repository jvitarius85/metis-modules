# Settings

Managing site settings and APIs

## Routes

- Base route: `/settings`
- `/settings/dashboard` -> `general.php`
- `/settings/logging` -> `logging.php`
- `/settings/customization` -> `customization.php`
- `/settings/accessibility` -> `accessibility.php`
- `/settings/menu` -> `menu.php`
- `/settings/profile` -> `profile.php`
- `/settings/newsletter` -> `newsletter.php`
- `/settings/workspace` -> `workspace.php`
- `/settings/drive` -> `drive.php`
- `/settings/backup` -> `backup.php`
- `/settings/calendar` -> `calendar.php`
- `/settings/api` -> `api.php`
- `/settings/scheduler` -> `scheduler.php`
- `/settings/help` -> `help.php`

## UI Components

- **Dashboard** template: `general.php`
- **Logging** template: `logging.php`
- **Customization** template: `customization.php`
- **Accessibility** template: `accessibility.php`
- **Menu** template: `menu.php`
- **Profile** template: `profile.php`
- **Newsletter** template: `newsletter.php`
- **Workspace** template: `workspace.php`
- **Drive** template: `drive.php`
- **Backup** template: `backup.php`
- **Calendar** template: `calendar.php`
- **Api** template: `api.php`
- **Scheduler** template: `scheduler.php`
- **Help** template: `help.php`

## APIs

- `metis_backup_restore_run`
- `metis_backup_run_now`
- `metis_drive_sync_now`
- `metis_release_apply`
- `metis_release_check_updates`
- `metis_release_rollback`
- `metis_scheduler_build_integrity_baseline`
- `metis_scheduler_run_task_now`
- `metis_scheduler_update_task_settings`
- `metis_settings_save_section`

## Database Tables Used

- No table references were discovered.

## Assets and Extension Hooks

- CSS: `settings.css`
- JS: `settings.js`
- Registered help topics: `settings.dashboard`, `settings.help`
