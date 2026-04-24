# METIS DEVELOPMENT PLAYBOOK
Version: 1.0

This is the master governance file for Metis. All contributors and AI systems must read this document before modifying the platform.

## Authority

Architecture, security, and system rule changes require direct approval from the project owner.

An architecture, security, or workflow change is only allowed if:
- the owner is consulted first
- the technical reason is documented
- the change follows all security protocols
- the change does not introduce duplication, drift, or hidden compatibility layers

## Required Document Order

Read and follow these documents before coding:

1. `METIS_ARCHITECTURE_CONTRACT.md`
2. `METIS_SECURITY_MODEL.md`
3. `METIS_SYSTEM_FLOW.md`
4. `METIS_CORE_SERVICES.md`
5. `METIS_ACTION_PIPELINE.md`
6. `METIS_ACTION_REGISTRY.md`
7. `METIS_MODULE_SPEC.md`
8. `METIS_MODULE_DEVELOPMENT_CONTRACT.md`
9. `METIS_MODULE_GENERATOR_CONTRACT.md`
10. `METIS_FRONTEND_ARCHITECTURE.md`
11. `METIS_UI_DESIGN_RULES.md`
12. `METIS_DATA_MODEL_RULES.md`
13. `METIS_ERROR_HANDLING.md`
14. `METIS_CODE_STYLE_GUIDE.md`
15. `METIS_TESTING_RULES.md`

## Non-Negotiable Development Rules

- Do not invent architecture.
- Do not introduce legacy support layers, shims, ghost files, or fallback wrappers.
- Do not duplicate services, helpers, patterns, or UI systems.
- Do not bypass the Secure Enclave.
- Do not create alternate AJAX, webhook, cron, or shell patterns.
- Do not create custom modal, toast, routing, or form frameworks.
- Do not hard-code configuration.
- Do not write runtime data outside `storage/`.

## AI-Specific Rules

AI systems must:
- search for an existing service before creating a new one
- use existing patterns and registries
- refuse architecture changes until owner approval exists
- keep the system simple, readable, and consistent
- prefer multi-screen workflows when they reduce confusion
- keep end-user interactions easy to understand and follow

## Delivery Rules

All generated code must be:
- secure
- modular
- readable
- maintainable
- consistent with every contract in this pack
