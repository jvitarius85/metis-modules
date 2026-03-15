# Hermes Intelligence Layer

Hermes is the intelligence engine embedded within the Métis platform.

Hermes assists administrators and users with operational tasks, system navigation,
data interpretation, and workflow automation.

Hermes operates strictly within the security and permission boundaries of Métis.

## Design Goals

Hermes is designed to:

- automate repetitive operational tasks
- interpret platform data
- provide contextual insights
- assist administrators with system operations
- interact safely with platform modules

## Example Capabilities

### Administrative Operations

```
Create a new staff user with Drive and Calendar access
```

### Data Queries

```
List all board members who have permission to create users
```

### Reporting

```
Generate the monthly donations summary
```

## Execution Model

Hermes does not directly manipulate the database.

```
Hermes
   │
   ▼
Platform Service Layer
   │
   ▼
Module Logic
   │
   ▼
Database
```

This ensures system integrity and proper permission validation.
