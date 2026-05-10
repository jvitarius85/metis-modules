# Metis Hardening Pass

This pass tightened Metis around privileged runtime behavior without changing the application architecture.

## Changes

- App keys and nonce signing now fail closed after installation. Runtime code must use `metis_runtime_require_app_key()` and must not fall back to predictable values such as `metis-local-key`.
- The installer may generate an app key when the submitted value is missing or unsafe. Test/dev may use `METIS_TEST_APP_KEY` only when `METIS_APP_ENV` or `APP_ENV` is explicitly test/dev/local.
- `/media/raw/...` is limited to public-safe image, video, and audio files under `storage/uploads`; it rejects traversal, symlinks, hidden/internal paths, documents, archives, logs, SQL/config-like files, and private/protected directories.
- Tokenized `/media/{token}` still uses the media registry, validates the token shape and DB row, resolves the path server-side, rejects traversal/symlinks/unsafe extensions, and serves SVG as an attachment.
- Direct `storage/` access is blocked in Apache rules. Media must be served through the front controller.
- Sensitive tool scripts now have runtime CLI guards, including backup, restore, docs generation, and repair utilities.
- `system/tools/security_scan.php` provides a repo-local automated hardening scan with centralized approved legacy exceptions.

## Approved Gateways

- Nonce and signing key access: `metis_runtime_require_app_key()`.
- Media/file serving: `metis_kernel_handle_public_storage_request()` and upload helpers in `UploadsRuntime.php`.
- DB access: `DatabaseService`, `MetisRuntimeDbConnection`, core schema/migration/install code, and explicit module repository/service layers.
- Process execution: release, recovery, integrity, finance import services, and CLI tools only.
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
```

Optional project checks:

```bash
composer audit || true
composer test || true
system/vendor/bin/phpstan analyse || true
system/vendor/bin/psalm || true
```

## Remaining Risks

- Direct superglobal usage still exists in legacy module AJAX/view code. New code should use the request abstraction, and the scan centralizes the approved legacy paths.
- Some module SQL and process execution remains in established service/tool layers. New raw SQL or process calls must be added only through approved gateways or the scan should fail.
