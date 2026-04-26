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

## JavaScript Trust Boundary

Browser JavaScript is treated as a user interface layer only. It must not be the
source of truth for authentication, permissions, payments, release updates,
backups, recovery, or Secure Enclave decisions.

Client code may improve workflow speed and usability, but every privileged or
state-changing action must still be validated server-side with authentication,
permissions, nonce/CSRF checks, request validation, rate limiting where
appropriate, and Secure Enclave enforcement for protected operations.

JavaScript must follow these rules:

- do not store secrets, private keys, raw access tokens, or recovery material in
  browser-readable configuration
- use public keys only where the provider requires them, such as Stripe
  publishable keys
- sanitize or escape server-provided HTML before inserting it into the DOM
- use shared navigation helpers for dynamic redirects and clickable UI targets
- avoid inline event handlers and `javascript:` URLs
- prefer shared rendering helpers over hand-built HTML when adding reusable UI
  controls

Payment flows must bind the server-created payment session to the exact payment
intent, amount, and currency before recording a submission or donation. Client
payment state is never enough by itself.

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
