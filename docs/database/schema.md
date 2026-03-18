# Database Schema

This section is generated from `src/Metis/Core/DatabaseRuntime.php`, module schema managers, and table registry definitions.

## `table` (`table`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tid`: `VARCHAR(16)     NOT NULL`
- `refund_date`: `DATE            NOT NULL`
- `amount`: `DECIMAL(10,2)   NOT NULL`
- `reason`: `VARCHAR(255)    DEFAULT NULL`
- `notes`: `TEXT            DEFAULT NULL`
- `source`: `VARCHAR(32)     NOT NULL DEFAULT 'manual'`
- `stripe_refund_id`: `VARCHAR(64)     DEFAULT NULL`
- `refunded_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY tid (tid)`
- `KEY stripe_refund_id (stripe_refund_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `sync_state` (`metis_sync_state`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `service`: `VARCHAR(191)    NOT NULL`
- `last_sync`: `DATETIME        DEFAULT NULL`
- `sync_token`: `LONGTEXT        DEFAULT NULL`
- `updated_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY service (service)`
- `KEY last_sync (last_sync)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `suppressions` (`suppressions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `suppression_code`: `VARCHAR(16) NOT NULL`
- `contact_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `email`: `VARCHAR(191) NOT NULL`
- `reason`: `VARCHAR(64) DEFAULT NULL`
- `source`: `VARCHAR(40) DEFAULT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `expires_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY suppression_code (suppression_code)`
- `KEY email_active (email, is_active)`
- `KEY contact_active (contact_id, is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `settings` (`metis_settings`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `setting_key`: `VARCHAR(191)    NOT NULL`
- `setting_value`: `LONGTEXT        DEFAULT NULL`
- `autoload`: `TINYINT(1)      DEFAULT 1`
- `updated_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY setting_key (setting_key)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_workspace_users` (`metis_people_workspace_users`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `workspace_user_id`: `VARCHAR(191) DEFAULT NULL`
- `primary_email`: `VARCHAR(191) NOT NULL`
- `first_name`: `VARCHAR(120) DEFAULT NULL`
- `last_name`: `VARCHAR(120) DEFAULT NULL`
- `display_name`: `VARCHAR(191) DEFAULT NULL`
- `org_unit_path`: `VARCHAR(191) DEFAULT NULL`
- `recovery_email`: `VARCHAR(191) DEFAULT NULL`
- `is_suspended`: `TINYINT(1) NOT NULL DEFAULT 0`
- `is_protected`: `TINYINT(1) NOT NULL DEFAULT 0`
- `last_login_at`: `DATETIME DEFAULT NULL`
- `source`: `VARCHAR(24) NOT NULL DEFAULT 'metis'`
- `sync_status`: `VARCHAR(24) NOT NULL DEFAULT 'pending'`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY primary_email (primary_email)`
- `KEY person_id (person_id)`
- `KEY workspace_user_id (workspace_user_id)`
- `KEY sync_status (sync_status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_workspace_user_roles` (`metis_people_workspace_user_roles`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `workspace_user_id`: `BIGINT UNSIGNED NOT NULL`
- `role_key`: `VARCHAR(64) NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY user_role (workspace_user_id, role_key)`
- `KEY role_key (role_key)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_workspace_sync_jobs` (`metis_people_workspace_sync_jobs`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `job_type`: `VARCHAR(64) NOT NULL`
- `entity_type`: `VARCHAR(32) NOT NULL`
- `entity_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `requested_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `payload_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'queued'`
- `last_error`: `TEXT DEFAULT NULL`
- `processed_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY status (status)`
- `KEY entity_ref (entity_type, entity_id)`
- `KEY job_type (job_type)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_workspace_security_actions` (`metis_people_workspace_security_actions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `workspace_user_id`: `BIGINT UNSIGNED NOT NULL`
- `action_type`: `VARCHAR(64) NOT NULL`
- `requested_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'pending'`
- `reason`: `TEXT DEFAULT NULL`
- `payload_json`: `LONGTEXT DEFAULT NULL`
- `completed_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY workspace_user_id (workspace_user_id)`
- `KEY action_status (action_type, status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_workspace_groups` (`metis_people_workspace_groups`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `workspace_group_id`: `VARCHAR(191) DEFAULT NULL`
- `group_email`: `VARCHAR(191) NOT NULL`
- `group_name`: `VARCHAR(191) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `direct_members_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `source`: `VARCHAR(24) NOT NULL DEFAULT 'metis'`
- `sync_status`: `VARCHAR(24) NOT NULL DEFAULT 'pending'`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY group_email (group_email)`
- `KEY workspace_group_id (workspace_group_id)`
- `KEY sync_status (sync_status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_workspace_group_members` (`metis_people_workspace_group_members`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `group_id`: `BIGINT UNSIGNED NOT NULL`
- `workspace_user_id`: `BIGINT UNSIGNED NOT NULL`
- `member_role`: `VARCHAR(24) NOT NULL DEFAULT 'member'`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY group_member (group_id, workspace_user_id)`
- `KEY group_id (group_id)`
- `KEY workspace_user_id (workspace_user_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_user_roles` (`metis_people_user_roles`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED NOT NULL`
- `role_id`: `BIGINT UNSIGNED NOT NULL`
- `start_at`: `DATETIME DEFAULT NULL`
- `end_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY person_role (person_id, role_id)`
- `KEY role_id (role_id)`
- `KEY role_window (start_at, end_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_template_roles` (`metis_people_template_roles`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `template_id`: `BIGINT UNSIGNED NOT NULL`
- `role_id`: `BIGINT UNSIGNED NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY template_role (template_id, role_id)`
- `KEY role_id (role_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_roles` (`metis_people_roles`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `role_key`: `VARCHAR(64) NOT NULL`
- `role_domain`: `VARCHAR(24) NOT NULL DEFAULT 'metis'`
- `role_name`: `VARCHAR(120) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `is_system`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY role_domain_key (role_domain, role_key)`
- `KEY role_domain (role_domain)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_role_templates` (`metis_people_role_templates`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `template_key`: `VARCHAR(64) NOT NULL`
- `template_name`: `VARCHAR(120) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `checklist_json`: `LONGTEXT DEFAULT NULL`
- `is_system`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY template_key (template_key)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_role_perms` (`metis_people_role_permissions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `role_id`: `BIGINT UNSIGNED NOT NULL`
- `permission_id`: `BIGINT UNSIGNED NOT NULL`
- `allow_access`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY role_permission (role_id, permission_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_permissions` (`metis_people_permissions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `permission_key`: `VARCHAR(191) NOT NULL`
- `module_slug`: `VARCHAR(64) NOT NULL`
- `action_key`: `VARCHAR(32) NOT NULL`
- `permission_name`: `VARCHAR(191) NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY permission_key (permission_key)`
- `KEY module_action (module_slug, action_key)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_passkeys` (`metis_people_passkeys`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED NOT NULL`
- `credential_id`: `VARCHAR(255) NOT NULL`
- `credential_public_key`: `LONGTEXT DEFAULT NULL`
- `sign_count`: `BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `transports_json`: `LONGTEXT DEFAULT NULL`
- `label`: `VARCHAR(120) DEFAULT NULL`
- `created_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `last_used_at`: `DATETIME DEFAULT NULL`
- `revoked_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY credential_id (credential_id)`
- `KEY person_active (person_id, revoked_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_lifecycle_tasks` (`metis_people_lifecycle_tasks`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED NOT NULL`
- `phase`: `VARCHAR(24) NOT NULL DEFAULT 'onboarding'`
- `task_key`: `VARCHAR(120) DEFAULT NULL`
- `task_label`: `VARCHAR(255) NOT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'pending'`
- `due_at`: `DATETIME DEFAULT NULL`
- `completed_at`: `DATETIME DEFAULT NULL`
- `source_template_key`: `VARCHAR(64) DEFAULT NULL`
- `created_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY person_phase_status (person_id, phase, status)`
- `KEY due_at (due_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_emergency_access` (`metis_people_emergency_access`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED NOT NULL`
- `granted_role_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `reason`: `TEXT DEFAULT NULL`
- `granted_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `starts_at`: `DATETIME NOT NULL`
- `ends_at`: `DATETIME NOT NULL`
- `revoked_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY person_id (person_id)`
- `KEY granted_role_id (granted_role_id)`
- `KEY ends_at (ends_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_documents` (`metis_people_documents`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED NOT NULL`
- `doc_type`: `VARCHAR(64) NOT NULL`
- `doc_label`: `VARCHAR(191) NOT NULL`
- `storage_ref`: `VARCHAR(255) DEFAULT NULL`
- `meta_json`: `LONGTEXT DEFAULT NULL`
- `remind_at`: `DATETIME DEFAULT NULL`
- `expires_at`: `DATETIME DEFAULT NULL`
- `lifecycle_status`: `VARCHAR(24) NOT NULL DEFAULT 'active'`
- `created_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY person_id (person_id)`
- `KEY doc_type (doc_type)`
- `KEY lifecycle_dates (lifecycle_status, expires_at, remind_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_auth_challenges` (`metis_people_auth_challenges`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `challenge_key`: `VARCHAR(64) NOT NULL`
- `challenge_value`: `VARCHAR(191) NOT NULL`
- `purpose`: `VARCHAR(32) NOT NULL`
- `expires_at`: `DATETIME NOT NULL`
- `consumed_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY challenge_key (challenge_key)`
- `KEY purpose_expires (purpose, expires_at)`
- `KEY person_id (person_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_activity` (`metis_people_activity`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `actor_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `activity_type`: `VARCHAR(64) NOT NULL`
- `summary`: `VARCHAR(255) NOT NULL`
- `details`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY person_id (person_id)`
- `KEY actor_person_id (actor_person_id)`
- `KEY activity_type (activity_type)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people_access_requests` (`metis_people_access_requests`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `request_code`: `VARCHAR(24) DEFAULT NULL`
- `requester_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `target_person_id`: `BIGINT UNSIGNED NOT NULL`
- `role_id`: `BIGINT UNSIGNED NOT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'pending'`
- `reason`: `TEXT DEFAULT NULL`
- `decision_note`: `TEXT DEFAULT NULL`
- `required_approvals`: `TINYINT UNSIGNED NOT NULL DEFAULT 2`
- `approval_count`: `TINYINT UNSIGNED NOT NULL DEFAULT 0`
- `approval_log_json`: `LONGTEXT DEFAULT NULL`
- `requested_start_at`: `DATETIME DEFAULT NULL`
- `requested_end_at`: `DATETIME DEFAULT NULL`
- `expires_at`: `DATETIME DEFAULT NULL`
- `resolver_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `resolved_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY request_code (request_code)`
- `KEY target_person_id (target_person_id)`
- `KEY role_id (role_id)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `people` (`metis_people`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `pid`: `VARCHAR(16) DEFAULT NULL`
- `auth_provider`: `VARCHAR(32) NOT NULL DEFAULT 'metis'`
- `email`: `VARCHAR(191) NOT NULL`
- `first_name`: `VARCHAR(120) DEFAULT NULL`
- `last_name`: `VARCHAR(120) DEFAULT NULL`
- `display_name`: `VARCHAR(191) NOT NULL`
- `linked_donor_id`: `VARCHAR(64) DEFAULT NULL`
- `is_workspace_user`: `TINYINT(1) NOT NULL DEFAULT 0`
- `workspace_email`: `VARCHAR(191) DEFAULT NULL`
- `workspace_role`: `VARCHAR(64) DEFAULT NULL`
- `stripe_role`: `VARCHAR(64) DEFAULT NULL`
- `is_staff`: `TINYINT(1) NOT NULL DEFAULT 0`
- `is_board`: `TINYINT(1) NOT NULL DEFAULT 0`
- `is_volunteer`: `TINYINT(1) NOT NULL DEFAULT 0`
- `manager_pid`: `VARCHAR(16) DEFAULT NULL`
- `department`: `VARCHAR(120) DEFAULT NULL`
- `board_term_start`: `DATE DEFAULT NULL`
- `board_term_end`: `DATE DEFAULT NULL`
- `volunteer_area`: `VARCHAR(120) DEFAULT NULL`
- `lifecycle_status`: `VARCHAR(24) NOT NULL DEFAULT 'active'`
- `email_notifications`: `TINYINT(1) NOT NULL DEFAULT 1`
- `sms_notifications`: `TINYINT(1) NOT NULL DEFAULT 0`
- `notification_prefs_json`: `LONGTEXT DEFAULT NULL`
- `requires_2fa`: `TINYINT(1) NOT NULL DEFAULT 0`
- `mfa_method`: `VARCHAR(24) NOT NULL DEFAULT 'none'`
- `totp_enabled`: `TINYINT(1) NOT NULL DEFAULT 0`
- `passkey_enabled`: `TINYINT(1) NOT NULL DEFAULT 0`
- `totp_secret_enc`: `TEXT DEFAULT NULL`
- `avatar_url`: `VARCHAR(255) DEFAULT NULL`
- `last_active_at`: `DATETIME DEFAULT NULL`
- `offboarded_at`: `DATETIME DEFAULT NULL`
- `status`: `VARCHAR(20) NOT NULL DEFAULT 'active'`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY pid (pid)`
- `UNIQUE KEY email (email)`
- `KEY linked_donor_id (linked_donor_id)`
- `KEY workspace_email (workspace_email)`
- `KEY auth_provider (auth_provider)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_templates` (`metis_newsletter_templates`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `template_code`: `VARCHAR(16) NOT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `subject`: `VARCHAR(255) NOT NULL`
- `from_name`: `VARCHAR(191) DEFAULT NULL`
- `from_email`: `VARCHAR(191) DEFAULT NULL`
- `reply_to`: `VARCHAR(191) DEFAULT NULL`
- `doc_json`: `LONGTEXT DEFAULT NULL`
- `html_body`: `LONGTEXT NOT NULL`
- `text_body`: `LONGTEXT DEFAULT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY template_code (template_code)`
- `KEY is_active (is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_subs` (`metis_newsletter_subscriptions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `contact_id`: `BIGINT UNSIGNED NOT NULL`
- `list_id`: `BIGINT UNSIGNED NOT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'subscribed'`
- `source`: `VARCHAR(40) DEFAULT NULL`
- `subscribed_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `unsubscribed_at`: `DATETIME DEFAULT NULL`
- `bounce_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `last_event_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY combo (contact_id, list_id)`
- `KEY list_id (list_id)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_revisions` (`metis_newsletter_revisions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `revision_code`: `VARCHAR(16) NOT NULL`
- `entity_type`: `VARCHAR(24) NOT NULL`
- `entity_id`: `BIGINT UNSIGNED NOT NULL`
- `summary`: `VARCHAR(255) DEFAULT NULL`
- `doc_json`: `LONGTEXT DEFAULT NULL`
- `html_body`: `LONGTEXT DEFAULT NULL`
- `text_body`: `LONGTEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY revision_code (revision_code)`
- `KEY entity_ref (entity_type, entity_id)`
- `KEY created_at (created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_messages` (`metis_newsletter_messages`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `message_code`: `VARCHAR(16) NOT NULL`
- `campaign_id`: `BIGINT UNSIGNED NOT NULL`
- `contact_id`: `BIGINT UNSIGNED NOT NULL`
- `email`: `VARCHAR(191) NOT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'queued'`
- `provider`: `VARCHAR(40) DEFAULT 'gmail_api'`
- `provider_message_id`: `VARCHAR(191) DEFAULT NULL`
- `attempts`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `queued_at`: `DATETIME DEFAULT NULL`
- `sent_at`: `DATETIME DEFAULT NULL`
- `delivered_at`: `DATETIME DEFAULT NULL`
- `bounced_at`: `DATETIME DEFAULT NULL`
- `rejected_at`: `DATETIME DEFAULT NULL`
- `opened_at`: `DATETIME DEFAULT NULL`
- `clicked_at`: `DATETIME DEFAULT NULL`
- `last_error`: `TEXT DEFAULT NULL`
- `payload_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY message_code (message_code)`
- `UNIQUE KEY campaign_contact (campaign_id, contact_id)`
- `KEY status (status)`
- `KEY email (email)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_lists` (`metis_newsletter_lists`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `list_key`: `VARCHAR(32) DEFAULT NULL`
- `legacy_lid`: `VARCHAR(50) DEFAULT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY list_key (list_key)`
- `UNIQUE KEY legacy_lid (legacy_lid)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_google_usage_daily` (`metis_newsletter_google_usage_daily`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `usage_date`: `DATE NOT NULL`
- `workspace_email`: `VARCHAR(191) NOT NULL`
- `sent_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `source`: `VARCHAR(40) DEFAULT 'google_reports'`
- `workspace_user_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `payload_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY usage_email_date (usage_date, workspace_email)`
- `KEY usage_date (usage_date)`
- `KEY sent_count (sent_count)`
- `KEY workspace_user_id (workspace_user_id)`
- `KEY person_id (person_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_events` (`metis_newsletter_events`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `event_code`: `VARCHAR(16) NOT NULL`
- `message_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `campaign_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `contact_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `email`: `VARCHAR(191) DEFAULT NULL`
- `event_type`: `VARCHAR(32) NOT NULL`
- `reason`: `VARCHAR(255) DEFAULT NULL`
- `source`: `VARCHAR(40) DEFAULT NULL`
- `event_at`: `DATETIME DEFAULT NULL`
- `payload_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY event_code (event_code)`
- `KEY message_id (message_id)`
- `KEY campaign_id (campaign_id)`
- `KEY event_type (event_type)`
- `KEY email (email)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_campaigns` (`metis_newsletter_campaigns`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `campaign_code`: `VARCHAR(16) NOT NULL`
- `template_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `subject`: `VARCHAR(255) NOT NULL`
- `from_name`: `VARCHAR(191) DEFAULT NULL`
- `from_email`: `VARCHAR(191) DEFAULT NULL`
- `reply_to`: `VARCHAR(191) DEFAULT NULL`
- `preheader`: `VARCHAR(255) DEFAULT NULL`
- `doc_json`: `LONGTEXT DEFAULT NULL`
- `html_body`: `LONGTEXT DEFAULT NULL`
- `text_body`: `LONGTEXT DEFAULT NULL`
- `audience_json`: `LONGTEXT DEFAULT NULL`
- `attachments_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `test_sent_at`: `DATETIME DEFAULT NULL`
- `scheduled_at`: `DATETIME DEFAULT NULL`
- `queued_at`: `DATETIME DEFAULT NULL`
- `archived_at`: `DATETIME DEFAULT NULL`
- `sent_at`: `DATETIME DEFAULT NULL`
- `total_recipients`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `sent_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `failed_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `bounced_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `rejected_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `last_error`: `TEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY campaign_code (campaign_code)`
- `KEY status (status)`
- `KEY scheduled_at (scheduled_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `newsletter_audit` (`metis_newsletter_audit`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `audit_code`: `VARCHAR(16) NOT NULL`
- `action`: `VARCHAR(40) NOT NULL`
- `entity_type`: `VARCHAR(24) NOT NULL`
- `entity_id`: `BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `user_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `meta_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY audit_code (audit_code)`
- `KEY entity_action (entity_type, entity_id, action)`
- `KEY created_at (created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `job_queue` (`metis_job_queue`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `job_code`: `VARCHAR(16)     NOT NULL`
- `queue_name`: `VARCHAR(64)     NOT NULL DEFAULT 'default'`
- `job_type`: `VARCHAR(191)    NOT NULL`
- `status`: `VARCHAR(24)     NOT NULL DEFAULT 'queued'`
- `dedupe_key`: `VARCHAR(191)    DEFAULT NULL`
- `priority`: `SMALLINT UNSIGNED NOT NULL DEFAULT 50`
- `attempts`: `INT UNSIGNED    NOT NULL DEFAULT 0`
- `max_attempts`: `INT UNSIGNED    NOT NULL DEFAULT 3`
- `available_at`: `DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `reserved_at`: `DATETIME        DEFAULT NULL`
- `reserved_until`: `DATETIME        DEFAULT NULL`
- `started_at`: `DATETIME        DEFAULT NULL`
- `completed_at`: `DATETIME        DEFAULT NULL`
- `failed_at`: `DATETIME        DEFAULT NULL`
- `last_error`: `TEXT            DEFAULT NULL`
- `payload_json`: `LONGTEXT        DEFAULT NULL`
- `result_json`: `LONGTEXT        DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY job_code (job_code)`
- `KEY queue_status_available (queue_name, status, available_at)`
- `KEY job_type (job_type)`
- `KEY dedupe_key (dedupe_key)`
- `KEY reserved_until (reserved_until)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `grandys_stash_items` (`metis_grandys_stash_items`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `equipment_code`: `VARCHAR(16) NOT NULL`
- `name`: `VARCHAR(191) NOT NULL`
- `category`: `VARCHAR(64) NOT NULL DEFAULT 'mobility_aids'`
- `condition_status`: `VARCHAR(32) NOT NULL DEFAULT 'good'`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'available'`
- `storage_location`: `VARCHAR(191) DEFAULT NULL`
- `serial_number`: `VARCHAR(120) DEFAULT NULL`
- `donor_contact_cid`: `VARCHAR(16) DEFAULT NULL`
- `source_case_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `notes`: `TEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY equipment_code (equipment_code)`
- `KEY category_status (category, status)`
- `KEY donor_contact_cid (donor_contact_cid)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `grandys_stash_distributions` (`metis_grandys_stash_distributions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `distribution_code`: `VARCHAR(16) NOT NULL`
- `item_id`: `BIGINT UNSIGNED NOT NULL`
- `case_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `recipient_cid`: `VARCHAR(16) DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'assigned'`
- `fulfillment_method`: `VARCHAR(24) DEFAULT NULL`
- `scheduled_for`: `DATETIME DEFAULT NULL`
- `completed_at`: `DATETIME DEFAULT NULL`
- `notes`: `TEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY distribution_code (distribution_code)`
- `KEY item_id (item_id)`
- `KEY case_id (case_id)`
- `KEY recipient_cid (recipient_cid)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `grandys_stash_catalog` (`metis_grandys_stash_catalog`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `catalog_code`: `VARCHAR(16) NOT NULL`
- `item_name`: `VARCHAR(191) NOT NULL`
- `item_slug`: `VARCHAR(191) NOT NULL`
- `category_name`: `VARCHAR(120) NOT NULL`
- `category_slug`: `VARCHAR(120) NOT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `sort_order`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY catalog_code (catalog_code)`
- `UNIQUE KEY item_slug (item_slug)`
- `KEY category_slug (category_slug)`
- `KEY is_active_sort (is_active, sort_order)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `grandys_stash_cases` (`metis_grandys_stash_cases`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `case_code`: `VARCHAR(16) NOT NULL`
- `intake_type`: `VARCHAR(24) NOT NULL DEFAULT 'request'`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'new'`
- `contact_cid`: `VARCHAR(16) DEFAULT NULL`
- `assignee_user_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `assignee_name`: `VARCHAR(191) DEFAULT NULL`
- `urgency`: `VARCHAR(24) NOT NULL DEFAULT 'standard'`
- `pickup_delivery`: `VARCHAR(24) DEFAULT NULL`
- `requested_categories_json`: `LONGTEXT DEFAULT NULL`
- `requested_items_json`: `LONGTEXT DEFAULT NULL`
- `offered_items_json`: `LONGTEXT DEFAULT NULL`
- `notes`: `TEXT DEFAULT NULL`
- `internal_notes`: `TEXT DEFAULT NULL`
- `scheduled_for`: `DATETIME DEFAULT NULL`
- `form_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `form_submission_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY case_code (case_code)`
- `UNIQUE KEY form_submission_id (form_submission_id)`
- `KEY intake_status (intake_type, status)`
- `KEY contact_cid (contact_cid)`
- `KEY assignee_user_id (assignee_user_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `forms` (`metis_forms`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `form_uuid`: `VARCHAR(16) NOT NULL`
- `slug`: `VARCHAR(120) NOT NULL`
- `name`: `VARCHAR(191) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `latest_version_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `published_version_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `payment_enabled`: `TINYINT(1) NOT NULL DEFAULT 0`
- `settings_json`: `LONGTEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY form_uuid (form_uuid)`
- `UNIQUE KEY slug (slug)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `form_versions` (`metis_form_versions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `form_id`: `BIGINT UNSIGNED NOT NULL`
- `version_number`: `INT UNSIGNED NOT NULL DEFAULT 1`
- `schema_json`: `LONGTEXT NOT NULL`
- `checksum`: `VARCHAR(64) DEFAULT NULL`
- `notes`: `TEXT DEFAULT NULL`
- `is_published`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY form_version (form_id, version_number)`
- `KEY form_published (form_id, is_published)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `form_submissions` (`metis_form_submissions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `form_id`: `BIGINT UNSIGNED NOT NULL`
- `version_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `submission_key`: `VARCHAR(16) NOT NULL`
- `submission_status`: `VARCHAR(24) NOT NULL DEFAULT 'submitted'`
- `payment_status`: `VARCHAR(24) NOT NULL DEFAULT 'not_required'`
- `payment_intent_id`: `VARCHAR(191) DEFAULT NULL`
- `amount_total`: `DECIMAL(12,2) NOT NULL DEFAULT 0.00`
- `currency`: `VARCHAR(8) NOT NULL DEFAULT 'usd'`
- `submitter_email`: `VARCHAR(191) DEFAULT NULL`
- `source_url`: `VARCHAR(255) DEFAULT NULL`
- `payload_json`: `LONGTEXT NOT NULL`
- `normalized_json`: `LONGTEXT DEFAULT NULL`
- `totals_json`: `LONGTEXT DEFAULT NULL`
- `automation_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY submission_key (submission_key)`
- `KEY form_created (form_id, created_at)`
- `KEY payment_intent_id (payment_intent_id(191))`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_tags` (`metis_finance_tags`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `tag_key`: `VARCHAR(64) NOT NULL`
- `tag_name`: `VARCHAR(191) NOT NULL`
- `tag_type`: `VARCHAR(32) NOT NULL DEFAULT 'program'`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY tag_key (tag_key)`
- `KEY tag_type (tag_type)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_reconciliations` (`metis_finance_reconciliations`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `account_key`: `VARCHAR(64) NOT NULL`
- `period_start`: `DATE NOT NULL`
- `period_end`: `DATE NOT NULL`
- `book_balance`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `statement_balance`: `DECIMAL(14,2) DEFAULT NULL`
- `variance`: `DECIMAL(14,2) DEFAULT NULL`
- `matched_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'open'`
- `notes`: `LONGTEXT DEFAULT NULL`
- `last_synced_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY account_period (account_key, period_start, period_end)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_ledger` (`metis_finance_ledger`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `entry_date`: `DATE NOT NULL`
- `account_key`: `VARCHAR(64) NOT NULL`
- `source_type`: `VARCHAR(64) NOT NULL`
- `source_ref`: `VARCHAR(128) NOT NULL`
- `direction`: `VARCHAR(16) NOT NULL DEFAULT 'inflow'`
- `entry_side`: `VARCHAR(16) DEFAULT NULL`
- `contra_account_key`: `VARCHAR(64) DEFAULT NULL`
- `event_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `fund_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `campaign_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `amount`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `memo`: `VARCHAR(255) DEFAULT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'posted'`
- `meta`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY source_account (account_key, source_type, source_ref, direction)`
- `KEY entry_date (entry_date)`
- `KEY status (status)`
- `KEY account_key (account_key)`
- `KEY event_id (event_id)`
- `KEY fund_id (fund_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_funds` (`metis_finance_funds`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `fund_key`: `VARCHAR(64) NOT NULL`
- `fund_name`: `VARCHAR(191) NOT NULL`
- `restriction_type`: `VARCHAR(32) NOT NULL DEFAULT 'unrestricted'`
- `description`: `TEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY fund_key (fund_key)`
- `KEY restriction_type (restriction_type)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_events` (`metis_finance_events`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `event_type`: `VARCHAR(64) NOT NULL`
- `provider`: `VARCHAR(64) DEFAULT NULL`
- `reference_id`: `VARCHAR(128) NOT NULL`
- `amount`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `currency`: `VARCHAR(8) NOT NULL DEFAULT 'usd'`
- `fund_id`: `BIGINT UNSIGNED NOT NULL`
- `campaign_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `notes`: `TEXT DEFAULT NULL`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `occurred_at`: `DATETIME NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY event_ref (event_type, provider, reference_id)`
- `KEY occurred_at (occurred_at)`
- `KEY fund_id (fund_id)`
- `KEY campaign_id (campaign_id)`
- `KEY provider (provider)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_event_tags` (`metis_finance_event_tags`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `event_id`: `BIGINT UNSIGNED NOT NULL`
- `tag_id`: `BIGINT UNSIGNED NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY event_tag (event_id, tag_id)`
- `KEY tag_id (tag_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_accounts` (`metis_finance_accounts`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `account_key`: `VARCHAR(64) NOT NULL`
- `label`: `VARCHAR(191) NOT NULL`
- `category`: `VARCHAR(64) NOT NULL`
- `normal_balance`: `VARCHAR(16) NOT NULL DEFAULT 'debit'`
- `is_system`: `TINYINT(1) NOT NULL DEFAULT 1`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY account_key (account_key)`
- `KEY category (category)`
- `KEY is_active (is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_user_folders` (`metis_drive_user_folders`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL`
- `person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `folder_id`: `VARCHAR(191) NOT NULL`
- `folder_name`: `VARCHAR(255) DEFAULT NULL`
- `parent_folder_id`: `VARCHAR(191) DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY drive_person (drive_id, person_id)`
- `KEY person_id (person_id)`
- `KEY folder_id (folder_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_sync_state` (`metis_drive_sync_state`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL`
- `folder_id`: `VARCHAR(191) NOT NULL`
- `parent_folder_id`: `VARCHAR(191) DEFAULT NULL`
- `folder_name`: `VARCHAR(255) DEFAULT NULL`
- `last_synced_at`: `DATETIME DEFAULT NULL`
- `last_requested_at`: `DATETIME DEFAULT NULL`
- `sync_status`: `VARCHAR(32) NOT NULL DEFAULT 'idle'`
- `sync_depth`: `SMALLINT UNSIGNED NOT NULL DEFAULT 0`
- `item_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `last_error`: `TEXT DEFAULT NULL`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY drive_folder (drive_id, folder_id)`
- `KEY sync_status (sync_status)`
- `KEY last_synced_at (last_synced_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_items` (`metis_drive_items`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL`
- `item_id`: `VARCHAR(191) NOT NULL`
- `parent_id`: `VARCHAR(191) NOT NULL`
- `item_name`: `VARCHAR(255) NOT NULL`
- `mime_type`: `VARCHAR(191) NOT NULL`
- `is_folder`: `TINYINT(1) NOT NULL DEFAULT 0`
- `modified_time`: `DATETIME DEFAULT NULL`
- `size_bytes`: `BIGINT UNSIGNED DEFAULT NULL`
- `web_view_link`: `TEXT DEFAULT NULL`
- `raw_json`: `LONGTEXT DEFAULT NULL`
- `synced_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY drive_item (drive_id, item_id)`
- `KEY drive_parent (drive_id, parent_id)`
- `KEY drive_parent_folder (drive_id, parent_id, is_folder)`
- `KEY item_name (item_name)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_audit` (`metis_drive_audit`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL`
- `folder_id`: `VARCHAR(191) DEFAULT NULL`
- `file_id`: `VARCHAR(191) DEFAULT NULL`
- `item_name`: `VARCHAR(255) DEFAULT NULL`
- `item_type`: `VARCHAR(64) DEFAULT NULL`
- `action_key`: `VARCHAR(64) NOT NULL`
- `actor_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `details_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY drive_id (drive_id)`
- `KEY folder_id (folder_id)`
- `KEY file_id (file_id)`
- `KEY action_key (action_key)`
- `KEY actor_person_id (actor_person_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `contacts` (`metis_contacts`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `did`: `VARCHAR(16)     DEFAULT NULL`
- `email`: `VARCHAR(180)    NOT NULL`
- `first_name`: `VARCHAR(120)    DEFAULT ''`
- `last_name`: `VARCHAR(120)    DEFAULT ''`
- `created_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY email (email)`
- `UNIQUE KEY did   (did)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `contact_dav_tokens` (`metis_contact_dav_tokens`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `user_id`: `BIGINT UNSIGNED NOT NULL`
- `label`: `VARCHAR(191)    NOT NULL DEFAULT ''`
- `token_prefix`: `VARCHAR(32)     NOT NULL DEFAULT ''`
- `token_hash`: `CHAR(64)        NOT NULL`
- `last_used_at`: `DATETIME        DEFAULT NULL`
- `created_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP`
- `revoked_at`: `DATETIME        DEFAULT NULL`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY token_hash (token_hash)`
- `KEY user_id (user_id)`
- `KEY token_prefix (token_prefix)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `contact_dav_sync` (`metis_contact_dav_sync`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `book_slug`: `VARCHAR(191)    NOT NULL`
- `contact_cid`: `VARCHAR(64)     NOT NULL`
- `operation`: `VARCHAR(20)     NOT NULL DEFAULT 'upsert'`
- `contact_etag`: `CHAR(40)        DEFAULT NULL`
- `changed_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY book_slug (book_slug)`
- `KEY contact_cid (contact_cid)`
- `KEY changed_at (changed_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `campaign_lists` (`campaign_lists`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `campaign_id`: `BIGINT UNSIGNED NOT NULL`
- `list_id`: `BIGINT UNSIGNED NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY campaign_list (campaign_id, list_id)`
- `KEY list_id (list_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `calendar_sync_state` (`metis_calendar_sync_state`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `calendar_id`: `VARCHAR(191) NOT NULL`
- `calendar_name`: `VARCHAR(255) DEFAULT NULL`
- `last_synced_at`: `DATETIME DEFAULT NULL`
- `last_requested_at`: `DATETIME DEFAULT NULL`
- `sync_status`: `VARCHAR(32) NOT NULL DEFAULT 'idle'`
- `item_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
- `last_error`: `TEXT DEFAULT NULL`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY calendar_id (calendar_id)`
- `KEY sync_status (sync_status)`
- `KEY last_synced_at (last_synced_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `calendar_events` (`metis_calendar_events`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `calendar_id`: `VARCHAR(191) NOT NULL`
- `event_id`: `VARCHAR(191) NOT NULL`
- `event_status`: `VARCHAR(32) NOT NULL DEFAULT 'confirmed'`
- `summary`: `TEXT DEFAULT NULL`
- `location`: `TEXT DEFAULT NULL`
- `description`: `LONGTEXT DEFAULT NULL`
- `event_start`: `DATETIME DEFAULT NULL`
- `event_end`: `DATETIME DEFAULT NULL`
- `is_all_day`: `TINYINT(1) NOT NULL DEFAULT 0`
- `event_type`: `VARCHAR(64) NOT NULL DEFAULT 'general'`
- `event_module`: `VARCHAR(64) NOT NULL DEFAULT 'general'`
- `etag`: `VARCHAR(191) DEFAULT NULL`
- `google_updated_at`: `DATETIME DEFAULT NULL`
- `html_link`: `TEXT DEFAULT NULL`
- `raw_json`: `LONGTEXT DEFAULT NULL`
- `synced_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY calendar_event (calendar_id, event_id)`
- `KEY calendar_start (calendar_id, event_start)`
- `KEY event_start (event_start)`
- `KEY google_updated_at (google_updated_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_meetings` (`metis_board_meetings`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `meeting_code`: `VARCHAR(16) DEFAULT NULL`
- `title`: `VARCHAR(191) NOT NULL`
- `committee_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `meeting_date`: `DATETIME NOT NULL`
- `meeting_type`: `VARCHAR(32) NOT NULL DEFAULT 'board'`
- `location`: `VARCHAR(191) DEFAULT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'draft'`
- `agenda_json`: `LONGTEXT DEFAULT NULL`
- `minutes_html`: `LONGTEXT DEFAULT NULL`
- `board_packet_notes`: `LONGTEXT DEFAULT NULL`
- `packet_source_minutes_meeting_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `packet_financial_document_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `google_calendar_event_id`: `VARCHAR(191) DEFAULT NULL`
- `google_calendar_html_link`: `VARCHAR(255) DEFAULT NULL`
- `google_drive_folder_id`: `VARCHAR(191) DEFAULT NULL`
- `google_drive_folder_url`: `VARCHAR(255) DEFAULT NULL`
- `attendance_locked`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `published_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY meeting_code (meeting_code)`
- `KEY committee_id (committee_id)`
- `KEY meeting_date (meeting_date)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_documents` (`metis_board_documents`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `document_code`: `VARCHAR(16) DEFAULT NULL`
- `meeting_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `committee_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `title`: `VARCHAR(191) NOT NULL`
- `doc_type`: `VARCHAR(40) NOT NULL DEFAULT 'board_packet'`
- `google_file_id`: `VARCHAR(191) DEFAULT NULL`
- `google_drive_url`: `VARCHAR(255) DEFAULT NULL`
- `mime_type`: `VARCHAR(120) DEFAULT NULL`
- `file_size`: `BIGINT UNSIGNED DEFAULT NULL`
- `version_label`: `VARCHAR(32) DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'active'`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY document_code (document_code)`
- `KEY meeting_id (meeting_id)`
- `KEY committee_id (committee_id)`
- `KEY doc_type (doc_type)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_decisions` (`metis_board_decisions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `decision_code`: `VARCHAR(16) DEFAULT NULL`
- `meeting_id`: `BIGINT UNSIGNED NOT NULL`
- `title`: `VARCHAR(191) NOT NULL`
- `agenda_section_title`: `VARCHAR(191) DEFAULT NULL`
- `agenda_item_title`: `VARCHAR(191) DEFAULT NULL`
- `agenda_point_hash`: `VARCHAR(64) DEFAULT NULL`
- `decision_text`: `LONGTEXT DEFAULT NULL`
- `outcome`: `VARCHAR(32) NOT NULL DEFAULT 'pending'`
- `votes_for`: `INT NOT NULL DEFAULT 0`
- `votes_against`: `INT NOT NULL DEFAULT 0`
- `votes_abstain`: `INT NOT NULL DEFAULT 0`
- `passed`: `TINYINT(1) NOT NULL DEFAULT 0`
- `passed_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY decision_code (decision_code)`
- `KEY meeting_id (meeting_id)`
- `KEY agenda_point_hash (agenda_point_hash)`
- `KEY outcome (outcome)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_decision_templates` (`metis_board_decision_templates`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `template_code`: `VARCHAR(16) DEFAULT NULL`
- `title`: `VARCHAR(191) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `default_outcome`: `VARCHAR(32) NOT NULL DEFAULT 'pending'`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY template_code (template_code)`
- `KEY sort_order (sort_order)`
- `KEY is_active (is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_compliance` (`metis_board_compliance`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `item_code`: `VARCHAR(16) DEFAULT NULL`
- `person_id`: `BIGINT UNSIGNED NOT NULL`
- `item_type`: `VARCHAR(40) NOT NULL DEFAULT 'policy_ack'`
- `title`: `VARCHAR(191) NOT NULL`
- `description`: `LONGTEXT DEFAULT NULL`
- `due_date`: `DATE DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'pending'`
- `acknowledged_at`: `DATETIME DEFAULT NULL`
- `disclosure_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY item_code (item_code)`
- `KEY person_id (person_id)`
- `KEY item_type (item_type)`
- `KEY status (status)`
- `KEY due_date (due_date)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_committees` (`metis_board_committees`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `committee_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(191) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `chair_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY committee_code (committee_code)`
- `KEY name (name)`
- `KEY chair_person_id (chair_person_id)`
- `KEY is_active (is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_attendance` (`metis_board_attendance`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `meeting_id`: `BIGINT UNSIGNED NOT NULL`
- `person_id`: `BIGINT UNSIGNED NOT NULL`
- `role_label`: `VARCHAR(64) DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'present'`
- `checkin_at`: `DATETIME DEFAULT NULL`
- `notes`: `TEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY meeting_person (meeting_id, person_id)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_announcements` (`metis_board_announcements`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `announcement_code`: `VARCHAR(16) DEFAULT NULL`
- `title`: `VARCHAR(191) NOT NULL`
- `body_html`: `LONGTEXT DEFAULT NULL`
- `audience_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `publish_at`: `DATETIME DEFAULT NULL`
- `published_by_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY announcement_code (announcement_code)`
- `KEY status (status)`
- `KEY publish_at (publish_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_agenda_templates` (`metis_board_agenda_templates`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `template_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(191) NOT NULL`
- `description`: `TEXT DEFAULT NULL`
- `default_items_json`: `LONGTEXT DEFAULT NULL`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `is_required`: `TINYINT(1) NOT NULL DEFAULT 0`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY template_code (template_code)`
- `KEY sort_order (sort_order)`
- `KEY is_active (is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `board_action_items` (`metis_board_action_items`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `action_code`: `VARCHAR(16) DEFAULT NULL`
- `meeting_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `decision_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `title`: `VARCHAR(191) NOT NULL`
- `description`: `LONGTEXT DEFAULT NULL`
- `owner_person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `due_date`: `DATE DEFAULT NULL`
- `priority`: `VARCHAR(16) NOT NULL DEFAULT 'normal'`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'open'`
- `completed_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY action_code (action_code)`
- `KEY meeting_id (meeting_id)`
- `KEY owner_person_id (owner_person_id)`
- `KEY status (status)`
- `KEY due_date (due_date)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `auth_users` (`metis_auth_users`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `person_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `user_login`: `VARCHAR(120)    NOT NULL`
- `user_email`: `VARCHAR(191)    NOT NULL`
- `password_hash`: `VARCHAR(255)    NOT NULL`
- `display_name`: `VARCHAR(191)    NOT NULL`
- `first_name`: `VARCHAR(120)    DEFAULT ''`
- `last_name`: `VARCHAR(120)    DEFAULT ''`
- `roles_json`: `LONGTEXT        DEFAULT NULL`
- `is_active`: `TINYINT(1)      DEFAULT 1`
- `last_login_at`: `DATETIME        DEFAULT NULL`
- `created_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY user_login (user_login)`
- `UNIQUE KEY user_email (user_email)`
- `KEY person_id (person_id)`
- `KEY is_active (is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.
