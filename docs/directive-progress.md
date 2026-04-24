# CODEX Directive Progress (Locked)

This tracker is used to prevent repeated implementation loops.

## Lock Rules
- Items marked `DONE` are frozen unless a regression is reported.
- New chunks must select only `PENDING` items.
- Each chunk updates this file with net-new changes only.

## Current Status

### DONE
- Finance dashboard now uses shared sidebar-first layout (Settings-style placement).
- Donations dashboard now uses shared sidebar-first layout (Settings-style placement).
- Finance inline gray section labels were replaced with shared inset section headers.
- Finance “What Needs Attention” no longer uses ad-hoc gray date meta label.
- Waiting-to-settle section uses stable grid row/header table layout.
- Standalone Media Library page exists and is admin-routable via Media module.
- Standalone Media Library UI now uses shared admin controls (`mw-page-header`, `mw-input`, `mw-select`).
- Media Library includes folder/category organization controls.
- Website block registry includes extended required block definitions (compliance baseline list).
- Website block renderer now has explicit donation/campaign block renderers:
  - `donation_form_block`
  - `progress_bar_block`
  - `campaign_description_block`
  - `donation_goal_summary_block`
  - `donor_wall_block`
  - `impact_metrics_block`
  - `countdown_block`
  - `anchor_block`
- Website/Page/Post/Popup editor now loads block palette definitions dynamically from PHP block registry (`metis_website_block_registry`) with JS fallback.
- Newsletter editor now enforces an explicit allowlist of newsletter-safe blocks (UI-configurable via page data) so website-only blocks cannot appear.
- Newsletter editor routes now support friendly module views for editing:
  - `newsletter/template_editor`
  - `newsletter/campaign_editor`
  while preserving legacy query fallback.
- Finance waiting-to-settle table now preserves tabular columns at all viewport sizes (horizontal scroll fallback instead of collapsing into stacked rows).
- Finance/Donations premium headers now have module-level no-shift hover enforcement.
- Import preview now supports per-item correction/approval before confirm:
  - page-level include/exclude
  - post-level include/exclude
  with selected IDs enforced server-side before draft creation.
- Popup list trigger labeling now includes `scroll` trigger type parity with renderer/service support.
- ImportService approve flow is no longer a stub; it now performs real draft entity creation (pages/posts/menus) and writes import results into `conversion_report_json`.
- Added Forms-module embed bridge `metis_forms_render_embed()` so website/popup form blocks can host real published forms via controlled public form URLs instead of placeholder-only rendering.
- ImportService conversion reporting now records directive-aligned fields (`source_type`, `items_parsed`, `items_converted`, `unsupported_modules`, `fallback_html_blocks`, `broken_media_references`, `warnings`, `errors`).
- Import AJAX workflow now persists `import_jobs` rows:
  - job created at parse with `preview` status and preview/report JSON
  - job finalized at confirm with `completed` status and `import_results` in conversion report.
- Backup service media archive/restore paths now use `storage/media` (with backward-compatible restore from legacy `storage/uploads` archives).
- Settings General now uses centralized Media Library selection (logo + favicon) instead of raw file inputs; saves structured media token/url metadata while preserving legacy upload fallback compatibility.
- Settings Customization login media fields (login logo + login background image) now use centralized Media Library selection instead of raw file inputs; saves structured media token/url metadata while preserving legacy upload fallback compatibility.
- Website dashboard quick actions now open page/post editors dynamically (inline builder launch) instead of redirect-only links.
- Website dashboard now includes quick-edit buttons for recent pages/posts to reduce navigation hops.
- Removed stale dead template artifact `modules/donations/templates/.afpDeleted737723` as part of scoped cleanup.
- Donation embed bridge hardening pass completed:
  - now rejects inactive campaigns in addition to non-public campaigns
  - adds request-local campaign lookup cache to avoid duplicate campaign queries per render pass
- Consolidated directive validation pass executed and recorded in `docs/directive-checklist-matrix.md`.
- Import/reporting checklist reconciliation completed and captured in the directive matrix.
- Popup/banner targeting/frequency/form-host validation pass completed and captured in the directive matrix.
- Newsletter editor runtime now prefers the universal block editor (`MetisBlockEditor`) with a compatibility adapter that converts newsletter doc JSON to/from editor blocks, while retaining fallback to legacy `MebeEditor`.
- Newsletter template/campaign editor views now explicitly load universal block editor runtime (`/assets/js/editor/block-editor.js`) for shared framework parity.
- Newsletter adapter hardening now auto-selects legacy `MebeEditor` for complex legacy doc block types (columns/hero/video/header/footer/etc.) to prevent lossy saves during migration.
- Universal newsletter adapter now enforces a backend-safe block palette (`heading`, `text`, `button`, `image`, `spacer`, `divider`) to prevent creating unsupported doc shapes before server-side compile parity is implemented.
- Newsletter backend document compiler now supports structured `header/footer` block payloads (logo/address/branding fields) in addition to raw HTML, enabling safer universal round-trip for those block types.
- Universal newsletter safe palette expanded to include `header` and `footer` after backend compile parity landed.
- Newsletter backend document compiler now supports structured `social` and `unsubscribe` block payloads.
- Universal newsletter safe palette expanded to include `social` and `unsubscribe` after backend compile parity landed.
- Universal newsletter editor now supports `video` block round-trip (adapter + block palette + context-aware properties panel), and `video` was removed from fallback-only gating.
- Universal newsletter editor now supports `hero` block round-trip (adapter + block palette + context-aware properties panel), and `hero` was removed from fallback-only gating.
- Universal newsletter editor now supports `columns` block round-trip (adapter + block palette + properties panel), and `columns` was removed from fallback-only gating.
- Added dedicated adapter utility module (`modules/newsletter/assets/newsletter-adapter.js`) and Node-based round-trip regression test (`tests/modules/newsletter_adapter_roundtrip_test.js`) covering supported newsletter block mappings.
- Newsletter template/campaign editor now includes shared editor runtime URLs in page UI payload and client-side asset fail-safe loading/retry, resolving shared-editor boot failures in module views.
- Added and executed newsletter document compile smoke test (`tests/modules/newsletter_document_compile_test.php`) validating structured compile parity for `header/footer/social/unsubscribe/hero/video/columns`.

### IN PROGRESS
- Complete Finance/Donations shared-sidebar parity validation against Settings navigation language in live pages.

### PENDING
- Run focused browser end-to-end regression QA across newsletter editor engines (universal and legacy fallback) for save/reload/render parity.
