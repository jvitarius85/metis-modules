# METIS MODULE DEVELOPMENT CONTRACT
Version: 1.2

Defines behavioral rules for module development.

## Required References

Before building a module, read:
- `METIS_MODULE_SPEC.md`
- `METIS_SECURITY_MODEL.md`
- `METIS_FRONTEND_ARCHITECTURE.md`
- `METIS_UI_DESIGN_RULES.md`

## Development Rules

- request handling lives in module AJAX files, route handlers, or core services; modules do not require a `controllers/` folder
- business logic lives in services
- views render only
- routes register through the router
- actions register with the Secure Enclave
- cron tasks register through CronKernel
- shell commands register through ShellKernel
- assets follow frontend and UI rules
- do not create custom API stacks, alternate routing frameworks, or parallel ajax dispatchers
- declare API and page routes in `module.json` and `routes/*.php`, not in `bootstrap.php`
- do not bypass module manifest contracts for permissions, dependencies, or route ownership
- use core UI services for user feedback (toast/tooltip) instead of per-module frameworks
- process execution must not be used in web request handlers
- if process execution is required, it must run through a registered background worker with strict input allowlist and hash/path validation
- only approved OCR process execution is `finance_v2.recon_pdf_ocr`; all other module OCR/process paths are non-compliant

## Failure Behavior Requirements

- module compliance failure must hard-fail that module
- module compliance failure must not hard-fail the full platform boot
- failure reason must be emitted to audit/logging and exposed to admin diagnostics
- failed modules remain visible in module version reporting with status and reason
