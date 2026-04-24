# Metis Inbound Email Engine

## Architecture Overview

Metis inbound email now runs as a hidden `communications_inbound` module on top of the existing shared runtime:

1. Gmail publishes mailbox change notifications to Google Pub/Sub.
2. Pub/Sub pushes to the shared Metis webhook gateway at `gmail_pubsub`.
3. The webhook verifier validates the Google-signed bearer JWT, decodes the Pub/Sub envelope, and resolves the mailbox.
4. The webhook handler enqueues a background mailbox sync job instead of doing long work inline.
5. The sync worker loads Gmail history or runs a bounded full-sync fallback when the stored history cursor is no longer valid.
6. Each fetched Gmail message is normalized into a provider-neutral message shape.
7. The normalized message is persisted before parser/handler execution.
8. The parser engine runs registered parsers in explicit priority order.
9. The selected handler updates domain state and stores linkage records.
10. Message state, handler events, and linkage rows remain queryable for replay, recovery, and review.

## Data Model

New inbound core tables:

- `communications_inbound_mailboxes`
- `communications_inbound_messages`
- `communications_inbound_events`
- `communications_inbound_links`

Grandy’s Stash was extended with:

- `grandys_stash_messages`

The mailbox table stores runtime watch state, current Gmail history cursor, and sync errors. The message table stores raw provider payload, normalized headers/bodies, parser/handler state, and dedupe keys. The events table records processing steps and failures. The links table connects inbound messages to downstream entities. `grandys_stash_messages` stores threaded ticket communication history without hardwiring Stash rules into the core engine.

## Configuration

All setup lives in **Settings → API & Endpoints**.

Required values:

- Google Cloud project ID
- Pub/Sub topic or full topic path
- Pub/Sub push audience
- Pub/Sub push service account email
- Google Workspace service account credential
- Enabled mailboxes JSON

Optional values:

- logging verbosity
- full sync lookback days
- manual reprocess toggle
- individual handler toggles

Mailbox JSON example:

```json
[
  {
    "mailbox_email": "newsletter@example.org",
    "display_name": "Newsletter",
    "enabled": true,
    "delegated_user": "newsletter@example.org",
    "label_ids": ["INBOX"],
    "label_filter_behavior": "include"
  }
]
```

## Watch Setup And Renewal

The Gmail watch endpoint is the shared webhook gateway URL shown in Settings for `gmail_pubsub`.

Operational commands:

```bash
php tools/communications_inbound_watch.php status
php tools/communications_inbound_watch.php watch --mailbox=newsletter@example.org --force
php tools/communications_inbound_watch.php renew-due
php tools/communications_inbound_watch.php sync --mailbox=newsletter@example.org
```

Watches are renewed by the `communications_inbound_watch_renewal` cron task every 6 hours. Mailboxes due within one day are renewed.

## Adding A New Parser

1. Create a class under `src/Metis/Modules/CommunicationsInbound/Parsers/`.
2. Implement `MessageParserInterface`.
3. Return `ParseResult::matched(...)` with a stable classification and handler key.
4. Register the parser in `CommunicationsInboundModule::registerParsersAndHandlers()`.
5. Add fixtures and tests covering the new match logic.

Parser rules must only inspect `NormalizedInboundMessage`. They should not mutate storage or domain state directly.

## Adding A New Handler

1. Create a class under `src/Metis/Modules/CommunicationsInbound/Handlers/`.
2. Implement `MessageHandlerInterface`.
3. Keep business updates inside the handler.
4. Return a structured result with `handled`, `status`, `metadata`, and optional `links`.
5. Register the handler in `CommunicationsInboundModule::registerParsersAndHandlers()`.

Handlers are invoked only after the raw inbound message has already been persisted.

## Recovery, Replay, And Failure Notes

- Duplicate Pub/Sub deliveries are absorbed by the shared webhook event table and per-job dedupe keys.
- Duplicate Gmail messages are absorbed by the inbound message dedupe key and unique provider/mailbox/message index.
- Gmail history gaps fall back to a bounded full sync using the configured lookback window.
- Parser failures are captured as parser errors and do not silently discard the message.
- Handler failures leave the message in a failed state with an error trace in the inbound event log.
- Manual replay is available through `php tools/communications_inbound_watch.php reprocess --message-id=...` when reprocessing is enabled.

## Google-Side Setup Placeholders

Google-side setup still requires environment-specific values:

- create or choose the Pub/Sub topic
- configure the Gmail watch for each mailbox
- configure the authenticated push subscription to the Metis webhook URL
- set the push audience to the exact Metis webhook URL
- assign the push service account used by Pub/Sub
- grant Gmail API and domain-wide delegation scopes to the Workspace service account

Recommended Gmail scope for this feature:

- `https://www.googleapis.com/auth/gmail.modify`
