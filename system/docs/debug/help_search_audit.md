# Help Search Audit

Date: 2026-04-23
Workspace: `metis`

## First-pass finding

The existing Help Search UI was rendering, but it was not built on the required production path.

Primary root causes identified before rebuild:

1. The search request path was wrong for the new requirement.
   - Existing frontend: `assets/js/help/help-search.js`
   - Existing call path: legacy AJAX action `metis_help_search`
   - Required path: `/system/enclave/help/search.php`

2. The search source was not normalized or indexed in MySQL.
   - Existing backend: `src/Metis/Core/HelpService.php`
   - Existing search behavior: in-memory scan across `docs/help-index.json`, `docs/walkthroughs.json`, and markdown files
   - No `help_articles`, `help_categories`, or `help_search_index` tables existed in MySQL at the start of this task

3. The UI implementation diverged from the stated standards.
   - Modal markup was assembled inline in JS instead of binding to a rendered container
   - Search result cards used legacy help-search classes and styling from `assets/core.css`
   - Existing help panel action buttons used inline CSS (`style=\"display:none;\"`)

4. Empty-state handling was weak.
   - Legacy search returned `No matching help content.` for an empty query instead of providing a seeded fallback or popular content
   - With no normalized DB layer, there was no durable article inventory to guarantee non-empty search on a healthy install

5. Standalone verification was blocked by an unrelated integrity runtime defect.
   - `src/Metis/Core/IntegrityRuntime.php`
   - `ensure_runtime()` called `build_baseline()` on version mismatch
   - `build_baseline()` immediately re-entered `ensure_runtime()`, causing recursive baseline generation and memory exhaustion

## Rendering/API status at audit time

- UI rendering: `b)` rendering existed
- Broken layout risk: `c)` partial risk due to legacy modal/card styling not matching the requested spec
- API mismatch: `d)` existing API did not match the required enclave endpoint or data model

## Checks performed

- Verified current search trigger and modal bindings in:
  - `src/Metis/Core/Runtime/ShellTemplate.php`
  - `assets/js/help/help.js`
  - `assets/js/help/help-search.js`
- Verified backend search implementation in:
  - `src/Metis/Core/HelpService.php`
- Verified there was no existing enclave help endpoint
- Verified there were no existing help search tables in MySQL before rebuild

## Console/network note

Direct browser console inspection was not available from the terminal-only environment, so console findings are based on code-path inspection rather than live DevTools capture.

No immediate missing-symbol issue was found in the legacy help JS, but the architecture mismatch was enough to require replacement.

## Rebuild direction taken

- Added normalized MySQL-backed help search tables
- Seeded the tables from the existing help index and docs corpus
- Added `/system/enclave/help/search.php` with Secure Enclave enforcement
- Replaced the search modal with a rendered view plus dedicated CSS/JS
- Removed inline help-panel display styling in favor of semantic hidden states
- Added a re-entry guard to integrity baseline initialization so standalone runtime boot can complete

## Final verification

- `php -l` passed for the new and modified Help Search files and the integrity runtime patch
- Authenticated Secure Enclave execution returned structured search results for `newsletter` with `total=33`
- Direct execution of `/system/enclave/help/search.php` returned JSON success for an authenticated request
- Direct execution of `/system/enclave/help/search.php` returned JSON `Authentication required.` for an unauthenticated request instead of a fatal
