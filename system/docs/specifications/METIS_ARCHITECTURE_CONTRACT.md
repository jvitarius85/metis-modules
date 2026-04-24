# METIS ARCHITECTURE CONTRACT
Version: 1.0

Defines the authoritative architecture for Metis.

## Allowed Top-Level Runtime Directories

- `src/`
- `modules/`
- `assets/`
- `config/`
- `storage/`
- `vendor/`
- `docs/`

## Forbidden Runtime Directories

The following must not contain runtime logic and must not be recreated:

- `includes/`
- `helpers/`
- `legacy/`
- `compat/`
- `system/`
- `lib/`

## Runtime Ownership

All runtime application logic must live in:

`src/Metis`

## Entrypoint Architecture

Only these execution entrypoints are allowed:

- `index.php`
- `system/ajax.php`
- `system/webhooks.php`
- `system/cron.php`
- `system/shell.php`

Entrypoints must be thin launchers only. They must immediately delegate execution into the core runtime.

## Core Runtime Areas

Authoritative runtime areas:

- `src/Metis/Core/Kernel`
- `src/Metis/Core/Routing`
- `src/Metis/Core/Security`
- `src/Metis/Core/Services`
- `src/Metis/Core/Workers`
- `src/Metis/Core/Modules`

## Architectural Principles

- Single runtime source of truth
- Centralized routing
- Centralized security
- Module isolation
- Clear execution boundaries
- No legacy compatibility layers
- No duplicate frameworks

## Modification Policy

Architecture changes require owner approval before implementation.
