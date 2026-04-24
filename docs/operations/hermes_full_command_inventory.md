# Hermes Full Command Inventory

Source of truth: `HermesCommandRegistry` + `HermesIntentParser`.

## Summary

- Registry commands: 24
- Parser-mapped command actions: 24
- Parser-only intents: `get_entity_attribute`

## Command Matrix

| Command | Title | Module | Permission | Approval | Service | Parser Mapped |
|---|---|---|---|---|---|---|
| `aut_self_heal` | Run Self-Heal | `settings` | `system.backup.execute` | yes | `self_healing::repairSystem` | yes |
| `aut_update_check` | Check for Updates | `settings` | `system.diagnostics.view` | no | `github_update::checkForUpdates` | yes |
| `aut_update_install` | Install Update | `settings` | `system.backup.execute` | yes | `updates::installUpdate` | yes |
| `clarify_password_reset` | Clarify Password Reset | `people` | `people.edit` | no | `hermes_user_admin::clarifyPasswordReset` | yes |
| `clear_cache` | Clear Cache | `settings` | `system.backup.execute` | yes | `hermes_system_ops::clearCache` | yes |
| `create_post` | Create Post | `website` | `website.edit` | yes | `hermes_website_admin::createPost` | yes |
| `create_user` | Create User | `people` | `people.create` | yes | `hermes_user_admin::createUser` | yes |
| `diagnose_permissions` | Diagnose Permissions | `people` | `system.diagnostics.view` | no | `security_diagnostics::diagnosePermissions` | yes |
| `link_drive_folder` | Link Drive Folder | `drive` | `people.edit` | yes | `hermes_user_admin::linkDriveFolder` | yes |
| `lookup_profile` | Lookup Profile | `people` | `directory.lookup` | no | `hermes_directory::lookupProfile` | yes |
| `manage_user_roles` | Manage User Roles | `people` | `people.edit` | yes | `hermes_user_admin::manageUserRoles` | yes |
| `manage_workspace_groups` | Manage Workspace Groups | `people` | `people.workspace_manage` | yes | `hermes_user_admin::manageWorkspaceGroups` | yes |
| `offboard_user` | Offboard User | `people` | `people.edit` | yes | `hermes_user_admin::offboardUser` | yes |
| `publish_post` | Publish Post | `website` | `website.edit` | yes | `hermes_website_admin::publishPost` | yes |
| `query_capability_actors` | Query Capability Actors | `people` | `system.diagnostics.view` | no | `hermes_directory::queryCapabilityActors` | yes |
| `query_giving_summary` | Query Giving Summary | `donations` | `donations.view` | no | `hermes_directory::queryGivingSummary` | yes |
| `reset_metis_password` | Reset Metis Password | `people` | `people.edit` | yes | `hermes_user_admin::resetMetisPassword` | yes |
| `reset_user_mfa` | Reset User MFA | `people` | `people.edit` | yes | `hermes_user_admin::resetUserMfa` | yes |
| `reset_workspace_password` | Reset Workspace Password | `people` | `people.workspace_manage` | yes | `hermes_user_admin::resetWorkspacePassword` | yes |
| `run_backup` | Run System Backup | `settings` | `system.backup.execute` | yes | `hermes_system_ops::runBackup` | yes |
| `send_announcement` | Send Announcement | `newsletter` | `communications.announcement.send` | yes | `communications::sendAnnouncement` | yes |
| `sync_calendar` | Sync Calendar | `calendar` | `system.backup.execute` | yes | `hermes_system_ops::syncCalendar` | yes |
| `sync_drive` | Sync Drive | `drive` | `system.backup.execute` | yes | `hermes_system_ops::syncDrive` | yes |
| `update_contact` | Update Contact | `contacts` | `contacts.edit` | yes | `hermes_contact_admin::updateContact` | yes |

## Parser-Only Intent

- `get_entity_attribute` (handles direct/reverse attribute lookups, resolved by operational engine path)

## Representative Phrases Currently Supported

- `run backup`, `sync drive`, `sync calendar`, `clear cache`
- `create user ...`, `offboard user ...`, `manage roles ...`, `manage workspace groups ...`
- `reset metis password for ...`, `reset workspace password for ...`, `reset ... mfa`, `revoke passkeys for ...`
- `update ... phone/address/city/state/zip/birthday`, `add ... to newsletter list ...`
- `what is ... email/phone/address`, `whose email/number is this ...`
- `how much money have we raised this year`, `what newsletters is ... registered for`

## Gaps Outside Current Command Set

- No dedicated Hermes commands yet for: finance ledger ops, forms ops, portal ops, board workflows, import/export workflows.
- No explicit command yet for backup restore through Hermes chat.
- Additional natural-language variants still need parser hardening per module.

## Next Inventory Phase

1. Add per-module command targets (board, finance, forms, portal, website admin expansion).
2. Add parser phrase catalogs with tests for each command.
3. Track completion in this inventory by module and permission domain.

## Prioritized Backlog

### P1 (Immediate, High User Impact)

| Proposed Command | Module | Permission | Approval | Example Phrases | Dependencies |
|---|---|---|---|---|---|
| `backup_restore` | settings | `system.backup.execute` | yes | `restore backup <run id>`, `restore latest backup` | Existing `metis_backup_restore_run` |
| `query_contact_full` | contacts | `directory.lookup` | no | `show JD full contact info`, `give me all contact fields for JD` | Extend `HermesDirectoryService` response formatter |
| `query_donor_person` | donations | `donations.view` | no | `how much has Meg donated this year`, `donor summary for Meg` | Existing donor/profile data with clearer response contract |
| `query_newsletter_membership` | newsletter | `directory.lookup` | no | `what lists is JD on`, `is JD subscribed to newsletter` | Existing newsletter subscription query |
| `reset_workspace_password_confirmed` | people | `people.workspace_manage` | yes | `reset workspace password for Codex` | Existing command; add UX confirmation + reveal polish |
| `reset_metis_password_confirmed` | people | `people.edit` | yes | `reset metis password for Codex` | Existing command; add UX confirmation + reveal polish |

P1 parser test requirements:
- Add phrase variants for each command (min 5 positive, 3 negative).
- Add subject extraction tests for possessive + `for <name>` forms.
- Add execution response rendering tests for success + actionable failure.

### P2 (System Operations Expansion)

| Proposed Command | Module | Permission | Approval | Example Phrases | Dependencies |
|---|---|---|---|---|---|
| `queue_drain` | system | `system.backup.execute` | yes | `drain queue`, `run queued jobs now` | Existing `OperationsService` |
| `run_cron_task` | system | `system.backup.execute` | yes | `run cron task cache_cleanup` | Existing cron manager |
| `release_check` | settings | `system.diagnostics.view` | no | `check releases`, `what update is available` | Existing release service |
| `release_apply` | settings | `system.backup.execute` | yes | `apply release v1.2.3` | Existing release service |
| `release_rollback` | settings | `system.backup.execute` | yes | `rollback release` | Existing release service |
| `integrity_baseline` | settings | `system.backup.execute` | yes | `rebuild integrity baseline` | Existing integrity manager |
| `drive_sync_status` | drive | `system.diagnostics.view` | no | `drive sync status`, `last drive sync` | Existing sync state tables |
| `calendar_sync_status` | calendar | `system.diagnostics.view` | no | `calendar sync status`, `last calendar sync` | Existing sync state tables |

P2 parser test requirements:
- Distinguish status queries from execute queries (`status` vs `sync now`).
- Ensure update/release phrases do not collide with generic backup/system intents.

### P3 (Module Breadth / Analytics)

| Proposed Command | Module | Permission | Approval | Example Phrases | Dependencies |
|---|---|---|---|---|---|
| `query_raised_period` | donations | `donations.view` | no | `how much raised this month`, `ytd raised amount` | Existing giving aggregates |
| `query_top_donors` | donations | `donations.view` | no | `top donors this year` | Add aggregate query |
| `query_campaign_performance` | donations | `donations.view` | no | `campaign totals this quarter` | Add campaign aggregate query |
| `query_volunteer_status` | people | `people.view` | no | `is JD a volunteer`, `list active volunteers` | Existing attribute + people filters |
| `query_board_access` | board | `board.view` | no | `who has board access`, `is JD a board member` | Board permission joins |
| `forms_query` | forms | `forms.view` | no | `show form submissions today` | Forms service endpoints |
| `portal_query` | portal | `portal.view` | no | `portal usage this week` | Portal analytics endpoint |

P3 parser test requirements:
- Date range extraction (`this month`, `last quarter`, explicit dates).
- Aggregate intent tests (`total`, `count`, `top`).

## Rollout Rules

1. Add command registry entry first.
2. Add parser mapping + payload extractor.
3. Add service method (reuse existing module services, no duplicate logic).
4. Add operational + contract tests.
5. Add user-facing example phrase to `hermes_commands_help.md`.
