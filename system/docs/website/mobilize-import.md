# Mobilize Waco Content Sync (Editable)

This sync imports content from `https://mobilizewaco.org` into Metis Website pages/posts as drafts so everything remains editable.

## What it imports

- Website pages from the source CMS REST API (`/wp-json/wp/v2/pages`)
- Website posts from the source CMS REST API (`/wp-json/wp/v2/posts`)
- Title, slug, excerpt (posts), and main rendered content HTML
- Page hierarchy (`parent_id`) when importing pages

## Editability

Imported content is saved into Metis Website layout JSON as a standard `text` module (`tag=div`) plus `editor_meta.simple_html`.

This means:
- Content is editable in the current fallback page/post editor.
- Save/publish workflows remain normal in Metis.
- Team members can use starter sections in the fallback toolbar (`Insert starter section`) to quickly scaffold hero/CTA/grid/events/donate/newsletter layouts.

## Calendar integration

For the page with slug `calendar` (or link matching `/join/calendar/`), the importer appends:

`[metis:calendar.upcoming]`

`BlockRenderer` resolves this token at render time using your synced Calendar module data.

## Security sanitization

Importer strips:
- `<script>`
- `<style>`
- `<noscript>`
- `<object>`
- `<embed>`
- HTML comments

Then runs Metis HTML sanitization (`metis_runtime_kses_post`) when available.

## CLI usage

Dry run (default):

```bash
php tools/import_mobilizewaco_content.php
```

Apply changes:

```bash
php tools/import_mobilizewaco_content.php --apply
```

Pages only:

```bash
php tools/import_mobilizewaco_content.php --apply --pages-only
```

Posts only:

```bash
php tools/import_mobilizewaco_content.php --apply --posts-only
```

Custom source:

```bash
php tools/import_mobilizewaco_content.php --apply --source=https://mobilizewaco.org
```

## Notes

- Existing records are upserted by slug (updated if found, created if missing).
- Imported records are stored as `draft` for review.
- You can rerun this sync any time to refresh content from source.
- The importer reads a WordPress-compatible export API from the remote source, but the destination platform is Metis.

## Suggested team workflow

1. Run import to refresh source content into drafts.
2. Editors update pages/posts in Metis fallback editor.
3. Editors publish reviewed pages/posts.
4. Re-run import only when source needs to be re-synced (it does not force-publish).
