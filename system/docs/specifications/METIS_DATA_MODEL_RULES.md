# METIS DATA MODEL RULES
Version: 1.0

Defines database and schema rules.

## Table Naming

- lowercase
- snake_case
- plural nouns

Examples:
- `people`
- `transactions`
- `newsletter_subscribers`

## Column Naming

- snake_case
- descriptive
- foreign keys use `<entity>_id`

## Standard Columns

Required where applicable:
- `id`
- `created_at`
- `updated_at`

Optional:
- `deleted_at`

## Migrations

All schema changes must use migrations.

Location:
`modules/<module>/migrations`

Migrations must be:
- reversible
- versioned
- documented
