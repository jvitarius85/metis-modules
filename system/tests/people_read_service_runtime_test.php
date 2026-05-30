<?php
declare(strict_types=1);

namespace Metis\Modules\People {
    final class WorkspaceActivityService {
        public static function payload( int $sync_page, int $security_page ): array {
            return [
                'sync_page' => $sync_page,
                'security_page' => $security_page,
                'sync_logs' => [],
                'security_logs' => [],
            ];
        }

        public static function countQueuedJobs(): int {
            return 3;
        }
    }
}

namespace {

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

final class Metis_Tables {
    public static function get( string $table ): string {
        return 'metis_' . $table;
    }
}

final class MetisFakePeopleReadDb {
    public array $scalarCalls = [];
    public array $fetchAllCalls = [];

    public function scalar( string $sql, array $params = [] ): int|string|float|null {
        $this->scalarCalls[] = [ $sql, $params ];

        if ( str_contains( $sql, "FROM metis_people WHERE is_staff = 1" ) ) {
            return 2;
        }
        if ( str_contains( $sql, "FROM metis_people WHERE is_board = 1" ) ) {
            return 1;
        }
        if ( str_contains( $sql, "FROM metis_people WHERE is_volunteer = 1" ) ) {
            return 1;
        }
        if ( str_contains( $sql, "FROM metis_people WHERE is_workspace_user = 1" ) ) {
            return 3;
        }
        if ( str_contains( $sql, "FROM metis_people WHERE stripe_role IS NOT NULL AND stripe_role <> ''" ) ) {
            return 2;
        }
        if ( str_contains( $sql, "FROM metis_people WHERE status = 'active'" ) ) {
            return 3;
        }
        if ( str_contains( $sql, "FROM metis_people_access_requests WHERE status = 'pending'" ) ) {
            return 2;
        }
        if ( str_contains( $sql, "FROM metis_people WHERE status='active' AND requires_2fa = 1 AND (totp_enabled = 0 AND passkey_enabled = 0)" ) ) {
            return 1;
        }
        if ( str_contains( $sql, "FROM metis_people_access_requests WHERE status = 'expired'" ) ) {
            return 1;
        }
        if ( str_contains( $sql, "FROM metis_people_documents WHERE lifecycle_status = 'expired'" ) ) {
            return 2;
        }
        if ( str_contains( $sql, "FROM metis_people_workspace_users WHERE is_suspended = 1" ) ) {
            return 1;
        }
        if ( str_contains( $sql, 'FROM metis_people_activity WHERE created_at >=' ) ) {
            return 6;
        }
        if ( str_contains( $sql, "FROM metis_people_roles" ) ) {
            return 5;
        }
        if ( str_contains( $sql, "FROM metis_people_permissions" ) ) {
            return 9;
        }
        if ( str_contains( $sql, "FROM metis_people_role_templates" ) ) {
            return 4;
        }
        if ( str_contains( $sql, "SELECT COUNT(*) FROM metis_people" ) ) {
            return 4;
        }

        return null;
    }

    public function fetchAll( string $sql, array $params = [] ): array {
        $this->fetchAllCalls[] = [ $sql, $params ];

        if ( str_contains( $sql, 'FROM metis_people' ) && str_contains( $sql, 'LIMIT %d OFFSET %d' ) ) {
            return [
                [
                    'id' => 1,
                    'pid' => 'P-1',
                    'auth_provider' => 'metis',
                    'email' => 'ada@example.com',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'display_name' => 'Ada Lovelace',
                    'linked_donor_id' => 'D-1',
                    'is_workspace_user' => 1,
                    'workspace_email' => 'ada@workspace.test',
                    'stripe_role' => 'manager',
                ],
                [
                    'id' => 2,
                    'pid' => 'P-2',
                    'auth_provider' => 'google',
                    'email' => 'grace@example.com',
                    'first_name' => 'Grace',
                    'last_name' => 'Hopper',
                    'display_name' => 'Grace Hopper',
                    'linked_donor_id' => '',
                    'is_workspace_user' => 0,
                    'workspace_email' => '',
                    'stripe_role' => '',
                ],
            ];
        }

        if ( str_contains( $sql, "FROM metis_people_roles WHERE role_domain = 'metis'" ) ) {
            return [
                [ 'id' => 10, 'role_key' => 'admin', 'role_name' => 'Admin' ],
                [ 'id' => 11, 'role_key' => 'editor', 'role_name' => 'Editor' ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_people_user_roles ur WHERE ur.person_id IN (1,2)' ) ) {
            return [
                [ 'person_id' => 1, 'role_id' => 10 ],
                [ 'person_id' => 1, 'role_id' => 11 ],
                [ 'person_id' => 2, 'role_id' => 11 ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_people_workspace_users wu' ) ) {
            return [
                [ 'id' => 100, 'display_name' => 'Ada Lovelace', 'primary_email' => 'ada@workspace.test', 'person_id' => 1, 'pid' => 'P-1', 'linked_pid' => 'P-1', 'linked_name' => 'Ada Lovelace', 'metadata_json' => '{}' ],
                [ 'id' => 101, 'display_name' => 'Grace Hopper', 'primary_email' => 'grace@workspace.test', 'person_id' => 2, 'pid' => 'P-2', 'linked_pid' => 'P-2', 'linked_name' => 'Grace Hopper', 'metadata_json' => '{"ui_hidden":true}' ],
                [ 'id' => 102, 'display_name' => 'Linus Torvalds', 'primary_email' => 'linus@workspace.test', 'person_id' => 0, 'pid' => '', 'linked_pid' => null, 'linked_name' => null, 'metadata_json' => '{}' ],
            ];
        }

        if ( str_contains( $sql, "SELECT role_key, role_name FROM metis_people_roles WHERE role_domain = 'workspace'" ) ) {
            return [
                [ 'role_key' => 'workspace_admin', 'role_name' => 'Workspace Admin' ],
            ];
        }

        if ( str_contains( $sql, 'SELECT workspace_user_id, role_key FROM metis_people_workspace_user_roles' ) ) {
            return [
                [ 'workspace_user_id' => 100, 'role_key' => 'workspace_admin' ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_people_workspace_groups wg' ) ) {
            return [
                [ 'id' => 201, 'group_name' => 'Board', 'group_email' => 'board@example.com', 'member_count' => 2 ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_people_workspace_group_members gm' ) ) {
            return [
                [ 'group_id' => 201, 'workspace_user_id' => 100, 'member_role' => 'MEMBER', 'primary_email' => 'ada@workspace.test', 'display_name' => 'Ada Lovelace' ],
                [ 'group_id' => 201, 'workspace_user_id' => 102, 'member_role' => 'MANAGER', 'primary_email' => 'linus@workspace.test', 'display_name' => 'Linus Torvalds' ],
            ];
        }

        return [];
    }
}

function metis_db(): MetisFakePeopleReadDb {
    static $db = null;
    if ( ! $db instanceof MetisFakePeopleReadDb ) {
        $db = new MetisFakePeopleReadDb();
    }
    return $db;
}

function metis_current_time( string $type = 'timestamp' ): int|string {
    if ( $type === 'timestamp' ) {
        return strtotime( '2026-05-30 12:00:00 UTC' );
    }
    return '2026-05-30 12:00:00';
}

function metis_runtime_date( string $format, int $timestamp ): string {
    return gmdate( $format, $timestamp );
}

function metis_key_clean( string $value ): string {
    return strtolower( preg_replace( '/[^a-z0-9_]/', '', $value ) ?? '' );
}

require_once dirname( __DIR__ ) . '/src/Metis/Modules/People/ReadService.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$dashboard = \Metis\Modules\People\ReadService::dashboardSnapshot();
$peopleList = \Metis\Modules\People\ReadService::peopleListSnapshot( 1, 2 );
$workspace = \Metis\Modules\People\ReadService::workspaceSnapshot( 2, 3 );
$db = metis_db();

$assert( ( $dashboard['total_people'] ?? 0 ) === 4, 'People dashboard must expose total people count.' );
$assert( ( $dashboard['staff_count'] ?? 0 ) === 2, 'People dashboard must expose staff count.' );
$assert( ( $dashboard['board_count'] ?? 0 ) === 1, 'People dashboard must expose board count.' );
$assert( ( $dashboard['active_people'] ?? 0 ) === 3, 'People dashboard must expose active people count.' );
$assert( ( $dashboard['pending_requests'] ?? 0 ) === 2, 'People dashboard must expose pending request count.' );
$assert( ( $dashboard['activity_24h'] ?? 0 ) === 6, 'People dashboard must expose 24-hour activity count.' );
$assert( ( $dashboard['mfa_gaps'] ?? 0 ) === 1, 'People dashboard must expose MFA gap count.' );
$assert( ( $dashboard['expired_docs'] ?? 0 ) === 2, 'People dashboard must expose expired document count.' );

$assert( ( $peopleList['page'] ?? 0 ) === 1 && ( $peopleList['per_page'] ?? 0 ) === 2, 'People list snapshot must retain pagination inputs.' );
$assert( ( $peopleList['total_people'] ?? 0 ) === 4, 'People list snapshot must expose total people count.' );
$assert( ( $peopleList['total_pages'] ?? 0 ) === 2, 'People list snapshot must compute total pages.' );
$assert( count( $peopleList['people'] ?? [] ) === 2, 'People list snapshot must return current page people.' );
$assert( ( $peopleList['people'][0]['full_name'] ?? '' ) === 'Ada Lovelace', 'People list snapshot must derive full name from first and last names.' );
$assert( ( $peopleList['people'][0]['roles'] ?? [] ) === [ 'admin', 'editor' ], 'People list snapshot must map assigned metis roles.' );
$assert( ( $peopleList['people'][1]['roles'] ?? [] ) === [ 'editor' ], 'People list snapshot must map single-role assignments.' );
$assert( isset( $peopleList['role_by_key']['admin'] ), 'People list snapshot must expose role lookup by key.' );

$assert( ( $workspace['kpi_total_users'] ?? 0 ) === 2, 'Workspace snapshot must exclude UI-hidden users from the visible KPI total.' );
$assert( ( $workspace['kpi_suspended'] ?? 0 ) === 1, 'Workspace snapshot must expose suspended user count.' );
$assert( ( $workspace['kpi_groups'] ?? 0 ) === 1, 'Workspace snapshot must expose workspace group count.' );
$assert( ( $workspace['kpi_pending_jobs'] ?? 0 ) === 3, 'Workspace snapshot must expose queued job count.' );
$assert( count( $workspace['workspace_users'] ?? [] ) === 3, 'Workspace snapshot must return workspace user rows.' );
$assert( ( $workspace['workspace_roles']['workspace_admin'] ?? '' ) === 'Workspace Admin', 'Workspace snapshot must expose workspace role labels.' );
$assert( ( $workspace['roles_by_user'][100] ?? [] ) === [ 'workspace_admin' ], 'Workspace snapshot must map workspace roles by user.' );
$assert( count( $workspace['members_by_group'][201] ?? [] ) === 2, 'Workspace snapshot must map group members by group id.' );
$assert( count( $workspace['groups_by_user'][100] ?? [] ) === 1, 'Workspace snapshot must map groups by workspace user id.' );
$assert( ( $workspace['activity']['sync_page'] ?? 0 ) === 2 && ( $workspace['activity']['security_page'] ?? 0 ) === 3, 'Workspace snapshot must delegate paging into WorkspaceActivityService payload.' );

$assert( count( $db->scalarCalls ) === 17, 'People read runtime test must exercise expected scalar calls.' );
$assert( count( $db->fetchAllCalls ) === 8, 'People read runtime test must exercise expected fetchAll calls.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "People read service runtime checks passed.\n" );
}
