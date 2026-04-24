# METIS UNIFIED DISPATCHER STANDARD
Version: 1.0

Defines the preferred solidification pattern for Metis execution.

## Standard

AJAX, webhook, cron, and shell execution should resolve through a single central Action Dispatcher service after Secure Enclave validation.

## Flow

`Entrypoint → Kernel → Secure Enclave → Action Dispatcher → Handler → Standard Response`

## Result

This reduces:
- duplicated execution logic
- inconsistent action handling
- security drift
- special-case bugs
