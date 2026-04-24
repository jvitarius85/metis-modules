# Installation

## Server Requirements

- PHP 8.1+ with JSON, mbstring, OpenSSL, PDO/MySQLi, and file upload support.
- MariaDB or MySQL compatible with the schema created through `dbDelta` installers.
- Writable access for logs and storage paths used by Metis.

## Setup

1. Place the repository in the web root or application directory expected by your standalone runtime.
2. Configure `config/database.php` and environment-specific settings before first boot.
3. Ensure `src/Metis/Core/DatabaseRuntime.php` runs so core tables and module schema managers can create their tables.
4. Open the portal, authenticate, and complete initial settings for branding, API keys, workspace integration, help, and scheduling.

## First Boot

- The core bootstrap loads autoloading, service registration, routing, security boundaries, and module manifests.
- Modules boot from `modules/*` and may run schema installers from their namespaced services.
- Initial admin work should include settings review, backup configuration, cron secret setup, and help system verification.
