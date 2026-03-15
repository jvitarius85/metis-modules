# Métis Architecture

Métis is designed as a modular operational platform built around shared infrastructure services.
The architecture separates system infrastructure, operational modules, and intelligence capabilities
into clear layers so the system remains stable, maintainable, and extensible.

## Architectural Layers

### Core Infrastructure

The core layer provides foundational services used by all modules.

Examples include:

- authentication
- permissions
- routing
- API services
- UI service layer
- logging
- caching
- configuration management

Example structure:

```
core/
├── auth
├── permissions
├── router
├── services
├── api
├── ui
├── logging
├── cache
```

### Modules

Modules represent functional capabilities of the platform.

```
modules/
├── people
├── communications
├── donations
├── drive
├── calendar
├── governance
├── reports
├── website
```

Modules should rely on core services instead of duplicating logic.

### Hermes Intelligence Layer

Hermes operates as a service layer capable of interacting with platform modules.

Hermes executes actions through the same service interfaces used by the platform,
ensuring consistent permission enforcement and audit logging.

## Request Flow

```
User Request
     │
     ▼
Router
     │
     ▼
Permissions / Authentication
     │
     ▼
Module Controller
     │
     ▼
Core Services
     │
     ▼
Response
```

Hermes requests follow the same flow.
