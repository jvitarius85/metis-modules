# Module Scaffold

Use this scaffold for new modules that follow the current Metis namespaced pattern.

Required files:

- `includes/modules/<module>/bootstrap.php`
- `includes/modules/<module>/<module>.json`
- `src/Metis/Modules/<Module>/<Module>Module.php`

Optional during migration:

- `includes/modules/<module>/legacy.php`

Recommended internal layout:

- `src/Metis/Modules/<Module>/<Module>Module.php`
- `src/Metis/Modules/<Module>/Support.php`
- `src/Metis/Modules/<Module>/SchemaManager.php`
- `src/Metis/Modules/<Module>/MaintenanceManager.php`

Rule of thumb:

- New modules should skip `legacy.php` entirely.
- Existing modules may keep `legacy.php` temporarily while behavior is moved into namespaced classes.
