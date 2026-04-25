# Metis

Metis is a modular operations platform for organizations that need one place for
people, permissions, communications, donations, files, finance, governance,
website content, automation, and system administration.

The platform is designed as a standalone PHP application with a browser-based
installer, a shared core UI, a module system, Secure Enclave protected actions,
audited operations, backups, recovery services, and trusted release updates.

## What Metis Provides

- Centralized identity, users, roles, and permissions
- Modular admin portal with consistent UI patterns
- Donations, deposits, donors, and reporting
- Board and governance workflows
- Contacts, people, newsletter, forms, media, drive, calendar, and website tools
- Basic finance and reconciliation workflows
- Hermes operational assistant with permission-aware actions
- Secure Enclave enforcement for sensitive operations
- Browser installer with system checks and first-admin setup
- Backup, recovery, integrity, and release management services

## Current Official Modules

Official modules are defined by trusted repository metadata.

- Portal
- People
- Profile
- Settings
- Contacts
- Donations
- Drive
- Calendar
- Finance
- Newsletter
- Forms
- Board
- Website
- Hermes
- Media
- Help

Required modules cannot be disabled because they provide core platform access,
identity, profile, and settings behavior.

## Repository Layout

```text
index.php              Application entry point
system/                Core source, modules, config, assets, tools, docs
system/src/            Core PHP services and runtime
system/modules/        Metis modules
system/assets/         Shared runtime assets
system/config/         Local configuration templates and policies
system/tools/          CLI maintenance tools
system/docs/           Detailed system documentation
storage/               Runtime storage, logs, cache, uploads, backups
```

## Installation

Metis is installed from the browser. After files are uploaded or cloned to a
server, open the site URL and follow the installer.

The installer validates requirements, checks writable folders, verifies database
credentials, creates the configuration file, installs core and module tables,
creates the first administrator, enables protections, and redirects to the admin
portal.

See [INSTALL.md](INSTALL.md) for the installation checklist.

## Updates

Metis supports trusted release checks against the configured GitHub repository.
Admins can review available releases and apply trusted updates from the admin
portal. Updates require preflight checks and a pre-update backup before files are
changed.

## Security

Metis uses layered security controls:

- role and permission checks
- CSRF/nonce validation
- Secure Enclave policy enforcement
- protected filesystem paths
- audit logging
- rate-limit and abuse checks
- integrity and recovery services

See [SECURITY.md](SECURITY.md) for security expectations and vulnerability
reporting.

## Roadmap

The public roadmap focuses on release trust, provider architecture, operational
visibility, optional integrations, UI consistency, and product expansion.

See [ROADMAP.md](ROADMAP.md).
