# People

Manage staff, board, volunteers, and system access.

## Routes

- Base route: `/people`
- `/people/dashboard` -> `dashboard.php`
- `/people/people_list` -> `people_list.php`
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

- `metis_people_add_document`
- `metis_people_add_lifecycle_task`
- `metis_people_apply_template`
- `metis_people_attach_drive_folder`
- `metis_people_attach_drive_folder_selection`
- `metis_people_begin_passkey_registration`
- `metis_people_bulk_role_action`
- `metis_people_bulk_stripe_role_action`
- `metis_people_bulk_workspace_group_action`
- `metis_people_complete_lifecycle_task`
- `metis_people_complete_passkey_registration`
- `metis_people_create_access_request`
- `metis_people_delete_document`
- `metis_people_drive_folder_picker`
- `metis_people_generate_totp_secret`
- `metis_people_grant_emergency_access`
- `metis_people_offboard_person`
- `metis_people_reset_mfa`
- `metis_people_resolve_access_request`
- `metis_people_revoke_emergency_access`
- `metis_people_revoke_passkey`
- `metis_people_save_avatar`
- `metis_people_save_person`
- `metis_people_save_role`
- `metis_people_save_template`
- `metis_people_search_donor`
- `metis_people_search_person`
- `metis_people_simulate_permission`
- `metis_people_verify_totp_secret`
- `metis_people_workspace_add_group_member`
- `metis_people_workspace_delete_group`
- `metis_people_workspace_full_sync_directory`
- `metis_people_workspace_get_group_members_matrix`
- `metis_people_workspace_get_group_permissions`
- `metis_people_workspace_get_role_map`
- `metis_people_workspace_import_directory_users`
- `metis_people_workspace_inspect_user_attributes`
- `metis_people_workspace_process_queue`
- `metis_people_workspace_run_security_action`
- `metis_people_workspace_save_group`
- `metis_people_workspace_save_group_members_bulk`
- `metis_people_workspace_save_group_permissions`
- `metis_people_workspace_save_user`

## Database Tables Used

- `contacts` (`metis_contacts`)
- `drive_user_folders` (`metis_drive_user_folders`)
- `people` (`metis_people`)
- `people_access_requests` (`metis_people_access_requests`)
- `people_activity` (`metis_people_activity`)
- `people_auth_challenges` (`metis_people_auth_challenges`)
- `people_documents` (`metis_people_documents`)
- `people_emergency_access` (`metis_people_emergency_access`)
- `people_lifecycle_tasks` (`metis_people_lifecycle_tasks`)
- `people_passkeys` (`metis_people_passkeys`)
- `people_permissions` (`metis_people_permissions`)
- `people_role_perms` (`metis_people_role_permissions`)
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
