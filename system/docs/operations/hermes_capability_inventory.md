# Hermes Capability Inventory

Generated from command registry and Hermes library definitions.

## Commands (Current)

| Command | Type | Permission | Context Packs |
|---|---|---|---|
| run_backup | write | system.backup.execute | system, backup |
| sync_drive | write | system.backup.execute | system, drive |
| sync_calendar | write | system.backup.execute | system, calendar |
| clear_cache | write | system.backup.execute | system |
| send_announcement | write | communications.announcement.send | contacts, communications |
| diagnose_permissions | read | system.diagnostics.view | people, permissions, drive, board |
| lookup_profile | read | directory.lookup | people, contacts, donations |
| query_giving_summary | read | donations.view | donations |
| query_capability_actors | read | system.diagnostics.view | people, permissions |
| update_contact | write | contacts.edit | contacts, communications |
| create_user | write | people.create | people, permissions, drive |
| offboard_user | write | people.edit | people, permissions, drive |
| manage_user_roles | write | people.edit | people, permissions |
| manage_workspace_groups | write | people.workspace_manage | people, drive |
| reset_workspace_password | write | people.workspace_manage | people |
| reset_metis_password | write | people.edit | people |
| reset_user_mfa | write | people.edit | people, security |
| clarify_password_reset | read | people.edit | people |
| link_drive_folder | write | people.edit | people, drive |
| create_post | write | website.edit | cms_content |
| publish_post | write | website.edit | cms_content |
| aut_update_check | read | system.diagnostics.view | system |
| aut_update_install | write | system.backup.execute | system |
| aut_self_heal | write | system.backup.execute | system |

## Intent Coverage (Parser)

Supported direct intents include:
- system: backup, update check/install, self-heal
- system: backup, drive sync, calendar sync, cache clear, update check/install, self-heal
- communications: send announcement
- security: diagnose permissions, query capability actors
- directory/entity: profile lookup, attribute lookup, reverse ownership lookup
- contacts: update contact fields/list membership
- people: create/offboard user, role changes, workspace groups, workspace password reset, Metis password reset, MFA reset, password reset clarification
- website: create post draft, publish existing post
- donations: organization giving summary queries

## Hermes Library Inventory

### Context Packs
- system
- backup
- contacts
- people
- permissions
- donations
- communications
- board
- drive
- forms
- inventory
- cms_content
- reports

### Playbooks
- permission_diagnostics
- donation_reconciliation
- announcement_workflows
- board_access_troubleshooting
- content_publishing_workflows
- inventory_verification
- system_health_diagnostics

### Missions
- preparing_board_meetings
- publishing_announcements
- creating_fundraising_pages
- reconciling_monthly_donations
- generating_operational_reports

## Known Gaps / Backlog

- Password handling UX should avoid exposing generated passwords directly in transcript; move to secure one-time reveal flow.
- Subscription-specific query rendering can be improved with dedicated phrasing (e.g., "registered for" response copy).
- Additional secure actions likely needed for full system coverage (imports/exports, module toggles, targeted report generation, cache controls, restore operations).
- Some natural-language variants still map to generic errors and should be captured in parser regression tests.
- Need a central capability matrix that maps each module action to command + permission + playbook support status.

## Next Build Steps

1. Add secure credential handoff path for password resets (masked transcript + one-time reveal endpoint).
2. Expand parser phrase coverage for high-value business questions (fundraising KPIs, newsletter cohorts, volunteer reporting).
3. Add command-level tests for each newly onboarded module action before exposing in Hermes UI.
4. Add approval policy metadata for sensitive fields and bulk operations.
