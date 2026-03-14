# Admin Guide

## Users, Permissions, and Roles

- Authentication and account state are managed through `metis_auth_users` plus the People role and permission tables.
- Module manifests declare role mappings for `view`, `edit`, `create`, and `delete` actions.

## Backups and Maintenance

- Backup execution is handled by the backup service and settings screens.
- Scheduler controls, integrity baselines, and release operations are centralized in Settings.

## System Settings

- Branding, workspace credentials, API keys, accessibility, menu order, and help-system controls all persist through `Core_Settings_Service`.
- Help mode and walkthroughs can be enabled or disabled from the Settings > Help section.
