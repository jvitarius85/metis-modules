# METIS SYSTEM FLOW
Version: 1.0

Defines the runtime flow used across Metis.

## Core Flow

`Request → Entrypoint → Kernel → Secure Enclave → Router / Dispatcher → Controller / Service → Response`

## HTTP Flow

`browser → index.php → Kernel → Router → Module Controller → Response`

## AJAX Flow

`frontend → /api/ajax → system/ajax.php → AjaxKernel → Secure Enclave → Action Dispatcher → Service / Controller → JSON`

## Webhook Flow

`external provider → /webhook/{provider} → system/webhooks.php → WebhookKernel → signature validation → action dispatcher → handler → response`

## Cron Flow

`system cron → system/cron.php → CronKernel → scheduled action dispatch → logs`

## Shell Flow

`cli → system/shell.php → ShellKernel → command registry → action dispatch → output`

## Module Load Flow

`Kernel → ModuleLoader → module.json validation → dependency validation → Module.php boot/register → routes/actions/assets available`
