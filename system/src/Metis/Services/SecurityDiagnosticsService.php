<?php
declare(strict_types=1);

namespace Metis\Services;

final class SecurityDiagnosticsService {
    private ?DatabaseService $db;

    public function __construct(
        private readonly PermissionsService $permissions,
        ?DatabaseService $db = null
    ) {
        $this->db = $db;
    }

    private function database(): DatabaseService {
        if ( $this->db === null ) {
            $this->db = function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : new DatabaseService();
        }
        return $this->db;
    }

    public function diagnosePermissions( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $query   = trim( (string) ( $request['query'] ?? '' ) );
        $queryLc = strtolower( $query );
        $subject = $this->extractSubject( $query );
        $person  = $this->resolvePerson( $subject );

        $checks = [
            'verify_user_role',
            'verify_drive_acl',
            'verify_group_membership',
            'verify_permission_inheritance',
        ];

        $resource = str_contains( $queryLc, 'board drive' ) ? 'board_drive' : 'unknown';
        $activePermissions = $person !== null ? $this->activePermissionsForPerson( (int) ( $person['id'] ?? 0 ) ) : [];
        $activePermissionKeys = array_values( array_map(
            static fn ( array $permission ): string => (string) ( $permission['key'] ?? '' ),
            $activePermissions
        ) );
        $boardAccess = in_array( 'board.view', $activePermissionKeys, true );
        $driveAccess = in_array( 'drive.view', $activePermissionKeys, true );
        $issueFound = $person === null || ( $resource === 'board_drive' && ( ! $boardAccess || ! $driveAccess ) );
        $missingPermission = $resource === 'board_drive' && ! $driveAccess
            ? 'drive.board.view'
            : ( $resource === 'board_drive' && ! $boardAccess ? 'board.view' : '' );
        $diagnosis = $person === null
            ? 'User not found'
            : ( $issueFound ? 'User missing permission' : 'Permissions verified' );

        return [
            'status' => 'success',
            'diagnosis' => $diagnosis,
            'user' => $this->displayNameForPerson( $person, $subject ),
            'resource' => $resource,
            'checks_performed' => $checks,
            'missing_permission' => $missingPermission,
            'suggested_fix' => $person === null
                ? 'Match the request to a Metis person record before running permission diagnostics.'
                : ( $issueFound && $missingPermission !== '' ? 'Grant permission ' . $missingPermission : '' ),
            'issue_found' => $issueFound,
            'resolved_person_id' => (int) ( $person['id'] ?? 0 ),
            'permission_count' => count( $activePermissions ),
            'resolved_permissions' => $activePermissions,
            'permission_summary' => $this->summarizePermissions( $activePermissions ),
            'inference' => false,
            'query' => $query,
        ];
    }

    private function extractSubject( string $query ): string {
        $patterns = [
            '/what\s+are\s+the\s+permissions\s+that\s+(.+?)\s+has/i',
            '/what\s+permissions\s+does\s+(.+?)\s+have/i',
            '/permissions\s+of\s+(.+)$/i',
            '/why can(?:\'|’)t\s+(.+?)\s+(?:view|access)/i',
            '/why cant\s+(.+?)\s+(?:view|access)/i',
            '/for\s+(.+)$/i',
        ];

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $query, $matches ) ) {
                return trim( (string) ( $matches[1] ?? '' ), " \t\n\r\0\x0B?.!" );
            }
        }

        return '';
    }

    private function resolvePerson( string $subject ): ?array {
        if ( $subject === '' || ! class_exists( 'Metis_Tables' ) ) {
            return null;
        }

        $peopleTable = \Metis_Tables::get( 'people' );
        $workspaceUsersTable = \Metis_Tables::get( 'people_workspace_users' );
        $needle = strtolower( trim( $subject ) );
        $compact = preg_replace( '/[^a-z0-9]/', '', $needle ) ?? '';
        $tokens = array_values( array_filter( preg_split( '/\s+/', $needle ) ?: [] ) );

        $exact = $this->database()->fetchOne(
            "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, p.workspace_email
                 FROM {$peopleTable} p
                 WHERE LOWER(COALESCE(p.display_name,'')) = %s
                    OR LOWER(CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,''))) = %s
                    OR LOWER(COALESCE(p.email,'')) = %s
                    OR LOWER(COALESCE(p.workspace_email,'')) = %s
                 LIMIT 1",
            [ $needle, $needle, $needle, $needle ]
        );

        if ( is_array( $exact ) ) {
            return $exact;
        }

        $pidMatch = $this->database()->fetchOne(
            "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, p.workspace_email
                 FROM {$peopleTable} p
                 WHERE LOWER(COALESCE(p.pid,'')) = %s
                 LIMIT 1",
            [ $needle ]
        );

        if ( is_array( $pidMatch ) ) {
            return $pidMatch;
        }

        if ( strlen( $compact ) <= 4 ) {
            $initials = $this->database()->fetchOne(
                "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, p.workspace_email
                     FROM {$peopleTable} p
                     WHERE LOWER(CONCAT(LEFT(COALESCE(p.first_name,''),1), LEFT(COALESCE(p.last_name,''),1))) = %s
                        OR LOWER(CONCAT(LEFT(COALESCE(p.display_name,''),1), LEFT(SUBSTRING_INDEX(COALESCE(p.display_name,''), ' ', -1),1))) = %s
                     LIMIT 1",
                [ $compact, $compact ]
            );

            if ( is_array( $initials ) ) {
                return $initials;
            }
        }

        if ( count( $tokens ) >= 2 ) {
            $firstToken = (string) ( $tokens[0] ?? '' );
            $lastToken  = (string) ( $tokens[ count( $tokens ) - 1 ] ?? '' );

            $tokenMatch = $this->database()->fetchOne(
                "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, p.workspace_email
                     FROM {$peopleTable} p
                     WHERE LOWER(COALESCE(p.last_name,'')) = %s
                       AND (
                            LOWER(COALESCE(p.first_name,'')) = %s
                            OR LOWER(COALESCE(p.display_name,'')) LIKE %s
                            OR LOWER(COALESCE(p.email,'')) LIKE %s
                            OR LOWER(COALESCE(p.workspace_email,'')) LIKE %s
                       )
                     ORDER BY p.id DESC
                     LIMIT 1",
                [
                    $lastToken,
                    $firstToken,
                    '%' . $this->database()->escapeLike( $needle ) . '%',
                    $firstToken . '%',
                    $firstToken . '%',
                ]
            );

            if ( is_array( $tokenMatch ) ) {
                return $tokenMatch;
            }
        }

        $likeMatch = $this->database()->fetchOne(
            "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, p.workspace_email
                 FROM {$peopleTable} p
                 WHERE LOWER(COALESCE(p.display_name,'')) LIKE %s
                    OR LOWER(CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,''))) LIKE %s
                    OR LOWER(COALESCE(p.email,'')) LIKE %s
                    OR LOWER(COALESCE(p.workspace_email,'')) LIKE %s
                 ORDER BY p.id DESC
                 LIMIT 1",
            [
                '%' . $this->database()->escapeLike( $needle ) . '%',
                '%' . $this->database()->escapeLike( $needle ) . '%',
                '%' . $this->database()->escapeLike( $needle ) . '%',
                '%' . $this->database()->escapeLike( $needle ) . '%',
            ]
        );

        if ( is_array( $likeMatch ) ) {
            return $likeMatch;
        }

        $workspace = $this->database()->fetchOne(
            "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, wu.primary_email AS workspace_email
                 FROM {$workspaceUsersTable} wu
                 INNER JOIN {$peopleTable} p ON p.id = wu.person_id
                 WHERE LOWER(COALESCE(wu.primary_email,'')) = %s
                    OR LOWER(COALESCE(wu.display_name,'')) = %s
                    OR LOWER(COALESCE(wu.display_name,'')) LIKE %s
                    OR LOWER(CONCAT(LEFT(COALESCE(wu.first_name,''),1), LEFT(COALESCE(wu.last_name,''),1))) = %s
                 LIMIT 1",
            [ $needle, $needle, '%' . $this->database()->escapeLike( $needle ) . '%', $compact ]
        );

        return is_array( $workspace ) ? $workspace : null;
    }

    private function activePermissionsForPerson( int $personId ): array {
        if ( $personId < 1 || ! class_exists( 'Metis_Tables' ) ) {
            return [];
        }

        $rolesTable = \Metis_Tables::get( 'people_roles' );
        $userRolesTable = \Metis_Tables::get( 'people_user_roles' );
        $rolePermsTable = \Metis_Tables::get( 'people_role_perms' );
        $permsTable = \Metis_Tables::get( 'people_permissions' );

        $now = \function_exists( 'metis_current_time' ) ? \metis_current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $rows = $this->database()->fetchAll(
            "SELECT DISTINCT
                    p.permission_key,
                    p.permission_name,
                    p.module_slug,
                    p.action_key
                 FROM {$userRolesTable} ur
                 INNER JOIN {$rolesTable} r ON r.id = ur.role_id
                 INNER JOIN {$rolePermsTable} rp ON rp.role_id = ur.role_id AND rp.allow_access = 1
                 INNER JOIN {$permsTable} p ON p.id = rp.permission_id
                 WHERE ur.person_id = %d
                   AND (ur.start_at IS NULL OR ur.start_at <= %s)
                   AND (ur.end_at IS NULL OR ur.end_at >= %s)
                 ORDER BY p.module_slug ASC, p.action_key ASC, p.permission_name ASC",
            [ $personId, $now, $now ]
        ) ?: [];

        return array_values( array_map(
            static fn ( array $row ): array => [
                'key' => (string) ( $row['permission_key'] ?? '' ),
                'name' => (string) ( $row['permission_name'] ?? $row['permission_key'] ?? '' ),
                'module' => (string) ( $row['module_slug'] ?? '' ),
                'action' => (string) ( $row['action_key'] ?? '' ),
            ],
            array_values( array_filter( $rows, static fn ( mixed $row ): bool => is_array( $row ) ) )
        ) );
    }

    private function summarizePermissions( array $permissions ): array {
        $summary = [];

        foreach ( $permissions as $permission ) {
            $module = (string) ( $permission['module'] ?? '' );
            $action = (string) ( $permission['action'] ?? '' );
            $name   = (string) ( $permission['name'] ?? '' );

            if ( $module === '' ) {
                $module = 'general';
            }

            if ( ! isset( $summary[ $module ] ) ) {
                $summary[ $module ] = [
                    'module' => $module,
                    'module_label' => $this->humanizeLabel( $module ),
                    'permissions' => [],
                    'actions' => [],
                ];
            }

            if ( $name !== '' ) {
                $summary[ $module ]['permissions'][ $name ] = true;
            }

            if ( $action !== '' ) {
                $summary[ $module ]['actions'][ $action ] = true;
            }
        }

        foreach ( $summary as &$group ) {
            $group['permissions'] = array_values( array_keys( (array) ( $group['permissions'] ?? [] ) ) );
            $group['actions'] = array_values( array_map(
                fn ( string $action ): string => $this->humanizeLabel( $action ),
                array_keys( (array) ( $group['actions'] ?? [] ) )
            ) );
        }

        return array_values( $summary );
    }

    private function humanizeLabel( string $value ): string {
        $value = trim( str_replace( [ '_', '.' ], ' ', $value ) );
        return $value !== '' ? ucwords( $value ) : '';
    }

    private function displayNameForPerson( ?array $person, string $fallback ): string {
        if ( ! is_array( $person ) ) {
            return $fallback !== '' ? $fallback : 'Unknown';
        }

        $full = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
        if ( $full !== '' ) {
            return $full;
        }

        $display = trim( (string) ( $person['display_name'] ?? '' ) );
        if ( $display !== '' ) {
            return $display;
        }

        return (string) ( $person['email'] ?? $fallback ?: 'Unknown' );
    }
}
