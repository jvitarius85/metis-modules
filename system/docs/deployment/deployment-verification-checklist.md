# Deployment Verification Checklist

Run this checklist before promoting Metis to a production host.

## Bootstrap And Secrets

- `storage/install.lock` exists after installation.
- `app_key` exists, is not a documented default, and is at least 32 characters of high-entropy material.
- Missing or weak `app_key` blocks installed runtime boot.
- PHP error display is disabled and errors write to `storage/logs/`.
- Database credentials are not readable through the web server.

## Direct Web Access Denials

Verify each path returns `403` or `404` when requested directly:

- `/system/`
- `/system/config/`
- `/system/src/`
- `/system/vendor/`
- `/system/tools/`
- `/system/tests/`
- `/storage/`
- `/storage/protected-media/`
- `/storage/private-records/`
- `/storage/backups/`
- `/storage/logs/`
- `/vendor/`
- `/*.sql`, `/*.sqlite`, `/*.log`, `/*.env`, `/*.zip`, `/*.tar`, `/*.bak`

## Media Access

- `/media/raw/...` serves only files under `storage/public-media`.
- `/media/raw/...` rejects documents, archives, configs, logs, backups, SQL, temp files, and traversal attempts.
- `/media/{token}` is required for protected and private records.
- Expired protected/private media tokens return `404`.
- Unauthorized protected/private media access is audit logged and returns `404`.
- Protected/private media responses use `Cache-Control: private, no-store, max-age=0`.

## Governance Commands

Run:

```bash
php system/tools/security_scan.php
php system/tools/test_suite.php --filter=governance
php system/tests/operational_governance_test.php
```

Both commands must pass before deployment.

## Operational Readiness

- Backups can be created and restored in a non-production environment.
- Release, restore, backup, and integrity commands run only from CLI or approved Secure Enclave paths.
- Logs do not include secrets, tokens, credentials, PHI, or raw protected media paths.
- nginx or LiteSpeed hosts apply rules equivalent to `.htaccess`.
