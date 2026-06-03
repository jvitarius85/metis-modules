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

### 2.1 Intent, Entity, and Attribute Registries

Hermes maintains explicit registries for the three normalization layers that sit in front of secure execution:

- `HermesIntentRegistry`: canonical top-level intents (`LOOKUP`, `REPORT`, `CREATE`, `UPDATE`, `DELETE`, `EXECUTE`, `HELP`) plus phrase aliases and command-family mapping.
- `EntityRegistryBuilder`: canonical entities and aliases sourced from module entity manifests.
- `HermesAttributeRegistry`: canonical attributes and aliases used for subject-attribute lookups.

These registries let Hermes classify user language consistently without embedding duplicate alias maps across the parser, operational engine, and response flow.

### 2.2 Intent Router and Conversation State Engine

Hermes now separates two coordination concerns that previously sat implicitly inside the gateway:

- `HermesIntentRouter`: converts conversational input into a deterministic routing decision (`command`, `data`, `entity_attribute`, `clarification`, or `knowledge`) and can fall back to the legacy parser when that yields a stronger structured interpretation, especially for data intents.
- `HermesConversationStateEngine`: owns turn opening, session-context hydration, and post-response state persistence using the existing Hermes session and memory stores.

This keeps request classification and conversation continuity explicit, testable, and independent from approval or execution logic.

### 2.3 Intelligence and Operations Registries

Hermes now also exposes two canonical registries above the raw services:

- `HermesIntelligenceRegistry`: the normalized catalog of grounded knowledge sources, currently documentation, help topics, and walkthroughs.
- `HermesOperationsRegistry`: the normalized catalog of executable or inspectable operations, built by joining Hermes command metadata with Hermes tool metadata and top-level intent classification.

These registries reduce duplicated metadata, make dashboard/reporting surfaces easier to consume, and keep knowledge retrieval plus operation discovery explicit instead of implicit.

### 2.4 Approval Engine

Hermes now treats approval handling as its own coordination surface:

- `HermesApprovalEngine`: packages pending approvals from operational responses, persists approval records, attaches approval UI prompts, and validates approval transitions before any later execution step.

Execution still runs through the Secure Enclave path, but approval preparation and approval-state changes no longer live inline inside the gateway.

### 2.5 Action Executor

Hermes now isolates approved-action execution behind a dedicated executor:

- `HermesActionExecutor`: runs approved actions through the Secure Enclave path, dispatches supported non-command action types, redacts sensitive result fields, persists execution results, and handles one-time secret reveal retrieval.

The gateway still enforces high-level action state checks, but the enclave execution path and post-execution handling are no longer embedded there.

### 2.6 Workflow Continuation

Hermes now treats short conversational follow-up replies as an explicit continuation layer:

- `HermesWorkflowContinuationEngine`: resolves approval replies such as `yes` and `no` against the latest pending session action, enforces a 15-minute workflow TTL, transitions expired or cancelled actions out of the pending queue, and delegates approved execution back to `HermesActionExecutor`.

This keeps approval memory deterministic and session-scoped without duplicating approval or enclave execution logic inside the gateway.

### 2.7 Recent Entity Memory

Hermes now persists a canonical recent-entity reference alongside normal conversation summaries:

- `HermesConversationStateEngine`: extracts the most recent entity subject from completed turns and stores it in session memory.
- `HermesMemoryStore`: exposes dedicated recent-entity recall instead of overloading generic conversation summaries.
- `ConversationalParser`: uses that recent-entity memory only for context-aware commands so pronouns such as `his`, `her`, `them`, `it`, and phrases such as `that user` resolve deterministically inside the current session.

This keeps follow-up entity resolution explicit and auditable without guessing across sessions or reintroducing parser-local alias maps.

### 2.8 Pending Workflow Engine

Hermes now isolates incomplete multi-turn command capture behind a dedicated workflow layer:

- `HermesPendingWorkflowEngine`: persists incomplete workflow state per session, asks the next deterministic question for missing command fields, expires stale workflows after 15 minutes, and hands completed payloads back into the existing operational and approval pipeline.

The first implemented workflow is `create_user`, which can now collect name, email, and an optional role across multiple turns before Hermes presents the normal approval prompt.

### 2.9 Disambiguation Memory

Hermes now persists entity disambiguation prompts as explicit session state:

- `HermesDisambiguationEngine`: stores candidate lists when entity resolution finds multiple matches, accepts a numbered or named follow-up reply on the next turn, expires stale prompts after 15 minutes, and hands the selected entity back into the normal attribute-resolution path.

This lets Hermes ask `Which person would you like?` once and continue deterministically on the user’s next reply instead of starting a fresh parse.

### 2.10 Conversation Package

Hermes now has an explicit `Metis\Hermes\Conversation` package that matches the directive’s conversation-layer separation:

- `ConversationStore`: session, message, summary, recent-entity, pending-workflow, pending-disambiguation, and pending-action access.
- `ConversationResolver`: runtime-context hydration plus recent-entity extraction.
- `ConversationStateManager`: turn opening and completion orchestration.
- `ConversationContext`, `PendingAction`, and `PendingQuestion`: typed conversation-state objects for session runtime state.

`HermesConversationStateEngine` remains the public compatibility surface, but it now delegates into these dedicated conversation classes instead of owning the logic directly.

### 2.11 Intelligence Framework Foundation

Hermes now delegates grounded knowledge retrieval through an explicit `Metis\Intelligence` package:

- `Contracts/IntelligenceProviderInterface`: stable provider contract with `supports()`, `getMetrics()`, `getInsights()`, `getAlerts()`, and `getRecommendations()`.
- `DTOs/IntelligenceSnapshot`: normalized response DTO for provider output.
- `Support/IntelligenceResponseFactory`: common snapshot construction.
- `Registry/IntelligenceProviderRegistry`: canonical provider catalog and resolution entrypoint.
- `Services/*IntelligenceProvider`: adapter providers for documentation, help topics, and walkthrough intelligence.

`HermesIntelligenceRegistry` now acts as the Hermes-facing compatibility layer over that provider registry rather than assembling grounded sources directly itself.

### 2.12 Dashboard Intelligence Services

Hermes dashboard health synthesis is now delegated into explicit deterministic intelligence services:

- `Support/SeverityRanker`: shared severity ordering and threshold checks.
- `Services/AlertIntelligenceService`: operator-facing alert synthesis from queue, cron, reconciliation, permission, and board-health evidence.
- `Services/IntegrationFailureIntelligenceService`: integration-failure summaries from the same evidence surfaces without duplicating gateway rule logic.
- `Services/ModuleHealthIntelligenceService`: per-module status, restriction, and live board-diagnostic resolution.
- `Services/DiagnosticTrendIntelligenceService`: oldest-first chart points for recent diagnostic reports.

`HermesGateway` still owns dashboard orchestration and caching, but it no longer owns these rule engines directly. That keeps the dashboard read path deterministic, testable, and aligned with the directive’s broader intelligence-layer separation.

### 2.13 Trend Engine

Hermes now has a centralized trend engine in `Metis\Intelligence\Services\TrendIntelligenceService` with shared support for:

- day-over-day
- week-over-week
- month-over-month
- quarter-over-quarter
- year-over-year

The service owns comparison normalization, current/previous window resolution, delta-percentage calculation, and deterministic label/class formatting like `up 100.0% vs previous month`. `DiagnosticTrendIntelligenceService` now consumes this engine instead of carrying ad hoc comparison logic, which satisfies the directive’s requirement to avoid duplicated trend rules.

### 2.14 Recommendation Engine

Hermes now exposes deterministic dashboard recommendations through `Metis\Intelligence\Services\RecommendationIntelligenceService`.

The recommendation engine:

- consumes existing health evidence from alerts, integration failures, module summaries, and live diagnostics
- resolves each recommendation to a real Hermes operation key when one exists
- keeps recommendation generation outside `HermesGateway`
- avoids freeform advice by grounding next steps in the operations catalog

Current rule coverage includes queue pressure, reconciliation anomalies, board workspace drift, newsletter delivery friction, and permission mismatches.

### 2.15 Domain Intelligence Providers

The intelligence registry now includes first-class deterministic domain providers backed by existing Metis module services:

- `DonorIntelligenceProvider`: delegates to donations dashboard snapshots for fundraising and donor-health signals.
- `CampaignIntelligenceProvider`: delegates to donations campaign snapshots for campaign activity and raised totals.
- `FinancialIntelligenceProvider`: delegates to `FinanceV2Service::summary()` for reconciliation and ledger-health signals.
- `NewsletterIntelligenceProvider`: delegates to newsletter dashboard snapshots for delivery and subscriber signals.
- `SystemIntelligenceProvider`: delegates to Hermes diagnostics and queue summaries for system-health signals.

These providers keep Hermes out of direct business calculation paths while expanding the explicit `Metis\Intelligence` framework toward the directive’s domain-specific intelligence model.

### 2.16 Operations Framework

Hermes now has an explicit `Metis\Operations` package with:

- `Contracts/OperationsRegistryInterface`
- `DTOs/OperationDefinition`
- `Services/OperationDefinitionBuilder`
- `Registry/OperationsRegistry`

The builder composes operation metadata from the existing Hermes command, tool, and intent registries, while `HermesOperationsRegistry` now acts as the backward-compatible facade over this standalone package. This keeps the current gateway and dashboard path stable while satisfying the directive’s requirement that operations metadata live under an explicit `system/src/Metis/Operations/` framework.

### 2.17 Operations Service Catalogs

The operations framework now also exposes explicit service ownership catalogs for the directive’s major operation domains:

- `UserOperationsService`
- `WorkspaceUserOperationsService`
- `CampaignOperationsService`
- `NewsletterOperationsService`
- `SystemOperationsService`

`OperationsServiceCatalog` resolves handler metadata for each known operation, and `OperationDefinitionBuilder` now attaches that handler ownership directly into the normalized operation definitions. This does not change Secure Enclave execution, but it makes command-to-service ownership explicit and testable.

### 2.18 Multi-Step Command Handling

Hermes operational processing now preserves parser-emitted multi-step `execution_plan` data through:

- action-plan rendering
- approval payload packaging
- per-step permission validation
- prepared-action validation
- sequential execution with stop-on-failure semantics

Each step is resolved against the command registry independently, carries its own approval/read-only metadata, and is validated separately before execution. This closes the gap between the earlier conversational multi-fragment parser output and the directive’s required multi-step operational handling.

### 2.18a Blocked Operation Catalog

Hermes now centralizes intentionally blocked backend metadata in `Metis\Hermes\HermesBlockedOperationCatalog`.

That catalog owns the canonical metadata for operations that Hermes must expose but cannot safely execute yet, including:

- canonical operation key
- tool key
- Hermes capability method
- representative conversational phrase
- operator-facing unsupported message
- expected guidance fragment used by contract tests

The current blocked surface includes:

- `service_restart`
- `recover_module`
- `rollback_module`
- `enable_module`
- `disable_module`
- `install_module`
- `update_module`
- `export_data`
- `import_data`
- `deduplicate`
- `rotate_keys`

`HermesCommandRegistry` now reads unsupported messages from this catalog instead of embedding them inline, and the shared blocked-operation test fixture delegates to the same runtime source. That removes the last duplicated blocked-backend message list between production code and contract tests.

### 2.19 Broader Trusted Operations Coverage

Hermes now exposes first-class operations for trusted backends that already exist in Metis:

- `user_password_reset`
- `workspace_user_password_reset`
- `run_backup`
- `backup_start`
- `backup_restore`
- `backup_validate`
- `cache_clear`
- `update_check`
- `aut_update_check`
- `update_install`
- `aut_update_install`
- `release_rollback`
- `diagnostics_run`

These commands are normalized through the Hermes command/tool registries and the standalone `Metis\Operations` framework, while dispatch remains grounded in real services:

- password resets delegate to `HermesUserAdminService`
- backup start and restore queue trusted operations through `HermesSystemOperationsService`
- update install queues trusted release operations through `HermesSystemOperationsService`
- release rollback queues the trusted `release.rollback` operation through `HermesSystemOperationsService`
- diagnostics and cache aliases reuse the existing Hermes tools instead of creating duplicate execution paths

This phase expands the directive-required operations surface without weakening the enclave or approval model: mutating commands remain approval-gated, and only operations with concrete backends are exposed as executable.

### 2.20 Expanded Pending Workflows

Hermes pending workflows now cover more than user creation. `HermesPendingWorkflowEngine` now handles:

- `create_user`
- `workspace_user_create`
- ambiguous password reset requests
- explicit Metis password reset requests with missing subject
- explicit Workspace password reset requests with missing subject
- `backup_restore` requests with a missing run ID
- `backup_validate` requests with a missing run ID

The workflow engine now asks deterministic follow-up questions only for missing executable inputs:

- missing user name or email for create-user flows
- missing password target scope for ambiguous reset requests
- missing subject for reset requests
- missing `run_uuid` for backup restore and backup validation

Once the missing inputs are collected, Hermes converts the workflow back into the canonical operation review path and reuses the existing approval continuation system. This preserves the Secure Enclave execution path while broadening multi-turn recovery for operational requests that were previously dead ends.

### 2.21 Contextual Action Confidence

Recent-entity memory now feeds action-command confidence more directly inside `ConversationalParser`, not just lookup follow-ups. Hermes now treats ordered phrase matches with lightweight filler words in between as valid command evidence, so prompts like:

- `enable that user`
- `update that user`
- `reset his password`
- `reset his workspace password`

can resolve against the last referenced person without falling into low-confidence clarification loops.

The parser now also breaks ties with pattern specificity instead of raw confidence alone, which keeps more specific commands ahead of generic ones. That matters for cases like:

- `create a workspace user` over generic `create_user`
- `module diagnostic` over generic full-diagnostics runs
- explicitly scoped Workspace password resets over generic password reset commands

This improves follow-up determinism while still preserving workflow-based clarification when the request is genuinely under-specified, such as an unscoped password reset that needs Metis-versus-Workspace confirmation.

### 2.22 Campaign and Newsletter Operations

The standalone operations package now exposes real `campaign` and `newsletter` families instead of empty placeholders. Hermes now registers first-class operations for:

- `campaign_create`
- `campaign_update`
- `campaign_publish`
- `campaign_archive`
- `campaign_delete`
- `newsletter_create`
- `newsletter_send`
- `newsletter_schedule`
- `newsletter_delete`

These operations are backed by a dedicated `HermesNewsletterAdminService`, which delegates to the existing newsletter module services:

- `CampaignService::save()` for create and update
- `QueueService::queueCampaignMessages()` for publish/send and scheduled sends
- `CampaignService::archive()` for archive
- `CampaignService::delete()` for delete

This keeps business logic in the module that already owns newsletter campaigns while allowing Hermes and the `Metis\Operations` framework to surface those capabilities as deterministic, approval-gated enterprise operations.

### 2.23 Backup Validation Operation

Hermes now exposes `backup_validate` as a first-class trusted system operation instead of treating backup verification as an undocumented backend detail.

The operation is grounded in the existing Metis operations runtime rather than new inline logic:

- `HermesSystemOperationsService::validateBackup()` queues the trusted `backup.stage` operation
- the queued payload pins `stage` to `verify`
- Hermes preserves approval gating because backup verification still targets a specific backup run and executes through the worker-backed system-operations path

`HermesPendingWorkflowEngine` now shares one backup-run workflow path for both `backup_restore` and `backup_validate`. If the user says `Validate backup.` without a run ID, Hermes asks for the missing `run_uuid`, stores the pending workflow in session memory, and then returns to the standard approval review using the canonical `backup_validate` operation key.

### 2.24 Release Rollback Operation

Hermes now exposes `release_rollback` as a first-class system operation instead of leaving rollback only as an internal operations-runtime command or unsupported recovery hint.

The operation is deliberately narrow:

- `HermesSystemOperationsService::rollbackRelease()` queues the trusted `release.rollback` operation
- the Hermes command and tool registries expose `release rollback`, `rollback release`, and related phrases as one canonical approval-gated action
- dispatch stays in the existing worker-backed release runtime rather than introducing a new synchronous rollback path

This gives Hermes a real supported rollback command without pretending that module-level rollback or service restart are solved. The operation stays grounded in the release manager that already owns trusted release rollback behavior.

### 2.25 Workspace User Operation Expansion

The standalone workspace-user operations family now exposes more than create and password reset. Hermes now registers first-class operations for:

- `workspace_user_update`
- `workspace_user_disable`
- `workspace_user_enable`
- `workspace_user_delete`

These operations are routed through `HermesUserAdminService`, but execution remains grounded in the existing people-module workspace services:

- updates reuse `WorkspaceUserService::saveUser()`
- enable and disable actions queue `WorkspaceUserService::queueSecurityAction()` with suspend and unsuspend actions
- deletes reuse `WorkspaceUserService::deleteUser()`

Hermes also now treats phrases like `disable workspace access` as canonical workspace-user actions instead of falling back to generic user-management routing. This expands the directive-required workspace-user surface while keeping the actual persistence and Google Workspace sync logic inside the module that already owns it.

### 2.26 User Admin Operation Expansion

Hermes now exposes more of the existing user-admin runtime as first-class operations instead of leaving those capabilities hidden behind internal service methods. This phase adds canonical operations for:

- `manage_workspace_groups`
- `reset_user_mfa`
- `link_drive_folder`

These operations are routed through `HermesCapabilityService`, but execution remains grounded in the existing `HermesUserAdminService` methods that already own the underlying behavior:

- Workspace group changes continue to use the configured Workspace integration and local membership sync logic
- MFA resets continue to use the existing person and passkey reset path
- Drive folder linking continues to use the existing Shared Drive validation and mapping logic

This closes another directive gap by turning real administrative capabilities into explicit registry-backed Hermes operations, without introducing a new execution path or speculative backend behavior.

### 2.27 Newsletter Cancel Operation

Hermes now exposes `newsletter_cancel` as a first-class newsletter operation instead of leaving scheduled-campaign cancellation implicit in the module internals.

The implementation is intentionally narrow and deterministic:

- it only applies to campaigns currently in `scheduled` or `queued` state
- it removes queued newsletter message rows for that campaign
- it resets the campaign back to `draft` with no active schedule

This keeps cancellation grounded in the existing newsletter schema and queue model rather than inventing a separate delivery-control subsystem. Sent, sending, and archived campaigns remain non-cancelable, matching the module’s existing safety boundaries.

### 2.28 Content Operation Pending Workflows

Hermes pending workflows now recover incomplete campaign and newsletter actions, not just user and backup requests. `HermesPendingWorkflowEngine` now opens deterministic follow-up workflows for existing-resource content operations when key inputs are missing.

Current coverage includes:

- `campaign_update`
- `campaign_publish`
- `campaign_archive`
- `campaign_delete`
- `newsletter_send`
- `newsletter_schedule`
- `newsletter_cancel`
- `newsletter_delete`

The workflow behavior is deliberately narrow:

- if the operation needs a campaign or newsletter reference, Hermes asks for the missing subject
- if `newsletter_schedule` also lacks a schedule time, Hermes asks for that second
- once the missing fields are collected, Hermes returns to the standard approval packaging path using the canonical operation key

This gives Hermes a more general multi-turn recovery path for operational actions without introducing freeform agentic planning or changing the enclave-backed execution model.

### 2.29 User Delete Operation

Hermes now exposes `user_delete` as a first-class user operation, but it is intentionally implemented as a conservative soft delete rather than a physical row delete.

The current behavior is:

- require an existing Metis person reference
- refuse deletion if the person still has linked Workspace access
- mark the person as inactive with `lifecycle_status = deleted`
- clear Metis role assignments
- upsert the linked auth user to an inactive state
- invalidate active auth sessions for that auth user

This keeps the operation aligned with the existing people and auth runtime instead of introducing destructive record deletion that the broader application is not clearly designed around.

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

## Recent Directive Phases

### User Unlock Operation

Hermes now exposes `user_unlock` as a first-class operation. The runtime path clears account-level login failure counters and user-scoped authentication threat state through the existing auth protection and security kernel services, and it intentionally does not pretend to clear an end user's remote IP throttle state from an admin action.

### Queued System Operations

Hermes now also exposes additional trusted queued system operations that already existed in the core operations worker: `drive_sync`, `calendar_sync`, `queue_drain`, `integrity_baseline`, and `module_compliance_audit`. These are routed through the shared operations queue instead of running inline in the request path, which keeps Hermes aligned with the workspace performance requirement to avoid heavy synchronous work.

### File-Level Backup Restore

Hermes now exposes a real queued `restore_file` path instead of a placeholder. The implementation restores only a single archive entry from a specific backup run, limits restores to known backup-managed paths like `config/...` and `storage/...`, rejects traversal or unsupported destinations, and uses a deterministic pending workflow when the backup run ID or file path is missing.

### Board Workspace Preparation

Hermes now also surfaces `board_workspace_prepare` as a first-class queued operation over the existing trusted `board.workspace.prepare` worker path. When the meeting code or ID is missing, Hermes asks for it explicitly before approval instead of dropping into a generic execution failure.

### Cron Task Queueing

Hermes now constrains `create_job` to registered cron tasks instead of allowing arbitrary generic job types. The parser captures `task_slug` for direct requests, the capability layer validates that the task exists and is enabled before queueing `cron.task.run`, and the pending workflow asks for the cron task slug explicitly when it is missing.

### Worker Job Follow-Ups

Hermes now also treats worker job administration as a deterministic workflow family. `cancel_job` and `retry_job` ask for a missing job code before approval, and the capability layer accepts parsed `job_key` payloads directly so conversational parsing, workflow review, and execution all use the same bounded job identifier path.

### People Action Follow-Ups

Hermes now treats several existing subject-only people operations as deterministic workflows instead of leaving them to fail on missing input. Commands like `user_unlock`, `user_delete`, `disable_user`, `workspace_user_disable`, `workspace_user_enable`, `workspace_user_delete`, `reset_user_mfa`, and `link_drive_folder` now ask for the target person explicitly when the subject is missing, then return to the normal approval flow with the canonical operation key and captured `subject`.

### Membership Follow-Ups

Hermes now also treats people membership mutations as deterministic multi-step workflows. Role operations such as `assign_role` and Workspace group operations such as `manage_workspace_groups` now collect the missing user reference first, then collect the missing `roles` or `group_emails` payload before entering the approval path. This keeps role and group mutations bounded to the existing user-admin services instead of letting incomplete requests fail inside execution.

Hermes now also extracts direct membership payloads more accurately when the user supplies them in one sentence. In particular, Workspace group commands now treat email addresses as `group_emails` rather than misclassifying them as the target `subject`, and role or group commands carry an explicit mutation `mode` so the execution layer does not have to infer add versus remove semantics later.

Drive-folder linking now also preserves explicit folder references from the conversational layer. When a user provides a Google Drive folder URL or folder ID in a `link_drive_folder` request, Hermes now carries that `folder_id` through parsing and tool schemas instead of dropping back to the default auto-create behavior.

## Directive Completion Checklist

- [x] explicit `Metis\Hermes\Conversation` package with state, context, and pending-action/question types
- [x] explicit `Metis\Intelligence` framework with provider contracts, DTOs, registry, support, trend, and recommendation services
- [x] explicit `Metis\Operations` framework with contracts, DTOs, registry, builder, and service ownership catalogs
- [x] deterministic approval, pending-workflow, continuation, disambiguation, and recent-entity conversation layers
- [x] multi-step operational planning, validation, approval packaging, and stop-on-failure execution
- [x] dashboard payload exposure for operations, tools, alerts, trends, recommendations, and blocked-operation metadata
- [x] blocked backend catalog with consistent unsupported metadata across commands, tools, operations, dashboard payloads, and tests
- [x] domain intelligence providers for donor, campaign, financial, newsletter, and system surfaces
- [x] first-class registry-backed user, workspace-user, campaign, newsletter, worker, backup, update, rollback, and diagnostics operations
- [x] top-level completion and parity audits plus a single directive regression runner at `tools/governance/run-hermes-directive-regression.php`
