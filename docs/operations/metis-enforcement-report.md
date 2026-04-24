# Metis Enforcement Report

## Status

Metis runtime ownership has been moved to the approved runtime tree:

- `index.php`
- `src/Metis/Core/Kernel/Runtime.php`
- `src/Metis/Core/Security/SecurityKernel.php`
- `src/Metis/Core/ServiceRegistryRuntime.php`
- `src/Metis/Core/Routing/RouterRuntime.php`
- `src/Metis/Core/ModuleLoader.php`
- `modules/*`

Live module discovery now follows:

- `ModuleLoader`
- `scan /modules`
- `ModuleValidator`
- `load module`

with validation implemented in `src/Metis/Core/Modules/ModuleValidator.php`.

## Completed Enforcement

- Moved active runtime, bootstrap, router, auth, cron, webhook, storage, upload, logger, integrity, database, and support implementations into `src/Metis/Core/*`.
- Moved the legacy `core/services/*` runtime service layer into `src/Metis/Core/*` and updated Composer/service registry ownership to point at the `src` copies.
- Moved the legacy `core/ui/*` runtime JavaScript assets into `assets/js/help/*` and updated runtime asset loading to use the approved asset tree.
- Moved the active Stripe runtime integration out of `core/integrations/stripe/*` into `src/Metis/Core/Integrations/*`.
- Moved the live module tree from `includes/modules` to `modules`.
- Removed the old `includes/core/runtime` layer entirely.
- Removed the old `includes/core/templates` layer entirely.
- Moved runtime write locations to top-level `storage/*`.
- Removed inline runtime `<script>` and `<style>` emission from the live runtime path.
- Replaced active raw WXR-shaped helper calls in app/module code with Metis-owned wrappers.
- Replaced the active compatibility-layer raw helper aliases with Metis-owned runtime helpers and wrappers.
- Removed the dead root-level event and job alias classes so canonical ownership now lives only under `src/Metis/Core/Events/*` and `src/Metis/Core/Jobs/*`.
- Removed the obsolete `Operations` facade and moved its remaining callers to the canonical operations service helper.
- Removed the obsolete auth facade and rebound the runtime auth service to `src/Metis/Services/AuthService.php`.
- Removed stray repository junk, including `.DS_Store` files and the orphan `src/storage` tree.
- Removed the remaining backward-compatibility shim files under `includes/core`, `includes/apis/stripe`, and `includes/modules`.
- Removed the obsolete top-level `logs` tree after logger ownership was consolidated under `storage/logs`.

## Current Includes State

The remaining `includes` tree is intentionally limited to:

- `includes/.htaccess`
- `includes/index.php`

## Residual Manual Cleanup

- The live repository no longer uses `core/*` for runtime ownership.
- `core/` is no longer present in the repository view.

## Validations Run

The enforcement work was validated repeatedly during migration and cleanup, including:

- `php tests/core/base_url_detection_test.php`
- `php tests/security/security_auth_test.php`
- `php tests/core/service_registry_test.php`
- `php tests/core/unified_services_test.php`
- `php tests/security/router_request_security_test.php`
- `php tests/security/directory_access_test.php`
- `php tests/security/integrity_signature_test.php`
- `php tests/security/security_enclave_test.php`
- `php tests/core/runtime_asset_service_test.php`

## Residual Audit 2026-03-16

Fresh closeout audit results:

- `includes` is reduced to:
  - `includes/.htaccess`
  - `includes/index.php`
- `core/` is no longer present in the repository tree.
- `system/` still exists, but the executable files are confirmed to be thin launchers only:
  - `system/ajax.php`
  - `system/cron.php`
  - `system/shell.php`
  - `system/webhooks.php`
  Each file only requires `src/Metis/Core/Kernel/Runtime.php` and calls `metis_kernel_execute(...)`.
- The non-runtime leftover `system/CHANGELOG.txt` has been removed, so `system/` now contains launcher files only.
- No active runtime ownership was found under `includes/core`, `includes/modules`, `includes/apis`, or `core/*`.
- The last public bridge wrappers previously carried by `src/Metis/Core/Runtime/StandaloneBootstrap.php` have been removed.
- Live callers now use runtime-owned helpers directly for:
  - request unslashing
  - JSON response emission
  - nonce creation and verification
  - nonce field rendering
  - date/time formatting
  - redirects
  - URL parsing
  - safe HTML passthrough

Audit conclusion:

- The repository is fully past the cleanup/migration phase.
- `system/` is no longer a logic layer; it is only a launcher surface retained intentionally as an operational entry shim.
- The remaining debt is no longer directory ownership or bridge cleanup. It is the broader `src/Metis/Core/Legacy*` compatibility runtime, which still wraps the migrated runtime by design.

## Conclusion

The repository is now in the enforced state expected by the Metis architecture review:

- active runtime code is under `src/Metis`
- active modules are under `modules`
- active runtime writes are under `storage`
- `includes` has been reduced to non-runtime directory protection files only

The remaining cleanup debt is no longer dead entrypoints, duplicate top-level files, or standalone bridge wrappers. It is the intentional `src/Metis/Core/Legacy*` compatibility surface that still wraps the migrated runtime.
