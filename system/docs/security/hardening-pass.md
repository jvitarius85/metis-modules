# Metis Hardening Pass

This pass tightened Metis around privileged runtime behavior without changing the application architecture.

## Changes

- App keys and nonce signing now fail closed after installation. Runtime code must use `metis_runtime_require_app_key()` and must not fall back to predictable values such as `metis-local-key`.
- The installer may generate an app key when the submitted value is missing or unsafe. Test/dev may use `METIS_TEST_APP_KEY` only when `METIS_APP_ENV` or `APP_ENV` is explicitly test/dev/local.
- `/media/raw/...` is limited to public-safe image, video, and audio files under `storage/public-media`; it rejects traversal, symlinks, hidden/internal paths, documents, archives, logs, SQL/config-like files, and private/protected directories.
- Media storage has explicit classes: `storage/public-media`, `storage/protected-media`, and `storage/private-records`. Legacy `storage/uploads` and `storage/media` are tokenized compatibility roots only.
- Tokenized `/media/{token}` still uses the media registry, validates the token shape and DB row, resolves the path server-side, rejects traversal/symlinks/unsafe extensions, and serves SVG as an attachment.
- Direct `storage/` access is blocked in Apache rules. Media must be served through the front controller.
- Sensitive tool scripts now have runtime CLI guards, including backup, restore, docs generation, and repair utilities.
- `$_REQUEST` has been removed from non-vendor PHP so cookies cannot be mixed into request action or nonce lookup paths.
- Raw request superglobal approvals are empty; `RequestRuntime.php` is the only SAPI request boundary.
- CLI subprocess execution now routes through `ProcessRunner` with explicit CLI security, audit, and permission context.
- Public website routes now attach route security policies so anonymous page/CSS rendering is rate-limited and audited through the same route policy path.
- `FileService` now validates managed roots and audits writes, copies, removes, and permission changes without logging raw path values.
- `system/tools/security_scan.php` provides a repo-local automated hardening scan with centralized, file-specific approved legacy exceptions.
- `system/tests/security_governance_test.php` asserts the app-key, media, process, scanner, and Hermes governance contracts.
- `system/tests/operational_governance_test.php` asserts process context enforcement, managed file operations, and public website route policy wiring.
- Governance allowlists live in `system/config/governance.php`; scan changes must be reviewed as boundary changes and must not use broad module/tool/core prefixes.

## Approved Gateways

- Nonce and signing key access: `metis_runtime_require_app_key()`.
- Media/file serving: `metis_kernel_handle_public_storage_request()`, media storage class helpers, and upload helpers in `UploadsRuntime.php`.
- DB access: `DatabaseService`, `MetisRuntimeDbConnection`, core schema/migration/install code, and explicit module repository/service layers that call the DB service.
- Process execution: `ProcessRunner` only.
- File writes/removes: `FileService` or storage runtimes with managed-root validation and audit evidence.
- Privileged AJAX and route writes: router request security, AJAX controller registry, Secure Enclave policies, permission checks, and audit logging.

## Deployment Rules

- Every installed environment must have a strong `app_key` in `system/config/database.php`.
- Do not serve `system/`, `storage/`, `vendor/`, config, logs, SQL, archives, backups, docs/specs, or env-like files directly.
- `.htaccess` protects Apache and LiteSpeed-compatible deployments, but nginx does not read it.

Example nginx deny rules:

```nginx
location ~ /\.(?!well-known/) { deny all; }
location ^~ /system/ { deny all; }
location ^~ /storage/ { deny all; }
location ^~ /vendor/ { deny all; }
location ~* \.(env|ini|log|md|markdown|pem|key|crt|sql|sqlite3?|toml|txt|ya?ml|zip|tar|gz|bak|dist|old)$ { deny all; }
location / { try_files $uri $uri/ /index.php?$query_string; }
```

LiteSpeed should mirror the Apache rules and must not rely on directory indexes or upload-directory file extension filtering as the only protection.

## Validation

Run:

```bash
find . -name "*.php" -not -path "./system/vendor/*" -print0 | xargs -0 -n1 php -l
php system/tools/security_scan.php
php system/tests/security_governance_test.php
php system/tests/operational_governance_test.php
php system/tests/system_audit_test.php
php system/tools/test_suite.php
```

Optional project checks:

```bash
composer audit || true
composer test || true
system/vendor/bin/phpstan analyse || true
system/vendor/bin/psalm || true
```

## Remaining Risks

- Some legacy module views and AJAX handlers still contain SQL statement text. Existing calls must execute through `DatabaseService`; follow-up extraction should move statement construction into repositories/services.
- Some domain-specific file operations remain in older storage/runtime services. New privileged writes should route through `FileService` or audited storage helpers.
- Protected/private media has token, expiration, permission, and audit hooks at the serving boundary. Existing modules still need to opt into protected/private storage where their domain data requires it.
