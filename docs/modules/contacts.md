# Contacts

View and organize all contacts across the organization.

## Routes

- Base route: `/contacts`
- `/contacts/dashboard` -> `dashboard.php`
- `/contacts/contact` -> `contact.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Contact** template: `contact.php`

## APIs

- `metis_contact_add_additional_email`
- `metis_contact_add_newsletter`
- `metis_contact_add_note`
- `metis_contact_detail_save`
- `metis_contact_inline_update`
- `metis_contact_remove_additional_email`
- `metis_contact_remove_newsletter`
- `metis_contact_remove_relationship`
- `metis_contacts_cleanup_merge_notes`
- `metis_contacts_merge_duplicates`
- `metis_contacts_save`

## Database Tables Used

- `campaigns` (`metis_campaigns`)
- `contact_details` (`metis_contact_details`)
- `contact_notes` (`metis_contact_notes`)
- `contacts` (`metis_contacts`)
- `newsletter_lists` (`metis_newsletter_lists`)
- `newsletter_subs` (`metis_newsletter_subscriptions`)
- `people` (`metis_people`)
- `transactions` (`metis_transactions`)

## Assets and Extension Hooks

- CSS: `contacts.css`
- JS: `contacts.js`
- Registered help topics: `contacts.dashboard`, `contacts.contact`
