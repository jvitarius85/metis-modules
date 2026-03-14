# Newsletter

Create newsletters, manage templates, subscriptions, and delivery outcomes.

## Routes

- Base route: `/newsletter`
- `/newsletter/dashboard` -> `dashboard.php`
- `/newsletter/campaigns` -> `campaigns.php`
- `/newsletter/templates` -> `templates.php`
- `/newsletter/lists` -> `lists.php`
- `/newsletter/subscribers` -> `subscribers.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Campaigns** template: `campaigns.php`
- **Templates** template: `templates.php`
- **Lists** template: `lists.php`
- **Subscribers** template: `subscribers.php`

## APIs

- `metis_newsletter_archive_campaign`
- `metis_newsletter_campaign_status`
- `metis_newsletter_delete_campaign`
- `metis_newsletter_giphy_search`
- `metis_newsletter_klipy_search`
- `metis_newsletter_queue_campaign`
- `metis_newsletter_record_event`
- `metis_newsletter_run_queue`
- `metis_newsletter_save_campaign`
- `metis_newsletter_save_defaults`
- `metis_newsletter_save_list`
- `metis_newsletter_save_template`
- `metis_newsletter_search_contacts`
- `metis_newsletter_sync_google_usage`
- `metis_newsletter_test_send_campaign`
- `metis_newsletter_upload_attachment`
- `metis_newsletter_upload_image`
- `metis_newsletter_upsert_subscription`

## Database Tables Used

- `contacts` (`metis_contacts`)
- `newsletter_audit` (`metis_newsletter_audit`)
- `newsletter_campaign_lists` (`metis_newsletter_campaign_lists`)
- `newsletter_campaigns` (`metis_newsletter_campaigns`)
- `newsletter_events` (`metis_newsletter_events`)
- `newsletter_google_usage_daily` (`metis_newsletter_google_usage_daily`)
- `newsletter_lists` (`metis_newsletter_lists`)
- `newsletter_messages` (`metis_newsletter_messages`)
- `newsletter_revisions` (`metis_newsletter_revisions`)
- `newsletter_subs` (`metis_newsletter_subscriptions`)
- `newsletter_suppressions` (`metis_newsletter_suppressions`)
- `newsletter_templates` (`metis_newsletter_templates`)
- `people_workspace_users` (`metis_people_workspace_users`)

## Assets and Extension Hooks

- CSS: `editor.css`, `newsletter.css`
- JS: `editor.js`, `newsletter.js`
- Registered help topics: `newsletter.dashboard`, `newsletter.campaigns`, `newsletter.templates`, `newsletter.lists`, `newsletter.subscribers`
