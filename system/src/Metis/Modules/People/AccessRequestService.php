<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class AccessRequestService {
    public static function resolveRoleIdByKey( string $role_key ): int {
        $roles_table = \Metis_Tables::get( 'people_roles' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1",
            [ $role_key ]
        );
    }

    public static function resolvePersonIdByPid( string $pid ): int {
        $people_table = \Metis_Tables::get( 'people' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1",
            [ $pid ]
        );
    }

    public static function createRequest( array $payload ): string {
        $requests_table = \Metis_Tables::get( 'people_access_requests' );
        $code = \metis_generate_code( 'AR', $requests_table, 'request_code' );

        \metis_db()->insert( $requests_table, [
            'request_code' => $code,
            'requester_person_id' => $payload['requester_person_id'] ?? null,
            'target_person_id' => (int) $payload['target_person_id'],
            'role_id' => (int) $payload['role_id'],
            'status' => 'pending',
            'reason' => $payload['reason'] ?? null,
            'required_approvals' => (int) $payload['required_approvals'],
            'approval_count' => 0,
            'approval_log_json' => \metis_json_encode( [] ),
            'requested_start_at' => $payload['requested_start_at'] ?? null,
            'requested_end_at' => $payload['requested_end_at'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,
        ], [ '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ] );

        return $code;
    }

    public static function getTargetSummary( int $target_person_id ): array {
        $people_table = \Metis_Tables::get( 'people' );
        $target_row = \metis_db()->fetchOne(
            "SELECT pid, display_name, first_name, last_name FROM {$people_table} WHERE id = %d LIMIT 1",
            [ $target_person_id ]
        );

        return is_array( $target_row ) ? $target_row : [];
    }

    public static function getRoleName( int $role_id ): string {
        $roles_table = \Metis_Tables::get( 'people_roles' );

        return (string) \metis_db()->scalar(
            "SELECT role_name FROM {$roles_table} WHERE id = %d LIMIT 1",
            [ $role_id ]
        );
    }

    public static function getRequestById( int $request_id ): ?array {
        $requests_table = \Metis_Tables::get( 'people_access_requests' );
        $row = \metis_db()->fetchOne(
            "SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1",
            [ $request_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function rejectRequest( int $request_id, string $decision_note, ?int $resolver_person_id ): void {
        $requests_table = \Metis_Tables::get( 'people_access_requests' );

        \metis_db()->update( $requests_table, [
            'status' => 'rejected',
            'decision_note' => $decision_note,
            'resolver_person_id' => $resolver_person_id,
            'resolved_at' => \metis_current_time( 'mysql' ),
        ], [ 'id' => $request_id ], [ '%s', '%s', '%d', '%s' ], [ '%d' ] );
    }

    public static function updateApprovalState( int $request_id, array $approval_log, int $approval_count, string $status, string $decision_note, ?int $resolver_person_id, ?string $resolved_at ): void {
        $requests_table = \Metis_Tables::get( 'people_access_requests' );

        \metis_db()->update( $requests_table, [
            'status' => $status,
            'approval_count' => $approval_count,
            'approval_log_json' => \metis_json_encode( $approval_log ),
            'decision_note' => $decision_note,
            'resolver_person_id' => $resolver_person_id,
            'resolved_at' => $resolved_at,
        ], [ 'id' => $request_id ], [ '%s', '%d', '%s', '%s', '%d', '%s' ], [ '%d' ] );
    }

    public static function hasAssignedRole( int $person_id, int $role_id ): bool {
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1",
            [ $person_id, $role_id ]
        ) > 0;
    }

    public static function assignRoleFromRequest( array $request ): void {
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        \metis_db()->insert( $user_roles_table, [
            'person_id' => (int) $request['target_person_id'],
            'role_id' => (int) $request['role_id'],
            'start_at' => ! empty( $request['requested_start_at'] ) ? (string) $request['requested_start_at'] : null,
            'end_at' => ! empty( $request['requested_end_at'] ) ? (string) $request['requested_end_at'] : null,
        ], [ '%d', '%d', '%s', '%s' ] );
    }
}
