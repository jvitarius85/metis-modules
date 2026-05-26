# AJAX + UI Hardening Audit

Date: 2026-05-25
Repository: `/Users/jvitarius85/Documents/GitHub/metis`
Branch: `stable`
Initial git status: clean (`git status --short` returned no tracked changes)
PHP: `PHP 8.5.5`
Composer: `composer.json` and `composer.lock` are present

## Baseline

- Central AJAX entrypoint already exists at `system/ajax.php` and routes through `metis_kernel_execute('ajax')`.
- Core request enforcement already exists in `system/src/Metis/Core/Ajax/AjaxRuntime.php`, `system/src/Metis/Core/Routing/RouterRuntime.php`, and `system/src/Metis/Core/Runtime/ResponseRuntime.php`.
- Shared frontend services already exist in `system/assets/core.js` for AJAX, toast, modal, and confirm behavior, but they were not consistently consumed by modules.

## AJAX Endpoints

Primary routed entrypoint:

- `system/ajax.php`

Module-local AJAX handler files discovered:

- `system/modules/board/assets/board.ajax.php`
- `system/modules/calendar/assets/calendar.ajax.php`
- `system/modules/contacts/ajax/carddav.ajax.php`
- `system/modules/contacts/ajax/contacts.ajax.php`
- `system/modules/contacts/ajax/exports.ajax.php`
- `system/modules/contacts/ajax/imports.ajax.php`
- `system/modules/contacts/ajax/lists.ajax.php`
- `system/modules/contacts/ajax/relationships.ajax.php`
- `system/modules/contacts/assets/contacts.ajax.php`
- `system/modules/donations/assets/campaigns.ajax.php`
- `system/modules/donations/assets/deposits.ajax.php`
- `system/modules/donations/assets/donations.ajax.php`
- `system/modules/donations/assets/donor_intelligence.ajax.php`
- `system/modules/donations/assets/notes.ajax.php`
- `system/modules/donations/assets/offline.ajax.php`
- `system/modules/donations/assets/recurring.ajax.php`
- `system/modules/donations/assets/reports.ajax.php`
- `system/modules/drive/assets/drive.ajax.php`
- `system/modules/finance/assets/finance.ajax.php`
- `system/modules/forms/assets/forms.ajax.php`
- `system/modules/grandys_stash/assets/grandys_stash.ajax.php`
- `system/modules/hermes/assets/hermes.ajax.php`
- `system/modules/import/assets/import.ajax.php`
- `system/modules/media/assets/media.ajax.php`
- `system/modules/newsletter/assets/newsletter.ajax.php`
- `system/modules/people/ajax/auth.ajax.php`
- `system/modules/people/ajax/groups.ajax.php`
- `system/modules/people/ajax/jobs.ajax.php`
- `system/modules/people/ajax/mfa.ajax.php`
- `system/modules/people/ajax/people.ajax.php`
- `system/modules/people/ajax/permissions.ajax.php`
- `system/modules/people/ajax/requests.ajax.php`
- `system/modules/people/ajax/roles.ajax.php`
- `system/modules/people/ajax/templates.ajax.php`
- `system/modules/people/ajax/workspace.ajax.php`
- `system/modules/people/assets/people.ajax.php`
- `system/modules/portal/assets/portal.ajax.php`
- `system/modules/profile/assets/profile.ajax.php`
- `system/modules/settings/assets/settings.ajax.php`
- `system/modules/website/ajax/website.ajax.php`
- `system/modules/website/assets/website.ajax.php`

## Endpoint Risk Categorization

Safe:

- `system/ajax.php`
- `system/modules/forms/assets/forms.ajax.php`
- modules already using `metis_ajax_register_controller()` plus explicit nonce and permission checks

Partially safe:

- module AJAX files that register through central runtime but still parse request payloads locally or return ad hoc response shapes

Unsafe / governance drift:

- module files with handler registration but inconsistent controller metadata
- module files with direct SQL embedded in handler files
- frontend code paths still using `window.alert()` or `window.confirm()` fallback

Deprecated patterns observed:

- duplicate request wrappers in module JS instead of `Metis.ajax`
- module-local toast/modal helpers instead of centralized services
- ad hoc SQL-backed option loading instead of shared services

## Direct Superglobal Usage

Non-test/runtime-approved direct superglobal reads still exist, primarily:

- request URI / method reads in runtime, routing, and security bootstrap code
- direct `$_SERVER` access in a limited number of enclave and tooling entrypoints
- direct request parsing in some legacy module handlers

Governance direction already documented in `system/docs/governance/production-governance.md`:

- `RequestRuntime.php` is the approved request boundary
- new business logic should not read request superglobals directly

## Raw SQL Hotspots

High concentration areas:

- module AJAX files under `system/modules/*/assets/*.ajax.php`
- module views that combine rendering and data access under `system/modules/donations/views/*.php`
- maintenance / migration scripts under `system/tools/*.php`

Observed forms-specific hotspot before repair:

- `system/src/Metis/Modules/Forms/Concerns/SharedRepositoryLogic.php`
  - campaign option query assumed non-canonical columns (`campaign_code`, `code`)
  - failure mode was an empty campaign option list in the Form Builder

## Duplicate Functions / Helper Drift

Repeated helper shapes were found across modules:

- `toast()`
- `openModal()` / `closeModal()`
- request wrappers (`request()`, `adminRequest()`, `post()`)
- local confirmation wrappers

This is a maintainability risk even when behavior is equivalent.

## Modal Implementations

Canonical system already exists:

- `Metis.modal`
- `Metis.confirm`
- `window.metis_confirm`

Duplicated implementations still exist in module-local code, especially:

- settings media selector modal
- media library modal flow
- people prompt modal

## Toast Implementations

Canonical system already exists:

- `Metis.toast`
- `window.metis_toast`

Duplicated / drifting usage still exists in module-local code, including:

- media library module-local toast implementation
- legacy website admin wrappers
- module-local success/error wrappers in several views

## Dropdown Implementations

Observed dropdown patterns:

- native `<select>` rendering in views
- module-local rich editor dropdown logic in forms/newsletter
- pill dropdown behavior in `system/assets/core.js`

No single canonical dropdown population helper was in use for Form Builder campaign option loading before this pass.

## Directly Executable PHP Files

Primary executable web/runtime entrypoints:

- `index.php`
- `system/ajax.php`
- `system/cron.php`
- `system/shell.php`
- `system/webhooks.php`
- `system/enclave/help/*.php`

Governance note:

- module `*.ajax.php` files are loaded as handler registries, not intended as direct web entrypoints

## Centralized Request Handlers

- `system/ajax.php`
- `system/src/Metis/Core/Ajax/AjaxRuntime.php`
- `system/src/Metis/Core/Routing/RouterRuntime.php`
- `system/src/Metis/Core/Runtime/ResponseRuntime.php`
- `system/src/Metis/Core/Security/SecurityRuntimeBridge.php`

## Centralized UI Helpers

- `system/assets/core.js`
  - `Metis.ajax`
  - `Metis.toast`
  - `Metis.modal`
  - `Metis.confirm`

## Root Cause: Form Builder Campaign Dropdown

Root cause identified:

- Form Builder admin boot options came from `FormDefinitionRepository::adminOptions()`
- campaign retrieval flowed through `SharedRepositoryLogic::campaignOptions()`
- that query selected non-canonical columns (`campaign_code`, `code`) that are not guaranteed on the donations campaign table
- on installations where those columns are absent, the query path returns no usable campaign options

Repair direction adopted:

- create one canonical donations campaign service
- query only columns that actually exist
- filter only active campaigns using canonical activity/status fields
- reuse that service from the forms repository

## Files Changed In This Pass

- `system/src/Metis/Modules/Donations/CampaignService.php`
- `system/src/Metis/Modules/Forms/Concerns/SharedRepositoryLogic.php`
- `system/src/Metis/Core/Runtime/ResponseRuntime.php`
- `system/assets/core.js`
- `system/modules/forms/assets/forms.js`
- `system/modules/website/assets/website.js`
- `system/modules/portal/assets/portal.ajax.php`
- `system/modules/donations/assets/campaigns.ajax.php`
- `system/modules/donations/assets/offline.ajax.php`

## Remaining Risks

- many module AJAX handlers still use local validation and local response assumptions
- duplicate modal and toast logic still exists outside the forms path
- donations views still mix SQL and rendering
- the governance checker added in this pass is heuristic and intended to surface drift, not replace code review

## Continuation Pass Delta

Additional hardening completed after the first report:

- removed module-local website toast/confirm shim definitions from `system/modules/website/assets/website.js`
- added explicit nonce and permission validation to `system/modules/portal/assets/portal.ajax.php`
- added explicit nonce and permission validation to `system/modules/donations/assets/campaigns.ajax.php`
- switched `system/modules/donations/assets/offline.ajax.php` to the canonical donations campaign service and added explicit nonce/permission validation

Governance checker delta after the continuation pass:

- `website.js` no longer reports duplicate modal/toast system definitions
- `portal.ajax.php`, `campaigns.ajax.php`, and `offline.ajax.php` no longer report missing permission validation
