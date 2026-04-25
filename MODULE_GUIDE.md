# Module Development Guide

Modules provide functional capabilities within Métis.

## Structure

Example module layout:

```
module/
├── controller
├── service
├── api
├── ui
```

## Rules

Modules should:

- rely on core services
- avoid duplicated logic
- enforce permission checks
- maintain clear separation of responsibilities

## Example Modules

```
modules/
├── people
├── communications
├── donations
├── drive
├── governance
├── website
```
