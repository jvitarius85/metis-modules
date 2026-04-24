# People

Manage people, roles, access, and activity.

## Routes

- Base route: `/people`
- `/people/dashboard` -> `dashboard.php`
- `/people/people_list` -> `people_list.php`
- `/people/positions` -> `positions.php`
- `/people/roles_list` -> `roles_list.php`
- `/people/permissions` -> `permissions.php`
- `/people/access_requests` -> `access_requests.php`
- `/people/templates` -> `templates.php`
- `/people/bulk_actions` -> `bulk_actions.php`
- `/people/activity` -> `activity.php`
- `/people/workspace` -> `workspace.php`
- `/people/person` -> `person.php`
- `/people/role` -> `role.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **People List** template: `people_list.php`
- **Positions** template: `positions.php`
- **Roles List** template: `roles_list.php`
- **Permissions** template: `permissions.php`
- **Access Requests** template: `access_requests.php`
- **Templates** template: `templates.php`
- **Bulk Actions** template: `bulk_actions.php`
- **Activity** template: `activity.php`
- **Workspace** template: `workspace.php`
- **Person** template: `person.php`
- **Role** template: `role.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- `people` (`metis_people`)
- `people_access_requests` (`metis_people_access_requests`)
- `people_activity` (`metis_people_activity`)
- `people_auth_challenges` (`metis_people_auth_challenges`)
- `people_documents` (`metis_people_documents`)
- `people_emergency_access` (`metis_people_emergency_access`)
- `people_lifecycle_tasks` (`metis_people_lifecycle_tasks`)
- `people_passkeys` (`metis_people_passkeys`)
- `people_permissions` (`metis_people_permissions`)
- `people_positions` (`metis_people_positions`)
- `people_role_perms` (`metis_people_role_perms`)
- `people_role_templates` (`metis_people_role_templates`)
- `people_roles` (`metis_people_roles`)
- `people_template_roles` (`metis_people_template_roles`)
- `people_user_roles` (`metis_people_user_roles`)
- `people_workspace_group_members` (`metis_people_workspace_group_members`)
- `people_workspace_groups` (`metis_people_workspace_groups`)
- `people_workspace_security_actions` (`metis_people_workspace_security_actions`)
- `people_workspace_sync_jobs` (`metis_people_workspace_sync_jobs`)
- `people_workspace_user_roles` (`metis_people_workspace_user_roles`)
- `people_workspace_users` (`metis_people_workspace_users`)

## Assets and Extension Hooks

- CSS: `profile.css`
- JS: `profile.js`
