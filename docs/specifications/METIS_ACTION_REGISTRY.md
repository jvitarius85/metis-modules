# METIS ACTION REGISTRY
Version: 1.0

Defines canonical action naming and registration.

## Naming Format

Actions use:

`module.action`

Examples:
- `people.create`
- `people.delete`
- `newsletter.send`
- `finance.reconcile.daily`

Rules:
- lowercase
- dot-separated
- module-prefixed
- descriptive

## Registration Requirement

Actions must be registered during route definition, module boot, cron registration, webhook registration, or shell command registration.

Unregistered actions must not execute.
