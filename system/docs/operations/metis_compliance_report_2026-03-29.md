# Metis Compliance Report
Date: 2026-03-29
Scope: Non-editor compliance pass against `docs/specifications` with editor scope deferred.

## Summary
- Completed action registration hardening across non-editor module AJAX surfaces.
- Added explicit controller metadata (`module`, `permission`, `nonce_action`) so actions are pre-registered and fail closed when missing.
- Removed duplicate Grandy's Stash action handler registrations to eliminate ambiguity.
- Verified syntax on all changed PHP files.

## Repairs Completed
### Explicit controller registration added/normalized
- settings, forms, newsletter, media, calendar, hermes, import, grandys_stash, profile, drive
- contacts (`contacts.ajax.php`, `imports.ajax.php`, `lists.ajax.php`, `relationships.ajax.php`)
- donations (`campaigns.ajax.php`, `deposits.ajax.php`, `donor_intelligence.ajax.php`, `notes.ajax.php`, `reports.ajax.php`)
- board
- people (`groups`, `jobs`, `mfa`, `people`, `permissions`, `requests`, `roles`, `templates`, `workspace`)
- core/services (`HelpService`, `WalkthroughService`, `StripeImportHandler`)
- `PeopleModule` schema status action metadata

### Duplicate action repair
File: `modules/grandys_stash/assets/grandys_stash.ajax.php`
- Removed duplicate `metis_grandys_stash_create_ticket` registration.
- Removed duplicate `metis_grandys_stash_set_email_pref` registration.
- Kept canonical later definitions to preserve current runtime behavior.

## Permission Alignment Review
- Reviewed action permission assignments against handler guard semantics (`*_ajax_verify(...)`, module `can_view/can_manage` patterns, and admin checks).
- No permission mismatches found in the non-editor scope during this pass.
- `view/edit/delete` labels remain consistent with guard intent for touched modules.

## Smoke Verification
### Static checks
- `php -l` passed for all modified PHP files.

### Registration checks (excluding editor + website deferred file)
- Duplicate AJAX action names: none.
- Handler files without controller registration: none.

Evidence commands:
- Duplicate scan:
  - `rg ... metis_ajax_register_handler ... | sort | uniq -d` => no output
- Coverage scan:
  - every file with `metis_ajax_register_handler(...)` also contains `metis_ajax_register_controller(...)` (excluding deferred website/editor scope)

## Backups
Backups were created on NAS before each repair batch under:
- `/volume1/backups/metis/20260329_203224`
- `/volume1/backups/metis/20260329_203430`
- `/volume1/backups/metis/20260329_203541`
- `/volume1/backups/metis/20260329_203646`
- `/volume1/backups/metis/20260329_203841`
- `/volume1/backups/metis/20260329_203950`
- `/volume1/backups/metis/20260329_204251`
- `/volume1/backups/metis/20260329_204435`
- `/volume1/backups/metis/20260329_204817`

## Deferred / Remaining
- `modules/website/ajax/website.ajax.php` remains deferred because it contains editor-related actions and editor scope is currently excluded.
