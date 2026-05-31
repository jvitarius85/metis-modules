# AJAX Hardening

## Canonical Flow

Client request
-> `Metis.ajax`
-> `system/ajax.php`
-> `RouterRuntime`
-> `AjaxRuntime`
-> Secure Enclave / permission policy
-> module action handler
-> `metis_runtime_send_json_success()` / `metis_runtime_send_json_error()`
-> JSON response with `success`, `message`, `data`, `errors`, `request_id`

## Canonical Request Lifecycle

- Frontend code should use `Metis.ajax.post()` or a thin module wrapper that delegates to it.
- Action nonces must come from the centralized action nonce map.
- Requests must route through `system/ajax.php`.
- Module handlers should register controller metadata with `metis_ajax_register_controller()`.
- Mutation handlers must enforce nonce, method, permission, and input validation.

## Canonical Response Lifecycle

- Success responses are emitted through `metis_runtime_send_json_success()`.
- Error responses are emitted through `metis_runtime_send_json_error()`.
- Responses now include:
  - `success`
  - `message`
  - `data`
  - `errors`
  - `request_id`
- Compatibility fields such as `status` remain in place during this hardening pass.

## Form Builder Campaign Dropdown

Canonical backend source:

- `Metis\Modules\Donations\CampaignService::getActiveCampaignOptions()`

Canonical frontend request path:

- Form Builder admin boot
- or `metis_forms_get` / `metis_forms_dynamic_options`
- through `Metis.ajax`

Filtering rule:

- only active campaigns are returned
- activity is resolved from canonical campaign activity fields present on the table

## Deprecated Patterns

- direct `fetch()` calls to `/api/ajax` from module code
- local nonce assembly in module JS
- raw `window.alert()` / `window.confirm()` fallback
- ad hoc JSON echo paths
- campaign option queries embedded directly in forms logic

## Migration Notes

- `forms.js` now delegates admin AJAX traffic to `Metis.ui.ajax` / `Metis.ajax`
- forms notifications and confirms now route to centralized UI services
- forms campaign option loading now comes from the shared donations campaign service
- `portal.ajax.php` now performs explicit nonce and `portal.view` permission verification
- donations campaign editor AJAX now performs explicit nonce and manage-permission verification
- offline donations AJAX now reuses `CampaignService` for active campaign options and performs explicit nonce/permission verification
- large legacy handler files in Board, Contacts, Donations, Newsletter, People, Portal, Profile, Settings, Website, and core AJAX runtime were reduced to thin controller/handler delegates over canonical services
- view-layer SQL was moved into canonical read services for Board, Contacts, Donations, Newsletter, People, Portal, Profile, and Settings

## Files Changed

- `system/src/Metis/Modules/Donations/CampaignService.php`
- `system/src/Metis/Modules/Forms/Concerns/SharedRepositoryLogic.php`
- `system/src/Metis/Core/Runtime/ResponseRuntime.php`
- `system/assets/core.js`
- `system/modules/forms/assets/forms.js`
- `system/modules/website/assets/website.js`
- `system/modules/portal/assets/portal.ajax.php`
- `system/modules/donations/assets/campaigns.ajax.php`
- `system/modules/donations/assets/offline.ajax.php`

Key service layer additions during the broader pass:

- `system/src/Metis/Core/Services/AjaxCodeLookupService.php`
- `system/src/Metis/Modules/Board/*Service.php`
- `system/src/Metis/Modules/Contacts/*Service.php`
- `system/src/Metis/Modules/Donations/ReadService.php`
- `system/src/Metis/Modules/Donations/StripeDepositService.php`
- `system/src/Metis/Modules/Donations/TransactionRecordService.php`
- `system/src/Metis/Modules/Media/MediaLibraryService.php`
- `system/src/Metis/Modules/Newsletter/ReadService.php`
- `system/src/Metis/Modules/People/ReadService.php`
- `system/src/Metis/Modules/Portal/PortalDashboardService.php`
- `system/src/Metis/Modules/Settings/SettingsTelemetryService.php`
- `system/src/Metis/Modules/Website/Services/EditorOptionsService.php`

## Regression Verification

Canonical local regression command:

- `php tools/governance/run-ajax-ui-hardening-regression.php`

Current regression runner includes:

- `tools/governance/check-ajax-ui-hardening.php`
- `system/tests/ajax_ui_hardening_contract_test.php`
- `system/tests/view_service_delegation_contract_test.php`
- `system/tests/donations_read_service_runtime_test.php`
- `system/tests/newsletter_read_service_runtime_test.php`
- `system/tests/people_read_service_runtime_test.php`
- `system/tests/production_governance_test.php`
- `system/tests/security_governance_test.php`

CI integration:

- `.github/workflows/ajax-ui-hardening.yml` runs the same regression command on `stable`, `main`, pull requests, and manual dispatch

## Closeout Update (May 31, 2026)

- Governance now enforces modal backdrop lifecycle defaults in module views: any `class="metis-modal-backdrop"` with `aria-hidden="true"` must also include `hidden` at rest.
- Public website templates now include skip-link + focusable main-content targets to guarantee keyboard bypass of repetitive navigation.
- Public navigation runtime now preserves focus on open/close and supports escape-close as an enforced governance behavior.
