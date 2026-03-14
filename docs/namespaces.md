# Metis Namespace Layout

- `Metis\Core`: runtime bootstrap, container, module loader, router orchestration.
- `Metis\Services`: adapters around auth, logging, settings, and database access.
- `Metis\Http`: request/response/router primitives.
- `Metis\Modules\<ModuleName>`: reserved for module-specific classes as modules move out of bootstrap function files.

# Compatibility Rules

- Legacy underscore class names remain available through class aliases.
- Global helper functions remain supported for now; new class-based code should live under `src/Metis`.
- The local autoloader in `includes/core/autoload.php` provides PSR-4-style loading without requiring Composer.
- `includes/core/bootstrap.php` is the single entrypoint for procedural core dependencies, so entrypoints and tests can request named components instead of maintaining long `require_once` lists.

# Suggested Module Structure

- `src/Metis/Modules/Donations/DonationsModule.php`
- `src/Metis/Modules/Donations/Services/StripeSyncService.php`
- `src/Metis/Modules/Donations/Http/DonationWebhookController.php`

# Reference Migration

- `Donations` is now the reference module migration.
- The runtime entrypoint remains `includes/modules/donations/bootstrap.php`.
- The implementation lives in `src/Metis/Modules/Donations/DonationsModule.php`.
- Legacy template and AJAX helpers still call global `metis_*` functions, which now delegate into the namespaced module class.
- `Contacts` is now the first non-legacy migration for a formerly procedural module.
- The implementation lives in `src/Metis/Modules/Contacts/` and no longer depends on `includes/modules/contacts/legacy.php`.
- `Calendar` is now the second non-legacy migration for a formerly procedural module.
- The implementation lives in `src/Metis/Modules/Calendar/` and no longer depends on `includes/modules/calendar/legacy.php`.
- `Board` is now the third non-legacy migration for a formerly procedural module.
- The implementation lives in `src/Metis/Modules/Board/` and no longer depends on `includes/modules/board/legacy.php`.
- `Finance` is now the fourth non-legacy migration for a formerly procedural module.
- The implementation lives in `src/Metis/Modules/Finance/` and no longer depends on `includes/modules/finance/legacy.php`.
- `Newsletter` is now the fifth non-legacy migration for a formerly procedural module.
- The implementation lives in `src/Metis/Modules/Newsletter/` and no longer depends on `includes/modules/newsletter/legacy.php`.
- `Portal`, `Profile`, `Settings`, and `Website` no longer need `legacy.php` because their remaining bootstrap behavior now lives directly in their module classes.
- `People` is now the sixth non-legacy migration for a formerly procedural module.
- The implementation lives in `src/Metis/Modules/People/` and no longer depends on `includes/modules/people/legacy.php`.

# Current Module Pattern

- Every module now has a namespaced owner under `src/Metis/Modules/<Module>/<Module>Module.php`.
- Each `includes/modules/<module>/bootstrap.php` file is now a shim that loads the autoloader and boots the module class.
- Large modules currently keep their procedural implementation in `includes/modules/<module>/legacy.php` so behavior stays stable while ownership moves into the namespaced tree.
- `Donations` is the most complete migration and should be the template for converting `legacy.php` modules into fully class-based internals over time.
- `Contacts` is the template for removing `legacy.php` by splitting responsibilities into support, schema, and maintenance classes while preserving global compatibility wrappers.
- `Calendar` is the template for a service-heavy migration split across access, settings, Google API, and sync/cache classes.
- `Board` is the template for mixed schema and Workspace helper migrations split across access, support, schema, and Workspace service classes.
- `Finance` is the template for schema plus domain-service migrations split across access, support, schema, ledger, and finance service classes.
- `Newsletter` is the template for delivery and queue migrations split across access, support, schema, delivery, and queue service classes.
- `People` is the template for access-control-heavy migrations split across support, schema, access, activity, and maintenance classes.

# Scaffold

- A reusable new-module scaffold now lives under `scaffolds/module/`.
- It provides starter templates for `bootstrap.php`, the module class, and `module.json`.
- The scaffold is template text, not executable PHP, because it contains replacement tokens.

# Migration Approach

1. Add namespaced classes under `src/Metis`.
2. Preserve existing call sites with class aliases.
3. Move new behavior into classes first, then retire legacy bootstrap functions incrementally.
