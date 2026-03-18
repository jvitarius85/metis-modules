# Developer Guide

## Module Creation

- Create a manifest in `includes/modules/<module>/<module>.json`.
- Define views, assets, permissions, help topics, and optional services.
- Keep module logic behind the existing router, service, and UI layers instead of bypassing them.

## Router Usage

- Portal routes are derived from domain and view query vars.
- AJAX routes go through `/api/ajax` and should register secure controller definitions when possible.

## Service Architecture

- Register shared services through `includes/core/service_registry.php`.
- Reuse `Core_Settings_Service`, `Metis_Tables`, and existing module services before adding new abstractions.

## UI Services

- Extend the shared `Metis` JS namespace instead of shipping isolated frameworks.
- Use `data-help` attributes and manifest `help_topics` to connect UI elements to help content.

## Coding Standards

- Preserve the layered request path: router -> core services -> UI services -> modules.
- Prefer aggregated queries, indexed filters, lazy-loaded help metadata, and cached references on performance-sensitive paths.
