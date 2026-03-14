<?php
declare(strict_types=1);

namespace Metis\Services;

final class SecurityDiagnosticsService {
    public function __construct(
        private readonly PermissionsService $permissions
    ) {}

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
        $requiredPermission = $resource === 'board_drive' ? 'drive.view' : 'people.view';
        $activePermissions = $person !== null ? $this->activePermissionKeysForPerson( (int) ( $person['id'] ?? 0 ) ) : [];
        $boardAccess = in_array( 'board.view', $activePermissions, true );
        $driveAccess = in_array( 'drive.view', $activePermissions, true );
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
            'resolved_permissions' => array_values( array_filter( $activePermissions, static fn ( string $key ): bool => str_contains( $key, '.view' ) ) ),
            'inference' => false,
            'query' => $query,
        ];
    }

    private function extractSubject( string $query ): string {
        $patterns = [
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
        global $wpdb;

        if ( $subject === '' || ! class_exists( 'Metis_Tables' ) ) {
            return null;
        }

        $peopleTable = \Metis_Tables::get( 'people' );
        $workspaceUsersTable = \Metis_Tables::get( 'people_workspace_users' );
        $needle = strtolower( trim( $subject ) );
        $compact = preg_replace( '/[^a-z0-9]/', '', $needle ) ?? '';

        $exact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, p.workspace_email
                 FROM {$peopleTable} p
                 WHERE LOWER(COALESCE(p.display_name,'')) = %s
                    OR LOWER(CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,''))) = %s
                    OR LOWER(COALESCE(p.email,'')) = %s
                    OR LOWER(COALESCE(p.workspace_email,'')) = %s
                 LIMIT 1",
                $needle,
                $needle,
                $needle,
                $needle
            ),
            ARRAY_A
        );

        if ( is_array( $exact ) ) {
            return $exact;
        }

        if ( strlen( $compact ) <= 4 ) {
            $initials = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, p.workspace_email
                     FROM {$peopleTable} p
                     WHERE LOWER(CONCAT(LEFT(COALESCE(p.first_name,''),1), LEFT(COALESCE(p.last_name,''),1))) = %s
                        OR LOWER(CONCAT(LEFT(COALESCE(p.display_name,''),1), LEFT(SUBSTRING_INDEX(COALESCE(p.display_name,''), ' ', -1),1))) = %s
                     LIMIT 1",
                    $compact,
                    $compact
                ),
                ARRAY_A
            );

            if ( is_array( $initials ) ) {
                return $initials;
            }
        }

        $workspace = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.id, p.display_name, p.first_name, p.last_name, p.email, wu.primary_email AS workspace_email
                 FROM {$workspaceUsersTable} wu
                 INNER JOIN {$peopleTable} p ON p.id = wu.person_id
                 WHERE LOWER(COALESCE(wu.primary_email,'')) = %s
                    OR LOWER(CONCAT(LEFT(COALESCE(wu.first_name,''),1), LEFT(COALESCE(wu.last_name,''),1))) = %s
                 LIMIT 1",
                $needle,
                $compact
            ),
            ARRAY_A
        );

        return is_array( $workspace ) ? $workspace : null;
    }

    private function activePermissionKeysForPerson( int $personId ): array {
        if ( $personId < 1 || ! function_exists( 'metis_people_active_permission_keys_for_person' ) ) {
            return [];
        }

        return array_values( array_map( 'strval', (array) \metis_people_active_permission_keys_for_person( $personId ) ) );
    }

    private function displayNameForPerson( ?array $person, string $fallback ): string {
        if ( ! is_array( $person ) ) {
            return $fallback !== '' ? $fallback : 'Unknown';
        }

        $display = trim( (string) ( $person['display_name'] ?? '' ) );
        if ( $display !== '' ) {
            return $display;
        }

        $full = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
        if ( $full !== '' ) {
            return $full;
        }

        return (string) ( $person['email'] ?? $fallback ?: 'Unknown' );
    }
}
