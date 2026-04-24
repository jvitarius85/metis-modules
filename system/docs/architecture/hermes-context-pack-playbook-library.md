# Hermes Context Pack and Playbook Library

## Purpose

This library provides the first loadable Hermes knowledge base for Metis using file-backed JSON definitions. It is designed to keep reasoning bounded, deterministic, and cheap to assemble at runtime.

The library is split into:

- static context packs in `config/hermes/context-packs/`
- playbooks in `config/hermes/playbooks/`
- missions in `config/hermes/missions/`
- a dynamic context schema in `config/hermes/dynamic-context.schema.json`
- a runtime dynamic snapshot in `storage/hermes/dynamic-context.json`

## Load Model

Hermes should load `config/hermes/library.json` first, then hydrate the referenced context packs, playbooks, and missions through the `hermes_library` service.

Dynamic reasoning overlays should merge in this order:

1. Static context pack definitions
2. Reviewed dynamic failure patterns, successful resolutions, and workflow signals
3. Session-specific evidence gathered during the current mission

Candidate dynamic records remain advisory. Reviewed records are the only ones intended to influence default playbook selection or recommended resolutions.

## Context Pack Coverage

The initial library covers these Hermes reasoning surfaces:

- `contacts`
- `donations`
- `communications`
- `board`
- `forms`
- `inventory`
- `cms_content`
- `reports`

Some packs map one-to-one with a Metis module. Others are composite reasoning surfaces:

- `communications` is grounded primarily in `newsletter` plus `board` announcements and `contacts`
- `inventory` is grounded in `grandys_stash`
- `cms_content` is grounded in `website` plus publish dependencies from `forms` and `donations`
- `reports` is a cross-module reporting surface rooted in `finance`

## Dynamic Context Layer

The dynamic layer stores three bounded record types:

- `failure_pattern`
- `successful_resolution`
- `workflow_signal`

Each record type has explicit required fields and indexing guidance in `config/hermes/dynamic-context.schema.json`.

Recommended operating rules:

- store only normalized signatures and evidence tags, not raw conversational history
- cap per-module active records to avoid unbounded prompt growth
- require repeated occurrences plus confidence thresholds before promotion to `reviewed`
- revalidate reviewed records on a fixed schedule so stale heuristics do not persist indefinitely

## Performance Notes

The library is intentionally file-backed and indexed by key inside the loader service so request-time lookup stays constant within a process.

For future persistence work:

- keep dynamic learning in aggregated tables or snapshot files keyed by module and signature
- index by `module_keys`, `review_state`, and recency fields
- materialize high-use diagnostic summaries instead of recomputing full mission history
- load only the packs and playbooks required by the current request rather than the entire library when Hermes routing is in place
