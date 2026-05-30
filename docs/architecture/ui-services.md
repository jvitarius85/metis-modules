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
- `Metis.ui.dropdown`
- `Metis.ui.form`
- `Metis.ui.loading`

## Modal Usage

Use:

- `Metis.modal.open(target)`
- `Metis.modal.close(target)`
- `Metis.confirm.open({ message, title, confirmLabel, cancelLabel, tone })`
- `Metis.ui.modal.confirm({ message, title, confirmLabel, cancelLabel, tone })`
- `Metis.ui.modal.form(target)`

Do not use:

- browser native `confirm()`
- module-local competing confirm dialogs for standard confirmation flow

## Toast Usage

Use:

- `Metis.toast.success(message, options)`
- `Metis.toast.error(message, options)`
- `Metis.toast.warning(message, options)`
- `Metis.toast.info(message, options)`
- `Metis.ui.toast.*(...)`

Do not use:

- browser native `alert()`
- module-local duplicate toast containers for routine notifications

## Dropdown Usage

Use:

- `Metis.ui.dropdown.init()`
- `Metis.ui.dropdown.open(target)`
- `Metis.ui.dropdown.close(target)`
- `Metis.ui.dropdown.toggle(target)`

For the Form Builder campaign selector:

- options are sourced from `CampaignService`
- transport is centralized through `Metis.ajax`
- errors surface through centralized toast handling

## Form And Loading Usage

Use:

- `Metis.ui.form.setSubmitting(target, busy, options)`
- `Metis.ui.form.clearErrors(formEl)`
- `Metis.ui.loading.set(target, busy, options)`
- `Metis.ui.loading.button(button, busy, options)`

## Deprecated Patterns

- module-local request wrappers that bypass `Metis.ajax`
- native alert/confirm fallbacks
- duplicate modal state machines when `Metis.modal` is sufficient
- duplicate toast wrappers when `Metis.toast` is sufficient

## Current Status

- no browser native `alert()` or `confirm()` fallback remains in the hardened UI paths
- Form Builder campaign population now uses centralized request handling, centralized response handling, and the canonical donations campaign service
- the shared core layer now exposes canonical AJAX, toast, modal, confirm, dropdown, form, and loading helpers
- hardened module paths now route toasts, confirms, submit state, and loading state through `Metis.ui.*`
