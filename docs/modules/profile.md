# Profile

Manage your profile, security methods, and notification preferences.

## Routes

- Base route: `/profile`
- `/profile/dashboard` -> `dashboard.php`

## UI Components

- **Dashboard** template: `dashboard.php`

## APIs

- `metis_profile_begin_passkey_registration`
- `metis_profile_change_workspace_password`
- `metis_profile_complete_passkey_registration`
- `metis_profile_generate_totp_secret`
- `metis_profile_get`
- `metis_profile_revoke_passkey`
- `metis_profile_save`
- `metis_profile_save_avatar`
- `metis_profile_verify_totp_secret`

## Database Tables Used

- `people` (`metis_people`)
- `people_passkeys` (`metis_people_passkeys`)

## Assets and Extension Hooks

- CSS: `profile.css`
- JS: `profile.js`
