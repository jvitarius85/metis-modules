# UI Services

## Canonical Services

- `Metis.ajax`
- `Metis.toast`
- `Metis.modal`
- `Metis.confirm`

Namespaced aliases added in this pass:

- `Metis.ui.ajax`
- `Metis.ui.toast`
- `Metis.ui.modal`
- `Metis.ui.confirm`

## Modal Usage

Use:

- `Metis.modal.open(target)`
- `Metis.modal.close(target)`
- `Metis.confirm.open({ message, title, confirmLabel, cancelLabel, tone })`

Do not use:

- browser native `confirm()`
- module-local competing confirm dialogs for standard confirmation flow

## Toast Usage

Use:

- `Metis.toast.success(message, options)`
- `Metis.toast.error(message, options)`
- `Metis.toast.warning(message, options)`
- `Metis.toast.info(message, options)`

Alias support:

- `window.metis_toast(...)`
- `Metis.ui.toast.*(...)`

Do not use:

- browser native `alert()`
- module-local duplicate toast containers for routine notifications

## Dropdown Usage

This pass did not introduce a new dropdown framework.

Current governance direction:

- keep native `<select>` controls where appropriate
- centralize data sourcing and request handling
- avoid embedding campaign option queries in views or templates

For the Form Builder campaign selector:

- options are sourced from `CampaignService`
- transport is centralized through `Metis.ajax`
- errors surface through centralized toast handling

## Deprecated Patterns

- module-local request wrappers that bypass `Metis.ajax`
- native alert/confirm fallbacks
- duplicate modal state machines when `Metis.modal` is sufficient
- duplicate toast wrappers when `Metis.toast` is sufficient

Continuation pass note:

- `system/modules/website/assets/website.js` no longer defines its own `window.metis_toast` or `window.metis_confirm` shims

## Remaining Known Risks

- some modules still contain local modal or toast implementations
- a general-purpose dropdown data helper is still not standardized across all modules
- the governance checker should be run after future UI work to keep drift visible
