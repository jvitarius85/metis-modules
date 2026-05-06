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

## Website CMS

Website is the official CMS surface for Metis. CMS behavior lives in
`system/modules/website` and `system/src/Metis/Modules/Website`; a separate CMS
module is not required.

The Website CMS supports pages, posts, templates, menus, media-backed content,
reusable blocks, autosaved drafts, revisions, revision compare/restore, preview,
publish/update states, scheduled content, redirects, banners, popups, theme
controls, and public rendering. The editor uses the shared Metis admin UI with a
block library, visual canvas, page/block settings panels, local draft recovery,
and permission-gated controls.

Website permissions remain granular. View, create, edit, publish, delete, media,
menu, banner, popup, redirect, template, theme, reusable block/web part, launch,
and import actions must be enforced on both the UI and server-side AJAX/action
paths. Secure Enclave protected operations must continue to use Website action
names and Website permissions.

Rich text, HTML blocks, reusable blocks, templates, banners, popups, web parts,
and public rendering share the core `metis_runtime_kses_post()` sanitization
boundary. Unsafe tags, inline event handlers, unsafe URL schemes, and dangerous
style values are stripped before storage/rendering. Trusted raw script or iframe
embeds are not allowed by default.

Preview should use the same structured layout contract as public rendering. If
the canvas is approximate, the preview path should favor server-rendered output
so draft and published pages stay consistent.

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

## System Requirements

Baseline requirements:

- PHP 8.1 or newer
- MariaDB or MySQL with InnoDB
- Apache with rewrite support, or Nginx with equivalent server rules
- Writable `storage/` and `system/config/`
- PHP process functions enabled: `proc_open`, `proc_close`,
  `proc_get_status`, and `proc_terminate`
- PHP `curl` and `zip` extensions
- 2 GB RAM minimum, 4 GB preferred
- 2 CPU cores minimum
- 60 GB storage minimum, 100 GB preferred
- PHP memory limit of 256 MB minimum, 512 MB preferred

See [INSTALL.md](INSTALL.md) for the full server and installer requirements.

## Updates

Metis supports trusted release checks against the configured GitHub repository.
Admins can review available releases and apply trusted updates from the admin
portal. Updates require preflight checks and a pre-update backup before files are
changed.

Release discovery uses repository metadata in `meta/releases.json` first, then
falls back to Git tags when the server supports Git process execution. When
publishing a release, update the version, generate the manifest entry, then tag
the release:

```bash
php system/tools/release_manifest.php add v1.9.4
```

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
