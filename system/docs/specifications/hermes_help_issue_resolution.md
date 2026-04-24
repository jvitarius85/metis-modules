# Hermes Help Issue Resolution

## Architecture

Hermes help issue resolution adds a read-only tool path for natural-language support requests. The flow is:

1. Hermes conversational parsing maps casual issue language to `resolve_help_issue`.
2. `Metis\Hermes\HelpIssueResolver` normalizes the issue, matches it against the shared issue catalog, and searches Help articles first.
3. The resolver checks permission and configuration signals when available.
4. Hermes returns a structured resolution payload with instructional steps, checks, escalation criteria, related articles, and optional proposed actions.
5. Any modifying action remains proposal-only and must be routed through Secure Enclave with explicit approval.

## Issue Classifications

The resolver assigns one primary classification:

- `INSTRUCTIONAL`
- `PERMISSION`
- `VALIDATION`
- `WORKFLOW`
- `SYSTEM`
- `CONFIGURATION`
- `LOCKED_STATE`

Catalog-backed matches provide the primary classification. Heuristic fallback is used only when the catalog or Help search cannot produce an exact match.

## Resolution Flow

1. Normalize the user issue text.
2. Match the issue against `config/hermes/help_issue_catalog.php`.
3. Search Help documents using the raw phrase, article title, module, and action variants.
4. Check permissions, module visibility, route context, feature flags, and recent Hermes failures when those signals are available.
5. Build a user-facing answer using:
   - What this means
   - Step-by-step fix
   - Things to check
   - When to contact an admin
   - Related help articles
6. Attach proposed Secure Enclave action payloads only when a catalog entry explicitly defines them.
7. Log the full resolution event to Hermes help issue audit storage.

## Help Search Integration

Help issue articles are seeded from the shared issue catalog. Each seeded issue article includes:

- likely causes
- step-by-step resolution
- permission checks
- validation checks
- configuration checks
- admin escalation criteria
- related help articles
- natural-language search terms

Help search ranking now persists and indexes `module_key` and `action_key` and prioritizes:

1. exact title match
2. exact search term match
3. module and action match
4. tag match
5. summary match
6. content match

## Help Document Requirements

Issue articles must be searchable by natural phrasing such as:

- `i can't create a new gl entry`
- `i don't see the new donation button`
- `the newsletter test email won't send`
- `search shows no results`

Each article stores search terms in `help_articles.search_terms`, and those terms are included in `help_search_index.searchable_text`.

## Secure Enclave Rules

Hermes help issue resolution itself is read-only. Proposed actions include an enclave payload skeleton:

- `action`
- `requested_by`
- `issue`
- `module`
- `module_key`
- `proposed_fix`
- `requires_permission`
- `risk_level`
- `nonce`

No proposed action executes automatically. Approval, permission validation, nonce validation, and audit logging remain mandatory.

## UI Behavior

The Hermes response payload includes:

- interpreted issue summary
- confidence label
- step list
- checks
- related articles
- proposed action payloads
- a preformatted markdown response block
- guidance links and walkthrough ids for supported playbooks

Walkthrough overlays must stay within the visible viewport, clamp their callout position near the highlighted control, and prefer specific UI controls over broad page containers when a playbook defines step targets.

The Help admin area now includes `/admin/help/issue-resolution` for reviewing frequent unresolved phrases, failed matches, and missing article coverage. It reuses existing Help admin rebuild patterns and does not add inline CSS or inline JavaScript.

## Audit Logging

Hermes help issue resolution writes to `metis_hermes_help_issue_logs` with:

- user id
- session code
- raw message
- normalized issue
- classification
- module key and label
- action key
- confidence label and score
- help articles used
- diagnostics checked
- proposed actions
- executed actions
- final result payload
- timestamp

## Testing Checklist

- natural-language issue parsing maps to `resolve_help_issue`
- GL entry help issue resolves to Accounting / `create_gl_entry`
- donation button issue classifies as permission-related
- publish page issue resolves to website publish action
- newsletter test send issue classifies as system-related and returns proposed actions
- report issue resolves to report execution guidance
- instructional prompts reuse the same issue playbook matching through normalized core issue phrases
- guided playbooks remain available for user creation, person search, donation editing, reports, file workflows, settings, and existing finance / website / newsletter flows
- Help search matches natural-language issue phrases through seeded search terms
- proposed actions return enclave payloads but do not execute
- help issue resolutions are audit logged
