# Production Governance

Metis governance is deny-by-default for dangerous operations. New code must extend the existing governance surfaces instead of creating parallel paths.

## Approved Layers

Approved layers are declared in `system/config/governance.php`.

- Raw request superglobal approvals are empty. GET, POST, and COOKIE intake use `filter_input_array(...)`; uploaded files and raw body intake are isolated to the explicit SAPI bridge in `RequestRuntime.php`.
- Native database access is limited to `DatabaseService` and bootstrap/runtime plumbing. Runtime modules and CLI tools must use the Metis DB service API instead of native connection handles.
- Direct database execution is limited to approved DB/runtime/install layers. CLI maintenance tools that need database access must instantiate the Metis runtime connection and call `DatabaseService`.
- Process execution is limited to `ProcessRunner`.
- Legacy PHP serialization is limited to approved compatibility decoders.
- Sensitive media writes are registered with an expected storage class.
- Managed file writes, removals, copies, and permission changes must flow through `FileService` or an approved storage runtime with root validation and audit evidence.

## Media

Canonical write helpers:

- `metis_store_public_media(...)`
- `metis_store_protected_media(...)`
- `metis_store_private_record(...)`

Protected/private helpers require explicit expiration metadata and audit storage outcomes. Legacy `storage/uploads` and `storage/media` reads remain compatible, but new sensitive writes must not use them.

## Request Input

New request handling should use typed request helpers from `RequestRuntime.php`, including ID, object-code, enum, JSON, date, boolean, and file accessors. Do not destructively sanitize global arrays.

`RequestRuntime.php` is the only approved SAPI request boundary. New code must not read `$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`, `$_COOKIE`, `$GLOBALS['_*']`, or `php://input` directly.

## Database Access

Production code must route database work through `Metis\Services\DatabaseService` or module repositories/services that use it. The DB service owns native connection access, prepared execution, escaping, charset, prefix, last-error, and reconnect behavior. The magic native-connection passthrough is intentionally disabled.

Existing module views and AJAX handlers still contain legacy SQL statement text in some places. That SQL must continue to execute through `DatabaseService`; new work should move statement construction into repositories/services rather than adding query strings to views or request handlers.

## Process Execution

All runtime process execution goes through `ProcessRunner::run(...)` and must include:

- `security_context`
- `audit_context`
- `permission_context`

The runner rejects missing context before `proc_open`, validates authority or explicit preauthorization, and redacts sensitive context keys before logging.

CLI tools that invoke subprocesses must call `metis_cli_process_context(...)` and pass the resulting context to `ProcessRunner`; raw `exec`, `proc_open`, `shell_exec`, `system`, `passthru`, and `popen` are not approved outside the runner.

## Route Security

Public routes still pass explicit route policies unless they are pure static assets. Website public routes attach `route.security` and register anonymous policies so rate limiting, audit context, and fail-secure error handling remain deterministic without requiring portal authentication.

## Drift Detection

`system/tools/security_scan.php` checks:

- PHP syntax
- app-key fallback denial
- raw media policy
- required media roots
- sensitive media storage class rules
- raw `$_REQUEST`
- raw superglobals outside approved layers
- SAPI request bridge use outside `RequestRuntime.php`
- raw SQL outside approved layers
- native DB access outside approved layers
- unsafe serialization outside approved compatibility decoders
- process execution outside approved layers
- `ProcessRunner` context propagation
- route middleware and route policy wiring
- AJAX handlers without security controller metadata
- Hermes permission/risk/approval metadata
- public website route security policy wiring
- governed file/process operational tests

Sentinel should run this scanner as a blocking deployment gate and report drift by rule name.
