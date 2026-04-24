# METIS SECURITY MODEL
Version: 1.2

Defines the security model for Metis.

## Core Principles

- centralized validation
- explicit action registration
- minimal trust boundaries
- server-side enforcement
- auditability
- fail-closed module compliance enforcement

## Secure Enclave

All executable actions must be registered in the Secure Enclave.

Examples:
- `people.create`
- `people.update`
- `finance.transaction.record`

Unregistered actions must fail.

## Validation Order

Every executable action must pass:
1. authentication
2. authorization
3. action registration validation
4. request validation
5. input validation

Module loading and release operations must also pass:
1. module manifest and structure validation
2. route ownership and registration policy checks
3. dependency resolution
4. compliance gate checks before release apply/rollback

## Supported Authentication

- password login
- passkeys
- approved SSO integrations

## Forbidden Functions

These must not exist anywhere in the repository:

- `eval`
- `exec`
- `shell_exec`
- `system`
- `passthru`

## Process Execution Policy

- runtime process execution is denied by default
- no module may execute arbitrary commands from request data
- only the approved Finance reconciliation OCR worker path may execute OCR subprocesses
- allowed job type: `finance_v2.recon_pdf_ocr`
- allowed purpose: parse uploaded reconciliation PDF statements only
- all other module-level process execution attempts must fail-closed
- OCR execution must validate file path allowlist, file hash, file type, and size before processing
- OCR execution must run in background worker/cron context, not direct web request context

## Logging

Security events must be logged in `storage/logs`.

Required security/audit events include:
- module compliance failures
- cron task failures (including compliance audit failures)
- release operations blocked by integrity or module compliance
