<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Metis Table Registry
 *
 * Single source of truth for all database table names.
 */

class Metis_Tables {

    private static array $map = [];
    private static bool $booted = false;

    private static array $definitions = [

        // --- Donations ---
        'transactions'          => 'metis_transactions',
        'transaction_notes'     => 'metis_transaction_notes',
        'transaction_refunds'   => 'metis_transaction_refunds',

        // --- Deposits & Batches ---
        'deposits'              => 'metis_deposits',
        'batches'               => 'metis_batches',
        'batch_notes'           => 'metis_batch_notes',
        'batch_audit'           => 'metis_batch_audit',

        // --- Donors ---
        'donor_details'         => 'metis_donor_details',
        'donor_notes'           => 'metis_donor_notes',
        'donor_tags'            => 'metis_donor_tags',

        // --- Campaigns ---
        'campaigns'             => 'metis_campaigns',

        // --- Contacts ---
        'contacts'              => 'metis_contacts',
        'contact_details'       => 'metis_contact_details',
        'contact_notes'         => 'metis_contact_notes',
        'contact_dav_tokens'    => 'metis_contact_dav_tokens',
        'contact_dav_sync'      => 'metis_contact_dav_sync',

        // --- People / ACL ---
        'people'                => 'metis_people',
        'people_roles'          => 'metis_people_roles',
        'people_permissions'    => 'metis_people_permissions',
        'people_role_perms'     => 'metis_people_role_permissions',
        'people_user_roles'     => 'metis_people_user_roles',
        'people_activity'       => 'metis_people_activity',
        'people_access_requests'=> 'metis_people_access_requests',
        'people_role_templates' => 'metis_people_role_templates',
        'people_template_roles' => 'metis_people_template_roles',
        'people_documents'      => 'metis_people_documents',
        'people_emergency_access' => 'metis_people_emergency_access',
        'people_passkeys'       => 'metis_people_passkeys',
        'passkeys'              => 'metis_passkeys',
        'people_auth_challenges'=> 'metis_people_auth_challenges',
        'people_lifecycle_tasks'=> 'metis_people_lifecycle_tasks',
        'people_workspace_users'=> 'metis_people_workspace_users',
        'people_workspace_user_roles'=> 'metis_people_workspace_user_roles',
        'people_workspace_groups'=> 'metis_people_workspace_groups',
        'people_workspace_group_members'=> 'metis_people_workspace_group_members',
        'people_workspace_security_actions'=> 'metis_people_workspace_security_actions',
        'people_workspace_sync_jobs'=> 'metis_people_workspace_sync_jobs',

        // --- Newsletter ---
        'newsletter_lists'      => 'metis_newsletter_lists',
        'newsletter_subs'       => 'metis_newsletter_subscriptions',
        'newsletter_templates'  => 'metis_newsletter_templates',
        'newsletter_campaigns'  => 'metis_newsletter_campaigns',
        'newsletter_campaign_lists' => 'metis_newsletter_campaign_lists',
        'newsletter_messages'   => 'metis_newsletter_messages',
        'newsletter_events'     => 'metis_newsletter_events',
        'newsletter_revisions'  => 'metis_newsletter_revisions',
        'newsletter_audit'      => 'metis_newsletter_audit',
        'newsletter_suppressions' => 'metis_newsletter_suppressions',
        'newsletter_google_usage_daily' => 'metis_newsletter_google_usage_daily',

        // --- Board Governance ---
        'board_committees'      => 'metis_board_committees',
        'board_meetings'        => 'metis_board_meetings',
        'board_decisions'       => 'metis_board_decisions',
        'board_action_items'    => 'metis_board_action_items',
        'board_attendance'      => 'metis_board_attendance',
        'board_documents'       => 'metis_board_documents',
        'board_compliance'      => 'metis_board_compliance',
        'board_announcements'   => 'metis_board_announcements',
        'board_agenda_templates'=> 'metis_board_agenda_templates',
        'board_decision_templates'=> 'metis_board_decision_templates',

        // --- Drive ---
        'drive_audit'           => 'metis_drive_audit',
        'drive_user_folders'    => 'metis_drive_user_folders',
        'drive_items'           => 'metis_drive_items',
        'drive_sync_state'      => 'metis_drive_sync_state',

        // --- Calendar ---
        'calendar_events'       => 'metis_calendar_events',
        'calendar_sync_state'   => 'metis_calendar_sync_state',

        // --- Backup ---
        'backup_runs'           => 'metis_backup_runs',

        // --- Tags ---
        'tags'                  => 'metis_tags',

        // --- Reports ---
        'reports'               => 'metis_reports',
        'hermes_sessions'       => 'metis_hermes_sessions',
        'hermes_messages'       => 'metis_hermes_messages',
        'hermes_actions'        => 'metis_hermes_actions',
        'hermes_reports'        => 'metis_hermes_reports',
        'hermes_memory'         => 'metis_hermes_memory',

        // --- Finance ---
        'finance_accounts'      => 'metis_finance_accounts',
        'finance_events'        => 'metis_finance_events',
        'finance_funds'         => 'metis_finance_funds',
        'finance_tags'          => 'metis_finance_tags',
        'finance_event_tags'    => 'metis_finance_event_tags',
        'finance_ledger'        => 'metis_finance_ledger',
        'finance_reconciliations' => 'metis_finance_reconciliations',

        // --- Forms ---
        'forms'                 => 'metis_forms',
        'form_versions'         => 'metis_form_versions',
        'form_submissions'      => 'metis_form_submissions',

        // --- Grandy's Stash ---
        'grandys_stash_catalog' => 'metis_grandys_stash_catalog',
        'grandys_stash_items'   => 'metis_grandys_stash_items',
        'grandys_stash_cases'   => 'metis_grandys_stash_cases',
        'grandys_stash_distributions' => 'metis_grandys_stash_distributions',

        // --- System ---
        'settings'              => 'metis_settings',
        'auth_users'            => 'metis_auth_users',
        'code_registry'         => 'metis_code_registry',
        'entity_prefixes'       => 'metis_entity_prefixes',
        'id_sequences'          => 'metis_id_sequences',
        'entity_registry'       => 'metis_entity_registry',
        'webhook_events'        => 'metis_webhook_events',
        'job_queue'            => 'metis_job_queue',
        'audit_activity'        => 'metis_audit_activity',
        'audit_security'        => 'metis_audit_security',
        'sync_state'            => 'metis_sync_state',

    ];

    public static function init(): void {

        if ( self::$booted ) {
            return;
        }

        $db         = metis_db();
        $connection = $db->connection();
        $existing   = array_flip( $db->column( 'SHOW TABLES' ) );
        $prefix     = (string) ( $connection->prefix ?? '' );

        foreach ( self::$definitions as $key => $bare_name ) {
            $canonical = $bare_name;
            $legacy    = $prefix . $bare_name;

            if ( isset( $existing[ $canonical ] ) ) {
                self::$map[ $key ] = $canonical;
            } elseif ( isset( $existing[ $legacy ] ) ) {
                self::$map[ $key ] = $legacy;
            } else {
                self::$map[ $key ] = $canonical;
            }
        }

        self::$booted = true;

        Metis_Logger::info( 'Table registry initialized (' . count( self::$map ) . ' tables)' );
    }

    public static function get( string $key ): string {

        if ( ! self::$booted ) {
            self::init();
        }

        if ( ! isset( self::$map[ $key ] ) ) {
            $message = "Metis_Tables: unknown table key '{$key}'";
            Metis_Logger::error( $message );
            throw new InvalidArgumentException( $message );
        }

        return self::$map[ $key ];
    }

    public static function all(): array {

        if ( ! self::$booted ) {
            self::init();
        }

        return self::$map;
    }

    public static function has( string $key ): bool {

        if ( ! self::$booted ) {
            self::init();
        }

        return isset( self::$map[ $key ] );
    }

    public static function definitions(): array {
        return self::$definitions;
    }
}
