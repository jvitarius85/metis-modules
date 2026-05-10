<?php
declare(strict_types=1);

if ( class_exists( "Metis_Tables", false ) ) {
    return;
}

final class Metis_Tables {
    private static bool $initialized = false;
    private static string $prefix = "metis_";

    /** @var array<string,string> */
    private static array $tables = [
        "settings" => "metis_settings",
        "auth_users" => "metis_auth_users",
        "backup_runs" => "metis_backup_runs",
        "recovery_events" => "metis_recovery_events",
        "recovery_actions" => "metis_recovery_actions",
        "recovery_backups" => "metis_recovery_backups",
        "recovery_integrity_manifest" => "metis_recovery_integrity_manifest",
        "recovery_locks" => "metis_recovery_locks",
        "people" => "metis_people",
        "people_permissions" => "metis_people_permissions",
        "people_roles" => "metis_people_roles",
        "people_role_perms" => "metis_people_role_perms",
        "people_role_templates" => "metis_people_role_templates",
        "people_template_roles" => "metis_people_template_roles",
        "people_user_roles" => "metis_people_user_roles",
        "people_passkeys" => "metis_people_passkeys",
        "people_auth_challenges" => "metis_people_auth_challenges",
        "people_positions" => "metis_people_positions",
        "people_activity" => "metis_people_activity",
        "people_access_requests" => "metis_people_access_requests",
        "people_documents" => "metis_people_documents",
        "people_emergency_access" => "metis_people_emergency_access",
        "people_lifecycle_tasks" => "metis_people_lifecycle_tasks",
        "people_workspace_users" => "metis_people_workspace_users",
        "people_workspace_user_roles" => "metis_people_workspace_user_roles",
        "people_workspace_groups" => "metis_people_workspace_groups",
        "people_workspace_group_members" => "metis_people_workspace_group_members",
        "people_workspace_security_actions" => "metis_people_workspace_security_actions",
        "people_workspace_sync_jobs" => "metis_people_workspace_sync_jobs",
        "contacts" => "metis_contacts",
        "donors" => "metis_contacts",
        "contact_details" => "metis_contact_details",
        "contact_notes" => "metis_contact_notes",
        "contact_dav_tokens" => "metis_contact_dav_tokens",
        "contact_dav_sync" => "metis_contact_dav_sync",
        "newsletter_lists" => "metis_newsletter_lists",
        "newsletter_subs" => "metis_newsletter_subs",
        "newsletter_templates" => "metis_newsletter_templates",
        "newsletter_campaigns" => "metis_newsletter_campaigns",
        "newsletter_campaign_lists" => "metis_newsletter_campaign_lists",
        "newsletter_messages" => "metis_newsletter_messages",
        "newsletter_events" => "metis_newsletter_events",
        "newsletter_revisions" => "metis_newsletter_revisions",
        "newsletter_audit" => "metis_newsletter_audit",
        "newsletter_suppressions" => "metis_newsletter_suppressions",
        "newsletter_google_usage_daily" => "metis_newsletter_google_usage_daily",
        "job_queue" => "metis_job_queue",
        "sync_state" => "metis_sync_state",
        "email_usage_daily" => "metis_email_usage_daily",
        "email_send_events" => "metis_email_send_events",
        "media_files" => "metis_media_files",
        "navigation_items" => "metis_navigation_items",
        "webhook_events" => "metis_webhook_events",
        "communications_inbound_mailboxes" => "metis_communications_inbound_mailboxes",
        "communications_inbound_messages" => "metis_communications_inbound_messages",
        "communications_inbound_attachments" => "metis_communications_inbound_attachments",
        "communications_inbound_events" => "metis_communications_inbound_events",
        "communications_inbound_links" => "metis_communications_inbound_links",
        "audit_activity" => "metis_audit_activity",
        "audit_security" => "metis_audit_security",
        "entity_registry" => "metis_entity_registry",
        "entity_prefixes" => "metis_entity_prefixes",
        "id_sequences" => "metis_id_sequences",
        "forms" => "metis_forms",
        "form_versions" => "metis_form_versions",
        "form_submissions" => "metis_form_submissions",
        "transactions" => "metis_transactions",
        "transaction_notes" => "metis_transaction_notes",
        "transaction_refunds" => "metis_transaction_refunds",
        "campaigns" => "metis_campaigns",
        "reports" => "metis_reports",
        "batches" => "metis_batches",
        "batch_notes" => "metis_batch_notes",
        "batch_audit" => "metis_batch_audit",
        "deposits" => "metis_deposits",
        "finance_v2_org_mode" => "metis_finance_org_mode",
        "finance_v2_fiscal_settings" => "metis_finance_fiscal_settings",
        "finance_v2_fiscal_periods" => "metis_finance_fiscal_periods",
        "finance_v2_mode_switch_jobs" => "metis_finance_mode_switch_jobs",
        "finance_v2_gl_entries" => "metis_finance_gl_entries",
        "finance_v2_accounts" => "metis_finance_accounts",
        "finance_v2_categories" => "metis_finance_categories",
        "finance_v2_bank_statements" => "metis_finance_bank_statements",
        "finance_v2_bank_lines" => "metis_finance_bank_lines",
        "finance_v2_recon_column_mappings" => "metis_finance_recon_column_mappings",
        "finance_v2_recon_review_queue" => "metis_finance_recon_review_queue",
        "finance_v2_recon_matches" => "metis_finance_recon_matches",
        "finance_v2_recon_parse_runs" => "metis_finance_recon_parse_runs",
        "finance_v2_recon_months" => "metis_finance_recon_months",
        "finance_v2_recon_month_items" => "metis_finance_recon_month_items",
        "finance_v2_recon_month_audit" => "metis_finance_recon_month_audit",
        "finance_v2_stripe_clearing_events" => "metis_finance_stripe_clearing_events",
        "finance_v2_stripe_payouts" => "metis_finance_stripe_payouts",
        "finance_v2_budget_versions" => "metis_finance_budget_versions",
        "finance_v2_budget_lines" => "metis_finance_budget_lines",
        "finance_v2_invoices" => "metis_finance_invoices",
        "finance_v2_invoice_lines" => "metis_finance_invoice_lines",
        "finance_v2_report_requests" => "metis_finance_report_requests",
        "finance_v2_kpi_cache" => "metis_finance_kpi_cache",
        "drive_audit" => "metis_drive_audit",
        "drive_user_folders" => "metis_drive_user_folders",
        "drive_items" => "metis_drive_items",
        "drive_sync_state" => "metis_drive_sync_state",
        "calendar_events" => "metis_calendar_events",
        "calendar_sync_state" => "metis_calendar_sync_state",
        "board_committees" => "metis_board_committees",
        "board_meetings" => "metis_board_meetings",
        "board_decisions" => "metis_board_decisions",
        "board_action_items" => "metis_board_action_items",
        "board_attendance" => "metis_board_attendance",
        "board_documents" => "metis_board_documents",
        "board_compliance" => "metis_board_compliance",
        "board_announcements" => "metis_board_announcements",
        "board_agenda_templates" => "metis_board_agenda_templates",
        "board_decision_templates" => "metis_board_decision_templates",
        "website_pages" => "metis_website_pages",
        "website_posts" => "metis_website_posts",
        "website_post_categories" => "metis_website_post_categories",
        "website_post_category_map" => "metis_website_post_category_map",
        "website_global_layouts" => "metis_website_global_layouts",
        "website_menus" => "metis_website_menus",
        "website_banners" => "metis_website_banners",
        "website_popups" => "metis_website_popups",
        "website_theme_config" => "metis_website_theme_config",
        "website_templates" => "metis_website_templates",
        "website_web_parts" => "metis_website_web_parts",
        "website_blocks" => "metis_website_blocks",
        "website_revisions" => "metis_website_revisions",
        "website_redirects" => "metis_website_redirects",
        "cms_pages" => "metis_cms_pages",
        "cms_posts" => "metis_cms_posts",
        "cms_post_categories" => "metis_cms_post_categories",
        "cms_post_category_map" => "metis_cms_post_category_map",
        "cms_global_layouts" => "metis_cms_global_layouts",
        "cms_menus" => "metis_cms_menus",
        "cms_banners" => "metis_cms_banners",
        "cms_popups" => "metis_cms_popups",
        "cms_theme_config" => "metis_cms_theme_config",
        "cms_templates" => "metis_cms_templates",
        "cms_web_parts" => "metis_cms_web_parts",
        "cms_blocks" => "metis_cms_blocks",
        "cms_revisions" => "metis_cms_revisions",
        "cms_redirects" => "metis_cms_redirects",
        "help_categories" => "metis_help_categories",
        "help_articles" => "metis_help_articles",
        "help_search_index" => "metis_help_search_index",
        "hermes_sessions" => "metis_hermes_sessions",
        "hermes_messages" => "metis_hermes_messages",
        "hermes_actions" => "metis_hermes_actions",
        "hermes_reports" => "metis_hermes_reports",
        "hermes_memory" => "metis_hermes_memory",
        "hermes_command_logs" => "metis_hermes_command_logs",
        "hermes_help_issue_logs" => "metis_hermes_help_issue_logs",
        "grandys_stash_catalog" => "metis_grandys_stash_catalog",
        "grandys_stash_inventory" => "metis_grandys_stash_inventory",
        "grandys_stash_facilities" => "metis_grandys_stash_facilities",
        "grandys_stash_groups" => "metis_grandys_stash_groups",
        "grandys_stash_tickets" => "metis_grandys_stash_tickets",
        "grandys_stash_ticket_items" => "metis_grandys_stash_ticket_items",
        "grandys_stash_notes" => "metis_grandys_stash_notes",
        "grandys_stash_activity" => "metis_grandys_stash_activity",
        "grandys_stash_messages" => "metis_grandys_stash_messages",
        "grandys_stash_email_prefs" => "metis_grandys_stash_email_prefs",
    ];

    public static function init( ?string $prefix = null ): void {
        if ( $prefix !== null && $prefix !== "" ) {
            self::$prefix = $prefix;
        } elseif ( function_exists( "metis_resolve_db_service" ) ) {
            try {
                $db_prefix = metis_resolve_db_service()->prefix();
                if ( $db_prefix !== "" ) {
                    self::$prefix = $db_prefix;
                }
            } catch ( Throwable ) {
            }
        }

        foreach ( self::$tables as $key => $table ) {
            if ( str_starts_with( $table, "metis_" ) ) {
                self::$tables[$key] = self::$prefix . substr( $table, 6 );
            }
        }

        self::$initialized = true;
    }

    public static function register( string $key, string $table ): void {
        $key = self::normalizeKey( $key );
        $table = trim( $table );
        if ( $key === "" || $table === "" ) {
            return;
        }
        self::$tables[$key] = $table;
    }

    public static function has( string $key ): bool {
        $key = self::normalizeKey( $key );
        if ( $key === "" ) {
            return false;
        }
        return array_key_exists( $key, self::$tables );
    }

    public static function get( string $key ): string {
        if ( ! self::$initialized ) {
            self::init();
        }

        $key = self::normalizeKey( $key );
        if ( $key === "" ) {
            return self::$prefix;
        }

        if ( isset( self::$tables[$key] ) ) {
            return self::$tables[$key];
        }

        // Safe fallback for newly-added keys.
        return self::$prefix . $key;
    }

    /** @return array<string,string> */
    public static function all(): array {
        if ( ! self::$initialized ) {
            self::init();
        }
        return self::$tables;
    }

    /** @return array<int,string> */
    public static function definitions(): array {
        if ( ! self::$initialized ) {
            self::init();
        }
        return array_values( self::$tables );
    }

    private static function normalizeKey( string $key ): string {
        $key = strtolower( trim( $key ) );
        $key = preg_replace( "/[^a-z0-9_]+/", "_", $key ) ?? "";
        return trim( $key, "_" );
    }
}
