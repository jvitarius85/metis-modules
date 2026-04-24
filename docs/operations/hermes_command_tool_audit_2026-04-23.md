# Hermes Command / Tool Audit

Date: April 23, 2026

## Summary

- Pre-flight backup completed and validated at `/Volumes/NAS/backups 2/hermes_full_20260423_224130/`.
- Hermes now has a mandatory conversational parser layer before command selection.
- Required command coverage is registered through strict tools with enclave routing metadata.
- Hermes execution now routes through [`core/enclave/execute.php`](/Users/jvitarius85/Library/CloudStorage/CloudMounter-TheHRDeptNAS/Web/metis/core/enclave/execute.php:1) instead of direct service dispatch inside [`HermesExecutionEngine`](/Users/jvitarius85/Library/CloudStorage/CloudMounter-TheHRDeptNAS/Web/metis/src/Metis/Hermes/HermesExecutionEngine.php:1).
- Queryable parser/tool/result traces are persisted through `metis_hermes_command_logs`.

## Audit Findings

| Area | Before | After |
| --- | --- | --- |
| Conversational parsing | Phrase-match parser only | Mandatory normalization, fragmenting, entity pre-resolution, context checks, ranking, clarification, multi-step plan |
| Command coverage | 24 legacy commands | Required command set registered in `HermesCommandRegistry` |
| Tool registry | 5 generic tools | Full strict tool definitions with schemas, approvals, risk, worker support, enclave action |
| Execution path | Direct service invocation from Hermes execution engine | Centralized enclave entrypoint via `core/enclave/execute.php` |
| Confidence gating | Not enforced end-to-end | Medium/low confidence blocked with clarification prompt |
| Multi-step commands | Not first-class | Execution plan stored and executed sequentially |
| Queryable logs | Audit stream only | `metis_hermes_command_logs` table captures parser + tool + result metadata |

## Required Command Coverage

All required commands are registered and mapped to tools:

- USER: `create_user`, `update_user`, `disable_user`, `enable_user`, `assign_role`, `remove_role`, `list_users`, `get_user`
- SYSTEM: `clear_cache`, `rebuild_indexes`, `reload_config`, `get_system_status`
- DIAGNOSTICS: `run_full_diagnostics`, `scan_integrity`, `check_db`, `check_workers`
- RECOVERY: `recover_module`, `restore_file`, `rollback_module`
- MODULE: `enable_module`, `disable_module`, `install_module`, `update_module`
- DATA: `export_data`, `import_data`, `deduplicate`
- WORKERS: `create_job`, `cancel_job`, `retry_job`, `list_jobs`
- SECURITY: `audit_permissions`, `verify_integrity`, `rotate_keys`
- METIS: `validate_routes`, `verify_nonce`, `run_enclave_test`

## Residual Risks

- Several recovery/module/data/security operations are intentionally routed as queued enclave-backed jobs because their underlying domain-specific executors are not centralized in Hermes yet.
- The legacy `HermesIntentParser` still exists as a fallback classifier for unmatched fragments and data-intent paths.
- Existing unrelated workspace changes remain in the worktree and were not modified.
