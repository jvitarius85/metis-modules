# Inbound Email Implementation Notes

## Summary

Implemented a production-oriented inbound email core service for Metis using the existing webhook gateway, job queue, settings system, audit model, and service conventions.

## Major Changes

- Added a core-owned communications inbound runtime with Gmail Pub/Sub webhook provider registration.
- Added mailbox state, inbound message, event, and entity-link persistence tables.
- Added Gmail watch and history-sync client with bounded full-sync fallback for expired history cursors.
- Added provider-neutral inbound message normalization for Gmail payloads.
- Added parser registry, handler registry, parser engine, and structured parse results.
- Added first-class deterministic parsers and handlers for bounce, unsubscribe, and Grandy’s Stash.
- Extended Grandy’s Stash schema with `grandys_stash_messages` to store threaded communication history.
- Extended Grandy’s Stash ticket detail with a conversation timeline and staff reply composer backed by Gmail send through the configured Stash mailbox.
- Added API settings fields and an operator CLI for watch/sync/reprocess flows.

## Phase Log

- Phase 0: existing webhook, jobs, settings, audit, and Grandy’s Stash architecture inspected.
- Phase 1/2: inbound module, schemas, mailbox state, parser contracts, and settings wiring implemented.
- Phase 3/4/5/6: Gmail Pub/Sub verification, Gmail watch/history sync, normalization, and handler pipeline implemented.
- Phase 7: operator CLI, docs, fixtures, and automated coverage added.

## Known External Dependencies

- Google Cloud Pub/Sub authenticated push configuration
- Gmail watch setup per mailbox
- Google Workspace domain-wide delegation on the configured service account
- Google Workspace domain-wide delegation must include both `https://www.googleapis.com/auth/gmail.modify` and `https://www.googleapis.com/auth/gmail.send` for any mailbox that should send Grandy’s Stash replies

## Safety Notes

- Raw provider payload is stored before business handling.
- Message and handler events are idempotent by stable dedupe keys.
- Webhook requests reject invalid bearer JWTs and malformed Pub/Sub envelopes.
- Mailbox sync state is stored separately from parser/handler state to keep recovery reviewable.
