# MODULE BUILD CONTRACT
Version: 1.0

Short build contract for Codex / Claude.

Before building any module, read and follow:

- `METIS_DEVELOPMENT_PLAYBOOK.md`
- `METIS_ARCHITECTURE_CONTRACT.md`
- `METIS_MODULE_SPEC.md`
- `METIS_MODULE_GENERATOR_CONTRACT.md`
- `METIS_SECURITY_MODEL.md`
- `METIS_FRONTEND_ARCHITECTURE.md`
- `METIS_UI_DESIGN_RULES.md`

## Hard Rules

- do not change architecture
- do not create custom frameworks
- do not create alternate AJAX / webhook / cron / shell patterns
- do not bypass the Secure Enclave
- do not duplicate services
- do not use forbidden functions
- do not add shims, wrappers, or ghost files
