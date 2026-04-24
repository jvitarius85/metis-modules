# Hermes

Monitor system health, review recommendations, and run approved actions.

## Routes

- Base route: `/hermes`
- `/hermes/dashboard` -> `dashboard.php`

## UI Components

- **Dashboard** template: `dashboard.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- `hermes_actions` (`metis_hermes_actions`)
- `hermes_memory` (`metis_hermes_memory`)
- `hermes_messages` (`metis_hermes_messages`)
- `hermes_reports` (`metis_hermes_reports`)
- `hermes_sessions` (`metis_hermes_sessions`)

## Assets and Extension Hooks

- CSS: `hermes.css`, `hermes-dashboard.css`
- JS: `hermes.js`, `hermes-dashboard.js`
