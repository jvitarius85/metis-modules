# Métis

![Architecture](https://img.shields.io/badge/architecture-modular-blue)
![Platform](https://img.shields.io/badge/platform-Metis-purple)
![AI Layer](https://img.shields.io/badge/AI-Hermes-indigo)
![Status](https://img.shields.io/badge/status-active-green)

## Overview

**Métis** is a modular operational platform designed to centralize governance,
communications, organizational data, and workflows within a unified system.

Rather than relying on fragmented SaaS tools, Métis provides a stable
infrastructure where people, information, and processes operate through
shared services and consistent interfaces.

Hermes serves as the intelligence layer that assists administrators with
automation, reporting, and operational insight.

---

## Key Features

- Modular platform architecture
- Centralized identity and permissions
- Governance and board management tools
- Integrated website builder
- File and document management
- Financial and donation tracking
- Reporting and analytics
- Hermes AI operational assistant

---

## Platform Architecture

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

Hermes interacts with the system through structured playbooks and the
platform service layer.

---

## Core Modules

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

---

## Hermes Intelligence Layer

Hermes enhances the platform by enabling:

- administrative automation
- contextual data queries
- operational insights
- workflow assistance
- reporting generation

Example requests:

```
List all board members who have permission to create users
```

```
Create a new staff user with Drive and Calendar access
```

---

## Documentation

Additional documentation can be found in the `/docs` directory:

- Architecture overview
- Hermes intelligence design
- System map
- Development guidelines
- Vision and roadmap

---

## Philosophy

Métis is designed with several guiding principles:

- modular architecture
- security-first design
- centralized shared services
- maintainability and clarity
- automation that respects governance

---

## Summary

Métis provides the **platform infrastructure**.

Hermes provides the **operational intelligence**.

Together they form a unified system designed to support organizations
with stability, automation, and strategic clarity.
