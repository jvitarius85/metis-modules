# Hermes Documentation-Aware Knowledge Architecture

## Purpose

This specification defines the documentation-aware knowledge layer for Hermes. It enables Hermes to answer instructional questions, open documentation, launch walkthroughs, and attach module references using local Metis documentation assets only.

Hermes knowledge grounding for this layer is limited to repository-backed sources:

- `/docs/**/*.md`
- `/docs/help-index.json`
- `/docs/walkthroughs.json`
- module manifests and Hermes static definitions already stored in the Metis repository

This layer must not depend on WXR APIs, remote APIs, or live external documentation calls. It operates on local files, cached compiled indexes, and existing Metis service contracts.

## Goals

- ground instructional answers in documented Metis behavior
- distinguish help questions from diagnostics, playbooks, and mission workflows
- prevent hallucinated instructions when documentation exists
- enforce permission-aware responses before showing steps or actions
- detect stale or broken documentation references
- keep request-time lookup fast through compiled local indexes and background refresh

## Service Topology

### HermesKnowledgeService

Top-level orchestration service for documentation-aware knowledge retrieval.

Responsibilities:

- classify knowledge intent for each user query
- load the minimal documentation context required for the request
- coordinate help topic, walkthrough, and module-document resolution
- assemble evidence bundles for the reasoning engine
- return typed knowledge responses to Hermes UI or reasoning pipelines

Primary methods:

- `classifyIntent(QueryContext $context): KnowledgeIntent`
- `resolve(QueryContext $context): KnowledgeResolution`
- `answerHelp(QueryContext $context): GroundedHelpAnswer`
- `openDocument(string $docPath, ActorContext $actor): DocumentReference`
- `launchWalkthrough(string $walkthroughId, ActorContext $actor): WalkthroughLaunchPlan`

### HermesDocumentationIndex

Compiled local documentation index built from `/docs`, `help-index.json`, `walkthroughs.json`, and module metadata.

Responsibilities:

- ingest and normalize documentation assets
- build searchable topic, document, module, and walkthrough records
- maintain reverse references between topics, docs, modules, permissions, and walkthroughs
- expose efficient lookup APIs for request-time retrieval
- publish validation metadata such as checksums, timestamps, and unresolved links

Primary methods:

- `build(): DocumentationSnapshot`
- `load(): DocumentationSnapshot`
- `findTopics(string $query, int $limit): array`
- `findDocuments(array $filters, int $limit): array`
- `getTopic(string $topicId): ?DocumentationTopic`
- `getWalkthrough(string $walkthroughId): ?WalkthroughDefinition`
- `getModuleDocs(string $moduleKey): array`

### HermesHelpResolver

Deterministic resolver for help-oriented questions.

Responsibilities:

- map a user query to one or more help topics
- retrieve the canonical instructional source set
- filter steps and links by actor permissions
- build concise grounded answers with citations to help topics and docs
- emit fallback states when documentation is missing or ambiguous

Primary methods:

- `resolveHelp(QueryContext $context, DocumentationSnapshot $snapshot): HelpResolution`
- `rankTopics(QueryContext $context, array $candidateTopics): array`
- `buildAnswer(HelpResolution $resolution): GroundedHelpAnswer`

### HermesWalkthroughResolver

Resolver for interactive guidance requests.

Responsibilities:

- map requests to walkthrough definitions in `/docs/walkthroughs.json`
- confirm referenced topics, modules, and UI targets are internally valid
- ensure the actor can access the module and underlying workflow
- produce launch metadata for Hermes UI surfaces
- fall back to documented manual steps if no valid walkthrough can run

Primary methods:

- `resolveWalkthrough(QueryContext $context, DocumentationSnapshot $snapshot): WalkthroughResolution`
- `validateLaunchability(WalkthroughDefinition $walkthrough, ActorContext $actor): WalkthroughLaunchability`
- `buildLaunchPlan(WalkthroughResolution $resolution): WalkthroughLaunchPlan`

### HermesGroundingValidator

Guardrail service that verifies Hermes instructional output is traceable to documentation.

Responsibilities:

- require documented evidence for help-mode answers when matching documentation exists
- reject unsupported claims, inferred steps, or permission recommendations without evidence
- score grounding completeness and confidence
- detect stale, conflicting, or unresolved documentation references
- emit structured warnings for authoring and operational review

Primary methods:

- `validateAnswer(GroundedDraft $draft, GroundingBundle $bundle): GroundingResult`
- `detectOutdatedDocs(DocumentationSnapshot $snapshot): array`
- `detectMissingTopics(DocumentationSnapshot $snapshot): array`
- `detectBrokenWalkthroughs(DocumentationSnapshot $snapshot): array`

## Knowledge Sources

### 1. Module Documentation

Markdown in `/docs/modules/*.md` and related architecture or guide files.

Extract:

- module key
- title
- summary
- supported workflows
- operational constraints
- route or UI surface references
- dependency references
- permission hints if explicitly documented

### 2. Help Topics

Structured topics from `/docs/help-index.json` plus generated module-view topics.

Extract:

- `topic_id`
- title
- description
- step list
- keyword list
- module scope
- `learn_more` document path
- related walkthrough ids

### 3. Walkthrough Definitions

Structured walkthroughs from `/docs/walkthroughs.json`.

Extract:

- `walkthrough_id`
- title
- description
- module
- topic
- trigger
- ordered steps
- UI target selectors
- advance mode

### 4. Operational Guides

Markdown in `/docs/operations`, `/docs/admin-guide`, `/docs/security`, `/docs/user-guide`, and Hermes architecture docs.

Extract:

- operating procedures
- diagnostic or policy rules
- prerequisites
- failure or recovery guidance
- escalation boundaries

## Ingestion and Compilation Model

### Source Loading

`HermesDocumentationIndex` scans the local docs tree and structured JSON files, then compiles a normalized snapshot:

```json
{
  "schema_version": 1,
  "built_at": "2026-03-14T18:00:00Z",
  "source_hash": "sha256:...",
  "topics": {},
  "documents": {},
  "walkthroughs": {},
  "modules": {},
  "references": {},
  "validation": {}
}
```

### Normalization Rules

- normalize all ids with the same plain-key rules used by help topics
- store canonical relative doc paths such as `/docs/modules/forms.md`
- extract markdown title from first `#` heading
- create short plain-text excerpts for ranking and UI display
- tokenize keywords from title, headings, topic ids, module keys, and explicit keyword lists
- attach provenance to every record: source file, record type, checksum, last modified time

### Record Types

- `DocumentationTopic`
- `DocumentationDocument`
- `WalkthroughDefinition`
- `ModuleDocumentationMap`
- `ReferenceEdge`
- `ValidationIssue`

### Cross-Reference Graph

The compiled index stores reverse links:

- topic -> document
- topic -> module
- topic -> walkthrough
- walkthrough -> topic
- walkthrough -> module
- document -> module
- document -> referenced topics

This graph supports fast answer assembly and broken-reference detection without rescanning all docs per request.

### Caching and Refresh

To keep Hermes responsive:

- build the snapshot once and cache it in memory per process
- persist a compiled JSON artifact in a Hermes storage path for cold starts
- refresh only when file mtimes or source hashes change
- run heavy rebuilds in a background worker such as `hermes.context.refresh`
- expose a lightweight synchronous rebuild only for development or explicit admin refresh

## Query Routing Model

`HermesKnowledgeService::classifyIntent(...)` must choose one primary route before reasoning:

### Documentation Help

Use when the query asks how to use a feature, where to find something, what steps to follow, what a screen means, or whether a walkthrough exists.

Examples:

- "How do I reconcile accounts?"
- "Show me the finance reconciliation walkthrough."
- "Where is the donor batch screen?"

Primary pipeline:

1. candidate topic search
2. module disambiguation
3. permission filtering
4. grounding validation
5. help answer or walkthrough launch plan

### Operational Diagnostics

Use when the query describes a problem, failure, mismatch, missing data, or degraded behavior.

Examples:

- "Why are newsletter jobs stuck?"
- "The reconciliation totals do not match."

Primary pipeline:

1. documentation lookup for documented symptoms or runbooks
2. diagnostic playbook selection
3. deterministic tool evidence collection
4. grounded explanation that separates documented facts from live findings

### Playbook Reasoning

Use when the request is investigative, procedural, or cross-module but still read-only and bounded.

Examples:

- "Help me troubleshoot board access."
- "What is the right process for publishing an announcement?"

Primary pipeline:

1. playbook selection
2. documentation pack load
3. diagnostic evidence
4. grounded recommendations

### Mission Execution

Use when the request implies a multi-step workflow that may end in executable proposals or user-approved actions.

Examples:

- "Prepare the monthly donations reconciliation."
- "Walk me through publishing announcements and propose the next actions."

Primary pipeline:

1. mission selection
2. documentation grounding for task instructions
3. diagnostic or context collection
4. proposal creation
5. approval gate before any mutation

## Reasoning Pipeline Integration

Documentation is integrated before model reasoning, not appended after the fact.

### Phase 1. Documentation Retrieval

Load only the minimum relevant bundle:

- matched help topics
- linked markdown documents
- related walkthrough definitions
- module references
- permission metadata

### Phase 2. Evidence Packaging

Build a `GroundingBundle` with:

- source ids
- short excerpts
- ordered documented steps
- linked module names
- unresolved references or ambiguity flags

### Phase 3. Deterministic Constraints

Before any model call:

- mark which steps are documented facts
- mark which points are inference-only
- hide instructions beyond actor permissions
- force "documentation unavailable" fallback if no valid grounding exists for a help answer

### Phase 4. Output Validation

`HermesGroundingValidator` checks:

- each instructional claim maps to one or more sources
- each link or walkthrough id resolves
- each permission statement is supported by actor permission checks or module permission metadata
- undocumented claims are either removed or labeled as inference

## Permission-Aware Help Model

Hermes must not provide instructions that imply access the actor does not have.

### Permission Sources

- actor roles and grants from Hermes runtime identity
- module permission metadata from manifests or Hermes context packs
- tool-level `permission_check` responses for sensitive or ambiguous workflows

### Permission Rules

- if the actor lacks module view permission, Hermes may describe the feature at a high level but must not provide step-by-step navigation or launch a walkthrough
- if the actor lacks action permission, Hermes may explain the read-only process and note that elevated access is required for execution steps
- walkthrough launch plans must be suppressed when the actor cannot access the target workflow
- links to modules should only be returned when the actor is authorized to open the destination

### Response Shapes

`allowed`

- full steps
- module links
- walkthrough launch action

`partial`

- redacted or truncated steps
- no direct launch
- explicit note about required permission

`denied`

- no detailed instructions
- high-level explanation only
- optional direction to contact an authorized administrator

## Supported Hermes Actions

### Answer Help Questions

Hermes returns:

- grounded summary
- documented steps
- relevant module references
- citations to help topic ids and doc paths

### Open Documentation

Hermes returns a resolved document reference:

- canonical doc path
- title
- module association
- optional anchor or related topic ids

### Launch Walkthroughs

Hermes returns a launch plan only when:

- the walkthrough exists
- its linked topic resolves
- the actor can access the workflow
- the walkthrough has no unresolved required references

### Link Relevant Modules

Hermes may attach:

- module key
- module title
- relevant doc page
- related help topics
- related walkthroughs

## Grounding Rules

### Mandatory Documentation Rule

If a matching help topic or operational guide exists, Hermes must answer from that documentation first. It may summarize, reorder for clarity, or abbreviate, but it may not invent an alternative explanation that conflicts with the documented source.

### Evidence Requirement

Instructional answers must include at least one of:

- help topic id
- markdown document path
- walkthrough id

### Inference Labeling

If Hermes adds a helpful inference because documentation is incomplete, the response must label it as an inference or best-effort suggestion and keep it separate from documented steps.

### Conflict Rule

If documentation conflicts across sources:

- prefer the most specific source over the broad guide
- prefer module documentation over generic operational prose
- mark the result with a documentation conflict warning for review

### No-Grounding Fallback

If the request is help-oriented and no documentation resolves cleanly:

- Hermes should say the documentation is missing or incomplete
- offer the nearest related doc if one exists
- create a validation signal for missing documentation coverage

## Validation and Drift Detection

### Outdated Documentation Detection

Mark documentation as potentially outdated when:

- a document references a module or topic that no longer exists
- help topics point to missing `learn_more` documents
- walkthrough topics no longer resolve
- module manifests expose views with no matching help coverage
- compiled doc checksum lags behind the current source tree snapshot

Suggested issue shape:

```json
{
  "type": "outdated_documentation",
  "severity": "warning",
  "source": "/docs/help-index.json",
  "reference": "finance.reconciliations",
  "message": "Topic exists but linked module view is no longer registered."
}
```

### Missing Help Topic Detection

Detect when:

- a module view exists without a corresponding help topic
- a walkthrough points to a topic not present in the compiled index
- Hermes receives repeated unmatched help queries for the same module surface

### Unresolved Walkthrough Reference Detection

Detect when:

- `walkthrough.topic` does not resolve
- `walkthrough.module` does not resolve to a known module
- no valid UI targets remain for a required step
- walkthrough steps are empty or malformed

### Validation Execution Model

- run lightweight checks during snapshot build
- persist issues in the compiled snapshot
- expose heavy audits through a background diagnostic job
- aggregate issue counts for Hermes dashboard health views

## Data Contracts

### HelpResolution

```json
{
  "intent": "documentation_help",
  "status": "resolved",
  "topic_ids": ["finance.reconciliations"],
  "document_paths": ["/docs/modules/finance.md"],
  "walkthrough_ids": ["finance_reconciliation"],
  "module_keys": ["finance"],
  "permission_mode": "allowed"
}
```

### GroundedHelpAnswer

```json
{
  "type": "help_answer",
  "summary": "Use the Finance reconciliation screen to compare statement balance, timing items, and follow-up notes.",
  "documented_steps": [
    "Open Finance from the sidebar.",
    "Open Reconcile.",
    "Review statement balance, timing items, and follow-up notes before saving the reconciliation."
  ],
  "sources": [
    { "type": "topic", "id": "finance.reconciliations" },
    { "type": "walkthrough", "id": "finance_reconciliation" },
    { "type": "document", "path": "/docs/modules/finance.md" }
  ],
  "permission_mode": "allowed",
  "grounding_score": 1.0
}
```

## Performance Requirements

The documentation layer must remain cheap at runtime.

- never rescan the full `/docs` tree on every request
- maintain a compiled snapshot with O(1) lookup by topic id, walkthrough id, module key, and doc path
- keep full-text search bounded to precomputed token fields and excerpts
- offload expensive rebuilds and validation scans to background jobs
- return only the minimal evidence bundle needed for the active query

## Operational Boundaries

- Hermes documentation grounding is read-only
- no direct module table access is allowed
- no WXR API dependency is allowed for ingestion or retrieval
- no remote documentation fetch is allowed
- action-oriented workflows still require the normal Hermes proposal and enclave approval path

## Recommended Implementation Order

1. Build `HermesDocumentationIndex` as a file-backed compiled snapshot service.
2. Add `HermesHelpResolver` and `HermesWalkthroughResolver` on top of the snapshot.
3. Add `HermesGroundingValidator` to enforce evidence-backed help output.
4. Integrate `HermesKnowledgeService` into Hermes intent classification and reasoning assembly.
5. Add background validation jobs and dashboard reporting for documentation drift.
