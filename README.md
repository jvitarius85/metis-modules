# Métis Platform

![Architecture](https://img.shields.io/badge/architecture-modular-blue)
![System](https://img.shields.io/badge/platform-Metis-purple)
![AI Layer](https://img.shields.io/badge/AI-Hermes-indigo)
![Status](https://img.shields.io/badge/status-active-green)

---

# Overview

**Métis** is a modular operational platform designed to centralize governance, communications, data management, and organizational workflows within a unified system.

Rather than relying on fragmented SaaS tools, Métis provides a coordinated infrastructure where people, information, and institutional processes operate through a consistent architecture and shared services layer.

The platform emphasizes:

- operational clarity  
- long‑term maintainability  
- secure governance  
- modular extensibility  

---

# Hermes Intelligence Layer

**Hermes** is the embedded intelligence engine within the Métis platform.

Where Métis provides the infrastructure, Hermes provides **reasoning, automation, and system‑aware assistance**.

Hermes operates within the permission and security model of the platform and interacts directly with system services and modules.

Capabilities include:

- administrative automation
- contextual data queries
- operational insights
- workflow assistance
- reporting generation

Hermes is designed as an **operational intelligence layer**, not a standalone chatbot.

---

# System Relationship

| Component | Responsibility |
|----------|---------------|
| Métis | Platform infrastructure and operational modules |
| Hermes | Automation, reasoning, and system intelligence |

Métis provides the **structure**.  
Hermes provides the **intelligence**.

---

# Core Platform Capabilities

## Identity & Access

- User management
- Role‑based permissions
- Access governance
- Audit visibility

## Communications

- Newsletter distribution
- Notifications
- Messaging integrations
- Event coordination

## Data & Documents

- Organizational file storage
- Document governance
- Folder structure management
- External storage integrations

## Governance

- Board portals
- Organizational recordkeeping
- Policy management
- Meeting materials

## Financial Operations

- Donation tracking
- Transaction records
- Financial reporting
- Payment integrations

## Reporting & Analytics

- Operational dashboards
- Data exports
- Audit logs
- System reports

## Website Builder

Métis includes a built-in **website builder and publishing system** designed to allow organizations to manage public-facing content without relying on external CMS platforms.

The builder provides a structured editing environment that enables administrators to create pages, landing sections, and content layouts while maintaining consistent design standards across the organization’s website.

Key features include:

- visual page composition
- reusable layout components
- dynamic content blocks
- SEO-friendly page generation
- structured navigation management
- publishing workflows
- accessibility-aware design

Unlike traditional CMS platforms, the Métis website builder is integrated directly with the platform’s identity, communications, and data modules. This allows organizations to connect their public presence with internal systems such as newsletters, events, and reporting tools.

The builder is designed to prioritize **simplicity for editors and maintainability for developers** while preserving a consistent interface across the entire platform.
---

# Hermes Example Requests

Hermes can assist administrators and operators with natural instructions such as:

```
List all board members who have permission to create users
```

```
Create a new staff user with Drive access and board portal permissions
```

```
Generate the monthly donations report
```

All actions pass through the Métis service layer to ensure permission enforcement and audit logging.

---

# Architecture

```
Users
   │
   ▼
+------------------------+
|        Métis Core      |
|------------------------|
| Authentication         |
| Permissions            |
| Core Services          |
| API Layer              |
| UI Services            |
| Logging / Audit        |
+-----------+------------+
            │
            ▼
  +---------+---------+
  |     Modules       |
  |-------------------|
  | People            |
  | Communications    |
  | Donations         |
  | Drive             |
  | Governance        |
  | Reports           |
  +---------+---------+
            │
            ▼
       +-----------+
       |  Hermes   |
       |-----------|
       | Automation|
       | Queries   |
       | Insights  |
       +-----------+
```

---

# Module Structure

```
modules/
├── people
├── communications
├── donations
├── drive
├── calendar
├── governance
├── reports
```

Core services:

```
core/
├── auth
├── permissions
├── services
├── api
├── ui
├── logging
├── caching
```

---

# Security Model

Métis is designed with a security‑first architecture.

Security principles include:

- role‑based access control
- permission‑gated system actions
- audit logging of sensitive operations
- controlled service execution
- minimal trust boundaries between modules

Hermes actions must always execute through the same permission layer used by human users.

This prevents automation from bypassing governance controls.

---

# Development Standards

The platform follows consistent development guidelines to maintain clarity and stability.

### Code Principles

- avoid duplicated logic
- centralize shared services
- modularize large components
- enforce permission checks
- maintain clean routing structures

### Frontend Principles

- shared core CSS
- module‑specific styling layers
- minimal table usage
- responsive UI components
- accessible interface design

### Performance

- caching of repeated queries
- asynchronous background tasks
- minimized external API calls
- modular asset loading

---

# Hermes Capability Framework

Hermes interacts with the platform through defined playbooks.

Example capability domains include:

### Administrative Operations

- user management
- permission adjustments
- account provisioning

### Data Analysis

- report generation
- financial summaries
- activity analysis

### Workflow Automation

- scheduled processes
- background tasks
- operational reminders

### System Assistance

- answering operational questions
- locating information
- assisting with configuration

Each Hermes capability operates through structured service calls to ensure transparency and maintainability.

---

# Intended Use

Métis is designed for organizations that require structured operational infrastructure without depending on fragmented SaaS ecosystems.

Typical environments include:

- nonprofit organizations
- community initiatives
- governance boards
- membership organizations
- operational teams

The platform provides a stable foundation for managing organizational processes, institutional knowledge, and operational coordination.

---

# Summary

Métis provides the **system infrastructure**.

Hermes provides the **operational intelligence**.

Together they form a unified platform designed to support organizations with stability, automation, and strategic clarity.
