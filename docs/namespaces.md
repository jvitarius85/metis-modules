# Metis Namespace Layout

- `Metis\Core`: runtime bootstrap, container, module loader, router orchestration.
- `Metis\Services`: adapters around auth, logging, settings, and database access.
- `Metis\Http`: request/response/router primitives.
- `Metis\Modules\<ModuleName>`: reserved for module-specific classes as modules move out of bootstrap function files.

# Compatibility Rules

- Legacy underscore class names remain available through class aliases.
- Global helper functions remain supported for now; new class-based code should live under `src/Metis`.
- The old `includes/core/*`, `includes/apis/stripe/*`, and `includes/modules/*` runtime compatibility shims have been removed; live implementation ownership is now under `src/Metis` and `modules`.
- The old `core/services/*`, `core/ui/*`, and live `core/integrations/stripe/*` runtime ownership has been migrated into `src/Metis` and `assets`; any remaining `core/*` file should be treated as cleanup debt, not an approved runtime location.
- Root-level duplicate aliases for `Metis\Core\Event`, `Metis\Core\EventBus`, `Metis\Core\JobQueue`, and `Metis\Core\JobWorkerRegistry` have been removed; the canonical owners are `Metis\Core\Events\*` and `Metis\Core\Jobs\*`.

# Suggested Module Structure

- `src/Metis/Modules/Donations/DonationsModule.php`
- `src/Metis/Modules/Donations/Services/StripeSyncService.php`
- `src/Metis/Modules/Donations/Http/DonationWebhookController.php`

# Reference Migration

- `Donations` is now the reference module migration.
- The runtime entrypoint remains `modules/donations/bootstrap.php`.
- The implementation lives in `src/Metis/Modules/Donations/DonationsModule.php`.
- Legacy template and AJAX helpers still call global `metis_*` functions, which now delegate into the namespaced module class.
- `Contacts` lives in `src/Metis/Modules/Contacts/`.
- `Calendar` lives in `src/Metis/Modules/Calendar/`.
- `Board` lives in `src/Metis/Modules/Board/`.
- `Finance` lives in `src/Metis/Modules/Finance/`.
- `Newsletter` lives in `src/Metis/Modules/Newsletter/`.
- `Portal`, `Profile`, `Settings`, and `Website` keep their module owners in `src/Metis/Modules/`.
- `People` lives in `src/Metis/Modules/People/`.

# Current Module Pattern

- Every module now has a namespaced owner under `src/Metis/Modules/<Module>/<Module>Module.php`.
- Each `modules/<module>/bootstrap.php` file is now a shim that loads the autoloader and boots the module class.
- Modules are discovered from `modules/<module>/<module>.json` and loaded through the shared module loader.
- `Donations` is the most complete migration and remains a useful template for class-owned module behavior.
- `Contacts` is the template for splitting responsibilities into support, schema, and maintenance classes while preserving global compatibility wrappers.
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
