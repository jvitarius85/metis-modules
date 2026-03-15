# Métis System Map

This document provides a high-level overview of the Métis platform structure.

The goal is to help developers, contributors, and AI coding tools quickly
understand how the system is organized.

---

# Platform Layers

```
Users
   │
   ▼
Router / Entry Point
   │
   ▼
Authentication + Permissions
   │
   ▼
Modules
   │
   ▼
Core Services
   │
   ▼
Database / External APIs
```

Hermes operates alongside modules and interacts with the system
through the service layer.

---

# Core Infrastructure

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

These components provide shared functionality used by all modules.

---

# Modules

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

Modules provide domain-specific capabilities while relying on
core services for common operations.

---

# Hermes Intelligence Layer

```
Hermes
   │
   ▼
Playbooks
   │
   ▼
Core Services
   │
   ▼
Modules
```

Hermes does not bypass the architecture. It interacts with the system
through defined playbooks and services.

---

# External Integrations

Métis may integrate with external systems such as:

- payment processors
- cloud storage providers
- email services
- authentication providers
- analytics platforms

These integrations occur through the platform API layer.

---

# Design Intent

The system map exists to provide clarity for developers and automation tools.

Key goals:

- clear architecture boundaries
- predictable service interactions
- maintainable module structure
- safe automation through Hermes
