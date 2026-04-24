# METIS MODULE SPEC
Version: 1.1

Defines the required technical structure and compliance rules for every module.

## Required Structure

```text
modules/<module_name>/
    module.json
    Module.php
    controllers/
    services/
    views/
    routes/
    assets/
        css/
        js/
    migrations/
```

## Naming Rules

Module folder names must be:
- lowercase
- snake_case
- no spaces

The folder name must match `module.json:slug` when present, and must map to the module key used by the loader.

## Manifest Fields

Required:
- `slug`
- `title`
- `version`
- `description`
- `entry`
- `routes`
- `views`

Optional:
- `dependencies`
- `assets`
- `permissions`
- `services`
- `controllers`

## Module Rules

- module logic stays inside the module
- no direct modification of core files
- no direct access to other module internals
- all routes must use the core router
- all actions must register with the Secure Enclave
- route declarations must be defined in `module.json -> routes` and handled through module route handlers
- module bootstrap files must not register custom `/api` routes
- module manifest permissions must be structurally valid arrays/objects accepted by the core validator
- dependency declarations must resolve before module boot
- non-compliant modules are disabled at boot and must not block platform startup

## Compliance Enforcement

- all modules are validated by `ModuleValidator` during discovery and registration
- validation failures are hard module failures and remove the module from active runtime
- boot failures are surfaced in UI and module-version reporting
- compliance checks must pass for release and deployment gates
