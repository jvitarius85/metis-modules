# Help Documents

## Overview

Metis Help provides a seeded, searchable help library for authenticated users and a secured admin surface for maintaining help content.

The implementation uses the Help data model, a rerunnable seeder, a full-text search index, public article routes, and Secure Enclave backed admin actions.

## Tables

### `help_categories`

- `id`
- `name`
- `slug`
- `sort_order`
- `created_at`
- `updated_at`

### `help_articles`

- `id`
- `object_code`
- `title`
- `slug`
- `summary`
- `content`
- `category_id`
- `tags`
- `search_terms`
- `seed_key`
- `system_seeded`
- `status`
- `created_at`
- `updated_at`

### `help_search_index`

- `article_id`
- `title`
- `summary`
- `content`
- `tags`
- `category_name`
- `search_terms`
- `searchable_text`
- `updated_at`

## Categories

- Getting Started
- Account & Login
- Dashboard & Navigation
- People & Permissions
- Website
- Newsletter
- Donations
- Finance
- Drive & Files
- Calendar
- Reports
- Security
- Hermes Assistant
- Settings
- Accessibility
- Troubleshooting
- System Administration

## Article Library

The default seed library creates 106 Help articles across the categories above. The article source lives in:

- `/database/seeders/help_documents_seed.php`

Each seeded article includes:

- a stable `seed_key`
- a unique `object_code` in `HLP000001` format
- searchable tags
- alternate search terms
- a structured body with:
  - `What this is`
  - `When to use it`
  - `How to use it`
  - `Important notes`
  - `Common issues`
  - `Related areas`

## Permissions

### Public Help

- Authenticated users with `help.view` can access published Help articles.

### Admin Help Management

- `help.manage` is required for:
  - article list
  - create
  - edit
  - publish
  - unpublish
  - search index rebuild

Permissions are enforced server-side through route checks and Secure Enclave policies.

## Routes

### Public

- `GET /help`
- `GET /help/search`
- `GET /help/article/{slug}`
- `GET /help/category/{slug}`

### Admin

- `GET /admin/help/articles`
- `GET /admin/help/articles/create`
- `GET /admin/help/articles/edit/{id}`

### Enclave

- `POST /enclave/help/search.php`
- `POST /enclave/help/article/save.php`
- `POST /enclave/help/article/publish.php`
- `POST /enclave/help/article/unpublish.php`
- `POST /enclave/help/index/rebuild.php`

## Seeder Behavior

The seeder is safe to run multiple times.

- Categories are created or updated by slug.
- Seeded articles are created or updated by `seed_key`.
- If an existing article has `system_seeded = 0`, the seeder preserves the manual edits.
- The search index is rebuilt after seeding.

## Search Index Behavior

Each index row stores normalized text from:

- article title
- summary
- content
- tags
- category name
- alternate search terms

Public search only returns published articles. Admin article management can list both draft and published content.

## Admin Workflow

1. Open `/admin/help/articles`.
2. Search or filter the library by text, category, or status.
3. Open create or edit.
4. Save through Secure Enclave.
5. Publish or unpublish through Secure Enclave.
6. Rebuild the search index through Secure Enclave when needed.

## Files

- `/modules/help/module.json`
- `/modules/help/admin/articles.php`
- `/modules/help/views/landing.php`
- `/modules/help/views/search_page.php`
- `/modules/help/views/article.php`
- `/modules/help/views/category.php`
- `/src/Metis/Modules/Help/HelpModule.php`
- `/src/Metis/Core/HelpSearchStore.php`
- `/database/seeders/help_documents_seed.php`
- `/enclave/help/article/save.php`
- `/enclave/help/article/publish.php`
- `/enclave/help/article/unpublish.php`
- `/enclave/help/index/rebuild.php`
