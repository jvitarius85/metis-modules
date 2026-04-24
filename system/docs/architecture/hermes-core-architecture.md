# Hermes Core Architecture

## Purpose

Hermes is a structured reasoning assistant for Metis. It operates as an operational intelligence layer that can inspect system state, diagnose issues, support administrative workflows, and prepare executable actions.

Hermes is explicitly non-autonomous:

- Hermes may observe, reason, summarize, diagnose, and propose.
- Hermes may not mutate system state without explicit user approval.
- Hermes may not bypass Metis security boundaries.
- Hermes may not query module tables directly.
- Hermes must interact with Metis modules only through the Hermes Tool Contract.
- All approved system-modifying actions must execute through the Metis Secure Enclave.

This design prioritizes security, determinism, maintainability, auditability, and scalability.

## Design Principles

- **Security first**: every action path is permission-aware, enclave-mediated, and auditable.
- **Deterministic orchestration**: Hermes uses bounded reasoning, typed tool responses, explicit playbooks, and stable execution phases.
- **Modular integration**: modules expose capabilities through a common tool contract rather than bespoke AI integrations.
- **Observability and auditability**: every analysis, proposal, approval, execution, and result is traceable.
- **Operational scalability**: expensive diagnostics, indexing, context refresh, and mission execution run through background workers.
- **Maintainable knowledge**: static context is versioned with module code; dynamic learning is structured, reviewable, and confidence-scored.

## System Components

### 1. Hermes AI Gateway

The AI Gateway is the only component allowed to interact with the language model runtime. It is responsible for:

- receiving reasoning requests from UI, API, or dashboard surfaces
- assembling bounded input packages
- selecting the appropriate playbook or reasoning mode
- enforcing token, latency, and tool-invocation limits
- translating model output into typed Hermes intents
- rejecting malformed or non-compliant model responses

The AI Gateway does not call modules directly. It delegates all system inspection and action preparation to the Reasoning Engine and Tool Registry.

Core responsibilities:

- prompt templating with strict response schemas
- session correlation and trace IDs
- redaction of sensitive fields before model submission
- output validation against Hermes response contracts
- fallback behavior when model output is incomplete or invalid

### 2. Hermes Reasoning Engine

The Reasoning Engine is the deterministic orchestration layer around the model. It manages:

- request classification
- context pack loading
- playbook selection
- tool planning
- reasoning phase transitions
- proposal generation
- approval packaging

The Reasoning Engine uses a fixed execution state machine:

1. Intake
2. Classify
3. Load context
4. Run diagnostics
5. Reason
6. Produce findings
7. Prepare proposals
8. Await approval
9. Execute approved action through enclave
10. Summarize results
11. Persist memory signals

This prevents free-form agent behavior and keeps Hermes predictable.

### 3. Hermes Tool Registry

The Tool Registry is the canonical catalog of all module capabilities available to Hermes. It resolves tool definitions, validates request payloads, enforces availability rules, and routes calls to module-provided handlers.

The registry only exposes tools that implement the Hermes Tool Contract.

Primary capabilities:

- register and version tools by module
- validate tool schemas
- enforce read-only versus action-proposal semantics
- attach permission requirements and enclave operation names
- support caching and TTL metadata for tool outputs
- expose diagnostics metadata for dashboard and audit use

### 4. Hermes Diagnostic Engine

The Diagnostic Engine runs structured inspection routines before or during reasoning. It is separate from free-form LLM analysis and provides deterministic evidence.

It supports:

- module health checks
- policy validation checks
- dependency chain verification
- queue and worker health checks
- stale cache detection
- permission mismatch detection
- configuration integrity checks
- known-pattern detection using dynamic context packs

The Diagnostic Engine can run synchronous lightweight checks in-request and defer expensive checks to workers.

### 5. Hermes Worker Framework

The Worker Framework handles asynchronous Hermes workloads using the existing Metis job queue and worker registry.

Use worker jobs for:

- expensive diagnostics
- context pack rebuilds
- cross-module health scans
- mission execution phases that exceed request budgets
- memory consolidation and pattern extraction
- dashboard snapshot refresh

Hermes workers must be idempotent, lease-safe, and resumable where feasible.

### 6. Hermes Mission Mode

Mission Mode is a bounded multi-step workflow for higher-order tasks such as incident diagnosis, release verification, or reconciliation assistance.

Mission Mode is not autonomy. It is a structured orchestration container that:

- declares objective, scope, and constraints
- uses an approved playbook
- tracks phase-by-phase evidence
- produces one or more action proposals
- pauses for human approval before any mutating step

### 7. Hermes Playbook System

Playbooks are predefined reasoning strategies for recurring problem types. They reduce prompt variance and improve determinism.

Examples:

- service degradation triage
- failed job investigation
- permission/access issue diagnosis
- configuration drift review
- data sync discrepancy analysis
- release readiness review

### 8. Hermes Context Pack System

Context Packs provide structured knowledge about modules and platform capabilities before reasoning starts.

They exist in two forms:

- static context packs: versioned alongside module definitions
- dynamic context packs: accumulated from operational outcomes and reviewed learning signals

### 9. Hermes Memory and Learning System

The Memory and Learning System stores bounded operational knowledge, not raw unconstrained conversation history.

It records:

- prior missions
- findings and resolutions
- successful and failed action outcomes
- repeated failure signatures
- module-specific heuristics
- approval/execution history

Dynamic learning remains advisory until promoted into reviewed dynamic context.

### 10. Hermes System Health Dashboard Integration

Hermes publishes health summaries, diagnostics, proposal queues, and mission outcomes to the Metis dashboard layer.

It does not own a separate admin UI stack. It integrates into existing Metis dashboard surfaces and uses aggregated snapshot data for responsiveness.

## Hermes Tool Contract

Every module must expose Hermes-compatible tools through a standardized contract. Hermes never reads module tables directly.

### Contract Goals

- unify module interaction
- provide typed access to context and diagnostics
- centralize permission metadata
- separate observation from mutation
- guarantee enclave execution metadata for actions

### Tool Categories

- `context`: returns contextual data for reasoning
- `diagnostic`: returns deterministic health or validation results
- `permission_check`: evaluates actor authorization for a proposed capability
- `action_proposal`: describes a proposed mutating action, required approval, and enclave operation
- `action_execute`: invoked only after approval, via enclave-mediated module dispatch

### Required Tool Definition Fields

Each registered tool definition should include:

- `tool_key`
- `module`
- `version`
- `category`
- `description`
- `input_schema`
- `output_schema`
- `permission`
- `read_only`
- `cache_ttl_seconds`
- `enclave_operation` for executable actions
- `idempotency_strategy`
- `diagnostic_tags`

### Tool Runtime Request Shape

```json
{
  "request_id": "hrm_req_01",
  "tool_key": "finance.diagnostic.reconciliation_health",
  "actor": {
    "user_id": 42,
    "roles": ["administrator"]
  },
  "scope": {
    "module": "finance",
    "entity_ids": ["dep_1007"]
  },
  "input": {
    "date_from": "2026-03-01",
    "date_to": "2026-03-14"
  },
  "meta": {
    "mission_id": "hrm_msn_12",
    "trace_id": "trace_abc123"
  }
}
```

### Tool Runtime Response Shape

```json
{
  "ok": true,
  "tool_key": "finance.diagnostic.reconciliation_health",
  "module": "finance",
  "category": "diagnostic",
  "data": {},
  "evidence": [],
  "warnings": [],
  "permissions": {
    "allowed": true,
    "required": ["finance.view"]
  },
  "cache": {
    "hit": false,
    "ttl_seconds": 300
  },
  "meta": {
    "duration_ms": 42,
    "generated_at": "2026-03-14T12:00:00Z"
  }
}
```

### Action Proposal Shape

```json
{
  "ok": true,
  "category": "action_proposal",
  "proposal": {
    "proposal_id": "hrm_prop_88",
    "title": "Retry stalled newsletter jobs",
    "summary": "Requeue three failed jobs after configuration validation.",
    "risk_level": "medium",
    "requires_approval": true,
    "module": "newsletter",
    "permission": "newsletter.manage",
    "enclave_operation": "newsletter.retry_jobs",
    "input_preview": {
      "job_ids": [991, 992, 995]
    },
    "expected_effects": [
      "jobs return to queued state",
      "delivery worker can resume processing"
    ],
    "rollback_strategy": "revert affected jobs to failed if retry preconditions are invalidated"
  }
}
```

## Secure Enclave Interaction Pattern

All mutating actions follow the same sequence:

1. Hermes produces an action proposal only.
2. The user reviews and explicitly approves the proposal.
3. Hermes submits the approved payload to a registered enclave operation.
4. The enclave revalidates authentication, session, nonce, rate limit, and permission.
5. The enclave dispatches the action to the module gateway.
6. The module executes the action and returns a typed result.
7. Hermes records the result, links it to the proposal, and updates memory.

### Enclave Rules

- Hermes may never execute mutating module logic outside `Metis_Security_Enclave::handle(...)`.
- Enclave policies must be pre-registered per executable Hermes operation.
- Permission validation must happen again at execution time even if it passed during proposal generation.
- The enclave payload must contain actor, mission, proposal, and trace metadata for audit.
- Module execution must be routed through the module gateway, never direct DB or filesystem access from Hermes.

### Recommended Enclave Operation Naming

- `hermes.<module>.<action>`
- examples:
  - `hermes.newsletter.retry_jobs`
  - `hermes.finance.rebuild_summary_cache`
  - `hermes.forms.pause_endpoint`

## Context Pack System

Context packs load before reasoning so the model and orchestrator operate with structured module knowledge.

### Static Context Packs

Static context packs are versioned assets owned by each module. They should be generated from or aligned with module manifests and service definitions.

Recommended fields:

- `module`
- `version`
- `description`
- `domain_summary`
- `objects`
- `relationships`
- `permissions`
- `available_actions`
- `diagnostics`
- `operational_patterns`
- `glossary`
- `constraints`
- `sensitive_fields`
- `dependencies`
- `dashboard_signals`

### Static Context Pack Example

```json
{
  "module": "newsletter",
  "version": "1.0.0",
  "description": "Email campaign and subscriber operations.",
  "objects": [
    { "name": "campaign", "key_fields": ["campaign_id", "status", "scheduled_at"] },
    { "name": "subscriber", "key_fields": ["subscriber_id", "status", "list_id"] },
    { "name": "delivery_job", "key_fields": ["job_id", "status", "attempts"] }
  ],
  "relationships": [
    { "from": "campaign", "to": "delivery_job", "type": "spawns" },
    { "from": "subscriber", "to": "campaign", "type": "receives" }
  ],
  "permissions": [
    { "key": "newsletter.view", "description": "View newsletter operations" },
    { "key": "newsletter.manage", "description": "Manage delivery actions" }
  ],
  "available_actions": [
    {
      "tool_key": "newsletter.action.retry_jobs",
      "permission": "newsletter.manage",
      "enclave_operation": "hermes.newsletter.retry_jobs"
    }
  ],
  "diagnostics": [
    "newsletter.diagnostic.queue_health",
    "newsletter.diagnostic.template_integrity"
  ],
  "operational_patterns": [
    {
      "pattern_key": "stalled_delivery_queue",
      "symptoms": ["failed jobs spike", "queue age exceeds threshold"],
      "recommended_playbook": "job-failure-triage"
    }
  ]
}
```

### Dynamic Context Packs

Dynamic context packs accumulate operational knowledge from real Hermes runs. They are not free-form notes. They are structured records with confidence, provenance, and review state.

Recommended dynamic fields:

- `pattern_key`
- `module`
- `signature`
- `symptoms`
- `likely_causes`
- `successful_resolutions`
- `failed_resolutions`
- `confidence`
- `occurrence_count`
- `last_seen_at`
- `sources`
- `review_status`

### Dynamic Context Governance

- new patterns are stored as unreviewed
- confidence increases only from repeated validated outcomes
- low-confidence patterns may guide ranking but not deterministically drive execution
- promotion to shared module context requires admin review
- sensitive data is redacted before persistence

## Playbook Framework

Playbooks define bounded reasoning procedures with typed inputs, expected evidence, decision checkpoints, and output formats.

### Playbook Structure

- `playbook_key`
- `name`
- `goal`
- `applicable_modules`
- `entry_conditions`
- `required_context`
- `tool_sequence`
- `decision_rules`
- `proposal_templates`
- `completion_criteria`
- `escalation_rules`

### Example Playbook Types

#### Incident Triage

- gather module health diagnostics
- inspect recent failures and event anomalies
- classify likely root cause
- recommend the least invasive next step

#### Permission Failure Analysis

- load permission model from context pack
- evaluate actor/tool mismatch
- identify whether the failure is configuration, role assignment, or policy registration
- produce either a configuration review proposal or a no-action finding

#### Queue Failure Recovery

- inspect job queue depth, failure reasons, lease expirations, and worker registration
- compare symptoms with dynamic patterns
- generate safe requeue, replay, or cache rebuild proposals

## Mission Mode

Mission Mode packages one or more playbooks into a tracked operational workflow.

### Mission Record

- `mission_id`
- `type`
- `objective`
- `status`
- `requested_by`
- `approved_by`
- `scope`
- `playbook_key`
- `phase`
- `findings`
- `proposals`
- `execution_log`
- `started_at`
- `completed_at`

### Mission States

- `draft`
- `running`
- `awaiting_approval`
- `approved`
- `executing`
- `completed`
- `failed`
- `cancelled`

### Mission Constraints

- each mission must declare module scope
- each mission must have a traceable initiating user
- missions may span multiple tool calls but must maintain a single audit chain
- missions may batch multiple proposals, but each mutating proposal needs explicit approval

## Worker Architecture

Hermes should use the existing Metis `JobQueue` and `JobWorkerRegistry` rather than introduce a second queueing layer.

### Recommended Hermes Job Types

- `hermes.context.refresh`
- `hermes.context.promote_dynamic_pattern`
- `hermes.diagnostic.deep_scan`
- `hermes.dashboard.refresh_snapshot`
- `hermes.memory.compact`
- `hermes.mission.resume`

### Worker Design Rules

- job payloads must be small and reference IDs rather than embed large blobs
- long-running scans should checkpoint progress
- jobs must support dedupe keys for repeated triggers
- dashboard jobs should publish aggregated snapshots, not raw event streams
- failed jobs should store structured error codes for diagnostic reuse

### Performance Considerations

- cache compiled context packs by module and version
- precompute dashboard health summaries on a schedule
- use indexed mission, proposal, and memory tables for time-ordered review
- avoid loading full mission histories during interactive reasoning; fetch latest summaries and targeted evidence

## Storage Model

Hermes storage should be additive and isolated from module business tables.

### Recommended Tables

#### `metis_hermes_sessions`

- request/session metadata
- actor ID
- active mission ID
- model/runtime metadata
- created and last activity timestamps

Indexes:

- `(actor_id, last_activity_at)`
- `(active_mission_id)`

#### `metis_hermes_missions`

- mission header
- objective
- status
- scope
- playbook key
- phase
- summarized findings
- timestamps

Indexes:

- `(status, updated_at)`
- `(requested_by, created_at)`
- `(type, created_at)`

#### `metis_hermes_proposals`

- proposal metadata
- mission ID
- module
- enclave operation
- permission
- risk level
- approval state
- payload hash
- timestamps

Indexes:

- `(mission_id, created_at)`
- `(approval_state, created_at)`
- `(module, approval_state, created_at)`
- unique `(payload_hash)` when idempotency is required

#### `metis_hermes_execution_log`

- proposal execution attempts
- enclave request metadata
- result status
- error code
- duration
- timestamps

Indexes:

- `(proposal_id, created_at)`
- `(result_status, created_at)`

#### `metis_hermes_context_static`

- compiled static context pack payloads by module and version
- content hash
- compiled timestamp

Indexes:

- unique `(module, version)`
- `(compiled_at)`

#### `metis_hermes_context_dynamic`

- dynamic operational patterns
- confidence
- occurrence count
- review status
- last seen timestamp

Indexes:

- `(module, review_status, confidence)`
- `(pattern_key)`
- `(last_seen_at)`

#### `metis_hermes_memory_items`

- normalized memory entries
- linked module/mission/proposal
- type
- summary
- evidence references
- retention expiry

Indexes:

- `(module, type, created_at)`
- `(mission_id)`
- `(expires_at)`

#### `metis_hermes_dashboard_snapshots`

- aggregated health and operations payloads
- snapshot scope
- generated timestamp

Indexes:

- `(scope_key, generated_at)`

## System Flows

### 1. Analysis-Only Flow

1. User opens Hermes and asks a question.
2. AI Gateway creates a session and trace ID.
3. Reasoning Engine classifies the request.
4. Relevant static and dynamic context packs are loaded.
5. Diagnostic Engine runs lightweight checks via registered tools.
6. Reasoning Engine produces findings and recommendations.
7. Findings are logged and summarized for dashboard/memory use.

No enclave execution occurs because the flow is read-only.

### 2. Proposal Flow

1. Hermes identifies a possible corrective action.
2. Hermes calls module `permission_check` and `action_proposal` tools.
3. Hermes generates a proposal with risk, expected effects, and enclave operation.
4. Proposal is stored with `awaiting_approval` state.
5. User explicitly approves or rejects.

### 3. Approved Execution Flow

1. User approves a proposal.
2. Hermes creates an enclave execution request with actor, proposal, and trace metadata.
3. Secure Enclave revalidates policy and permission.
4. Module action executes through the module gateway.
5. Result is persisted to execution log and mission state.
6. Hermes summarizes outcome and updates dynamic learning candidates.

### 4. Deep Diagnostic Flow

1. A request exceeds interactive budget.
2. Hermes creates a mission and queues `hermes.diagnostic.deep_scan`.
3. Worker runs registered diagnostic tools and stores summarized evidence.
4. Mission resumes and produces findings or proposals when complete.

## Dashboard Integration

Hermes should surface operational intelligence inside the Metis dashboard through aggregated views.

Recommended dashboard widgets:

- overall platform health score
- module health status by domain
- active incidents and missions
- pending approval proposals
- recent enclave executions
- recurring failure patterns
- worker and queue backlog indicators

Dashboard integration rules:

- read from snapshot tables or aggregated queries
- avoid live fan-out across all modules on every page request
- refresh expensive widgets asynchronously
- provide drill-down links into mission and proposal records

## Security Model for Hermes

- Hermes runs under the requesting user identity; there is no system-superuser bypass.
- Read-only tools still declare permission requirements and may redact sensitive fields.
- Prompt inputs must be redacted for secrets, tokens, credentials, and protected personal data.
- Every proposal and execution attempt receives a trace ID and immutable audit entry.
- Dynamic learning data must never store raw secrets or unrestricted personal records.
- Module authors must explicitly opt into Hermes exposure through tool registration.

## Maintainability Model

- module teams own their static context packs and tool definitions
- Hermes owns orchestration, playbooks, memory governance, and proposal lifecycle
- contract versions must support backward-compatible evolution
- diagnostic and action schemas should be stored as typed PHP arrays or JSON schema definitions
- new modules become Hermes-capable by implementing the tool contract, not by custom AI glue code

## Scalability Model

- use module-scoped context loading instead of global full-system context
- cache compiled context packs and health summaries
- offload heavy scans and pattern mining to workers
- keep mission records summarized and archive older evidence by retention policy
- favor append-only logs plus summary tables for dashboard reads

## Recommended Implementation Phases

### Phase 1: Foundation

- add Hermes service registration
- implement tool registry and contract
- add static context pack loading
- add analysis-only reasoning flow
- add mission, proposal, and execution storage tables

### Phase 2: Controlled Actioning

- add action proposal generation
- register enclave operations for Hermes-approved actions
- add approval workflow and execution logging
- expose dashboard widgets for proposals and executions

### Phase 3: Diagnostics and Workers

- add deep diagnostic engine
- register Hermes worker jobs
- add snapshot refresh and health aggregation
- add baseline playbooks for triage, permission, and queue issues

### Phase 4: Learning

- add dynamic context accumulation
- add review workflows for pattern promotion
- tune confidence scoring and retention policies

## Non-Negotiable Constraints

- Hermes never performs direct module database queries.
- Hermes never executes mutating logic without explicit user approval.
- Hermes never bypasses Secure Enclave validation.
- Hermes never treats learned patterns as unconditional truth.
- Hermes always produces auditable evidence for findings and actions.
