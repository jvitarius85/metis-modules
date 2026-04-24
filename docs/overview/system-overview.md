# System Overview

Metis is a modular portal for operations, donor management, finance, communications, governance, people administration, calendar, and drive workflows. The repository is organized around a layered request flow of `router -> core services -> UI services -> modules`.

## Major Capabilities

- **Board Administration**: Plan meetings, track decisions, and keep board work organized.
- **Calendar**: Schedule events and keep calendars in sync.
- **Contacts**: View, organize, and update shared contact records.
- **Donations**: Track donors, gifts, deposits, and fundraising activity.
- **Drive**: Browse shared files and manage team documents.
- **Finance**: Finance V2 foundation with scheduled org mode switching.
- **Forms**: Build forms, share them, and review submissions.
- **Grandy's Stash**: Track equipment intake, inventory, and distributions.
- **Hermes**: Monitor system health, review recommendations, and run approved actions.
- **Import**: Import content into Metis from supported export formats.
- **Media**: Centralized media library for uploads used by builders and content modules.
- **Newsletter**: Create email campaigns, manage newsletter theme settings, and track delivery.
- **People**: Manage people, roles, access, and activity.
- **Portal**: Open shared dashboards and common workspace tools.
- **Profile**: Manage your profile, security methods, and notification preferences.
- **Settings**: Configure workspace settings, integrations, and system tools.
- **Website**: Structured website management for pages, posts, categories, menus, redirects, templates, and theme.

## Architecture

- **Router**: `src/Metis/Core/Routing/RouterRuntime.php` and `src/Metis/Http` normalize requests, enforce middleware, and dispatch portal, AJAX, webhook, and cron traffic.
- **Core services**: `src/Metis/Core/ServiceRegistryRuntime.php` registers the compatibility-backed settings, DB, auth, router, backup, release, help, and walkthrough services used by the standalone runtime.
- **UI services**: `assets/core.js` provides the shared UI runtime; module assets extend it without replacing the base layer.
- **Modules**: `modules/*/*.json` define menus, views, permissions, assets, and extension hooks. PHP templates and module services implement behavior.
