# Security

Métis follows a security-first architecture.

## Permission Model

All actions within the platform must pass through the permission layer.
Both human users and automated agents must respect role-based access controls.

## Hermes Safety

Hermes cannot bypass platform services or access the database directly.

All Hermes operations execute through platform services which enforce
permissions and audit logging.

## Audit Logging

Sensitive operations must be logged including:

- user management
- permission changes
- financial operations
- administrative automation

## Vulnerability Reporting

If you discover a vulnerability, please contact the maintainers privately
before public disclosure.
