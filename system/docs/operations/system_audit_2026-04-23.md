# Metis System Audit

Date: 2026-04-23
Workspace: `/Users/jvitarius85/Library/CloudStorage/CloudMounter-TheHRDeptNAS/Web/metis`

## Scope

- Route, API, and AJAX registration/resolution audit
- Email dispatch path audit
- Secure enclave / policy coverage audit
- WordPress runtime dependency audit
- Backup checkpoints to `/Volumes/NAS/backups 2/metis/snapshots/`
- Archive checkpoints to `/Volumes/NAS/backups 2/metis/archives/`

## Backup Checkpoints

- Baseline snapshot: `20260423-190819`
- Post-fix snapshot: `20260423-191654`
- Final audit snapshot: `20260423-193339`
  - Exclusions: `.github/`, `.metis-integrity/`, and `storage/` were excluded from this final code snapshot because CloudMounter returned repeated timeout errors on unreadable mounted artifacts in those trees
- Final audit archive: `metis-code-audit-20260423-193625.tar.gz`
  - SHA-256: `f19622b16fa40439f90f6a72ef7cbcbc5824f0d4475b42c78a17bdebcc420213`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
  - Default archive helper: `tools/archive_backup.sh`
- Modernization archive: `metis-code-modernization-20260423-194546.tar.gz`
  - SHA-256: `dcb403132014e293dee916f328e190bca1250fd33bdbabd196f4cd198a36743b`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-modernization-20260423-195135.tar.gz`
  - SHA-256: `46e02f878d6867e5bce777d92f713a494194e3ae24f14d1458e2b68711ca80ec`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-195603.tar.gz`
  - SHA-256: `6cd8611c5bc7cf89ff7657aecb608aff19ab8cad2bfee1b029843c21d29a3f0f`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-195732.tar.gz`
  - SHA-256: `fee5384e0480a1341f7ef24b7c687b61aaae104666d8316b53b00b07a0add6f4`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-200310.tar.gz`
  - SHA-256: `c4975ed9776a9c63e1e305029c8243d896f17e0f6e7336e77a548a3cbf389082`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-200752.tar.gz`
  - SHA-256: `dc7cfbf8e9ecf4eaa805313a07a2320a1bd1ac13105785daf90680df13b174a4`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-201048.tar.gz`
  - SHA-256: `b2444f879a21cad525525dede8df2a17119ddea69c6c5adc220e1561b53604cb`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-201302.tar.gz`
  - SHA-256: `649f771a6541a07c97a6315baa6949120ee3b7d4bb54b52198e0883394a0499c`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-201522.tar.gz`
  - SHA-256: `0d1f9ebda6849b8db8fbbacad23a2ac931befd9ca61b0b36300b599fe0267369`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-202025.tar.gz`
  - SHA-256: `35162150d1a6e9f546920cd2c8435f63fe41c0258661e456ff901bdee57a8d96`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
- Modernization archive: `metis-code-20260423-202531.tar.gz`
  - SHA-256: `b22b5511d5f36f72c6d6c2edfe87bd38223402cd6e8f754bd1373966ad9ee957`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
  - Archive note: direct tar writes to the NAS hit `Stale NFS file handle`, so this checkpoint was built locally first and then copied to the NAS as a single archive file
- Modernization archive: `metis-code-20260423-203351.tar.gz`
  - SHA-256: `8b561e26737bf025c1716bc009b8c04e16990b21bd3c26fa0de042efcf56bdf6`
  - Exclusions: `.git/`, `.github/`, `.metis-integrity/`, `node_modules/`, `vendor/`, and `storage/`
  - Archive note: built locally and copied to the NAS as a single archive file to avoid mounted-filesystem tar write instability

## Automated Checks

- `php tools/module_compliance.php verify`
  - Result: pass
  - Modules checked: 17
- `php tools/test_suite.php --json`
  - Result: pass
  - Tests passed: 2 / 2
- `php tools/security_audit.php --internal --json`
  - Result: pass for current runtime audit
  - Legacy deleted tests are now marked skipped instead of failing the audit harness
- `php tools/integrity.php verify`
  - Result: pass
  - Status: `signature_not_required`
  - Verified files: 1157
- `php tools/integrity.php baseline audit_2026-04-23-pass4`
  - Result: pass
  - Manifest and recovery snapshot tree were written successfully

## Findings

### Resolved

- Removed active WordPress runtime calls from application code paths:
  - `wp_rand`
  - `wp_strip_all_tags`
  - `has_action`
  - `do_action`
- Removed an accidental duplicate runtime file: `src/Metis/Core/Runtime/HooksRuntime 2.php`
- Added neutral Metis-native aliases for WordPress-shaped helper APIs so new code can target non-WordPress names.
- Added `tests/system_audit_test.php` to validate:
  - module load success
  - manifest route handler validity
  - AJAX controller to handler coverage
  - AJAX controller to enclave policy coverage
  - client-referenced AJAX actions are registered
  - core API endpoints resolve without 404
  - direct raw `mail()` usage stays confined to the runtime email service layer
- Routed newsletter test sends through `Metis\Core\Services\EmailService` instead of calling the provider helper directly.
- Added an audit guard that fails if module code calls `metis_newsletter_gmail_send()` outside the shared email service.
- Modernized a first tranche of shared runtime files to the Metis-native helper aliases, including core navigation/rendering, profile dashboard rendering, email/maintenance services, assets/runtime wiring, entity catalog, service registry, permissions, cron wiring, and integrity alert rendering.
- Extended that modernization guard into manager/shell rendering, AJAX dispatch, security runtime bridge, and the auth runtime shell/request normalization paths.
- Extended the modernization guard further into standalone bootstrap, request nonce dispatch, accessibility profile loading, auth protection rate-limit/failure keys, and progressive delay subject normalization.
- Modernized the batch API controller and batch validator so route attributes, payload action/module reconciliation, nonce inputs, schema field keys/types, and email validation all route through the Metis-native helper aliases.
- Modernized the router/module-loader layer, audit logging runtime, and navigation service so shared route matching, portal and AJAX request normalization, asset dispatch, module manifest normalization, audit payload normalization, and navigation module-key/category handling now route through the Metis-native helper aliases.
- Modernized editor block registry/context policy, Stripe webhook header normalization, CSRF field rendering/token extraction, and logger request-token parsing so those shared request-facing files now route through the Metis-native helper aliases.
- Modernized the remaining shared editor block renderers plus Stripe import error routing, credential registry normalization, walkthrough AJAX error normalization, and behavior-profiler operation keys so those runtime-facing leaf files now route through the Metis-native helper aliases.
- Modernized kernel webhook/auth-method normalization, rename-table maintenance request/output handling, and operations-service command parsing/dedupe keys so those shared operational runtime paths now route through the Metis-native helper aliases.
- Modernized scheduler task normalization, help-service AJAX error normalization, module-validator category/default-parent sanitization, quick-actions registry normalization, and release-execution audit action keys so those remaining support-layer files now route through the Metis-native helper aliases.
- Fixed the invalid `metis_status_header()` call path in router/setup response emission and modernized error logger, webhook runtime, security context, and threat-score key normalization so webhook and error-handling runtime paths no longer depend on missing or WordPress-shaped helper symbols.
- Added an audit guard for those modernized shared files so legacy helper names now fail the system audit if reintroduced.
- Modernized the remaining non-compatibility tail files, including database maintenance request matching, upload policy/category normalization, GitHub update cache/ref normalization, system version failure slugs, and Hermes website/user/directory/definition/contact admin services so those scattered support paths now route through the Metis-native helper aliases as well.
- Extended the system audit guard to cover those remaining support-layer files so the cleanup tail now fails the audit if legacy helper calls are reintroduced.
- Restored `tests/system_audit_test.php` into the current worktree so the runtime route/AJAX/enclave/email audit is enforced again instead of silently skipping.
- Modernized the next module-layer tranche, including media, Hermes, and calendar AJAX handlers plus communications-inbound message normalization, mailbox settings, Workspace Google token validation, and newsletter delivery/manage/public routes so those request-facing module paths now route through the Metis-native helper aliases or bootstrap-safe fallbacks.
- Modernized the remaining active board AJAX runtime plus the inbound/newsletter support tail, including board packet recipient validation, meeting/detail/action/template/Drive/calendar request normalization, Drive upload/copy filenames, packet PDF/link rendering, packet publish email escaping, mailbox email normalization, newsletter queue text/pixel rendering, and newsletter shell attribute escaping so those request-facing module paths now route through the Metis-native helper aliases as well.
- Extended the system audit guard to cover `MailboxRepository`, `Newsletter\\QueueService`, `Newsletter\\Support`, and `modules/board/assets/board.ajax.php` so these module-layer paths now fail the runtime audit if legacy helper calls are reintroduced.
- Modernized the remaining board UI/view layer, including `modules/board/views/dashboard.php`, `modules/board/meeting.php`, and `modules/board/views/meeting.php`, so board dashboard rendering, meeting workflow screens, packet/decision/attendance/action UI, and selected/checked attribute rendering now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those board UI/view files so the remaining board presentation layer now fails the runtime audit if legacy helper calls are reintroduced.
- Refactored the compatibility layer itself so escaping, selected/checked attributes, status emission, and shortcode handling are now implemented by Metis-native functions first, with the WordPress-shaped names reduced to thin compatibility shims. Also migrated `DonationsModule` to the Metis-native shortcode and escaping helpers so that active shortcode registration and rendering no longer depend on the WordPress-shaped APIs.
- Extended the system audit guard to cover `src/Metis/Modules/Donations/DonationsModule.php` so that migrated shortcode path now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized a finance/portal tranche, including `modules/finance/views/finance.php`, `modules/portal/assets/portal.ajax.php`, `modules/portal/views/email_usage.php`, and the newsletter/workspace/settings portions of `modules/portal/views/settings.php`, so those finance shell, board-action hub, email usage, and settings management surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those finance/portal files so the newly modernized tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized `src/Metis/Modules/Website/Services/BlockRenderer.php` so website block rendering now routes key normalization and output escaping through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover `src/Metis/Modules/Website/Services/BlockRenderer.php` so this website rendering path now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized `src/Metis/Modules/Website/Services/WebsiteRenderer.php` so website document rendering, structured sections, metadata output, and public template UI now route key normalization, slug cleaning, text normalization, and output escaping through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover `src/Metis/Modules/Website/Services/WebsiteRenderer.php` so this larger website rendering surface now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the remaining website editor/theme tranche, including `modules/website/ajax/website.ajax.php`, `modules/website/views/theme.php`, `modules/website/views/pages.php`, `src/Metis/Modules/Website/Services/ThemeService.php`, and `src/Metis/Modules/Website/Services/WebPartService.php`, so website editor actions, theme configuration UI, page-list rendering, theme token normalization, and web-part targeting/rendering now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Removed direct WordPress runtime fallbacks from that website tranche by dropping `get_userdata()` fallback usage in `website.ajax.php` and `get_bloginfo()` fallback usage in `ThemeService.php`.
- Extended the system audit guard to cover those website editor/theme files so this broader website management surface now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized `src/Metis/Modules/Finance/FinanceV2Service.php` so Finance V2 category, GL, reconciliation, payout, invoice, and report request normalization plus invoice/report HTML rendering now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover `src/Metis/Modules/Finance/FinanceV2Service.php` so this larger finance service surface now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized `modules/settings/views/_settings_bootstrap.php` so settings route parsing, sidebar rendering, media/token normalization, theme/login color handling, email/workspace/communications validation, runtime/job settings parsing, and developer/system settings inputs now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover `modules/settings/views/_settings_bootstrap.php` so this shared settings bootstrap surface now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the first People-heavy tranche, including `modules/people/views/person.php`, `modules/people/views/workspace.php`, and `modules/people/ajax/people.ajax.php`, so person/workspace UI rendering, People AJAX request normalization, MFA/workspace settings parsing, and People role/position/email handling now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those People files so this first People UI/AJAX surface now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the next People/settings tranche, including `modules/people/ajax/workspace.ajax.php`, `modules/people/ajax/groups.ajax.php`, `modules/people/views/dashboard.php`, and the denser settings subviews (`scheduler`, `developers_api`, `general`, `logging`, `newsletter`, `runtime`, `customization`), so those People workspace/group flows and settings view surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those People/settings files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the next Contacts/Hermes tranche, including `modules/contacts/views/contact.php`, `modules/contacts/ajax/contacts.ajax.php`, `modules/contacts/views/dashboard.php`, `modules/hermes/views/dashboard.php`, plus the remaining smaller People list/detail views (`modules/people/views/role.php`, `modules/people/views/people_list.php`), so those contact, Hermes, and People presentation/AJAX surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those Contacts/Hermes/People files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the next donations/newsletter tranche, including `modules/donations/views/dashboard.php`, `modules/newsletter/views/theme.php`, and `modules/newsletter/assets/newsletter.ajax.php`, so those dashboard/theme/newsletter request and rendering surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those donations/newsletter files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the next finance/forms/portal tail, including `modules/finance/assets/finance.ajax.php`, `src/Metis/Modules/Forms/Concerns/SharedRepositoryLogic.php`, `src/Metis/Modules/Forms/FormRenderer.php`, `modules/portal/views/dashboard.php`, `modules/newsletter/views/campaigns.php`, `modules/newsletter/views/dashboard.php`, `modules/newsletter/views/lists.php`, and `modules/donations/views/campaign.php`, so those remaining request/rendering surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those finance/forms/portal/newsletter/donations files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the next finance/settings tail, including `modules/finance/views/ledger.php`, `modules/finance/views/reports.php`, `modules/finance/views/reconciliations.php`, `modules/portal/views/settings.php`, `modules/settings/views/about.php`, `modules/settings/views/menu.php`, and `modules/donations/assets/reports.ajax.php`, so those remaining finance/settings/donations request and rendering surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those finance/settings/donations files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Isolated the remaining WordPress-shaped compatibility wrappers into `src/Metis/Core/Runtime/LegacyCompatibilityRuntime.php`, and removed those wrapper definitions from `SanitizationRuntime`, `ResponseRuntime`, and `HooksRuntime` so the main runtime is now Metis-native first.
- Updated the runtime audit to recognize `LegacyCompatibilityRuntime.php` as the explicit compatibility boundary while continuing to fail modernized shared paths if legacy helper names are reintroduced.
- Modernized another active-code tail tranche, including `src/Metis/Modules/Website/Services/StructuredWebsiteBuilderService.php`, `src/Metis/Modules/Finance/FinanceService.php`, `src/Metis/Modules/GrandyStash/GrandyStashRepository.php`, `modules/website/views/posts.php`, `modules/website/views/dashboard.php`, `modules/website/views/categories.php`, `modules/drive/assets/drive.ajax.php`, `modules/profile/assets/profile.ajax.php`, `modules/people/views/bulk_actions.php`, `modules/people/views/access_requests.php`, and `modules/people/views/roles_list.php`, so those remaining website/finance/drive/profile/people request and rendering surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover that tranche so those newly modernized active paths now fail the runtime audit if legacy helper calls are reintroduced.
- Modernized the next scattered tail tranche, including newsletter audit/tracking services, import AJAX/service slugs, settings AJAX request normalization, forms builder bootstrap, donations campaign/note/donor-intelligence handlers, donations batch/deposit/donor/report views, and the website editor/menus/media/redirects/default-template/routes surface, so those remaining request and rendering paths now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover that tranche so those additional module/template paths now fail the runtime audit if legacy helper calls are reintroduced.
- Modernized the next live UI tail tranche, including the forms dashboard/detail/settings/entries views, newsletter subscribers/editor views, settings profile/cache/drive views, and website popups view, so those remaining rendering and UI bootstrap paths now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those forms/newsletter/settings/website files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the next peripheral tail tranche, including donations campaign/deposit/donor detail views and settings accessibility/help/workspace/payments/operations/jobs-tasks views, so those remaining rendering and configuration surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those donations/settings files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Modernized the next website/settings/donations tail tranche, including website templates/banners/import views, settings backup/calendar/checker/user-experience/logging/runtime views, and donations transaction/transactions/report JS bootstrap paths, so those remaining rendering and JS bootstrap surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover those website/settings/donations files so that tranche now fails the runtime audit if legacy helper calls are reintroduced.
- Re-ran the compatibility boundary scan after that tranche and confirmed the shim file cannot be safely reduced yet because meaningful legacy helper usage still remains in People, Drive, Grandy's Stash, imports, and disabled website template surfaces outside the already-guarded core.
- Modernized the next People/Drive/Import/Grandy's Stash tranche, including People activity/permissions/templates views, People access/permissions/roles AJAX handlers, Drive dashboard view, Import dashboard view, Grandy's Stash dashboard/report/settings/ticket views, plus `HermesOperationalEngine` and `AuthSessionManager`, so those remaining live request/rendering/auth surfaces now route through the Metis-native helper aliases instead of the WordPress-shaped names.
- Extended the system audit guard to cover that People/Drive/Import/Grandy's Stash/Auth/Hermes tranche so those paths now fail the runtime audit if legacy helper calls are reintroduced.
- Updated `tools/security_audit.php` so stale deleted test paths no longer make the audit report misleading.

## Audit Status

Overall status: pass

The runtime route/AJAX/enclave/email wiring passes the audit coverage, the WordPress-shaped compatibility layer has been removed from the runtime, and integrity baseline generation verifies cleanly for the current repository layout.

## Latest Checkpoint

- Archive: `/Volumes/NAS/backups 2/metis/archives/metis-code-20260423-220703.tar.gz`
- SHA-256: `490f8b3ddcb19c92afb7415eb014b469670f0d9c8edd72267065d0c5eafd08d9`
- Verification:
  - `php tests/system_audit_test.php`
  - `php tools/test_suite.php --json`
  - `php tools/security_audit.php --internal --json`
  - `php tools/integrity.php verify`
  - Result: pass

## Latest Pass

- Modernized the remaining readable live-code tail outside the previously guarded surface, including `src/Metis/Http/Router.php`, `src/Metis/Backup/BackupService.php`, `src/Metis/Core/Auth/AuthRuntime.php`, `src/Metis/Core/Cron/CronRuntime.php`, the Calendar/Board/Forms/People/Finance service layer, `modules/contacts/ajax/*`, `modules/forms/bootstrap.php`, `modules/donations/bootstrap.php`, `modules/calendar/views/dashboard.php`, `modules/settings/assets/settings.ajax.php`, `modules/settings/views/_settings_bootstrap.php`, `modules/portal/views/settings.php`, and the remaining website/newsletter service cluster (`TemplateService`, `ReusableBlockService`, `LayoutProfileService`, `BannerService`, `PopupService`, `PostCategoryService`, `RevisionTimelineService`, `PageService`, `PostService`, `WebsiteRenderer`, `newsletter.ajax.php`).
- Replaced the remaining readable `esc_url_raw()` call sites in active code with `metis_url_clean()` and updated the runtime audit so `esc_url_raw()` is now treated as a legacy compatibility helper in guarded files.
- Extended `tests/system_audit_test.php` to guard the newly modernized router, backup, calendar, board, forms, website-service, contacts, donations, tools, and bootstrap surfaces against reintroduction of legacy helper calls.
- Reduced the readable live-code legacy-helper scan to the explicit compatibility boundary plus a small set of files that could not be scanned because the mounted workspace returned repeated timeout errors.

## Current Residual Debt

- A few workspace paths still could not be scanned during the repo-wide grep because the mounted filesystem returned `Operation timed out`, specifically:
  - `src/Metis/Core/Editor/CoreBlockRegistry.php`
  - `src/Metis/Core/EditorCoreRegistry.php`
  - `src/Metis/Modules/Website/Services/EditorContextService.php`
  - `modules/finance/entities/finance_event.entity.json`
- The disabled website templates under `modules/website/Templates/-disabled/` were also normalized onto Metis-native escaping helpers in this pass, but they remain disabled assets rather than active runtime surfaces.

## Final Pass

- Removed `src/Metis/Core/Runtime/LegacyCompatibilityRuntime.php` from the runtime and from the load path in `src/Metis/Core/Runtime/StandaloneRuntime.php`, so Metis no longer boots a WordPress-shaped compatibility shim.
- Drained the remaining readable slug/filename/color helper tail by replacing `sanitize_title`, `sanitize_title_with_dashes`, `sanitize_file_name`, `sanitize_hex_color`, and `get_bloginfo('charset')` usage with Metis-native equivalents across the website, forms, finance, upload, auth, webhook, HTTP response, and settings surfaces.
- Normalized the disabled website template set under `modules/website/Templates/-disabled/` from `esc_attr()` to `metis_escape_attr()` so those inactive assets no longer advertise WordPress-shaped helper usage either.
- Updated the runtime audit to stop expecting a compatibility boundary file and re-verified the full suite after the shim removal.

## Latest Checkpoint

- Archive: `/Volumes/NAS/backups 2/metis/archives/metis-code-20260423-222614.tar.gz`
- SHA-256: `7c3639de618cdc8b844da35ad9061bd2f6063305108d04d6f7e1f61703b1a8b7`
- Verification:
  - `php tests/system_audit_test.php`
  - `php tools/test_suite.php --json`
  - `php tools/security_audit.php --internal --json`
  - `php tools/integrity.php verify`
  - Result: pass
