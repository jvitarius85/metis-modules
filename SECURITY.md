# Security

Metis is designed around layered security, audited privileged actions, and safe
failure behavior.

## Access Control

All admin actions must pass through authentication, role checks, and module
permissions. Required modules provide the core identity, profile, settings, and
portal behavior needed for access control.

## CSRF and Request Validation

State-changing requests must use Metis nonce/CSRF validation. AJAX and routed
actions should reject invalid, missing, or mismatched action tokens.

## Secure Enclave

Sensitive operations are expected to route through Secure Enclave policies.
Privileged actions should not bypass registered enclave operations, permission
checks, or audit logging.

Examples of sensitive operations include:

- user and role changes
- backup and restore actions
- release updates and rollbacks
- recovery actions
- system settings changes
- financial and donation operations

## Audit Logging

Sensitive activity should be logged with enough detail to support review without
exposing secrets. Logs must not include passwords, private keys, raw tokens, or
full secret payloads.

## Recovery and Integrity

Metis includes integrity, backup, and recovery services. Recovery actions should
follow a controlled sequence: detect, classify, validate, backup, execute,
verify, log, and report.

Recovery must not overwrite or mutate files without a backup and audit trail.

## Protected Paths

Servers must prevent direct public access to private paths, including:

- `system/config/`
- `system/src/`
- `system/vendor/`
- `storage/logs/`
- `storage/backups/`
- `storage/runtime/`

Apache uses `.htaccess` rules for this protection. Nginx requires equivalent
server configuration.

## Vulnerability Reporting

Please report suspected vulnerabilities privately to the maintainers before
public disclosure. Include the affected version, reproduction steps, expected
impact, and any relevant logs with secrets removed.
