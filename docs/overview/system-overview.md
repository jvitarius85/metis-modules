# System Overview

Metis is a modular portal for operations, donor management, finance, communications, governance, people administration, calendar, and drive workflows. The repository is organized around a layered request flow of `router -> core services -> UI services -> modules`.

## Major Capabilities

- **Board**: Board governance portal for meetings, decisions, actions, committees, and compliance.
- **Calendar**: Manage Google Calendar events from Metis.
- **Contacts**: View and organize all contacts across the organization.
- **Donations**: Manage donors, transactions, offline entries, deposits, and campaigns.
- **Drive**: Browse and manage files in the configured Google Shared Drive.
- **Finance**: Finance operations dashboard for deposits, settlement activity, campaign performance, ledger activity, reconciliations, and reporting.
- **Forms**: Build forms, publish public endpoints, and manage submissions.
- **Grandy's Stash**: Coordinate durable medical equipment intake, inventory, and community distribution.
- **Newsletter**: Create newsletters, manage templates, subscriptions, and delivery outcomes.
- **People**: Manage staff, board, volunteers, and system access.
- **Portal**: 
- **Profile**: Manage your profile, security methods, and notification preferences.
- **Settings**: Managing site settings and APIs
- **Website**: Manage pages & posts on the site.

## Architecture

- **Router**: `includes/core/router.php` and `src/Metis/Http` normalize requests, enforce middleware, and dispatch portal, AJAX, webhook, and cron traffic.
- **Core services**: `includes/core/service_registry.php` registers shared settings, DB, auth, router, backup, release, help, and walkthrough services.
- **UI services**: `assets/core.js` provides the shared UI runtime; module assets extend it without replacing the base layer.
- **Modules**: `includes/modules/*/*.json` define menus, views, permissions, assets, and extension hooks. PHP templates and module services implement behavior.
