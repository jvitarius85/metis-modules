# METIS PROJECT STRUCTURE
Version: 1.0

High-level repository structure for Metis.

```text
src/
    Metis/
        Core/
        Modules/
modules/
assets/
config/
storage/
vendor/
system/
docs/
tests/
```

## Directory Roles

- `src/` → core runtime logic
- `modules/` → self-contained product modules
- `assets/` → global CSS and JS
- `config/` → configuration
- `storage/` → logs, cache, runtime state, sessions
- `vendor/` → composer dependencies
- `system/` → thin execution launchers (`ajax.php`, `cron.php`, `webhooks.php`, `shell.php`)
- `docs/` → governance and rules
- `tests/` → tests

## Storage Rules

Allowed runtime write targets:
- `storage/logs`
- `storage/cache`
- `storage/runtime`
- `storage/sessions`
