# METIS ACTION PIPELINE
Version: 1.0

Defines the unified execution model used by AJAX, webhooks, cron, and shell.

## Pipeline

`Entrypoint → Kernel → Secure Enclave → Action Dispatcher → Handler → Standard Response`

## Dispatcher Responsibilities

The dispatcher must:
- resolve action name
- verify action registration
- verify permissions
- invoke the mapped handler
- return a standard response

## Process-Bound Actions

- actions that trigger OCR/process execution must be explicit, registered, and non-generic
- approved OCR worker action: `finance_v2.recon_pdf_ocr`
- dispatcher and worker must reject any payload that attempts to alter command/runtime targets
- non-approved process-bound actions must be denied

## Benefit

This creates one predictable execution model across:
- AJAX
- webhooks
- cron
- shell
