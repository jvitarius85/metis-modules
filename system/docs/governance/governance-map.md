# Metis Governance Map

Metis governance is enforced through a small number of approved boundaries. New platform code should extend these boundaries instead of bypassing them.

## Runtime Flow

1. `index.php` loads `system/src/Metis/Core/Kernel/Runtime.php`.
2. The kernel defines constants, loads Composer/core bootstrap, and registers core services.
3. Public storage requests are mediated before normal routing by `metis_kernel_handle_public_storage_request()`.
4. Non-storage requests flow into the router, middleware, module manifests, AJAX registry, and portal dispatch.
5. Writes and sensitive actions must pass route/AJAX security, permission checks, nonce checks where applicable, Secure Enclave policy, and audit logging.

## Approved Boundaries

- Request input: request/runtime helpers, router request objects, AJAX controller contracts, and explicit module legacy paths listed in `system/config/governance.php`.
- Media: `storage/public-media` for raw public media; `storage/protected-media` and `storage/private-records` for tokenized permission-checked access.
- SQL: database service, runtime DB connection, schema/install/migration tooling, and explicit module repository/service layers.
- Process execution: `Metis\Core\Services\ProcessRunner` plus CLI tools guarded by `metis_require_cli_tool()`.
- Hermes: `HermesToolRegistry`, `HermesToolExecutor`, `HermesPermissionValidator`, and `EnclaveToolRuntime`.

## Drift Detection

`php system/tools/security_scan.php` enforces the deterministic checks that protect these boundaries:

- PHP syntax.
- static app-key fallback denial.
- raw media root and protected token checks.
- direct storage denial.
- `eval()` absence.
- `$_REQUEST` absence.
- CLI tool guards.
- raw superglobal usage outside approved layers.
- raw SQL outside approved layers.
- process execution outside approved layers.

Approved layers live in `system/config/governance.php` so additions are visible and reviewable. Entries should be file-specific; broad module, tool, Hermes, and core prefixes are treated as governance drift.

`php system/tests/system_audit_test.php` bootstraps the platform and verifies route/AJAX registry contracts, including missing handlers and missing Secure Enclave AJAX policies.

`php system/tests/security_governance_test.php` statically verifies the central app-key, media, process execution, scanner, and Hermes governance contracts that should remain difficult to bypass accidentally.

## Deferred Governance Debt

- Legacy module AJAX/view code still contains direct `$_GET`, `$_POST`, and `$_FILES` access. This is allowed only through the current governance registry and should shrink over time.
- Some module SQL remains in established service layers. New SQL should be added to repositories/services and use prepared helpers.
- Protected/private media adoption is enforced at the serving boundary; domain modules still need to choose protected/private roots for sensitive data.
