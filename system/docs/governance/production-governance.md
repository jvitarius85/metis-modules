# Production Governance

Metis governance is deny-by-default for dangerous operations. New code must extend the existing governance surfaces instead of creating parallel paths.

## Approved Layers

Approved layers are declared in `system/config/governance.php`.

- Raw request superglobals are limited to `RequestRuntime.php`, which is the only PHP-global request bridge.
- Direct database execution is limited to approved DB/runtime/install layers and explicit CLI maintenance tooling.
- Process execution is limited to `ProcessRunner` and approved CLI tools.
- Sensitive media writes are registered with an expected storage class.

## Media

Canonical write helpers:

- `metis_store_public_media(...)`
- `metis_store_protected_media(...)`
- `metis_store_private_record(...)`

Protected/private helpers require explicit expiration metadata and audit storage outcomes. Legacy `storage/uploads` and `storage/media` reads remain compatible, but new sensitive writes must not use them.

## Request Input

New request handling should use typed request helpers from `RequestRuntime.php`, including ID, object-code, enum, JSON, date, boolean, and file accessors. Do not destructively sanitize global arrays.

## Process Execution

All runtime process execution goes through `ProcessRunner::run(...)` and must include:

- `security_context`
- `audit_context`
- `permission_context`

The runner rejects missing context before `proc_open`, validates authority or explicit preauthorization, and redacts sensitive context keys before logging.

## Drift Detection

`system/tools/security_scan.php` checks:

- PHP syntax
- app-key fallback denial
- raw media policy
- required media roots
- sensitive media storage class rules
- raw `$_REQUEST`
- raw superglobals outside approved layers
- raw SQL outside approved layers
- process execution outside approved layers
- `ProcessRunner` context propagation
- route middleware and route policy wiring
- AJAX handlers without security controller metadata
- Hermes permission/risk/approval metadata

Sentinel should run this scanner as a blocking deployment gate and report drift by rule name.
