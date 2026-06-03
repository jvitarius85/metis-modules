<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesIntentRegistry {
    private const DEFINITIONS = [
        'LOOKUP' => [
            'description' => 'Resolve a person, record, or discrete fact.',
            'aliases' => [ 'lookup', 'look up', 'find', 'search', 'show', 'get', 'who is', 'what is', 'whose', 'who has' ],
        ],
        'REPORT' => [
            'description' => 'Return aggregated, ranked, or exported operational data.',
            'aliases' => [ 'report', 'reports', 'summary', 'summarize', 'count', 'total', 'sum', 'average', 'avg', 'top', 'breakdown', 'export', 'download' ],
        ],
        'CREATE' => [
            'description' => 'Create a new record or resource.',
            'aliases' => [ 'create', 'add', 'new', 'provision', 'onboard' ],
        ],
        'UPDATE' => [
            'description' => 'Modify an existing record or assignment.',
            'aliases' => [ 'update', 'edit', 'change', 'assign', 'remove', 'revoke', 'link', 'publish', 'unpublish', 'reset', 'manage', 'set' ],
        ],
        'DELETE' => [
            'description' => 'Delete or permanently remove a record.',
            'aliases' => [ 'delete', 'remove permanently', 'erase', 'purge' ],
        ],
        'EXECUTE' => [
            'description' => 'Run an operation, workflow, diagnostic, or system action.',
            'aliases' => [ 'run', 'execute', 'sync', 'clear', 'rebuild', 'reload', 'check', 'scan', 'audit', 'verify', 'recover', 'restore', 'rollback', 'retry', 'cancel', 'rotate', 'install', 'disable', 'enable', 'offboard', 'send', 'queue', 'dispatch' ],
        ],
        'HELP' => [
            'description' => 'Explain how to complete a workflow or resolve a blocked state.',
            'aliases' => [ 'help', 'how do i', 'how can i', 'where do i', 'show me how', 'walk me through', 'why can\'t i', 'i can\'t', 'i cant', 'i don\'t see', 'i dont see', 'permission denied' ],
        ],
    ];

    private const COMMAND_MAP = [
        'lookup_profile' => 'LOOKUP',
        'get_entity_attribute' => 'LOOKUP',
        'query_capability_actors' => 'LOOKUP',
        'list_users' => 'LOOKUP',
        'get_user' => 'LOOKUP',
        'query_giving_summary' => 'REPORT',
        'resolve_help_issue' => 'HELP',
        'create_user' => 'CREATE',
        'workspace_user_create' => 'CREATE',
        'campaign_create' => 'CREATE',
        'newsletter_create' => 'CREATE',
        'create_post' => 'CREATE',
        'create_job' => 'CREATE',
        'send_announcement' => 'EXECUTE',
        'campaign_update' => 'UPDATE',
        'campaign_archive' => 'UPDATE',
        'publish_post' => 'UPDATE',
        'update_user' => 'UPDATE',
        'update_contact' => 'UPDATE',
        'manage_user_roles' => 'UPDATE',
        'manage_workspace_groups' => 'UPDATE',
        'link_drive_folder' => 'UPDATE',
        'campaign_publish' => 'EXECUTE',
        'campaign_delete' => 'DELETE',
        'newsletter_send' => 'EXECUTE',
        'newsletter_schedule' => 'EXECUTE',
        'newsletter_cancel' => 'UPDATE',
        'newsletter_delete' => 'DELETE',
        'user_delete' => 'DELETE',
        'user_unlock' => 'EXECUTE',
        'disable_user' => 'EXECUTE',
        'enable_user' => 'EXECUTE',
        'offboard_user' => 'EXECUTE',
        'user_password_reset' => 'EXECUTE',
        'workspace_user_password_reset' => 'EXECUTE',
        'workspace_user_update' => 'UPDATE',
        'workspace_user_disable' => 'EXECUTE',
        'workspace_user_enable' => 'EXECUTE',
        'workspace_user_delete' => 'DELETE',
        'reset_user_mfa' => 'EXECUTE',
        'reset_workspace_password' => 'EXECUTE',
        'reset_metis_password' => 'EXECUTE',
        'run_backup' => 'EXECUTE',
        'backup_start' => 'EXECUTE',
        'backup_restore' => 'EXECUTE',
        'backup_validate' => 'EXECUTE',
        'cache_clear' => 'EXECUTE',
        'update_check' => 'EXECUTE',
        'aut_update_check' => 'EXECUTE',
        'update_install' => 'EXECUTE',
        'aut_update_install' => 'EXECUTE',
        'release_rollback' => 'EXECUTE',
        'drive_sync' => 'EXECUTE',
        'calendar_sync' => 'EXECUTE',
        'queue_drain' => 'EXECUTE',
        'integrity_baseline' => 'EXECUTE',
        'module_compliance_audit' => 'EXECUTE',
        'board_workspace_prepare' => 'EXECUTE',
        'diagnostics_run' => 'EXECUTE',
        'clarify_password_reset' => 'HELP',
    ];

    public function definitions(): array {
        return self::DEFINITIONS;
    }

    public function definition( string $intent ): ?array {
        $intent = $this->normalizeIntent( $intent );
        return self::DEFINITIONS[ $intent ] ?? null;
    }

    public function supportedIntents(): array {
        return array_keys( self::DEFINITIONS );
    }

    public function classifyQuery( string $query ): string {
        $normalized = strtolower( trim( $query ) );
        if ( $normalized === '' ) {
            return 'LOOKUP';
        }

        $bestIntent = 'LOOKUP';
        $bestLength = 0;
        foreach ( self::DEFINITIONS as $intent => $definition ) {
            foreach ( (array) ( $definition['aliases'] ?? [] ) as $alias ) {
                $alias = strtolower( trim( (string) $alias ) );
                if ( $alias === '' || ! str_contains( $normalized, $alias ) ) {
                    continue;
                }

                $length = strlen( $alias );
                if ( $length > $bestLength ) {
                    $bestIntent = $intent;
                    $bestLength = $length;
                }
            }
        }

        return $bestIntent;
    }

    public function classifyCommand( string $commandKey, string $query = '' ): string {
        $commandKey = strtolower( trim( $commandKey ) );
        if ( $commandKey === '' ) {
            return $this->classifyQuery( $query );
        }

        if ( isset( self::COMMAND_MAP[ $commandKey ] ) ) {
            return self::COMMAND_MAP[ $commandKey ];
        }

        return match ( true ) {
            str_starts_with( $commandKey, 'create_' ) => 'CREATE',
            str_starts_with( $commandKey, 'update_' ) => 'UPDATE',
            str_starts_with( $commandKey, 'delete_' ) => 'DELETE',
            str_starts_with( $commandKey, 'list_' ), str_starts_with( $commandKey, 'get_' ), str_starts_with( $commandKey, 'lookup_' ) => 'LOOKUP',
            str_starts_with( $commandKey, 'check_' ), str_starts_with( $commandKey, 'run_' ), str_starts_with( $commandKey, 'sync_' ) => 'EXECUTE',
            default => $this->classifyQuery( $query ),
        };
    }

    private function normalizeIntent( string $intent ): string {
        return strtoupper( trim( $intent ) );
    }
}
