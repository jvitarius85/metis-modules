# Database Schema

This section is generated from `src/Metis/Core/DatabaseRuntime.php`, module schema managers, and table registry definitions.

## `website_web_parts` (`metis_website_web_parts`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `part_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `part_type`: `VARCHAR(64) NOT NULL DEFAULT 'custom'`
- `render_mode`: `VARCHAR(24) NOT NULL DEFAULT 'blocks'`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `content_json`: `LONGTEXT DEFAULT NULL`
- `config_json`: `TEXT DEFAULT NULL`
- `visibility_json`: `TEXT DEFAULT NULL`
- `target_scope`: `VARCHAR(24) NOT NULL DEFAULT 'site'`
- `target_ref`: `VARCHAR(128) DEFAULT NULL`
- `region`: `VARCHAR(24) NOT NULL DEFAULT 'main'`
- `slot`: `VARCHAR(24) NOT NULL DEFAULT 'append'`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY part_code (part_code)`
- `KEY target_lookup (status, target_scope, target_ref)`
- `KEY placement_lookup (region, slot, sort_order)`
- `KEY type_lookup (part_type, status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_theme_config` (`metis_website_theme_config`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `global_styles_json`: `TEXT DEFAULT NULL`
- `typography_json`: `TEXT DEFAULT NULL`
- `color_palette_json`: `TEXT DEFAULT NULL`
- `spacing_json`: `TEXT DEFAULT NULL`
- `custom_tokens_json`: `TEXT DEFAULT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY is_active (is_active)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_templates` (`metis_website_templates`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `template_key`: `VARCHAR(64) NOT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `template_type`: `VARCHAR(24) NOT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'published'`
- `structure_json`: `LONGTEXT DEFAULT NULL`
- `is_default`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY template_key (template_key)`
- `KEY type_status_default (template_type, status, is_default)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_revisions` (`metis_website_revisions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `entity_type`: `VARCHAR(32) NOT NULL`
- `entity_id`: `BIGINT UNSIGNED NOT NULL`
- `revision_data`: `LONGTEXT NOT NULL`
- `revision_note`: `TEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY entity_lookup (entity_type, entity_id)`
- `KEY created_at (created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_redirects` (`metis_website_redirects`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `source_path`: `VARCHAR(512) NOT NULL`
- `destination_path`: `VARCHAR(512) NOT NULL`
- `redirect_type`: `VARCHAR(3) NOT NULL DEFAULT '301'`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `notes`: `TEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY source_path (source_path)`
- `KEY active_type (is_active, redirect_type)`
- `KEY updated_at (updated_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_posts` (`metis_website_posts`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `post_code`: `VARCHAR(16) DEFAULT NULL`
- `title`: `VARCHAR(255) NOT NULL`
- `slug`: `VARCHAR(255) NOT NULL`
- `excerpt`: `TEXT DEFAULT NULL`
- `content_json`: `LONGTEXT DEFAULT NULL`
- `draft_content_json`: `LONGTEXT DEFAULT NULL`
- `published_content_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `publish_date`: `DATETIME DEFAULT NULL`
- `seo_meta_json`: `TEXT DEFAULT NULL`
- `page_type`: `VARCHAR(24) NOT NULL DEFAULT 'post'`
- `content_format`: `VARCHAR(24) NOT NULL DEFAULT 'standard'`
- `template_key`: `VARCHAR(64) DEFAULT NULL`
- `post_category_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `parent_page_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `author_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `featured_image_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `featured_image_caption`: `TEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY post_code (post_code)`
- `UNIQUE KEY slug (slug)`
- `KEY status (status)`
- `KEY page_type (page_type)`
- `KEY publish_date (publish_date)`
- `KEY template_key (template_key)`
- `KEY post_category_id (post_category_id)`
- `KEY parent_page_id (parent_page_id)`
- `KEY author_id (author_id)`
- `KEY created_by (created_by)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_post_category_map` (`metis_website_post_category_map`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `post_id`: `BIGINT UNSIGNED NOT NULL`
- `category_id`: `BIGINT UNSIGNED NOT NULL`
- `is_primary`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY post_category_unique (post_id, category_id)`
- `KEY post_id (post_id)`
- `KEY category_id (category_id)`
- `KEY primary_lookup (post_id, is_primary)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_post_categories` (`metis_website_post_categories`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `category_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(191) NOT NULL`
- `slug`: `VARCHAR(191) NOT NULL`
- `parent_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'active'`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY category_code (category_code)`
- `UNIQUE KEY slug (slug)`
- `KEY parent_id (parent_id)`
- `KEY status (status)`
- `KEY sort_order (sort_order)`
- `KEY name (name)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_popups` (`metis_website_popups`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `popup_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `trigger_type`: `VARCHAR(32) NOT NULL DEFAULT 'click'`
- `trigger_config_json`: `TEXT DEFAULT NULL`
- `layout_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `display_rules_json`: `TEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY popup_code (popup_code)`
- `KEY status (status)`
- `KEY trigger_type (trigger_type)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_pages` (`metis_website_pages`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `page_code`: `VARCHAR(16) DEFAULT NULL`
- `title`: `VARCHAR(255) NOT NULL`
- `slug`: `VARCHAR(255) NOT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `layout_json`: `LONGTEXT DEFAULT NULL`
- `draft_layout_json`: `LONGTEXT DEFAULT NULL`
- `published_layout_json`: `LONGTEXT DEFAULT NULL`
- `seo_meta_json`: `TEXT DEFAULT NULL`
- `page_type`: `VARCHAR(24) NOT NULL DEFAULT 'page'`
- `template_key`: `VARCHAR(64) DEFAULT NULL`
- `parent_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `menu_order`: `INT NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `published_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY page_code (page_code)`
- `UNIQUE KEY slug (slug)`
- `KEY status (status)`
- `KEY page_type (page_type)`
- `KEY parent_id (parent_id)`
- `KEY menu_order (menu_order)`
- `KEY created_by (created_by)`
- `KEY published_at (published_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_menus` (`metis_website_menus`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `menu_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `location`: `VARCHAR(64) DEFAULT NULL`
- `items_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'active'`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY menu_code (menu_code)`
- `KEY location (location)`
- `KEY status (status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_global_layouts` (`metis_website_global_layouts`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `layout_code`: `VARCHAR(16) DEFAULT NULL`
- `type`: `VARCHAR(24) NOT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `layout_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `is_default`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY layout_code (layout_code)`
- `KEY type (type)`
- `KEY status (status)`
- `KEY is_default (is_default)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_blocks` (`metis_website_blocks`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `block_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `type`: `VARCHAR(64) NOT NULL`
- `block_json`: `LONGTEXT DEFAULT NULL`
- `category`: `VARCHAR(64) DEFAULT NULL`
- `is_global`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY block_code (block_code)`
- `KEY type (type)`
- `KEY category (category)`
- `KEY is_global (is_global)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `website_banners` (`metis_website_banners`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `banner_code`: `VARCHAR(16) DEFAULT NULL`
- `name`: `VARCHAR(255) NOT NULL`
- `type`: `VARCHAR(32) NOT NULL DEFAULT 'top_banner'`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'draft'`
- `content_json`: `LONGTEXT DEFAULT NULL`
- `targeting_json`: `TEXT DEFAULT NULL`
- `dismiss_mode`: `VARCHAR(24) NOT NULL DEFAULT 'session'`
- `start_at`: `DATETIME DEFAULT NULL`
- `end_at`: `DATETIME DEFAULT NULL`
- `timezone`: `VARCHAR(64) NOT NULL DEFAULT 'UTC'`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY banner_code (banner_code)`
- `KEY status (status)`
- `KEY type (type)`
- `KEY start_at (start_at)`
- `KEY end_at (end_at)`
- `KEY sort_order (sort_order)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `webhook_events` (`metis_webhook_events`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `provider`: `VARCHAR(64) NOT NULL`
- `event_id`: `VARCHAR(191) NOT NULL`
- `event_type`: `VARCHAR(191) DEFAULT NULL`
- `resource_id`: `VARCHAR(191) DEFAULT NULL`
- `request_id`: `VARCHAR(64) DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'received'`
- `attempts`: `INT UNSIGNED NOT NULL DEFAULT 1`
- `signature_status`: `VARCHAR(24) NOT NULL DEFAULT 'verified'`
- `payload_json`: `LONGTEXT DEFAULT NULL`
- `headers_json`: `LONGTEXT DEFAULT NULL`
- `last_error`: `TEXT DEFAULT NULL`
- `processed_at`: `DATETIME DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY provider_event_id (provider, event_id)`
- `KEY provider (provider)`
- `KEY event_type (event_type)`
- `KEY status (status)`
- `KEY resource_id (resource_id)`
- `KEY processed_at (processed_at)`
- `KEY created_at (created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `transaction_refunds` (`metis_transaction_refunds`)

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

## `people_role_perms` (`metis_people_role_perms`)

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

## `people_positions` (`metis_people_positions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `group_key`: `VARCHAR(24) NOT NULL`
- `position_key`: `VARCHAR(64) NOT NULL`
- `position_label`: `VARCHAR(120) NOT NULL`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY group_position (group_key, position_key)`
- `KEY group_active_sort (group_key, is_active, sort_order, position_label)`

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
- `board_position`: `VARCHAR(120) DEFAULT NULL`
- `staff_position`: `VARCHAR(120) DEFAULT NULL`
- `is_volunteer`: `TINYINT(1) NOT NULL DEFAULT 0`
- `volunteer_position`: `VARCHAR(120) DEFAULT NULL`
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
- `password_hash`: `VARCHAR(255) DEFAULT NULL`
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
- `KEY board_position (board_position)`
- `KEY staff_position (staff_position)`
- `KEY volunteer_position (volunteer_position)`
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

## `newsletter_suppressions` (`metis_newsletter_suppressions`)

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

## `newsletter_subs` (`metis_newsletter_subs`)

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
- `editor_body_html`: `LONGTEXT DEFAULT NULL`
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

## `newsletter_campaign_lists` (`metis_newsletter_campaign_lists`)

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

## `navigation_items` (`metis_navigation_items`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `label`: `VARCHAR(191) NOT NULL`
- `route`: `VARCHAR(255) NOT NULL DEFAULT ''`
- `icon`: `TEXT NULL`
- `parent_id`: `BIGINT UNSIGNED NULL`
- `position`: `INT NOT NULL DEFAULT 0`
- `is_visible`: `TINYINT(1) NOT NULL DEFAULT 1`
- `permissions_required`: `VARCHAR(191) NOT NULL DEFAULT ''`
- `module_key`: `VARCHAR(191) NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY uniq_module_key (module_key)`
- `KEY idx_parent_position (parent_id, position)`
- `KEY idx_visible (is_visible)`
- `KEY idx_module_key (module_key)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `media_files` (`metis_media_files`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `public_token`: `VARCHAR(64) NOT NULL`
- `storage_path`: `VARCHAR(512) NOT NULL`
- `file_name`: `VARCHAR(255) NOT NULL`
- `mime_type`: `VARCHAR(191) NOT NULL`
- `size`: `BIGINT UNSIGNED NOT NULL DEFAULT 0`
- `uploaded_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY public_token (public_token)`
- `KEY created_at (created_at)`
- `KEY mime_type (mime_type)`
- `KEY uploaded_by (uploaded_by)`

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

## `id_sequences` (`metis_id_sequences`)

### Fields

- `entity_type`: `VARCHAR(64) PRIMARY KEY`
- `next_value`: `INT NOT NULL DEFAULT 1`

### Indexes

- No explicit secondary indexes discovered.

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `hermes_sessions` (`metis_hermes_sessions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `session_code`: `VARCHAR(32) NOT NULL`
- `user_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `title`: `VARCHAR(191) DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'open'`
- `last_intent`: `VARCHAR(64) DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY session_code (session_code)`
- `KEY user_updated (user_id, updated_at)`
- `KEY status_updated (status, updated_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `hermes_reports` (`metis_hermes_reports`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `report_code`: `VARCHAR(32) NOT NULL`
- `session_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `report_type`: `VARCHAR(64) NOT NULL`
- `subject_key`: `VARCHAR(100) DEFAULT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'ready'`
- `summary_json`: `LONGTEXT NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY report_code (report_code)`
- `KEY type_subject (report_type, subject_key)`
- `KEY status_updated (status, updated_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `hermes_messages` (`metis_hermes_messages`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `session_id`: `BIGINT UNSIGNED NOT NULL`
- `role_name`: `VARCHAR(24) NOT NULL`
- `message_hash`: `VARCHAR(64) DEFAULT NULL`
- `content`: `LONGTEXT NOT NULL`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY session_created (session_id, created_at)`
- `KEY role_created (role_name, created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `hermes_memory` (`metis_hermes_memory`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `memory_key`: `VARCHAR(120) NOT NULL`
- `memory_type`: `VARCHAR(64) NOT NULL`
- `scope_key`: `VARCHAR(120) DEFAULT NULL`
- `contents_json`: `LONGTEXT NOT NULL`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY memory_key (memory_key)`
- `KEY type_scope (memory_type, scope_key)`
- `KEY updated_at (updated_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `hermes_actions` (`metis_hermes_actions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `session_id`: `BIGINT UNSIGNED NOT NULL`
- `message_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `action_code`: `VARCHAR(32) NOT NULL`
- `action_type`: `VARCHAR(64) NOT NULL`
- `title`: `VARCHAR(191) NOT NULL`
- `approval_status`: `VARCHAR(24) NOT NULL DEFAULT 'pending'`
- `payload_json`: `LONGTEXT NOT NULL`
- `preview_json`: `LONGTEXT DEFAULT NULL`
- `approved_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `approval_note`: `TEXT DEFAULT NULL`
- `executed_at`: `DATETIME DEFAULT NULL`
- `result_json`: `LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY action_code (action_code)`
- `KEY session_status (session_id, approval_status, created_at)`
- `KEY status_created (approval_status, created_at)`

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

## `finance_v2_stripe_payouts` (`metis_finance_v2_stripe_payouts`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `payout_id`: `VARCHAR(128) NOT NULL`
- `payout_date`: `DATE NOT NULL`
- `expected_deposit_amount`: `DECIMAL(14,2) NOT NULL`
- `currency`: `VARCHAR(12) NOT NULL DEFAULT 'usd'`
- `bank_account_label`: `VARCHAR(191) DEFAULT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'expected'`
- `matched_bank_line_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `matched_at`: `DATETIME DEFAULT NULL`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_payout_id (org_id, payout_id)`
- `KEY org_status_date (org_id, status, payout_date)`
- `KEY org_matched_line (org_id, matched_bank_line_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_stripe_clearing_events` (`metis_finance_v2_stripe_clearing_events`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `event_type`: `VARCHAR(32) NOT NULL`
- `event_date`: `DATE NOT NULL`
- `reference_id`: `VARCHAR(128) DEFAULT NULL`
- `amount_signed`: `DECIMAL(14,2) NOT NULL`
- `currency`: `VARCHAR(12) NOT NULL DEFAULT 'usd'`
- `description`: `VARCHAR(255) DEFAULT NULL`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_date_type (org_id, event_date, event_type)`
- `KEY org_ref (org_id, reference_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_report_requests` (`metis_finance_v2_report_requests`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `report_type`: `VARCHAR(64) NOT NULL`
- `period_code`: `VARCHAR(16) NOT NULL`
- `orientation`: `VARCHAR(16) NOT NULL DEFAULT 'landscape'`
- `include_prev_month`: `TINYINT(1) NOT NULL DEFAULT 0`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'completed'`
- `payload_json`: `LONGTEXT DEFAULT NULL`
- `generated_at`: `DATETIME DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_report_created (org_id, report_type, created_at)`
- `KEY org_period_created (org_id, period_code, created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_recon_review_queue` (`metis_finance_v2_recon_review_queue`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `recon_parse_run_id`: `BIGINT UNSIGNED NOT NULL`
- `confidence_score`: `DECIMAL(5,2) NOT NULL DEFAULT 0`
- `confidence_band`: `VARCHAR(16) NOT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'pending_confirmation'`
- `decision`: `VARCHAR(32) DEFAULT NULL`
- `decision_notes`: `VARCHAR(255) DEFAULT NULL`
- `decided_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `decided_at`: `DATETIME DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_status_created (org_id, status, created_at)`
- `KEY org_run (org_id, recon_parse_run_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_recon_parse_runs` (`metis_finance_v2_recon_parse_runs`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `import_type`: `VARCHAR(16) NOT NULL`
- `file_name`: `VARCHAR(191) NOT NULL`
- `confidence_score`: `DECIMAL(5,2) NOT NULL DEFAULT 0`
- `confidence_band`: `VARCHAR(16) NOT NULL`
- `mapping_json`: `LONGTEXT DEFAULT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'queued'`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_created (org_id, created_at)`
- `KEY org_band_status (org_id, confidence_band, status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_recon_months` (`metis_finance_v2_recon_months`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `recon_month`: `DATE NOT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'open'`
- `starting_balance`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `statement_ending_balance`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `expected_ending_balance`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `difference_amount`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `statement_file_name`: `VARCHAR(191) DEFAULT NULL`
- `statement_media_token`: `VARCHAR(128) DEFAULT NULL`
- `statement_media_url`: `VARCHAR(255) DEFAULT NULL`
- `finalized_at`: `DATETIME DEFAULT NULL`
- `finalized_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_month_unique (org_id, recon_month)`
- `KEY org_status_month (org_id, status, recon_month)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_recon_month_items` (`metis_finance_v2_recon_month_items`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `recon_month_id`: `BIGINT UNSIGNED NOT NULL`
- `gl_entry_id`: `BIGINT UNSIGNED NOT NULL`
- `is_cleared`: `TINYINT(1) NOT NULL DEFAULT 0`
- `cleared_at`: `DATETIME DEFAULT NULL`
- `cleared_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_month_entry_unique (org_id, recon_month_id, gl_entry_id)`
- `KEY org_month_cleared (org_id, recon_month_id, is_cleared)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_recon_month_audit` (`metis_finance_v2_recon_month_audit`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `recon_month_id`: `BIGINT UNSIGNED NOT NULL`
- `event_type`: `VARCHAR(32) NOT NULL`
- `reason_text`: `VARCHAR(255) DEFAULT NULL`
- `actor_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_month_event (org_id, recon_month_id, event_type, created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_recon_matches` (`metis_finance_v2_recon_matches`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `match_type`: `VARCHAR(32) NOT NULL`
- `payout_record_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `bank_line_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `gl_entry_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `match_amount`: `DECIMAL(14,2) NOT NULL`
- `confidence_score`: `DECIMAL(5,2) NOT NULL DEFAULT 100`
- `notes`: `VARCHAR(255) DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_type_created (org_id, match_type, created_at)`
- `KEY org_payout (org_id, payout_record_id)`
- `KEY org_bank_line (org_id, bank_line_id)`
- `KEY org_gl_entry (org_id, gl_entry_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_recon_column_mappings` (`metis_finance_v2_recon_column_mappings`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `import_type`: `VARCHAR(16) NOT NULL`
- `mapping_name`: `VARCHAR(128) NOT NULL`
- `mapping_json`: `LONGTEXT NOT NULL`
- `is_default`: `TINYINT(1) NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_mapping_name (org_id, import_type, mapping_name)`
- `KEY org_import_default (org_id, import_type, is_default)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_org_mode` (`metis_finance_v2_org_mode`)

### Fields

- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `current_mode`: `VARCHAR(32) NOT NULL DEFAULT 'finance'`
- `effective_at`: `DATETIME DEFAULT NULL`
- `switched_at`: `DATETIME DEFAULT NULL`
- `switch_status`: `VARCHAR(32) NOT NULL DEFAULT 'idle'`
- `switch_job_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (org_id)`
- `KEY current_mode (current_mode)`
- `KEY effective_at (effective_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_mode_switch_jobs` (`metis_finance_v2_mode_switch_jobs`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `target_mode`: `VARCHAR(32) NOT NULL`
- `effective_at`: `DATETIME NOT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'queued'`
- `preflight_result_json`: `LONGTEXT DEFAULT NULL`
- `rollback_result_json`: `LONGTEXT DEFAULT NULL`
- `queue_job_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `queue_job_code`: `VARCHAR(64) DEFAULT NULL`
- `started_at`: `DATETIME DEFAULT NULL`
- `finished_at`: `DATETIME DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_status_effective (org_id, status, effective_at)`
- `KEY queue_job_id (queue_job_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_kpi_cache` (`metis_finance_v2_kpi_cache`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `cache_key`: `VARCHAR(191) NOT NULL`
- `payload_json`: `LONGTEXT NOT NULL`
- `expires_at`: `DATETIME NOT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_cache_key (org_id, cache_key)`
- `KEY org_expiry (org_id, expires_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_invoices` (`metis_finance_v2_invoices`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `invoice_number`: `VARCHAR(64) NOT NULL`
- `customer_name`: `VARCHAR(191) NOT NULL`
- `customer_email`: `VARCHAR(191) NOT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'draft'`
- `currency`: `VARCHAR(12) NOT NULL DEFAULT 'usd'`
- `issued_date`: `DATE NOT NULL`
- `due_date`: `DATE NOT NULL`
- `paid_date`: `DATE DEFAULT NULL`
- `subtotal_amount`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `total_amount`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `stripe_payment_intent_id`: `VARCHAR(128) DEFAULT NULL`
- `sent_at`: `DATETIME DEFAULT NULL`
- `notes`: `VARCHAR(255) DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_invoice_number (org_id, invoice_number)`
- `KEY org_status_due (org_id, status, due_date)`
- `KEY org_issued (org_id, issued_date)`
- `KEY org_customer_email (org_id, customer_email)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_invoice_lines` (`metis_finance_v2_invoice_lines`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `invoice_id`: `BIGINT UNSIGNED NOT NULL`
- `description`: `VARCHAR(255) NOT NULL`
- `quantity`: `DECIMAL(10,2) NOT NULL DEFAULT 1`
- `unit_amount`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `line_total`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `sort_order`: `SMALLINT UNSIGNED NOT NULL DEFAULT 0`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_invoice_sort (org_id, invoice_id, sort_order)`
- `KEY org_invoice (org_id, invoice_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_gl_entries` (`metis_finance_v2_gl_entries`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `entry_date`: `DATE NOT NULL`
- `account_code`: `VARCHAR(64) NOT NULL`
- `description`: `VARCHAR(255) NOT NULL`
- `amount_signed`: `DECIMAL(14,2) NOT NULL`
- `amount_abs`: `DECIMAL(14,2) NOT NULL`
- `dc_type`: `VARCHAR(16) NOT NULL`
- `category_code`: `VARCHAR(64) DEFAULT NULL`
- `source_type`: `VARCHAR(32) NOT NULL DEFAULT 'manual'`
- `reconciliation_status`: `VARCHAR(32) NOT NULL DEFAULT 'unmatched'`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_entry_date_id (org_id, entry_date, id)`
- `KEY org_account_date (org_id, account_code, entry_date)`
- `KEY org_recon_status (org_id, reconciliation_status, entry_date)`
- `KEY org_source_date (org_id, source_type, entry_date)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_fiscal_settings` (`metis_finance_v2_fiscal_settings`)

### Fields

- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `fiscal_year_start_month`: `TINYINT UNSIGNED NOT NULL DEFAULT 1`
- `timezone`: `VARCHAR(64) NOT NULL DEFAULT 'UTC'`
- `active_period_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `updated_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (org_id)`
- `KEY fiscal_year_start_month (fiscal_year_start_month)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_fiscal_periods` (`metis_finance_v2_fiscal_periods`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `label`: `VARCHAR(128) NOT NULL`
- `start_date`: `DATE NOT NULL`
- `end_date`: `DATE NOT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'active'`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_period_unique (org_id, start_date, end_date)`
- `KEY org_status (org_id, status)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_categories` (`metis_finance_v2_categories`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `category_code`: `VARCHAR(64) NOT NULL`
- `category_name`: `VARCHAR(191) NOT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_category_code (org_id, category_code)`
- `KEY org_active_sort (org_id, is_active, sort_order)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_budget_versions` (`metis_finance_v2_budget_versions`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `version_label`: `VARCHAR(128) NOT NULL`
- `fiscal_year`: `SMALLINT UNSIGNED NOT NULL`
- `period_start`: `DATE NOT NULL`
- `period_end`: `DATE NOT NULL`
- `is_locked`: `TINYINT(1) NOT NULL DEFAULT 0`
- `source_version_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_year_period (org_id, fiscal_year, period_start)`
- `KEY org_locked_created (org_id, is_locked, created_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_budget_lines` (`metis_finance_v2_budget_lines`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `budget_version_id`: `BIGINT UNSIGNED NOT NULL`
- `account_code`: `VARCHAR(64) NOT NULL`
- `planned_amount`: `DECIMAL(14,2) NOT NULL DEFAULT 0`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_version_account (org_id, budget_version_id, account_code)`
- `KEY org_version (org_id, budget_version_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_bank_lines` (`metis_finance_v2_bank_lines`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `recon_parse_run_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `source_type`: `VARCHAR(32) NOT NULL DEFAULT 'manual'`
- `line_date`: `DATE NOT NULL`
- `description`: `VARCHAR(255) NOT NULL`
- `amount_signed`: `DECIMAL(14,2) NOT NULL`
- `status`: `VARCHAR(32) NOT NULL DEFAULT 'unmatched'`
- `matched_payout_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `matched_gl_entry_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `created_by`: `BIGINT UNSIGNED DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY org_status_date (org_id, status, line_date)`
- `KEY org_amount_date (org_id, amount_signed, line_date)`
- `KEY org_recon_run (org_id, recon_parse_run_id)`
- `KEY org_gl_match (org_id, matched_gl_entry_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `finance_v2_accounts` (`metis_finance_v2_accounts`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `org_id`: `BIGINT UNSIGNED NOT NULL`
- `account_code`: `VARCHAR(64) NOT NULL`
- `account_name`: `VARCHAR(191) NOT NULL`
- `account_type`: `VARCHAR(32) NOT NULL`
- `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
- `sort_order`: `INT NOT NULL DEFAULT 0`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY org_account_code (org_id, account_code)`
- `KEY org_type_active (org_id, account_type, is_active, sort_order)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `entity_registry` (`metis_entity_registry`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `entity_uid`: `VARCHAR(16) NOT NULL`
- `entity_type`: `VARCHAR(64) NOT NULL`
- `entity_table`: `VARCHAR(64) NOT NULL`
- `entity_id`: `BIGINT UNSIGNED NOT NULL`
- `module_slug`: `VARCHAR(64) DEFAULT NULL`
- `created_at`: `DATETIME DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY entity_uid (entity_uid)`
- `KEY idx_entity_uid (entity_uid)`
- `KEY idx_entity_lookup (entity_table, entity_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `entity_prefixes` (`metis_entity_prefixes`)

### Fields

- `entity_type`: `VARCHAR(64) PRIMARY KEY`
- `prefix`: `VARCHAR(8) NOT NULL`
- `description`: `VARCHAR(255) DEFAULT NULL`
- `created_at`: `DATETIME DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `UNIQUE KEY prefix (prefix)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_user_folders` (`metis_drive_user_folders`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL, person_id BIGINT UNSIGNED DEFAULT NULL`
- `folder_id`: `VARCHAR(191) NOT NULL, folder_name VARCHAR(255) DEFAULT NULL`
- `parent_folder_id`: `VARCHAR(191) DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id), UNIQUE KEY drive_person (drive_id, person_id)`
- `KEY person_id (person_id), KEY folder_id (folder_id)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_sync_state` (`metis_drive_sync_state`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL, folder_id VARCHAR(191) NOT NULL`
- `parent_folder_id`: `VARCHAR(191) DEFAULT NULL, folder_name VARCHAR(255) DEFAULT NULL`
- `last_synced_at`: `DATETIME DEFAULT NULL, last_requested_at DATETIME DEFAULT NULL`
- `sync_status`: `VARCHAR(32) NOT NULL DEFAULT 'idle'`
- `sync_depth`: `SMALLINT UNSIGNED NOT NULL DEFAULT 0`
- `item_count`: `INT UNSIGNED NOT NULL DEFAULT 0, last_error TEXT DEFAULT NULL`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id), UNIQUE KEY drive_folder (drive_id, folder_id)`
- `KEY sync_status (sync_status), KEY last_synced_at (last_synced_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_items` (`metis_drive_items`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL, item_id VARCHAR(191) NOT NULL`
- `parent_id`: `VARCHAR(191) NOT NULL, item_name VARCHAR(255) NOT NULL`
- `mime_type`: `VARCHAR(191) NOT NULL, is_folder TINYINT(1) NOT NULL DEFAULT 0`
- `modified_time`: `DATETIME DEFAULT NULL, size_bytes BIGINT UNSIGNED DEFAULT NULL`
- `web_view_link`: `TEXT DEFAULT NULL, raw_json LONGTEXT DEFAULT NULL`
- `synced_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id), UNIQUE KEY drive_item (drive_id, item_id)`
- `KEY drive_parent (drive_id, parent_id)`
- `KEY drive_parent_folder (drive_id, parent_id, is_folder), KEY item_name (item_name)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `drive_audit` (`metis_drive_audit`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `drive_id`: `VARCHAR(191) NOT NULL, folder_id VARCHAR(191) DEFAULT NULL`
- `file_id`: `VARCHAR(191) DEFAULT NULL, item_name VARCHAR(255) DEFAULT NULL`
- `item_type`: `VARCHAR(64) DEFAULT NULL, action_key VARCHAR(64) NOT NULL`
- `actor_person_id`: `BIGINT UNSIGNED DEFAULT NULL, details_json LONGTEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id), KEY drive_id (drive_id), KEY folder_id (folder_id)`
- `KEY file_id (file_id), KEY action_key (action_key), KEY actor_person_id (actor_person_id)`

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
- `google_calendar_event_name`: `VARCHAR(191) DEFAULT NULL`
- `google_calendar_html_link`: `VARCHAR(255) DEFAULT NULL`
- `google_drive_folder_id`: `VARCHAR(191) DEFAULT NULL`
- `google_drive_folder_name`: `VARCHAR(191) DEFAULT NULL`
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
- `decision_votes_json`: `LONGTEXT DEFAULT NULL`
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

## `backup_runs` (`metis_backup_runs`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `run_uuid`: `VARCHAR(64) NOT NULL`
- `environment`: `VARCHAR(64) NOT NULL`
- `status`: `VARCHAR(24) NOT NULL DEFAULT 'running'`
- `trigger_source`: `VARCHAR(64) NOT NULL DEFAULT 'manual'`
- `version`: `VARCHAR(32) DEFAULT NULL`
- `started_at`: `DATETIME NOT NULL`
- `completed_at`: `DATETIME DEFAULT NULL`
- `drive_id`: `VARCHAR(191) DEFAULT NULL`
- `drive_run_folder_id`: `VARCHAR(191) DEFAULT NULL`
- `local_path`: `TEXT DEFAULT NULL`
- `metadata_json`: `LONGTEXT DEFAULT NULL`
- `components_json`: `LONGTEXT DEFAULT NULL`
- `restore_json`: `LONGTEXT DEFAULT NULL`
- `last_error`: `TEXT DEFAULT NULL`
- `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `UNIQUE KEY run_uuid (run_uuid)`
- `KEY environment_status (environment, status)`
- `KEY completed_at (completed_at)`

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

## `audit_security` (`metis_audit_security`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `request_id`: `VARCHAR(64) DEFAULT NULL`
- `user_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `action_type`: `VARCHAR(100) NOT NULL`
- `severity`: `VARCHAR(24) NOT NULL DEFAULT 'warning'`
- `outcome`: `VARCHAR(24) NOT NULL DEFAULT 'blocked'`
- `module_slug`: `VARCHAR(64) DEFAULT NULL`
- `resource_type`: `VARCHAR(64) DEFAULT NULL`
- `resource_id`: `VARCHAR(191) DEFAULT NULL`
- `resource_label`: `VARCHAR(255) DEFAULT NULL`
- `ip_address`: `VARCHAR(64) DEFAULT NULL`
- `user_agent`: `VARCHAR(512) DEFAULT NULL`
- `context_json`: `LONGTEXT DEFAULT NULL`
- `occurred_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY user_id (user_id)`
- `KEY action_type (action_type)`
- `KEY severity (severity)`
- `KEY module_slug (module_slug)`
- `KEY resource_type (resource_type)`
- `KEY resource_id (resource_id)`
- `KEY occurred_at (occurred_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

## `audit_activity` (`metis_audit_activity`)

### Fields

- `id`: `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`
- `request_id`: `VARCHAR(64) DEFAULT NULL`
- `user_id`: `BIGINT UNSIGNED DEFAULT NULL`
- `action_type`: `VARCHAR(100) NOT NULL`
- `module_slug`: `VARCHAR(64) DEFAULT NULL`
- `resource_type`: `VARCHAR(64) DEFAULT NULL`
- `resource_id`: `VARCHAR(191) DEFAULT NULL`
- `resource_label`: `VARCHAR(255) DEFAULT NULL`
- `ip_address`: `VARCHAR(64) DEFAULT NULL`
- `user_agent`: `VARCHAR(512) DEFAULT NULL`
- `context_json`: `LONGTEXT DEFAULT NULL`
- `occurred_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Indexes

- `PRIMARY KEY (id)`
- `KEY user_id (user_id)`
- `KEY action_type (action_type)`
- `KEY module_slug (module_slug)`
- `KEY resource_type (resource_type)`
- `KEY resource_id (resource_id)`
- `KEY occurred_at (occurred_at)`

### Data Lifecycle

- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.
- Indexes should be preserved when adding filters, joins, or pagination flows against this table.

