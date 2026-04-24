# CODEX Directive Checklist Matrix

Last updated: 2026-03-21 (NAS SSH pass)

| Scope | Check | Status | Evidence |
|---|---|---|---|
| Website/Newsletter editor compliance | Newsletter route framework (`newsletter/template_editor`, `newsletter/campaign_editor`) | PASS | `modules/newsletter/module.json`, `modules/newsletter/templates/templates.php`, `modules/newsletter/templates/campaigns.php` |
| Website/Newsletter editor compliance | Newsletter block allowlist enforcement | PASS | `modules/newsletter/assets/newsletter.js`, `modules/newsletter/assets/editor.js` |
| Website/Newsletter editor compliance | Shared editor framework parity (single universal editor path) | PASS | Newsletter editor now prefers `MetisBlockEditor` via adapter bridge, with automatic compatibility fallback to `MebeEditor` for complex legacy docs and safe-palette restriction in universal mode (`modules/newsletter/assets/newsletter.js`, `modules/newsletter/templates/templates.php`, `modules/newsletter/templates/campaigns.php`) |
| Website/Newsletter editor compliance | Universal-safe block parity (`header/footer`) | PASS | Backend newsletter doc compiler now accepts structured `header/footer` fields and universal adapter maps both directions (`modules/newsletter/services/document.php`, `modules/newsletter/assets/newsletter.js`) |
| Website/Newsletter editor compliance | Universal-safe block parity (`social/unsubscribe`) | PASS | Backend newsletter doc compiler now accepts structured `social` and `unsubscribe` fields and universal adapter maps both directions (`modules/newsletter/services/document.php`, `modules/newsletter/assets/newsletter.js`) |
| Website/Newsletter editor compliance | Universal-safe block parity (`video`) | PASS | Universal adapter maps newsletter `video` blocks both directions and universal newsletter block palette/properties panel now support video authoring (`modules/newsletter/assets/newsletter.js`, `assets/js/editor/block-editor.js`, `assets/js/editor/properties-panel.js`) |
| Website/Newsletter editor compliance | Universal-safe block parity (`hero`) | PASS | Backend newsletter doc compiler accepts structured `hero` fields and universal adapter maps hero both directions; universal newsletter palette/properties include hero (`modules/newsletter/services/document.php`, `modules/newsletter/assets/newsletter.js`, `assets/js/editor/block-editor.js`, `assets/js/editor/properties-panel.js`) |
| Website/Newsletter editor compliance | Universal-safe block parity (`columns`) | PASS | Universal adapter maps newsletter `columns` blocks both directions and universal newsletter block palette/properties include columns authoring (`modules/newsletter/assets/newsletter.js`, `assets/js/editor/block-editor.js`, `assets/js/editor/properties-panel.js`) |
| Test coverage | Newsletter mapping round-trip regression test | PASS | Added adapter-focused Node regression test (`tests/modules/newsletter_adapter_roundtrip_test.js`) against shared mapping utility (`modules/newsletter/assets/newsletter-adapter.js`) |
| Test coverage | Newsletter document compile parity smoke test | PASS | Added PHP smoke test for structured newsletter blocks (`tests/modules/newsletter_document_compile_test.php`) validating compile output for `header/footer/social/unsubscribe/hero/video/columns` in `modules/newsletter/services/document.php` |
| Website/Newsletter editor compliance | Control persistence (autosave + explicit save path) | PASS | `modules/newsletter/assets/newsletter.js` |
| Import/reporting reconciliation | Preview include/exclude enforcement for pages/posts | PASS | `modules/import/assets/import.js`, `modules/import/assets/import.ajax.php` |
| Import/reporting reconciliation | `import_jobs` preview/completed lifecycle persistence | PASS | `modules/import/assets/import.ajax.php`, `src/Metis/Modules/Import/SchemaManager.php` |
| Import/reporting reconciliation | Directive-aligned conversion report keys present | PASS | `modules/import/assets/import.ajax.php` |
| Popup/banner/form-host validation | Popup trigger/frequency normalization + runtime trigger support (`click`, `delay`, `load`, `scroll`, `exit`) | PASS | `src/Metis/Modules/Website/Services/PopupService.php`, `src/Metis/Modules/Website/Services/WebsiteRenderer.php` |
| Popup/banner/form-host validation | Banner scheduling + targeting evaluation | PASS | `src/Metis/Modules/Website/Services/BannerService.php`, `src/Metis/Modules/Website/Services/WebsiteRenderer.php` |
| Popup/banner/form-host validation | Forms embed host bridge for website/popup blocks | PASS | `modules/forms/bootstrap.php`, `src/Metis/Modules/Website/Services/BlockRenderer.php` |
| Donation embed validation | Donation form block delegates to live donations embed bridge | PASS | `src/Metis/Modules/Website/Services/BlockRenderer.php`, `modules/donations/bootstrap.php` |
| Donation embed validation | Public/active campaign guard + per-request lookup cache | PASS | `modules/donations/bootstrap.php` |

## Consolidated Result

- Overall pass state: PARTIAL
- No remaining blocker in this matrix pass.
- Residual risk: no remaining block-type gating in universal newsletter mode from this directive pass; remaining risk is browser-level E2E coverage depth rather than missing parity mapping.
- Non-blocking note: this matrix is a code-level validation pass; no automated browser E2E coverage was added in this pass.
