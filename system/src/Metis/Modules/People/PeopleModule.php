<?php
declare(strict_types=1);

namespace Metis\Modules\People;

use Metis\Core\Events\Event;

final class PeopleModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'People bootstrap loaded' );

        \metis_on( 'init', [ self::class, 'handleInit' ], 4 );
        if ( \function_exists( 'metis_ajax_register_controller' ) ) {
            \metis_ajax_register_controller(
                'metis_people_schema_status',
                [
                    'module' => 'people',
                    'permission' => 'view',
                    'nonce_action' => \metis_ajax_nonce_action( 'metis_people_schema_status' ),
                    'schema' => [],
                ]
            );
        }
        \metis_ajax_register_handler(
            'metis_people_schema_status',
            [ self::class, 'handleSchemaStatusRequest' ],
            [
                'module' => 'people',
                'permission' => 'view',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_people_schema_status' ),
                'schema' => [],
            ]
        );
        self::registerEventSubscribers();
    }

    public static function handleInit(): void {
        SchemaManager::ensureSchema();
        AccessManager::seedPermissionsAndRoles();
        MaintenanceManager::runMaintenance();

        if ( \metis_user_logged_in() && function_exists( 'metis_is_portal_request' ) && \metis_is_portal_request() ) {
            AccessManager::getOrCreateCurrentPerson();
        }
    }

    public static function handleSchemaStatusRequest(): void {
        \metis_check_ajax_referer( 'metis_people', 'nonce' );
        if ( ! \metis_current_user_can( 'manage_options' ) ) {
            \metis_runtime_send_json_error( 'Unauthorized', 403 );
        }

        SchemaManager::ensureSchema();
        AccessManager::seedPermissionsAndRoles();

        $tables = [
            'people' => \Metis_Tables::get( 'people' ),
            'roles' => \Metis_Tables::get( 'people_roles' ),
            'permissions' => \Metis_Tables::get( 'people_permissions' ),
            'role_permissions' => \Metis_Tables::get( 'people_role_perms' ),
            'user_roles' => \Metis_Tables::get( 'people_user_roles' ),
            'activity' => \Metis_Tables::get( 'people_activity' ),
            'access_requests' => \Metis_Tables::get( 'people_access_requests' ),
            'role_templates' => \Metis_Tables::get( 'people_role_templates' ),
            'template_roles' => \Metis_Tables::get( 'people_template_roles' ),
            'documents' => \Metis_Tables::get( 'people_documents' ),
            'emergency_access' => \Metis_Tables::get( 'people_emergency_access' ),
            'passkeys' => \Metis_Tables::get( 'people_passkeys' ),
            'auth_challenges' => \Metis_Tables::get( 'people_auth_challenges' ),
            'lifecycle_tasks' => \Metis_Tables::get( 'people_lifecycle_tasks' ),
            'workspace_users' => \Metis_Tables::get( 'people_workspace_users' ),
            'workspace_user_roles' => \Metis_Tables::get( 'people_workspace_user_roles' ),
            'workspace_groups' => \Metis_Tables::get( 'people_workspace_groups' ),
            'workspace_group_members' => \Metis_Tables::get( 'people_workspace_group_members' ),
            'workspace_security_actions' => \Metis_Tables::get( 'people_workspace_security_actions' ),
            'workspace_sync_jobs' => \Metis_Tables::get( 'people_workspace_sync_jobs' ),
        ];

        $status = [];
        foreach ( $tables as $key => $table_name ) {
            $status[ $key ] = SchemaManager::tableExists( $table_name );
        }

        \metis_runtime_send_json_success( [ 'tables' => $status ] );
    }

    public static function handleUserCreated( Event $event ): void {
        $person_id = (int) $event->payload( 'person_id', 0 );
        ActivityService::logActivity(
            $person_id > 0 ? $person_id : null,
            'user_created',
            'Created people profile',
            [
                'event' => $event->name(),
                'email' => (string) $event->payload( 'email', '' ),
                'roles' => (array) $event->payload( 'roles', [] ),
            ]
        );
    }

    public static function handleDonationBatchCreated( Event $event ): void {
        ActivityService::logActivity(
            null,
            'donation_batch_created',
            'Created donation deposit batch',
            [
                'event'      => $event->name(),
                'batch_code' => (string) $event->payload( 'batch_code', '' ),
                'txn_count'  => (int) $event->payload( 'txn_count', 0 ),
                'gross'      => (float) $event->payload( 'gross', 0.0 ),
                'net'        => (float) $event->payload( 'net', 0.0 ),
            ]
        );
    }

    public static function handleDonationReceived( Event $event ): void {
        ActivityService::logActivity(
            null,
            'donation_received',
            'Recorded donation event',
            [
                'event'         => $event->name(),
                'finance_event' => (int) $event->payload( 'event_id', 0 ),
                'reference_id'  => (string) $event->payload( 'reference_id', '' ),
                'amount'        => (float) $event->payload( 'amount', 0.0 ),
                'currency'      => (string) $event->payload( 'currency', 'usd' ),
            ]
        );
    }

    public static function handleNewsletterSent( Event $event ): void {
        ActivityService::logActivity(
            null,
            'newsletter_sent',
            'Newsletter message delivered',
            [
                'event'       => $event->name(),
                'campaign_id' => (int) $event->payload( 'campaign_id', 0 ),
                'message_id'  => (int) $event->payload( 'message_id', 0 ),
                'email'       => (string) $event->payload( 'email', '' ),
            ]
        );
    }

    private static function registerEventSubscribers(): void {
        if ( ! \Metis\Core\Application::has_service( 'events' ) ) {
            return;
        }

        $events = \Metis\Core\Application::service( 'events' );
        $events->subscribe( 'user.created', [ self::class, 'handleUserCreated' ] );
        $events->subscribe( 'donation.batch.created', [ self::class, 'handleDonationBatchCreated' ] );
        $events->subscribe( 'donation.received', [ self::class, 'handleDonationReceived' ] );
        $events->subscribe( 'newsletter.sent', [ self::class, 'handleNewsletterSent' ] );
    }

    public static function tableExists( string $table ): bool { return SchemaManager::tableExists( $table ); }
    public static function columnExists( string $table, string $column ): bool { return SchemaManager::columnExists( $table, $column ); }
    public static function addColumnIfMissing( string $table, string $column, string $definition ): void { SchemaManager::addColumnIfMissing( $table, $column, $definition ); }
    public static function dropIndexIfExists( string $table, string $index_name ): void { SchemaManager::dropIndexIfExists( $table, $index_name ); }
    public static function addIndexIfMissing( string $table, string $index_name, string $definition ): void { SchemaManager::addIndexIfMissing( $table, $index_name, $definition ); }
    public static function baseUrl(): string { return Support::baseUrl(); }
    public static function personUrl( string $pid = '' ): string { return Support::personUrl( $pid ); }
    public static function peopleListUrl(): string { return Support::peopleListUrl(); }
    public static function rolesListUrl(): string { return Support::rolesListUrl(); }
    public static function permissionsUrl(): string { return Support::permissionsUrl(); }
    public static function accessRequestsUrl(): string { return Support::accessRequestsUrl(); }
    public static function templatesUrl(): string { return Support::templatesUrl(); }
    public static function bulkActionsUrl(): string { return Support::bulkActionsUrl(); }
    public static function activityUrl(): string { return Support::activityUrl(); }
    public static function workspaceUrl(): string { return Support::workspaceUrl(); }
    public static function roleUrl( string $role_key = '', string $role_domain = '' ): string { return Support::roleUrl( $role_key, $role_domain ); }
    public static function canManage(): bool { return Support::canManage(); }
    public static function canView(): bool { return Support::canView(); }
    public static function canWorkspaceManage(): bool { return Support::canWorkspaceManage(); }
    public static function ensureSchema(): void { SchemaManager::ensureSchema(); }
    public static function seedPermissionsAndRoles(): void { AccessManager::seedPermissionsAndRoles(); }
    public static function getOrCreateCurrentPerson(): ?array { return AccessManager::getOrCreateCurrentPerson(); }
    public static function can( string $domain, string $action = 'view' ): bool { return AccessManager::can( $domain, $action ); }
    public static function getCurrentPersonId(): int { return AccessManager::getCurrentPersonId(); }
    public static function logActivity( ?int $person_id, string $activity_type, string $summary, array $details = [] ): void { ActivityService::logActivity( $person_id, $activity_type, $summary, $details ); }
    public static function runMaintenance(): void { MaintenanceManager::runMaintenance(); }
}
