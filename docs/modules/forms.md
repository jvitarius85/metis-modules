# Forms

Build forms, publish public endpoints, and manage submissions.

## Routes

- Base route: `/forms`
- `/forms/dashboard` -> `dashboard.php`
- `/forms/form` -> `form.php`
- `/forms/build` -> `build.php`
- `/forms/entries` -> `entries.php`
- `/forms/settings` -> `settings.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Form** template: `form.php`
- **Build** template: `build.php`
- **Entries** template: `entries.php`
- **Settings** template: `settings.php`

## APIs

- `metis_forms_delete`
- `metis_forms_duplicate`
- `metis_forms_dynamic_options`
- `metis_forms_entries`
- `metis_forms_export`
- `metis_forms_get`
- `metis_forms_list`
- `metis_forms_publish`
- `metis_forms_save`

## Database Tables Used

- `calendar_events` (`metis_calendar_events`)
- `campaigns` (`metis_campaigns`)
- `contacts` (`metis_contacts`)
- `form_submissions` (`metis_form_submissions`)
- `form_versions` (`metis_form_versions`)
- `forms` (`metis_forms`)
- `grandys_stash_catalog` (`metis_grandys_stash_catalog`)

## Assets and Extension Hooks

- CSS: `forms.css`
- JS: `forms.js`
