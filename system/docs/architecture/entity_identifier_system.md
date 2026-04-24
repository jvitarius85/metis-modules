# Entity Identifier System

Metis now uses a prefix-based global identifier layer for public-facing entity references. Database row IDs remain internal implementation details.

## UID Format

Every public identifier follows:

`PREFIX-NNNNNN`

Examples:

- `PPL-000001`
- `DNR-000120`
- `DTX-000981`

The numeric suffix is zero-padded to six digits and randomly allocated with collision checks.

## Registry Tables

### `metis_entity_prefixes`

Stores the canonical prefix registry:

- `entity_type`
- `prefix`
- `description`
- `created_at`

### `metis_id_sequences` (legacy compatibility)

The table remains for backward compatibility with earlier sequence-based allocation, but new UIDs are now random.

### `metis_entity_registry`

Maps a UID back to the owning row:

- `entity_uid`
- `entity_type`
- `entity_table`
- `entity_id`
- `module_slug`
- `created_at`

This is the global lookup table used by the resolver.

## Core Services

### `Metis\Core\EntityId`

Responsibilities:

- creates and seeds the prefix, sequence compatibility, and registry tables
- ensures dedicated UID columns exist on supported entity tables
- allocates a random UID for an entity type using `PREFIX-000001` formatting
- assigns UID payload fields during inserts
- registers inserted rows in `metis_entity_registry`
- migrates existing rows and fills missing UIDs

### `Metis\Core\EntityResolver`

`resolve('PPL-000042')` returns the registry mapping for the entity:

- entity type
- backing table
- internal row ID
- module slug

## Supported Entity Types

The catalog seeds core entity types plus module-declared types from each module manifest's `entity_prefixes` block.

Core entity types:

- `contact` → `CON`
- `person` → `PPL`
- `donor` → `DNR`
- `donation_campaign` → `DCP`
- `donation_transaction` → `DTX`
- `donation_deposit` → `DEP`
- `deposit_batch` → `DBT`
- `newsletter_campaign` → `NLC`
- `newsletter_template` → `NLT`
- `newsletter_list` → `NLL`
- `automation` → `AUT`
- `form` → `FRM`
- `meeting` → `MTG`
- `calendar` → `CAL`
- `security_role` → `SRL`
- `permission` → `PER`
- `activity_log` → `ACT`
- `report` → `REP`
- `form_entry` → `ENY`
- `board_packet` → `BDP`
- `board_financial` → `BDF`
- `board_minutes` → `BDM`
- `board_action_item` → `BDA`
- `board_decision_point` → `BDD`

Example module-declared entity types (Website module):

- `website_page` → `WPG`
- `website_post` → `WBP`
- `website_banner` → `WBN`

## Module Manifest Registration

Modules can declare entity prefixes in `modules/<module>/module.json`:

```json
"entity_prefixes": [
  {
    "entity_type": "website_page",
    "prefix": "WPG",
    "table_key": "website_pages",
    "uid_column": "page_code",
    "legacy_columns": ["page_code"]
  }
]
```

At runtime, `EntityCatalog` loads these manifest entries and merges them into the catalog before `EntityId` seeds `metis_entity_prefixes`.

## Creation Flow

New entity creation should follow this sequence:

1. Generate or assign the UID through `metis_entity_id_service()->assignForInsert(...)`.
2. Persist the UID in the entity’s dedicated UID column.
3. Mirror the UID into the legacy public column where compatibility is still required.
4. Insert the corresponding registry row with `metis_entity_id_service()->register(...)`.

This keeps old module queries working while public identifiers converge on the new UID format.

## Migration

Run:

```bash
php tools/migrate_entity_ids.php
```

The migration script:

- ensures schema for the registry tables and supported modules
- seeds prefixes
- backfills dedicated UID columns
- rewrites legacy public code fields to the new UID where supported
- inserts registry mappings for existing rows

## Compatibility Notes

- Existing helper calls to `metis_generate_code()` now emit catalog-backed UIDs for supported entity types.
- The legacy `Metis_Code_Registry` wrapper resolves through `metis_entity_registry`.
- Existing routes and UI query parameters still work where legacy public columns are mirrored to the new UID values.
