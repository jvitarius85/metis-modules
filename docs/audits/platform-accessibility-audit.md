# Metis Platform Accessibility Audit

Date: May 31, 2026

## Scope

Baseline accessibility audit across:

- shared runtime and shell
- admin UI modules with dynamic dialogs and custom controls
- public website navigation runtime

This audit is governance-first. It documents what exists, what is missing, and what has been remediated in this phase.

## Current Strengths

- Shared accessibility runtime exists in [system/assets/core.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/core.js:3013).
- Shared dialog semantics and focus trapping exist in [system/assets/core.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/core.js:1832).
- Accessibility preference bootstrap exists in [system/src/Metis/Core/AccessibilityRuntime.php](/Users/jvitarius85/Documents/GitHub/metis/system/src/Metis/Core/AccessibilityRuntime.php:1).
- Portal shell exposes an accessibility panel in [system/src/Metis/Core/Runtime/ShellTemplate.php](/Users/jvitarius85/Documents/GitHub/metis/system/src/Metis/Core/Runtime/ShellTemplate.php:166).
- Public navigation exposes governed ARIA state in [system/modules/website/assets/public-navigation.js](/Users/jvitarius85/Documents/GitHub/metis/system/modules/website/assets/public-navigation.js:95).

## Confirmed Gaps Before Remediation

1. Accessibility support existed, but platform-wide compliance was not proven.
2. No dedicated automated accessibility governance test existed.
3. Some module dialogs still bypassed the shared modal runtime by manually toggling `aria-hidden`, `.show()`, or `.hide()`.
4. No formal audit document existed for admin/public accessibility status.

## Remediation In This Phase

1. Settings media picker now uses the shared modal runtime in [system/modules/settings/assets/settings.js](/Users/jvitarius85/Documents/GitHub/metis/system/modules/settings/assets/settings.js:1).
2. Newsletter campaign detail, test-send, prompt, theme image settings, and inline image picker dialogs now use the shared modal runtime in [system/modules/newsletter/assets/newsletter.js](/Users/jvitarius85/Documents/GitHub/metis/system/modules/newsletter/assets/newsletter.js:1).
3. Media library preview, organize, and delete dialogs now use shared modal markup and shared modal open/close flow in [system/modules/media/views/library.php](/Users/jvitarius85/Documents/GitHub/metis/system/modules/media/views/library.php:105) and [system/modules/media/assets/media.js](/Users/jvitarius85/Documents/GitHub/metis/system/modules/media/assets/media.js:239).
4. Core modal and accessibility helpers no longer carry media-specific dialog exceptions in [system/assets/core.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/core.js:1814).
5. People workspace account-action menus now expose menu semantics, trigger state, escape close, outside close, keyboard opening, and focus return in [system/modules/people/views/workspace.php](/Users/jvitarius85/Documents/GitHub/metis/system/modules/people/views/workspace.php:177) and [system/modules/people/assets/js/profile-workspace.js](/Users/jvitarius85/Documents/GitHub/metis/system/modules/people/assets/js/profile-workspace.js:678).
6. Rich editor toolbar dropdowns now expose menu semantics, trigger state, keyboard opening, directional focus movement, escape close, and focus return in [system/assets/js/editor/simple-editor.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/js/editor/simple-editor.js:1691) and [system/assets/js/editor/simple-editor.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/js/editor/simple-editor.js:4916).
7. Builder preview/revision drawers and the block inserter overlay now expose drawer/dialog linkage, trigger state, escape close, and focus return in [system/assets/js/editor/simple-editor.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/js/editor/simple-editor.js:3637), [system/assets/js/editor/simple-editor.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/js/editor/simple-editor.js:4695), and [system/assets/js/editor/simple-editor.js](/Users/jvitarius85/Documents/GitHub/metis/system/assets/js/editor/simple-editor.js:4897).
8. People access-request notes and security confirmations now use shared prompt and confirm runtimes instead of local modal builders in [system/modules/people/assets/js/profile-shell.js](/Users/jvitarius85/Documents/GitHub/metis/system/modules/people/assets/js/profile-shell.js:30) and [system/modules/people/assets/js/profile-security.js](/Users/jvitarius85/Documents/GitHub/metis/system/modules/people/assets/js/profile-security.js:23).
9. Added accessibility governance coverage in [system/tests/accessibility_governance_test.php](/Users/jvitarius85/Documents/GitHub/metis/system/tests/accessibility_governance_test.php:1).

## Remaining Known Risks

1. This repository still does not have full automated WCAG scanning with tools like axe or pa11y.
2. This audit does not yet prove screen-reader correctness across all public and admin flows.
3. This audit does not yet prove full keyboard-only completion across every module.
4. Some modules still use module-local picker, sidebar, inspector, search-results, or other dynamic interaction logic that should be reviewed for keyboard and screen-reader parity even when they are not modal dialogs.

## Recommended Next Phase

1. Add a dedicated accessibility governance runner or fold the accessibility governance test into the canonical runner.
2. Audit remaining dynamic interaction implementations module-by-module, with priority on dashboard/search-result widgets, remaining people adjunct flows, and any custom picker or inspector behavior not already covered by shared runtime semantics.
3. Add manual validation scripts for:
   - keyboard-only navigation
   - focus order
   - modal open/close/focus return
   - screen-reader labeling on critical workflows
4. Add automated public-page accessibility scanning in CI for core website templates.

## Closeout Status (May 31, 2026)

Implemented after this audit:

1. Public default website templates now include:
   - skip link (`.metis-skip-link`)
   - stable main-content target id
   - `tabindex="-1"` target for keyboard focus landing
2. Public navigation now enforces:
   - focus transfer into nav on mobile open
   - focus return to toggle on close
   - escape-close for open nav/menu state
3. Theme dynamic 4-side controls now expose:
   - `aria-pressed` linked/unlinked state
   - directional `aria-label`s per side input
4. Module modal backdrop defaults were normalized platform-wide in hardened scope:
   - `class="metis-modal-backdrop" aria-hidden="true" hidden`
5. Governance now includes modal default-state enforcement through the AJAX/UI governance checker.

Current state: all accessibility governance and regression suites pass in this repository state.
